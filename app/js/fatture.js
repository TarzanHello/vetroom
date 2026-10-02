// =============================================================
//  VETROOM 2 — Cassa e fatture: conti, controlli, PDF
//  I conti qui servono per l'anteprima mentre si compila.
//  Quelli che valgono li fa il database (stesse regole, stessi arrotondamenti):
//  prima di emettere si chiede sempre l'anteprima al server.
// =============================================================
(function () {
  const VR = window.VR;
  const I = (VR.inv = {});

  I.PAY = { contanti: 'Contanti', carta: 'Carta o bancomat', bonifico: 'Bonifico', assegno: 'Assegno', altro: 'Altro pagamento tracciabile' };
  I.KIND = { SV: 'Prestazione', FV: 'Farmaco', AA: 'Altro' };
  I.NATURE = { N1: 'Escluso art. 15', N4: 'Esente art. 10', 'N2.2': 'Non soggetto (forfettario)' };
  I.TS = {
    pending: ['Da inviare', 'pill-wait'], sent: ['Inviata', ''], accepted: ['Accolta', 'pill-ok'],
    warning: ['Accolta con avvisi', 'pill-wait'], rejected: ['Scartata', 'pill-bad'], not_required: ['Non serve', ''], sending: ['In invio', 'pill-wait']
  };

  const fmt = new Intl.NumberFormat('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  I.money = (x) => '€ ' + fmt.format(Number(x || 0));
  I.num = (x) => fmt.format(Number(x || 0));
  I.date = (d) => (d ? new Date(String(d).length === 10 ? d + 'T00:00:00' : d).toLocaleDateString('it-IT') : '');
  I.today = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };

  // ---------- Controlli ----------
  // Codice fiscale di persona fisica, compresi i casi di omocodia
  I.cfValid = (cf) => {
    cf = String(cf || '').toUpperCase().replace(/\s/g, '');
    if (!/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/.test(cf)) return false;
    const odd = { 0: 1, 1: 0, 2: 5, 3: 7, 4: 9, 5: 13, 6: 15, 7: 17, 8: 19, 9: 21, A: 1, B: 0, C: 5, D: 7, E: 9, F: 13, G: 15, H: 17, I: 19, J: 21, K: 2, L: 4, M: 18, N: 20, O: 11, P: 3, Q: 6, R: 8, S: 12, T: 14, U: 16, V: 10, W: 22, X: 25, Y: 24, Z: 23 };
    let s = 0;
    for (let i = 0; i < 15; i++) {
      const c = cf[i];
      if (i % 2 === 0) s += odd[c];
      else s += /[0-9]/.test(c) ? Number(c) : c.charCodeAt(0) - 65;
    }
    return String.fromCharCode(65 + (s % 26)) === cf[15];
  };
  I.pivaValid = (p) => {
    p = String(p || '').replace(/\s/g, '');
    if (!/^[0-9]{11}$/.test(p)) return false;
    let s = 0;
    for (let i = 0; i < 11; i++) {
      let n = Number(p[i]);
      if (i % 2 === 1) { n *= 2; if (n > 9) n -= 9; }
      s += n;
    }
    return s % 10 === 0;
  };

  // ---------- Conti (in centesimi, come il database) ----------
  const rd = (a, b) => Math.floor((2 * a + b) / (2 * b));     // a/b arrotondato a metà in su (numeri positivi)
  const cents = (x) => Math.round(Number(x) * 100);
  I.calc = (prof, lines, forceStamp = false) => {
    const forf = prof.regime === 'forfettario';
    const enOn = prof.enpav_on !== false;
    const rateBps = Math.round(Number(prof.enpav_rate ?? 2) * 100);
    const rows = (lines || []).map((l, i) => {
      const kind = l.kind || 'SV';
      const qH = Math.round(Number(l.qty || 1) * 100), uC = cents(l.unit || 0);
      let vat = null, nature = null;
      if (forf) nature = 'N2.2';
      else if (l.nature) nature = l.nature;
      else vat = Math.round(Number(l.vat ?? prof.vat_default ?? 22));
      const enpav = (l.enpav ?? kind === 'SV') && enOn;
      return { i, desc: l.desc, qty: qH / 100, unit: uC / 100, kind, vat, nature, key: vat != null ? String(vat) : nature, enpav, net: rd(qH * uC, 100), contrib: 0, iva: 0 };
    });
    const enRows = rows.filter((r) => r.enpav);
    const base = enRows.reduce((s, r) => s + r.net, 0);
    const contrib = base > 0 ? rd(base * rateBps, 10000) : 0;
    let given = 0;
    enRows.forEach((r, k) => { r.contrib = k === enRows.length - 1 ? contrib - given : rd(contrib * r.net, base); given += r.contrib; });
    const keys = [...new Set(rows.map((r) => r.key))].sort();
    let vatTotal = 0, nonVat = 0;
    const summary = keys.map((key) => {
      const rs = rows.filter((r) => r.key === key);
      const imp = rs.reduce((s, r) => s + r.net + r.contrib, 0);
      const vat = rs[0].vat;
      const iva = vat == null ? 0 : rd(imp * vat, 100);
      vatTotal += iva; if (vat == null) nonVat += imp;
      let g = 0;
      rs.forEach((r, k) => { const im = r.net + r.contrib; r.iva = k === rs.length - 1 ? iva - g : (imp === 0 ? 0 : rd(iva * im, imp)); g += r.iva; });
      return { key, vat, nature: rs[0].nature, imponibile: imp / 100, iva: iva / 100 };
    });
    const taxable = rows.reduce((s, r) => s + r.net, 0);
    const stamp = ((prof.bollo_auto !== false && nonVat > 7747) || forceStamp) ? 200 : 0;
    const ts = [];
    [...new Set(rows.map((r) => r.kind))].sort().forEach((kind) => keys.forEach((key) => {
      const rs = rows.filter((r) => r.kind === kind && r.key === key);
      const imp = rs.reduce((s, r) => s + r.net + r.contrib + r.iva, 0);
      if (imp > 0) ts.push({ tipoSpesa: kind, importo: imp / 100, ...(rs[0].vat != null ? { aliquotaIVA: rs[0].vat } : { naturaIVA: rs[0].nature }) });
    }));
    return {
      lines: rows.map((r) => ({ ...r, net: r.net / 100, contrib: r.contrib / 100, iva: r.iva / 100 })),
      taxable: taxable / 100, contrib: contrib / 100, vat_total: vatTotal / 100, stamp: stamp / 100,
      total: (taxable + contrib + vatTotal + stamp) / 100, vat_summary: summary, ts_voci: ts, non_vat: nonVat / 100
    };
  };

  I.loadProfile = async (clinicId) => {
    const { data, error } = await VR.sb.from('billing_profiles').select('*').eq('clinic_id', clinicId).maybeSingle();
    if (error) throw error;
    return data;
  };
  I.profileReady = (p) => !!(p && p.name && p.piva && p.cf && p.address && p.city);


  // Finestra di conferma con eventuali campi (al posto di confirm/prompt del browser)
  // I.ask({ title, html, ok, danger, fields: [{ name, label, type, value, options, required }] }) → valori o null
  I.ask = (o) => new Promise((resolve) => {
    const d = document.createElement('dialog');
    d.className = 'dlg';
    d.innerHTML = `<form method="dialog" novalidate>
      <h2 style="margin-top:0">${VR.esc(o.title || 'Conferma')}</h2>
      ${o.html ? `<div style="margin-bottom:8px">${o.html}</div>` : ''}
      <div class="grid">${(o.fields || []).map((f, k) => `<div class="s12"><label for="dq${k}">${VR.esc(f.label)}</label>${
        f.type === 'select' ? `<select id="dq${k}" name="${f.name}">${f.options.map(([v, l]) => `<option value="${VR.esc(v)}" ${String(v) === String(f.value ?? '') ? 'selected' : ''}>${VR.esc(l)}</option>`).join('')}</select>`
        : f.type === 'textarea' ? `<textarea id="dq${k}" name="${f.name}" rows="3">${VR.esc(f.value ?? '')}</textarea>`
        : `<input id="dq${k}" name="${f.name}" type="${f.type || 'text'}" value="${VR.esc(f.value ?? '')}" ${f.max ? `max="${f.max}"` : ''} ${f.min ? `min="${f.min}"` : ''}>`}</div>`).join('')}</div>
      <div class="dq-msg" hidden></div>
      <div class="actions-bar" style="margin-top:14px">
        <button class="btn ${o.danger ? 'btn-danger' : 'btn-primary'}" type="submit">${VR.esc(o.ok || 'Conferma')}</button>
        <button class="btn btn-ghost" type="button" data-x>Annulla</button>
      </div></form>`;
    document.body.appendChild(d);
    const done = (v) => { d.close(); d.remove(); resolve(v); };
    d.querySelector('[data-x]').addEventListener('click', () => done(null));
    d.addEventListener('cancel', (e) => { e.preventDefault(); done(null); });
    d.querySelector('form').addEventListener('submit', (e) => {
      e.preventDefault();
      const v = {};
      for (const el of e.target.elements) if (el.name) v[el.name] = el.value;
      const miss = (o.fields || []).find((f) => f.required && !String(v[f.name] || '').trim());
      if (miss) { VR.say(d.querySelector('.dq-msg'), `Compila "${miss.label}".`, 'error'); return; }
      done(v);
    });
    d.showModal();
  });


  // ---------- Sistema Tessera Sanitaria ----------
  // Errore leggibile da una risposta della funzione vetroom-ts
  I.fnError = async (error) => {
    try { const j = await error.context.json(); if (j && j.error) return j.error; } catch { /* niente */ }
    return /Failed to send|fetch/i.test(error?.message || '') ? 'La funzione di invio non risponde: controlla che "vetroom-ts" sia installata su Supabase.' : (error?.message || 'Errore sconosciuto');
  };

  // Chiede le credenziali (mai salvate da Vetroom) e invia a gruppi finché c'è qualcosa da inviare.
  // onProgress(testo) mostra l'avanzamento; ritorna il riepilogo.
  I.sendToTs = async ({ clinicId, defaultCf = '', only = null, onProgress = () => {} }) => {
    const d = document.createElement('dialog');
    d.className = 'dlg';
    d.innerHTML = `<form autocomplete="on" novalidate>
      <h2 style="margin-top:0">Accesso al Sistema TS</h2>
      <p style="margin-top:0;font-size:14px" class="muted">Le credenziali che usi su sistemats.it. Vetroom le usa solo per questo invio e <b>non le salva</b>: se vuoi, le ricorda il tuo browser.</p>
      <div class="grid">
        <div class="s12"><label for="tsU">Codice fiscale (nome utente)</label><input id="tsU" type="text" name="username" autocomplete="username" maxlength="16" style="text-transform:uppercase" value="${VR.esc(defaultCf)}"></div>
        <div class="s12"><label for="tsP">Password</label><input id="tsP" name="password" type="password" autocomplete="current-password"></div>
        <div class="s12"><label for="tsPin">Pincode</label><input id="tsPin" name="pincode" type="password" inputmode="numeric" autocomplete="off" maxlength="14"><span class="hint-small">10 cifre: su sistemats.it, in Profilo utente → Stampa pincode. <a href="/guida/invio-spese-veterinarie-sistema-ts.html#credenziali" target="_blank" rel="noopener">Non hai le credenziali?</a></span></div>
      </div>
      <div class="ts-msg" hidden style="margin-top:10px"></div>
      <div class="actions-bar" style="margin-top:14px"><button class="btn btn-primary" type="submit">Invia</button><button class="btn btn-ghost" type="button" data-x>Annulla</button></div>
      <p class="hint-small" style="margin:.7rem 0 0">Se il codice fiscale, la password o il pincode non vengono accettati, l'invio si ferma subito: così l'utenza non rischia blocchi.</p>
    </form>`;
    document.body.appendChild(d);
    d.showModal();
    const f = d.querySelector('form'), m = d.querySelector('.ts-msg');
    (defaultCf ? d.querySelector('#tsP') : d.querySelector('#tsU')).focus();
    return await new Promise((resolve) => {
      const close = (v) => { d.close(); d.remove(); resolve(v); };
      d.querySelector('[data-x]').addEventListener('click', () => close(null));
      d.addEventListener('cancel', (e) => { e.preventDefault(); close(null); });
      f.addEventListener('submit', async (e) => {
        e.preventDefault();
        const cred = { user: f.username.value.trim().toUpperCase(), password: f.password.value, pincode: f.pincode.value.replace(/\s/g, '') };
        if (!/^[A-Z0-9]{16}$/.test(cred.user)) { VR.say(m, 'Il codice fiscale deve avere 16 caratteri.', 'error'); return; }
        if (!cred.password || !cred.pincode) { VR.say(m, 'Servono password e pincode.', 'error'); return; }
        const btn = f.querySelector('[type=submit]'); btn.disabled = true; btn.textContent = 'Invio in corso…';
        const tot = { sent: 0, accepted: 0, warnings: 0, rejected: 0, results: [], stop: null };
        try {
          for (let round = 0; round < 40; round++) {
            const { data, error } = await VR.sb.functions.invoke('vetroom-ts', { body: { action: 'send', clinic_id: clinicId, cred, only, limit: 20 } });
            if (error) { tot.error = await I.fnError(error); break; }
            tot.sent += data.sent || 0; tot.accepted += data.accepted || 0; tot.warnings += data.warnings || 0; tot.rejected += data.rejected || 0;
            tot.results.push(...(data.results || []));
            onProgress(`Inviati ${tot.sent}…`);
            m.className = 'notice notice-info'; m.hidden = false; m.textContent = `Inviati ${tot.sent} documenti…`;
            if (data.stop) { tot.stop = data.stop; tot.stopMsg = (data.results || []).find((r) => r.status === 'pending')?.messaggi?.[0]?.descrizione; break; }
            if (!data.sent || only) break;
          }
        } catch (err) { tot.error = err.message; }
        // credenziali accettate: il browser può proporre di ricordarle (Vetroom non le vede più)
        if (!tot.error && tot.stop !== 'credenziali' && tot.sent > 0 && window.PasswordCredential && navigator.credentials?.store) {
          try { await navigator.credentials.store(new window.PasswordCredential({ id: cred.user, password: cred.password, name: 'Sistema TS' })); } catch { /* il browser può rifiutare */ }
        }
        cred.password = ''; cred.pincode = '';
        if (tot.stop === 'credenziali') { btn.disabled = false; btn.textContent = 'Invia'; VR.say(m, 'Credenziali non accettate dal Sistema TS' + (tot.stopMsg ? ` (${tot.stopMsg})` : '') + '. Controllale e riprova.', 'error'); f.password.value = ''; f.pincode.value = ''; return; }
        close(tot);
      });
    });
  };

  // ---------- PDF ----------
  const clean = (s) => String(s ?? '')
    .replace(/[\u2018\u2019]/g, "'").replace(/[\u201C\u201D]/g, '"').replace(/[\u2013\u2014]/g, '-')
    .replace(/\u2026/g, '...').replace(/\r\n?/g, '\n')
    .replace(/[^\n\x20-\x7E\u00A0-\u00FF\u20AC]/g, '');
  const INK = [28, 43, 54], MUTED = [94, 111, 124], LINE = [214, 222, 228], TEAL = [15, 118, 110];

  I.buildPdf = (inv, { logo = null } = {}) => {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'mm', format: 'a4' });
    const W = 210, M = 16, CW = W - 2 * M;
    const font = (size, style = 'normal', color = INK) => { doc.setFont('helvetica', style); doc.setFontSize(size); doc.setTextColor(...color); };
    const T = (s, x, y, o) => doc.text(clean(s), x, y, o);
    const iss = inv.issuer || {}, cu = inv.customer || {};
    const nc = inv.doc_type === 'NC';
    let y = M;

    // intestazione di chi emette
    let tx = M;
    if (logo) {
      const r = Math.min(30 / logo.w, 20 / logo.h);
      doc.addImage(logo.dataUrl, logo.fmt, M, y, logo.w * r, logo.h * r);
      tx = M + logo.w * r + 6;
    }
    font(13, 'bold'); T(iss.name || '', tx, y + 5);
    font(9, 'normal', MUTED);
    const il = [
      [iss.address, [iss.zip, iss.city, iss.province ? '(' + iss.province + ')' : ''].filter(Boolean).join(' ')].filter(Boolean).join(' - '),
      ['P. IVA ' + (iss.piva || ''), 'C.F. ' + (iss.cf || '')].join('  -  '),
      iss.albo || '',
      [iss.phone ? 'Tel. ' + iss.phone : '', iss.email || '', iss.pec ? 'PEC ' + iss.pec : ''].filter(Boolean).join('  -  ')
    ].filter(Boolean);
    il.forEach((t, k) => T(t, tx, y + 10.5 + k * 4.3));
    y += Math.max(26, 10.5 + il.length * 4.3 + 4);

    // titolo
    doc.setDrawColor(...TEAL); doc.setLineWidth(0.8); doc.line(M, y, W - M, y);
    y += 8;
    font(15, 'bold', TEAL); T((nc ? 'NOTA DI CREDITO' : 'FATTURA') + ' n. ' + inv.doc_number, M, y);
    font(10, 'normal', INK); T('Data: ' + I.date(inv.issued_on), W - M, y, { align: 'right' });
    y += 9;

    // destinatario
    const boxTop = y;
    font(8, 'bold', MUTED); T('DESTINATARIO', M + 4, y + 5);
    font(10.5, 'bold'); T(cu.name || '', M + 4, y + 10.5);
    font(9, 'normal');
    const cl = [cu.cf ? 'C.F. ' + cu.cf : '', [cu.address, [cu.zip, cu.city, cu.province ? '(' + cu.province + ')' : ''].filter(Boolean).join(' ')].filter(Boolean).join(' - ')].filter(Boolean);
    cl.forEach((t, k) => T(t, M + 4, y + 15.5 + k * 4.5));
    if (cu.pet) { font(9, 'normal', MUTED); T('Paziente: ' + cu.pet, W - M - 4, y + 10.5, { align: 'right' }); }
    const boxH = 15.5 + cl.length * 4.5 + 2;
    doc.setDrawColor(...LINE); doc.setLineWidth(0.3); doc.roundedRect(M, boxTop, CW, boxH, 2, 2, 'S');
    y = boxTop + boxH + 8;

    // righe
    const cols = [M + 2, W - M - 82, W - M - 58, W - M - 30, W - M - 2];
    const head = () => {
      doc.setFillColor(240, 247, 246); doc.rect(M, y - 4.5, CW, 7, 'F');
      font(8.5, 'bold', MUTED);
      T('Descrizione', cols[0], y); T('Q.tà', cols[1], y, { align: 'right' }); T('Prezzo', cols[2], y, { align: 'right' });
      T('IVA', cols[3], y, { align: 'right' }); T('Importo', cols[4], y, { align: 'right' });
      y += 7;
    };
    head();
    const ivaLbl = (l) => (l.vat != null ? l.vat + '%' : (l.nature || ''));
    (inv.lines || []).forEach((l) => {
      font(9.5, 'normal');
      const d = doc.splitTextToSize(clean(l.desc), cols[1] - cols[0] - 14);
      if (y + d.length * 4.6 > 262) { doc.addPage(); y = M + 6; head(); }
      doc.text(d, cols[0], y);
      T(I.num(l.qty).replace(/,00$/, ''), cols[1], y, { align: 'right' });
      T(I.num(l.unit), cols[2], y, { align: 'right' });
      T(ivaLbl(l), cols[3], y, { align: 'right' });
      T(I.num(l.net), cols[4], y, { align: 'right' });
      y += d.length * 4.6 + 1.8;
    });
    if (Number(inv.contrib) > 0) {
      font(9.5, 'normal');
      T('Contributo integrativo ENPAV ' + I.num(iss.enpav_rate).replace(/,00$/, '') + '%', cols[0], y);
      T(I.num(inv.contrib), cols[4], y, { align: 'right' });
      y += 6.4;
    }
    doc.setDrawColor(...LINE); doc.line(M, y - 3, W - M, y - 3);
    y += 3;

    // riepilogo
    if (y > 225) { doc.addPage(); y = M + 6; }
    const sx = W - M - 80;
    const row = (l, v, bold) => { font(bold ? 11 : 9.5, bold ? 'bold' : 'normal', bold ? INK : MUTED); T(l, sx, y); T(v, W - M - 2, y, { align: 'right' }); y += bold ? 7 : 5.2; };
    (inv.vat_summary || []).forEach((s) => {
      if (s.vat != null) { row('Imponibile al ' + s.vat + '%', I.money(s.imponibile)); row('IVA ' + s.vat + '%', I.money(s.iva)); }
      else row('Imponibile ' + (I.NATURE[s.nature] || s.nature).toLowerCase(), I.money(s.imponibile));
    });
    if (Number(inv.stamp) > 0) row('Imposta di bollo', I.money(inv.stamp));
    y += 1; doc.setDrawColor(...TEAL); doc.setLineWidth(0.5); doc.line(sx, y - 3.5, W - M, y - 3.5); y += 1.5;
    row(nc ? 'TOTALE STORNATO' : 'TOTALE', I.money(inv.total), true);

    // pagamento e diciture
    y += 3;
    const notes = [];
    if (!nc) {
      if (inv.paid_on) notes.push(`Pagata il ${I.date(inv.paid_on)} - ${I.PAY[inv.payment_method] || inv.payment_method}${inv.traced ? ' (pagamento tracciabile)' : ''}.`);
      else notes.push('Da pagare' + (iss.iban ? ` con bonifico su IBAN ${iss.iban}` : '') + '.');
    }
    if (iss.regime === 'forfettario') notes.push('Operazione effettuata ai sensi dell\'art. 1, commi da 54 a 89, della Legge n. 190/2014 - regime forfettario. Operazione senza applicazione dell\'IVA e non soggetta a ritenuta d\'acconto.');
    if (Number(inv.stamp) > 0) notes.push('Imposta di bollo di 2,00 euro assolta sull\'originale.');
    if (inv.ts_opposition) notes.push('Il cliente si è opposto alla trasmissione dei dati della spesa al Sistema Tessera Sanitaria.');
    if (inv.notes) notes.push(inv.notes);
    if (iss.footer) notes.push(iss.footer);
    font(9, 'normal', INK);
    notes.forEach((n) => {
      const t = doc.splitTextToSize(clean(n), CW);
      if (y + t.length * 4.3 > 285) { doc.addPage(); y = M + 6; }
      doc.text(t, M, y); y += t.length * 4.3 + 2;
    });
    // piè di pagina
    const pages = doc.getNumberOfPages();
    for (let p = 1; p <= pages; p++) {
      doc.setPage(p); font(7.5, 'normal', MUTED);
      T(`${nc ? 'Nota di credito' : 'Fattura'} ${inv.doc_number} - pagina ${p} di ${pages}`, W - M, 290, { align: 'right' });
    }
    return doc;
  };

  I.pdfName = (inv) => `${inv.doc_type === 'NC' ? 'NotaCredito' : 'Fattura'}_${String(inv.doc_number).replace(/[^A-Za-z0-9]+/g, '-')}.pdf`;

  // Logo della clinica per il PDF (se c'è)
  I.loadLogo = async (clinicId) => {
    try {
      const { data } = await VR.sb.from('clinics').select('logo_path').eq('id', clinicId).maybeSingle();
      return data?.logo_path && VR.loadImageForPdf ? await VR.loadImageForPdf(data.logo_path, 'clinic-assets', 600) : null;
    } catch { return null; }
  };
})();
