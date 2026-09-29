// =============================================================
//  VETROOM 2 — Promemoria e richiami (scheda animale, lato clinica)
// =============================================================
(function () {
  const VR = window.VR;
  VR.REMINDER_PRESETS = ['Vaccino annuale', 'Richiamo vaccinale', 'Antiparassitario', 'Controllo', 'Esami del sangue', 'Detartrasi'];

  VR.remindersWidget = async ({ el, petId, clinicId, clientId, clinicName, petName, client, userId }) => {
    const today = new Date().toLocaleDateString('sv-SE');
    el.innerHTML = `
      <div class="rem-list"></div>
      <p class="muted rem-empty" hidden style="margin:0 0 10px">Nessun promemoria.</p>
      <form class="rem-add" novalidate>
        <input type="text" class="rem-title" list="remPresets" placeholder="Es. Vaccino annuale" aria-label="Cosa">
        <datalist id="remPresets">${VR.REMINDER_PRESETS.map((p) => `<option value="${VR.esc(p)}">`).join('')}</datalist>
        <input type="date" class="rem-date" aria-label="Scadenza">
        <button class="btn btn-ghost btn-small" type="submit">Aggiungi promemoria</button>
      </form>
      <div class="rem-msg" hidden></div>`;
    const list = el.querySelector('.rem-list'), msg = el.querySelector('.rem-msg');

    const load = async () => {
      const { data, error } = await VR.sb.from('reminders').select('*').eq('pet_id', petId).eq('clinic_id', clinicId)
        .order('status').order('due_date');
      if (error) { VR.say(msg, VR.errorText(error), 'error'); return; }
      const rows = data || [];
      (rows.length ? VR.hide : VR.show)(el.querySelector('.rem-empty'));
      list.innerHTML = rows.map((r) => {
        const late = r.status === 'open' && r.due_date < today;
        const text = VR.reminderMessage({ pet: petName, title: r.title, due: r.due_date, clinic: clinicName });
        return `<div class="rem-row ${r.status !== 'open' ? 'is-done' : ''}" data-id="${r.id}">
          <div class="rem-main"><strong>${VR.esc(r.title)}</strong>
            <span class="${late ? 'late' : ''}">${late ? 'Scaduto il ' : 'Entro il '}${new Date(r.due_date + 'T00:00:00').toLocaleDateString('it-IT')}${r.status === 'done' ? ' · fatto' : ''}${r.notified_at ? ' · avvisato il ' + new Date(r.notified_at).toLocaleDateString('it-IT') : ''}</span></div>
          ${r.status === 'open' ? `<div class="rem-acts">
            ${client?.phone ? `<a class="btn btn-ghost btn-small" data-notify href="${VR.esc(VR.waLink(client.phone, text))}" target="_blank" rel="noopener">WhatsApp</a>` : ''}
            ${client?.email ? `<a class="btn btn-ghost btn-small" data-notify href="${VR.esc(VR.mailLink(client.email, 'Promemoria per ' + petName, text))}">Email</a>` : ''}
            <button class="btn btn-primary btn-small" type="button" data-done>Fatto</button>
          </div>` : `<button class="btn btn-ghost btn-small" type="button" data-del>Elimina</button>`}
        </div>`;
      }).join('');
    };

    list.addEventListener('click', async (ev) => {
      const row = ev.target.closest('.rem-row'); if (!row) return;
      const id = row.dataset.id;
      if (ev.target.closest('[data-notify]')) { await VR.sb.from('reminders').update({ notified_at: new Date().toISOString() }).eq('id', id); setTimeout(load, 500); return; }
      let res;
      if (ev.target.closest('[data-done]')) res = await VR.sb.from('reminders').update({ status: 'done' }).eq('id', id);
      else if (ev.target.closest('[data-del]')) { if (!confirm('Eliminare questo promemoria?')) return; res = await VR.sb.from('reminders').delete().eq('id', id); }
      else return;
      if (res.error) VR.say(msg, VR.errorText(res.error), 'error'); else load();
    });

    el.querySelector('.rem-add').addEventListener('submit', async (ev) => {
      ev.preventDefault(); VR.hide(msg);
      const title = el.querySelector('.rem-title').value.trim(), due = el.querySelector('.rem-date').value;
      if (!title || !due) { VR.say(msg, 'Indica cosa ricordare e la data di scadenza.', 'error'); return; }
      const kind = /vaccin/i.test(title) ? 'vaccino' : /controllo|esami/i.test(title) ? 'controllo' : 'richiamo';
      const { error } = await VR.sb.from('reminders').insert({ clinic_id: clinicId, pet_id: petId, client_id: clientId || null, title, due_date: due, kind, created_by: userId });
      if (error) { VR.say(msg, VR.errorText(error), 'error'); return; }
      el.querySelector('.rem-title').value = ''; el.querySelector('.rem-date').value = '';
      load();
    });
    await load();
    return { reload: load };
  };

  // Dalla scheda compilata: i campi data "richiamo / prossimo…" diventano promemoria
  VR.syncVisitReminders = async ({ visitId, clinicId, petId, clientId, fields, values, formName, userId }) => {
    if (!Array.isArray(fields)) return;
    const vaccine = values.vaccino_nome;
    for (const f of fields) {
      if (f.type !== 'date') continue;
      const isRem = f.key === 'vaccino_richiamo' || /richiam|prossim|scadenza vaccin|rinnovo/i.test(f.label || '');
      if (!isRem || !values[f.key]) continue;
      const title = f.key === 'vaccino_richiamo' && vaccine ? `Richiamo: ${vaccine}` : `${f.label}${formName ? ' (' + formName + ')' : ''}`;
      await VR.sb.from('reminders').upsert({
        clinic_id: clinicId, pet_id: petId, client_id: clientId || null, visit_id: visitId, source_key: f.key,
        title, due_date: values[f.key], kind: /vaccin/i.test(title) || f.key.startsWith('vaccino') ? 'vaccino' : 'richiamo', created_by: userId
      }, { onConflict: 'visit_id,source_key' });
    }
  };
})();
