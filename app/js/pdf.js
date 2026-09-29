// =============================================================
//  VETROOM 2 — Referto PDF della visita
//  VR.buildVisitPdf({ clinic, visit, pet, client, vetName }) → jsPDF
// =============================================================
(function () {
  const VR = window.VR;
  const NAVY = [3, 56, 96], TEAL = [52, 174, 167], MUTED = [94, 111, 124], LINE = [220, 228, 234];

  // I caratteri standard dei PDF non contengono emoji e simboli rari: li sostituiamo
  const clean = (s) => String(s ?? '')
    .replace(/[\u2018\u2019]/g, "'").replace(/[\u201C\u201D]/g, '"').replace(/[\u2013\u2014]/g, '-')
    .replace(/\u2026/g, '...').replace(/\r\n?/g, '\n')
    .replace(/[^\n\x20-\x7E\u00A0-\u00FF\u20AC]/g, '');

  const fmtDate = (d) => d ? new Date(d.length === 10 ? d + 'T00:00:00' : d).toLocaleDateString('it-IT') : '';

  // Scarica un'immagine privata e la prepara per il PDF (ridotta se troppo grande)
  VR.loadImageForPdf = async (path, bucket = 'clinic-assets', maxSize = 1600) => {
    if (!path) return null;
    try {
      const url = await VR.fileUrl(path, bucket);
      if (!url) return null;
      const blob = await (await fetch(url)).blob();
      const isPng = blob.type.includes('png');
      const src = URL.createObjectURL(blob);
      const img = await new Promise((res) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => res(null); i.src = src; });
      if (!img) { URL.revokeObjectURL(src); return null; }
      const scale = Math.min(1, maxSize / Math.max(img.naturalWidth, img.naturalHeight));
      const c = document.createElement('canvas');
      c.width = Math.round(img.naturalWidth * scale); c.height = Math.round(img.naturalHeight * scale);
      const g = c.getContext('2d');
      if (!isPng) { g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); }
      g.drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(src);
      return { dataUrl: c.toDataURL(isPng ? 'image/png' : 'image/jpeg', 0.85), w: c.width, h: c.height, fmt: isPng ? 'PNG' : 'JPEG' };
    } catch { return null; }
  };

  // Adatta un'immagine dentro un riquadro mantenendo le proporzioni
  const fit = (img, maxW, maxH) => { const r = Math.min(maxW / img.w, maxH / img.h); return { w: img.w * r, h: img.h * r }; };

  const T = (x) => (VR.tr ? VR.tr(x) : x);
  VR.buildVisitPdf = ({ clinic, visit, pet, client, vetName, logo, stamp, module, draft = false, images = [], background = null }) => {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'mm', format: 'a4' });
    const W = 210, H = 297, M = 18, CW = W - 2 * M;
    let y = M;

    const text = (s, x, yy, opt = {}) => doc.text(clean(s), x, yy, opt);
    const setFont = (size, style = 'normal', color = [28, 43, 54]) => { doc.setFont('helvetica', style); doc.setFontSize(size); doc.setTextColor(...color); };
    // Stile scelto nelle impostazioni della clinica
    const ps = clinic.pdf_settings || {};
    // Carta intestata: immagine a pagina intera sotto al contenuto
    const BG = background;
    const TOP = BG ? Math.max(10, Number(ps.lh_top ?? 45)) : M;
    const BOTTOM = BG ? Math.max(10, Number(ps.lh_bottom ?? 25)) : 0;
    const LIMIT = BG ? H - BOTTOM - 6 : H - 30;
    const paintBg = () => { if (BG) doc.addImage(BG.dataUrl, BG.fmt, 0, 0, W, H, undefined, 'FAST'); };
    const newPage = () => { doc.addPage(); paintBg(); y = TOP; };
    paintBg();
    const hex = (h) => { const m = /^#?([0-9a-f]{6})$/i.exec(h || ''); if (!m) return null; const n = parseInt(m[1], 16); return [n >> 16, (n >> 8) & 255, n & 255]; };
    const ACC = hex(ps.accent) || TEAL;
    const boxed = ps.style === 'boxed';
    const centered = ps.header === 'center';
    const PAD = boxed ? 5 : 0;
    const endBox = (top, page) => {
      if (!boxed || doc.getNumberOfPages() !== page) return;
      doc.setDrawColor(...LINE); doc.setLineWidth(0.35); doc.roundedRect(M, top, CW, y - top + 1, 2.5, 2.5, 'S');
    };
    const boxHeading = (t) => {
      setFont(10.5, 'bold', ACC); text(T(t), M + PAD, y + 7);
      doc.setDrawColor(...ACC); doc.setLineWidth(0.7); doc.line(M + PAD, y + 9, M + PAD + 26, y + 9);
      y += 15;
    };
    const ensure = (h) => { if (y + h > LIMIT) newPage(); };

    // ---------- Intestazione (non serve se c'è la carta intestata) ----------
    if (BG) { y = TOP; }
    else {
    let headerH = 0;
    if (logo) {
      const s = fit(logo, centered ? 60 : 55, centered ? 22 : 24);
      doc.addImage(logo.dataUrl, logo.fmt, centered ? (W - s.w) / 2 : M, y, s.w, s.h);
      headerH = s.h;
    }
    const lines = [
      [clinic.header_name || clinic.name, 12, 'bold', NAVY],
      [clinic.header_title, 9.5, 'normal', MUTED],
      [clinic.header_address, 9, 'normal', MUTED],
      [[clinic.header_phone, clinic.header_email].filter(Boolean).join('  ·  '), 9, 'normal', MUTED],
      [[clinic.header_piva ? 'P.IVA ' + clinic.header_piva : '', clinic.header_albo ? 'Iscr. Albo ' + clinic.header_albo : ''].filter(Boolean).join('  ·  '), 9, 'normal', MUTED]
    ].filter((l) => l[0]);
    let hy = centered ? y + headerH + (logo ? 6 : 4) : y + 4;
    lines.forEach(([s, size, style, color]) => {
      setFont(size, style, color);
      doc.splitTextToSize(clean(s), centered ? CW : 100).forEach((ln) => {
        text(ln, centered ? W / 2 : W - M, hy, { align: centered ? 'center' : 'right' }); hy += size * 0.45;
      });
    });
    y = centered ? hy + 1 : Math.max(y + headerH, hy) + 4;
    doc.setDrawColor(...ACC); doc.setLineWidth(0.8); doc.line(M, y, W - M, y);
    y += 10;
    }

    // ---------- Titolo ----------
    setFont(17, 'bold', NAVY);
    text(T(module?.doc_title || 'Referto di visita'), M, y);
    setFont(10, 'normal', MUTED);
    text(T(`${visit.visit_kind || 'Visita'} del ${fmtDate(visit.visit_date)}`), W - M, y, { align: 'right' });
    y += 9;

    // ---------- Paziente e proprietario ----------
    const sex = T({ M: 'Maschio', F: 'Femmina', U: 'Non noto' }[pet?.sex] || '');
    const neut = T({ yes: 'sterilizzato/a', no: 'non sterilizzato/a' }[pet?.neuter_status] || '');
    const weight = visit.form_data?.weight_kg ?? pet?.weight_kg;
    const petRows = [
      ['Nome', pet?.name],
      ['Specie / razza', [pet?.species, pet?.breed].filter(Boolean).join(' - ')],
      ['Sesso', [sex, neut].filter(Boolean).join(', ')],
      ['Nascita', pet?.birth_date ? `${fmtDate(pet.birth_date)} (${T(VR.age(pet.birth_date))})` : ''],
      ['Microchip', pet?.microchip],
      ['Peso', weight != null ? String(weight).replace('.', ',') + ' kg' : '']
    ].filter((r) => r[1]);
    const address = client ? [[client.address_street, client.address_number].filter(Boolean).join(' '),
      [client.address_zip, client.address_city, client.address_province ? `(${client.address_province})` : ''].filter(Boolean).join(' ')].filter(Boolean).join(', ') : '';
    const ownRows = client ? [
      ['Nome', VR.clientName(client)],
      ['Codice fiscale', client.fiscal_code],
      ['Indirizzo', address],
      ['Telefono', client.phone],
      ['Email', client.email]
    ].filter((r) => r[1]) : [];

    const boxW = (CW - 6) / 2;
    const drawBox = (x, title, rows) => {
      let by = y + 7;
      setFont(8.5, 'bold', ACC); text(T(title).toUpperCase(), x + 4, by); by += 5.5;
      rows.forEach(([k, v]) => {
        setFont(8.5, 'normal', MUTED); text(T(k), x + 4, by);
        setFont(9.5, 'normal');
        const wrapped = doc.splitTextToSize(clean(v), boxW - 34);
        text(wrapped, x + 30, by);
        by += 4.6 * wrapped.length;
      });
      return by - y + 2;
    };
    const measure = (rows) => 14 + rows.reduce((h, [, v]) => h + 4.6 * doc.splitTextToSize(clean(v), boxW - 34).length, 0);
    setFont(9.5);
    const boxH = Math.max(measure(petRows), ownRows.length ? measure(ownRows) : 0, 22);
    doc.setFillColor(245, 248, 250); doc.setDrawColor(...LINE); doc.setLineWidth(0.3);
    doc.roundedRect(M, y, boxW, boxH, 2, 2, 'FD');
    drawBox(M, 'Paziente', petRows);
    if (ownRows.length) {
      doc.setFillColor(245, 248, 250); doc.setDrawColor(...LINE);
      doc.roundedRect(M + boxW + 6, y, boxW, boxH, 2, 2, 'FD');
      drawBox(M + boxW + 6, 'Proprietario', ownRows);
    }
    y += boxH + 10;

    // ---------- Sezioni ----------
    const section = (title, body) => {
      if (!body) return;
      if (boxed) {
        setFont(10.5);
        const ls = doc.splitTextToSize(clean(body), CW - 2 * PAD);
        const h = 15 + ls.length * 5.2 + 3;
        ensure(Math.min(h, H - 60));
        const top = y, page = doc.getNumberOfPages();
        boxHeading(title);
        setFont(10.5);
        ls.forEach((ln) => { ensure(6); text(ln, M + PAD, y); y += 5.2; });
        y += 1; endBox(top, page); y += 5;
        return;
      }
      ensure(18);
      setFont(10.5, 'bold', ACC); text(T(title), M, y); y += 2;
      doc.setDrawColor(...LINE); doc.setLineWidth(0.3); doc.line(M, y, W - M, y); y += 5.5;
      setFont(10.5);
      doc.splitTextToSize(clean(body), CW).forEach((ln) => { ensure(6); text(ln, M, y); y += 5.2; });
      y += 5;
    };
    // ---------- Scheda del modulo (blocchi a tutta larghezza o affiancati) ----------
    function drawModule(mod) {
      if (!mod || !Array.isArray(mod.fields) || !mod.fields.length) return;
      const vals = mod.values || {};
      const inner = CW - 2 * PAD;
      const half = (inner - 8) / 2;
      let boxTop = null, boxPage = 0;
      const closeBox = () => { if (boxTop === null) return; y -= 1.5; endBox(boxTop, boxPage); y += 6; boxTop = null; };
      const valText = (fd) => {
        const v = vals[fd.key];
        if (fd.type === 'checkbox') return T(v ? 'Sì' : 'No');
        if (fd.type === 'date') return fmtDate(v);
        if (fd.type === 'number') return String(v).replace('.', ',') + (fd.unit ? ' ' + fd.unit : '');
        return String(v);
      };
      const has = (fd) => fd.type === 'checkbox' ? vals[fd.key] === true : (vals[fd.key] !== undefined && vals[fd.key] !== null && vals[fd.key] !== '');
      const lines = (fd, w) => { setFont(10.5); return doc.splitTextToSize(clean(valText(fd)), w); };
      let row = [];
      const flush = () => {
        if (!row.length) return;
        const widths = row.length === 2 ? [half, half] : [row[0].width === 'half' ? half : inner];
        const ls = row.map((fd, i) => lines(fd, widths[i]));
        const h = 5 + Math.max(...ls.map((l) => l.length)) * 5.1;
        ensure(h + 2);
        row.forEach((fd, i) => {
          const x = M + PAD + (i === 1 ? half + 8 : 0);
          setFont(8.5, 'normal', MUTED); text(T(fd.label), x, y);
          setFont(10.5);
          ls[i].forEach((ln, k) => text(ln, x, y + 5 + k * 5.1));
        });
        y += h + 2.5;
        row = [];
      };
      const heading = (t) => {
        flush();
        if (boxed) { closeBox(); ensure(26); boxTop = y; boxPage = doc.getNumberOfPages(); boxHeading(t); return; }
        ensure(20);
        setFont(10.5, 'bold', ACC); text(T(t), M, y); y += 2;
        doc.setDrawColor(...LINE); doc.setLineWidth(0.3); doc.line(M, y, W - M, y); y += 6;
      };
      let pending = null, started = false;
      for (const fd of mod.fields) {
        if (fd.print === false) continue;
        if (fd.type === 'section') { flush(); pending = fd.label; continue; }
        if (!has(fd)) continue;
        if (!started && pending === null) pending = mod.name || 'Scheda';
        if (pending !== null) { heading(pending); pending = null; }
        started = true;
        if (fd.width === 'half') { row.push(fd); if (row.length === 2) flush(); }
        else { flush(); row = [fd]; flush(); }
      }
      flush();
      if (boxed) closeBox();
      else if (started) y += 3;
    }

    section('Motivo della visita', visit.title);
    section('Anamnesi ed esame clinico', visit.notes);
    drawModule(module);
    section('Diagnosi', visit.diagnosis);
    section('Terapia e indicazioni', visit.therapy);

    // ---------- Firma e timbro ----------
    const SIGN_END = BG ? H - BOTTOM - 2 : H - 13;
    if (y + 42 > SIGN_END) newPage();
    y = Math.max(y + 2, SIGN_END - 42);
    const sx = W - M - 70;
    setFont(9, 'normal', MUTED); text(`${T('Data:')} ${fmtDate(visit.visit_date)}`, M, y + 4);
    setFont(9, 'normal', MUTED); text(T('Il medico veterinario'), sx + 35, y + 4, { align: 'center' });
    if (vetName) { setFont(10, 'bold', NAVY); text(vetName, sx + 35, y + 10, { align: 'center' }); }
    if (stamp) {
      const s = fit(stamp, 45, 28);
      doc.addImage(stamp.dataUrl, stamp.fmt, sx + 35 - s.w / 2, y + 13, s.w, s.h);
    } else {
      doc.setDrawColor(...LINE); doc.line(sx, y + 32, sx + 70, y + 32);
    }

    // ---------- Foto allegate: una pagina ciascuna ----------
    images.forEach((im, k) => {
      doc.addPage(); y = M;
      setFont(11, 'bold', NAVY); text(T(`Allegato ${k + 1}`) + (im.title ? ' - ' + im.title : ''), M, y + 2);
      const s = fit(im, CW, H - 2 * M - 22);
      doc.addImage(im.dataUrl, im.fmt, M + (CW - s.w) / 2, y + 8, s.w, s.h);
    });

    // ---------- Piè di pagina (e scritta BOZZA) ----------
    const pages = doc.getNumberOfPages();
    for (let i = 1; i <= pages; i++) {
      doc.setPage(i);
      if (draft) {
        doc.saveGraphicsState && doc.saveGraphicsState();
        if (doc.GState) doc.setGState(new doc.GState({ opacity: 0.12 }));
        setFont(90, 'bold', [220, 38, 38]); text(T('BOZZA'), W / 2, H / 2 + 20, { align: 'center', angle: 35 });
        doc.restoreGraphicsState && doc.restoreGraphicsState();
      }
      setFont(8, 'normal', MUTED);
      const FY = BG ? H - BOTTOM + 4 : H - 10;
      text(T(`${pet?.name || ''} - referto del ${fmtDate(visit.visit_date)}`) + (draft ? T(' - BOZZA non definitiva') : ''), M, FY);
      text(T(`Pagina ${i} di ${pages}`), W - M, FY, { align: 'right' });
    }
    return doc;
  };

  // Sfondo del referto: quello della scheda, altrimenti la carta intestata della clinica
  VR.loadPdfBackground = (clinic, bgPath) => {
    const path = bgPath || clinic?.pdf_settings?.letterhead_path;
    return path ? VR.loadImageForPdf(path, 'clinic-assets', 2000) : Promise.resolve(null);
  };

  VR.samplePdf = async ({ clinic, module = null, vetName = '' }) => {
    const [logo, stamp, background] = await Promise.all([VR.loadImageForPdf(clinic.logo_path), VR.loadImageForPdf(clinic.stamp_path),
      VR.loadPdfBackground(clinic, module?.bg_path)]);
    const values = {};
    (module?.fields || []).forEach((x) => {
      if (x.type === 'section') return;
      values[x.key] = x.type === 'number' ? 12.5 : x.type === 'checkbox' ? true : x.type === 'date' ? new Date().toLocaleDateString('sv-SE')
        : x.type === 'select' ? (x.options?.[0] || 'Esempio') : x.type === 'textarea' ? 'Testo di esempio su più righe, per vedere come si impagina il contenuto di un campo lungo.' : 'Esempio';
    });
    return VR.buildVisitPdf({
      clinic, logo, stamp, vetName, background,
      visit: { visit_date: new Date().toLocaleDateString('sv-SE'), visit_kind: 'Visita clinica', title: 'Zoppia arto posteriore sinistro da tre giorni', notes: 'Nessun trauma riferito. Appetito conservato. Dolore alla flessione del ginocchio sinistro.', diagnosis: 'Sospetta tendinopatia.', therapy: 'Riposo per 10 giorni, passeggiate brevi al guinzaglio. Controllo tra 10 giorni.', form_data: { weight_kg: 24.5 } },
      pet: { name: 'Paziente di esempio', species: 'Cane', breed: 'Meticcio', sex: 'M', neuter_status: 'yes', birth_date: '2020-01-01', microchip: '380260000000000' },
      client: { first_name: 'Mario', last_name: 'Rossi', address_street: 'Via Roma', address_number: '1', address_zip: '00100', address_city: 'Roma', phone: '333 0000000' },
      module: module ? { name: module.name, fields: module.fields, values, doc_title: module.doc_title } : null
    });
  };

  VR.visitPdfName = (pet, visit, draft = false) =>
    `${draft ? 'Bozza referto' : 'Referto'} ${clean(pet?.name || 'visita')} ${visit.visit_date}.pdf`.replace(/[\\/:*?"<>|]+/g, '-');
})();
