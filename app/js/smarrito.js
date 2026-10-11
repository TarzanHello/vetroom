// =============================================================
//  FURRFINDER · SMARRITO — funzioni comuni (patch 38)
//  Indirizzi pubblici, condivisione, mappe e locandina (SMARRITO / RITROVATO).
//  Usa: vendor/leaflet, vendor/qrcode-generator.js, vendor/jspdf (solo per l'A4).
// =============================================================
(function () {
  const VR = window.VR;
  const L$ = (VR.lost = {});
  const cfg = window.VETROOM_CONFIG || {};

  // Le pagine pubbliche stanno sullo stesso sito dell'app (vetroom.it/smarriti/…)
  L$.SITO = location.origin;
  L$.pageUrl = (sm, canale) => L$.SITO + sm.url + (canale ? '?da=' + encodeURIComponent(canale) : '');
  L$.shortUrl = (sm) => (L$.SITO.replace(/^https?:\/\/(www\.)?/, '') + sm.url);
  L$.photoUrl = (path) => (path ? `${cfg.SUPABASE_URL}/storage/v1/object/public/smarriti/${path.split('/').map(encodeURIComponent).join('/')}` : null);

  L$.STATO = { aperto: 'Smarrito', ritrovato: 'Ritrovato', chiuso: 'Chiuso', scaduto: 'Scaduto', sospeso: 'Sospeso' };
  L$.statoPill = (s) => `<span class="pill lost-pill lost-${s}">${L$.STATO[s] || s}</span>`;
  L$.femmina = (sm) => sm.sesso === 'F';
  L$.perso = (sm) => (L$.femmina(sm) ? 'smarrita' : 'smarrito');
  L$.tornato = (sm) => (L$.femmina(sm) ? 'tornata' : 'tornato');
  L$.quando = (d) => new Date(d).toLocaleString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
  L$.giorno = (d) => new Date(d).toLocaleDateString('it-IT', { day: 'numeric', month: 'long' });
  L$.ora = (d) => new Date(d).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });

  L$.evento = (codice, evento) => { try { VR.sb.rpc('smarrito_evento', { p_codice: codice, p_evento: evento }).then(() => {}, () => {}); } catch { /* non importante */ } };

  // ---------- Testi per la condivisione ----------
  L$.shareText = (sm) => {
    if (sm.stato === 'ritrovato') return `${sm.nome} è ${L$.tornato(sm)} a casa! Grazie a tutti quelli che hanno condiviso l'avviso.`;
    const chi = [sm.specie, sm.razza].filter(Boolean).join(', ');
    return `AIUTO! ${sm.nome}${chi ? ' (' + chi.toLowerCase() + ')' : ''} si è ${L$.perso(sm)} a ${sm.luogo ? sm.luogo + ', ' : ''}${sm.citta} il ${L$.giorno(sm.perso_il)}. Se l'hai visto, segnalalo qui:`;
  };
  L$.shareLinks = (sm) => {
    const t = L$.shareText(sm);
    return {
      whatsapp: `https://wa.me/?text=${encodeURIComponent(t + ' ' + L$.pageUrl(sm, 'whatsapp'))}`,
      facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(L$.pageUrl(sm, 'facebook'))}`,
      telegram: `https://t.me/share/url?url=${encodeURIComponent(L$.pageUrl(sm, 'telegram'))}&text=${encodeURIComponent(t)}`
    };
  };

  // Pulsanti di condivisione (data-share="whatsapp|facebook|telegram|copia|altro")
  L$.shareButtons = (sm) => {
    const l = L$.shareLinks(sm);
    return `<div class="lost-share">
      <a class="btn lost-btn-wa" href="${l.whatsapp}" target="_blank" rel="noopener" data-share="whatsapp">WhatsApp</a>
      <a class="btn lost-btn-fb" href="${l.facebook}" target="_blank" rel="noopener" data-share="facebook">Facebook</a>
      <a class="btn lost-btn-tg" href="${l.telegram}" target="_blank" rel="noopener" data-share="telegram">Telegram</a>
      <button class="btn btn-ghost" type="button" data-share="copia">Copia link</button>
      ${navigator.share ? '<button class="btn btn-ghost" type="button" data-share="altro">Altro…</button>' : ''}
    </div>`;
  };
  L$.bindShare = (el, getSm, onMsg) => {
    el.addEventListener('click', async (ev) => {
      const b = ev.target.closest('[data-share]'); if (!b) return;
      const sm = getSm(); const k = b.dataset.share;
      L$.evento(sm.codice, 'condividi:' + k);
      if (k === 'copia') {
        ev.preventDefault();
        try { await navigator.clipboard.writeText(L$.pageUrl(sm, 'link')); onMsg && onMsg('Link copiato: incollalo dove vuoi.'); }
        catch { prompt('Copia questo link:', L$.pageUrl(sm, 'link')); }
      }
      if (k === 'altro') {
        ev.preventDefault();
        try { await navigator.share({ title: (sm.stato === 'ritrovato' ? 'Ritrovato: ' : 'Smarrito: ') + sm.nome, text: L$.shareText(sm), url: L$.pageUrl(sm, 'altro') }); } catch { /* annullato */ }
      }
    });
  };

  // ---------- Mappe (Leaflet + OpenStreetMap) ----------
  L$.map = (el, { lat = 41.9, lon = 12.5, zoom = 6 } = {}) => {
    const m = window.L.map(el, { scrollWheelZoom: false, tap: true }).setView([lat, lon], zoom);
    window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'
    }).addTo(m);
    return m;
  };
  L$.pin = (color = '#dc2626') => window.L.divIcon({
    className: 'lost-pin', iconSize: [28, 38], iconAnchor: [14, 37], popupAnchor: [0, -32],
    html: `<svg viewBox="0 0 28 38" width="28" height="38" aria-hidden="true"><path d="M14 1C7 1 1.5 6.4 1.5 13.3 1.5 22.6 14 37 14 37s12.5-14.4 12.5-23.7C26.5 6.4 21 1 14 1z" fill="${color}" stroke="#fff" stroke-width="2"/><circle cx="14" cy="13.5" r="4.6" fill="#fff"/></svg>`
  });

  // Indirizzo da coordinate e viceversa (Nominatim di OpenStreetMap, uso leggero)
  L$.reverse = async (lat, lon) => {
    const r = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=17&addressdetails=1&accept-language=it&lat=${lat}&lon=${lon}`);
    if (!r.ok) throw new Error('indirizzo non trovato');
    const j = await r.json(), a = j.address || {};
    const citta = a.city || a.town || a.village || a.municipality || a.hamlet || '';
    const zona = [a.suburb || a.quarter || a.neighbourhood || a.city_district || '', a.road || a.pedestrian || a.square || ''].filter(Boolean);
    const iso = a['ISO3166-2-lvl6'] || '';
    return { citta, provincia: /^IT-[A-Z]{2}$/.test(iso) ? iso.slice(3) : '', luogo: zona.length > 1 ? `${zona[0]}, vicino a ${zona[1]}` : (zona[0] || '') };
  };
  L$.search = async (q) => {
    const r = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&countrycodes=it&accept-language=it&q=${encodeURIComponent(q)}`);
    if (!r.ok) return [];
    return (await r.json()).map((x) => ({ lat: Number(x.lat), lon: Number(x.lon), nome: x.display_name }));
  };

  // ---------- Locandina ----------
  // formato: 'post' (1080×1350), 'storia' (1080×1920), 'a4' (1240×1754, per la stampa)
  const SIZES = { post: [1080, 1350], storia: [1080, 1920], a4: [1240, 1754] };
  const loadImg = (src) => new Promise((res) => {
    if (!src) return res(null);
    const i = new Image(); i.crossOrigin = 'anonymous';
    i.onload = () => res(i); i.onerror = () => res(null); i.src = src;
  });
  const wrap = (ctx, text, maxW, maxLines) => {
    const words = String(text || '').replace(/\s+/g, ' ').trim().split(' ');
    const lines = []; let cur = '';
    for (const w of words) {
      const t = cur ? cur + ' ' + w : w;
      if (ctx.measureText(t).width <= maxW || !cur) cur = t; else { lines.push(cur); cur = w; }
      if (lines.length === maxLines) break;
    }
    if (cur && lines.length < maxLines) lines.push(cur);
    if (lines.length === maxLines && words.join(' ').length > lines.join(' ').length) {
      let last = lines[maxLines - 1];
      while (ctx.measureText(last + '…').width > maxW && last.length) last = last.slice(0, -1);
      lines[maxLines - 1] = last.replace(/[\s,.;:]+$/, '') + '…';
    }
    return lines;
  };
  const fit = (ctx, text, weight, maxSize, maxW, family, min = 20) => {
    let s = maxSize + 2;
    do { s -= 2; ctx.font = `${weight} ${s}px ${family}`; } while (ctx.measureText(text).width > maxW && s > min);
    return s;
  };
  const rrect = (ctx, x, y, w, h, r) => { ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath(); };
  const drawQr = (ctx, text, x, y, size) => {
    const qr = window.qrcode(0, 'M'); qr.addData(text); qr.make();
    const n = qr.getModuleCount(), q = 2, cell = size / (n + q * 2);
    ctx.fillStyle = '#fff'; ctx.fillRect(x, y, size, size);
    ctx.fillStyle = '#0b2545';
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (qr.isDark(r, c)) ctx.fillRect(Math.floor(x + (c + q) * cell), Math.floor(y + (r + q) * cell), Math.ceil(cell), Math.ceil(cell));
  };

  L$.poster = async (sm, formato = 'post') => {
    const [W, H] = SIZES[formato] || SIZES.post;
    const c = document.createElement('canvas'); c.width = W; c.height = H;
    const ctx = c.getContext('2d');
    const F = 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif';
    const found = sm.stato === 'ritrovato';
    const accent = found ? '#15803d' : '#dc2626';
    const pad = Math.round(W * 0.06), inner = W - pad * 2;
    const tall = formato === 'storia', a4 = formato === 'a4';
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);

    // fascia in alto
    const bandH = Math.round(H * (tall ? 0.13 : 0.15));
    ctx.fillStyle = accent; ctx.fillRect(0, 0, W, bandH);
    ctx.fillStyle = '#fff'; ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
    const title = found ? (L$.femmina(sm) ? 'RITROVATA!' : 'RITROVATO!') : (L$.femmina(sm) ? 'SMARRITA' : 'SMARRITO');
    fit(ctx, title, 900, Math.round(bandH * 0.62), inner, F);
    ctx.fillText(title, W / 2, Math.round(bandH * 0.66));
    ctx.font = `600 ${Math.round(bandH * 0.17)}px ${F}`;
    ctx.fillText([sm.specie, sm.citta].filter(Boolean).join(' · ').toUpperCase(), W / 2, Math.round(bandH * 0.9));

    // testo sotto la foto: prima lo misuro, poi la foto prende lo spazio che resta
    const footH = Math.round(H * (tall ? 0.17 : 0.2));
    const fy = H - footH - Math.round(pad * 0.5);
    const base = Math.round(W * (a4 ? 0.03 : 0.034));
    const nameMax = Math.round(H * (tall ? 0.065 : a4 ? 0.06 : 0.07));
    const righe = (descrLines) => {
      const out = [];
      if (found) {
        out.push([`è ${L$.tornato(sm)} a casa`, Math.round(base * 1.25), 700, '#15803d', 1]);
        out.push(['Grazie a tutti quelli che hanno condiviso l\'avviso e lo hanno cercato con noi.', base, 500, '#334155', 3]);
      } else {
        const razza = [sm.razza, sm.sesso === 'F' ? 'femmina' : sm.sesso === 'M' ? 'maschio' : ''].filter(Boolean).join(' · ');
        if (razza) out.push([razza, base, 600, '#475569', 1]);
        out.push([`${L$.femmina(sm) ? 'Vista' : 'Visto'} l'ultima volta: ${sm.luogo ? sm.luogo + ', ' : ''}${sm.citta}`, base, 700, '#0f172a', 2]);
        out.push([`${L$.giorno(sm.perso_il)} alle ${L$.ora(sm.perso_il)}`, base, 500, '#334155', 1]);
        if (sm.descrizione && descrLines) out.push([sm.descrizione, Math.round(base * 0.92), 500, '#334155', descrLines]);
      }
      return out.map(([t, size, weight, color, max]) => { ctx.font = `${weight} ${size}px ${F}`; return { lines: wrap(ctx, t, inner, max), size, weight, color }; });
    };
    const altezza = (rs) => rs.reduce((h, r) => h + r.lines.length * Math.round(r.size * 1.25), 0) + Math.round(base * 0.35) * (rs.length - 1);
    const top = bandH + Math.round(pad * 0.7), gap = Math.round(pad * 0.6), nameH = Math.round(nameMax * 1.05);
    let blocco = righe(tall || a4 ? 4 : 3);
    const spazio = () => fy - gap - top - gap - nameH - altezza(blocco) - gap;
    if (spazio() < H * 0.28 && !found) blocco = righe(2);
    if (spazio() < H * 0.24 && !found) blocco = righe(0);
    const photoH = Math.max(Math.round(H * 0.24), Math.min(Math.round(H * 0.42), spazio()));

    // foto
    let y = top;
    const img = await loadImg(L$.photoUrl(sm.foto_path));
    ctx.save(); rrect(ctx, pad, y, inner, photoH, 28); ctx.clip();
    if (img) {
      const s = Math.max(inner / img.width, photoH / img.height), iw = img.width * s, ih = img.height * s;
      ctx.drawImage(img, pad + (inner - iw) / 2, y + (photoH - ih) / 2.4, iw, ih);
    } else {
      ctx.fillStyle = '#e2e8f0'; ctx.fillRect(pad, y, inner, photoH);
      ctx.fillStyle = '#64748b'; ctx.font = `700 ${Math.round(photoH * 0.4)}px ${F}`; ctx.textAlign = 'center';
      ctx.fillText((sm.nome || '?').slice(0, 1).toUpperCase(), W / 2, y + photoH * 0.64);
    }
    ctx.restore();
    if (sm.ricompensa && !found) {
      const rw = Math.round(inner * 0.36), rh = Math.round(H * 0.045);
      ctx.fillStyle = '#facc15'; rrect(ctx, pad + inner - rw - 18, y + 18, rw, rh, rh / 2); ctx.fill();
      ctx.fillStyle = '#422006'; ctx.font = `800 ${Math.round(rh * 0.5)}px ${F}`; ctx.textAlign = 'center';
      ctx.fillText('RICOMPENSA', pad + inner - rw / 2 - 18, y + 18 + rh * 0.66);
    }
    // il testo si centra nello spazio fra la foto e il riquadro in basso
    const libero = fy - (y + photoH) - nameH - altezza(blocco);
    y += photoH + Math.max(gap, Math.round(libero / 2));

    // nome
    ctx.fillStyle = '#0b2545'; ctx.textAlign = 'center';
    const ns = fit(ctx, (sm.nome || '').toUpperCase(), 900, nameMax, inner, F);
    ctx.fillText((sm.nome || '').toUpperCase(), W / 2, y + ns * 0.8);
    y += nameH;
    blocco.forEach((r, i) => {
      if (i) y += Math.round(base * 0.35);
      ctx.font = `${r.weight} ${r.size}px ${F}`; ctx.fillStyle = r.color;
      for (const l of r.lines) { y += Math.round(r.size * 1.25); ctx.fillText(l, W / 2, y - Math.round(r.size * 0.25)); }
    });

    // fondo: QR + invito
    ctx.fillStyle = found ? '#f0fdf4' : '#fef2f2'; rrect(ctx, pad, fy, inner, footH, 28); ctx.fill();
    const qs = Math.round(footH * 0.82), qx = pad + Math.round(footH * 0.09), qy = fy + Math.round(footH * 0.09);
    drawQr(ctx, L$.pageUrl(sm, formato === 'a4' ? 'qr-stampa' : 'qr'), qx, qy, qs);
    const tx = qx + qs + Math.round(pad * 0.5), tw = pad + inner - tx - Math.round(pad * 0.4);
    ctx.textAlign = 'left'; ctx.fillStyle = '#0b2545';
    let ty = fy + Math.round(footH * 0.3);
    const big = Math.round(footH * 0.15);
    ctx.font = `800 ${big}px ${F}`;
    for (const l of wrap(ctx, found ? 'Proteggi anche il tuo animale' : `Hai visto ${sm.nome}?`, tw, 2)) { ctx.fillText(l, tx, ty); ty += Math.round(big * 1.15); }
    const small = Math.round(footH * 0.095);
    ctx.font = `500 ${small}px ${F}`; ctx.fillStyle = '#334155';
    const tel = !found && sm.telefono ? `Chiama ${sm.telefono} oppure inquadra il codice` : 'Inquadra il codice e segnalalo: basta un minuto';
    for (const l of wrap(ctx, found ? 'Con FurrFinder l\'avviso parte in un minuto. È gratis.' : tel, tw, 2)) { ctx.fillText(l, tx, ty); ty += Math.round(small * 1.25); }
    ctx.fillStyle = accent;
    fit(ctx, L$.shortUrl(sm), 600, Math.round(small * 0.85), tw, F, 12);   // l'indirizzo non va a capo: se è lungo si rimpicciolisce
    ctx.fillText(L$.shortUrl(sm), tx, ty);

    // firma
    ctx.textAlign = 'center'; ctx.fillStyle = '#64748b'; ctx.font = `600 ${Math.round(W * 0.018)}px ${F}`;
    ctx.fillText('FurrFinder · avvisi gratuiti per animali smarriti', W / 2, H - Math.round(pad * 0.22));
    return c;
  };

  const blobOf = (canvas, type = 'image/png', q) => new Promise((res) => canvas.toBlob(res, type, q));
  const download = (blob, name) => {
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name;
    document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
  };
  const fileName = (sm, formato) => `${sm.stato === 'ritrovato' ? 'ritrovato' : 'smarrito'}-${String(sm.nome || 'animale').toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${formato}`;

  // Condivide l'immagine (sul telefono si apre il menu di condivisione) oppure la scarica
  L$.sharePoster = async (sm, formato) => {
    L$.evento(sm.codice, 'locandina:' + formato);
    const canvas = await L$.poster(sm, formato);
    if (formato === 'a4') {
      const { jsPDF } = window.jspdf;
      const pdf = new jsPDF({ unit: 'mm', format: 'a4' });
      pdf.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', 0, 0, 210, 297);
      pdf.save(fileName(sm, 'a4') + '.pdf');
      return 'scaricata';
    }
    const blob = await blobOf(canvas);
    const file = new File([blob], fileName(sm, formato) + '.png', { type: 'image/png' });
    if (navigator.canShare && navigator.canShare({ files: [file] })) {
      try { await navigator.share({ files: [file], text: L$.shareText(sm) + ' ' + L$.pageUrl(sm, 'locandina') }); return 'condivisa'; }
      catch (e) { if (e && e.name === 'AbortError') return 'annullata'; }
    }
    download(blob, file.name);
    return 'scaricata';
  };
})();
