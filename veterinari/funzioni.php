<?php
// =============================================================
//  VETROOM · Sezione /veterinari/ — dati e strumenti comuni
//  Compatibile con PHP 7.4 e successivi.
// =============================================================

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ---------- testo e indirizzi ----------
function vr_fold($s) {
  static $mappa = [
    'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
    'ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','ß'=>'ss','ø'=>'o','æ'=>'ae',
    'À'=>'a','Á'=>'a','Â'=>'a','Ä'=>'a','È'=>'e','É'=>'e','Ê'=>'e','Ë'=>'e','Ì'=>'i','Í'=>'i','Î'=>'i','Ï'=>'i','Ò'=>'o','Ó'=>'o',
    'Ô'=>'o','Ö'=>'o','Ù'=>'u','Ú'=>'u','Û'=>'u','Ü'=>'u','Ç'=>'c','Ñ'=>'n','’'=>"'",'‘'=>"'",'´'=>"'",'`'=>"'"
  ];
  return strtolower(strtr((string)$s, $mappa));
}
function vr_slug($s) {
  $s = preg_replace('/[^a-z0-9]+/', '-', vr_fold($s));
  $s = trim($s, '-');
  return $s === '' ? 'senza-nome' : substr($s, 0, 80);
}
// "Valle d'Aosta / Vallée d'Aoste" -> "Valle d'Aosta"; "Trentino-Alto Adige/Südtirol" -> "Trentino-Alto Adige"
function vr_nome_regione($r) { $p = preg_split('#\s*/\s*#', (string)$r); return trim($p[0]); }

function vr_province() {
  return [
    'AG'=>'Agrigento','AL'=>'Alessandria','AN'=>'Ancona','AO'=>'Aosta','AR'=>'Arezzo','AP'=>'Ascoli Piceno','AT'=>'Asti','AV'=>'Avellino',
    'BA'=>'Bari','BT'=>'Barletta-Andria-Trani','BL'=>'Belluno','BN'=>'Benevento','BG'=>'Bergamo','BI'=>'Biella','BO'=>'Bologna','BZ'=>'Bolzano',
    'BS'=>'Brescia','BR'=>'Brindisi','CA'=>'Cagliari','CL'=>'Caltanissetta','CB'=>'Campobasso','CE'=>'Caserta','CT'=>'Catania','CZ'=>'Catanzaro',
    'CH'=>'Chieti','CO'=>'Como','CS'=>'Cosenza','CR'=>'Cremona','KR'=>'Crotone','CN'=>'Cuneo','EN'=>'Enna','FM'=>'Fermo','FE'=>'Ferrara',
    'FI'=>'Firenze','FG'=>'Foggia','FC'=>'Forlì-Cesena','FR'=>'Frosinone','GE'=>'Genova','GO'=>'Gorizia','GR'=>'Grosseto','IM'=>'Imperia',
    'IS'=>'Isernia','AQ'=>"L'Aquila",'SP'=>'La Spezia','LT'=>'Latina','LE'=>'Lecce','LC'=>'Lecco','LI'=>'Livorno','LO'=>'Lodi','LU'=>'Lucca',
    'MC'=>'Macerata','MN'=>'Mantova','MS'=>'Massa-Carrara','MT'=>'Matera','ME'=>'Messina','MI'=>'Milano','MO'=>'Modena','MB'=>'Monza e della Brianza',
    'NA'=>'Napoli','NO'=>'Novara','NU'=>'Nuoro','OR'=>'Oristano','PD'=>'Padova','PA'=>'Palermo','PR'=>'Parma','PV'=>'Pavia','PG'=>'Perugia',
    'PU'=>'Pesaro e Urbino','PE'=>'Pescara','PC'=>'Piacenza','PI'=>'Pisa','PT'=>'Pistoia','PN'=>'Pordenone','PZ'=>'Potenza','PO'=>'Prato',
    'RG'=>'Ragusa','RA'=>'Ravenna','RC'=>'Reggio Calabria','RE'=>'Reggio Emilia','RI'=>'Rieti','RN'=>'Rimini','RM'=>'Roma','RO'=>'Rovigo',
    'SA'=>'Salerno','SS'=>'Sassari','SV'=>'Savona','SI'=>'Siena','SR'=>'Siracusa','SO'=>'Sondrio','SU'=>'Sud Sardegna','TA'=>'Taranto',
    'TE'=>'Teramo','TR'=>'Terni','TO'=>'Torino','TP'=>'Trapani','TN'=>'Trento','TV'=>'Treviso','TS'=>'Trieste','UD'=>'Udine','VA'=>'Varese',
    'VE'=>'Venezia','VB'=>'Verbano-Cusio-Ossola','VC'=>'Vercelli','VR'=>'Verona','VV'=>'Vibo Valentia','VI'=>'Vicenza','VT'=>'Viterbo'
  ];
}

function vr_nome($s) { return $s['nome'] ?: 'Struttura veterinaria'; }
function vr_indirizzo($s, $conComune = true) {
  if (!empty($s['indirizzo_vr'])) return $s['indirizzo_vr'];   // scritto dalla struttura nella sua intestazione su Vetroom
  $via = trim(($s['via'] ?? '') . ' ' . ($s['civico'] ?? ''));
  $luogo = trim(($s['cap'] ?? '') . ' ' . ($conComune ? $s['comune'] : ''));
  $out = implode(', ', array_filter([$via, $luogo]));
  if ($conComune && !empty($s['provincia'])) $out .= ' (' . $s['provincia'] . ')';
  return trim($out);
}
// Numero per i link "tel:" e per Google: solo cifre, con +39 davanti se manca
function vr_tel_link($t) {
  $n = preg_replace('/[^\d+]/', '', (string)$t);
  if ($n === '') return '';
  if (strpos($n, '00') === 0) $n = '+' . substr($n, 2);
  if ($n[0] !== '+' && preg_match('/^[03]\d{5,10}$/', $n)) $n = '+39' . $n;
  return $n;
}
function vr_telefono($t) {
  if (!$t) return '';
  if (strpos($t, '+39') !== 0) return $t;
  $n = substr($t, 3);
  if (preg_match('/^3\d{9}$/', $n)) return '+39 ' . substr($n, 0, 3) . ' ' . substr($n, 3, 3) . ' ' . substr($n, 6);   // cellulare
  if (preg_match('/^0[26]\d+$/', $n)) return '+39 ' . substr($n, 0, 2) . ' ' . substr($n, 2);                       // Milano, Roma
  return '+39 ' . $n;
}

// Orari nel formato di OpenStreetMap ("Mo-Fr 09:00-19:00; Sa 09:00-12:00") resi leggibili in italiano
function vr_orari($s) {
  $s = trim((string)$s);
  if ($s === '') return [];
  if ($s === '24/7') return ['Aperto 24 ore su 24, tutti i giorni'];
  $giorni = ['Mo'=>'Lun','Tu'=>'Mar','We'=>'Mer','Th'=>'Gio','Fr'=>'Ven','Sa'=>'Sab','Su'=>'Dom','PH'=>'Festivi'];
  $righe = [];
  foreach (explode(';', $s) as $p) {
    $p = trim($p); if ($p === '') continue;
    $p = preg_replace_callback('/\b(Mo|Tu|We|Th|Fr|Sa|Su|PH)\b/', function ($m) use ($giorni) { return $giorni[$m[1]]; }, $p);
    $p = preg_replace('/(Lun|Mar|Mer|Gio|Ven|Sab|Dom)-(Lun|Mar|Mer|Gio|Ven|Sab|Dom)/', '$1–$2', $p);
    $p = preg_replace('/(\d{1,2}:\d{2})-(\d{1,2}:\d{2})/', '$1–$2', $p);
    $p = preg_replace('/\b(off|closed)\b/i', 'chiuso', $p);
    $p = str_replace(',', ', ', $p);
    $righe[] = preg_replace('/\s+/', ' ', $p);
  }
  return $righe;
}
// Per i dati strutturati di Google: solo gli orari in forma semplice
function vr_orari_schema($s) {
  $out = [];
  foreach (explode(';', (string)$s) as $p) {
    $p = trim($p);
    if (preg_match('/^(Mo|Tu|We|Th|Fr|Sa|Su)(-(Mo|Tu|We|Th|Fr|Sa|Su))?(,(Mo|Tu|We|Th|Fr|Sa|Su)(-(Mo|Tu|We|Th|Fr|Sa|Su))?)* \d{2}:\d{2}-\d{2}:\d{2}(,\d{2}:\d{2}-\d{2}:\d{2})*$/', $p)) $out[] = $p;
  }
  return $out;
}
// Orari impostati dalla struttura su Vetroom: [[giorno 0=dom…6=sab, inizio, fine, inizio2, fine2], …]
function vr_orari_fasce($r) {
  $hm = function ($t) { return $t ? substr((string)$t, 0, 5) : ''; };
  $f = [];
  if ($hm($r[1] ?? '') && $hm($r[2] ?? '')) $f[] = [$hm($r[1]), $hm($r[2])];
  if ($hm($r[3] ?? '') && $hm($r[4] ?? '')) $f[] = [$hm($r[3]), $hm($r[4])];
  return $f;
}
function vr_orari_vetroom($righe) {
  $nomi = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
  $per = [];
  foreach ((array)$righe as $r) {
    $g = (int)($r[0] ?? -1); if ($g < 0 || $g > 6) continue;
    $f = vr_orari_fasce($r); if (!$f) continue;
    $per[$g] = implode(', ', array_map(function ($x) { return $x[0] . '–' . $x[1]; }, $f));
  }
  $gruppi = [];   // giorni consecutivi (da lunedì a domenica) con gli stessi orari
  foreach ([1, 2, 3, 4, 5, 6, 0] as $pos => $g) {
    if (!isset($per[$g])) continue;
    $n = count($gruppi);
    if ($n && $gruppi[$n - 1]['orari'] === $per[$g] && $gruppi[$n - 1]['fine'] === $pos - 1) { $gruppi[$n - 1]['a'] = $g; $gruppi[$n - 1]['fine'] = $pos; }
    else $gruppi[] = ['da' => $g, 'a' => $g, 'fine' => $pos, 'orari' => $per[$g]];
  }
  return array_map(function ($x) use ($nomi) {
    return ($x['da'] === $x['a'] ? $nomi[$x['da']] : $nomi[$x['da']] . '–' . $nomi[$x['a']]) . ' ' . $x['orari'];
  }, $gruppi);
}
function vr_orari_vetroom_schema($righe) {
  $sigle = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']; $out = [];
  foreach ((array)$righe as $r) {
    $g = (int)($r[0] ?? -1); if ($g < 0 || $g > 6) continue;
    foreach (vr_orari_fasce($r) as $x) $out[] = $sigle[$g] . ' ' . $x[0] . '-' . $x[1];
  }
  return $out;
}
function vr_km($a, $b) {
  $r = M_PI / 180;
  $x = ($b['lon'] - $a['lon']) * $r * cos((($a['lat'] + $b['lat']) / 2) * $r);
  $y = ($b['lat'] - $a['lat']) * $r;
  return sqrt($x * $x + $y * $y) * 6371;
}

// ---------- lettura dei dati (con copia locale per un'ora) ----------
function vr_scarica() {
  $url = VR_SUPABASE_URL . '/rest/v1/rpc/osm_pubblico';
  $headers = ['apikey: ' . VR_SUPABASE_KEY, 'Content-Type: application/json', 'Accept: application/json'];
  if (strpos(VR_SUPABASE_KEY, 'eyJ') === 0) $headers[] = 'Authorization: Bearer ' . VR_SUPABASE_KEY;
  if (function_exists('curl_init')) {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_HTTPHEADER => $headers,
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8]);
    $body = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
  } else {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => '{}', 'timeout' => 20, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0; if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
  }
  if ($code !== 200 || !$body) return null;
  $j = json_decode($body, true);
  return (is_array($j) && isset($j['strutture'])) ? $j : null;
}
function vr_cache_file() {
  $dir = sys_get_temp_dir();
  if (!is_writable($dir)) { $dir = __DIR__ . '/cache'; if (!is_dir($dir)) @mkdir($dir, 0755); }
  return rtrim($dir, '/') . '/vetroom_veterinari_' . md5(VR_SUPABASE_URL) . '.json';
}
function vr_dati_grezzi() {
  $f = vr_cache_file();
  $fresca = is_file($f) && (time() - filemtime($f)) < VR_CACHE_SECONDI;
  if ($fresca) { $j = json_decode((string)file_get_contents($f), true); if ($j) return $j; }
  $j = vr_scarica();
  if ($j) { @file_put_contents($f, json_encode($j, JSON_UNESCAPED_UNICODE), LOCK_EX); return $j; }
  if (is_file($f)) { $j = json_decode((string)file_get_contents($f), true); if ($j) return $j; }   // meglio vecchi che niente
  return null;
}

// Indice: regioni > province > comuni > strutture
function vr_indice() {
  static $D = null;
  if ($D !== null) return $D;
  $raw = vr_dati_grezzi();
  if (!$raw) return $D = false;
  $P = vr_province();
  $D = ['aggiornato' => $raw['aggiornato'] ?? null, 'regioni' => [], 'id' => [], 'tutte' => []];
  foreach ($raw['strutture'] as $s) {
    $sig = strtoupper((string)$s['provincia']);
    if (!isset($P[$sig])) continue;
    $rn = vr_nome_regione($s['regione']); $rs = vr_slug($rn);
    $ps = vr_slug($P[$sig]); $cs = vr_slug($s['comune']);
    $s['id'] = substr($s['t'], 0, 1) . $s['i'];
    // Struttura attiva su Vetroom: i dati curati dal titolare hanno la precedenza su quelli di OpenStreetMap
    $v = $s['vetroom'] ?? null;
    if (array_key_exists('vetroom', $s) && !is_array($v)) $s['su_vetroom'] = false;   // struttura sospesa
    $s['righe_orari'] = vr_orari($s['orari'] ?? ''); $s['schema_orari'] = vr_orari_schema($s['orari'] ?? '');
    $s['codice'] = ''; $s['domicilio'] = false; $s['zona'] = ''; $s['indirizzo_vr'] = '';
    if (is_array($v)) {
      if (!empty($v['nome'])) $s['nome'] = $v['nome'];
      if (!empty($v['telefono'])) $s['telefono'] = $v['telefono'];
      if (!empty($v['indirizzo'])) $s['indirizzo_vr'] = $v['indirizzo'];
      $ro = vr_orari_vetroom($v['orari'] ?? []);
      if ($ro) { $s['righe_orari'] = $ro; $s['schema_orari'] = vr_orari_vetroom_schema($v['orari']); }
      $s['codice'] = preg_match('/^[A-Za-z0-9_-]{3,40}$/', (string)($v['codice'] ?? '')) ? $v['codice'] : '';
      $s['domicilio'] = !empty($v['domicilio']); $s['zona'] = (string)($v['zona'] ?? '');
    }
    unset($s['vetroom']);
    $s['rs'] = $rs; $s['ps'] = $ps; $s['cs'] = $cs; $s['provincia'] = $sig;
    $s['url'] = "/veterinari/$rs/$ps/$cs/" . vr_slug(vr_nome($s)) . '-' . $s['id'];
    $s['indicizza'] = !empty($s['su_vetroom']) || (!empty($s['nome']) && (!empty($s['orari']) || !empty($s['telefono']) || !empty($s['sito'])));
    if (!isset($D['regioni'][$rs])) $D['regioni'][$rs] = ['nome' => $rn, 'slug' => $rs, 'url' => "/veterinari/$rs/", 'province' => [], 'n' => 0];
    $R =& $D['regioni'][$rs];
    if (!isset($R['province'][$ps])) $R['province'][$ps] = ['nome' => $P[$sig], 'sigla' => $sig, 'slug' => $ps, 'url' => "/veterinari/$rs/$ps/", 'comuni' => [], 'n' => 0, 'regione' => $rn];
    $Pr =& $R['province'][$ps];
    if (!isset($Pr['comuni'][$cs])) $Pr['comuni'][$cs] = ['nome' => $s['comune'], 'slug' => $cs, 'url' => "/veterinari/$rs/$ps/$cs/", 'strutture' => []];
    $Pr['comuni'][$cs]['strutture'][] = $s;
    $Pr['n']++; $R['n']++;
    $D['id'][$s['id']] = [$rs, $ps, $cs, count($Pr['comuni'][$cs]['strutture']) - 1];
    unset($R, $Pr);
  }
  ksort($D['regioni']);
  foreach ($D['regioni'] as &$R) {
    ksort($R['province']);
    foreach ($R['province'] as &$Pr) {
      ksort($Pr['comuni']);
      foreach ($Pr['comuni'] as &$C) { $C['n'] = count($C['strutture']); $C['indicizza'] = $C['n'] >= VR_MIN_STRUTTURE_COMUNE; }
      unset($C);
    }
    unset($Pr);
  }
  unset($R);
  foreach ($D['id'] as $k => $pos) $D['tutte'][$k] = $D['regioni'][$pos[0]]['province'][$pos[1]]['comuni'][$pos[2]]['strutture'][$pos[3]];
  return $D;
}
function vr_struttura($id) {
  $D = vr_indice();
  return ($D && isset($D['tutte'][$id])) ? $D['tutte'][$id] : null;
}
function vr_vicine($s, $quante = 6) {
  $D = vr_indice(); $out = [];
  foreach ($D['tutte'] as $x) {
    if ($x['id'] === $s['id'] || $x['lat'] === null || $s['lat'] === null) continue;
    $d = vr_km($s, $x); if ($d < 40) $out[] = [$d, $x];
  }
  usort($out, function ($a, $b) { return $a[0] <=> $b[0]; });
  return array_slice($out, 0, $quante);
}
function vr_data($iso) { return $iso ? date('d/m/Y', strtotime($iso)) : ''; }

// ---------- pagina ----------
function vr_jsonld($dati) {
  return '<script type="application/ld+json">' . json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
}
function vr_pagina($o) {
  $canon = VR_SITO . $o['url'];
  $crumbs = $o['crumbs'] ?? [];
  $ld = $o['jsonld'] ?? [];
  if ($crumbs) {
    $items = []; $i = 1;
    foreach ($crumbs as $c) $items[] = ['@type' => 'ListItem', 'position' => $i++, 'name' => $c[0], 'item' => VR_SITO . $c[1]];
    $ld[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
  }
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: public, max-age=600');
  if (empty($o['indicizza'])) header('X-Robots-Tag: noindex, follow');
  ?><!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="#033860" />
  <title><?= h($o['title']) ?></title>
  <meta name="description" content="<?= h($o['description']) ?>" />
  <meta name="robots" content="<?= empty($o['indicizza']) ? 'noindex,follow' : 'index,follow,max-image-preview:large' ?>" />
  <link rel="canonical" href="<?= h($canon) ?>" />
  <link rel="icon" href="/favicon.ico" />
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png" />
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
  <meta property="og:locale" content="it_IT" />
  <meta property="og:site_name" content="Vetroom" />
  <meta property="og:type" content="website" />
  <meta property="og:title" content="<?= h($o['og'] ?? $o['title']) ?>" />
  <meta property="og:description" content="<?= h($o['description']) ?>" />
  <meta property="og:url" content="<?= h($canon) ?>" />
  <link rel="stylesheet" href="/assets/site.css?v=2026-10-10" />
  <link rel="stylesheet" href="/veterinari/veterinari.css?v=2026-10-11a" />
  <?php foreach ($ld as $x) echo vr_jsonld($x), "\n"; ?>
</head>
<body>
  <header class="site-header">
    <div class="container">
      <div class="header-inner">
        <a class="brand" href="/" aria-label="Vetroom, vai alla home">
          <span class="brand-logo" aria-hidden="true"><img src="/app/img/logo-bianco.png" alt="Vetroom" width="504" height="132" /></span>
          <span class="sr-only">Vetroom</span>
        </a>
        <nav class="nav" id="nav" aria-label="Navigazione principale">
          <a href="/">Gestionale</a>
          <a href="/veterinari/">Veterinari per città</a>
          <a href="/trova-struttura.html">Sei un veterinario?</a>
          <a class="btn small primary" href="/app/">Accedi</a>
        </nav>
        <button class="nav-toggle" type="button" aria-controls="nav" aria-expanded="false" aria-label="Apri menu">☰</button>
      </div>
    </div>
  </header>
  <main class="vt">
    <div class="container">
<?php if ($crumbs) { ?>
      <nav class="crumbs" aria-label="Percorso"><?php $u = []; foreach ($crumbs as $k => $c) $u[] = $k === count($crumbs) - 1 ? '<span aria-current="page">' . h($c[0]) . '</span>' : '<a href="' . h($c[1]) . '">' . h($c[0]) . '</a>'; echo implode(' › ', $u); ?></nav>
<?php } ?>
<?= $o['body'] ?>
      <p class="vt-fonte">Elenco basato sui dati di © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a> (licenza ODbL)<?php $D = vr_indice(); if ($D && $D['aggiornato']) echo ', aggiornati il ' . h(vr_data($D['aggiornato'])); ?>. Le informazioni possono non essere aggiornate: prima di andare, telefona. Vuoi correggere o togliere una scheda? Scrivi a <a href="mailto:info@vetroom.it">info@vetroom.it</a>.</p>
    </div>
  </main>
  <footer class="site-footer">
    <div class="container">
      <div class="footer-grid">
        <div class="fine">
          <a href="/" class="footer-brand" aria-label="Vetroom"><img src="/app/img/logo-bianco.png" alt="Vetroom" width="504" height="132" loading="lazy" /></a>
          <p class="footer-claim">Il gestionale veterinario gratuito in cloud, per lo studio e per le visite a domicilio.</p>
          <a class="btn primary footer-cta" href="/app/">Login</a>
        </div>
        <nav class="footer-col" aria-label="Sezioni">
          <h4>Vetroom</h4>
          <a href="/">Gestionale veterinario gratuito</a>
          <a href="/veterinari/">Veterinari per città</a>
          <a href="/trova-struttura.html">Rivendica la tua struttura</a>
          <a href="/veterinario-a-domicilio.html">Veterinario a domicilio</a>
          <a href="/guida/">Guida</a>
        </nav>
        <div class="footer-col">
          <h4>Contatti</h4>
          <a href="mailto:info@vetroom.it">info@vetroom.it</a>
          <a href="/privacy.html">Privacy</a>
          <a href="/privacy.html#cookie">Cookie</a>
          <a href="/termini.html">Termini di servizio</a>
        </div>
      </div>
      <div class="footer-bottom">
        <span>© <?= date('Y') ?> Vetroom è un servizio di AF&amp;B S.r.l.s. · Via Mario Ugo Guattari 48, 00173 Roma (RM) · P.IVA 13698751008 · REA RM-1466364 · Capitale sociale € 2.700,00 i.v.</span>
        <span><a href="/privacy.html">Informativa privacy</a> · <a href="/termini.html">Termini</a></span>
      </div>
    </div>
  </footer>
  <script src="/assets/cookie-notice.js" defer></script>
<?php if (!empty($o['evento']) && VR_CONTA_VISITE) { ?>
  <script>
    // Contatore anonimo: solo un totale per scheda e per giorno, niente cookie e niente dati di chi visita
    (function () {
      if (navigator.webdriver || /bot|crawl|spider|slurp|preview|lighthouse/i.test(navigator.userAgent)) return;
      var url = <?= json_encode(VR_SUPABASE_URL . '/rest/v1/rpc/osm_evento', JSON_UNESCAPED_SLASHES) ?>, key = <?= json_encode(VR_SUPABASE_KEY) ?>;
      var dove = <?= json_encode(['p_type' => $o['evento'][0], 'p_id' => $o['evento'][1]]) ?>;
      function conta(ev) {
        try {
          var h = { 'apikey': key, 'Content-Type': 'application/json' };
          if (key.indexOf('eyJ') === 0) h.Authorization = 'Bearer ' + key;
          fetch(url, { method: 'POST', keepalive: true, headers: h, body: JSON.stringify({ p_type: dove.p_type, p_id: dove.p_id, p_evento: ev }) }).catch(function () {});
        } catch (e) {}
      }
      conta('vista');
      document.addEventListener('click', function (e) { var a = e.target.closest && e.target.closest('[data-ev]'); if (a) conta(a.getAttribute('data-ev')); });
    })();
  </script>
<?php } ?>
  <script>
    (function () {
      var t = document.querySelector('.nav-toggle'), n = document.getElementById('nav');
      if (t && n) t.addEventListener('click', function () { var o = n.classList.toggle('open'); t.setAttribute('aria-expanded', String(o)); });
    })();
  </script>
</body>
</html>
<?php
}

// Scheda breve di una struttura negli elenchi
function vr_card($s, $conComune = false) {
  $orari = $s['righe_orari'] ?? vr_orari($s['orari'] ?? '');
  $tag = !empty($s['su_vetroom']) ? '<span class="vt-on">Usa Vetroom</span>' : '';
  return '<li class="vt-card"><a href="' . h($s['url']) . '"><strong>' . h(vr_nome($s)) . '</strong>' . $tag
    . '<span class="vt-addr">' . h(vr_indirizzo($s, $conComune) ?: $s['comune']) . '</span>'
    . ($orari ? '<span class="vt-hours">' . h($orari[0]) . (count($orari) > 1 ? ' …' : '') . '</span>' : '')
    . '</a></li>';
}
function vr_conta($n, $uno, $molti) { return $n . ' ' . ($n === 1 ? $uno : $molti); }

// Riquadri per proprietari e veterinari
function vr_box_proprietari($s = null) {
  if ($s && !empty($s['su_vetroom']) && !empty($s['codice'])) {
    return '<aside class="vt-box vt-owner"><h2>Prenota con FurrFinder</h2><p>' . h(vr_nome($s)) . ' usa Vetroom: con <b>FurrFinder</b> chiedi un appuntamento senza telefonare, hai il libretto sanitario del tuo animale sempre in tasca e ricevi i promemoria di vaccini e richiami. È gratis.</p><a class="btn primary" data-ev="prenota" href="/app/?codice=' . rawurlencode($s['codice']) . '">Prenota con FurrFinder</a></aside>';
  }
  if ($s && !empty($s['su_vetroom'])) {
    return '<aside class="vt-box vt-owner"><h2>Sei cliente di questa struttura?</h2><p>' . h(vr_nome($s)) . ' usa Vetroom: con <b>FurrFinder</b> hai il libretto sanitario del tuo animale sempre in tasca, i promemoria di vaccini e richiami e puoi chiedere un appuntamento senza telefonare.</p><a class="btn primary" href="/app/?per=proprietario">Apri FurrFinder, è gratis</a></aside>';
  }
  return '<aside class="vt-box vt-owner"><h2>Hai un cane o un gatto?</h2><p>Con <b>FurrFinder</b> tieni il libretto sanitario sempre in tasca e ricevi i promemoria di vaccini e richiami. Se il tuo veterinario usa Vetroom, prenoti anche senza telefonare.</p><a class="btn primary" href="/app/?per=proprietario">Prova FurrFinder, è gratis</a></aside>';
}
function vr_box_veterinari($s = null) {
  if ($s && !empty($s['su_vetroom'])) {
    return '<aside class="vt-box vt-vet"><h2>Gestita su Vetroom</h2><p>Questa scheda è curata dalla struttura con Vetroom, il gestionale veterinario gratuito. Sei il titolare e non sei tu a gestirla?</p><a class="btn" data-ev="rivendica" href="/app/rivendica.html?s=' . h($s['t'] . '/' . $s['i']) . '">Segnalacelo</a></aside>';
  }
  if ($s) {
    return '<aside class="vt-box vt-vet"><h2>Sei il titolare di ' . h(vr_nome($s)) . '?</h2><p>Rivendica gratis questa scheda: correggi i dati, ricevi le richieste di appuntamento dei proprietari e gestisci lo studio con Vetroom, il gestionale veterinario gratuito.</p><a class="btn" data-ev="rivendica" href="/app/rivendica.html?s=' . h($s['t'] . '/' . $s['i']) . '">Rivendica la scheda</a></aside>';
  }
  return '<aside class="vt-box vt-vet"><h2>Sei un veterinario?</h2><p>La tua struttura potrebbe essere già in questo elenco. Rivendicala gratis e gestisci agenda, cartelle cliniche, fatture e prenotazioni con Vetroom.</p><a class="btn" href="/trova-struttura.html">Trova e rivendica la tua struttura</a></aside>';
}
