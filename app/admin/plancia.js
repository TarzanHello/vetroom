// =============================================================
//  VETROOM · LA PLANCIA — struttura comune a tutte le pagine admin
//  Uso: <body class="pl" data-station="ponte"> … PL.boot().then(...)
// =============================================================
(function () {
  const VR = window.VR;
  const PL = (window.PL = {});
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  PL.$ = $; PL.$$ = $$;
  const esc = VR.esc;
  PL.esc = esc;

  // ---------- Icone (disegnate apposta, tratto sottile) ----------
  const I = {
    ponte: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="3.5"/><path d="M12 3.5v3M12 17.5v3M3.5 12h3M17.5 12h3"/></svg>',
    cliniche: '<svg viewBox="0 0 24 24"><path d="M4 20V9l8-5 8 5v11"/><path d="M9 20v-6h6v6"/><path d="M12 8.5v3M10.5 10h3"/></svg>',
    utenti: '<svg viewBox="0 0 24 24"><circle cx="9" cy="8.5" r="3.2"/><path d="M3.5 19c.6-3.3 2.8-5 5.5-5s4.9 1.7 5.5 5"/><circle cx="17" cy="9.5" r="2.4"/><path d="M15.5 14.3c2.6-.3 4.4 1.2 5 4.2"/></svg>',
    prenotazioni: '<svg viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15"/><path d="M3.5 9.5h17M8 3v4M16 3v4M8 14l2.5 2.5L16 12"/></svg>',
    messaggi: '<svg viewBox="0 0 24 24"><path d="M4 5h16v11H9l-5 4z"/><path d="M8 9.5h8M8 12.5h5"/></svg>',
    comunicazioni: '<svg viewBox="0 0 24 24"><path d="M4 10v4h3l6 4V6L7 10z"/><path d="M16.5 9a4 4 0 0 1 0 6M19 6.5a7.5 7.5 0 0 1 0 11"/></svg>',
    diario: '<svg viewBox="0 0 24 24"><path d="M6 3.5h11l2 2v15H6z"/><path d="M9 8h7M9 11.5h7M9 15h4"/></svg>',
    sala: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/></svg>',
    collaudo: '<svg viewBox="0 0 24 24"><path d="M12 3l7.5 3v5.5c0 4.6-3.2 8.2-7.5 9.5-4.3-1.3-7.5-4.9-7.5-9.5V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/></svg>',
    cestino: '<svg viewBox="0 0 24 24"><path d="M4.5 7h15M9.5 7V4.5h5V7M6.5 7l1 13h9l1-13"/><path d="M10 11v6M14 11v6"/></svg>',
    cerca: '<svg viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M15.5 15.5L20 20"/></svg>',
    menu: '<svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>'
  };
  PL.icon = I;

  const STATIONS = [
    { g: 'Comando', items: [
      { k: 'ponte', l: 'Ponte di comando', h: './' },
      { k: 'cliniche', l: 'Strutture', h: 'cliniche.html' },
      { k: 'utenti', l: 'Utenti', h: 'utenti.html' },
      { k: 'prenotazioni', l: 'Prenotazioni', h: 'prenotazioni.html' }
    ] },
    { g: 'Contatti', items: [
      { k: 'messaggi', l: 'Messaggi', h: 'messaggi.html', badge: 'support' },
      { k: 'comunicazioni', l: 'A tutti, annunci, email', h: 'comunicazioni.html' }
    ] },
    { g: 'Controllo', items: [
      { k: 'diario', l: 'Diario di bordo', h: 'diario.html' },
      { k: 'collaudo', l: 'Collaudo', h: 'collaudo.html', badge: 'health' },
      { k: 'sala', l: 'Sala macchine', h: 'sala-macchine.html', badge: 'errors' },
      { k: 'cestino', l: 'Cestino', h: 'cestino.html', badge: 'trash' }
    ] }
  ];

  // ---------- Formati ----------
  PL.n = (x) => Number(x || 0).toLocaleString('it-IT');
  PL.d = (x) => (x ? new Date(x).toLocaleDateString('it-IT') : '—');
  PL.dt = (x) => (x ? new Date(x).toLocaleString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');
  PL.hm = (x) => new Date(x).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
  PL.ago = (x) => {
    if (!x) return '—';
    const t = new Date(x), now = new Date(), s = (now - t) / 1000;
    if (s < 60) return 'adesso';
    if (s < 3600) return `${Math.floor(s / 60)} min fa`;
    const days = Math.round((new Date(now.getFullYear(), now.getMonth(), now.getDate()) - new Date(t.getFullYear(), t.getMonth(), t.getDate())) / 864e5);
    if (days <= 0) return 'oggi, ' + PL.hm(t);
    if (days === 1) return 'ieri, ' + PL.hm(t);
    if (days < 30) return `${days} giorni fa`;
    return PL.d(x);
  };
  PL.bytes = (b) => {
    b = Number(b || 0);
    if (b < 1024) return b + ' B';
    const u = ['KB', 'MB', 'GB', 'TB']; let i = -1;
    do { b /= 1024; i++; } while (b >= 1024 && i < u.length - 1);
    return b.toLocaleString('it-IT', { maximumFractionDigits: b < 10 ? 1 : 0 }) + ' ' + u[i];
  };
  PL.mailStatus = (s) => ({ sent: 'inviata', failed: 'non partita', pending: 'in coda', queued: 'in coda' }[s] || s || '');
  PL.chip = (c) => (c ? `<span class="chip">${esc(c)}</span>` : '');
  PL.ROLE = { admin: 'Amministratore', vet: 'Veterinario', secretary: 'Segreteria' };
  PL.initials = (s) => String(s || '?').replace(/^(dott\.?|dr\.?|dott\.ssa)\s+/i, '').split(/[\s@.]+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('') || '?';

  // ---------- Chiamate al database ----------
  PL.missing = (e) => e && (e.code === 'PGRST202' || /Could not find the function|does not exist/i.test(e.message || ''));
  PL.rpc = async (name, args) => {
    const { data, error } = await VR.sb.rpc(name, args || {});
    if (error) {
      if (PL.missing(error)) {
        const err = new Error(`Manca una funzione del database (${name}): esegui l'ultima patch SQL su Supabase.`);
        err.missing = true; throw err;
      }
      throw new Error(VR.errorText(error));
    }
    return data;
  };
  PL.try = async (name, args, fallback = null) => { try { return await PL.rpc(name, args); } catch { return fallback; } };
  PL.KIND = { ambulatorio: 'Ambulatorio', clinica: 'Clinica', studio_associato: 'Studio associato', ospedale: 'Ospedale veterinario', domicilio: 'A domicilio', altro: 'Altro' };
  PL.BILLING = { struttura: 'Fattura la struttura', individuale: 'Ognuno a nome proprio', misto: 'Misto' };

  // Codici: id ⇄ codice, per indirizzi leggibili (clinica.html?c=CL-XXXXX)
  let codesP = null;
  PL.codes = () => (codesP ||= PL.try('platform_codes', {}, { clinics: {}, users: {} }).then((c) => c || { clinics: {}, users: {} }));
  PL.clinicHref = (id, code) => `clinica.html?${code ? 'c=' + encodeURIComponent(code) : 'id=' + id}`;
  PL.userHref = (id, code) => `utente.html?${code ? 'u=' + encodeURIComponent(code) : 'id=' + id}`;
  // Legge dall'indirizzo quale clinica/utente aprire e restituisce l'id
  PL.resolve = async (kind) => {
    const q = new URLSearchParams(location.search);
    const id = q.get('id');
    if (id) return id;
    const code = (q.get(kind === 'clinic' ? 'c' : 'u') || '').trim().toUpperCase();
    if (!code) return null;
    if (kind === 'clinic' && /^ST-?[A-Z0-9]{5}$/.test(code)) {
      const st = await PL.try('platform_clinic_codes', {}, {}) || {};
      const k = code.replace('-', '');
      const hit = Object.entries(st).find(([, c]) => String(c).replace('-', '') === k);
      return hit ? hit[0] : null;
    }
    const codes = await PL.codes();
    const map = kind === 'clinic' ? codes.clinics : codes.users;
    const hit = Object.entries(map || {}).find(([, c]) => String(c).toUpperCase() === code);
    return hit ? hit[0] : null;
  };

  // ---------- Avvisi ----------
  PL.toast = (text, kind = 'ok') => {
    let box = $('.toasts');
    if (!box) { box = document.createElement('div'); box.className = 'toasts'; box.setAttribute('aria-live', 'polite'); document.body.appendChild(box); }
    const t = document.createElement('div');
    t.className = 'toast ' + kind; t.textContent = text;
    box.appendChild(t);
    setTimeout(() => t.remove(), kind === 'bad' ? 9000 : 5000);
  };
  PL.say = (el, text, kind = 'info') => { if (!el) return; el.className = 'note ' + kind; el.textContent = text; el.hidden = false; };

  // ---------- Finestre di dialogo (al posto di prompt/confirm del browser) ----------
  // PL.ask({ title, html, fields:[{name,label,type,value,placeholder,rows,options}], word, ok, danger })
  // → oggetto con i valori, oppure null se annullato
  PL.ask = (o) => new Promise((resolve) => {
    const d = document.createElement('dialog');
    d.className = 'dl' + (o.danger ? ' danger' : '') + (o.wide ? ' wide' : '');
    const fields = (o.fields || []).map((f, i) => {
      const id = 'f' + i + Math.random().toString(36).slice(2, 6);
      const val = esc(f.value ?? '');
      let ctl;
      if (f.type === 'textarea') ctl = `<textarea id="${id}" name="${f.name}" rows="${f.rows || 5}" placeholder="${esc(f.placeholder || '')}">${val}</textarea>`;
      else if (f.type === 'select') ctl = `<select id="${id}" name="${f.name}">${f.options.map(([v, l]) => `<option value="${esc(v)}" ${String(v) === String(f.value) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
      else if (f.type === 'checkbox') return `<label class="check" style="margin-top:12px"><input type="checkbox" name="${f.name}" ${f.value ? 'checked' : ''}> ${esc(f.label)}</label>`;
      else ctl = `<input id="${id}" name="${f.name}" type="${f.type || 'text'}" value="${val}" placeholder="${esc(f.placeholder || '')}" autocomplete="off">`;
      return `<label class="f" for="${id}">${esc(f.label)}</label>${ctl}`;
    }).join('');
    const word = o.word ? `<label class="f" for="cw">Per confermare scrivi <span class="confirm-word">${esc(o.word)}</span></label><input id="cw" name="__word" type="text" autocomplete="off" spellcheck="false">` : '';
    d.innerHTML = `<form method="dialog" novalidate>
      <h2>${esc(o.title || 'Conferma')}</h2>
      ${o.html ? `<div class="dim" style="margin-bottom:6px">${o.html}</div>` : ''}
      ${fields}${word}
      <div class="note bad" hidden data-err style="margin-top:12px"></div>
      <div class="foot">
        <button class="btn ghost" type="button" data-x>Annulla</button>
        <button class="btn ${o.danger ? 'danger' : 'prim'}" type="submit" data-ok ${o.word ? 'disabled' : ''}>${esc(o.ok || 'Conferma')}</button>
      </div></form>`;
    document.body.appendChild(d);
    const form = d.querySelector('form'), okb = d.querySelector('[data-ok]');
    const done = (v) => { d.close(); d.remove(); resolve(v); };
    if (o.word) d.querySelector('#cw').addEventListener('input', (e) => { okb.disabled = e.target.value.trim().toUpperCase() !== String(o.word).toUpperCase(); });
    d.querySelector('[data-x]').addEventListener('click', () => done(null));
    d.addEventListener('cancel', (e) => { e.preventDefault(); done(null); });
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const v = {};
      for (const el of form.elements) { if (!el.name) continue; v[el.name] = el.type === 'checkbox' ? el.checked : el.value; }
      for (const f of o.fields || []) {
        if (f.required && !String(v[f.name] || '').trim()) { PL.say(d.querySelector('[data-err]'), `Compila il campo "${f.label}".`, 'bad'); return; }
      }
      if (o.word) {
        // Il controllo accetta maiuscole/minuscole e spazi ai lati: al server va sempre la parola esatta
        if (String(v.__word || '').trim().toUpperCase() !== String(o.word).toUpperCase()) { PL.say(d.querySelector('[data-err]'), `Per confermare scrivi ${o.word}.`, 'bad'); return; }
        v.__word = String(o.word);
      }
      done(v);
    });
    d.showModal();
    const first = d.querySelector('input, textarea, select'); if (first) first.focus();
  });
  // Esegue un'azione con il pulsante che mostra l'attesa e un avviso finale
  PL.run = async (btn, fn, okText) => {
    const old = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Attendi…'; }
    try {
      const r = await fn();
      const msg = typeof okText === 'function' ? okText(r) : (okText || (typeof r === 'string' ? r : 'Fatto.'));
      if (msg) PL.toast(msg, 'ok');
      return r;
    } catch (e) { PL.toast(e.message || String(e), 'bad'); return undefined; }
    finally { if (btn) { btn.disabled = false; btn.innerHTML = old; } }
  };

  // ---------- Esportazione ----------
  PL.download = (name, text, type = 'text/plain') => {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([text], { type }));
    a.download = name; document.body.appendChild(a); a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  };
  PL.csv = (name, rows, cols) => {
    const q = (v) => { const s = v == null ? '' : String(v); return /[";\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
    // punto e virgola: Excel italiano apre il file già diviso in colonne
    const out = [cols.map((c) => q(c[0])).join(';'), ...rows.map((r) => cols.map((c) => q(c[1](r))).join(';'))].join('\r\n');
    PL.download(name, '\ufeff' + out, 'text/csv;charset=utf-8');
  };

  // ---------- Tabella con ordinamento e pagine ----------
  // PL.table({ el, cols:[{k,l,sort:(r)=>v,cls,render:(r)=>html,hideM}], rows, pageSize, onRow:(r)=>href, empty })
  PL.table = (o) => {
    const st = { key: o.sortKey || null, dir: o.sortDir || -1, page: 0, rows: o.rows || [] };
    const draw = () => {
      let rows = st.rows.slice();
      const col = o.cols.find((c) => c.k === st.key);
      if (col && col.sort) rows.sort((a, b) => {
        const x = col.sort(a), y = col.sort(b);
        if (x == null && y == null) return 0; if (x == null) return 1; if (y == null) return -1;
        return (typeof x === 'string' ? x.localeCompare(y, 'it') : x - y) * st.dir;
      });
      const size = o.pageSize || 25, pages = Math.max(1, Math.ceil(rows.length / size));
      st.page = Math.min(st.page, pages - 1);
      const view = rows.slice(st.page * size, st.page * size + size);
      o.el.innerHTML = `<div class="tw"><table class="t"><thead><tr>${o.cols.map((c) => `<th class="${c.cls || ''} ${c.hideM ? 'hide-m' : ''}" ${c.sort ? `data-sort="${c.k}"` : ''} ${st.key === c.k ? `aria-sort="${st.dir > 0 ? 'ascending' : 'descending'}"` : ''}>${esc(c.l)}</th>`).join('')}</tr></thead>
        <tbody>${view.map((r, i) => `<tr class="${o.onRow ? 'go' : ''} ${o.rowClass ? o.rowClass(r) : ''}" data-i="${st.page * size + i}">${o.cols.map((c) => `<td class="${c.cls || ''} ${c.hideM ? 'hide-m' : ''}">${c.render(r)}</td>`).join('')}</tr>`).join('')
          || `<tr><td colspan="${o.cols.length}" class="empty">${o.empty || 'Niente da mostrare.'}</td></tr>`}</tbody></table></div>
        ${rows.length > size ? `<div class="pager"><span>${PL.n(st.page * size + 1)}–${PL.n(Math.min(rows.length, (st.page + 1) * size))} di ${PL.n(rows.length)}</span>
          <button class="btn sm ghost" data-pg="-1" ${st.page === 0 ? 'disabled' : ''}>Indietro</button>
          <button class="btn sm ghost" data-pg="1" ${st.page >= pages - 1 ? 'disabled' : ''}>Avanti</button></div>` : ''}`;
      st.sorted = rows;
    };
    o.el.addEventListener('click', (e) => {
      const th = e.target.closest('th[data-sort]');
      if (th) { const k = th.dataset.sort; if (st.key === k) st.dir *= -1; else { st.key = k; st.dir = -1; } draw(); return; }
      const pg = e.target.closest('[data-pg]');
      if (pg) { st.page += Number(pg.dataset.pg); draw(); o.el.scrollIntoView({ block: 'nearest' }); return; }
      if (e.target.closest('a, button, input, select')) return;
      const tr = e.target.closest('tr[data-i]');
      if (tr && o.onRow) { const href = o.onRow(st.sorted[Number(tr.dataset.i)]); if (href) { if (e.ctrlKey || e.metaKey) window.open(href); else location.href = href; } }
    });
    draw();
    return { set(rows) { st.rows = rows || []; st.page = 0; draw(); }, get rows() { return st.sorted || []; } };
  };

  // ---------- Diario: frasi leggibili ----------
  const ST = { confirmed: 'ha confermato', cancelled: 'ha annullato', done: 'ha segnato come svolto', no_show: 'ha segnato come assente', requested: 'ha richiesto' };
  PL.logLine = (a) => {
    const m = a.meta || {};
    const T = a.target_name ? esc(a.target_name) : 'un account';
    const L = a.label ? `<span class="mono">${esc(a.label)}</span>` : '';
    const role = (r) => esc(PL.ROLE[r] || r || '');
    const map = {
      'account.creato': 'ha creato il suo account',
      'account.ruolo_clinica': 'ha scelto di lavorare in una struttura veterinaria',
      'account.ruolo_proprietario': 'ha scelto “Ho un animale”',
      'clinica.creata': `ha creato la clinica ${esc(a.label || '')}`,
      'clinica.sospesa': `ha sospeso la clinica ${esc(a.label || '')}`,
      'clinica.riattivata': `ha riattivato la clinica ${esc(a.label || '')}`,
      'clinica.rinominata': `ha rinominato la clinica in ${esc(a.label || '')}`,
      'team.entrato': a.actor_id && a.actor_id === a.target_id ? `ha accettato l'invito nel team come ${role(m.ruolo)}` : `ha inserito nel team ${T} come ${role(m.ruolo)}`,
      'team.uscito': a.actor_id && a.actor_id === a.target_id ? 'ha lasciato il team' : `ha tolto dal team ${T}`,
      'team.modificato': `ha cambiato ruolo o stato di ${T}`,
      'team.invito': `ha invitato un nuovo membro come ${role(m.ruolo)}`,
      'anagrafica.proprietario': 'ha registrato un proprietario',
      'anagrafica.animale': m.da === 'proprietario' ? 'ha aggiunto un animale su FurrFinder' : 'ha registrato un animale',
      'furrfinder.collegato': 'ha collegato un animale alla clinica (FurrFinder)',
      'visita.creata': `ha aperto la visita ${L}`,
      'visita.referto': `ha reso definitivo il referto ${L}`,
      'prenotazione.online': `ha prenotato online ${L}`,
      'prenotazione.creata': `ha fissato l'appuntamento ${L}`,
      'prenotazione.spostata': `ha spostato l'appuntamento ${L}`,
      'documento.caricato': 'ha caricato un documento',
      'cassa.fattura': `ha emesso la fattura ${L}`,
      'cassa.incasso': `ha registrato l'incasso della fattura ${L}`,
      'cassa.nota_credito': `ha emesso la nota di credito ${L}`,
      'struttura.creata': `ha creato la struttura ${esc(a.label || '')}${m.tipo ? ' (' + esc(PL.KIND[m.tipo] || m.tipo) + ')' : ''}`,
      'struttura.cessione_proposta': `ha proposto di cedere la titolarità a ${T}`,
      'struttura.ceduta': 'ha accettato ed è diventato titolare della struttura',
      'team.richiesta': `ha chiesto di unirsi alla struttura come ${m.ruolo === 'vet' ? 'veterinario' : 'personale'}`,
      'team.richiesta_approvata': `ha approvato la richiesta di adesione di ${T} come ${role(m.ruolo)}`,
      'admin.albo_verificato': `ha segnato come verificata l'iscrizione all'Ordine di ${T}`,
      'admin.albo_non_verificato': `ha tolto la verifica dell'iscrizione all'Ordine di ${T}`,
      'cassa.invio_ts': `ha inviato ${Number(m.inviati || 0)} documenti al Sistema TS (${Number(m.accolti || 0)} accolti${Number(m.scartati) ? ', ' + Number(m.scartati) + ' scartati' : ''})`,
      'messaggio.inviato': m.tipo === 'clinic_owner' ? 'ha scritto un messaggio (clinica ⇄ proprietario)' : 'ha scritto all\'assistenza',
      'admin.nota': `ha aggiunto una nota interna su ${a.target_kind === 'clinic' ? esc(a.target_name || 'una clinica') : T}`,
      'admin.account_sospeso': `ha sospeso l'account di ${T}`,
      'admin.account_riattivato': `ha riattivato l'account di ${T}`,
      'admin.scollegato': `ha scollegato ${T} da tutti i dispositivi`,
      'admin.team_modificato': `ha cambiato ruolo di ${T} (${role(m.ruolo)})`,
      'admin.team_rimosso': `ha tolto ${T} dal team`,
      'admin.cestino': `ha messo nel cestino ${esc(a.label || '')}`,
      'admin.ripristinato': `ha ripristinato ${esc(a.label || '')}`,
      'admin.eliminato': `ha eliminato definitivamente ${esc(a.label || '')}`,
      'admin.export_dati': `ha esportato i dati di ${T}`,
      'admin.nuovo_amministratore': `ha nominato amministratore ${T}`,
      'admin.tolto_amministratore': `ha tolto ${T} dagli amministratori`,
      'admin.annuncio': `ha pubblicato l'annuncio “${esc(a.label || '')}”`,
      'admin.messaggio_gruppo': `ha scritto a un gruppo scelto (${Number(m.destinatari || 0)} destinatari)`,
      'admin.broadcast': `ha scritto a tutti ${a.label === '/all_vet' ? 'i veterinari' : 'i proprietari'} (${Number(m.destinatari || 0)} destinatari)`,
      'admin.impostazione': a.label === 'maintenance' ? (m.on ? 'ha ATTIVATO la modalità manutenzione' : 'ha disattivato la modalità manutenzione') : `ha cambiato l'impostazione ${esc(a.label || '')}`
    };
    let what = map[a.action];
    if (!what && a.action.startsWith('prenotazione.')) what = `${ST[a.action.split('.')[1]] || 'ha aggiornato'} l'appuntamento ${L}`;
    if (!what) what = esc(a.action);
    // le azioni sull'account senza autore (es. l'iscrizione) hanno come protagonista l'account stesso
    const selfAct = !a.actor_id && a.target_kind === 'user' && a.target_id && /^account\./.test(a.action);
    const who = selfAct ? (a.target_name || 'Nuovo utente') : a.actor_kind === 'system' && !a.actor_id ? 'Sistema' : (a.actor_name || a.actor_email || 'Qualcuno');
    const whoId = selfAct ? a.target_id : a.actor_id, whoCode = selfAct ? a.target_code : a.actor_code;
    const whoHtml = whoId ? `<a class="who" href="${PL.userHref(whoId, whoCode)}">${esc(who)}</a>` : `<span class="who">${esc(who)}</span>`;
    const ctx = [a.clinic_name ? `<a href="${PL.clinicHref(a.clinic_id)}">${esc(a.clinic_name)}</a>` : '',
      a.target_kind === 'user' && a.target_id && !/^account\./.test(a.action) ? `<a href="${PL.userHref(a.target_id, a.target_code)}">scheda di ${esc(a.target_name || 'utente')}</a>` : '',
      a.actor_kind === 'admin' ? 'azione amministratore' : ''].filter(Boolean).join(' · ');
    const tone = /eliminato|sospes/.test(a.action) ? 'k-bad' : /cestino|cancelled|no_show/.test(a.action) ? 'k-warn' : 'k-' + (selfAct ? 'user' : a.actor_kind);
    return { html: `${whoHtml} ${what}${ctx ? `<span class="ctx">${ctx}</span>` : ''}`, tone };
  };
  PL.renderLog = (el, rows, { wide = false, fresh = new Set() } = {}) => {
    el.classList.toggle('wide', wide);
    el.innerHTML = rows.map((a) => {
      const l = PL.logLine(a);
      const t = new Date(a.at);
      const today = t.toDateString() === new Date().toDateString();
      return `<li class="${l.tone} ${fresh.has(a.id) ? 'fresh' : ''}"><time datetime="${a.at}" title="${PL.dt(a.at)}">${wide ? PL.dt(a.at) : (today ? PL.hm(t) : t.toLocaleDateString('it-IT', { day: 'numeric', month: 'short' }))}</time><span class="mk"></span><div>${l.html}</div></li>`;
    }).join('') || '<li class="empty" style="display:block">Ancora nessuna attività registrata.</li>';
  };

  // ---------- Ricerca istantanea (Ctrl+K o /) ----------
  const openFinder = () => {
    if ($('.k-back')) return;
    const back = document.createElement('div');
    back.className = 'k-back';
    back.innerHTML = `<div class="k-box" role="dialog" aria-label="Cerca nella piattaforma">
      <input type="search" placeholder="Codice ST- / CL- / UT-, numero di prenotazione, nome, email…" aria-label="Cerca" autocomplete="off" spellcheck="false">
      <div class="k-res" role="listbox"></div>
      <div class="k-help">Invio per aprire · frecce per scegliere · Esc per chiudere</div></div>`;
    document.body.appendChild(back);
    const inp = $('input', back), res = $('.k-res', back);
    let items = [], sel = 0, timer;
    const nav = STATIONS.flatMap((g) => g.items).map((s) => ({ t: 'Vai a: ' + s.l, href: s.h, d: '' }));
    const draw = (sections) => {
      items = sections.flatMap((s) => s.items);
      sel = Math.min(sel, Math.max(0, items.length - 1));
      let i = 0;
      res.innerHTML = sections.filter((s) => s.items.length).map((s) => `<div class="k-sec">${esc(s.title)}</div>` + s.items.map((it) => {
        const k = i++;
        return `<button type="button" class="k-it" role="option" data-k="${k}" aria-selected="${k === sel}">${it.code ? PL.chip(it.code) : ''}<span>${esc(it.t)}</span><span class="d">${esc(it.d || '')}</span></button>`;
      }).join('')).join('') || '<div class="empty">Nessun risultato.</div>';
    };
    const go = (it) => { if (!it) return; close(); location.href = it.href; };
    const close = () => { back.remove(); document.removeEventListener('keydown', onKey, true); };
    const search = async () => {
      const q = inp.value.trim();
      const navHits = nav.filter((x) => !q || x.t.toLowerCase().includes(q.toLowerCase())).slice(0, q ? 4 : 9);
      if (q.length < 2) { draw([{ title: 'Postazioni', items: navHits }]); return; }
      draw([{ title: 'Cerco…', items: [] }, { title: 'Postazioni', items: navHits }]);
      let data = [];
      let sts = [];
      [data, sts] = await Promise.all([PL.rpc('platform_find', { p_q: q }).catch(() => []), /^st/i.test(q) ? PL.try('platform_find_structure', { p_q: q }, []) : Promise.resolve([])]);
      data = data || [];
      if (inp.value.trim() !== q) return;
      const codes = await PL.codes();
      const found = data.map((x) => {
        if (x.kind === 'clinic') return { t: x.name, d: x.detail || 'Clinica', code: x.code, href: PL.clinicHref(x.id, codes.clinics?.[x.id] || x.code) };
        if (x.kind === 'user') return { t: x.name, d: x.detail || 'Utente', code: x.code, href: PL.userHref(x.id, x.code) };
        return { t: x.name, d: x.detail || 'Prenotazione', href: 'prenotazioni.html?q=' + encodeURIComponent(q) };
      });
      for (const x of (sts || [])) if (!found.some((f) => f.href.includes(x.id))) found.unshift({ t: x.name, d: (PL.KIND[x.kind] || 'Struttura') + ' · ' + x.code, code: x.code, href: 'clinica.html?id=' + x.id });
      if (/^[A-Z]{0,3}-?\d{4,}$/i.test(q)) found.push({ t: `Cerca "${q}" fra le prenotazioni`, d: '', href: 'prenotazioni.html?q=' + encodeURIComponent(q) });
      found.push({ t: `Cerca "${q}" nel diario di bordo`, d: '', href: 'diario.html' });
      draw([{ title: 'Risultati', items: found }, { title: 'Postazioni', items: navHits }]);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') { e.preventDefault(); close(); }
      else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault(); sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % Math.max(1, items.length);
        $$('.k-it', res).forEach((b) => b.setAttribute('aria-selected', Number(b.dataset.k) === sel));
        const on = $(`.k-it[data-k="${sel}"]`, res); if (on) on.scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter') { e.preventDefault(); go(items[sel]); }
    };
    document.addEventListener('keydown', onKey, true);
    inp.addEventListener('input', () => { sel = 0; clearTimeout(timer); timer = setTimeout(search, 220); });
    res.addEventListener('click', (e) => { const b = e.target.closest('.k-it'); if (b) go(items[Number(b.dataset.k)]); });
    back.addEventListener('mousedown', (e) => { if (e.target === back) close(); });
    search(); inp.focus();
  };
  PL.find = openFinder;

  // ---------- Costruzione della pagina ----------
  const buildShell = (active, email) => {
    const body = document.body;
    const bar = document.createElement('header');
    bar.className = 'pl-bar';
    bar.innerHTML = `
      <a class="pl-brand" href="./" aria-label="Ponte di comando"><img src="../img/logo-bianco.png" alt="Vetroom"><small>PLANCIA</small></a>
      <button class="pl-find" type="button" data-find title="Cerca (Ctrl+K)">${I.cerca.replace('<svg', '<svg width="18" height="18" style="stroke:currentColor;fill:none;stroke-width:1.8"')}<span class="lbl">Cerca un codice, un nome, una prenotazione…</span><kbd>Ctrl K</kbd></button>
      <div class="pl-status">
        <span class="pl-light hide-m" id="plDb" title="Collegamento al database"><i></i><span>Database</span></span>
        <span class="pl-light hide-m" id="plMaint" hidden><i></i><span>Manutenzione</span></span>
        <span class="pl-clock" id="plClock" title="Ora di bordo"></span>
        <span class="pl-who dim" title="${esc(email)}">${esc(email)}</span>
        <button class="pl-out" type="button" data-action="logout">Esci</button>
      </div>`;
    const rail = document.createElement('nav');
    rail.className = 'pl-rail';
    rail.setAttribute('aria-label', 'Postazioni');
    rail.innerHTML = STATIONS.map((g) => `<div class="pl-rail-group"><div class="pl-rail-title">${g.g}</div>${g.items.map((s) => `
      <a class="pl-st ${s.k === active ? 'on' : ''}" href="${s.h}" ${s.k === active ? 'aria-current="page"' : ''}>${I[s.k]}<span>${s.l}</span>${s.badge ? `<span class="pl-badge" data-badge="${s.badge}" hidden></span>` : ''}</a>`).join('')}</div>`).join('')
      + `<div class="pl-rail-group"><div class="pl-rail-title">Altro</div>
          <a class="pl-st" href="../clinica/">${I.cliniche}<span>Apri il gestionale</span></a>
          <a class="pl-st" href="/guida/" target="_blank" rel="noopener">${I.diario}<span>Guida pubblica</span></a></div>`;
    const tab = document.createElement('nav');
    tab.className = 'pl-tab';
    tab.setAttribute('aria-label', 'Menu rapido');
    const t = (k, h, l, badge) => `<a href="${h}" class="${k === active ? 'on' : ''}">${I[k]}<span>${l}</span>${badge ? `<span class="pl-badge" data-badge="${badge}" hidden></span>` : ''}</a>`;
    tab.innerHTML = t('ponte', './', 'Ponte') + t('cliniche', 'cliniche.html', 'Strutture') + t('utenti', 'utenti.html', 'Utenti') + t('messaggi', 'messaggi.html', 'Messaggi', 'support')
      + `<button type="button" data-rail>${I.menu}<span>Altro</span></button>`;
    const back = document.createElement('div'); back.className = 'rail-back';
    const main = $('main');
    main.classList.add('pl-main');
    body.prepend(rail); body.prepend(bar);
    body.appendChild(tab); body.appendChild(back);
    bar.querySelector('[data-find]').addEventListener('click', openFinder);
    tab.querySelector('[data-rail]').addEventListener('click', () => body.classList.toggle('rail-open'));
    back.addEventListener('click', () => body.classList.remove('rail-open'));
    document.addEventListener('keydown', (e) => {
      const typing = /INPUT|TEXTAREA|SELECT/.test(document.activeElement?.tagName || '') || document.activeElement?.isContentEditable;
      if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey)) { e.preventDefault(); openFinder(); }
      else if (e.key === '/' && !typing && !$('dialog[open]')) { e.preventDefault(); openFinder(); }
    });
    const clock = $('#plClock');
    const tick = () => { clock.textContent = new Date().toLocaleTimeString('it-IT'); };
    tick(); setInterval(tick, 1000);
  };

  // Spie in alto e numeri sul menu: si aggiornano da soli
  PL.refreshSignals = async () => {
    const db = $('#plDb');
    const t0 = performance.now();
    const alerts = await PL.try('platform_alerts', {}, null);
    if (db) {
      db.className = 'pl-light hide-m ' + (alerts ? 'ok' : 'bad');
      db.title = alerts ? `Database collegato (${Math.round(performance.now() - t0)} ms)` : 'Database non raggiungibile';
    }
    const by = Object.fromEntries((alerts || []).map((a) => [a.kind, a]));
    const set = (k, n, red) => $$(`[data-badge="${k}"]`).forEach((b) => { b.hidden = !n; b.textContent = n > 99 ? '99+' : String(n || ''); b.classList.toggle('red', !!red); });
    set('support', by.support?.n || 0);
    set('errors', by.errors?.n || 0, by.errors?.level === 'bad');
    set('trash', by.trash?.n || 0);
    const m = $('#plMaint'); if (m) { m.hidden = !by.maintenance; m.className = 'pl-light hide-m bad'; }
    // Spia del Collaudo: problemi dell'ultimo controllo (rossa se ce ne sono di gravi)
    const hc = await PL.try('platform_health_history', { p_limit: 1 }, null);
    const last = hc && hc[0] && hc[0].summary;
    if (last) set('health', Number(last.bad) + Number(last.err) + Number(last.warn), Number(last.bad) + Number(last.err) > 0);
    PL.alerts = (alerts || []).slice();
    if (hc) {
      const gravi = last ? Number(last.bad) + Number(last.err) : 0, guardare = last ? Number(last.warn) : 0;
      const age = hc[0] ? (Date.now() - new Date(hc[0].at).getTime()) / 864e5 : Infinity;
      if (gravi) PL.alerts.push({ kind: 'health', level: 'bad', n: gravi, text: `Collaudo: ${gravi} ${gravi === 1 ? 'problema grave' : 'problemi gravi'}`, href: 'collaudo.html' });
      else if (guardare) PL.alerts.push({ kind: 'health', level: 'warn', n: guardare, text: `Collaudo: ${guardare} ${guardare === 1 ? 'cosa' : 'cose'} da guardare`, href: 'collaudo.html' });
      if (age > 7) PL.alerts.push({ kind: 'health', level: 'info', n: 0, text: hc[0] ? 'Ultimo collaudo più vecchio di una settimana: rifallo' : 'Fai il primo collaudo completo della piattaforma', href: 'collaudo.html#avvia' });
    }
    document.dispatchEvent(new CustomEvent('pl:signals', { detail: PL.alerts }));
    return PL.alerts;
  };

  // Avvio: controlla sessione e permessi, costruisce la plancia
  PL.boot = async () => {
    const active = document.body.dataset.station || '';
    const main = $('main');
    let session;
    try { session = await VR.requireSession(); } catch { return new Promise(() => {}); }
    let ok = false;
    try { ok = !!(await PL.rpc('is_platform_admin')); } catch { ok = false; }
    if (!ok) {
      main.innerHTML = `<div class="denied pn"><h1>Accesso negato</h1><p class="dim" style="margin-top:10px">La Plancia è riservata agli amministratori della piattaforma Vetroom.</p>
        <div class="actions" style="justify-content:center;margin-top:16px"><a class="btn" href="../">Torna a Vetroom</a><button class="btn ghost" data-action="logout">Esci</button></div></div>`;
      main.classList.add('pl-main'); main.style.marginLeft = '0';
      return new Promise(() => {});
    }
    sessionStorage.setItem('vetroom_is_platform', '1');
    buildShell(active, session.user.email || '');
    PL.session = session;
    PL.refreshSignals();
    setInterval(() => { if (document.visibilityState === 'visible') PL.refreshSignals(); }, 60000);
    return session;
  };

  // ---------- Schede a linguette dentro una pagina (con indirizzo #nome) ----------
  PL.tabs = (el, onShow) => {
    const btns = $$('[role="tab"]', el);
    const show = (name, push) => {
      btns.forEach((b) => b.setAttribute('aria-selected', b.dataset.tab === name));
      $$('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== name; });
      if (push) history.replaceState(null, '', '#' + name);
      if (onShow) onShow(name);
    };
    el.addEventListener('click', (e) => { const b = e.target.closest('[role="tab"]'); if (b) show(b.dataset.tab, true); });
    const start = (location.hash || '').slice(1);
    show(btns.some((b) => b.dataset.tab === start) ? start : btns[0].dataset.tab, false);
    return { show };
  };

  // ---------- Email dal pannello (riusa l'invio già configurato) ----------
  let mailReady = null;
  PL.compose = async ({ to = '', subject = '', body = '', name = null } = {}) => {
    if (mailReady === null) mailReady = await PL.try('platform_mail_ready', {}, { ready: false }) || { ready: false };
    const v = await PL.ask({
      title: 'Scrivi un\'email', wide: true, ok: mailReady.ready ? 'Invia' : 'Apri nel programma di posta',
      html: mailReady.ready ? `Parte da ${esc(mailReady.name ? mailReady.name + ' <' + mailReady.from + '>' : mailReady.from)}. Le risposte arrivano in quella casella.`
        : 'L\'invio dal pannello non è ancora configurato: si aprirà il tuo programma di posta con il testo pronto.',
      fields: [{ name: 'to', label: 'A', type: 'email', value: to, required: true }, { name: 'subject', label: 'Oggetto', value: subject, required: true },
        { name: 'body', label: 'Messaggio', type: 'textarea', rows: 12, value: body, required: true }]
    });
    if (!v) return false;
    if (!mailReady.ready) {
      location.href = `mailto:${v.to.replace(/[?&#\s]/g, '')}?subject=${encodeURIComponent(v.subject)}&body=${encodeURIComponent(v.body)}`;
      return true;
    }
    try {
      const id = await PL.rpc('platform_send_email', { p_to: v.to.trim(), p_subject: v.subject.trim(), p_body: v.body, p_to_name: name, p_kind: 'manual' });
      PL.toast('Email in partenza…', 'info');
      for (let i = 0; i < 12; i++) {
        await new Promise((r) => setTimeout(r, i < 3 ? 700 : 1500));
        const st = await PL.try('platform_mail_status', { p_id: id });
        if (st && st.status === 'sent') { PL.toast('Email inviata.'); return true; }
        if (st && st.status === 'failed') { PL.toast('L\'email non è partita: ' + (st.error || 'errore sconosciuto'), 'bad'); return false; }
      }
      PL.toast('L\'email è in coda: parte da sola entro un minuto.', 'info');
      return true;
    } catch (e) { PL.toast(e.message, 'bad'); return false; }
  };

  // Messaggio interno dell'assistenza
  PL.messageTo = async (kind, id, label) => {
    const v = await PL.ask({
      title: `Messaggio a ${label}`, ok: 'Invia messaggio',
      html: 'Lo riceve dentro Vetroom, alla voce Messaggi, firmato “Assistenza Vetroom”. Se ha FurrFinder con le notifiche attive, gli arriva anche sul telefono.',
      fields: [{ name: 'body', label: 'Messaggio', type: 'textarea', rows: 7, required: true }]
    });
    if (!v) return false;
    const r = await PL.run(null, () => PL.rpc(kind === 'clinic' ? 'platform_message_clinic' : 'platform_message_user',
      kind === 'clinic' ? { p_clinic: id, p_body: v.body } : { p_user: id, p_body: v.body }), 'Messaggio inviato.');
    return r !== undefined;
  };

  // Messaggio a tutti: /all_vet (una conversazione per clinica) o /all_user (una per proprietario)
  PL.BROADCAST_TEXT = {
    vet: 'Ciao,\n\nVetroom sta crescendo: ogni settimana arrivano funzioni nuove, come le fatture e l\'invio delle spese al Sistema Tessera Sanitaria.\n\nVogliamo costruire il gestionale insieme a chi lo usa ogni giorno. Rispondi direttamente qui: raccontaci cosa ti fa perdere tempo in ambulatorio, cosa manca o cosa miglioreresti. Leggiamo ogni risposta, una per una.\n\nGrazie!\nAureliano – Vetroom',
    user: 'Ciao,\n\nFurrFinder sta crescendo e vogliamo renderla sempre più utile per te e per il tuo animale.\n\nRispondi direttamente qui: cosa vorresti trovare nell\'app? Cosa ti farebbe risparmiare tempo con il veterinario? Leggiamo ogni risposta, una per una.\n\nGrazie!\nAureliano – FurrFinder'
  };
  PL.broadcast = async (audience) => {
    let pv;
    try { pv = await PL.rpc('platform_broadcast_preview', { p_audience: audience }); }
    catch (e) { PL.toast(e.missing ? 'Per i messaggi a tutti va eseguita la patch 23 su Supabase.' : e.message, 'bad'); return null; }
    if (!pv.count) { PL.toast(audience === 'vet' ? 'Nessuna clinica a cui scrivere.' : 'Nessun proprietario a cui scrivere.', 'bad'); return null; }
    const who = audience === 'vet' ? `${pv.count} ${pv.count === 1 ? 'clinica' : 'cliniche'}` : `${pv.count} ${pv.count === 1 ? 'proprietario' : 'proprietari'}`;
    const v = await PL.ask({
      title: audience === 'vet' ? `/all_vet · a tutti i veterinari (${who})` : `/all_user · a tutti i proprietari (${who})`,
      wide: true, danger: true, ok: `Invia a ${who}`, word: 'INVIA',
      html: `Arriva nella casella <b>Messaggi</b> di ${audience === 'vet' ? 'ogni clinica (lo vede tutto il team)' : 'ogni proprietario, in FurrFinder (con notifica sul telefono se attiva)'}, dentro la sua conversazione con l'Assistenza.
        Le risposte arrivano a te <b>separate</b>, una per ${audience === 'vet' ? 'clinica' : 'persona'}: nessuno vede le risposte degli altri.
        <br><span class="small faint">Fra i destinatari: ${pv.sample.map(esc).join(', ')}${pv.count > pv.sample.length ? '…' : ''}${pv.last ? ` · ultimo messaggio a tutti: ${PL.dt(pv.last.created_at)}` : ''}</span>`,
      fields: [{ name: 'body', label: 'Messaggio', type: 'textarea', rows: 12, value: PL.BROADCAST_TEXT[audience], required: true }]
    });
    if (!v) return null;
    PL.toast(`Invio a ${who} in corso…`, 'info');
    try {
      const r = await PL.rpc('platform_broadcast_send', { p_audience: audience, p_body: v.body });
      PL.toast(`Inviato a ${r.sent} ${audience === 'vet' ? 'cliniche' : 'proprietari'}${r.failed ? `, ${r.failed} non riusciti` : ''}. Le risposte arriveranno in Messaggi.`, r.failed ? 'bad' : 'ok');
      return r;
    } catch (e) { PL.toast(e.message, 'bad'); return null; }
  };

  // Note interne (scheda clinica e scheda utente)
  PL.mountNotes = (el, kind, target) => {
    const load = async () => {
      let rows = [];
      try { rows = await PL.rpc('platform_notes_list', { p_target: target }) || []; }
      catch (e) { el.innerHTML = `<p class="note warn">${esc(e.message)}</p>`; return 0; }
      el.innerHTML = `<form class="add" style="margin-bottom:14px"><label class="f" for="nt">Nuova nota (la vedi solo tu e gli altri amministratori)</label>
          <textarea id="nt" rows="3" placeholder="Es. ha chiamato il 12/10 per l'import da Excel, richiamare lunedì"></textarea>
          <div class="actions" style="margin-top:8px"><button class="btn prim sm" type="submit">Salva nota</button></div></form>
        <div class="notes">${rows.map((r) => `<div class="nt">${esc(r.body)}<small><span>${PL.dt(r.created_at)}</span><span>${esc(r.author_email || '')}</span><button type="button" data-del="${r.id}">Elimina</button></small></div>`).join('') || '<p class="dim">Nessuna nota.</p>'}</div>`;
      $('form', el).addEventListener('submit', async (e) => {
        e.preventDefault();
        const t = $('#nt', el).value.trim(); if (!t) return;
        const r = await PL.run(e.submitter, () => PL.rpc('platform_note_add', { p_kind: kind, p_target: target, p_body: t }), 'Nota salvata.');
        if (r !== undefined) load();
      });
      return rows.length;
    };
    el.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-del]'); if (!b) return;
      const ok = await PL.ask({ title: 'Eliminare la nota?', ok: 'Elimina', danger: true });
      if (!ok) return;
      const r = await PL.run(b, () => PL.rpc('platform_note_delete', { p_id: Number(b.dataset.del) }), 'Nota eliminata.');
      if (r !== undefined) load();
    });
    return load();
  };
})();
