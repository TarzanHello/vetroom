// =============================================================
//  VETROOM 2 — funzioni comuni al portale proprietario
//  (usa anche le utilità di clinica.js: moduli, foto, età…)
// =============================================================
(function () {
  const VR = window.VR;

  VR.loadOwnerContext = async () => {
    if (VR.buildShell) VR.buildShell();
    const session = await VR.requireSession();
    const who = document.getElementById('who');
    if (who) who.textContent = session.user.email;
    const profile = await VR.loadProfile(session.user.id);
    if (!profile.is_owner && !profile.is_staff) { VR.go('benvenuto.html'); throw new Error('redirect'); }
    await VR.requireTerms({ profile });
    // Chi lavora in una clinica vede anche il link al gestionale
    const staffLink = document.getElementById('staffLink');
    if (staffLink && profile.is_staff) VR.show(staffLink);
    return { session, profile, uid: session.user.id };
  };

  VR.fmtDate = (d) => d ? new Date(d.length === 10 ? d + 'T00:00:00' : d).toLocaleDateString('it-IT') : '';
  VR.fmtDateTime = (d) => new Date(d).toLocaleString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });

  VR.APPT_STATUS = {
    requested: 'In attesa di conferma', confirmed: 'Confermato', done: 'Svolto',
    no_show: 'Non presentato', cancelled: 'Annullato'
  };

  // Mostra il valore di un campo di modulo in sola lettura
  VR.moduleValueText = (fd, v) => {
    if (fd.type === 'checkbox') return v ? 'Sì' : 'No';
    if (fd.type === 'date') return VR.fmtDate(v);
    if (fd.type === 'number') return String(v).replace('.', ',') + (fd.unit ? ' ' + fd.unit : '');
    return String(v);
  };
})();
