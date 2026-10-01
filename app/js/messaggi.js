// =============================================================
//  MESSAGGI — elenco conversazioni + conversazione aperta
//  Usato uguale dal portale proprietari e dall'app delle cliniche.
// =============================================================
(function () {
  const VR = window.VR;
  const M = (VR.msg = {});

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
  // Il testo resta testo: niente HTML. Gli indirizzi web diventano link sicuri.
  const linkify = (t) => VR.esc(t).replace(/(https?:\/\/[^\s<]+)/g, (u) => `<a href="${u}" target="_blank" rel="noopener nofollow">${u}</a>`);

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

  // opts: { listEl, threadEl, side: 'owner'|'clinic', emptyHtml, onOpen }
  M.mount = async (opts) => {
    const list = VR.$(opts.listEl), pane = VR.$(opts.threadEl);
    let convs = [], current = null, polling = null;

    const renderList = () => {
      if (!convs.length) { list.innerHTML = opts.emptyHtml || '<p class="muted">Nessun messaggio.</p>'; return; }
      list.innerHTML = convs.map((c) => `
        <button type="button" class="conv ${c.id === current ? 'is-open' : ''} ${c.unread ? 'is-unread' : ''} ${c.kind && c.kind.startsWith('admin') ? 'msg-support' : ''}" data-conv="${c.id}">
          <span class="conv-top"><strong>${VR.esc(c.title)}</strong><span class="conv-when">${c.last_message_at ? when(c.last_message_at) : ''}</span></span>
          <span class="conv-sub">${VR.esc(c.subtitle || '')}</span>
          <span class="conv-prev">${c.last_preview ? (c.last_sender_role !== (opts.side === 'owner' ? 'owner' : 'clinic') ? '' : 'Tu: ') + VR.esc(c.last_preview) : 'Nessun messaggio'}</span>
          ${c.unread ? `<span class="conv-badge">${c.unread}</span>` : ''}
        </button>`).join('');
    };

    const renderThread = async (id, keepScroll) => {
      const c = convs.find((x) => x.id === id);
      if (!c) return;
      const { data, error } = await VR.sb.rpc('msg_thread', { p_conv: id, p_limit: 200 });
      if (error) { pane.innerHTML = `<div class="notice notice-error">${VR.esc(VR.errorText(error))}</div>`; return; }
      const msgs = (data || []).slice().reverse();
      let lastDay = '';
      const bubbles = msgs.map((m) => {
        const d = dayLabel(m.created_at);
        const sep = d !== lastDay ? `<div class="msg-day">${VR.esc(d)}</div>` : '';
        lastDay = d;
        return sep + `
          <div class="bubble ${m.mine ? 'mine' : 'theirs'} role-${m.sender_role}">
            ${!m.mine ? `<span class="bubble-who">${VR.esc(m.sender_name || '')}</span>` : ''}
            <p>${linkify(m.body)}</p>
            <span class="bubble-when">${when(m.created_at)}</span>
          </div>`;
      }).join('');
      const box = pane.querySelector('.msg-scroll');
      const atBottom = !box || box.scrollHeight - box.scrollTop - box.clientHeight < 60;
      pane.innerHTML = `
        <div class="msg-head">
          <button type="button" class="btn btn-ghost btn-small msg-back" data-back>← Messaggi</button>
          <div><strong>${VR.esc(c.title)}</strong><span class="muted">${VR.esc(c.subtitle || '')}</span></div>
        </div>
        <div class="msg-scroll">${bubbles || '<p class="muted" style="text-align:center">Scrivi il primo messaggio.</p>'}</div>
        ${c.active ? `
        <form class="msg-form" id="msgForm">
          <textarea id="msgText" rows="2" placeholder="Scrivi un messaggio…" maxlength="4000"></textarea>
          <button class="btn btn-primary" type="submit">Invia</button>
        </form>
        <p class="hint-small msg-note">${opts.noteHtml || ''}</p>`
        : '<p class="notice notice-info" style="margin:12px">Questa conversazione è chiusa: il collegamento fra proprietario e clinica non è più attivo. I messaggi restano leggibili.</p>'}`;
      const sc = pane.querySelector('.msg-scroll');
      if (sc && (atBottom || !keepScroll)) sc.scrollTop = sc.scrollHeight;
      const form = pane.querySelector('#msgForm');
      if (form) {
        const ta = pane.querySelector('#msgText');
        ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); form.requestSubmit(); } });
        ta.addEventListener('input', () => { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 160) + 'px'; });
        form.addEventListener('submit', async (e) => {
          e.preventDefault();
          const text = ta.value.trim();
          if (!text) return;
          const btn = form.querySelector('button'); btn.disabled = true;
          const { error: err } = await VR.sb.rpc('msg_send', { p_conv: id, p_body: text });
          btn.disabled = false;
          if (err) { alert(VR.errorText(err)); return; }
          ta.value = ''; ta.style.height = 'auto';
          await refresh(true);
          ta.focus();
        });
        if (!matchMedia('(hover: none)').matches) ta.focus();
      }
      document.body.classList.add('msg-open');
    };

    const refresh = async (keepOpen) => {
      const { data, error } = await VR.sb.rpc('msg_list');
      if (error) { list.innerHTML = `<div class="notice notice-error">${VR.esc(VR.errorText(error))}</div>`; return; }
      convs = data || [];
      renderList();
      M.unreadBadge();
      if (current && convs.some((c) => c.id === current)) await renderThread(current, keepOpen);
    };

    list.addEventListener('click', async (ev) => {
      const b = ev.target.closest('[data-conv]'); if (!b) return;
      current = b.dataset.conv;
      await renderThread(current);
      renderList();
      if (opts.onOpen) opts.onOpen(current);
    });
    pane.addEventListener('click', (ev) => {
      if (!ev.target.closest('[data-back]')) return;
      current = null; document.body.classList.remove('msg-open'); pane.innerHTML = ''; renderList();
    });

    M.openConversation = async (id) => { current = id; await refresh(); await renderThread(id); renderList(); };
    await refresh();
    // I messaggi nuovi arrivano da soli: Supabase avvisa il browser appena la riga è scritta.
    M.live(() => refresh(true));
    // rete di sicurezza, se la connessione in tempo reale cade
    polling = setInterval(() => { if (document.visibilityState === 'visible') refresh(true); }, 25000);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') refresh(true); });
    window.addEventListener('beforeunload', () => clearInterval(polling));
    return { refresh };
  };
})();
