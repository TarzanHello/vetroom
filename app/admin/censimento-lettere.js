// =============================================================
//  VETROOM · Plancia · Censimento OSM — lettere da spedire per posta
//  Un PDF A4 con una lettera per struttura: destinatario nella finestra
//  della busta (DL con finestra a destra), testo, QR verso la pagina di rivendicazione.
// =============================================================
(function () {
  const carica = (src) => new Promise((ok, ko) => {
    if (document.querySelector(`script[data-lib="${src}"]`)) return ok();
    const s = document.createElement('script');
    s.src = src; s.dataset.lib = src; s.onload = ok; s.onerror = () => ko(new Error('Non riesco a caricare ' + src));
    document.head.appendChild(s);
  });
  const librerie = async () => {
    if (!window.jspdf) await carica('../vendor/jspdf-2.5.2.min.js');
    if (!window.qrcode) await carica('../vendor/qrcode-generator.js');
  };

  const SITO = 'https://vetroom.it';
  const MESI = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
  const oggi = () => { const d = new Date(); return `${d.getDate()} ${MESI[d.getMonth()]} ${d.getFullYear()}`; };
  // jsPDF con i caratteri standard scrive solo l'alfabeto Windows-1252: tolgo il resto
  const pulito = (s) => String(s == null ? '' : s).replace(/[‘’ʼ´`]/g, "'").replace(/[“”«»]/g, '"').replace(/[^\x20-\x7E\xA0-\xFF€–—…·•]/g, '').replace(/\s+/g, ' ').trim();
  const linkDi = (r) => `${SITO}/app/rivendica.html?s=${r.t}/${Number(r.i)}`;

  // Perché una struttura non può ricevere la lettera (null = va bene)
  const motivoEsclusione = (r) => {
    if (!r.attivo) return 'non è più su OpenStreetMap';
    if (r.revisione !== 'ok') return 'non è abilitata';
    if (r.su_vetroom) return 'è già attiva su Vetroom';
    if (!r.via || !r.comune) return 'manca l\'indirizzo';
    if (!r.cap) return 'manca il CAP';
    if (!r.provincia || !r.regione) return 'manca la provincia';
    return null;
  };

  const lettera = (doc, r, firma) => {
    const NAVY = [11, 37, 69], TEAL = [18, 120, 110], GREY = [80, 88, 100], FAINT = [130, 138, 150];
    const L = 22, R = 188, W = R - L;
    const nome = pulito(r.nome) || 'Struttura veterinaria';
    const comune = pulito(r.comune);

    // Mittente
    doc.setFont('helvetica', 'bold'); doc.setFontSize(17); doc.setTextColor(...TEAL); doc.text('Vetroom', L, 22);
    doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); doc.setTextColor(...GREY);
    doc.text(['AF&B S.r.l.s. - Via Mario Ugo Guattari 48 - 00173 Roma (RM)', 'info@vetroom.it - vetroom.it'], L, 28);

    // Destinatario: finestra della busta DL a destra (circa 110-200 mm, 45-80 mm dall'alto)
    const via = pulito([r.via, r.civico].filter(Boolean).join(' '));
    const citta = pulito(`${r.cap || ''} ${r.comune || ''} ${r.provincia ? '(' + r.provincia + ')' : ''}`);
    doc.setFontSize(10.5); doc.setTextColor(0, 0, 0);
    const dest = ['Spett.le', ...doc.splitTextToSize(nome, 78).slice(0, 2), 'Alla cortese attenzione del titolare', via, citta];
    doc.text(dest, 112, 52, { lineHeightFactor: 1.35 });

    let y = 96;
    doc.setFontSize(10); doc.setTextColor(...GREY); doc.text(`Roma, ${oggi()}`, R, y, { align: 'right' });
    y += 12;
    doc.setFont('helvetica', 'bold'); doc.setFontSize(11.5); doc.setTextColor(...NAVY);
    const ogg = doc.splitTextToSize(`Oggetto: la scheda di ${nome} su vetroom.it`, W);
    doc.text(ogg, L, y); y += ogg.length * 5.6 + 5;

    doc.setFont('helvetica', 'normal'); doc.setFontSize(10.5); doc.setTextColor(30, 34, 40);
    const par = (t) => { const righe = doc.splitTextToSize(pulito(t), W); doc.text(righe, L, y, { lineHeightFactor: 1.4 }); y += righe.length * 5.2 + 3.2; };
    par('Gentile titolare,');
    par(`su vetroom.it c'è una pagina pubblica dedicata alla vostra struttura, nell'elenco "Veterinari per città": chi cerca un veterinario a ${comune} trova indirizzo, orari e contatti. I dati provengono da OpenStreetMap, la mappa libera e collaborativa, e potrebbero non essere aggiornati.`);
    par('Potete rivendicare la scheda, gratuitamente: la collegate a Vetroom, il gestionale veterinario gratuito in cloud per agenda, cartelle cliniche e fatture, ne curate voi i dati e ricevete le richieste di appuntamento dei proprietari tramite l\'app FurrFinder.');
    par('Prima di approvare una richiesta telefoniamo al numero pubblico della struttura, per essere certi che a gestire la scheda sia davvero chi ne ha titolo.');

    // QR + istruzioni
    y += 2;
    const link = linkDi(r);
    const qr = window.qrcode(0, 'M'); qr.addData(link); qr.make();
    const n = qr.getModuleCount(), size = 38, cell = size / n;
    doc.setDrawColor(...NAVY); doc.setLineWidth(0.5); doc.setFillColor(255, 255, 255);
    doc.roundedRect(L - 3, y - 3, size + 6, size + 6, 2, 2, 'FD');
    doc.setFillColor(0, 0, 0);
    for (let a = 0; a < n; a++) for (let b = 0; b < n; b++) if (qr.isDark(a, b)) doc.rect(L + b * cell, y + a * cell, cell + 0.02, cell + 0.02, 'F');
    const tx = L + size + 10, tw = R - tx;
    doc.setFont('helvetica', 'bold'); doc.setFontSize(12); doc.setTextColor(...NAVY);
    doc.text('Per iniziare bastano pochi minuti', tx, y + 5);
    doc.setFont('helvetica', 'normal'); doc.setFontSize(10); doc.setTextColor(30, 34, 40);
    const istr = doc.splitTextToSize('Inquadrate il codice con la fotocamera del telefono, oppure scrivete nel browser questo indirizzo:', tw);
    doc.text(istr, tx, y + 12, { lineHeightFactor: 1.35 });
    doc.setFont('courier', 'bold'); doc.setFontSize(9.5); doc.setTextColor(...NAVY);
    doc.text(doc.splitTextToSize(link.replace(/^https:\/\//, ''), tw), tx, y + 12 + istr.length * 4.8 + 2);
    y += size + 12;

    doc.setFont('helvetica', 'normal'); doc.setFontSize(10.5); doc.setTextColor(30, 34, 40);
    par('Per qualsiasi domanda potete scriverci a info@vetroom.it.');
    par('Cordiali saluti,');
    doc.setFont('helvetica', 'bold'); doc.text(pulito(firma.nome || 'Il team di Vetroom'), L, y + 2);
    doc.setFont('helvetica', 'normal'); doc.setTextColor(...GREY); doc.text('Vetroom - AF&B S.r.l.s.', L, y + 7.5);

    // Nota privacy in fondo
    doc.setDrawColor(210, 214, 220); doc.setLineWidth(0.2); doc.line(L, 262, R, 262);
    doc.setFontSize(7.6); doc.setTextColor(...FAINT);
    doc.text(doc.splitTextToSize("Perché ricevete questa lettera: il nome e l'indirizzo della struttura sono pubblicati su OpenStreetMap (© OpenStreetMap contributors, licenza ODbL) e li usiamo solo per presentarvi la scheda. Per correggere o togliere la scheda, o per non ricevere altre comunicazioni, scrivete a info@vetroom.it. Informativa completa: vetroom.it/privacy.html. AF&B S.r.l.s., Via Mario Ugo Guattari 48, 00173 Roma, P.IVA 13698751008.", W), L, 266, { lineHeightFactor: 1.3 });
  };

  // Etichette adesive A4 (3 x 8, 70 x 37 mm) per chi usa buste senza finestra
  const etichette = (doc, righe) => {   // inizia su una pagina nuova già aggiunta
    const COL = 3, RIG = 8, EW = 70, EH = 37;
    righe.forEach((r, k) => {
      const pos = k % (COL * RIG);
      if (pos === 0 && k > 0) doc.addPage();
      const x = (pos % COL) * EW + 6, y = Math.floor(pos / COL) * EH + 10;
      doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(0, 0, 0);
      const t = [...doc.splitTextToSize(pulito(r.nome) || 'Struttura veterinaria', EW - 12).slice(0, 2), 'Alla c.a. del titolare',
        pulito([r.via, r.civico].filter(Boolean).join(' ')), pulito(`${r.cap || ''} ${r.comune || ''} ${r.provincia ? '(' + r.provincia + ')' : ''}`)];
      doc.text(t, x, y, { lineHeightFactor: 1.3 });
    });
  };

  window.CZL = {
    motivoEsclusione,
    // righe: strutture già controllate con motivoEsclusione; opz: { firma, etichette }
    async genera(righe, opz = {}) {
      await librerie();
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF({ unit: 'mm', format: 'a4' });
      righe.forEach((r, k) => { if (k) doc.addPage(); lettera(doc, r, opz.firma || {}); });
      // Etichette in fondo allo stesso PDF: un solo file da scaricare, anche dal telefono
      if (opz.etichette) { doc.addPage(); etichette(doc, righe); }
      const data = new Date().toISOString().slice(0, 10);
      doc.save(`Lettere Vetroom ${data} (${righe.length}).pdf`);
    }
  };
})();
