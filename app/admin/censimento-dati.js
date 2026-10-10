// =============================================================
//  VETROOM · CENSIMENTO — pulizia dei dati di OpenStreetMap
//  Usato da admin/censimento.html (window.CZ). Nessun accesso alla rete.
// =============================================================
(function (root) {
  const CZ = {};

  // ---------- testo ----------
  const first = (tags, ...keys) => { for (const k of keys) { const v = String(tags[k] || '').trim(); if (v) return v; } return null; };
  const one = (v) => (v ? String(v).split(';')[0].trim() || null : null);
  const fold = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[’`]/g, "'").replace(/\s+/g, ' ').trim();
  CZ.fold = fold;

  // Nomi: spazi in ordine, maiuscola iniziale, niente TUTTO MAIUSCOLO (le sigle corte restano)
  const MINUSCOLE = new Set(['di', 'del', 'della', 'dello', 'dei', 'degli', 'delle', 'e', 'a', 'al', 'alla', 'da', 'in', 'il', 'la', 'lo', 'le', 'per', 'con', 'su', 'sul', 'sulla']);
  CZ.nome = (v) => {
    if (!v) return null;
    let s = String(v).replace(/\s+/g, ' ').trim();
    if (!s) return null;
    if (s.length > 5 && s === s.toUpperCase() && /[A-Z]{3}/.test(s)) {
      s = s.toLowerCase().split(' ').map((w, i) => (i && MINUSCOLE.has(w) ? w : w.charAt(0).toUpperCase() + w.slice(1))).join(' ');
    }
    if (/^[a-zà-ÿ]/.test(s)) s = s.charAt(0).toUpperCase() + s.slice(1);
    return s;
  };

  // Telefono: il primo numero, in formato +39…; scarta quelli impossibili
  CZ.tel = (v) => {
    if (!v) return null;
    const part = String(v).split(/\s*(?:[;,/|]|\s[-–]\s|\so\s|\se\s|\s{2,})\s*/)[0].trim();
    if (!part) return null;
    let d = part.replace(/\D/g, '');
    let intl = part.startsWith('+');
    if (d.startsWith('00')) { d = d.slice(2); intl = true; }
    if (!intl) d = d.startsWith('39') && d.length >= 11 ? d : '39' + d;
    if (d.startsWith('39')) {
      let naz = d.slice(2);
      if (naz.length > 11) {
        // due numeri attaccati: si tiene il cellulare iniziale (10 cifre), altrimenti si scarta
        if (/^3\d{9}/.test(naz)) naz = naz.slice(0, 10); else return null;
      }
      return naz.length >= 6 ? '+39' + naz : null;
    }
    return d.length >= 8 && d.length <= 15 ? '+' + d : null;
  };
  CZ.mail = (v) => { v = one(v); if (!v) return null; v = v.toLowerCase().replace(/^mailto:/, '').trim(); return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v) ? v : null; };
  CZ.sito = (v) => {
    v = one(v); if (!v || /\s/.test(v)) return null;
    if (!/^https?:\/\//i.test(v)) v = 'https://' + v;
    const m = v.match(/^(https?:\/\/)([^/?#]+)(.*)$/i);
    if (!m || !/\.[a-z]{2,}$/i.test(m[2])) return null;
    return (m[1].toLowerCase() + m[2].toLowerCase() + m[3]).replace(/\/+$/, '');
  };
  const sigla = (v) => { v = String(v || '').trim().toUpperCase(); return /^[A-Z]{2}$/.test(v) && SIGLE.has(v) ? v : null; };
  CZ.sigla = sigla;

  // ---------- province ----------
  const PROV = {
    AG: 'Agrigento', AL: 'Alessandria', AN: 'Ancona', AO: 'Aosta', AR: 'Arezzo', AP: 'Ascoli Piceno', AT: 'Asti', AV: 'Avellino',
    BA: 'Bari', BT: 'Barletta-Andria-Trani', BL: 'Belluno', BN: 'Benevento', BG: 'Bergamo', BI: 'Biella', BO: 'Bologna', BZ: 'Bolzano',
    BS: 'Brescia', BR: 'Brindisi', CA: 'Cagliari', CL: 'Caltanissetta', CB: 'Campobasso', CE: 'Caserta', CT: 'Catania', CZ: 'Catanzaro',
    CH: 'Chieti', CO: 'Como', CS: 'Cosenza', CR: 'Cremona', KR: 'Crotone', CN: 'Cuneo', EN: 'Enna', FM: 'Fermo', FE: 'Ferrara',
    FI: 'Firenze', FG: 'Foggia', FC: 'Forlì-Cesena', FR: 'Frosinone', GE: 'Genova', GO: 'Gorizia', GR: 'Grosseto', IM: 'Imperia',
    IS: 'Isernia', AQ: "L'Aquila", SP: 'La Spezia', LT: 'Latina', LE: 'Lecce', LC: 'Lecco', LI: 'Livorno', LO: 'Lodi', LU: 'Lucca',
    MC: 'Macerata', MN: 'Mantova', MS: 'Massa-Carrara', MT: 'Matera', ME: 'Messina', MI: 'Milano', MO: 'Modena', MB: 'Monza e della Brianza',
    NA: 'Napoli', NO: 'Novara', NU: 'Nuoro', OR: 'Oristano', PD: 'Padova', PA: 'Palermo', PR: 'Parma', PV: 'Pavia', PG: 'Perugia',
    PU: 'Pesaro e Urbino', PE: 'Pescara', PC: 'Piacenza', PI: 'Pisa', PT: 'Pistoia', PN: 'Pordenone', PZ: 'Potenza', PO: 'Prato',
    RG: 'Ragusa', RA: 'Ravenna', RC: 'Reggio Calabria', RE: 'Reggio Emilia', RI: 'Rieti', RN: 'Rimini', RM: 'Roma', RO: 'Rovigo',
    SA: 'Salerno', SS: 'Sassari', SV: 'Savona', SI: 'Siena', SR: 'Siracusa', SO: 'Sondrio', SU: 'Sud Sardegna', TA: 'Taranto',
    TE: 'Teramo', TR: 'Terni', TO: 'Torino', TP: 'Trapani', TN: 'Trento', TV: 'Treviso', TS: 'Trieste', UD: 'Udine', VA: 'Varese',
    VE: 'Venezia', VB: 'Verbano-Cusio-Ossola', VC: 'Vercelli', VR: 'Verona', VV: 'Vibo Valentia', VI: 'Vicenza', VT: 'Viterbo'
  };
  const SIGLE = new Set(Object.keys(PROV));
  CZ.PROVINCE = PROV;
  const PER_NOME = new Map();
  const chiave = (s) => fold(s).replace(/[-–]/g, ' ').replace(/[^a-z' ]/g, '').replace(/\s+/g, ' ').trim();
  Object.entries(PROV).forEach(([k, nome]) => PER_NOME.set(chiave(nome), k));
  [['roma capitale', 'RM'], ['aquila', 'AQ'], ['l aquila', 'AQ'], ['spezia', 'SP'], ['la spezia', 'SP'], ['alto adige', 'BZ'], ['sudtirol', 'BZ'], ['bolzano bozen', 'BZ'], ['trentino', 'TN'], ['valle d aosta', 'AO'],
    ['forli cesena', 'FC'], ['monza e brianza', 'MB'], ['monza brianza', 'MB'], ['pesaro urbino', 'PU'], ['reggio di calabria', 'RC'],
    ['reggio nell emilia', 'RE'], ['massa carrara', 'MS'], ['barletta andria trani', 'BT'], ['verbano cusio ossola', 'VB']].forEach(([n, k]) => PER_NOME.set(n, k));
  // "Città metropolitana di Napoli", "Provincia di Lecce", "Libero consorzio comunale di Ragusa", …
  CZ.siglaDaNome = (nome) => {
    if (!nome) return null;
    let k = chiave(nome).replace(/'/g, ' ').replace(/\s+/g, ' ');
    k = k.replace(/^(citta metropolitana|provincia autonoma|provincia|libero consorzio comunale|ente di decentramento regionale|unione territoriale intercomunale)\s+(di|del|della|dell)\s+/, '');
    for (const tentativo of [k, k.split(' / ')[0], k.split(' - ')[0]]) {
      if (PER_NOME.has(tentativo)) return PER_NOME.get(tentativo);
      const conApostrofo = tentativo.replace(/^l /, "l'");
      if (PER_NOME.has(conApostrofo)) return PER_NOME.get(conApostrofo);
    }
    for (const [n, s] of PER_NOME) if (k.startsWith(n + ' ') || k.endsWith(' ' + n)) return s;
    return null;
  };

  // Dai confini restituiti da OSM (admin_level 4 regione, 6 provincia, 8 comune)
  CZ.luogoDaAree = (aree) => {
    const o = {};
    for (const t of aree) {
      const nome = t['name:it'] || t.name || null;
      if (t.admin_level === '8' && !o.comune) o.comune = nome;
      if (t.admin_level === '6' && !o.provincia) {
        const iso = String(t['ISO3166-2'] || '').match(/^IT-([A-Z]{2})$/);
        o.provincia = (iso && sigla(iso[1])) || sigla(t.ref) || sigla(t.short_name) || CZ.siglaDaNome(t['name:it']) || CZ.siglaDaNome(t.name);
      }
      if (t.admin_level === '4' && !o.regione) o.regione = nome;
    }
    if (!o.provincia && o.regione && /aosta/i.test(o.regione)) o.provincia = 'AO';
    return o;
  };

  // ---------- strutture che forse non sono veterinarie ----------
  const PAROLE = [
    [/\b(para)?farmaci[ae]\b/, 'sembra una farmacia'],
    [/toelett|grooming|\bdog ?(spa|wash)\b/, 'sembra una toelettatura'],
    [/\b(canile|gattile|rifugio|oasi felina)\b/, 'sembra un rifugio per animali'],
    [/allevament|\bcattery\b|\bkennel\b/, 'sembra un allevamento'],
    [/\bpensione\b/, 'sembra una pensione per animali'],
    [/\bpet ?shop\b|\bnegozio\b|zoocenter|\bagri ?zoo\b|zoolife|maxi ?zoo|arcaplanet|isola dei tesori|\bacquari|mangim|\bcasa del cane\b/, 'sembra un negozio'],
    [/recupero (della )?fauna|ripopolamento|\bcras\b|\blipu\b|\bwwf\b/, 'sembra un centro fauna o un\'associazione'],
    [/\baddestr|educazione cinofila|centro cinofilo/, 'sembra un centro cinofilo']
  ];
  CZ.avviso = (tags, nome) => {
    const t = tags || {}, motivi = [];
    if (t.shop) motivi.push(`segnata anche come negozio (shop=${t.shop})`);
    if (t.amenity === 'animal_shelter' || t.animal_shelter) motivi.push('segnata come rifugio per animali');
    if (t.amenity === 'animal_boarding' || t.animal_boarding) motivi.push('segnata come pensione per animali');
    if (t.amenity === 'animal_breeding' || t.animal_breeding) motivi.push('segnata come allevamento');
    if (t.amenity === 'pharmacy' || t.healthcare === 'pharmacy') motivi.push('segnata come farmacia');
    if (t.tourism === 'zoo') motivi.push('segnata come zoo');
    const n = fold(nome);
    for (const [re, txt] of PAROLE) if (re.test(n) && !motivi.includes(txt)) motivi.push(txt);
    return motivi.length ? motivi.join(' · ') : null;
  };

  // ---------- da elemento OSM a riga del database ----------
  CZ.riga = (el) => {
    const t = el.tags || {};
    const lat = el.type === 'node' ? el.lat : el.center?.lat, lon = el.type === 'node' ? el.lon : el.center?.lon;
    if (lat == null || lon == null) return null;
    const nome = CZ.nome(first(t, 'name', 'official_name', 'brand'));
    return {
      osm_type: el.type, osm_id: el.id, nome,
      via: first(t, 'addr:street', 'addr:place'), civico: first(t, 'addr:housenumber'), cap: first(t, 'addr:postcode'),
      comune: first(t, 'addr:city'), provincia: sigla(first(t, 'addr:province')), regione: null,
      telefono: CZ.tel(first(t, 'phone', 'contact:phone', 'contact:mobile', 'mobile')),
      email: CZ.mail(first(t, 'email', 'contact:email')),
      sito: CZ.sito(first(t, 'website', 'contact:website', 'url')),
      orari: first(t, 'opening_hours'),
      avviso: CZ.avviso(t, nome),
      lat, lon, tags: t, osm_versione: el.version ?? null, osm_modificato_il: el.timestamp ?? null, localizzata: false
    };
  };

  // ---------- possibili doppioni: entro 40 metri, oppure stesso telefono ----------
  const metri = (a, b) => {
    const R = 6371000, r = Math.PI / 180;
    const x = (b.lon - a.lon) * r * Math.cos(((a.lat + b.lat) / 2) * r), y = (b.lat - a.lat) * r;
    return Math.sqrt(x * x + y * y) * R;
  };
  CZ.doppioni = (rows, soglia = 40) => {
    const out = new Map();
    const add = (a, b, perche) => {
      for (const [x, y] of [[a, b], [b, a]]) {
        const k = x.t + '/' + x.i;
        if (!out.has(k)) out.set(k, []);
        if (!out.get(k).some((d) => d.k === y.t + '/' + y.i)) out.get(k).push({ k: y.t + '/' + y.i, nome: y.nome, perche });
      }
    };
    const s = rows.filter((r) => r.lat != null).slice().sort((a, b) => a.lat - b.lat);
    for (let i = 0; i < s.length; i++) {
      for (let j = i + 1; j < s.length && s[j].lat - s[i].lat < 0.0005; j++) {
        const m = metri(s[i], s[j]);
        if (m <= soglia) add(s[i], s[j], `a ${Math.round(m)} m`);
      }
    }
    const perTel = new Map();
    rows.forEach((r) => { if (r.telefono) { if (!perTel.has(r.telefono)) perTel.set(r.telefono, []); perTel.get(r.telefono).push(r); } });
    for (const g of perTel.values()) for (let i = 0; i < g.length; i++) for (let j = i + 1; j < g.length; j++) add(g[i], g[j], 'stesso telefono');
    return out;
  };

  CZ.metri = metri;
  if (typeof module !== 'undefined' && module.exports) module.exports = CZ; else root.CZ = CZ;
})(typeof window !== 'undefined' ? window : globalThis);
