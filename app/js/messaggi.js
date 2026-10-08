// =============================================================
//  MESSAGGI — elenco conversazioni + conversazione aperta
//  Usato uguale dal portale proprietari e dall'app delle cliniche.
//  Con la patch 32: letto/non letto, archivio, ricerca, selezione
//  multipla, spunte di lettura, allegati, risposte pronte e (clinica)
//  invio a più clienti insieme.
// =============================================================
(function () {
  const VR = window.VR;
  const M = (VR.msg = {});
  const BUCKET = 'msg-files';
  const MAX_FILES = 5, MAX_SIZE = 10 * 1024 * 1024;

  const when = (iso) => {
    const d = new Date(iso), now = new Date();
    const sameDay = d.toDateString() === now.toDateString();
    const y = new Date(now); y.setDate(y.getDate() - 1);
    if (sameDay) return d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
    if (d.toDateString() === y.toDateString()) return 'ieri, ' + d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString('it-IT', { day: 'numeric', month: 'short' }) +
      (now - d < 300 * 864e5 ? ', ' + d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' }) : '');
  };
  const dayLabel = (iso) => {
    const d = new Date(iso), now = new Date(), y = new Date(now); y.setDate(y.getDate() - 1);
    if (d.toDateString() === now.toDateString()) return 'Oggi';
    if (d.toDateString() === y.toDateString()) return 'Ieri';
    return d.toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' });
  };
  // Nome di chi scrive: se il database manda solo il ruolo, mostra un nome leggibile
  const ROLE_NAME = { owner: 'Cliente', clinic: 'Clinica', admin: 'Vetroom' };
  const who = (m, c) => {
    const n = (m.sender_name || '').trim();
    if (n && !ROLE_NAME[n]) return n;
    const r = m.sender_role || n;
    if (r !== 'admin' && c && c.title) return c.title;
    return ROLE_NAME[r] || n;
  };
  // Il testo resta testo: niente HTML. Gli indirizzi web diventano link sicuri.
  const linkify = (t) => VR.esc(t).replace(/(https?:\/\/[^\s<]+)/g, (u) => `<a href="${u}" target="_blank" rel="noopener nofollow">${u}</a>`);
  const bytes = (b) => { b = Number(b || 0); return b < 1024 * 1024 ? Math.max(1, Math.round(b / 1024)) + ' KB' : (b / 1048576).toLocaleString('it-IT', { maximumFractionDigits: 1 }) + ' MB'; };
  const missing = (e) => e && (e.code === 'PGRST202' || /Could not find the function|does not exist/i.test(e.message || ''));
  M.missing = missing;

  const ICON = {
    clip: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 11.5l-8.2 8.2a5 5 0 0 1-7.1-7.1l8.6-8.6a3.4 3.4 0 0 1 4.8 4.8l-8.6 8.6a1.7 1.7 0 0 1-2.4-2.4l7.9-7.9"/></svg>',
    bolt: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2.5L4.5 13.5H11l-1 8 8.5-11H12z"/></svg>',
    pdf: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2.5h8l4 4v15H6z"/><path d="M14 2.5v4h4M9 13h6M9 16.5h6"/></svg>'
  };

  M.unreadBadge = async () => {
    try {
      const { data } = await VR.sb.rpc('msg_unread');
      const n = Number(data || 0);
      document.querySelectorAll('[data-msg-badge]').forEach((el) => { el.textContent = n ? String(n) : ''; el.hidden = !n; });
      if (VR.navBadge) VR.navBadge(n);
      return n;
    } catch { return 0; }
  };

  // Collegamento in tempo reale: una sola connessione per pagina, condivisa.
  let channel = null;
  const listeners = new Set();
  M.live = (fn) => {
    listeners.add(fn);
    if (channel || !VR.sb.channel) return;
    try {
      channel = VR.sb.channel('vr-messaggi')
        .on('postgres_changes', { event: 'INSERT', schema: 'public', table: 'messages' }, () => listeners.forEach((f) => f()))
        .on('postgres_changes', { event: 'UPDATE', schema: 'public', table: 'conversations' }, () => listeners.forEach((f) => f()))
        .subscribe();
    } catch { channel = null; }
  };

  // ---------- Allegati ----------
  const urlCache = new Map();
  M.signed = async (paths) => {
    const now = Date.now();
    const need = [...new Set(paths)].filter((p) => !urlCache.has(p) || urlCache.get(p).until < now);
    if (need.length) {
      const { data } = await VR.sb.storage.from(BUCKET).createSignedUrls(need, 3600);
      (data || []).forEach((r) => { if (r.signedUrl) urlCache.set(r.path, { url: r.signedUrl, until: now + 50 * 60000 }); });
    }
    return Object.fromEntries(paths.map((p) => [p, urlCache.get(p)?.url || null]));
  };
  const safeName = (n) => String(n || 'file').normalize('NFKD').replace(/[^\w.\-]+/g, '_').replace(/_+/g, '_').slice(-80) || 'file';
  M.prepareFile = async (file) => {
    const okType = /^image\/(jpeg|png|webp|gif|heic|heif)$/.test(file.type) || file.type === 'application/pdf' || /\.(heic|heif)$/i.test(file.name);
    if (!okType) throw new Error(`"${file.name}": si possono inviare solo foto e PDF.`);
    let f = file;
    // foto grandi: ridotte prima dell'invio (più veloce e meno spazio)
    if (/^image\/(jpeg|png|webp)$/.test(file.type) && file.size > 1.2 * 1024 * 1024 && VR.resizeImage) {
      try { const b = await VR.resizeImage(file, 1920, 0.85); f = new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }); } catch { /* resta l'originale */ }
    }
    if (f.size > MAX_SIZE) throw new Error(`"${file.name}" supera i 10 MB.`);
    return f;
  };
  M.upload = async (conv, file) => {
    const path = `${conv}/${crypto.randomUUID()}-${safeName(file.name)}`;
    const { error } = await VR.sb.storage.from(BUCKET).upload(path, file, { contentType: file.type || 'application/octet-stream', upsert: false });
    if (error) throw new Error(/row-level|security|403/i.test(error.message || '') ? 'Non puoi inviare allegati in questa conversazione.' : 'Caricamento non riuscito: ' + error.message);
    return { path, name: file.name, mime: file.type || null, size: file.size };
  };
  M.attachmentsHtml = (files, urls) => {
    if (!files || !files.length) return '';
    return `<div class="att-grid" translate="no">${files.map((a) => {
      const u = urls[a.storage_path];
      if (/^image\//.test(a.mime || '') && !/hei[cf]/.test(a.mime || '')) {
        return `<a class="att-img" href="${VR.esc(u || '#')}" target="_blank" rel="noopener" title="${VR.esc(a.name)}">${u ? `<img src="${VR.esc(u)}" alt="${VR.esc(a.name)}" loading="lazy">` : '<span>…</span>'}</a>`;
      }
      return `<a class="att-file" href="${VR.esc(u || '#')}" target="_blank" rel="noopener">${ICON.pdf}<span><b>${VR.esc(a.name)}</b><small>${bytes(a.size)}</small></span></a>`;
    }).join('')}</div>`;
  };

  // ---------- Risposte pronte (della clinica) ----------
  const TPL_EXAMPLES = [
    ['Orari di apertura', 'Siamo aperti dal lunedì al venerdì dalle 9:00 alle 19:00 e il sabato dalle 9:00 alle 13:00. Per le urgenze fuori orario chiamaci al numero della clinica.'],
    ['Digiuno prima dell\'intervento', 'Per l\'intervento di domani: niente cibo dalle 20:00 di stasera, l\'acqua invece può berla fino alla mattina. Portalo in clinica all\'orario concordato.'],
    ['Referto pronto', 'Il referto della visita è pronto: lo trovi nel libretto di FurrFinder, nella scheda del tuo animale. Se hai domande scrivici pure qui.']
  ];
  M.templates = {
    list: async (clinicId) => {
      const { data, error } = await VR.sb.rpc('msg_templates', { p_clinic: clinicId || null });
      if (error) throw error;
      return data || [];
    },
    // finestra per aggiungere, modificare ed eliminare
    manage: (clinicId) => new Promise((resolve) => {
      const d = document.createElement('dialog');
      d.className = 'dlg msg-dlg';
      d.innerHTML = `<h2>Risposte pronte</h2>
        <p class="muted" style="margin-top:-6px">Testi che tutto il team può inserire con un tocco, invece di riscriverli ogni volta.</p>
        <div class="tpl-list" data-list><p class="muted">Caricamento…</p></div>
        <form class="tpl-form" data-form>
          <input type="hidden" name="id">
          <label for="tplT">Titolo (lo vedi solo tu)</label><input id="tplT" name="title" maxlength="80" required placeholder="Es. Orari di apertura">
          <label for="tplB">Testo</label><textarea id="tplB" name="body" rows="4" maxlength="4000" required></textarea>
          <div class="actions-bar"><button class="btn btn-primary btn-small" type="submit">Salva</button><button class="btn btn-ghost btn-small" type="button" data-new hidden>Nuova</button></div>
        </form>
        <div class="notice notice-error" data-err hidden></div>
        <div class="actions-bar" style="justify-content:flex-end;margin-top:12px"><button class="btn btn-ghost" type="button" data-close>Chiudi</button></div>`;
      document.body.appendChild(d);
      const form = d.querySelector('[data-form]'), err = d.querySelector('[data-err]');
      let rows = [];
      const draw = async () => {
        try { rows = await M.templates.list(clinicId); } catch (e) { VR.say(err, VR.errorText(e), 'error'); return; }
        d.querySelector('[data-list]').innerHTML = rows.length ? rows.map((t) => `<div class="tpl-row"><div translate="no"><strong>${VR.esc(t.title)}</strong><span>${VR.esc(t.body)}</span></div>
            <div class="tpl-row-act"><button type="button" class="btn btn-ghost btn-small" data-edit="${t.id}">Modifica</button><button type="button" class="btn btn-danger btn-small" data-del="${t.id}">Elimina</button></div></div>`).join('')
          : `<div class="tpl-empty"><p class="muted">Ancora nessuna risposta pronta.</p><button type="button" class="btn btn-ghost btn-small" data-examples>Aggiungi tre esempi da modificare</button></div>`;
      };
      const reset = () => { form.reset(); form.id.value = ''; d.querySelector('[data-new]').hidden = true; };
      form.addEventListener('submit', async (e) => {
        e.preventDefault(); VR.hide(err);
        const { error } = await VR.sb.rpc('msg_template_save', { p_id: form.id.value || null, p_clinic: clinicId || null, p_title: form.title.value, p_body: form.body.value });
        if (error) { VR.say(err, VR.errorText(error), 'error'); return; }
        reset(); draw();
      });
      d.addEventListener('click', async (e) => {
        const ed = e.target.closest('[data-edit]'), de = e.target.closest('[data-del]');
        if (e.target.closest('[data-close]')) { d.close(); return; }
        if (e.target.closest('[data-new]')) { reset(); return; }
        if (e.target.closest('[data-examples]')) {
          for (const [t, b] of TPL_EXAMPLES) await VR.sb.rpc('msg_template_save', { p_id: null, p_clinic: clinicId || null, p_title: t, p_body: b });
          draw(); return;
        }
        if (ed) { const t = rows.find((x) => x.id === ed.dataset.edit); if (t) { form.id.value = t.id; form.title.value = t.title; form.body.value = t.body; d.querySelector('[data-new]').hidden = false; form.title.focus(); } }
        if (de && confirm('Eliminare questa risposta pronta?')) { await VR.sb.rpc('msg_template_delete', { p_id: de.dataset.del, p_clinic: clinicId || null }); draw(); }
      });
      d.addEventListener('close', () => { d.remove(); resolve(); });
      d.showModal(); draw();
    }),
    // menu a comparsa sopra la casella di testo
    pick: async (anchor, clinicId, onPick) => {
      document.querySelectorAll('.tpl-pop').forEach((x) => x.remove());
      const pop = document.createElement('div');
      pop.className = 'tpl-pop';
      pop.innerHTML = '<p class="muted" style="margin:8px">Caricamento…</p>';
      anchor.appendChild(pop);
      const close = (e) => { if (!e || !pop.contains(e.target)) { pop.remove(); document.removeEventListener('pointerdown', close, true); } };
      setTimeout(() => document.addEventListener('pointerdown', close, true), 0);
      let rows = [];
      try { rows = await M.templates.list(clinicId); } catch (e) { pop.innerHTML = `<p class="notice notice-error" style="margin:8px">${VR.esc(VR.errorText(e))}</p>`; return; }
      pop.innerHTML = `<div class="tpl-pop-h"><strong>Risposte pronte</strong><button type="button" class="link-btn" data-manage>Gestisci</button></div>
        ${rows.length ? rows.map((t) => `<button type="button" class="tpl-item" data-t="${t.id}" translate="no"><b>${VR.esc(t.title)}</b><span>${VR.esc(t.body)}</span></button>`).join('')
          : '<p class="muted" style="margin:8px 10px">Nessuna risposta pronta. Premi “Gestisci” per crearne.</p>'}`;
      pop.addEventListener('click', async (e) => {
        const it = e.target.closest('[data-t]');
        if (it) { const t = rows.find((x) => x.id === it.dataset.t); if (t) onPick(t.body); close(); }
        if (e.target.closest('[data-manage]')) { close(); await M.templates.manage(clinicId); }
      });
    }
  };
  // inserisce il testo dove si trova il cursore
  const insertAtCursor = (ta, text) => {
    const s = ta.selectionStart ?? ta.value.length, e = ta.selectionEnd ?? ta.value.length;
    const before = ta.value.slice(0, s), after = ta.value.slice(e);
    const sep = before && !/\s$/.test(before) ? ' ' : '';
    ta.value = before + sep + text + after;
    const pos = (before + sep + text).length;
    ta.focus(); ta.setSelectionRange(pos, pos);
    ta.dispatchEvent(new Event('input'));
  };
  M.insertAtCursor = insertAtCursor;

  // ---------- La clinica scrive a più clienti ----------
  M.groupCompose = (clinicId) => new Promise((resolve) => {
    const d = document.createElement('dialog');
    d.className = 'dlg msg-dlg msg-dlg-wide';
    d.innerHTML = `<h2>Scrivi a più clienti</h2>
      <p class="muted" style="margin-top:-6px">Ognuno riceve il messaggio nella sua conversazione con la clinica, con una notifica sul telefono. Le risposte arrivano separate: nessuno vede gli altri destinatari.</p>
      <div class="grp-pick">
        <div class="grp-tools">
          <input type="search" data-q placeholder="Cerca per nome o animale" aria-label="Cerca destinatari">
          <div class="chips" data-f></div>
        </div>
        <label class="grp-all"><input type="checkbox" data-all> <span data-alltxt>Seleziona tutti</span></label>
        <div class="grp-list" data-list><p class="muted">Caricamento…</p></div>
      </div>
      <label for="grpText" style="margin-top:12px">Messaggio</label>
      <div class="grp-text"><textarea id="grpText" rows="5" maxlength="4000" placeholder="Es. È iniziata la campagna vaccini: prenota direttamente da FurrFinder."></textarea>
        <button type="button" class="msg-icon-btn" data-tpl title="Risposte pronte">${ICON.bolt}</button></div>
      <div class="notice notice-error" data-err hidden></div>
      <div class="actions-bar grp-foot"><span class="muted" data-count>Nessun destinatario</span>
        <span style="flex:1"></span><button type="button" class="btn btn-ghost" data-close>Annulla</button><button type="button" class="btn btn-primary" data-send disabled>Invia</button></div>`;
    document.body.appendChild(d);
    const $ = (s) => d.querySelector(s);
    const err = $('[data-err]'), ta = $('#grpText');
    let rows = [], sel = new Set(), f = 'all';
    const yearAgo = new Date(Date.now() - 365 * 864e5).toISOString().slice(0, 10);
    const F = [['all', 'Tutti'], ['cane', 'Cani'], ['gatto', 'Gatti'], ['altri', 'Altri animali'], ['recenti', 'Visti nell\'ultimo anno'], ['persi', 'Non visti da un anno']];
    const pass = (r) => {
      const sp = (r.species || '').split(',');
      if (f === 'cane' && !sp.includes('cane')) return false;
      if (f === 'gatto' && !sp.includes('gatto')) return false;
      if (f === 'altri' && !sp.some((s) => s && s !== 'cane' && s !== 'gatto')) return false;
      if (f === 'recenti' && !(r.last_visit && r.last_visit >= yearAgo)) return false;
      if (f === 'persi' && !(r.last_visit && r.last_visit < yearAgo)) return false;
      const q = $('[data-q]').value.trim().toLowerCase();
      return !q || [r.name, r.email, r.pets].join(' ').toLowerCase().includes(q);
    };
    const draw = () => {
      $('[data-f]').innerHTML = F.map(([k, l]) => `<button type="button" class="chip ${f === k ? 'is-on' : ''}" data-k="${k}">${l}</button>`).join('');
      const vis = rows.filter(pass);
      $('[data-list]').innerHTML = rows.length ? (vis.map((r) => `<label class="grp-row"><input type="checkbox" data-id="${r.owner_user_id}" ${sel.has(r.owner_user_id) ? 'checked' : ''}>
          <span translate="no"><strong>${VR.esc(r.name)}</strong><small>${VR.esc(r.pets || 'nessun animale collegato')}${r.last_visit ? ' · ultima visita ' + new Date(r.last_visit + 'T00:00:00').toLocaleDateString('it-IT') : ''}</small></span></label>`).join('')
          || '<p class="muted">Nessun cliente con questi criteri.</p>')
        : '<p class="muted">Nessun cliente usa ancora FurrFinder con la tua clinica. <a href="furrfinder.html">Come invitarli</a></p>';
      const allSel = vis.length && vis.every((r) => sel.has(r.owner_user_id));
      $('[data-all]').checked = !!allSel; $('[data-all]').disabled = !vis.length;
      $('[data-alltxt]').textContent = `Seleziona tutti quelli mostrati (${vis.length})`;
      const n = sel.size;
      $('[data-count]').textContent = n ? `${n} ${n === 1 ? 'destinatario' : 'destinatari'}` : 'Nessun destinatario';
      $('[data-send]').textContent = n ? `Invia a ${n} ${n === 1 ? 'cliente' : 'clienti'}` : 'Invia';
      $('[data-send]').disabled = !n || !ta.value.trim();
    };
    d.addEventListener('change', (e) => {
      const c = e.target.closest('[data-id]');
      if (c) { if (c.checked) sel.add(c.dataset.id); else sel.delete(c.dataset.id); draw(); }
      if (e.target.closest('[data-all]')) { const vis = rows.filter(pass); vis.forEach((r) => (e.target.checked ? sel.add(r.owner_user_id) : sel.delete(r.owner_user_id))); draw(); }
    });
    d.addEventListener('input', (e) => { if (e.target.closest('[data-q]') || e.target === ta) draw(); });
    d.addEventListener('click', async (e) => {
      const k = e.target.closest('[data-k]');
      if (k) { f = k.dataset.k; draw(); }
      if (e.target.closest('[data-close]')) d.close();
      if (e.target.closest('[data-tpl]')) M.templates.pick($('.grp-text'), clinicId, (t) => insertAtCursor(ta, t));
      if (e.target.closest('[data-send]')) {
        const b = $('[data-send]'); b.disabled = true; VR.hide(err);
        const { data, error } = await VR.sb.rpc('clinic_message_many', { p_clinic: clinicId, p_owners: [...sel], p_body: ta.value });
        if (error) { VR.say(err, VR.errorText(error), 'error'); b.disabled = false; return; }
        d.close(); resolve(data);
      }
    });
    d.addEventListener('close', () => { d.remove(); resolve(null); });
    d.showModal();
    VR.sb.rpc('clinic_msg_recipients', { p_clinic: clinicId }).then(({ data, error }) => {
      if (error) { $('[data-list]').innerHTML = `<p class="notice notice-error">${VR.esc(VR.errorText(error))}</p>`; return; }
      rows = data || []; draw();
    });
  });

  // ---------- Casella e conversazione ----------
  // opts: { listEl, threadEl, side: 'owner'|'clinic', clinicId, emptyHtml, noteHtml, onOpen }
  M.mount = async (opts) => {
    const list = VR.$(opts.listEl), pane = VR.$(opts.threadEl);
    const side = opts.side;
    let convs = [], current = null, polling = null, adv = true;
    let filter = 'all', q = '', hits = new Map(), selecting = false;
    const sel = new Set();
    let shown = { id: null, sig: '' }, receipt = 0, pending = [];

    list.innerHTML = `
      <div class="msg-tools">
        <input type="search" class="msg-q" placeholder="Cerca nei messaggi" aria-label="Cerca nei messaggi">
        <div class="msg-filters" role="group" aria-label="Mostra"></div>
        <div class="msg-bulk"></div>
      </div>
      <div class="msg-convs" role="list"></div>`;
    const convBox = list.querySelector('.msg-convs'), filt = list.querySelector('.msg-filters'), bulk = list.querySelector('.msg-bulk');
    const flash = (text) => {
      const n = document.createElement('div');
      n.className = 'notice notice-ok msg-flash'; n.textContent = text;
      list.querySelector('.msg-tools').appendChild(n);
      setTimeout(() => n.remove(), 9000);
    };

    const FILTERS = [['all', 'Tutte'], ['unread', 'Non lette'], ['reply', 'Da rispondere'], ['archived', 'Archiviate']];
    const matchF = (c, k) => k === 'archived' ? c.archived : !c.archived && (k === 'all' || (k === 'unread' ? c.unread > 0 : c.needs_reply));
    const visible = () => {
      if (q.length >= 2) {
        const t = q.toLowerCase();
        return convs.filter((c) => hits.has(c.id) || [c.title, c.subtitle].join(' ').toLowerCase().includes(t));
      }
      return convs.filter((c) => matchF(c, filter));
    };

    const renderTools = () => {
      filt.hidden = !adv || q.length >= 2;
      filt.innerHTML = FILTERS.map(([k, l]) => {
        const n = convs.filter((c) => matchF(c, k)).length;
        return `<button type="button" data-f="${k}" aria-pressed="${filter === k}">${l}${n && k !== 'all' ? ` <span class="n">${n}</span>` : ''}</button>`;
      }).join('');
      const vis = visible();
      if (!adv) { bulk.innerHTML = opts.groupBtn ? `<button type="button" class="btn btn-ghost btn-small" data-compose>Scrivi a più clienti</button>` : ''; bulk.hidden = !opts.groupBtn; return; }
      bulk.hidden = false;
      bulk.innerHTML = selecting
        ? `<label class="msg-selall"><input type="checkbox" data-selall ${vis.length && vis.every((c) => sel.has(c.id)) ? 'checked' : ''}> ${sel.size ? sel.size + ' selezionate' : 'Seleziona tutte'}</label>
           <span class="msg-bulk-act">
             <button type="button" class="btn btn-ghost btn-small" data-act="read" ${sel.size ? '' : 'disabled'}>Lette</button>
             <button type="button" class="btn btn-ghost btn-small" data-act="unread" ${sel.size ? '' : 'disabled'}>Non lette</button>
             <button type="button" class="btn btn-ghost btn-small" data-act="${filter === 'archived' ? 'unarchive' : 'archive'}" ${sel.size ? '' : 'disabled'}>${filter === 'archived' ? 'Ripristina' : 'Archivia'}</button>
             <button type="button" class="btn btn-ghost btn-small" data-selend>Fine</button></span>`
        : `<button type="button" class="btn btn-ghost btn-small" data-selstart ${convs.length ? '' : 'disabled'}>Seleziona</button>
           ${opts.groupBtn ? '<button type="button" class="btn btn-ghost btn-small" data-compose>Scrivi a più clienti</button>' : ''}`;
    };

    const renderList = () => {
      renderTools();
      if (!convs.length) { convBox.innerHTML = opts.emptyHtml || '<p class="muted">Nessun messaggio.</p>'; return; }
      const rows = visible();
      const mineRole = side === 'owner' ? 'owner' : 'clinic';
      convBox.innerHTML = rows.map((c) => {
        const h = hits.get(c.id);
        const prev = h ? `🔎 ${VR.esc(h[0].snippet)}` : c.last_preview ? (c.last_sender_role === mineRole ? 'Tu: ' : '') + VR.esc(c.last_preview) : 'Nessun messaggio';
        return `
        <div class="conv ${c.id === current ? 'is-open' : ''} ${c.unread ? 'is-unread' : ''} ${c.kind && c.kind.startsWith('admin') ? 'msg-support' : ''} ${sel.has(c.id) ? 'is-sel' : ''}" data-conv="${c.id}" role="listitem" tabindex="0">
          ${selecting ? `<input type="checkbox" class="conv-check" ${sel.has(c.id) ? 'checked' : ''} aria-label="Seleziona ${VR.esc(c.title)}" tabindex="-1">` : ''}
          <span class="conv-top"><strong translate="no">${VR.esc(c.title)}</strong><span class="conv-when">${c.last_message_at ? when(c.last_message_at) : ''}</span></span>
          ${c.subtitle ? `<span class="conv-sub" translate="no">${VR.esc(c.subtitle)}</span>` : ''}
          <span class="conv-prev" translate="no">${prev}</span>
          <span class="conv-flags">${c.archived ? '<span class="conv-tag">Archiviata</span>' : ''}${c.needs_reply && !c.unread && adv ? '<span class="conv-tag tag-reply">Da rispondere</span>' : ''}</span>
          ${c.unread ? `<span class="conv-badge">${c.unread}</span>` : ''}
        </div>`;
      }).join('') || `<p class="muted msg-none">${q.length >= 2 ? 'Nessun messaggio trovato.' : filter === 'archived' ? 'Nessuna conversazione archiviata.' : filter === 'unread' ? 'Nessun messaggio da leggere. 👍' : filter === 'reply' ? 'Hai risposto a tutti. 👍' : 'Nessun messaggio.'}</p>`;
    };

    // ---- conversazione aperta: intestazione e casella di scrittura si costruiscono una volta sola
    const buildPane = (c) => {
      const canWrite = c.active;
      pane.innerHTML = `
        <div class="msg-head">
          <button type="button" class="btn btn-ghost btn-small msg-back" data-back>← Messaggi</button>
          <div class="msg-head-t" translate="no"><strong>${VR.esc(c.title)}</strong><span class="muted">${VR.esc(c.subtitle || '')}</span></div>
          ${adv ? `<div class="msg-head-act">
            <button type="button" class="btn btn-ghost btn-small" data-unread title="Torna nella lista come da leggere">Non letta</button>
            <button type="button" class="btn btn-ghost btn-small" data-arch>${c.archived ? 'Ripristina' : 'Archivia'}</button></div>` : ''}
        </div>
        <div class="msg-scroll"></div>
        ${canWrite ? `
        <div class="msg-attach" hidden></div>
        <form class="msg-form" id="msgForm">
          ${adv ? `<button type="button" class="msg-icon-btn" data-attach title="Allega foto o PDF (max 10 MB)">${ICON.clip}</button>
          <input type="file" data-file multiple accept="image/*,application/pdf" hidden>` : ''}
          ${adv && side === 'clinic' && opts.clinicId ? `<span class="tpl-anchor"><button type="button" class="msg-icon-btn" data-tpl title="Risposte pronte">${ICON.bolt}</button></span>` : ''}
          <textarea id="msgText" rows="2" placeholder="Scrivi un messaggio…" maxlength="4000"></textarea>
          <button class="btn btn-primary" type="submit">Invia</button>
        </form>
        <p class="hint-small msg-note">${opts.noteHtml || ''}</p>`
        : '<p class="notice notice-info" style="margin:12px">Questa conversazione è chiusa: il collegamento fra proprietario e clinica non è più attivo. I messaggi restano leggibili.</p>'}`;
      pending = [];
      const form = pane.querySelector('#msgForm');
      if (!form) return;
      const ta = pane.querySelector('#msgText'), strip = pane.querySelector('.msg-attach');
      const drawStrip = () => {
        strip.hidden = !pending.length;
        strip.innerHTML = pending.map((p, i) => `<span class="att-chip ${p.state}" translate="no">${/^image\//.test(p.file.type) ? '🖼' : '📄'} ${VR.esc(p.file.name)} <small>${bytes(p.file.size)}</small>${p.state === 'up' ? ' <small>invio…</small>' : `<button type="button" data-rm="${i}" aria-label="Togli ${VR.esc(p.file.name)}">×</button>`}</span>`).join('');
      };
      ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); form.requestSubmit(); } });
      ta.addEventListener('input', () => { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 160) + 'px'; });
      // anche incollando una foto
      ta.addEventListener('paste', (e) => {
        const files = [...(e.clipboardData?.files || [])];
        if (files.length && adv) { e.preventDefault(); addFiles(files); }
      });
      const addFiles = async (files) => {
        for (const f of files) {
          if (pending.length >= MAX_FILES) { alert(`Al massimo ${MAX_FILES} allegati per messaggio.`); break; }
          try { pending.push({ file: await M.prepareFile(f), state: '' }); } catch (e) { alert(e.message); }
        }
        drawStrip();
      };
      const fileIn = pane.querySelector('[data-file]');
      if (fileIn) fileIn.addEventListener('change', () => { addFiles([...fileIn.files]); fileIn.value = ''; });
      strip?.addEventListener('click', (e) => { const r = e.target.closest('[data-rm]'); if (r) { pending.splice(Number(r.dataset.rm), 1); drawStrip(); } });
      pane.querySelector('[data-attach]')?.addEventListener('click', () => fileIn.click());
      pane.querySelector('[data-tpl]')?.addEventListener('click', () => M.templates.pick(pane.querySelector('.tpl-anchor'), opts.clinicId, (t) => insertAtCursor(ta, t)));
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const text = ta.value.trim();
        if (!text && !pending.length) return;
        const btn = form.querySelector('button[type="submit"]'); btn.disabled = true;
        try {
          if (pending.length) {
            const up = [];
            for (const p of pending) { p.state = 'up'; drawStrip(); up.push(await M.upload(c.id, p.file)); }
            const { error } = await VR.sb.rpc('msg_send_files', { p_conv: c.id, p_body: text, p_files: up });
            if (error) throw error;
          } else {
            const { error } = await VR.sb.rpc('msg_send', { p_conv: c.id, p_body: text });
            if (error) throw error;
          }
          ta.value = ''; ta.style.height = 'auto'; pending = []; drawStrip();
          await refresh(true);
          ta.focus();
        } catch (err) {
          pending.forEach((p) => { p.state = ''; }); drawStrip();
          alert(VR.errorText(err));
        } finally { btn.disabled = false; }
      });
      if (!matchMedia('(hover: none)').matches) ta.focus();
    };

    const drawTicks = () => {
      pane.querySelectorAll('.bubble.mine[data-mid]').forEach((b) => {
        const seen = Number(b.dataset.mid) <= receipt;
        const t = b.querySelector('.tick');
        if (t) { t.textContent = seen ? '✓✓' : '✓'; t.classList.toggle('seen', seen); t.title = seen ? 'Letto' : 'Inviato'; }
      });
    };

    const loadThread = async (id, keepScroll, focusMid) => {
      const c = convs.find((x) => x.id === id);
      if (!c) return;
      const [th, fl, rc] = await Promise.all([
        VR.sb.rpc('msg_thread', { p_conv: id, p_limit: 200 }),
        adv ? VR.sb.rpc('msg_files', { p_conv: id }) : Promise.resolve({ data: [] }),
        adv ? VR.sb.rpc('msg_receipt', { p_conv: id }) : Promise.resolve({ data: null })
      ]);
      if (current !== id) return;
      const sc = pane.querySelector('.msg-scroll');
      if (!sc) return;
      if (th.error) { sc.innerHTML = `<div class="notice notice-error">${VR.esc(VR.errorText(th.error))}</div>`; return; }
      receipt = Number(rc.data?.seen_id || 0);
      const msgs = (th.data || []).slice().reverse();
      const files = fl.data || [];
      const sig = msgs.length + ':' + (msgs.length ? msgs[msgs.length - 1].id : 0) + ':' + files.length;
      if (shown.id === id && shown.sig === sig && !focusMid) { drawTicks(); return; }
      shown = { id, sig };
      const byMsg = {};
      files.forEach((a) => { (byMsg[a.message_id] ||= []).push(a); });
      const urls = files.length ? await M.signed(files.map((a) => a.storage_path)) : {};
      const hitIds = new Set((hits.get(id) || []).map((h) => Number(h.message_id)));
      let lastDay = '';
      const bubbles = msgs.map((m) => {
        const d = dayLabel(m.created_at);
        const sep = d !== lastDay ? `<div class="msg-day">${VR.esc(d)}</div>` : '';
        lastDay = d;
        const att = byMsg[m.id] || [];
        const auto = att.length && m.body === '📎 ' + att.map((a) => a.name.slice(0, 60)).join(', ');
        return sep + `
          <div class="bubble ${m.mine ? 'mine' : 'theirs'} role-${m.sender_role} ${hitIds.has(Number(m.id)) ? 'hit' : ''}" data-mid="${m.id}">
            ${!m.mine ? `<span class="bubble-who" translate="no">${VR.esc(who(m, c))}</span>` : ''}
            ${auto ? '' : `<p translate="no">${linkify(m.body)}</p>`}
            ${M.attachmentsHtml(att, urls)}
            <span class="bubble-when">${when(m.created_at)}${m.mine && adv ? ' <span class="tick">✓</span>' : ''}</span>
          </div>`;
      }).join('');
      const atBottom = sc.scrollHeight - sc.scrollTop - sc.clientHeight < 80;
      sc.innerHTML = bubbles || '<p class="muted" style="text-align:center">Scrivi il primo messaggio.</p>';
      drawTicks();
      const target = focusMid && sc.querySelector(`[data-mid="${focusMid}"]`);
      if (target) target.scrollIntoView({ block: 'center' });
      else if (atBottom || !keepScroll) sc.scrollTop = sc.scrollHeight;
    };

    const openConv = async (id, focusMid) => {
      const c = convs.find((x) => x.id === id);
      if (!c) return;
      current = id; shown = { id: null, sig: '' };
      buildPane(c);
      document.body.classList.add('msg-open');
      await loadThread(id, false, focusMid);
      // aprendola, risulta letta
      c.unread = 0; renderList(); M.unreadBadge();
      if (opts.onOpen) opts.onOpen(id);
    };
    const closeConv = () => { current = null; shown = { id: null, sig: '' }; document.body.classList.remove('msg-open'); pane.innerHTML = ''; renderList(); };

    const load = async () => {
      if (adv) {
        const r = await VR.sb.rpc('msg_inbox');
        if (!r.error) return r.data || [];
        if (!missing(r.error)) throw r.error;
        adv = false;   // database non ancora aggiornato: messaggi semplici come prima
      }
      const r = await VR.sb.rpc('msg_list');
      if (r.error) throw r.error;
      return (r.data || []).map((c) => ({ ...c, archived: false, needs_reply: false, files: 0 }));
    };
    const refresh = async (keepOpen) => {
      try { convs = await load(); } catch (e) { convBox.innerHTML = `<div class="notice notice-error">${VR.esc(VR.errorText(e))}</div>`; return; }
      [...sel].forEach((id) => { if (!convs.some((c) => c.id === id)) sel.delete(id); });
      if (current && !convs.some((c) => c.id === current)) closeConv();
      // La conversazione aperta si rilegge PRIMA di contare: aprendola, i messaggi
      // nuovi risultano letti, quindi né l'elenco né il menu devono mostrarli da leggere
      if (current) {
        await loadThread(current, keepOpen);
        const c = convs.find((x) => x.id === current);
        if (c) c.unread = 0;
      }
      renderList();
      M.unreadBadge();
    };

    const runSearch = async () => {
      const asked = q, found = new Map();
      if (asked.length >= 2 && adv) {
        const { data } = await VR.sb.rpc('msg_search', { p_q: asked });
        if (asked !== q) return;   // nel frattempo si è scritto altro: vale la ricerca più recente
        (data || []).forEach((h) => { if (!found.has(h.conversation_id)) found.set(h.conversation_id, []); found.get(h.conversation_id).push(h); });
      }
      hits = found;
      renderList();
    };
    let st;
    list.querySelector('.msg-q').addEventListener('input', (e) => { q = e.target.value.trim(); clearTimeout(st); st = setTimeout(runSearch, 280); });

    const bulkAct = async (action, ids) => {
      const { error } = await VR.sb.rpc('msg_mark', { p_convs: ids, p_action: action });
      if (error) { alert(VR.errorText(error)); return; }
      if ((action === 'unread' || action === 'archive') && ids.includes(current)) closeConv();
      await refresh(true);
    };

    list.addEventListener('click', async (ev) => {
      const f = ev.target.closest('[data-f]');
      if (f) { filter = f.dataset.f; sel.clear(); renderList(); return; }
      if (ev.target.closest('[data-selstart]')) { selecting = true; renderList(); return; }
      if (ev.target.closest('[data-selend]')) { selecting = false; sel.clear(); renderList(); return; }
      if (ev.target.closest('[data-compose]')) {
        const r = await M.groupCompose(opts.clinicId);
        if (r) { await refresh(true); flash(`Messaggio inviato a ${r.sent} ${r.sent === 1 ? 'cliente' : 'clienti'}${r.failed ? ` (${r.failed} non più collegati alla clinica: saltati)` : ''}. Le risposte arriveranno qui, una conversazione per ciascuno.`); }
        return;
      }
      const a = ev.target.closest('[data-act]');
      if (a) { await bulkAct(a.dataset.act, [...sel]); sel.clear(); selecting = false; renderList(); return; }
      const b = ev.target.closest('[data-conv]'); if (!b) return;
      if (selecting) { const id = b.dataset.conv; if (sel.has(id)) sel.delete(id); else sel.add(id); renderList(); return; }
      const h = hits.get(b.dataset.conv);
      await openConv(b.dataset.conv, h ? h[h.length - 1].message_id : null);
    });
    list.addEventListener('change', (ev) => {
      if (ev.target.closest('[data-selall]')) {
        const vis = visible();
        if (ev.target.checked) vis.forEach((c) => sel.add(c.id)); else vis.forEach((c) => sel.delete(c.id));
        renderList();
      }
    });
    list.addEventListener('keydown', (ev) => { if ((ev.key === 'Enter' || ev.key === ' ') && ev.target.matches('[data-conv]')) { ev.preventDefault(); ev.target.click(); } });
    pane.addEventListener('click', async (ev) => {
      if (ev.target.closest('[data-back]')) { closeConv(); return; }
      if (ev.target.closest('[data-unread]') && current) { await bulkAct('unread', [current]); return; }
      const ar = ev.target.closest('[data-arch]');
      if (ar && current) { const c = convs.find((x) => x.id === current); await bulkAct(c && c.archived ? 'unarchive' : 'archive', [current]); }
    });

    M.openConversation = async (id) => { await refresh(); await openConv(id); };
    await refresh();
    // I messaggi nuovi arrivano da soli: Supabase avvisa il browser appena la riga è scritta.
    M.live(() => refresh(true));
    // rete di sicurezza, se la connessione in tempo reale cade; più spesso a conversazione aperta (per le spunte)
    polling = setInterval(() => { if (document.visibilityState === 'visible') refresh(true); }, 20000);
    const rcPoll = setInterval(async () => {
      if (!current || !adv || document.visibilityState !== 'visible') return;
      const { data } = await VR.sb.rpc('msg_receipt', { p_conv: current });
      if (data) { receipt = Number(data.seen_id || 0); drawTicks(); }
    }, 8000);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') refresh(true); });
    window.addEventListener('beforeunload', () => { clearInterval(polling); clearInterval(rcPoll); });
    return { refresh, get advanced() { return adv; } };
  };
})();
