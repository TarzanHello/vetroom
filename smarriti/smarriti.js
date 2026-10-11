// =============================================================
//  FURRFINDER · pagina pubblica di un avviso (patch 38)
//  Mappa della zona, "L'ho visto" / "L'ho trovato", condivisione, contatori anonimi.
//  Nessun cookie: i contatori sono solo totali per giorno.
// =============================================================
(function () {
  var S = window.SM; if (!S) return;
  var $ = function (s) { return document.querySelector(s); };
  var headers = function () { var h = { apikey: S.key, 'Content-Type': 'application/json' }; if (S.key.indexOf('eyJ') === 0) h.Authorization = 'Bearer ' + S.key; return h; };
  var rpc = function (name, args) {
    return fetch(S.api + '/rest/v1/rpc/' + name, { method: 'POST', headers: headers(), body: JSON.stringify(args), keepalive: true })
      .then(function (r) { return r.text().then(function (t) { var j = null; try { j = t ? JSON.parse(t) : null; } catch (e) { j = null; } if (!r.ok) throw new Error((j && j.message) || 'Invio non riuscito, riprova.'); return j; }); });
  };
  var bot = navigator.webdriver || /bot|crawl|spider|slurp|preview|lighthouse|facebookexternalhit|whatsapp|telegram/i.test(navigator.userAgent);
  var conta = function (ev) { if (!bot) rpc('smarrito_evento', { p_codice: S.codice, p_evento: ev }).catch(function () {}); };

  // ---------- visita (con il canale da cui arriva: ?da=whatsapp, qr, facebook…) ----------
  var da = (new URLSearchParams(location.search).get('da') || '').toLowerCase().replace(/[^a-z0-9_-]/g, '').slice(0, 20);
  conta('vista:' + (da || (document.referrer && document.referrer.indexOf(location.host) < 0 ? 'web' : 'diretto')));
  if (da) history.replaceState(null, '', location.pathname);
  document.addEventListener('click', function (e) { var a = e.target.closest && e.target.closest('[data-ev]'); if (a) conta(a.getAttribute('data-ev')); });

  // ---------- condivisione ----------
  var link = function (c) { return S.url + '?da=' + c; };
  var wa = document.querySelector('[data-share="whatsapp"]'), fb = document.querySelector('[data-share="facebook"]');
  if (wa) wa.href = 'https://wa.me/?text=' + encodeURIComponent(S.testo + ' ' + link('whatsapp'));
  if (fb) fb.href = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(link('facebook'));
  var altro = document.querySelector('[data-share="altro"]'); if (altro && navigator.share) altro.hidden = false;
  var share = $('#share');
  if (share) share.addEventListener('click', function (e) {
    var b = e.target.closest('[data-share]'); if (!b) return;
    var k = b.getAttribute('data-share'); conta('condividi:' + k);
    if (k === 'copia') {
      e.preventDefault();
      var done = function () { var m = $('#shareMsg'); m.textContent = 'Link copiato: incollalo dove vuoi.'; m.hidden = false; };
      if (navigator.clipboard) navigator.clipboard.writeText(link('link')).then(done, function () { prompt('Copia questo link:', link('link')); });
      else prompt('Copia questo link:', link('link'));
    }
    if (k === 'altro') { e.preventDefault(); navigator.share({ title: document.title, text: S.testo, url: link('altro') }).catch(function () {}); }
  });

  // ---------- segnala un problema ----------
  var seg = $('#segnala');
  if (seg) seg.addEventListener('click', function () {
    var m = prompt('Qual è il problema con questo avviso? (es. è falso, è una truffa, contiene dati non corretti)');
    if (!m || !m.trim()) return;
    rpc('smarrito_segnala', { p_codice: S.codice, p_motivo: m.trim() }).then(function () { alert('Grazie, lo controlliamo al più presto.'); }, function () { alert('Invio non riuscito: scrivi a info@vetroom.it'); });
  });

  if (S.stato !== 'aperto' || !window.L) return;

  // ---------- mappe ----------
  var tiles = function (m) {
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>' }).addTo(m);
  };
  var pin = function (color) {
    return L.divIcon({ className: 'sm-pin', iconSize: [28, 38], iconAnchor: [14, 37],
      html: '<svg viewBox="0 0 28 38" width="28" height="38" aria-hidden="true"><path d="M14 1C7 1 1.5 6.4 1.5 13.3 1.5 22.6 14 37 14 37s12.5-14.4 12.5-23.7C26.5 6.4 21 1 14 1z" fill="' + color + '" stroke="#fff" stroke-width="2"/><circle cx="14" cy="13.5" r="4.6" fill="#fff"/></svg>' });
  };
  if ($('#smMap') && S.lat != null) {
    var zm = L.map('smMap', { scrollWheelZoom: false }).setView([S.lat, S.lon], 15);
    tiles(zm);
    L.circle([S.lat, S.lon], { radius: S.raggio, color: '#dc2626', weight: 2, fillOpacity: 0.12 }).addTo(zm);
  }

  // ---------- modulo "L'ho visto" / "L'ho trovato" ----------
  var box = $('#avvisa'), form = $('#fAvv'), fmap = null, mk = null, pos = null, acc = null;
  var aperto = Date.now();
  var setPos = function (lat, lon, precisione) {
    pos = [lat, lon]; acc = precisione || null;
    if (!mk) mk = L.marker(pos, { draggable: true, icon: pin('#f59e0b') }).addTo(fmap).on('dragend', function () { var p = mk.getLatLng(); pos = [p.lat, p.lng]; acc = null; });
    else mk.setLatLng(pos);
    $('#fPos').textContent = 'Punto segnato ✓';
  };
  var apri = function (tipo) {
    box.hidden = false;
    form.querySelector('[name=tipo][value="' + tipo + '"]').checked = true;
    titolo();
    var d = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
    if (!$('#fQuando').value) $('#fQuando').value = d.toISOString().slice(0, 16);
    if (!fmap) {
      fmap = L.map('fMap', { scrollWheelZoom: false }).setView([S.lat, S.lon], 15);
      tiles(fmap);
      L.circle([S.lat, S.lon], { radius: S.raggio, color: '#dc2626', weight: 1, fillOpacity: 0.06 }).addTo(fmap);
      fmap.on('click', function (e) { setPos(e.latlng.lat, e.latlng.lng); });
    }
    setTimeout(function () { fmap.invalidateSize(); }, 80);
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    conta('apri:' + tipo);
  };
  var titolo = function () {
    var t = form.querySelector('[name=tipo]:checked').value;
    $('#fTitle').textContent = t === 'trovato' ? 'Grazie! Dove l\'hai trovato?' : 'Dove l\'hai visto?';
    $('#fMsg').placeholder = t === 'trovato' ? 'Es. È con me, sta bene. Sono in via… Possiamo sentirci al telefono?' : 'Es. Era vicino al giardino di via…, andava verso il parco. Sembrava spaventato.';
  };
  document.querySelectorAll('[data-avv]').forEach(function (b) { b.addEventListener('click', function () { apri(b.getAttribute('data-avv')); }); });
  form.addEventListener('change', function (e) { if (e.target.name === 'tipo') titolo(); });
  $('#fAnnulla').addEventListener('click', function () { box.hidden = true; });
  $('#fGeo').addEventListener('click', function () {
    if (!navigator.geolocation) { $('#fPos').textContent = 'Posizione non disponibile: tocca la mappa.'; return; }
    $('#fPos').textContent = 'Cerco la posizione…';
    navigator.geolocation.getCurrentPosition(function (p) { setPos(p.coords.latitude, p.coords.longitude, Math.round(p.coords.accuracy)); fmap.setView(pos, 17); },
      function () { $('#fPos').textContent = 'Posizione non disponibile: tocca la mappa.'; }, { enableHighAccuracy: true, timeout: 12000 });
  });

  // foto ridotta prima dell'invio
  var riduci = function (file) {
    return new Promise(function (ok, ko) {
      var img = new Image(), u = URL.createObjectURL(file);
      img.onload = function () {
        var s = Math.min(1, 1200 / Math.max(img.width, img.height)), c = document.createElement('canvas');
        c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(u);
        c.toBlob(function (b) { b ? ok(b) : ko(new Error('Foto non leggibile')); }, 'image/jpeg', 0.82);
      };
      img.onerror = function () { URL.revokeObjectURL(u); ko(new Error('Il file scelto non è una foto')); };
      img.src = u;
    });
  };
  var caricaFoto = function () {
    var f = $('#fFoto').files[0];
    if (!f || !S.id) return Promise.resolve(null);
    return riduci(f).then(function (blob) {
      var nome = S.id + '/' + Date.now() + '-' + Math.random().toString(36).slice(2, 8) + '.jpg';
      var h = { apikey: S.key, 'Content-Type': 'image/jpeg' }; if (S.key.indexOf('eyJ') === 0) h.Authorization = 'Bearer ' + S.key;
      return fetch(S.api + '/storage/v1/object/smarriti-avvistamenti/' + nome, { method: 'POST', headers: h, body: blob })
        .then(function (r) { return r.ok ? nome : null; }, function () { return null; });
    }, function () { return null; });
  };

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var esito = $('#fEsito'), btn = $('#fInvia');
    var say = function (t, ok) { esito.textContent = t; esito.className = 'sm-note ' + (ok ? 'ok' : 'err'); esito.hidden = false; };
    var tipo = form.querySelector('[name=tipo]:checked').value, msg = $('#fMsg').value.trim();
    if (!pos && !msg) { say('Tocca la mappa dove l\'hai visto oppure scrivi un messaggio.'); return; }
    if (Date.now() - aperto < 2500) { say('Un attimo… ricontrolla i dati e premi di nuovo.'); aperto = 0; return; }
    btn.disabled = true; btn.textContent = 'Invio…';
    var q = $('#fQuando').value ? new Date($('#fQuando').value) : new Date();
    caricaFoto().then(function (foto) {
      return rpc('smarrito_avvista', { p_codice: S.codice, p_tipo: tipo, p_lat: pos ? pos[0] : null, p_lon: pos ? pos[1] : null, p_precisione_m: acc,
        p_visto_il: isNaN(q) ? null : q.toISOString(), p_messaggio: msg || null, p_nome: $('#fNome').value.trim() || null,
        p_contatto: $('#fCont').value.trim() || null, p_foto_path: foto, p_controllo: $('#fSito').value || null });
    }).then(function () {
      form.querySelectorAll('input, textarea, button').forEach(function (x) { if (x.id !== 'fAnnulla') x.disabled = true; });
      btn.textContent = 'Inviato ✓';
      say(tipo === 'trovato'
        ? 'Grazie di cuore! Il proprietario riceve subito il tuo messaggio. Se puoi, tienilo al sicuro e fallo controllare da un veterinario: con il microchip si conferma che è lui.'
        : 'Grazie! Il proprietario riceve subito la tua segnalazione. Se lo rivedi, puoi segnalarlo di nuovo.', true);
    }).catch(function (err) { btn.disabled = false; btn.textContent = 'Invia al proprietario'; say(err.message || 'Invio non riuscito, riprova.'); });
  });
})();
