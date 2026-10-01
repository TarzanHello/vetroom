// =============================================================
//  FURRFINDER — Notifiche sul telefono (Web Push)
//  Attivazione sul dispositivo, preferenze, prova, avviso in home.
// =============================================================
(function () {
  const VR = window.VR;
  const P = (VR.push = {});

  P.supported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  P.isIOS = () => /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  P.isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

  const keyBytes = (b64) => {
    const p = b64.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((b64.length + 3) % 4);
    return Uint8Array.from(atob(p), (c) => c.charCodeAt(0));
  };
  const registration = async () => {
    let reg = await navigator.serviceWorker.getRegistration('/app/');
    if (!reg) reg = await navigator.serviceWorker.register('/app/sw.js');
    return navigator.serviceWorker.ready.then(() => reg);
  };
  const current = async () => {
    if (!P.supported()) return null;
    const reg = await navigator.serviceWorker.getRegistration('/app/');
    return reg ? reg.pushManager.getSubscription() : null;
  };
  const save = async (sub) => {
    const j = sub.toJSON();
    const { error } = await VR.sb.rpc('push_subscribe', { p_endpoint: j.endpoint, p_p256dh: j.keys.p256dh, p_auth: j.keys.auth, p_ua: navigator.userAgent });
    if (error) throw error;
  };

  // Attiva su questo dispositivo
  P.enable = async () => {
    if (!P.supported()) throw new Error('Questo browser non supporta le notifiche.');
    const { data: key, error } = await VR.sb.rpc('push_public_key');
    if (error) throw error;
    if (!key) throw new Error('Le notifiche saranno disponibili a breve: riprova tra qualche minuto.');
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') throw new Error(perm === 'denied'
      ? 'Le notifiche sono bloccate nelle impostazioni del browser. Sbloccale dal lucchetto accanto all\'indirizzo (o dalle impostazioni del telefono) e riprova.'
      : 'Non hai dato il permesso alle notifiche.');
    const reg = await registration();
    let sub = await reg.pushManager.getSubscription();
    if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) });
    await save(sub);
    localStorage.setItem('ff_push_sync', String(Date.now()));
    return sub;
  };

  // Disattiva su questo dispositivo
  P.disable = async () => {
    const sub = await current();
    if (!sub) return;
    await VR.sb.rpc('push_unsubscribe', { p_endpoint: sub.endpoint });
    await sub.unsubscribe().catch(() => {});
  };

  // Una volta al giorno ricorda al server l'iscrizione (il telefono a volte la rinnova da solo)
  P.sync = async () => {
    try {
      if (!P.supported() || Notification.permission !== 'granted') return;
      const last = Number(localStorage.getItem('ff_push_sync') || 0);
      if (Date.now() - last < 864e5) return;
      const sub = await current();
      if (sub) { await save(sub); localStorage.setItem('ff_push_sync', String(Date.now())); }
    } catch { /* non importante */ }
  };

  // ---------- Pannello in Impostazioni ----------
  P.panel = async (el) => {
    const render = async (note, kind) => {
      const sub = await current().catch(() => null);
      const { data: st } = await VR.sb.rpc('push_status');
      const on = !!sub && Notification.permission === 'granted';
      const s = st || { reminders: true, appointments: true, reports: true, devices: 0 };
      let head;
      if (!P.supported()) {
        head = P.isIOS() && !P.isStandalone()
          ? `<div class="notice notice-info"><b>Su iPhone le notifiche funzionano dall'app sulla schermata Home.</b><br>
               1. In Safari tocca il pulsante <b>Condividi</b> (il quadrato con la freccia).<br>
               2. Scegli <b>Aggiungi alla schermata Home</b>.<br>
               3. Apri FurrFinder dall'icona e torna qui per attivarle.</div>`
          : '<p class="muted">Questo browser non supporta le notifiche. Prova con Chrome, Edge, Firefox o Safari aggiornati.</p>';
      } else if (on) {
        head = `<p class="push-state is-on">✓ Notifiche attive su questo dispositivo</p>
          <div class="actions-bar"><button class="btn btn-ghost btn-small" type="button" data-test>Invia una prova</button>
          <button class="btn btn-ghost btn-small" type="button" data-off>Disattiva su questo dispositivo</button></div>`;
      } else {
        head = `<p class="push-state">Le notifiche non sono attive su questo dispositivo.</p>
          <div class="actions-bar"><button class="btn btn-primary" type="button" data-on>Attiva le notifiche</button></div>`;
      }
      el.innerHTML = `
        ${head}
        <div id="pushMsg" ${note ? '' : 'hidden'}></div>
        <fieldset class="push-prefs" ${st && (on || s.devices) ? '' : 'hidden'}>
          <legend>Voglio ricevere un avviso per</legend>
          <label class="check"><input type="checkbox" data-pref="reminders" ${s.reminders ? 'checked' : ''}> Richiami in scadenza (una settimana prima e il giorno stesso)</label>
          <label class="check"><input type="checkbox" data-pref="appointments" ${s.appointments ? 'checked' : ''}> Appuntamenti: il giorno prima, conferme e spostamenti</label>
          <label class="check"><input type="checkbox" data-pref="reports" ${s.reports ? 'checked' : ''}> Nuovi referti nel libretto</label>
        </fieldset>
        ${s.devices > 1 ? `<p class="hint-small">Attive su ${s.devices} dispositivi.</p>` : ''}`;
      if (note) VR.say(el.querySelector('#pushMsg'), note, kind || 'info');
    };
    el.addEventListener('click', async (ev) => {
      const b = ev.target.closest('[data-on], [data-off], [data-test]'); if (!b) return;
      b.disabled = true;
      try {
        if (b.hasAttribute('data-on')) { await P.enable(); await render('Fatto! Premi "Invia una prova" per vedere come arrivano.', 'ok'); }
        else if (b.hasAttribute('data-off')) { await P.disable(); await render('Notifiche disattivate su questo dispositivo.', 'info'); }
        else {
          const { data, error } = await VR.sb.rpc('push_test');
          if (error) throw error;
          await render(data === 'ok' ? 'Prova inviata: arriva entro un minuto.' : data, data === 'ok' ? 'ok' : 'info');
        }
      } catch (e) { await render(VR.errorText ? VR.errorText(e) : String(e.message || e), 'error'); }
    });
    el.addEventListener('change', async (ev) => {
      if (!ev.target.closest('[data-pref]')) return;
      const v = (k) => el.querySelector(`[data-pref="${k}"]`).checked;
      const { error } = await VR.sb.rpc('push_set_prefs', { p_reminders: v('reminders'), p_appointments: v('appointments'), p_reports: v('reports') });
      const m = el.querySelector('#pushMsg');
      if (error) VR.say(m, VR.errorText(error), 'error'); else VR.say(m, 'Preferenze salvate.', 'ok');
    });
    await render();
  };

  // ---------- Invito in home (una volta sola, finché non si decide) ----------
  P.banner = async (el) => {
    P.sync();
    if (localStorage.getItem('ff_push_banner') === 'no') return;
    const iosNeedsHome = P.isIOS() && !P.isStandalone();
    if (!P.supported() && !iosNeedsHome) return;
    if (P.supported() && Notification.permission !== 'default') return;
    const { data: key } = await VR.sb.rpc('push_public_key');
    if (!key) return;
    el.innerHTML = `
      <div class="push-banner">
        <div><strong>🔔 Vuoi un avviso quando si avvicina un richiamo o un appuntamento?</strong>
          <span>${iosNeedsHome ? 'Su iPhone: aggiungi FurrFinder alla schermata Home (Condividi → Aggiungi alla schermata Home), poi attivale da Impostazioni.' : 'Ti scriviamo solo per le cose che riguardano i tuoi animali.'}</span></div>
        <div class="actions-bar">
          ${iosNeedsHome ? '' : '<button class="btn btn-primary btn-small" type="button" data-yes>Attiva</button>'}
          <button class="btn btn-ghost btn-small" type="button" data-no>${iosNeedsHome ? 'Ho capito' : 'Non ora'}</button>
        </div>
      </div>`;
    VR.show(el);
    el.addEventListener('click', async (ev) => {
      if (ev.target.closest('[data-no]')) { localStorage.setItem('ff_push_banner', 'no'); VR.hide(el); return; }
      const y = ev.target.closest('[data-yes]'); if (!y) return;
      y.disabled = true;
      try {
        await P.enable();
        localStorage.setItem('ff_push_banner', 'no');
        el.innerHTML = '<div class="notice notice-ok">Notifiche attive. Puoi scegliere cosa ricevere in Impostazioni.</div>';
      } catch (e) {
        el.innerHTML = `<div class="notice notice-error">${VR.esc(String(e.message || e))}</div>`;
      }
    });
  };
})();
