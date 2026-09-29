// =============================================================
//  VETROOM 2 — Documenti e allegati (riutilizzabile)
//  Uso: VR.docsWidget({ el, petId, clinicId, visitId, userId, canEdit })
//   - con visitId: mostra e carica gli allegati di quella visita
//   - senza visitId: mostra tutti i documenti dell'animale
// =============================================================
(function () {
  const VR = window.VR;
  const MAX_MB = 15;

  const fmtSize = (b) => !b ? '' : b < 1024 * 1024 ? Math.max(1, Math.round(b / 1024)) + ' KB' : (b / 1048576).toFixed(1) + ' MB';
  const fmtDate = (d) => d ? new Date(d).toLocaleDateString('it-IT') : '';
  const safeName = (n) => (n || 'file').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-zA-Z0-9._-]+/g, '-').replace(/-+/g, '-').slice(-80);

  VR.docsWidget = async ({ el, petId, clinicId, visitId = null, userId, canEdit = true }) => {
    el.innerHTML = `
      <div class="docs-list"></div>
      <p class="muted docs-empty" hidden style="margin:0">Nessun documento.</p>
      ${canEdit ? `
      <div class="actions-bar" style="margin-top:1rem">
        <label class="btn btn-ghost btn-small file-btn">
          <input type="file" multiple class="docs-file">
          <span class="docs-label">Carica documenti</span>
        </label>
        <span class="hint-small" style="margin:0">PDF, foto, esami… fino a ${MAX_MB} MB per file.</span>
      </div>` : ''}
      <div class="docs-msg" hidden></div>`;

    const list = el.querySelector('.docs-list');
    const empty = el.querySelector('.docs-empty');
    const msg = el.querySelector('.docs-msg');

    const load = async () => {
      let q = VR.sb.from('documents')
        .select('id, clinic_id, visit_id, storage_path, original_name, title, mime_type, file_size, visible_to_owner, doc_role, created_at, visits(visit_date, title)')
        .eq('pet_id', petId).order('created_at', { ascending: false });
      if (visitId) q = q.eq('visit_id', visitId);
      const { data, error } = await q;
      if (error) { VR.say(msg, VR.errorText(error), 'error'); return; }
      const docs = data || [];
      (docs.length ? VR.hide : VR.show)(empty);
      list.innerHTML = docs.map((d) => {
        const mine = d.clinic_id === clinicId;
        const from = !d.clinic_id ? 'Caricato dal proprietario'
          : (!visitId && d.visits ? `Visita del ${fmtDate(d.visits.visit_date)}` : '');
        return `
        <div class="doc-row" data-id="${d.id}">
          <div class="doc-main">
            <button type="button" class="doc-open" data-path="${VR.esc(d.storage_path)}">${VR.esc(d.title || d.original_name || 'Documento')}</button>${d.doc_role === 'visit_pdf_final' ? ' <span class="chip" style="align-self:flex-start">Referto definitivo</span>' : ''}
            <span>${VR.esc([fmtDate(d.created_at), fmtSize(d.file_size), from].filter(Boolean).join(' · '))}</span>
          </div>
          ${canEdit && mine ? `
          <div class="doc-actions">
            <button type="button" class="btn btn-ghost btn-small doc-rename">Rinomina</button>
            ${d.doc_role === 'visit_pdf_final' ? '' : '<button type="button" class="btn btn-danger btn-small doc-del">Elimina</button>'}
          </div>` : ''}
        </div>`;
      }).join('');
      list._docs = docs;
    };

    // Apri, rinomina, elimina
    list.addEventListener('click', async (ev) => {
      const row = ev.target.closest('.doc-row');
      if (!row) return;
      const doc = (list._docs || []).find((d) => d.id === row.dataset.id);
      if (!doc) return;

      if (ev.target.closest('.doc-open')) {
        const w = window.open('', '_blank');
        const url = await VR.fileUrl(doc.storage_path);
        if (url && w) w.location = url;
        else { if (w) w.close(); VR.say(msg, 'Impossibile aprire il documento.', 'error'); }
      }

      if (ev.target.closest('.doc-rename')) {
        const t = prompt('Nuovo nome del documento:', doc.title || doc.original_name || '');
        if (t === null || !t.trim()) return;
        const { error } = await VR.sb.from('documents').update({ title: t.trim() }).eq('id', doc.id);
        if (error) VR.say(msg, VR.errorText(error), 'error'); else load();
      }

      if (ev.target.closest('.doc-del')) {
        if (!confirm(`Eliminare "${doc.title || doc.original_name}"?`)) return;
        const { error } = await VR.sb.from('documents').delete().eq('id', doc.id);
        if (error) { VR.say(msg, VR.errorText(error), 'error'); return; }
        VR.sb.storage.from('pet-files').remove([doc.storage_path]);
        load();
      }
    });

    // Caricamento
    const input = el.querySelector('.docs-file');
    if (input) input.addEventListener('change', async () => {
      const files = [...input.files];
      input.value = '';
      if (!files.length) return;
      VR.hide(msg);
      const label = el.querySelector('.docs-label');
      let done = 0; const errors = [];
      for (const f of files) {
        label.textContent = `Caricamento ${done + 1} di ${files.length}…`;
        try {
          if (f.size > MAX_MB * 1048576) throw new Error(`supera ${MAX_MB} MB`);
          const path = `${petId}/${crypto.randomUUID()}-${safeName(f.name)}`;
          const up = await VR.sb.storage.from('pet-files').upload(path, f, { contentType: f.type || 'application/octet-stream' });
          if (up.error) throw up.error;
          const { error } = await VR.sb.from('documents').insert({
            pet_id: petId, clinic_id: clinicId, visit_id: visitId, uploaded_by: userId,
            storage_path: path, original_name: f.name, title: f.name.replace(/\.[^.]+$/, ''),
            mime_type: f.type || null, file_size: f.size, doc_role: 'attachment'
          });
          if (error) { VR.sb.storage.from('pet-files').remove([path]); throw error; }
          done++;
        } catch (e) { errors.push(`${f.name}: ${VR.errorText(e)}`); }
      }
      label.textContent = 'Carica documenti';
      if (errors.length) VR.say(msg, 'Non caricati — ' + errors.join(' / '), 'error');
      load();
    });

    await load();
    return { reload: load };
  };
})();
