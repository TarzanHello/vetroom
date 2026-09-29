// =============================================================
//  VETROOM 2 — Moduli di visita (schede personalizzabili)
//  Un modulo è un elenco ordinato di "blocchi":
//   { key, label, type, width: 'full'|'half', print: true|false, options?, unit? }
//   type: section | text | textarea | number | select | checkbox | date
// =============================================================
(function () {
  const VR = window.VR;

  VR.FIELD_TYPES = {
    text: 'Testo breve', textarea: 'Testo lungo', number: 'Numero',
    select: 'Scelta da elenco', checkbox: 'Sì / No', date: 'Data', section: 'Titolo di sezione'
  };

  const f = (key, label, type, section, extra = {}) => ({ key, label, type, section, ...extra });

  // Libreria dei campi pronti (dalla versione precedente di Vetroom)
  VR.FIELD_LIBRARY = [
    f('esame_obiettivo', 'Esame obiettivo', 'textarea', 'Dati visita'),
    f('regime', 'Regime', 'select', 'Dati visita', { options: ['Ambulatoriale', 'Day hospital', 'Degenza', 'Urgenza', 'Altro'] }),
    f('temperature_c', 'Temperatura', 'number', 'Parametri vitali', { unit: '°C' }),
    f('heart_rate_bpm', 'Frequenza cardiaca', 'number', 'Parametri vitali', { unit: 'bpm' }),
    f('resp_rate_bpm', 'Frequenza respiratoria', 'number', 'Parametri vitali', { unit: 'atti/min' }),
    f('bcs', 'Body Condition Score', 'select', 'Stato generale', { options: ['1/9', '2/9', '3/9', '4/9', '5/9', '6/9', '7/9', '8/9', '9/9'] }),
    f('hydration', 'Idratazione', 'select', 'Stato generale', { options: ['Normale', 'Lieve disidratazione', 'Moderata disidratazione', 'Grave disidratazione'] }),
    f('mucous_membranes', 'Mucose', 'select', 'Stato generale', { options: ['Rosa', 'Pallide', 'Iperemiche', 'Cianotiche', 'Itteriche'] }),
    f('crt', 'Tempo di riempimento capillare', 'select', 'Stato generale', { options: ['< 2 s', '2-3 s', '> 3 s'] }),
    f('obj_sensorio', 'Stato del sensorio', 'text', 'Esame obiettivo'),
    f('obj_respirazione', 'Respirazione', 'text', 'Esame obiettivo'),
    f('obj_linfonodi', 'Linfonodi', 'text', 'Esame obiettivo'),
    f('obj_polso_femorale', 'Polso femorale', 'text', 'Esame obiettivo'),
    f('obj_auscultazione_cardiaca', 'Auscultazione cardiaca', 'text', 'Esame obiettivo'),
    f('obj_apparato_respiratorio', 'Apparato respiratorio', 'text', 'Esame obiettivo'),
    f('obj_palpazione_addome', 'Palpazione addominale', 'text', 'Esame obiettivo'),
    f('obj_feci', 'Feci', 'text', 'Esame obiettivo'),
    f('appetite', 'Appetito', 'select', 'Anamnesi', { options: ['Normale', 'Ridotto', 'Assente', 'Aumentato'] }),
    f('vomiting', 'Vomito', 'checkbox', 'Anamnesi'),
    f('diarrhea', 'Diarrea', 'checkbox', 'Anamnesi'),
    f('vaccino_nome', 'Vaccino', 'text', 'Vaccinazioni'),
    f('vaccino_lotto', 'Lotto', 'text', 'Vaccinazioni'),
    f('vaccino_scadenza', 'Scadenza del lotto', 'date', 'Vaccinazioni'),
    f('vaccino_richiamo', 'Prossimo richiamo', 'date', 'Vaccinazioni'),
    f('derma_lesioni', 'Lesioni', 'textarea', 'Dermatologia'),
    f('derma_localizzazione', 'Localizzazione', 'text', 'Dermatologia'),
    f('derma_prurito', 'Prurito', 'select', 'Dermatologia', { options: ['Assente', 'Lieve', 'Moderato', 'Grave'] }),
    f('gastro_alimentazione', 'Alimentazione', 'text', 'Gastroenterologia'),
    f('gastro_feci', 'Feci', 'textarea', 'Gastroenterologia'),
    f('postop_ferita', 'Condizioni della ferita', 'textarea', 'Post-operatorio'),
    f('postop_punti', 'Punti di sutura presenti', 'checkbox', 'Post-operatorio'),
    f('postop_collare', 'Collare elisabettiano', 'checkbox', 'Post-operatorio'),
    f('obj_polso_tarsale', 'Polso tarsale', 'text', 'Esame obiettivo'),
    f('obj_mucose', 'Mucose (descrizione)', 'text', 'Esame obiettivo'),
    f('note_finali', 'Note', 'textarea', 'Dati visita'),
    ...['dx', 'sx'].flatMap((e) => {
      const eye = e === 'dx' ? 'OD' : 'OS';
      return [
        f(`oft_minaccia_${e}`, `Riflesso alla minaccia ${eye}`, 'text', 'Oftalmologia'),
        f(`oft_palpebrale_${e}`, `Riflesso palpebrale ${eye}`, 'text', 'Oftalmologia'),
        f(`oft_corneale_${e}`, `Riflesso corneale ${eye}`, 'text', 'Oftalmologia'),
        f(`oft_camera_${e}`, `Camera anteriore ${eye}`, 'text', 'Oftalmologia'),
        f(`oft_iride_${e}`, `Iride ${eye}`, 'text', 'Oftalmologia'),
        f(`oft_vitreo_${e}`, `Vitreo ${eye}`, 'text', 'Oftalmologia'),
        f(`oft_palpebre_${e}`, `Palpebre ${eye}`, 'text', 'Oftalmologia'),
        f(`oft_congiuntiva_${e}`, `Congiuntiva ${eye}`, 'text', 'Oftalmologia'),
        f(`oft_cornea_${e}`, `Cornea ${eye}`, 'text', 'Oftalmologia'),
        f(`oft_cristallino_${e}`, `Cristallino ${eye}`, 'text', 'Oftalmologia'),
        f(`oft_fondo_${e}`, `Fondo dell'occhio ${eye}`, 'text', 'Oftalmologia'),
        f(`oft_rpupillare_${e}`, `Riflesso pupillare ${eye}`, 'text', 'Oftalmologia'),
        f(`oft_schirmer_${e}`, `Test di Schirmer ${eye}`, 'number', 'Oftalmologia', { unit: 'mm/min' }),
        f(`oft_fluoresceina_${e}`, `Fluoresceina ${eye}`, 'select', 'Oftalmologia', { options: ['Negativa', 'Positiva'] }),
        f(`oft_iop_${e}`, `Pressione intraoculare ${eye}`, 'number', 'Oftalmologia', { unit: 'mmHg' })
      ];
    })
  ];

  const lib = (key, width = 'full') => {
    const x = VR.FIELD_LIBRARY.find((l) => l.key === key);
    const { section, ...rest } = x;
    return { ...rest, width, print: true };
  };
  const sec = (label) => ({ key: 's_' + Math.random().toString(36).slice(2, 8), label, type: 'section', width: 'full', print: true });

  // Modelli di partenza per un nuovo modulo
  VR.MODULE_TEMPLATES = {
    vuoto: { name: 'Modulo vuoto', fields: () => [] },
    clinica: {
      name: 'Visita clinica',
      fields: () => [
        lib('regime', 'half'),
        sec('Esame obiettivo'),
        lib('obj_sensorio', 'half'), lib('mucous_membranes', 'half'),
        lib('crt', 'half'), lib('hydration', 'half'),
        lib('obj_linfonodi', 'half'), lib('obj_respirazione', 'half'),
        lib('obj_auscultazione_cardiaca', 'half'), lib('obj_apparato_respiratorio', 'half'),
        lib('obj_polso_femorale', 'half'), lib('obj_polso_tarsale', 'half'),
        lib('obj_palpazione_addome', 'half'), lib('obj_feci', 'half'),
        lib('esame_obiettivo', 'full'),
        sec('Parametri vitali'),
        lib('temperature_c', 'half'), lib('heart_rate_bpm', 'half'),
        lib('resp_rate_bpm', 'half'), lib('bcs', 'half'),
        sec('Note'),
        lib('note_finali', 'full')
      ]
    },
    oftalmologica: {
      name: 'Visita oftalmologica',
      fields: () => {
        const pair = (k) => [lib(`oft_${k}_dx`, 'half'), lib(`oft_${k}_sx`, 'half')];
        return [
          sec('Riflessi'), ...['minaccia', 'rpupillare', 'palpebrale', 'corneale'].flatMap(pair),
          sec('Esame degli annessi'), ...['palpebre', 'congiuntiva', 'schirmer', 'fluoresceina'].flatMap(pair),
          sec("Esame dell'occhio"), ...['cornea', 'camera', 'iride', 'cristallino', 'vitreo', 'fondo', 'iop'].flatMap(pair),
          sec('Note'), lib('note_finali', 'full')
        ];
      }
    },
    buona_salute: {
      name: 'Certificato di buona salute', doc_title: 'Certificato di buona salute',
      fields: () => [
        sec('Esame clinico'),
        lib('temperature_c', 'half'), lib('heart_rate_bpm', 'half'), lib('mucous_membranes', 'half'), lib('bcs', 'half'),
        { key: 'c_esito', label: 'Esito', type: 'select', options: ['In buona salute', 'Idoneo al viaggio', 'Idoneo con prescrizioni'], width: 'full', print: true },
        { key: 'c_dichiarazione', label: 'Dichiarazione', type: 'textarea', width: 'full', print: true }
      ]
    },
    consenso: {
      name: 'Consenso informato', doc_title: 'Consenso informato',
      fields: () => [
        sec('Procedura'),
        { key: 'c_procedura', label: 'Procedura proposta', type: 'textarea', width: 'full', print: true },
        { key: 'c_rischi', label: 'Rischi e possibili complicanze illustrati', type: 'textarea', width: 'full', print: true },
        { key: 'c_anestesia', label: 'Richiede anestesia/sedazione', type: 'checkbox', width: 'half', print: true },
        { key: 'c_costo', label: 'Preventivo indicativo (€)', type: 'number', width: 'half', print: true },
        sec('Consenso del proprietario'),
        { key: 'c_consenso', label: 'Il proprietario, informato, acconsente alla procedura', type: 'checkbox', width: 'full', print: true },
        { key: 'c_firma', label: 'Firma del proprietario (su carta)', type: 'text', width: 'full', print: true }
      ]
    },
    vaccinazione: {
      name: 'Vaccinazione', doc_title: 'Certificato di vaccinazione',
      fields: () => [sec('Vaccinazione'), lib('vaccino_nome', 'half'), lib('vaccino_lotto', 'half'),
                     lib('vaccino_scadenza', 'half'), lib('vaccino_richiamo', 'half'), lib('temperature_c', 'half')]
    }
  };

  VR.newFieldKey = () => 'c_' + Math.random().toString(36).slice(2, 10);

  VR.loadModules = async (clinicId) => {
    const { data, error } = await VR.sb.from('visit_forms').select('*')
      .eq('clinic_id', clinicId).order('name');
    if (error) throw error;
    return data || [];
  };

  // Mostra i campi di un modulo dentro un contenitore del modulo della visita
  VR.renderModuleFields = (el, fields, values = {}, disabled = false) => {
    let html = '<div class="grid">';
    for (const fd of fields) {
      const id = 'mf_' + fd.key;
      const v = values[fd.key];
      const span = fd.width === 'half' ? '' : ' class="s12"';
      const dis = disabled ? ' disabled' : '';
      const lab = VR.esc(fd.label) + (fd.unit ? ` <span class="muted">(${VR.esc(fd.unit)})</span>` : '');
      if (fd.type === 'section') { html += `<div class="s12"><h3 class="mod-section">${VR.esc(fd.label)}</h3></div>`; continue; }
      let input;
      switch (fd.type) {
        case 'textarea': input = `<textarea id="${id}" data-key="${fd.key}" data-dictate rows="3"${dis}>${VR.esc(v ?? '')}</textarea>`; break;
        case 'number': input = `<input type="number" step="any" id="${id}" data-key="${fd.key}" value="${VR.esc(v ?? '')}" inputmode="decimal"${dis}>`; break;
        case 'date': input = `<input type="date" id="${id}" data-key="${fd.key}" value="${VR.esc(v ?? '')}"${dis}>`; break;
        case 'checkbox': input = `<label class="check"><input type="checkbox" id="${id}" data-key="${fd.key}" ${v ? 'checked' : ''}${dis}> ${VR.esc(fd.label)}</label>`; break;
        case 'select': input = `<select id="${id}" data-key="${fd.key}"${dis}><option value=""></option>${(fd.options || []).map((o) => `<option ${o === v ? 'selected' : ''}>${VR.esc(o)}</option>`).join('')}${v && !(fd.options || []).includes(v) ? `<option selected>${VR.esc(v)}</option>` : ''}</select>`; break;
        default: input = `<input type="text" id="${id}" data-key="${fd.key}" value="${VR.esc(v ?? '')}"${dis}>`;
      }
      html += fd.type === 'checkbox'
        ? `<div${span} style="align-self:end">${input}</div>`
        : `<div${span}><label for="${id}">${lab}</label>${input}</div>`;
    }
    el.innerHTML = html + '</div>';
  };

  VR.readModuleFields = (el) => {
    const out = {};
    el.querySelectorAll('[data-key]').forEach((inp) => {
      if (inp.type === 'checkbox') { if (inp.checked) out[inp.dataset.key] = true; return; }
      const v = inp.value.trim();
      if (v !== '') out[inp.dataset.key] = inp.type === 'number' ? Number(v.replace(',', '.')) : v;
    });
    return out;
  };
})();
