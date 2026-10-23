/* Vetroom · Chiusure di singole ore o giorni (patch 39, 20 ottobre 2026)
   Usato da clinica/orari.html e clinica/agenda.html.
   Senza la patch 39 tutto resta com'era: load() risponde { ok: false } e le pagine nascondono la funzione. */
(() => {
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const hm = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`;
  const at = (dateStr, timeStr) => { const [y, m, d] = dateStr.split('-').map(Number); const [h, mi] = (timeStr || '00:00').split(':').map(Number); return new Date(y, m - 1, d, h, mi, 0, 0); };
  const missing = (e) => /does not exist|schema cache|relation|could not find/i.test((e && (e.message || e.details)) || '');

  const C = {};

  // Chiusure che toccano il periodo [from, to)
  C.load = async (clinicId, from, to) => {
    const { data, error } = await VR.sb.from('closures').select('id, starts_at, ends_at, note, created_by')
      .eq('clinic_id', clinicId).lt('starts_at', to.toISOString()).gt('ends_at', from.toISOString()).order('starts_at');
    if (error) return { ok: false, missing: missing(error), error, rows: [] };
    return { ok: true, rows: data || [] };
  };

  // Minuti chiusi in un giorno: [[inizio, fine], …] tra 0 e 1440
  C.dayRanges = (rows, day) => {
    const d0 = new Date(day.getFullYear(), day.getMonth(), day.getDate()), d1 = new Date(d0); d1.setDate(d1.getDate() + 1);
    return (rows || []).map((r) => {
      const s = new Date(r.starts_at), e = new Date(r.ends_at);
      if (e <= d0 || s >= d1) return null;
      const a = s <= d0 ? 0 : s.getHours() * 60 + s.getMinutes();
      const b = e >= d1 ? 1440 : e.getHours() * 60 + e.getMinutes();
      return b > a ? [a, b, r] : null;
    }).filter(Boolean);
  };

  // Toglie le parti chiuse da una lista di fasce aperte
  C.subtract = (ranges, cuts) => {
    let out = ranges.map((r) => [r[0], r[1]]);
    cuts.forEach(([cs, ce]) => {
      const next = [];
      out.forEach(([s, e]) => {
        if (ce <= s || cs >= e) { next.push([s, e]); return; }
        if (cs > s) next.push([s, cs]);
        if (ce < e) next.push([ce, e]);
      });
      out = next;
    });
    return out;
  };

  // "gio 22 ott, 16:00–17:00" · "gio 22 ott, tutto il giorno" · "dal 10 al 14 ago"
  C.label = (r) => {
    const s = new Date(r.starts_at), e = new Date(r.ends_at);
    const day = (d) => d.toLocaleDateString('it-IT', { weekday: 'short', day: 'numeric', month: 'short' });
    const allDay = hm(s) === '00:00' && hm(e) === '00:00';
    if (allDay) {
      const last = new Date(e); last.setDate(last.getDate() - 1);
      return ymd(last) === ymd(s) ? `${day(s)}, tutto il giorno`
        : `dal ${s.toLocaleDateString('it-IT', { day: 'numeric', month: 'short' })} al ${last.toLocaleDateString('it-IT', { day: 'numeric', month: 'short' })} compreso`;
    }
    if (ymd(s) === ymd(e)) return `${day(s)}, ${hm(s)}–${hm(e)}`;
    return `${day(s)} ${hm(s)} – ${day(e)} ${hm(e)}`;
  };

  C.remove = async (id) => {
    const { error } = await VR.sb.from('closures').delete().eq('id', id);
    if (error) throw error;
  };

  // ---------- Finestra "Chiudi delle ore" ----------
  let dlg = null;
  const build = () => {
    dlg = document.createElement('dialog');
    dlg.className = 'dlg';
    dlg.id = 'closeDlg';
    dlg.innerHTML = `
      <style>
        #closeDlg .cl-allday { margin: .25rem 0 .75rem; }
        #closeDlg .hint-small { color: var(--muted); font-size: .85rem; }
      </style>
      <form method="dialog" novalidate>
        <h2>Chiudi delle ore</h2>
        <p class="muted" style="margin-top:-.5rem">In queste ore l'agenda le mostra come chiuse e i proprietari non possono chiedere appuntamenti su FurrFinder.</p>
        <div class="grid">
          <div class="s4"><label for="clDate">Giorno</label><input type="date" id="clDate" required></div>
          <div class="s4 cl-time"><label for="clFrom">Dalle</label><input type="time" id="clFrom" step="300"></div>
          <div class="s4 cl-time"><label for="clTo">Alle</label><input type="time" id="clTo" step="300"></div>
          <div class="s4 cl-until" hidden><label for="clUntil">Fino al (compreso)</label><input type="date" id="clUntil"></div>
          <div class="s12"><label class="check cl-allday"><input type="checkbox" id="clAll"> Tutto il giorno (o più giorni)</label></div>
          <div class="s12"><label for="clNote">Nota (facoltativa)</label>
            <input type="text" id="clNote" maxlength="200" placeholder="Es. riunione, formazione, ferie" autocomplete="off">
            <p class="hint-small" style="margin:.35rem 0 0">La vede solo il personale della struttura. Non serve scrivere il motivo di un'assenza.</p></div>
        </div>
        <div id="clMsg" hidden></div>
        <div class="actions-bar" style="margin-top:1.25rem">
          <button class="btn btn-primary" type="submit" id="clSave">Chiudi queste ore</button>
          <button class="btn btn-ghost" type="button" id="clCancel">Annulla</button>
        </div>
      </form>`;
    document.body.appendChild(dlg);
    const all = dlg.querySelector('#clAll');
    all.addEventListener('change', () => {
      dlg.querySelectorAll('.cl-time').forEach((el) => { el.hidden = all.checked; });
      dlg.querySelector('.cl-until').hidden = !all.checked;
    });
    dlg.querySelector('#clCancel').addEventListener('click', () => dlg.close('cancel'));
  };

  // opts: { clinicId, date: 'AAAA-MM-GG', from: 'HH:MM', to: 'HH:MM' } → Promise<true se salvata>
  C.open = (opts) => new Promise((resolve) => {
    if (!dlg) build();
    const $ = (q) => dlg.querySelector(q);
    const today = ymd(new Date());
    $('#clDate').value = opts.date || today;
    $('#clFrom').value = opts.from || '09:00';
    $('#clTo').value = opts.to || '10:00';
    $('#clAll').checked = false; $('#clAll').dispatchEvent(new Event('change'));
    $('#clUntil').value = '';
    $('#clNote').value = '';
    VR.hide($('#clMsg'));
    let confirmFor = null;
    $('#clSave').textContent = 'Chiudi queste ore';
    const form = dlg.querySelector('form');
    const onSubmit = async (ev) => {
      ev.preventDefault();
      const msg = $('#clMsg');
      const d = $('#clDate').value;
      if (!d) { VR.say(msg, 'Scegli il giorno.', 'error'); return; }
      let s, e;
      if ($('#clAll').checked) {
        const u = $('#clUntil').value || d;
        if (u < d) { VR.say(msg, 'La data "fino al" deve essere uguale o successiva al giorno.', 'error'); return; }
        s = at(d, '00:00'); e = at(u, '00:00'); e.setDate(e.getDate() + 1);
        if ((e - s) / 864e5 > 62) { VR.say(msg, 'Si possono chiudere al massimo 62 giorni alla volta.', 'error'); return; }
      } else {
        const f = $('#clFrom').value, t = $('#clTo').value;
        if (!f || !t || t <= f) { VR.say(msg, 'Controlla gli orari: "alle" deve essere dopo "dalle".', 'error'); return; }
        s = at(d, f); e = at(d, t);
      }
      if (e <= new Date()) { VR.say(msg, 'Queste ore sono già passate.', 'error'); return; }
      const key = s.toISOString() + e.toISOString();
      const btn = $('#clSave'); btn.disabled = true;
      // Appuntamenti già presi in quelle ore: restano, ma lo diciamo prima
      if (confirmFor !== key) {
        const { data: ap } = await VR.sb.from('appointments').select('id, starts_at, duration_min, status')
          .eq('clinic_id', opts.clinicId).lt('starts_at', e.toISOString())
          .gte('starts_at', new Date(s.getTime() - 24 * 3600e3).toISOString());
        const clash = (ap || []).filter((a) => !['cancelled', 'no_show'].includes(a.status)
          && new Date(a.starts_at).getTime() + (a.duration_min || 0) * 60000 > s.getTime());
        if (clash.length) {
          confirmFor = key; btn.disabled = false;
          btn.textContent = 'Chiudi lo stesso';
          VR.say(msg, clash.length === 1
            ? 'In queste ore c\'è già 1 appuntamento: resta in agenda e conviene avvisare il cliente. Premi "Chiudi lo stesso" per confermare.'
            : `In queste ore ci sono già ${clash.length} appuntamenti: restano in agenda e conviene avvisare i clienti. Premi "Chiudi lo stesso" per confermare.`, 'info');
          return;
        }
      }
      const note = $('#clNote').value.trim() || null;
      const { error } = await VR.sb.from('closures').insert({ clinic_id: opts.clinicId, starts_at: s.toISOString(), ends_at: e.toISOString(), note });
      btn.disabled = false;
      if (error) {
        VR.say(msg, missing(error) ? 'Le chiusure non sono ancora attive: va eseguito l\'aggiornamento del database (patch 39). Chiedi all\'amministratore della piattaforma.' : VR.errorText(error), 'error');
        return;
      }
      done(true);
    };
    const done = (saved) => {
      form.removeEventListener('submit', onSubmit);
      dlg.removeEventListener('close', onClose);
      if (dlg.open) dlg.close();
      resolve(saved);
    };
    const onClose = () => done(false);
    form.addEventListener('submit', onSubmit);
    dlg.addEventListener('close', onClose);
    dlg.showModal();
  });

  VR.chiusure = C;
})();
