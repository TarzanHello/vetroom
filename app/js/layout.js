// =============================================================
//  VETROOM 2 — Struttura delle pagine (grafica originale Vetroom)
//  Gestionale: intestazione verde petrolio + barra laterale scura
//    (su telefono e tablet diventa una griglia di icone).
//  Proprietario: intestazione chiara + barra in basso a 4 pulsanti.
//  Uso: <body data-shell="clinic" data-nav="agenda"> … VR.buildShell()
// =============================================================
(function () {
  const VR = window.VR;
  const icon = (n) => VR.url('img/icons/' + n + '.png');

  const CLINIC_NAV = [
    { key: 'studio', title: 'Studio', items: [
      { k: 'home', l: 'Dashboard', h: 'clinica/', i: 'dashboard' },
      { k: 'agenda', l: 'Agenda', h: 'clinica/agenda.html', i: 'agenda' },
      { k: 'furrfinder', l: 'FurrFinder', h: 'clinica/furrfinder.html', i: '../furrfinder-mark' },
      { k: 'messaggi', l: 'Messaggi', h: 'clinica/messaggi.html', i: 'owner_profile', badge: true }
    ] },
    { key: 'anagrafiche', title: 'Anagrafiche', items: [
      { k: 'clienti', l: 'Proprietari', h: 'clinica/clienti.html', i: 'owner' },
      { k: 'animali', l: 'Animali', h: 'clinica/animali.html', i: 'pets' }
    ] },
    { key: 'quick', title: 'Azioni rapide', items: [
      { k: 'new-patient', l: '+ Paziente', h: 'clinica/?paziente=nuovo', i: 'add_visit', open: 'new' },
      { k: 'new-appt', l: '+ Appuntamento', h: 'clinica/agenda.html?nuovo=1', i: 'appointment' }
    ] },
    { key: 'config', title: 'Configurazione', items: [
      { k: 'impostazioni', l: 'Opzioni', h: 'clinica/impostazioni.html', i: 'options' },
      { k: 'team', l: 'Team', h: 'clinica/team.html', i: 'team', adminOnly: true },
      { k: 'guida', l: 'Guida', h: '/guida/', i: 'upload_document', blank: true },
      { k: 'piattaforma', l: 'Pannello admin', h: 'piattaforma/', i: 'scan_code', platformOnly: true }
    ] }
  ];

  const OWNER_TABS = [
    { k: 'home', l: 'Home', h: 'proprietario/', i: 'owner_home' },
    { k: 'animali', l: 'Pet', h: 'proprietario/animali.html', i: 'owner_pets' },
    { k: 'cliniche', l: 'Cliniche', h: 'proprietario/cliniche.html', i: 'owner_vets' },
    { k: 'messaggi', l: 'Messaggi', h: 'proprietario/messaggi.html', i: 'owner_profile', badge: true },
    { k: 'profilo', l: 'Impostazioni', h: 'proprietario/profilo.html', i: 'owner_options' }
  ];

  const GROUPS_KEY = 'vetroom_nav_closed';

  const PLATFORM_NAV = [
    { key: 'admin', title: 'Amministrazione', items: [
      { k: 'panoramica', l: 'Panoramica', h: '#panoramica', i: 'dashboard', anchor: true },
      { k: 'cliniche', l: 'Cliniche', h: '#cliniche', i: 'owner_vets', anchor: true },
      { k: 'iscritti', l: 'Iscritti', h: '#iscritti', i: 'owner', anchor: true },
      { k: 'messaggi', l: 'Messaggi', h: '#messaggi', i: 'owner_profile', anchor: true, badge: true },
      { k: 'errori', l: 'Errori', h: '#errori', i: 'update_visit', anchor: true },
      { k: 'account', l: 'Il mio account', h: '#account', i: 'options', anchor: true }
    ] },
    { key: 'altro', title: 'Altro', items: [
      { k: 'guida', l: 'Guida', h: '/guida/', i: 'upload_document', blank: true }
    ] }
  ];

  VR.buildShell = () => {
    const body = document.body;
    const kind = body.dataset.shell;
    let active = body.dataset.nav || '';
    // Le "azioni rapide" hanno la loro icona accesa quando si crea qualcosa di nuovo
    if (new URLSearchParams(location.search).get('nuovo')) {
      if (/\/cliente\.html$/.test(location.pathname)) active = 'new-owner';
      else if (/\/animale\.html$/.test(location.pathname)) active = 'new-pet';
      else if (/\/agenda\.html$/.test(location.pathname)) active = 'new-appt';
    }
    const main = document.querySelector('main');
    if (!kind || !main || document.querySelector('.vr-header, .vr-owner-header')) return;
    document.querySelectorAll('header.topbar').forEach((h) => h.remove());

    if (kind === 'clinic' || kind === 'platform') {
      const isPlat = kind === 'platform';
      const NAV = isPlat ? PLATFORM_NAV : CLINIC_NAV;
      body.classList.add('vr-app');
      if (isPlat) body.classList.add('vr-platform');
      const closed = new Set(JSON.parse(localStorage.getItem(GROUPS_KEY) || '[]'));
      const header = document.createElement('header');
      header.className = 'vr-header';
      header.innerHTML = `
        <div class="vr-header-left">
          <a href="${VR.url(isPlat ? 'piattaforma/' : 'clinica/')}" class="vr-logo-link"><img class="vr-logo-img" src="${VR.url('img/logo-bianco.png')}" alt="Vetroom"></a>
          <div class="vr-logo-text">
            <div class="vr-logo-title" id="shellClinic">&nbsp;</div>
            <div class="vr-logo-sub" id="shellRole"></div>
          </div>
        </div>
        <div class="vr-header-right">
          <button class="vr-search-btn" type="button" data-open-search title="Cerca (tasto /)" aria-label="Cerca proprietari e animali" ${isPlat ? 'hidden' : ''}>
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2.4"/><path d="M16.5 16.5L21 21" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
            <span class="vr-search-lbl">Cerca</span>
          </button>
          <span class="vr-header-user" id="who"></span>
          <button class="vr-lang" type="button" data-lang-toggle translate="no" title="Italiano / English">${VR.lang === 'en' ? 'IT' : 'EN'}</button>
          <button class="vr-header-btn" data-action="logout" type="button">Esci</button>
        </div>`;
      const nav = document.createElement('nav');
      nav.className = 'vr-sidebar';
      nav.setAttribute('aria-label', 'Menu del gestionale');
      nav.innerHTML = NAV.map((g) => {
        const hasActive = g.items.some((it) => it.k === active);
        const isClosed = closed.has(g.key) && !hasActive;
        return `
        <div class="vr-nav-group ${isClosed ? 'collapsed' : ''}" data-group="${g.key}">
          <button type="button" class="vr-nav-group-title" aria-expanded="${!isClosed}">
            <span>${g.title}</span><span class="vr-nav-group-chevron" aria-hidden="true">▾</span>
          </button>
          <div class="vr-nav-group-items">
            ${g.items.map((it) => `
              <a href="${it.anchor ? it.h : VR.url(it.h)}" ${it.blank ? 'target="_blank" rel="noopener"' : ''} ${it.badge ? 'data-has-badge' : ''} ${it.open ? `data-open-search="${it.open}"` : ''} class="vr-nav-item ${it.k === active ? 'active' : ''}" ${it.adminOnly ? 'data-admin-only hidden' : ''} ${it.platformOnly ? 'data-platform-only hidden' : ''} title="${it.l}" ${it.k === active ? 'aria-current="page"' : ''}>
                <img class="vr-nav-icon" src="${icon(it.i)}" alt="" aria-hidden="true">
                <span class="vr-nav-label">${it.l}</span>
              </a>`).join('')}
          </div>
        </div>`;
      }).join('');
      nav.addEventListener('click', (ev) => {
        const t = ev.target.closest('.vr-nav-group-title'); if (!t) return;
        const g = t.closest('.vr-nav-group');
        g.classList.toggle('collapsed');
        t.setAttribute('aria-expanded', !g.classList.contains('collapsed'));
        const now = [...nav.querySelectorAll('.vr-nav-group.collapsed')].map((x) => x.dataset.group);
        localStorage.setItem(GROUPS_KEY, JSON.stringify(now));
      });

      const layout = document.createElement('div');
      layout.className = 'vr-layout';
      main.classList.add('vr-main');
      main.parentNode.insertBefore(header, main);
      main.parentNode.insertBefore(layout, main);
      layout.appendChild(nav);
      layout.appendChild(main);

      // Telefono e tablet: barra in basso con le 5 cose che servono ogni giorno.
      // Il resto del menu (con le stesse icone) si apre da "Menu".
      const tabActive = isPlat ? '' : (['home', 'agenda', 'animali'].includes(active) ? active : (['clienti', 'impostazioni', 'team', 'piattaforma', 'furrfinder'].includes(active) ? 'menu' : ''));
      const bar = document.createElement('nav');
      bar.className = 'vr-tabbar';
      bar.setAttribute('aria-label', 'Menu rapido');
      const tb = (k, href, ic, label) => `<a class="vr-tb ${tabActive === k ? 'is-active' : ''}" href="${VR.url(href)}" ${tabActive === k ? 'aria-current="page"' : ''}><img src="${icon(ic)}" alt="" aria-hidden="true"><span>${label}</span></a>`;
      bar.innerHTML = isPlat
        ? `<a class="vr-tb" href="#cliniche"><img src="${icon('owner_vets')}" alt="" aria-hidden="true"><span>Cliniche</span></a>
           <a class="vr-tb" href="#iscritti"><img src="${icon('owner')}" alt="" aria-hidden="true"><span>Iscritti</span></a>
           <a class="vr-tb vr-tb-main" href="#messaggi" data-has-badge><img src="${icon('owner_profile')}" alt="" aria-hidden="true"><span>Messaggi</span></a>
           <a class="vr-tb" href="#errori"><img src="${icon('update_visit')}" alt="" aria-hidden="true"><span>Errori</span></a>
           <button class="vr-tb" type="button" data-menu-toggle aria-expanded="false"><svg viewBox="0 0 24 24" width="34" height="34" aria-hidden="true"><rect x="1" y="1" width="22" height="22" rx="6" fill="#fff" stroke="rgba(0,0,0,.5)"/><path d="M7 8h10M7 12h10M7 16h10" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/></svg><span>Menu</span></button>`
        : tb('home', 'clinica/', 'dashboard', 'Oggi') + tb('agenda', 'clinica/agenda.html', 'agenda', 'Agenda') +
        `<button class="vr-tb vr-tb-main" type="button" data-open-search aria-label="Cerca o registra un paziente"><img src="${icon('add_visit')}" alt="" aria-hidden="true"><span>Paziente</span></button>` +
        tb('animali', 'clinica/animali.html', 'pets', 'Animali') +
        `<button class="vr-tb ${tabActive === 'menu' ? 'is-active' : ''}" type="button" data-menu-toggle aria-expanded="false"><svg viewBox="0 0 24 24" width="34" height="34" aria-hidden="true"><rect x="1" y="1" width="22" height="22" rx="6" fill="#fff" stroke="rgba(0,0,0,.5)"/><path d="M7 8h10M7 12h10M7 16h10" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/></svg><span>Menu</span></button>`;
      document.body.appendChild(bar);
      // Durante la visita lo schermo serve per scrivere: niente barra in basso
      if (/\/visita\.html$/.test(location.pathname)) body.classList.add('vr-focus');
      const backdrop = document.createElement('div');
      backdrop.className = 'vr-menu-backdrop';
      document.body.appendChild(backdrop);
      const setMenu = (open) => {
        body.classList.toggle('vr-menu-open', open);
        bar.querySelector('[data-menu-toggle]').setAttribute('aria-expanded', open);
      };
      bar.querySelector('[data-menu-toggle]').addEventListener('click', () => setMenu(!body.classList.contains('vr-menu-open')));
      backdrop.addEventListener('click', () => setMenu(false));
      nav.addEventListener('click', (ev) => { if (ev.target.closest('.vr-nav-item')) setMenu(false); });
      document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') setMenu(false); });
    }

    if (kind === 'owner') {
      body.classList.add('vr-owner-body');
      const header = document.createElement('header');
      header.className = 'vr-owner-header';
      header.innerHTML = `
        <div class="vr-owner-header-row">
          <span class="vr-owner-header-spacer"></span>
          <a class="vr-owner-brand" href="${VR.url('proprietario/')}" aria-label="FurrFinder"><img src="${VR.url('img/furrfinder-mark.png')}" alt="" width="32" height="32"><span translate="no">FurrFinder</span></a>
          <div class="vr-owner-header-right">
            <span class="vr-owner-pill" id="who"></span>
            <button class="vr-lang vr-lang-owner" type="button" data-lang-toggle translate="no" title="Italiano / English">${VR.lang === 'en' ? 'IT' : 'EN'}</button>
            <button class="vr-owner-logout" data-action="logout" type="button">Esci</button>
          </div>
        </div>`;
      const bar = document.createElement('nav');
      bar.className = 'vr-owner-tabbar';
      bar.setAttribute('aria-label', 'Navigazione');
      bar.innerHTML = OWNER_TABS.map((t) => `
        <div class="vr-tab-slot"><a class="vr-tab ${t.k === active ? 'is-active' : ''}" href="${VR.url(t.h)}" ${t.k === active ? 'aria-current="page"' : ''} ${t.badge ? 'data-has-badge' : ''}>
          <img class="vr-tab-ic" src="${icon(t.i)}" alt="" aria-hidden="true"><span class="vr-tab-tx">${t.l}</span>
        </a></div>`).join('');
      main.classList.add('vr-owner-main');
      main.parentNode.insertBefore(header, main);
      document.body.appendChild(bar);
    }
    if (VR.watchMessages) VR.watchMessages();
  };

  const setLangLabels = () => document.querySelectorAll('[data-lang-toggle]').forEach((b) => { b.textContent = VR.lang === 'en' ? 'IT' : 'EN'; });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setLangLabels); else setLangLabels();

  // ---------- Cerca o registra un paziente (ricerca unica + accettazione rapida) ----------
  let searchData = null;
  const digits = (x) => String(x || '').replace(/\D/g, '');
  const loadSearchData = async () => {
    const cid = localStorage.getItem('vetroom_clinica');
    const rows = await VR.fetchAll(() => VR.sb.from('clients')
      .select('id, first_name, last_name, phone, email, pet_access(status, pets(id, name, species, microchip, deleted_at))')
      .eq('clinic_id', cid).order('id'));
    const out = [];
    rows.forEach((c) => {
      const owner = VR.clientName ? VR.clientName(c) : [c.last_name, c.first_name].filter(Boolean).join(' ');
      const pets = (c.pet_access || []).filter((a) => a.status === 'active' && a.pets && !a.pets.deleted_at).map((a) => a.pets);
      out.push({ type: 'owner', id: c.id, title: owner, phone: digits(c.phone), sub: [c.phone, c.email, pets.map((p) => p.name).join(', ')].filter(Boolean).join(' · '),
        href: VR.url('clinica/cliente.html?id=' + c.id), text: [owner, c.phone, digits(c.phone), c.email, ...pets.map((p) => p.name)].filter(Boolean).join(' ').toLowerCase() });
      pets.forEach((p) => out.push({ type: 'pet', id: p.id, title: p.name, owner, sub: [p.species, owner, p.microchip].filter(Boolean).join(' · '),
        href: VR.url('clinica/animale.html?id=' + p.id), text: [p.name, p.species, p.microchip, owner, c.phone, digits(c.phone)].filter(Boolean).join(' ').toLowerCase() }));
    });
    return out;
  };

  const SPECIES_CHIPS = ['Cane', 'Gatto'];
  const openSearch = async (mode) => {
    const vet = () => !VR.ctx || VR.ctx.isVet;
    let dlg = document.getElementById('vr-search');
    if (!dlg) {
      dlg = document.createElement('dialog');
      dlg.id = 'vr-search'; dlg.className = 'dlg search-dlg';
      dlg.innerHTML = `
        <div data-view="find">
          <input type="search" id="vrQ" placeholder="Cerca proprietario, animale, telefono o microchip" aria-label="Cerca" autocomplete="off">
          <div class="search-res" id="vrRes"><p class="muted">Caricamento…</p></div>
          <div class="search-foot">
            <button class="btn btn-primary btn-small" type="button" data-new>${VR.icon ? VR.icon('add_visit') : ''}Nuovo paziente</button>
            <span class="hint-small" style="flex:1">Invio apre il primo risultato</span>
            <button class="btn btn-ghost btn-small" type="button" data-close>Chiudi</button>
          </div>
        </div>
        <form data-view="new" hidden novalidate autocomplete="off">
          <h2 style="margin:0 0 4px">Nuovo paziente</h2>
          <p class="hint-small" style="margin:0 0 12px">Bastano i dati essenziali: il resto lo completi dopo, con calma.</p>
          <fieldset class="qp-box" id="qpOwnerBox">
            <legend>Proprietario</legend>
            <div class="qp-fixed" id="qpOwnerFixed" hidden></div>
            <div class="qp-grid" id="qpOwnerFields">
              <div><label for="qpLast">Cognome</label><input type="text" id="qpLast" required></div>
              <div><label for="qpFirst">Nome</label><input type="text" id="qpFirst"></div>
              <div class="qp-wide"><label for="qpPhone">Telefono (per WhatsApp e promemoria)</label><input type="tel" id="qpPhone" inputmode="tel"></div>
            </div>
            <div class="qp-dup" id="qpDup" hidden></div>
          </fieldset>
          <fieldset class="qp-box">
            <legend>Animale</legend>
            <div class="qp-grid">
              <div class="qp-wide"><label for="qpPet">Nome dell'animale</label><input type="text" id="qpPet" required></div>
              <div class="qp-wide"><span class="qp-lbl">Specie</span>
                <div class="qp-chips" role="radiogroup" aria-label="Specie">
                  ${SPECIES_CHIPS.map((x) => `<label class="qp-chip"><input type="radio" name="qpSpecies" value="${x}"><span>${x}</span></label>`).join('')}
                  <select id="qpOther" aria-label="Altra specie"><option value="">Altra specie…</option>${(VR.SPECIES || []).filter((x) => !SPECIES_CHIPS.includes(x)).map((x) => `<option>${x}</option>`).join('')}</select>
                </div></div>
              <div><span class="qp-lbl">Sesso</span>
                <div class="qp-chips" role="radiogroup" aria-label="Sesso">
                  <label class="qp-chip"><input type="radio" name="qpSex" value="M"><span>Maschio</span></label>
                  <label class="qp-chip"><input type="radio" name="qpSex" value="F"><span>Femmina</span></label>
                </div></div>
              <div><label for="qpBirth">Data di nascita (se nota)</label><input type="date" id="qpBirth"></div>
            </div>
          </fieldset>
          <div id="qpMsg" hidden></div>
          <div class="actions-bar" style="margin-top:12px">
            <button class="btn btn-primary" type="submit" data-go="visit">Crea e inizia la visita</button>
            <button class="btn btn-ghost" type="submit" data-go="appt">Crea e fissa appuntamento</button>
            <button class="btn btn-ghost" type="submit" data-go="card">Crea e apri la scheda</button>
            <span class="spacer"></span>
            <button class="btn btn-ghost btn-small" type="button" data-back>← Torna alla ricerca</button>
          </div>
        </form>`;
      document.body.appendChild(dlg);
      const find = dlg.querySelector('[data-view="find"]'), form = dlg.querySelector('[data-view="new"]');
      const q = dlg.querySelector('#vrQ'), res = dlg.querySelector('#vrRes');
      const st = { clientId: null, fixedClient: null, created: { clientId: null, petId: null } };

      const render = () => {
        if (!searchData) return;
        const terms = q.value.toLowerCase().split(/\s+/).filter(Boolean);
        if (!terms.length) { res.innerHTML = '<p class="muted">Scrivi un nome, un cognome o un telefono. Se non lo trovi, premi <b>Nuovo paziente</b>.</p>'; return; }
        // prima chi ha il nome che inizia con quanto scritto (es. "fi" → Fido prima di Rossi)
        const hits = searchData.filter((x) => terms.every((t) => x.text.includes(t)))
          .map((x, i) => ({ x, i, s: x.title.toLowerCase().startsWith(terms[0]) || x.title.toLowerCase().includes(' ' + terms[0]) ? 0 : 1 }))
          .sort((a, b) => a.s - b.s || a.i - b.i).map((r) => r.x).slice(0, 15);
        res.innerHTML = hits.map((x) => {
          const acts = x.type === 'pet'
            ? `${vet() ? `<a class="btn btn-primary btn-small" href="${VR.url('clinica/visita.html?animale=' + x.id)}">Visita</a>` : ''}<a class="btn btn-ghost btn-small" href="${VR.url('clinica/agenda.html?animale=' + x.id)}">Appuntamento</a>`
            : `<button class="btn btn-ghost btn-small" type="button" data-addpet="${x.id}">+ Animale</button>`;
          return `<div class="search-row"><a href="${x.href}" class="search-hit"><img src="${VR.url('img/icons/' + (x.type === 'pet' ? 'pets' : 'owner') + '.png')}" alt=""><span><strong>${VR.esc(x.title)}</strong><small>${VR.esc(x.sub)}</small></span></a><div class="search-acts">${acts}</div></div>`;
        }).join('') || `<p class="muted">Nessun risultato per “${VR.esc(q.value.trim())}”. <button class="btn btn-primary btn-small" type="button" data-new>Registralo come nuovo paziente</button></p>`;
      };

      const showFind = () => { form.hidden = true; find.hidden = false; setTimeout(() => q.focus(), 30); };
      const showNew = (client) => {
        form.reset(); st.created = { clientId: null, petId: null };
        st.fixedClient = client || null;
        const fixed = dlg.querySelector('#qpOwnerFixed'), fields = dlg.querySelector('#qpOwnerFields');
        VR.hide(dlg.querySelector('#qpDup')); VR.hide(dlg.querySelector('#qpMsg'));
        if (client) {
          fixed.innerHTML = `<strong>${VR.esc(client.title)}</strong> <span class="muted">${VR.esc(client.sub || '')}</span> <button class="linklike" type="button" data-unfix>cambia</button>`;
          VR.show(fixed); fields.hidden = true;
        } else {
          VR.hide(fixed); fields.hidden = false;
          // se nella ricerca c'era un numero di telefono, lo riportiamo
          const typed = q.value.trim();
          if (digits(typed).length >= 6 && digits(typed).length >= typed.replace(/\s/g, '').length - 1) dlg.querySelector('#qpPhone').value = typed;
        }
        const bv = form.querySelector('[data-go="visit"]'), ba = form.querySelector('[data-go="appt"]');
        bv.hidden = !vet(); ba.className = 'btn ' + (vet() ? 'btn-ghost' : 'btn-primary');
        find.hidden = true; form.hidden = false;
        setTimeout(() => dlg.querySelector(client ? '#qpPet' : '#qpLast').focus(), 30);
      };

      q.addEventListener('input', render);
      q.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { const a = res.querySelector('a.search-hit'); if (a) location.href = a.href; }
        if (e.key === 'Escape') { e.preventDefault(); dlg.close(); }
      });
      dlg.addEventListener('click', (e) => {
        if (e.target === dlg || e.target.closest('[data-close]')) { dlg.close(); return; }
        if (e.target.closest('[data-new]')) { showNew(null); return; }
        if (e.target.closest('[data-back]')) { showFind(); return; }
        if (e.target.closest('[data-unfix]')) { showNew(null); return; }
        const add = e.target.closest('[data-addpet]');
        if (add) { showNew(searchData.find((x) => x.type === 'owner' && x.id === add.dataset.addpet)); return; }
        const use = e.target.closest('[data-use]');
        if (use) { showNew(searchData.find((x) => x.type === 'owner' && x.id === use.dataset.use)); }
      });
      dlg.querySelector('#qpOther').addEventListener('change', (e) => { if (e.target.value) form.querySelectorAll('[name=qpSpecies]').forEach((r) => { r.checked = false; }); });
      form.querySelectorAll('[name=qpSpecies]').forEach((r) => r.addEventListener('change', () => { dlg.querySelector('#qpOther').value = ''; }));

      // Avviso doppioni: stesso telefono o stesso cognome e nome
      const checkDup = () => {
        const box = dlg.querySelector('#qpDup');
        if (st.fixedClient || !searchData) { VR.hide(box); return; }
        const ph = digits(dlg.querySelector('#qpPhone').value);
        const last = dlg.querySelector('#qpLast').value.trim().toLowerCase(), first = dlg.querySelector('#qpFirst').value.trim().toLowerCase();
        const owners = searchData.filter((x) => x.type === 'owner');
        const same = owners.filter((o) => (ph.length >= 6 && o.phone && o.phone.slice(-9) === ph.slice(-9)) ||
          (last && first && o.title.toLowerCase().includes(last) && o.title.toLowerCase().includes(first))).slice(0, 3);
        if (!same.length) { VR.hide(box); return; }
        box.innerHTML = 'Forse è già registrato: ' + same.map((o) => `<button class="btn btn-ghost btn-small" type="button" data-use="${o.id}">${VR.esc(o.title)}${o.sub ? ' · ' + VR.esc(o.sub.split(' · ')[0]) : ''}</button>`).join(' ');
        VR.show(box);
      };
      ['#qpPhone', '#qpLast', '#qpFirst'].forEach((sel) => dlg.querySelector(sel).addEventListener('input', checkDup));
      form.addEventListener('input', () => VR.hide(dlg.querySelector('#qpMsg')));

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const go = e.submitter?.dataset.go || (vet() ? 'visit' : 'appt');
        const m = dlg.querySelector('#qpMsg'); VR.hide(m);
        const last = dlg.querySelector('#qpLast').value.trim(), first = dlg.querySelector('#qpFirst').value.trim();
        const phone = dlg.querySelector('#qpPhone').value.trim(), name = dlg.querySelector('#qpPet').value.trim();
        const species = (form.querySelector('[name=qpSpecies]:checked') || {}).value || dlg.querySelector('#qpOther').value || null;
        const sex = (form.querySelector('[name=qpSex]:checked') || {}).value || null;
        const birth = dlg.querySelector('#qpBirth').value || null;
        if (!st.fixedClient && !last && !first) { VR.say(m, 'Scrivi almeno il cognome del proprietario.', 'error'); dlg.querySelector('#qpLast').focus(); return; }
        if (!name) { VR.say(m, 'Scrivi il nome dell\'animale.', 'error'); dlg.querySelector('#qpPet').focus(); return; }
        const cid = localStorage.getItem('vetroom_clinica');
        const btns = form.querySelectorAll('button[type=submit]'); btns.forEach((b) => { b.disabled = true; });
        try {
          const r = await VR.createPatient({
            clinicId: cid,
            clientId: st.fixedClient ? st.fixedClient.id : st.created.clientId,
            petId: st.created.petId,
            owner: { last_name: last || null, first_name: first || null, phone: phone || null },
            pet: { name, species, sex, birth_date: birth }
          });
          searchData = null;
          const dest = go === 'visit' ? 'clinica/visita.html?animale=' + r.petId
            : go === 'appt' ? 'clinica/agenda.html?animale=' + r.petId
            : 'clinica/animale.html?id=' + r.petId;
          location.href = VR.url(dest);
        } catch (err) {
          if (err && err.clientId) st.created.clientId = err.clientId;
          if (err && err.petId) st.created.petId = err.petId;
          VR.say(m, (VR.errorText ? VR.errorText(err) : String(err)) + ' Riprova: quello che è già stato salvato non verrà duplicato.', 'error');
          btns.forEach((b) => { b.disabled = false; });
        }
      });
      dlg._render = render; dlg._showNew = showNew; dlg._showFind = showFind;
    }
    if (!dlg.open) dlg.showModal();
    const q = dlg.querySelector('#vrQ'); q.value = '';
    dlg._showFind();
    if (!searchData) {
      try { searchData = await loadSearchData(); } catch (e) { searchData = []; }
    }
    dlg._render();
    if (mode === 'new') dlg._showNew(null);
  };
  VR.openSearch = openSearch;
  document.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-open-search]');
    if (!b || (b.closest('#vr-search'))) return;
    ev.preventDefault();
    openSearch(b.dataset.openSearch === 'new' ? 'new' : null);
  });
  document.addEventListener('keydown', (ev) => {
    if (ev.key === '/' && document.body.classList.contains('vr-app') && !ev.target.closest('input, textarea, select, [contenteditable]')) { ev.preventDefault(); openSearch(); }
  });
  // Link diretto: …/clinica/?paziente=nuovo apre subito la registrazione
  if (/[?&]paziente=nuovo/.test(location.search)) {
    window.addEventListener('load', () => setTimeout(() => { if (document.body.dataset.shell === 'clinic') openSearch('new'); }, 400));
  }

  // Cambio lingua IT / EN
  document.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-lang-toggle]');
    if (b && VR.setLang) VR.setLang(VR.lang === 'en' ? 'it' : 'en');
  });

  // Dati della clinica nell'intestazione (chiamata da loadClinicContext)
  VR.shellSetClinic = (ctx) => {
    const n = document.getElementById('shellClinic');
    if (n) n.textContent = ctx.clinic?.name || 'La tua clinica';
    const r = document.getElementById('shellRole');
    if (r) r.textContent = VR.ROLE?.[ctx.role] || '';
    if (ctx.role === 'admin') document.querySelectorAll('[data-admin-only]').forEach((x) => x.removeAttribute('hidden'));
  };

  // Icona illustrata per i pulsanti d'azione (come nel vecchio Vetroom)
  // Il numero di messaggi non letti si aggiorna da solo su tutte le pagine
  VR.watchMessages = () => {
    if (!document.querySelector('[data-has-badge]')) return;
    const read = async () => {
      try { const { data } = await VR.sb.rpc('msg_unread'); VR.navBadge(Number(data || 0)); } catch { /* non importante */ }
    };
    read();
    try {
      if (VR.sb.channel) {
        VR.sb.channel('vr-badge')
          .on('postgres_changes', { event: 'INSERT', schema: 'public', table: 'messages' }, read)
          .subscribe();
      }
    } catch { /* resta il controllo periodico */ }
    setInterval(() => { if (document.visibilityState === 'visible') read(); }, 60000);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') read(); });
  };

  VR.navBadge = (n) => {
    document.querySelectorAll('[data-has-badge]').forEach((a) => {
      let d = a.querySelector('.nav-dot');
      if (!n) { if (d) d.remove(); return; }
      if (!d) { d = document.createElement('span'); d.className = 'nav-dot'; a.appendChild(d); }
      d.textContent = n > 9 ? '9+' : String(n);
    });
  };
  VR.icon = (name, cls = 'vr-action-icon') => `<img class="${cls}" src="${icon(name)}" alt="" aria-hidden="true">`;
})();

// =============================================================
//  PRESENTAZIONI BREVI (a colpo d'occhio) all'apertura delle sezioni nuove
//  VR.intro({ key, slides: [{ icon, title, text, cta, action }], force })
//  Ricompare a ogni apertura finché non si spunta "Non mostrare più".
// =============================================================
(function () {
  const VR = window.VR;
  const storeKey = (key) => `vetroom_intro_${key}_${(VR.ctx && VR.ctx.session && VR.ctx.session.user.id) || 'x'}`;
  VR.introSeen = (key) => localStorage.getItem(storeKey(key)) === 'no';

  VR.intro = ({ key, slides, force = false }) => {
    if (!slides || !slides.length) return;
    if (!force && VR.introSeen(key)) return;
    let i = 0;
    const dlg = document.createElement('dialog');
    dlg.className = 'dlg intro-dlg';
    dlg.setAttribute('aria-label', 'Presentazione');
    dlg.innerHTML = `
      <button class="intro-x" type="button" aria-label="Chiudi">×</button>
      <div class="intro-stage" aria-live="polite"></div>
      <div class="intro-dots" role="tablist">${slides.map((_, k) => `<button type="button" role="tab" aria-label="Pagina ${k + 1}" data-dot="${k}"></button>`).join('')}</div>
      <div class="intro-foot">
        <div class="intro-nav">
          <button class="btn btn-ghost" type="button" data-prev>Indietro</button>
          <button class="btn btn-primary" type="button" data-next>Avanti</button>
        </div>
        <label class="intro-never"><input type="checkbox" ${VR.introSeen(key) ? 'checked' : ''}> Non mostrare più</label>
      </div>`;
    document.body.appendChild(dlg);
    const stage = dlg.querySelector('.intro-stage');
    const close = () => {
      if (dlg.querySelector('.intro-never input').checked) localStorage.setItem(storeKey(key), 'no');
      else localStorage.removeItem(storeKey(key));
      dlg.close(); dlg.remove();
    };
    const show = (k, dir = 1) => {
      i = Math.max(0, Math.min(slides.length - 1, k));
      const s = slides[i], last = i === slides.length - 1;
      stage.innerHTML = `
        <div class="intro-slide ${dir < 0 ? 'from-left' : ''}" style="--intro-tint:${s.tint || '#e6f4f1'}">
          <div class="intro-art">${s.icon ? `<span class="intro-disc"><img src="${VR.url(s.icon)}" alt=""></span>` : ''}</div>
          ${s.kicker ? `<span class="intro-kicker">${VR.esc(s.kicker)}</span>` : ''}
          <h2>${VR.esc(s.title)}</h2>
          <p>${VR.esc(s.text)}</p>
        </div>`;
      dlg.querySelectorAll('[data-dot]').forEach((d, k) => d.setAttribute('aria-selected', k === i));
      dlg.querySelector('[data-prev]').style.visibility = i === 0 ? 'hidden' : 'visible';
      const next = dlg.querySelector('[data-next]');
      next.textContent = last ? (s.cta || 'Inizia') : 'Avanti';
    };
    dlg.addEventListener('click', async (e) => {
      if (e.target.closest('.intro-x') || e.target === dlg) { close(); return; }
      if (e.target.closest('[data-prev]')) { show(i - 1, -1); return; }
      const dot = e.target.closest('[data-dot]');
      if (dot) { show(Number(dot.dataset.dot), Number(dot.dataset.dot) < i ? -1 : 1); return; }
      if (e.target.closest('[data-next]')) {
        if (i < slides.length - 1) { show(i + 1); return; }
        const act = slides[i].action;
        close();
        if (typeof act === 'function') act();
      }
    });
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
    dlg.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight') { e.preventDefault(); show(i + 1); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); show(i - 1, -1); }
    });
    // scorrimento con il dito
    let sx = null;
    stage.addEventListener('touchstart', (e) => { sx = e.touches[0].clientX; }, { passive: true });
    stage.addEventListener('touchend', (e) => {
      if (sx == null) return;
      const dx = e.changedTouches[0].clientX - sx; sx = null;
      if (Math.abs(dx) > 50) show(i + (dx < 0 ? 1 : -1), dx < 0 ? 1 : -1);
    }, { passive: true });
    show(0);
    dlg.showModal();
    dlg.querySelector('[data-next]').focus();
  };
})();
