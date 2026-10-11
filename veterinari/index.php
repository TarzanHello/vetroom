<?php
// =============================================================
//  VETROOM · Sezione pubblica /veterinari/
//  /veterinari/                                 → Italia, per regione
//  /veterinari/lazio/                           → province della regione
//  /veterinari/lazio/roma/                      → comuni e strutture della provincia
//  /veterinari/lazio/roma/fiumicino/            → strutture del comune
//  /veterinari/lazio/roma/fiumicino/nome-n123   → scheda della struttura
//  /veterinari/sitemap.xml                      → elenco delle pagine per Google
// =============================================================
require __DIR__ . '/config.php';
require __DIR__ . '/funzioni.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/veterinari/', PHP_URL_PATH);
$path = rawurldecode((string)$path);
$rel = preg_replace('#^/veterinari/?#', '', $path);
$parti = array_values(array_filter(explode('/', $rel), 'strlen'));

function vr_redirect($url) { header('Location: ' . VR_SITO . $url, true, 301); exit; }
function vr_errore($codice, $titolo, $testo) {
  http_response_code($codice);
  vr_pagina(['url' => '/veterinari/', 'title' => $titolo . ' | Vetroom', 'description' => $testo, 'indicizza' => false,
    'crumbs' => [['Veterinari', '/veterinari/']],
    'body' => '<section class="vt-head"><h1>' . h($titolo) . '</h1><p class="lead">' . h($testo) . '</p><p><a class="btn primary" href="/veterinari/">Vai all\'elenco per regione</a></p></section>']);
  exit;
}

// ---------- sitemap ----------
if (isset($_GET['sitemap']) || $rel === 'sitemap.xml') {
  $D = vr_indice();
  header('Content-Type: application/xml; charset=utf-8');
  header('Cache-Control: public, max-age=3600');
  $oggi = $D && $D['aggiornato'] ? substr($D['aggiornato'], 0, 10) : date('Y-m-d');
  echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
  $u = function ($url, $data = null) use ($oggi) { echo '  <url><loc>', h(VR_SITO . $url), '</loc><lastmod>', h($data ?: $oggi), "</lastmod></url>\n"; };
  if ($D) {
    $u('/veterinari/');
    foreach ($D['regioni'] as $R) {
      $u($R['url']);
      foreach ($R['province'] as $P) {
        $u($P['url']);
        foreach ($P['comuni'] as $C) {
          if ($C['indicizza']) $u($C['url']);
          foreach ($C['strutture'] as $s) if ($s['indicizza']) $u($s['url'], !empty($s['modificato']) ? substr($s['modificato'], 0, 10) : null);
        }
      }
    }
  }
  echo "</urlset>\n";
  exit;
}

$D = vr_indice();
if (!$D) {
  http_response_code(503); header('Retry-After: 600');
  vr_pagina(['url' => '/veterinari/', 'title' => 'Veterinari in Italia | Vetroom', 'description' => 'Elenco temporaneamente non disponibile.', 'indicizza' => false,
    'body' => '<section class="vt-head"><h1>Elenco temporaneamente non disponibile</h1><p class="lead">Riprova tra qualche minuto.</p></section>']);
  exit;
}

// ---------- Italia ----------
if (!$parti) {
  if ($path !== '/veterinari/') vr_redirect('/veterinari/');
  $tot = count($D['tutte']); $comuni = [];
  foreach ($D['regioni'] as $R) foreach ($R['province'] as $P) foreach ($P['comuni'] as $C) $comuni[] = $C + ['prov' => $P['sigla']];
  usort($comuni, function ($a, $b) { return $b['n'] <=> $a['n'] ?: strcmp($a['nome'], $b['nome']); });
  $b = '<section class="vt-head"><h1>Veterinari in Italia</h1><p class="lead">Ambulatori, cliniche e studi veterinari in ' . count($D['regioni']) . ' regioni: <b>' . $tot . ' strutture</b> con indirizzo, orari e contatti. Scegli la regione o la città.</p></section>';
  $b .= '<h2 class="vt-h2">Per regione</h2><ul class="vt-grid">';
  foreach ($D['regioni'] as $R) $b .= '<li><a href="' . h($R['url']) . '"><strong>' . h($R['nome']) . '</strong><span>' . vr_conta($R['n'], 'struttura', 'strutture') . '</span></a></li>';
  $b .= '</ul><h2 class="vt-h2">Le città con più strutture</h2><ul class="vt-chips">';
  foreach (array_slice($comuni, 0, 16) as $C) $b .= '<li><a href="' . h($C['url']) . '">' . h($C['nome']) . ' <span>' . $C['n'] . '</span></a></li>';
  $b .= '</ul><div class="vt-boxes">' . vr_box_proprietari() . vr_box_veterinari() . '</div>';
  $items = []; $k = 1; foreach ($D['regioni'] as $R) $items[] = ['@type' => 'ListItem', 'position' => $k++, 'url' => VR_SITO . $R['url'], 'name' => 'Veterinari in ' . $R['nome']];
  vr_pagina(['url' => '/veterinari/', 'indicizza' => true,
    'title' => 'Veterinari in Italia: ambulatori e cliniche veterinarie per regione | Vetroom',
    'description' => "Trova un veterinario vicino a te: $tot ambulatori, cliniche e studi veterinari in Italia con indirizzo, orari e telefono, divisi per regione, provincia e comune.",
    'crumbs' => [['Vetroom', '/'], ['Veterinari', '/veterinari/']],
    'jsonld' => [['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => 'Veterinari in Italia per regione', 'itemListElement' => $items]],
    'body' => $b]);
  exit;
}

// ---------- Regione ----------
$R = $D['regioni'][$parti[0]] ?? null;
if (!$R) vr_errore(404, 'Pagina non trovata', 'Questa regione non è nell\'elenco.');
if (count($parti) === 1) {
  if ($path !== $R['url']) vr_redirect($R['url']);
  $comuni = [];
  foreach ($R['province'] as $P) foreach ($P['comuni'] as $C) $comuni[] = $C;
  usort($comuni, function ($a, $b) { return $b['n'] <=> $a['n'] ?: strcmp($a['nome'], $b['nome']); });
  $b = '<section class="vt-head"><h1>Veterinari in ' . h($R['nome']) . '</h1><p class="lead"><b>' . vr_conta($R['n'], 'struttura veterinaria', 'strutture veterinarie') . '</b> in ' . vr_conta(count($R['province']), 'provincia', 'province') . ': ambulatori, cliniche e studi con indirizzo, orari e contatti.</p></section>';
  $b .= '<h2 class="vt-h2">Per provincia</h2><ul class="vt-grid">';
  foreach ($R['province'] as $P) $b .= '<li><a href="' . h($P['url']) . '"><strong>Provincia di ' . h($P['nome']) . '</strong><span>' . vr_conta($P['n'], 'struttura', 'strutture') . ' · ' . vr_conta(count($P['comuni']), 'comune', 'comuni') . '</span></a></li>';
  $b .= '</ul><h2 class="vt-h2">Comuni</h2><ul class="vt-chips">';
  foreach (array_slice($comuni, 0, 30) as $C) $b .= '<li><a href="' . h($C['url']) . '">' . h($C['nome']) . ' <span>' . $C['n'] . '</span></a></li>';
  $b .= '</ul><div class="vt-boxes">' . vr_box_proprietari() . vr_box_veterinari() . '</div>';
  vr_pagina(['url' => $R['url'], 'indicizza' => true,
    'title' => 'Veterinari in ' . $R['nome'] . ': ambulatori e cliniche per provincia | Vetroom',
    'description' => 'Elenco di ' . $R['n'] . ' ambulatori e cliniche veterinarie in ' . $R['nome'] . ', divisi per provincia e comune, con indirizzo, orari e telefono.',
    'crumbs' => [['Vetroom', '/'], ['Veterinari', '/veterinari/'], [$R['nome'], $R['url']]],
    'body' => $b]);
  exit;
}

// ---------- Provincia ----------
$P = $R['province'][$parti[1]] ?? null;
if (!$P) vr_errore(404, 'Pagina non trovata', 'Questa provincia non è nell\'elenco.');
if (count($parti) === 2) {
  if ($path !== $P['url']) vr_redirect($P['url']);
  $b = '<section class="vt-head"><h1>Veterinari in provincia di ' . h($P['nome']) . '</h1><p class="lead"><b>' . vr_conta($P['n'], 'struttura veterinaria', 'strutture veterinarie') . '</b> in ' . vr_conta(count($P['comuni']), 'comune', 'comuni') . ' della provincia di ' . h($P['nome']) . ' (' . h($P['sigla']) . '), ' . h($R['nome']) . '.</p></section>';
  if (count($P['comuni']) > 1) {
    $b .= '<ul class="vt-chips">';
    foreach ($P['comuni'] as $C) $b .= '<li><a href="#' . h($C['slug']) . '">' . h($C['nome']) . ' <span>' . $C['n'] . '</span></a></li>';
    $b .= '</ul>';
  }
  $items = []; $k = 1;
  foreach ($P['comuni'] as $C) {
    $b .= '<h2 class="vt-h2" id="' . h($C['slug']) . '"><a href="' . h($C['url']) . '">Veterinari a ' . h($C['nome']) . '</a></h2><ul class="vt-list">';
    foreach ($C['strutture'] as $s) { $b .= vr_card($s); if ($k <= 100) $items[] = ['@type' => 'ListItem', 'position' => $k++, 'url' => VR_SITO . $s['url'], 'name' => vr_nome($s)]; }
    $b .= '</ul>';
  }
  $b .= '<div class="vt-boxes">' . vr_box_proprietari() . vr_box_veterinari() . '</div>';
  vr_pagina(['url' => $P['url'], 'indicizza' => true,
    'title' => 'Veterinari in provincia di ' . $P['nome'] . ': ' . vr_conta($P['n'], 'struttura', 'strutture') . ' | Vetroom',
    'description' => 'Ambulatori e cliniche veterinarie in provincia di ' . $P['nome'] . ' (' . $P['sigla'] . '): ' . $P['n'] . ' strutture in ' . count($P['comuni']) . ' comuni, con indirizzo, orari e telefono.',
    'crumbs' => [['Vetroom', '/'], ['Veterinari', '/veterinari/'], [$R['nome'], $R['url']], ['Provincia di ' . $P['nome'], $P['url']]],
    'jsonld' => [['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => 'Veterinari in provincia di ' . $P['nome'], 'itemListElement' => $items]],
    'body' => $b]);
  exit;
}

// ---------- Comune ----------
$C = $P['comuni'][$parti[2]] ?? null;
if (!$C) vr_errore(404, 'Pagina non trovata', 'Questo comune non è nell\'elenco.');
if (count($parti) === 3) {
  if ($path !== $C['url']) vr_redirect($C['url']);
  $b = '<section class="vt-head"><h1>Veterinari a ' . h($C['nome']) . '</h1><p class="lead">' . ($C['n'] === 1 ? 'Una struttura veterinaria' : '<b>' . $C['n'] . ' strutture veterinarie</b>') . ' a ' . h($C['nome']) . ' (' . h($P['sigla']) . '), con indirizzo, orari e contatti.</p></section><ul class="vt-list">';
  $items = []; $k = 1;
  foreach ($C['strutture'] as $s) { $b .= vr_card($s); $items[] = ['@type' => 'ListItem', 'position' => $k++, 'url' => VR_SITO . $s['url'], 'name' => vr_nome($s)]; }
  $b .= '</ul>';
  $altri = array_filter($P['comuni'], function ($x) use ($C) { return $x['slug'] !== $C['slug']; });
  uasort($altri, function ($a, $b) { return $b['n'] <=> $a['n']; });
  if ($altri) {
    $b .= '<h2 class="vt-h2">Altri comuni in provincia di ' . h($P['nome']) . '</h2><ul class="vt-chips">';
    foreach (array_slice($altri, 0, 20) as $x) $b .= '<li><a href="' . h($x['url']) . '">' . h($x['nome']) . ' <span>' . $x['n'] . '</span></a></li>';
    $b .= '</ul>';
  }
  $b .= '<div class="vt-boxes">' . vr_box_proprietari() . vr_box_veterinari() . '</div>';
  vr_pagina(['url' => $C['url'], 'indicizza' => $C['indicizza'],
    'title' => 'Veterinari a ' . $C['nome'] . ': ' . ($C['n'] === 1 ? 'ambulatorio veterinario' : $C['n'] . ' ambulatori e cliniche') . ' | Vetroom',
    'description' => 'Veterinari a ' . $C['nome'] . ' (' . $P['sigla'] . '): ' . vr_conta($C['n'], 'struttura veterinaria', 'strutture veterinarie') . ' con indirizzo, orari di apertura e telefono.',
    'crumbs' => [['Vetroom', '/'], ['Veterinari', '/veterinari/'], [$R['nome'], $R['url']], ['Provincia di ' . $P['nome'], $P['url']], [$C['nome'], $C['url']]],
    'jsonld' => [['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => 'Veterinari a ' . $C['nome'], 'itemListElement' => $items]],
    'body' => $b]);
  exit;
}

// ---------- Scheda della struttura ----------
if (count($parti) !== 4 || !preg_match('/-([nwr]\d+)$/', $parti[3], $m)) vr_errore(404, 'Pagina non trovata', 'Questa pagina non esiste.');
$s = vr_struttura($m[1]);
if (!$s) vr_errore(410, 'Scheda non più disponibile', 'Questa struttura non è più nel nostro elenco. Cerca un altro veterinario nella stessa zona.');
if ($path !== $s['url']) vr_redirect($s['url']);
$Rs = $D['regioni'][$s['rs']]; $Ps = $Rs['province'][$s['ps']]; $Cs = $Ps['comuni'][$s['cs']];
$nome = vr_nome($s); $orari = vr_orari($s['orari'] ?? '');
$tel = VR_MOSTRA_TELEFONO ? ($s['telefono'] ?? '') : '';
$sito = VR_MOSTRA_SITO && !empty($s['sito']) && preg_match('#^https?://#', $s['sito']) ? $s['sito'] : '';
$ind = vr_indirizzo($s);

$frase = h($nome) . ' è una struttura veterinaria a ' . h($s['comune']) . ' (' . h($s['provincia']) . ')'
  . (!empty($s['via']) ? ', in ' . h(trim($s['via'] . ' ' . ($s['civico'] ?? ''))) : '') . '.'
  . ($orari ? ' Orari: ' . h(implode('; ', $orari)) . '.' : '')
  . ($tel ? ' Per un appuntamento puoi telefonare al ' . h(vr_telefono($tel)) . '.' : '');
$b = '<article class="vt-scheda"><section class="vt-head"><h1>' . h($nome) . '</h1><p class="vt-sub">Veterinario a ' . h($s['comune']) . ' (' . h($s['provincia']) . ')' . (!empty($s['su_vetroom']) ? ' <span class="vt-on">Usa Vetroom</span>' : '') . '</p></section>';
$b .= '<div class="vt-cols"><div class="vt-info"><p>' . $frase . '</p><dl class="vt-dl">';
if ($ind) $b .= '<dt>Indirizzo</dt><dd>' . h($ind) . '</dd>';
if ($orari) $b .= '<dt>Orari</dt><dd><ul class="vt-orari"><li>' . implode('</li><li>', array_map('h', $orari)) . '</li></ul></dd>';
if ($tel) $b .= '<dt>Telefono</dt><dd><a class="vt-tel" href="tel:' . h(preg_replace('/[^\d+]/', '', $tel)) . '">' . h(vr_telefono($tel)) . '</a></dd>';
if ($sito) $b .= '<dt>Sito</dt><dd><a href="' . h($sito) . '" target="_blank" rel="nofollow noopener">' . h(preg_replace('#^https?://(www\.)?#', '', $sito)) . '</a></dd>';
$b .= '</dl>';
if ($s['lat'] !== null) {
  $b .= '<p class="vt-actions"><a class="btn small" href="https://www.google.com/maps/dir/?api=1&amp;destination=' . h($s['lat'] . ',' . $s['lon']) . '" target="_blank" rel="noopener">Indicazioni stradali</a>'
    . ($tel ? ' <a class="btn small primary" href="tel:' . h(preg_replace('/[^\d+]/', '', $tel)) . '">Chiama</a>' : '') . '</p>';
}
$b .= '</div>';
if ($s['lat'] !== null) {
  $d = 0.004; $bbox = ($s['lon'] - $d * 1.5) . ',' . ($s['lat'] - $d) . ',' . ($s['lon'] + $d * 1.5) . ',' . ($s['lat'] + $d);
  $b .= '<div class="vt-map"><iframe title="Mappa di ' . h($nome) . '" loading="lazy" src="https://www.openstreetmap.org/export/embed.html?bbox=' . h($bbox) . '&amp;layer=mapnik&amp;marker=' . h($s['lat'] . ',' . $s['lon']) . '"></iframe>'
    . '<a class="vt-maplink" href="https://www.openstreetmap.org/?mlat=' . h($s['lat']) . '&amp;mlon=' . h($s['lon']) . '#map=17/' . h($s['lat']) . '/' . h($s['lon']) . '" target="_blank" rel="noopener">Mappa più grande</a></div>';
}
$b .= '</div><div class="vt-boxes">' . vr_box_proprietari($s) . vr_box_veterinari($s) . '</div>';
$vicine = vr_vicine($s, 6);
if ($vicine) {
  $b .= '<h2 class="vt-h2">Altri veterinari vicino a ' . h($nome) . '</h2><ul class="vt-list">';
  foreach ($vicine as $v) {
    $km = $v[0] < 1 ? round($v[0] * 1000, -1) . ' m' : str_replace('.', ',', (string)round($v[0], 1)) . ' km';
    $b .= str_replace('</a></li>', '<span class="vt-km">a ' . h($km) . '</span></a></li>', vr_card($v[1], $v[1]['cs'] !== $s['cs']));
  }
  $b .= '</ul>';
}
$b .= '<p class="vt-more"><a href="' . h($Cs['url']) . '">Tutti i veterinari a ' . h($s['comune']) . '</a> · <a href="' . h($Ps['url']) . '">in provincia di ' . h($Ps['nome']) . '</a></p></article>';

$ld = ['@context' => 'https://schema.org', '@type' => 'VeterinaryCare', 'name' => $nome, 'url' => VR_SITO . $s['url'],
  'address' => array_filter(['@type' => 'PostalAddress', 'streetAddress' => trim(($s['via'] ?? '') . ' ' . ($s['civico'] ?? '')) ?: null,
    'postalCode' => $s['cap'] ?? null, 'addressLocality' => $s['comune'], 'addressRegion' => $s['provincia'], 'addressCountry' => 'IT'])];
if ($s['lat'] !== null) $ld['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $s['lat'], 'longitude' => $s['lon']];
if ($tel) $ld['telephone'] = $tel;
if ($sito) $ld['sameAs'] = [$sito];
$oh = vr_orari_schema($s['orari'] ?? ''); if ($oh) $ld['openingHours'] = $oh;

vr_pagina(['url' => $s['url'], 'indicizza' => $s['indicizza'],
  'title' => $nome . ' – Veterinario a ' . $s['comune'] . ' (' . $s['provincia'] . ') | Vetroom',
  'og' => $nome . ', veterinario a ' . $s['comune'],
  'description' => $nome . ', struttura veterinaria a ' . $s['comune'] . (!empty($s['via']) ? ' in ' . trim($s['via'] . ' ' . ($s['civico'] ?? '')) : '') . '.' . ($orari ? ' Orari: ' . implode('; ', $orari) . '.' : '') . ($tel ? ' Telefono ' . vr_telefono($tel) . '.' : '') . ' Indirizzo, mappa e altri veterinari vicini.',
  'crumbs' => [['Vetroom', '/'], ['Veterinari', '/veterinari/'], [$Rs['nome'], $Rs['url']], ['Provincia di ' . $Ps['nome'], $Ps['url']], [$s['comune'], $Cs['url']], [$nome, $s['url']]],
  'jsonld' => [$ld],
  'body' => $b]);
