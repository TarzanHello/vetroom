// =============================================================
//  VETROOM 2 — funzioni comuni a tutte le pagine
// =============================================================
(function () {
  const cfg = window.VETROOM_CONFIG || {};
  const configured = !!cfg.SUPABASE_URL && !!cfg.SUPABASE_KEY &&
    !cfg.SUPABASE_URL.includes('INCOLLA') && !cfg.SUPABASE_KEY.includes('INCOLLA');

  const VR = (window.VR = {});
  VR.configured = configured;
  VR.INVITE_KEY = 'vetroom_invito';

  VR.sb = configured
    ? window.supabase.createClient(cfg.SUPABASE_URL, cfg.SUPABASE_KEY, {
        auth: { flowType: 'pkce', persistSession: true, detectSessionInUrl: true }
      })
    : null;

  // Cartella principale dell'app (ogni pagina indica dove si trova con data-root)
  VR.root = new URL(document.documentElement.dataset.root || './', location.href);
  VR.url = (path) => new URL(path, VR.root).href;
  VR.go = (path) => location.replace(VR.url(path));

  VR.$ = (sel) => document.querySelector(sel);
  VR.show = (el) => el && el.removeAttribute('hidden');
  VR.hide = (el) => el && el.setAttribute('hidden', '');

  VR.esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // Mostra un messaggio in un riquadro (kind: 'error' | 'ok' | 'info')
  VR.say = (el, text, kind = 'info') => {
    if (!el) return;
    el.className = 'notice notice-' + kind;
    el.textContent = text;
    VR.show(el);
  };

  // Traduce gli errori tecnici in frasi comprensibili
  VR.errorText = (e) => {
    const m = (e && (e.message || e.error_description || e.msg)) || String(e || '');
    if (/Failed to fetch|NetworkError/i.test(m)) return 'Connessione non riuscita. Controlla internet e riprova.';
    if (/JWT|not authenticated|Accesso richiesto/i.test(m)) return 'La sessione è scaduta. Accedi di nuovo.';
    if (/row-level security/i.test(m)) return 'Non hai i permessi per questa operazione.';
    return m || 'Qualcosa non ha funzionato. Riprova.';
  };

  // ---------- Monitoraggio errori ----------
  // Ogni errore mostrato all'utente (e ogni errore imprevisto del codice) viene registrato
  // per l'amministratore della piattaforma. Nessun servizio esterno: finisce nel database Vetroom.
  VR.VERSION = '2026-10-07g';
  const IGNORE = /Failed to fetch|NetworkError|Load failed|network error|JWT|not authenticated|Accesso richiesto|AbortError|ResizeObserver loop|Area riservata|earlier share has not yet completed|Share canceled|^(redirect|noclinic|suspended)$/i;
  const sent = new Set();
  VR.reportError = (e, where) => {
    try {
      if (!VR.sb) return;
      const m = String((e && (e.message || e.error_description || e.msg)) || e || '').trim().slice(0, 500);
      if (!m || m === '[object Object]' || IGNORE.test(m)) return;
      const key = location.pathname + '|' + m;
      if (sent.has(key) || sent.size >= 20) return;
      sent.add(key);
      const det = [e && e.code ? 'codice ' + e.code : '', e && e.details, e && e.hint, where,
        e && e.stack ? String(e.stack).slice(0, 1200) : ''].filter(Boolean).join('\n');
      VR.sb.rpc('log_client_error', {
        p_page: location.pathname, p_message: m, p_detail: det || null,
        p_user_agent: navigator.userAgent, p_app_version: VR.VERSION
      }).then(() => {}, () => {});
    } catch { /* il monitoraggio non deve mai disturbare l'app */ }
  };
  const errorTextBase = VR.errorText;
  VR.errorText = (e) => { VR.reportError(e); return errorTextBase(e); };
  window.addEventListener('error', (ev) => {
    // solo errori del nostro codice (non estensioni del browser o script esterni)
    if (!ev.filename || !ev.filename.startsWith(location.origin)) return;
    VR.reportError(ev.error || ev.message, ev.filename.replace(location.origin, '') + ':' + ev.lineno);
  });
  window.addEventListener('unhandledrejection', (ev) => VR.reportError(ev.reason, 'errore non gestito'));

  // Supabase restituisce al massimo 1000 righe per richiesta: questa funzione le prende tutte, a blocchi
  VR.fetchAll = async (build, pageSize = 1000) => {
    const out = [];
    for (let from = 0; ; from += pageSize) {
      const { data, error } = await build().range(from, from + pageSize - 1);
      if (error) throw error;
      out.push(...(data || []));
      if (!data || data.length < pageSize) break;
    }
    return out;
  };

  // ---------- Termini di servizio e accordo sul trattamento dei dati ----------
  VR.TERMS_VERSION = '2026-09';
  VR.requireTerms = ({ profile, clinicId = null, clinicTerms = null, isAdmin = false }) => new Promise((resolve) => {
    const needUser = !profile?.terms_accepted_at || profile.terms_version !== VR.TERMS_VERSION;
    const needClinic = !!(isAdmin && clinicId && !clinicTerms);
    if (!needUser && !needClinic) return resolve();
    const dlg = document.createElement('dialog');
    dlg.className = 'dlg terms-dlg';
    dlg.innerHTML = `
      <h2>Prima di continuare</h2>
      <p class="muted" style="margin-top:0">Per usare il servizio ci serve la tua conferma. Puoi leggere i documenti completi con i link qui sotto.</p>
      ${needUser ? `<label class="check terms-check"><input type="checkbox" data-t="user"> <span>Ho letto e accetto i <a href="/termini.html" target="_blank" rel="noopener">Termini di servizio</a> e l'<a href="/privacy.html" target="_blank" rel="noopener">Informativa privacy</a></span></label>` : ''}
      ${needClinic ? `<label class="check terms-check"><input type="checkbox" data-t="clinic"> <span>Per la clinica: accetto l'<a href="/termini.html#dpa" target="_blank" rel="noopener">accordo sul trattamento dei dati</a> e nomino AF&amp;B S.r.l.s. responsabile del trattamento (art. 28 GDPR)</span></label>` : ''}
      <div class="terms-msg" hidden></div>
      <div class="actions-bar" style="margin-top:14px">
        <button class="btn btn-primary" type="button" data-go disabled>Continua</button>
        <button class="btn btn-ghost" type="button" data-action="logout">Esci</button>
      </div>`;
    document.body.appendChild(dlg);
    const go = dlg.querySelector('[data-go]');
    const boxes = [...dlg.querySelectorAll('input[type=checkbox]')];
    dlg.addEventListener('change', () => { go.disabled = !boxes.every((b) => b.checked); });
    dlg.addEventListener('cancel', (e) => e.preventDefault());
    go.addEventListener('click', async () => {
      go.disabled = true;
      try {
        if (needUser) { const { error } = await VR.sb.rpc('accept_terms', { p_version: VR.TERMS_VERSION }); if (error) throw error; }
        if (needClinic) { const { error } = await VR.sb.rpc('accept_clinic_terms', { p_clinic: clinicId, p_version: VR.TERMS_VERSION }); if (error) throw error; }
        dlg.close(); dlg.remove(); resolve();
      } catch (e) {
        VR.say(dlg.querySelector('.terms-msg'), VR.errorText(e), 'error'); go.disabled = false;
      }
    });
    dlg.showModal();
  });

  VR.getSession = async () => {
    const { data } = await VR.sb.auth.getSession();
    return data.session;
  };

  // Se non c'è una sessione, torna alla pagina di accesso
  VR.requireSession = async () => {
    const s = await VR.getSession();
    if (!s) { VR.go('index.html'); throw new Error('redirect'); }
    return s;
  };

  VR.loadProfile = async (uid) => {
    const { data, error } = await VR.sb.from('profiles').select('*').eq('id', uid).single();
    if (error) throw error;
    return data;
  };

  // Dove deve andare un utente in base al suo profilo
  VR.homeFor = (p) => (p.is_staff ? 'clinica/' : p.is_owner ? 'proprietario/' : 'benvenuto.html');

  VR.firstName = (p, session) =>
    p?.first_name || (p?.full_name || '').split(' ')[0] ||
    session?.user?.user_metadata?.given_name || '';

  VR.signIn = async () => {
    const { error } = await VR.sb.auth.signInWithOAuth({
      provider: 'google',
      options: { redirectTo: VR.url('benvenuto.html'), queryParams: { prompt: 'select_account' } }
    });
    if (error) throw error;
  };

  VR.signOut = async () => {
    try {
      // Su un computer condiviso non devono restare dati clinici: via le bozze delle visite salvate sul dispositivo
      Object.keys(localStorage).filter((k) => k.startsWith('vetroom_bozza_')).forEach((k) => localStorage.removeItem(k));
      sessionStorage.clear();
      await VR.sb.auth.signOut();
    } finally { VR.go('index.html'); }
  };

  // App installabile (service worker)
  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    window.addEventListener('load', () => navigator.serviceWorker.register('/app/sw.js').catch(() => {}));
  }

  // Pulsante "Esci" presente in più pagine
  document.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-action="logout"]');
    if (b) { ev.preventDefault(); VR.signOut(); }
  });
})();
