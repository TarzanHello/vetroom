// =============================================================
//  VETROOM 2 — funzioni comuni alle pagine della clinica
// =============================================================
(function () {
  const VR = window.VR;

  VR.CLINIC_KEY = 'vetroom_clinica';
  VR.ROLE = { admin: 'Amministratore', vet: 'Veterinario', secretary: 'Segreteria' };

  VR.SPECIES = ['Cane', 'Gatto', 'Coniglio', 'Furetto', 'Roditore', 'Uccello', 'Rettile', 'Cavallo', 'Altro'];
  VR.SEX = { M: 'Maschio', F: 'Femmina', U: 'Non noto' };
  VR.NEUTER = { yes: 'Sterilizzato/a', no: 'Non sterilizzato/a', unknown: 'Non noto' };

  VR.param = (k) => new URLSearchParams(location.search).get(k);

  // Carica sessione, profilo e clinica attiva. Se qualcosa manca, reindirizza.
  VR.loadClinicContext = async () => {
    if (VR.buildShell) VR.buildShell();
    const session = await VR.requireSession();
    const who = document.getElementById('who');
    if (who) who.textContent = session.user.email;

    const profile = await VR.loadProfile(session.user.id);
    if (!profile.is_staff) { VR.go('benvenuto.html'); throw new Error('redirect'); }

    const { data: rows, error } = await VR.sb
      .from('clinic_members')
      .select('role, clinic_id, clinics(name, affiliation_code, status, suspended_reason, terms_accepted_at)')
      .eq('user_id', session.user.id)
      .eq('status', 'active');
    if (error) throw error;
    if (!rows || rows.length === 0) {
      if (/\/clinica\/(index\.html)?$/.test(location.pathname)) throw new Error('noclinic');
      VR.go('clinica/'); throw new Error('redirect');
    }

    const saved = localStorage.getItem(VR.CLINIC_KEY);
    const activeRows = rows.filter((r) => r.clinics?.status !== 'suspended');
    const m = activeRows.find((r) => r.clinic_id === saved) || activeRows[0];
    if (!m) {
      // Tutte le cliniche dell'utente sono sospese
      if (/\/clinica\/(index\.html)?$/.test(location.pathname)) {
        const e = new Error('suspended'); e.clinic = rows[0].clinics || {}; throw e;
      }
      VR.go('clinica/'); throw new Error('redirect');
    }
    localStorage.setItem(VR.CLINIC_KEY, m.clinic_id);
    await VR.requireTerms({ profile, clinicId: m.clinic_id, clinicTerms: m.clinics?.terms_accepted_at, isAdmin: m.role === 'admin' });
    VR.checkPlatformAdmin();
    if (VR.shellSetClinic) VR.shellSetClinic({ clinic: m.clinics || {}, role: m.role });

    VR.ctx = {
      session, profile, memberships: rows,
      clinicId: m.clinic_id, role: m.role, clinic: m.clinics || {},
      isVet: m.role === 'admin' || m.role === 'vet'
    };
    return VR.ctx;
  };

  // Crea in un colpo proprietario (se serve) + animale + collegamento alla clinica.
  // Se il proprietario è già stato creato in un tentativo precedente, lo riusa (niente doppioni).
  VR.createPatient = async ({ clinicId, clientId = null, petId = null, owner = null, pet }) => {
    if (!clientId) {
      const { data, error } = await VR.sb.from('clients').insert({ ...owner, clinic_id: clinicId }).select('id').single();
      if (error) throw error;
      clientId = data.id;
    }
    if (!petId) {
      const { data: p, error: e1 } = await VR.sb.from('pets').insert({ ...pet, created_by_clinic_id: clinicId }).select('id').single();
      if (e1) { e1.clientId = clientId; throw e1; }
      petId = p.id;
    }
    const { error: e2 } = await VR.sb.from('pet_access').insert({ pet_id: petId, clinic_id: clinicId, client_id: clientId });
    if (e2) { e2.clientId = clientId; e2.petId = petId; throw e2; }
    return { clientId, petId };
  };

  // Mostra la voce "Piattaforma" solo agli amministratori della piattaforma
  VR.checkPlatformAdmin = async () => {
    let v = sessionStorage.getItem('vetroom_is_platform');
    if (v === null) {
      const { data } = await VR.sb.rpc('is_platform_admin');
      v = data ? '1' : '0';
      sessionStorage.setItem('vetroom_is_platform', v);
    }
    if (v === '1') document.querySelectorAll('[data-platform-only]').forEach((x) => x.removeAttribute('hidden'));
    return v === '1';
  };

  // Legge un modulo: campi vuoti → null, caselle → vero/falso, numeri → numero
  VR.formValues = (form) => {
    const o = {};
    for (const el of form.elements) {
      if (!el.name || el.disabled) continue;
      if (el.type === 'checkbox') { o[el.name] = el.checked; continue; }
      let v = (el.value || '').trim();
      if (el.dataset.upper !== undefined) v = v.toUpperCase();
      if (v === '') o[el.name] = null;
      else if (el.type === 'number') o[el.name] = Number(v.replace(',', '.'));
      else o[el.name] = v;
    }
    return o;
  };

  VR.fillForm = (form, obj) => {
    for (const el of form.elements) {
      if (!el.name || !(el.name in obj)) continue;
      if (el.type === 'checkbox') el.checked = !!obj[el.name];
      else el.value = obj[el.name] ?? '';
    }
  };

  VR.clientName = (c) =>
    [c?.last_name, c?.first_name].filter(Boolean).join(' ') || 'Cliente senza nome';

  VR.initials = (name) => (name || '?').trim().charAt(0).toUpperCase();

  // "3 anni", "5 mesi", "2 settimane"
  VR.age = (birth) => {
    if (!birth) return '';
    const b = new Date(birth + 'T00:00:00');
    const now = new Date();
    let months = (now.getFullYear() - b.getFullYear()) * 12 + (now.getMonth() - b.getMonth());
    if (now.getDate() < b.getDate()) months--;
    if (months < 0) return '';
    if (months === 0) {
      const weeks = Math.floor((now - b) / (7 * 864e5));
      return weeks <= 1 ? 'meno di 2 settimane' : `${weeks} settimane`;
    }
    if (months < 24) return months === 1 ? '1 mese' : `${months} mesi`;
    const y = Math.floor(months / 12);
    return `${y} anni`;
  };

  VR.petSubtitle = (p) =>
    [p.species, p.breed, VR.age(p.birth_date)].filter(Boolean).join(' · ');

  // Riduce una foto prima del caricamento (max 1200 px, JPEG)
  VR.resizeImage = (file, max = 1200, quality = 0.82, type = 'image/jpeg') => new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      const scale = Math.min(1, max / Math.max(img.width, img.height));
      const c = document.createElement('canvas');
      c.width = Math.round(img.width * scale);
      c.height = Math.round(img.height * scale);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(url);
      c.toBlob((b) => (b ? resolve(b) : reject(new Error('Foto non leggibile'))), type, quality);
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Il file scelto non è un\'immagine valida')); };
    img.src = url;
  });

  // Link temporaneo per vedere un file privato
  VR.fileUrl = async (path, bucket = 'pet-files') => {
    if (!path) return null;
    const { data } = await VR.sb.storage.from(bucket).createSignedUrl(path, 3600);
    return data?.signedUrl || null;
  };

  // Avatar: foto se c'è, altrimenti iniziale
  VR.avatarHtml = (pet, url, cls = 'avatar') =>
    url ? `<img class="${cls}" src="${VR.esc(url)}" alt="">`
        : `<span class="${cls}" aria-hidden="true">${VR.esc(VR.initials(pet.name))}</span>`;

  // ---------- WhatsApp ed email ----------
  VR.waPhone = (p) => {
    let d = String(p || '').replace(/[^\d+]/g, '');
    if (!d) return null;
    if (d.startsWith('+')) d = d.slice(1);
    else if (d.startsWith('00')) d = d.slice(2);
    else if (/^3\d{8,9}$/.test(d) || /^0\d{5,10}$/.test(d)) d = '39' + d;
    return d.length >= 8 ? d : null;
  };
  VR.waLink = (phone, text) => {
    const n = VR.waPhone(phone);
    return `https://wa.me/${n || ''}?text=${encodeURIComponent(text)}`;
  };
  VR.mailLink = (email, subject, text) => `mailto:${encodeURIComponent(email || '')}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(text)}`;
  VR.OWNER_PORTAL = 'https://www.vetroom.it/app/?per=proprietario';
  VR.fmtWhen = (d) => new Date(d).toLocaleString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
  VR.apptMessage = ({ pet, startsAt, clinic }) =>
    `Buongiorno, le ricordiamo l'appuntamento${pet ? ' per ' + pet : ''} ${VR.fmtWhen(startsAt).replace(',', ' alle')} presso ${clinic}. ` +
    `Se non potesse venire, ci avvisi rispondendo a questo messaggio. Grazie!`;
  VR.reminderMessage = ({ pet, title, due, clinic }) =>
    `Buongiorno, per ${pet} è in scadenza: ${title} (entro il ${new Date(due + 'T00:00:00').toLocaleDateString('it-IT')}). ` +
    `Può prenotare rispondendo a questo messaggio oppure dal libretto FurrFinder: ${VR.OWNER_PORTAL}\n${clinic}`;

  // ---------- Grafico del peso (SVG, senza librerie) ----------
  VR.weightChart = (el, points) => {
    const pts = points.filter((p) => p.w > 0 && p.d).sort((a, b) => a.d.localeCompare(b.d));
    if (pts.length < 2) { el.innerHTML = pts.length ? `<p class="muted" style="margin:0">Una sola misura: ${String(pts[0].w).replace('.', ',')} kg il ${new Date(pts[0].d + 'T00:00:00').toLocaleDateString('it-IT')}. Il grafico compare dalla seconda visita con il peso.</p>` : '<p class="muted" style="margin:0">Nessun peso registrato: si aggiunge dalle visite.</p>'; return; }
    const W = 420, H = 170, P = { l: 40, r: 12, t: 12, b: 24 };
    const xs = pts.map((p) => new Date(p.d).getTime()), ws = pts.map((p) => p.w);
    const x0 = Math.min(...xs), x1 = Math.max(...xs), wmin = Math.min(...ws), wmax = Math.max(...ws);
    const pad = Math.max(0.5, (wmax - wmin) * 0.15), y0 = Math.max(0, wmin - pad), y1 = wmax + pad;
    const X = (t) => P.l + (x1 === x0 ? 0.5 : (t - x0) / (x1 - x0)) * (W - P.l - P.r);
    const Y = (w) => P.t + (1 - (w - y0) / (y1 - y0)) * (H - P.t - P.b);
    const line = pts.map((p, i) => `${i ? 'L' : 'M'}${X(xs[i]).toFixed(1)},${Y(p.w).toFixed(1)}`).join(' ');
    const ticks = [y0, (y0 + y1) / 2, y1].map((v) => `<text x="${P.l - 6}" y="${Y(v) + 4}" text-anchor="end">${v.toFixed(1).replace('.', ',')}</text><line x1="${P.l}" x2="${W - P.r}" y1="${Y(v)}" y2="${Y(v)}" class="grid"/>`).join('');
    const fmt = (t) => new Date(t).toLocaleDateString('it-IT', { month: 'short', year: '2-digit' });
    el.innerHTML = `<svg viewBox="0 0 ${W} ${H}" class="wchart" role="img" aria-label="Andamento del peso">
      ${ticks}<path d="${line}" class="ln"/>
      ${pts.map((p, i) => `<circle cx="${X(xs[i])}" cy="${Y(p.w)}" r="4"><title>${new Date(p.d + 'T00:00:00').toLocaleDateString('it-IT')}: ${String(p.w).replace('.', ',')} kg</title></circle>`).join('')}
      <text x="${P.l}" y="${H - 6}">${fmt(x0)}</text><text x="${W - P.r}" y="${H - 6}" text-anchor="end">${fmt(x1)}</text>
    </svg><p class="hint-small" style="margin:4px 0 0">Ultimo peso: <b>${String(pts[pts.length - 1].w).replace('.', ',')} kg</b> (${new Date(pts[pts.length - 1].d + 'T00:00:00').toLocaleDateString('it-IT')})</p>`;
  };

  // ---------- Dettatura (dove il browser la supporta) ----------
  VR.enableDictation = (root) => {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) return;
    root.querySelectorAll('textarea[data-dictate]').forEach((ta) => {
      if (ta.dataset.dictReady) return; ta.dataset.dictReady = '1';
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'dictate-btn'; b.title = 'Detta'; b.setAttribute('aria-label', 'Detta'); b.textContent = '🎤';
      ta.insertAdjacentElement('beforebegin', b);
      let rec = null;
      b.addEventListener('click', () => {
        if (rec) { rec.stop(); return; }
        rec = new SR(); rec.lang = VR.lang === 'en' ? 'en-GB' : 'it-IT'; rec.continuous = true; rec.interimResults = false;
        rec.onresult = (e) => {
          const t = [...e.results].slice(e.resultIndex).map((r) => r[0].transcript).join(' ').trim();
          if (t) { ta.value = (ta.value ? ta.value.replace(/\s*$/, ' ') : '') + t; ta.dispatchEvent(new Event('input', { bubbles: true })); }
        };
        rec.onend = () => { rec = null; b.classList.remove('on'); };
        rec.onerror = () => { rec = null; b.classList.remove('on'); };
        b.classList.add('on'); rec.start();
      });
    });
  };

  VR.fillSpecies = (select) => {
    select.innerHTML = '<option value="">—</option>' +
      VR.SPECIES.map((s) => `<option>${s}</option>`).join('');
  };
})();

// =============================================================
//  FURRFINDER per la clinica: invito personale e poster con QR
// =============================================================
(function () {
  const VR = window.VR;
  VR.ff = {};

  // Link personale: il cliente entra con Google e trova il libretto già compilato
  VR.ff.inviteLink = async (clientId) => {
    const { data, error } = await VR.sb.rpc('clinic_owner_invite', { p_client: clientId });
    if (error) throw error;
    return new URL('?libretto=' + encodeURIComponent(data), VR.root).href;
  };
  VR.ff.inviteText = ({ firstName, petNames = [], clinicName, link }) => {
    const pets = petNames.filter(Boolean);
    const petPart = pets.length === 0 ? 'del tuo animale' : pets.length === 1 ? `di ${pets[0]}` : `di ${pets.slice(0, -1).join(', ')} e ${pets[pets.length - 1]}`;
    return `Ciao${firstName ? ' ' + firstName : ''}, ti scriviamo da ${clinicName}. Da oggi il libretto sanitario ${petPart} è anche sul telefono, gratis con FurrFinder:\n` +
      `• ti avvisiamo noi di richiami e appuntamenti\n• referti e vaccini sempre con te\n• prenoti online quando vuoi\n\n` +
      `Attivalo da qui (entri con il tuo account Google e trovi già tutto): ${link}`;
  };

  // Finestra "Invita su FurrFinder": anteprima modificabile + WhatsApp, email o copia
  VR.ff.openInvite = async ({ client, petNames = [], clinicName }) => {
    let dlg = document.getElementById('ff-invite');
    if (!dlg) {
      dlg = document.createElement('dialog');
      dlg.id = 'ff-invite'; dlg.className = 'dlg';
      dlg.innerHTML = `
        <h2 style="margin-top:0">Invita su FurrFinder</h2>
        <p class="hint-small" id="ffiWho" style="margin-top:0"></p>
        <div id="ffiBody"><p class="loader">Preparo il link personale…</p></div>
        <div class="actions-bar" style="margin-top:12px">
          <a class="btn btn-primary" id="ffiWa" target="_blank" rel="noopener" hidden>Invia su WhatsApp</a>
          <a class="btn btn-ghost" id="ffiMail" hidden>Invia per email</a>
          <button class="btn btn-ghost" type="button" id="ffiCopy" hidden>Copia messaggio</button>
          <span class="spacer"></span>
          <button class="btn btn-ghost" type="button" id="ffiClose">Chiudi</button>
        </div>`;
      document.body.appendChild(dlg);
      dlg.querySelector('#ffiClose').addEventListener('click', () => dlg.close());
      dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
    }
    const who = VR.clientName(client);
    dlg.querySelector('#ffiWho').textContent = `Link personale per ${who}: aprendolo entra con Google e trova il libretto già compilato. Vale 30 giorni e si usa una volta sola.`;
    const body = dlg.querySelector('#ffiBody');
    ['#ffiWa', '#ffiMail', '#ffiCopy'].forEach((s) => VR.hide(dlg.querySelector(s)));
    body.innerHTML = '<p class="loader">Preparo il link personale…</p>';
    if (!dlg.open) dlg.showModal();
    let link;
    try { link = await VR.ff.inviteLink(client.id); } catch (e) {
      body.innerHTML = `<div class="notice notice-error">${VR.esc(VR.errorText(e))}</div>`; return;
    }
    const firstName = String(client.first_name || '').trim().split(/\s+/)[0] || '';
    body.innerHTML = `<label for="ffiText">Messaggio (puoi modificarlo)</label><textarea id="ffiText" rows="8"></textarea>`;
    const ta = body.querySelector('#ffiText');
    ta.value = VR.ff.inviteText({ firstName, petNames, clinicName, link });
    const refresh = () => {
      const wa = dlg.querySelector('#ffiWa'), ml = dlg.querySelector('#ffiMail');
      if (client.phone && VR.waPhone(client.phone)) { wa.href = VR.waLink(client.phone, ta.value); VR.show(wa); } else VR.hide(wa);
      if (client.email) { ml.href = VR.mailLink(client.email, 'Il libretto sanitario digitale del tuo animale', ta.value); VR.show(ml); } else VR.hide(ml);
      VR.show(dlg.querySelector('#ffiCopy'));
    };
    ta.addEventListener('input', refresh);
    dlg.querySelector('#ffiCopy').onclick = async (ev) => {
      try { await navigator.clipboard.writeText(ta.value); ev.target.textContent = 'Copiato!'; setTimeout(() => { ev.target.textContent = 'Copia messaggio'; }, 1500); }
      catch { ta.select(); }
    };
    refresh();
  };

  // Poster A4 da stampare (vetrina, sala d'attesa): logo della clinica, tre vantaggi, QR grande
  VR.ff.poster = async (clinic) => {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'mm', format: 'a4' });
    const W = 210, NAVY = [11, 37, 69], ORANGE = [249, 115, 22], GREY = [90, 100, 115];
    const code = clinic.affiliation_code || '';
    const link = new URL('?codice=' + encodeURIComponent(code), VR.root).href;
    let y = 18;
    const logo = clinic.logo_path ? await VR.loadImageForPdf(clinic.logo_path).catch(() => null) : null;
    if (logo) {
      const r = Math.min(70 / logo.w, 30 / logo.h);
      const w = logo.w * r, h = logo.h * r;
      doc.addImage(logo.dataUrl, logo.fmt, (W - w) / 2, y, w, h);
      y += h + 6;
      doc.setFont('helvetica', 'bold'); doc.setFontSize(14); doc.setTextColor(...GREY);
      doc.text(clinic.name || '', W / 2, y, { align: 'center' }); y += 12;
    } else {
      doc.setFont('helvetica', 'bold'); doc.setFontSize(24); doc.setTextColor(...NAVY);
      doc.text(doc.splitTextToSize(clinic.name || '', 170), W / 2, y + 8, { align: 'center' }); y += 22;
    }
    doc.setDrawColor(...ORANGE); doc.setLineWidth(1.2); doc.line(W / 2 - 20, y, W / 2 + 20, y); y += 12;
    doc.setFont('helvetica', 'bold'); doc.setFontSize(25); doc.setTextColor(...NAVY);
    doc.text(['Il libretto sanitario del tuo animale,', 'sempre sul telefono'], W / 2, y, { align: 'center' });
    y += 22;
    doc.setFont('helvetica', 'normal'); doc.setFontSize(14); doc.setTextColor(...GREY);
    doc.text('Gratis con FurrFinder, collegato al tuo veterinario', W / 2, y, { align: 'center' }); y += 12;
    const items = [
      ['Ti avvisiamo noi', 'Vaccini, richiami e appuntamenti: il promemoria arriva da solo.'],
      ['Referti e vaccini sempre con te', 'Tutta la storia clinica del tuo animale, senza telefonare.'],
      ['Prenoti quando vuoi', 'Scegli tu l\'orario tra quelli liberi dello studio.']
    ];
    items.forEach(([t, s]) => {
      doc.setFillColor(...ORANGE); doc.circle(34, y - 1.5, 2.2, 'F');
      doc.setFont('helvetica', 'bold'); doc.setFontSize(14); doc.setTextColor(...NAVY); doc.text(t, 40, y);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(11.5); doc.setTextColor(...GREY); doc.text(s, 40, y + 6);
      y += 16;
    });
    // QR vettoriale: nitido a qualsiasi dimensione di stampa
    const qr = qrcode(0, 'M'); qr.addData(link); qr.make();
    const n = qr.getModuleCount(), size = 72, cell = size / n, qx = (W - size) / 2, qy = y + 2;
    doc.setFillColor(255, 255, 255); doc.setDrawColor(...NAVY); doc.setLineWidth(0.8);
    doc.roundedRect(qx - 6, qy - 6, size + 12, size + 12, 4, 4, 'FD');
    doc.setFillColor(0, 0, 0);
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (qr.isDark(r, c)) doc.rect(qx + c * cell, qy + r * cell, cell + 0.02, cell + 0.02, 'F');
    y = qy + size + 16;
    doc.setFont('helvetica', 'bold'); doc.setFontSize(15); doc.setTextColor(...NAVY);
    doc.text('Inquadra il codice con la fotocamera del telefono', W / 2, y, { align: 'center' }); y += 8;
    doc.setFont('helvetica', 'normal'); doc.setFontSize(11.5); doc.setTextColor(...GREY);
    doc.text('oppure su furrfinder.com inserisci il codice dello studio:', W / 2, y, { align: 'center' }); y += 11;
    doc.setFont('courier', 'bold'); doc.setFontSize(26); doc.setTextColor(...NAVY);
    doc.text(code.split('').join(' '), W / 2, y, { align: 'center' });
    doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(150, 155, 165);
    doc.text('FurrFinder è gratuito per i proprietari · un servizio collegato a Vetroom', W / 2, 287, { align: 'center' });
    doc.save(`FurrFinder - ${String(clinic.name || 'clinica').replace(/[\\/:*?"<>|]+/g, ' ').trim()}.pdf`);
  };
})();
