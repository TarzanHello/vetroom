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
      { k: 'agenda', l: 'Agenda', h: 'clinica/agenda.html', i: 'agenda' }
    ] },
    { key: 'anagrafiche', title: 'Anagrafiche', items: [
      { k: 'clienti', l: 'Proprietari', h: 'clinica/clienti.html', i: 'owner' },
      { k: 'animali', l: 'Animali', h: 'clinica/animali.html', i: 'pets' }
    ] },
    { key: 'quick', title: 'Azioni rapide', items: [
      { k: 'new-appt', l: '+ Appuntamento', h: 'clinica/agenda.html?nuovo=1', i: 'appointment' },
      { k: 'new-owner', l: '+ Proprietario', h: 'clinica/cliente.html?nuovo=1', i: 'create_owner' },
      { k: 'new-pet', l: '+ Animale', h: 'clinica/animale.html?nuovo=1', i: 'create_pet' }
    ] },
    { key: 'config', title: 'Configurazione', items: [
      { k: 'impostazioni', l: 'Opzioni', h: 'clinica/impostazioni.html', i: 'options' },
      { k: 'team', l: 'Team', h: 'clinica/team.html', i: 'team', adminOnly: true },
      { k: 'piattaforma', l: 'Piattaforma', h: 'piattaforma/', i: 'scan_code', platformOnly: true }
    ] }
  ];

  const OWNER_TABS = [
    { k: 'home', l: 'Home', h: 'proprietario/', i: 'owner_home' },
    { k: 'animali', l: 'Pet', h: 'proprietario/animali.html', i: 'owner_pets' },
    { k: 'cliniche', l: 'Cliniche', h: 'proprietario/cliniche.html', i: 'owner_vets' },
    { k: 'profilo', l: 'Impostazioni', h: 'proprietario/profilo.html', i: 'owner_options' }
  ];

  const GROUPS_KEY = 'vetroom_nav_closed';

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

    if (kind === 'clinic') {
      body.classList.add('vr-app');
      const closed = new Set(JSON.parse(localStorage.getItem(GROUPS_KEY) || '[]'));
      const header = document.createElement('header');
      header.className = 'vr-header';
      header.innerHTML = `
        <div class="vr-header-left">
          <a href="${VR.url('clinica/')}" class="vr-logo-link"><img class="vr-logo-img" src="${VR.url('img/logo-bianco.png')}" alt="Vetroom"></a>
          <div class="vr-logo-text">
            <div class="vr-logo-title" id="shellClinic">&nbsp;</div>
            <div class="vr-logo-sub" id="shellRole"></div>
          </div>
        </div>
        <div class="vr-header-right">
          <button class="vr-search-btn" type="button" data-open-search title="Cerca (tasto /)" aria-label="Cerca proprietari e animali">
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
      nav.innerHTML = CLINIC_NAV.map((g) => {
        const hasActive = g.items.some((it) => it.k === active);
        const isClosed = closed.has(g.key) && !hasActive;
        return `
        <div class="vr-nav-group ${isClosed ? 'collapsed' : ''}" data-group="${g.key}">
          <button type="button" class="vr-nav-group-title" aria-expanded="${!isClosed}">
            <span>${g.title}</span><span class="vr-nav-group-chevron" aria-hidden="true">▾</span>
          </button>
          <div class="vr-nav-group-items">
            ${g.items.map((it) => `
              <a href="${VR.url(it.h)}" class="vr-nav-item ${it.k === active ? 'active' : ''}" ${it.adminOnly ? 'data-admin-only hidden' : ''} ${it.platformOnly ? 'data-platform-only hidden' : ''} title="${it.l}" ${it.k === active ? 'aria-current="page"' : ''}>
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
        <div class="vr-tab-slot"><a class="vr-tab ${t.k === active ? 'is-active' : ''}" href="${VR.url(t.h)}" ${t.k === active ? 'aria-current="page"' : ''}>
          <img class="vr-tab-ic" src="${icon(t.i)}" alt="" aria-hidden="true"><span class="vr-tab-tx">${t.l}</span>
        </a></div>`).join('');
      main.classList.add('vr-owner-main');
      main.parentNode.insertBefore(header, main);
      document.body.appendChild(bar);
    }
  };

  const setLangLabels = () => document.querySelectorAll('[data-lang-toggle]').forEach((b) => { b.textContent = VR.lang === 'en' ? 'IT' : 'EN'; });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setLangLabels); else setLangLabels();

  // ---------- Ricerca unica (proprietari, animali, microchip) ----------
  let searchData = null;
  const openSearch = async () => {
    let dlg = document.getElementById('vr-search');
    if (!dlg) {
      dlg = document.createElement('dialog');
      dlg.id = 'vr-search'; dlg.className = 'dlg search-dlg';
      dlg.innerHTML = `<input type="search" id="vrQ" placeholder="Cerca per nome, cognome, telefono, email, animale o microchip" aria-label="Cerca" autocomplete="off">
        <div class="search-res" id="vrRes"><p class="muted">Caricamento…</p></div>
        <div class="actions-bar" style="margin-top:10px"><span class="hint-small" style="flex:1">Invio apre il primo risultato</span><button class="btn btn-ghost btn-small" type="button" data-close>Chiudi</button></div>`;
      document.body.appendChild(dlg);
      dlg.querySelector('[data-close]').addEventListener('click', () => dlg.close());
      dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
      const q = dlg.querySelector('#vrQ'), res = dlg.querySelector('#vrRes');
      const render = () => {
        if (!searchData) return;
        const terms = q.value.toLowerCase().split(/\s+/).filter(Boolean);
        if (!terms.length) { res.innerHTML = '<p class="muted">Scrivi per cercare…</p>'; return; }
        const hits = searchData.filter((x) => terms.every((t) => x.text.includes(t))).slice(0, 15);
        res.innerHTML = hits.map((x) => `<a href="${x.href}" class="search-hit"><img src="${VR.url('img/icons/' + (x.type === 'pet' ? 'pets' : 'owner') + '.png')}" alt=""><span><strong>${VR.esc(x.title)}</strong><small>${VR.esc(x.sub)}</small></span></a>`).join('')
          || '<p class="muted">Nessun risultato.</p>';
      };
      q.addEventListener('input', render);
      q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { const a = res.querySelector('a'); if (a) location.href = a.href; } });
      dlg._render = render;
    }
    dlg.showModal();
    const q = dlg.querySelector('#vrQ'); q.value = ''; q.focus();
    if (!searchData) {
      const cid = localStorage.getItem('vetroom_clinica');
      try {
        const rows = await VR.fetchAll(() => VR.sb.from('clients')
          .select('id, first_name, last_name, phone, email, pet_access(status, pets(id, name, species, microchip, deleted_at))')
          .eq('clinic_id', cid).order('id'));
        searchData = [];
        rows.forEach((c) => {
          const owner = VR.clientName ? VR.clientName(c) : [c.last_name, c.first_name].filter(Boolean).join(' ');
          const pets = (c.pet_access || []).filter((a) => a.status === 'active' && a.pets && !a.pets.deleted_at).map((a) => a.pets);
          searchData.push({ type: 'owner', title: owner, sub: [c.phone, c.email, pets.map((p) => p.name).join(', ')].filter(Boolean).join(' · '),
            href: VR.url('clinica/cliente.html?id=' + c.id), text: [owner, c.phone, (c.phone || '').replace(/\s/g, ''), c.email, ...pets.map((p) => p.name)].filter(Boolean).join(' ').toLowerCase() });
          pets.forEach((p) => searchData.push({ type: 'pet', title: p.name, sub: [p.species, owner, p.microchip].filter(Boolean).join(' · '),
            href: VR.url('clinica/animale.html?id=' + p.id), text: [p.name, p.species, p.microchip, owner].filter(Boolean).join(' ').toLowerCase() }));
        });
      } catch (e) { searchData = []; }
      dlg._render();
    }
  };
  document.addEventListener('click', (ev) => { if (ev.target.closest('[data-open-search]')) openSearch(); });
  document.addEventListener('keydown', (ev) => {
    if (ev.key === '/' && document.body.classList.contains('vr-app') && !ev.target.closest('input, textarea, select, [contenteditable]')) { ev.preventDefault(); openSearch(); }
  });

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
  VR.icon = (name, cls = 'vr-action-icon') => `<img class="${cls}" src="${icon(name)}" alt="" aria-hidden="true">`;
})();
