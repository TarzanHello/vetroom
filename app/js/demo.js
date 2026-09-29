// =============================================================
//  VETROOM 2 — Dati di esempio (per provare Vetroom senza inserire nulla)
//  Tutto è segnato come "esempio" e si rimuove con un clic.
// =============================================================
(function () {
  const VR = window.VR;
  const day = (n, h = 0, m = 0) => { const d = new Date(); d.setDate(d.getDate() + n); d.setHours(h, m, 0, 0); return d; };
  const ymd = (d) => d.toLocaleDateString('sv-SE');

  VR.createDemoData = async (ctx) => {
    const cid = ctx.clinicId, uid = ctx.session.user.id;
    const C = [
      { last_name: 'Esposito', first_name: 'Giulia', phone: '3331234567', email: 'giulia.esposito@esempio.it', address_city: 'Roma', address_province: 'RM' },
      { last_name: 'Ferrari', first_name: 'Marco', phone: '3479876543', email: 'marco.ferrari@esempio.it', address_city: 'Roma', address_province: 'RM' },
      { last_name: 'Ricci', first_name: 'Anna', phone: '3285551234', address_city: 'Frascati', address_province: 'RM' }
    ].map((c) => ({ id: crypto.randomUUID(), clinic_id: cid, is_demo: true, notes: 'Dati di esempio', ...c }));
    const P = [
      { name: 'Luna', species: 'Cane', breed: 'Labrador', sex: 'F', neuter_status: 'yes', birth_date: '2019-04-12', microchip: '380260000111222', weight_kg: 27.4, c: 0 },
      { name: 'Birba', species: 'Gatto', breed: 'Europeo', sex: 'F', neuter_status: 'yes', birth_date: '2021-09-03', weight_kg: 4.1, c: 0 },
      { name: 'Rocky', species: 'Cane', breed: 'Jack Russell', sex: 'M', neuter_status: 'no', birth_date: '2023-02-20', microchip: '380260000333444', weight_kg: 7.8, c: 1 },
      { name: 'Nuvola', species: 'Coniglio', breed: 'Ariete', sex: 'F', birth_date: '2024-05-01', weight_kg: 1.9, c: 2 }
    ].map((p) => ({ ...p, id: crypto.randomUUID() }));
    const pets = P.map(({ c, ...p }) => ({ ...p, created_by_clinic_id: cid, is_demo: true }));
    const access = P.map((p) => ({ pet_id: p.id, clinic_id: cid, client_id: C[p.c].id }));
    const V = [
      [0, -300, 'Visita clinica', 'Controllo annuale', 'Buone condizioni generali. Mucose rosee, linfonodi nella norma.', 'Soggetto sano.', 'Proseguire con l\'alimentazione attuale.', 26.2],
      [0, -120, 'Controllo', 'Zoppia arto anteriore destro', 'Zoppia di grado lieve da due giorni dopo una corsa al parco.', 'Distorsione lieve del carpo.', 'Riposo 7 giorni, antinfiammatorio come da prescrizione.', 27.0],
      [0, -10, 'Vaccinazione', 'Richiamo vaccinale annuale', 'Soggetto in buona salute al momento della vaccinazione.', '', 'Prossimo richiamo tra 12 mesi.', 27.4],
      [2, -60, 'Visita clinica', 'Starnuti frequenti', 'Starnuti da una settimana, appetito conservato.', 'Rinite lieve.', 'Aerosol con soluzione fisiologica 2 volte al giorno per 5 giorni.', 7.8],
      [1, -30, 'Visita clinica', 'Controllo peso', 'Tende a mangiare poco la sera.', 'Nessuna patologia evidente.', 'Dividere il pasto in due porzioni.', 4.1]
    ].map(([pi, d, kind, title, notes, diagnosis, therapy, w]) => ({
      clinic_id: cid, pet_id: P[pi].id, client_id: C[P[pi].c].id, visit_date: ymd(day(d)), visit_kind: kind, title, notes, diagnosis, therapy,
      form_data: { weight_kg: w }, visible_to_owner: true, created_by: uid, is_demo: true
    }));
    const A = [
      [0, 9, 30, 0, 'Controllo', 'confirmed'], [0, 11, 0, 2, 'Vaccinazione', 'confirmed'], [0, 16, 30, 3, 'Visita clinica', 'requested'],
      [1, 10, 0, 1, 'Controllo', 'confirmed']
    ].map(([d, h, m, pi, type, status]) => ({
      clinic_id: cid, pet_id: P[pi].id, client_id: C[P[pi].c].id, starts_at: day(d, h, m).toISOString(), duration_min: 30, type, status,
      notes_owner: status === 'requested' ? 'Mangia poco da due giorni' : null, is_demo: true
    }));
    const R = [
      { pet_id: P[2].id, client_id: C[1].id, title: 'Vaccino annuale', kind: 'vaccino', due_date: ymd(day(-5)) },
      { pet_id: P[1].id, client_id: C[0].id, title: 'Antiparassitario', kind: 'richiamo', due_date: ymd(day(7)) },
      { pet_id: P[0].id, client_id: C[0].id, title: 'Controllo annuale', kind: 'controllo', due_date: ymd(day(20)) }
    ].map((r) => ({ ...r, clinic_id: cid, is_demo: true, created_by: uid }));
    for (const [t, rows] of [['clients', C], ['pets', pets], ['pet_access', access], ['visits', V], ['appointments', A], ['reminders', R]]) {
      const { error } = await VR.sb.from(t).insert(rows);
      if (error) throw error;
    }
  };

  VR.removeDemoData = async (ctx) => {
    const { error } = await VR.sb.rpc('remove_demo_data', { p_clinic: ctx.clinicId });
    if (error) throw error;
  };
})();
