// =============================================================
//  VETROOM 2 — funzioni comuni alle pagine della clinica
// =============================================================
(function () {
  const VR = window.VR;

  VR.CLINIC_KEY = 'vetroom_clinica';
  VR.ROLE = { admin: 'Amministratore', vet: 'Veterinario', secretary: 'Segreteria' };

  VR.SPECIES = ['Cane', 'Gatto', 'Coniglio', 'Furetto', 'Roditore', 'Uccello', 'Rettile', 'Cavallo', 'Altro'];
  VR.SEX = { M: 'Maschio', F: 'Femmina', U: 'Non noto' };
  VR.NEUTER = { yes: 'Sterilizzato/a', no: 'Non sterilizzato/a', unknown: 'Non noto' };

  VR.param = (k) => new URLSearchParams(location.search).get(k);

  // Carica sessione, profilo e clinica attiva. Se qualcosa manca, reindirizza.
  VR.loadClinicContext = async () => {
    if (VR.buildShell) VR.buildShell();
    const session = await VR.requireSession();
    const who = document.getElementById('who');
    if (who) who.textContent = session.user.email;

    const profile = await VR.loadProfile(session.user.id);
    if (!profile.is_staff) { VR.go('benvenuto.html'); throw new Error('redirect'); }

    const { data: rows, error } = await VR.sb
      .from('clinic_members')
      .select('role, clinic_id, clinics(name, affiliation_code, status, suspended_reason, terms_accepted_at)')
      .eq('user_id', session.user.id)
      .eq('status', 'active');
    if (error) throw error;
    if (!rows || rows.length === 0) {
      if (/\/clinica\/(index\.html)?$/.test(location.pathname)) throw new Error('noclinic');
      VR.go('clinica/'); throw new Error('redirect');
    }

    const saved = localStorage.getItem(VR.CLINIC_KEY);
    const activeRows = rows.filter((r) => r.clinics?.status !== 'suspended');
    const m = activeRows.find((r) => r.clinic_id === saved) || activeRows[0];
    if (!m) {
      // Tutte le cliniche dell'utente sono sospese
      if (/\/clinica\/(index\.html)?$/.test(location.pathname)) {
        const e = new Error('suspended'); e.clinic = rows[0].clinics || {}; throw e;
      }
      VR.go('clinica/'); throw new Error('redirect');
    }
    localStorage.setItem(VR.CLINIC_KEY, m.clinic_id);
    await VR.requireTerms({ profile, clinicId: m.clinic_id, clinicTerms: m.clinics?.terms_accepted_at, isAdmin: m.role === 'admin' });
    VR.checkPlatformAdmin();
    if (VR.shellSetClinic) VR.shellSetClinic({ clinic: m.clinics || {}, role: m.role });

    return {
      session, profile, memberships: rows,
      clinicId: m.clinic_id, role: m.role, clinic: m.clinics || {},
      isVet: m.role === 'admin' || m.role === 'vet'
    };
  };

  // Mostra la voce "Piattaforma" solo agli amministratori della piattaforma
  VR.checkPlatformAdmin = async () => {
    let v = sessionStorage.getItem('vetroom_is_platform');
    if (v === null) {
      const { data } = await VR.sb.rpc('is_platform_admin');
      v = data ? '1' : '0';
      sessionStorage.setItem('vetroom_is_platform', v);
    }
    if (v === '1') document.querySelectorAll('[data-platform-only]').forEach((x) => x.removeAttribute('hidden'));
    return v === '1';
  };

  // Legge un modulo: campi vuoti → null, caselle → vero/falso, numeri → numero
  VR.formValues = (form) => {
    const o = {};
    for (const el of form.elements) {
      if (!el.name || el.disabled) continue;
      if (el.type === 'checkbox') { o[el.name] = el.checked; continue; }
      let v = (el.value || '').trim();
      if (el.dataset.upper !== undefined) v = v.toUpperCase();
      if (v === '') o[el.name] = null;
      else if (el.type === 'number') o[el.name] = Number(v.replace(',', '.'));
      else o[el.name] = v;
    }
    return o;
  };

  VR.fillForm = (form, obj) => {
    for (const el of form.elements) {
      if (!el.name || !(el.name in obj)) continue;
      if (el.type === 'checkbox') el.checked = !!obj[el.name];
      else el.value = obj[el.name] ?? '';
    }
  };

  VR.clientName = (c) =>
    [c?.last_name, c?.first_name].filter(Boolean).join(' ') || 'Cliente senza nome';

  VR.initials = (name) => (name || '?').trim().charAt(0).toUpperCase();

  // "3 anni", "5 mesi", "2 settimane"
  VR.age = (birth) => {
    if (!birth) return '';
    const b = new Date(birth + 'T00:00:00');
    const now = new Date();
    let months = (now.getFullYear() - b.getFullYear()) * 12 + (now.getMonth() - b.getMonth());
    if (now.getDate() < b.getDate()) months--;
    if (months < 0) return '';
    if (months === 0) {
      const weeks = Math.floor((now - b) / (7 * 864e5));
      return weeks <= 1 ? 'meno di 2 settimane' : `${weeks} settimane`;
    }
    if (months < 24) return months === 1 ? '1 mese' : `${months} mesi`;
    const y = Math.floor(months / 12);
    return `${y} anni`;
  };

  VR.petSubtitle = (p) =>
    [p.species, p.breed, VR.age(p.birth_date)].filter(Boolean).join(' · ');

  // Riduce una foto prima del caricamento (max 1200 px, JPEG)
  VR.resizeImage = (file, max = 1200, quality = 0.82, type = 'image/jpeg') => new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      const scale = Math.min(1, max / Math.max(img.width, img.height));
      const c = document.createElement('canvas');
      c.width = Math.round(img.width * scale);
      c.height = Math.round(img.height * scale);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(url);
      c.toBlob((b) => (b ? resolve(b) : reject(new Error('Foto non leggibile'))), type, quality);
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Il file scelto non è un\'immagine valida')); };
    img.src = url;
  });

  // Link temporaneo per vedere un file privato
  VR.fileUrl = async (path, bucket = 'pet-files') => {
    if (!path) return null;
    const { data } = await VR.sb.storage.from(bucket).createSignedUrl(path, 3600);
    return data?.signedUrl || null;
  };

  // Avatar: foto se c'è, altrimenti iniziale
  VR.avatarHtml = (pet, url, cls = 'avatar') =>
    url ? `<img class="${cls}" src="${VR.esc(url)}" alt="">`
        : `<span class="${cls}" aria-hidden="true">${VR.esc(VR.initials(pet.name))}</span>`;

  // ---------- WhatsApp ed email ----------
  VR.waPhone = (p) => {
    let d = String(p || '').replace(/[^\d+]/g, '');
    if (!d) return null;
    if (d.startsWith('+')) d = d.slice(1);
    else if (d.startsWith('00')) d = d.slice(2);
    else if (/^3\d{8,9}$/.test(d) || /^0\d{5,10}$/.test(d)) d = '39' + d;
    return d.length >= 8 ? d : null;
  };
  VR.waLink = (phone, text) => {
    const n = VR.waPhone(phone);
    return `https://wa.me/${n || ''}?text=${encodeURIComponent(text)}`;
  };
  VR.mailLink = (email, subject, text) => `mailto:${encodeURIComponent(email || '')}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(text)}`;
  VR.OWNER_PORTAL = 'https://www.vetroom.it/app/?per=proprietario';
  VR.fmtWhen = (d) => new Date(d).toLocaleString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
  VR.apptMessage = ({ pet, startsAt, clinic }) =>
    `Buongiorno, le ricordiamo l'appuntamento${pet ? ' per ' + pet : ''} ${VR.fmtWhen(startsAt).replace(',', ' alle')} presso ${clinic}. ` +
    `Se non potesse venire, ci avvisi rispondendo a questo messaggio. Grazie!`;
  VR.reminderMessage = ({ pet, title, due, clinic }) =>
    `Buongiorno, per ${pet} è in scadenza: ${title} (entro il ${new Date(due + 'T00:00:00').toLocaleDateString('it-IT')}). ` +
    `Può prenotare rispondendo a questo messaggio oppure dal libretto FurrFinder: ${VR.OWNER_PORTAL}\n${clinic}`;

  // ---------- Grafico del peso (SVG, senza librerie) ----------
  VR.weightChart = (el, points) => {
    const pts = points.filter((p) => p.w > 0 && p.d).sort((a, b) => a.d.localeCompare(b.d));
    if (pts.length < 2) { el.innerHTML = pts.length ? `<p class="muted" style="margin:0">Una sola misura: ${String(pts[0].w).replace('.', ',')} kg il ${new Date(pts[0].d + 'T00:00:00').toLocaleDateString('it-IT')}. Il grafico compare dalla seconda visita con il peso.</p>` : '<p class="muted" style="margin:0">Nessun peso registrato: si aggiunge dalle visite.</p>'; return; }
    const W = 420, H = 170, P = { l: 40, r: 12, t: 12, b: 24 };
    const xs = pts.map((p) => new Date(p.d).getTime()), ws = pts.map((p) => p.w);
    const x0 = Math.min(...xs), x1 = Math.max(...xs), wmin = Math.min(...ws), wmax = Math.max(...ws);
    const pad = Math.max(0.5, (wmax - wmin) * 0.15), y0 = Math.max(0, wmin - pad), y1 = wmax + pad;
    const X = (t) => P.l + (x1 === x0 ? 0.5 : (t - x0) / (x1 - x0)) * (W - P.l - P.r);
    const Y = (w) => P.t + (1 - (w - y0) / (y1 - y0)) * (H - P.t - P.b);
    const line = pts.map((p, i) => `${i ? 'L' : 'M'}${X(xs[i]).toFixed(1)},${Y(p.w).toFixed(1)}`).join(' ');
    const ticks = [y0, (y0 + y1) / 2, y1].map((v) => `<text x="${P.l - 6}" y="${Y(v) + 4}" text-anchor="end">${v.toFixed(1).replace('.', ',')}</text><line x1="${P.l}" x2="${W - P.r}" y1="${Y(v)}" y2="${Y(v)}" class="grid"/>`).join('');
    const fmt = (t) => new Date(t).toLocaleDateString('it-IT', { month: 'short', year: '2-digit' });
    el.innerHTML = `<svg viewBox="0 0 ${W} ${H}" class="wchart" role="img" aria-label="Andamento del peso">
      ${ticks}<path d="${line}" class="ln"/>
      ${pts.map((p, i) => `<circle cx="${X(xs[i])}" cy="${Y(p.w)}" r="4"><title>${new Date(p.d + 'T00:00:00').toLocaleDateString('it-IT')}: ${String(p.w).replace('.', ',')} kg</title></circle>`).join('')}
      <text x="${P.l}" y="${H - 6}">${fmt(x0)}</text><text x="${W - P.r}" y="${H - 6}" text-anchor="end">${fmt(x1)}</text>
    </svg><p class="hint-small" style="margin:4px 0 0">Ultimo peso: <b>${String(pts[pts.length - 1].w).replace('.', ',')} kg</b> (${new Date(pts[pts.length - 1].d + 'T00:00:00').toLocaleDateString('it-IT')})</p>`;
  };

  // ---------- Dettatura (dove il browser la supporta) ----------
  VR.enableDictation = (root) => {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) return;
    root.querySelectorAll('textarea[data-dictate]').forEach((ta) => {
      if (ta.dataset.dictReady) return; ta.dataset.dictReady = '1';
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'dictate-btn'; b.title = 'Detta'; b.setAttribute('aria-label', 'Detta'); b.textContent = '🎤';
      ta.insertAdjacentElement('beforebegin', b);
      let rec = null;
      b.addEventListener('click', () => {
        if (rec) { rec.stop(); return; }
        rec = new SR(); rec.lang = VR.lang === 'en' ? 'en-GB' : 'it-IT'; rec.continuous = true; rec.interimResults = false;
        rec.onresult = (e) => {
          const t = [...e.results].slice(e.resultIndex).map((r) => r[0].transcript).join(' ').trim();
          if (t) { ta.value = (ta.value ? ta.value.replace(/\s*$/, ' ') : '') + t; ta.dispatchEvent(new Event('input', { bubbles: true })); }
        };
        rec.onend = () => { rec = null; b.classList.remove('on'); };
        rec.onerror = () => { rec = null; b.classList.remove('on'); };
        b.classList.add('on'); rec.start();
      });
    });
  };

  VR.fillSpecies = (select) => {
    select.innerHTML = '<option value="">—</option>' +
      VR.SPECIES.map((s) => `<option>${s}</option>`).join('');
  };
})();
