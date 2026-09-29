/* Vetroom - avviso cookie (solo cookie tecnici: informativo, non blocca la pagina) */
(function () {
  var KEY = 'vetroom_cookie_ok';
  function show(force) {
    try { if (!force && localStorage.getItem(KEY)) return; } catch (e) {}
    if (document.getElementById('vr-cookie')) return;
    var en = (localStorage.getItem('vetroom_lang') || (navigator.language || 'it')).toLowerCase().indexOf('it') !== 0;
    var box = document.createElement('div');
    box.id = 'vr-cookie';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-live', 'polite');
    box.setAttribute('aria-label', en ? 'Cookie notice' : 'Informativa sui cookie');
    box.innerHTML =
      '<p><strong>' + (en ? 'Cookies' : 'Cookie') + '</strong> · ' +
      (en ? 'Vetroom only uses technical cookies needed to work (for example to keep you signed in). No profiling or third-party analytics cookies.'
          : 'Vetroom usa solo cookie tecnici, necessari al funzionamento (per esempio per mantenere l\u2019accesso). Nessun cookie di profilazione o di statistica di terze parti.') +
      '</p><div class="vr-cookie-actions"><a href="/privacy.html#cookie">' + (en ? 'Privacy & cookie policy' : 'Informativa privacy e cookie') +
      '</a><button type="button">' + (en ? 'Got it' : 'Ho capito') + '</button></div>';
    var st = document.createElement('style');
    st.textContent = '#vr-cookie{position:fixed;left:16px;right:16px;bottom:16px;z-index:9999;max-width:460px;background:#0f172a;color:#e5e7eb;border-radius:16px;padding:14px 16px;box-shadow:0 18px 40px rgba(15,23,42,.35);font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}' +
      '#vr-cookie p{margin:0 0 10px}#vr-cookie strong{color:#fff}' +
      '#vr-cookie .vr-cookie-actions{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}' +
      '#vr-cookie a{color:#99f6e4;text-decoration:underline}' +
      '#vr-cookie button{font:inherit;font-weight:700;background:#0f766e;color:#ecfeff;border:0;border-radius:12px;padding:10px 18px;min-height:42px;cursor:pointer}' +
      '#vr-cookie button:hover{background:#115e59}' +
      '@media(min-width:700px){#vr-cookie{right:auto}}';
    document.head.appendChild(st);
    document.body.appendChild(box);
    box.querySelector('button').addEventListener('click', function () {
      try { localStorage.setItem(KEY, new Date().toISOString()); } catch (e) {}
      box.remove();
    });
  }
  window.vetroomCookieNotice = function () { show(true); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { show(false); });
  else show(false);
})();
