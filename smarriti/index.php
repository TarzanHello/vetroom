<?php
// =============================================================
//  FURRFINDER · Sezione pubblica /smarriti/ (patch 38)
//  /smarriti/                               → avvisi in corso in tutta Italia + come funziona
//  /smarriti/roma/                          → avvisi di una città
//  /smarriti/roma/luna-k7m3qx               → pagina di un avviso (con "L'ho visto" / "L'ho trovato")
//  /smarriti/hai-perso-il-tuo-animale       → guida: cosa fare subito
//  /smarriti/hai-trovato-un-animale         → guida: cosa fare se lo trovi
//  /smarriti/sitemap.xml                    → elenco delle pagine per Google
// =============================================================
require __DIR__ . '/funzioni.php';

$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/smarriti/', PHP_URL_PATH));
$rel = preg_replace('#^/smarriti/?#', '', $path);
$parti = array_values(array_filter(explode('/', $rel), 'strlen'));
$PUBBLICA = '/app/?per=proprietario&amp;dopo=smarrito';

// Su furrfinder.com l'indirizzo è sempre con www (come in VR_SITO): la regola del sito principale
// non vale dentro questa cartella, che ha un suo .htaccess. (Solo il nome del sito: niente rischio di giri infiniti.)
$sm_vuole = parse_url(VR_SITO);
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) && !empty($sm_vuole['host']) && SM_MARCA === 'furrfinder'
    && strcasecmp($_SERVER['HTTP_HOST'] ?? '', $sm_vuole['host'] . (isset($sm_vuole['port']) ? ':' . $sm_vuole['port'] : '')) !== 0) {
  $q = preg_replace('/(^|&)p=[^&]*/', '', $_SERVER['QUERY_STRING'] ?? ''); $q = ltrim($q, '&');
  header('Location: ' . VR_SITO . $path . ($q !== '' ? '?' . $q : ''), true, 301); exit;
}

// Pagine trasferite su furrfinder.com? (config.php, SM_RIMANDA) → ogni indirizzo vecchio porta a quello nuovo
if (SM_RIMANDA && SM_MARCA !== 'furrfinder') {
  $q = $_SERVER['QUERY_STRING'] ?? ''; $q = preg_replace('/(^|&)p=[^&]*/', '', $q); $q = ltrim($q, '&');
  header('Location: ' . rtrim(SM_RIMANDA, '/') . $path . ($q !== '' ? '?' . $q : ''), true, 301); exit;
}

function sm_redirect($url) { header('Location: ' . VR_SITO . $url, true, 301); exit; }
function sm_errore($codice, $titolo, $testo) {
  http_response_code($codice);
  sm_pagina(['url' => '/smarriti/', 'title' => $titolo . ' | FurrFinder', 'description' => $testo, 'indicizza' => false,
    'body' => '<section class="sm-hero sm-hero-small"><h1>' . h($titolo) . '</h1><p class="sm-lead">' . h($testo) . '</p><p><a class="sm-btn" href="/smarriti/">Vedi gli avvisi in corso</a></p></section>']);
  exit;
}
function sm_box_pubblica() {
  return '<aside class="sm-box sm-box-red"><h2>Hai perso il tuo animale?</h2><p>Pubblica l\'avviso gratis in un minuto: FurrFinder prepara la pagina e la locandina da condividere su WhatsApp e Facebook. Chi lo vede ti avvisa subito. Il punto esatto resta privato.</p><a class="sm-btn sm-btn-red" href="/app/?per=proprietario&amp;dopo=smarrito">Pubblica un avviso</a> <a class="sm-link" href="/smarriti/hai-perso-il-tuo-animale">Cosa fare subito →</a></aside>';
}
function sm_box_trovato() {
  return '<aside class="sm-box"><h2>Hai trovato un animale?</h2><p>Guarda se qualcuno lo sta cercando qui sopra e apri il suo avviso: con <b>L\'ho trovato</b> il proprietario riceve subito il tuo messaggio. Poi fallo controllare da un veterinario: con il microchip si risale al proprietario.</p><a class="sm-btn" href="/smarriti/hai-trovato-un-animale">Cosa fare se lo trovi</a> <a class="sm-link" href="/veterinari/">Trova un veterinario →</a></aside>';
}

// ---------- sitemap ----------
if ($rel === 'sitemap.xml' || isset($_GET['sitemap'])) {
  header('Content-Type: application/xml; charset=utf-8');
  header('Cache-Control: public, max-age=900');
  $citta = sm_rpc('smarriti_citta', [], 900) ?: [];
  $tutti = sm_rpc('smarriti_elenco', ['p_citta_slug' => null, 'p_limite' => 500], 900) ?: [];
  echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
  $u = function ($url, $data = null) { echo '  <url><loc>', h(VR_SITO . $url), '</loc>', $data ? '<lastmod>' . h(substr($data, 0, 10)) . '</lastmod>' : '', "</url>\n"; };
  $u('/smarriti/'); $u('/smarriti/hai-perso-il-tuo-animale'); $u('/smarriti/hai-trovato-un-animale');
  foreach ($citta as $c) $u('/smarriti/' . $c['citta_slug'] . '/', $c['aggiornato'] ?? null);
  foreach ($tutti as $s) $u($s['url'], $s['chiuso_il'] ?? $s['perso_il']);
  echo "</urlset>\n";
  exit;
}

// ---------- guide ----------
if (count($parti) === 1 && in_array($parti[0], ['hai-perso-il-tuo-animale', 'hai-trovato-un-animale'], true)) {
  require __DIR__ . '/guide.php';
  sm_guida($parti[0]);
  exit;
}

// ---------- pagina principale ----------
if (!$parti) {
  if ($path !== '/smarriti/') sm_redirect('/smarriti/');
  $elenco = sm_rpc('smarriti_elenco', ['p_citta_slug' => null, 'p_limite' => 300]);
  $citta = sm_rpc('smarriti_citta', [], 300) ?: [];
  $aperti = array_values(array_filter($elenco ?: [], function ($s) { return $s['stato'] === 'aperto'; }));
  $trovati = array_values(array_filter($elenco ?: [], function ($s) { return $s['stato'] === 'ritrovato'; }));
  $b = '<section class="sm-hero"><div class="sm-hero-txt"><span class="sm-kicker">Lost &amp; Found · Animali smarriti e ritrovati</span><h1>Se si perde, tutto il quartiere lo cerca con te</h1>'
    . '<p class="sm-lead">Pubblica l\'avviso gratis in un minuto. FurrFinder prepara la pagina e la locandina con il codice QR: chi lo vede ti avvisa subito, anche senza scaricare nulla.</p>'
    . '<p class="sm-cta"><a class="sm-btn sm-btn-red sm-btn-big" href="' . $PUBBLICA . '">Pubblica un avviso gratis</a> <a class="sm-btn sm-btn-ghost sm-btn-big" href="/smarriti/hai-trovato-un-animale">Ho trovato un animale</a></p></div>'
    . '<ol class="sm-how"><li><b>1</b><span><strong>Segna dove l\'hai perso</strong>La posizione esatta resta privata: sulla pagina compare solo la zona.</span></li>'
    . '<li><b>2</b><span><strong>Condividi la locandina</strong>Pronta per WhatsApp, Facebook e da stampare, con il codice QR.</span></li>'
    . '<li><b>3</b><span><strong>Ricevi gli avvistamenti</strong>Chi lo vede segna dove e quando: ti arriva una notifica sul telefono.</span></li></ol></section>';
  if ($elenco === false) $b .= '<p class="sm-note">Gli avvisi non sono raggiungibili in questo momento: riprova tra qualche minuto.</p>';
  $b .= '<h2 class="sm-h2" id="avvisi">Avvisi in corso' . ($aperti ? ' <span class="sm-count">' . count($aperti) . '</span>' : '') . '</h2>';
  if ($aperti) {
    $b .= '<div class="sm-filtro"><input type="search" id="cerca" placeholder="Cerca per città, zona, nome o razza" aria-label="Cerca fra gli avvisi" autocomplete="off">'
      . '<button class="sm-btn sm-btn-ghost" type="button" id="vicino">📍 Più vicini a me</button></div><p class="sm-muted" id="filtroInfo" hidden></p>';
    $b .= '<ul class="sm-grid" id="lista">' . implode('', array_map('sm_card', $aperti)) . '</ul><p class="sm-empty" id="nessuno" hidden>Nessun avviso corrisponde alla ricerca.</p>';
  } else $b .= '<p class="sm-empty">Nessun avviso aperto in questo momento. Speriamo che resti così!</p>';
  if ($citta) {
    $b .= '<h2 class="sm-h2">Per città</h2><ul class="sm-chips">';
    foreach ($citta as $c) $b .= '<li><a href="/smarriti/' . h($c['citta_slug']) . '/">' . h($c['citta']) . ($c['aperti'] ? ' <span>' . (int)$c['aperti'] . '</span>' : '') . '</a></li>';
    $b .= '</ul>';
  }
  if ($trovati) $b .= '<h2 class="sm-h2">Tornati a casa</h2><ul class="sm-grid">' . implode('', array_map('sm_card', array_slice($trovati, 0, 8))) . '</ul>';
  $b .= '<div class="sm-boxes">' . sm_box_pubblica() . sm_box_trovato() . '</div>';
  $b .= '<section class="sm-faq"><h2 class="sm-h2">Domande frequenti</h2>'
    . '<details><summary>Quanto costa pubblicare un avviso?</summary><p>Niente. Pubblicare l\'avviso, condividerlo, stampare la locandina e ricevere gli avvistamenti è gratis.</p></details>'
    . '<details><summary>Chi vede il mio indirizzo o il mio telefono?</summary><p>Nessuno. Sulla pagina compare solo una zona di circa 400 metri attorno al punto in cui l\'hai perso, mai il punto esatto. Il telefono compare solo se scegli tu di mostrarlo.</p></details>'
    . '<details><summary>Chi lo trova deve registrarsi?</summary><p>No. Basta aprire il link o inquadrare il codice QR della locandina e premere "L\'ho visto" o "L\'ho trovato".</p></details>'
    . '<details><summary>E il microchip?</summary><p>Il microchip resta il modo ufficiale per risalire al proprietario: qualsiasi veterinario può leggerlo. FurrFinder serve a far girare l\'avviso il più in fretta possibile, insieme alle segnalazioni a Comune e ASL.</p></details></section>';
  $items = []; $k = 1; foreach (array_slice($aperti, 0, 30) as $s) $items[] = ['@type' => 'ListItem', 'position' => $k++, 'url' => VR_SITO . $s['url'], 'name' => ucfirst(sm_perso($s)) . ': ' . $s['nome'] . ' a ' . $s['citta']];
  sm_pagina(['url' => '/smarriti/', 'indicizza' => true,
    'title' => 'Animali smarriti: cani e gatti persi e ritrovati, avvisi gratuiti | FurrFinder',
    'og' => 'Hai perso il tuo animale? Pubblica l\'avviso gratis con FurrFinder',
    'description' => 'Cani e gatti smarriti vicino a te: avvisi con foto, zona e data. Pubblica gratis l\'avviso per il tuo animale e ricevi le segnalazioni di chi lo vede.',
    'jsonld' => $items ? [['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => 'Animali smarriti', 'itemListElement' => $items]] : [],
    'js' => $aperti ? ['hub' => true] : null,
    'body' => $b]);
  exit;
}

// ---------- città ----------
$cs = $parti[0];
if (!preg_match('/^[a-z0-9-]{1,60}$/', $cs)) sm_errore(404, 'Pagina non trovata', 'Questo indirizzo non esiste.');
if (count($parti) === 1) {
  if ($path !== "/smarriti/$cs/") sm_redirect("/smarriti/$cs/");
  $elenco = sm_rpc('smarriti_elenco', ['p_citta_slug' => $cs, 'p_limite' => 200]);
  if ($elenco === false) sm_errore(503, 'Avvisi non raggiungibili', 'Riprova tra qualche minuto.');
  $aperti = array_values(array_filter($elenco, function ($s) { return $s['stato'] === 'aperto'; }));
  $trovati = array_values(array_filter($elenco, function ($s) { return $s['stato'] === 'ritrovato'; }));
  $nome = $elenco ? $elenco[0]['citta'] : ucwords(str_replace('-', ' ', $cs));
  $b = '<section class="sm-hero sm-hero-small"><span class="sm-kicker">Animali smarriti</span><h1>Animali smarriti a ' . h($nome) . '</h1>'
    . '<p class="sm-lead">' . ($aperti ? '<b>' . count($aperti) . ' ' . (count($aperti) === 1 ? 'avviso aperto' : 'avvisi aperti') . '</b> a ' . h($nome) . '. Se riconosci uno di loro, apri l\'avviso e premi "L\'ho visto": il proprietario riceve subito il tuo messaggio.' : 'In questo momento non ci sono avvisi aperti a ' . h($nome) . '.') . '</p>'
    . '<p class="sm-cta"><a class="sm-btn sm-btn-red" href="' . $PUBBLICA . '">Hai perso il tuo animale? Pubblica l\'avviso</a></p></section>';
  if ($aperti) $b .= '<div class="sm-filtro"><button class="sm-btn sm-btn-ghost" type="button" id="vicino">📍 Più vicini a me</button></div><ul class="sm-grid" id="lista">' . implode('', array_map('sm_card', $aperti)) . '</ul>';
  if ($trovati) $b .= '<h2 class="sm-h2">Tornati a casa a ' . h($nome) . '</h2><ul class="sm-grid">' . implode('', array_map('sm_card', $trovati)) . '</ul>';
  $b .= '<div class="sm-boxes">' . sm_box_pubblica() . sm_box_trovato() . '</div>';
  if (!$elenco) http_response_code(404);
  sm_pagina(['url' => "/smarriti/$cs/", 'indicizza' => (bool)$elenco, 'js' => $aperti ? ['hub' => true] : null,
    'title' => "Animali smarriti a $nome: cani e gatti persi | FurrFinder",
    'description' => "Cani e gatti smarriti a $nome: avvisi con foto, zona e data della scomparsa. Se l'hai visto, segnalalo al proprietario in un minuto.",
    'crumbs' => [['Animali smarriti', '/smarriti/'], [$nome, "/smarriti/$cs/"]],
    'body' => $b]);
  exit;
}

// ---------- avviso ----------
if (count($parti) !== 2 || !preg_match('/-([a-z2-9]{6})$/i', $parti[1], $m)) sm_errore(404, 'Avviso non trovato', 'Questo indirizzo non esiste o è stato scritto male.');
$codice = strtoupper($m[1]);
$s = sm_rpc('smarrito_pubblico', ['p_codice' => $codice], 10);
if ($s === false) sm_errore(503, 'Avviso non raggiungibile', 'Riprova tra qualche minuto.');
if (!$s) sm_errore(404, 'Avviso non trovato', 'Questo avviso non esiste più.');
if ($path !== $s['url']) sm_redirect($s['url'] . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));

$nome = $s['nome']; $citta = $s['citta']; $stato = $s['stato'];
$crumbs = [['Animali smarriti', '/smarriti/'], [$citta, '/smarriti/' . $s['citta_slug'] . '/'], [$nome, $s['url']]];

if ($stato === 'chiuso' || empty($s['dettagli'])) {
  sm_pagina(['url' => $s['url'], 'indicizza' => false, 'title' => "Avviso chiuso: $nome | FurrFinder",
    'description' => "L'avviso per $nome a $citta è stato chiuso dal proprietario.", 'crumbs' => $crumbs,
    'body' => '<section class="sm-hero sm-hero-small"><h1>Questo avviso è chiuso</h1><p class="sm-lead">Il proprietario di ' . h($nome) . ' ha chiuso la segnalazione. Grazie a tutti quelli che l\'hanno condivisa.</p><p><a class="sm-btn" href="/smarriti/' . h($s['citta_slug']) . '/">Altri avvisi a ' . h($citta) . '</a></p></section><div class="sm-boxes">' . sm_box_pubblica() . sm_box_trovato() . '</div>']);
  exit;
}

$foto = sm_foto($s['foto_path'] ?? '');
$trovato = $stato === 'ritrovato';
$specie = sm_specie($s);
$dettagli = array_filter([ucfirst($specie), $s['razza'] ?? '', ($s['sesso'] ?? '') === 'F' ? 'femmina' : ((($s['sesso'] ?? '') === 'M') ? 'maschio' : '')]);
$dove = implode(', ', array_filter([$s['luogo'] ?? '', $citta . (!empty($s['provincia']) ? ' (' . $s['provincia'] . ')' : '')]));
$quando = sm_giorno($s['perso_il'], true) . ' verso le ' . sm_ora($s['perso_il']);

if ($trovato) {
  $title = "$nome è " . sm_tornato($s) . " a casa! | FurrFinder";
 $og = (sm_f($s) ? 'Ritrovata' : 'Ritrovato') . ": $nome è " . sm_tornato($s) . " a casa";
  $desc = "$nome, $specie " . sm_perso($s) . " a $citta, è " . sm_tornato($s) . " a casa. Grazie a tutti quelli che hanno condiviso l'avviso.";
} else {
  $title = mb_strtoupper(sm_perso($s), 'UTF-8') . ": $nome, $specie a $citta | FurrFinder";
  $og = ucfirst(sm_perso($s)) . " $nome a $citta: aiutaci a ritrovarl" . (sm_f($s) ? 'a' : 'o');
  $desc = ucfirst($specie) . ' ' . sm_perso($s) . " a $dove il " . sm_giorno($s['perso_il']) . '. ' . (!empty($s['descrizione']) ? mb_substr($s['descrizione'], 0, 140, 'UTF-8') . ' ' : '') . 'Se l\'hai visto, segnalalo qui.';
}

$b = '<article class="sm-lost ' . ($trovato ? 'is-found' : '') . '">';
$b .= '<div class="sm-lost-band">' . ($trovato ? (sm_f($s) ? 'Ritrovata!' : 'Ritrovato!') : h(mb_strtoupper(sm_perso($s), 'UTF-8'))) . '</div>';
$b .= '<div class="sm-lost-grid"><div class="sm-lost-photo">' . ($foto ? '<img src="' . h($foto) . '" alt="Foto di ' . h($nome) . '" width="640" height="640">' : '<span class="sm-card-nofoto">' . h(mb_substr($nome, 0, 1, 'UTF-8')) . '</span>')
  . (!empty($s['ricompensa']) ? '<span class="sm-reward">Ricompensa</span>' : '') . '</div><div class="sm-lost-info">';
$b .= '<h1>' . h($nome) . '</h1><p class="sm-lost-kind">' . h(implode(' · ', $dettagli)) . '</p>';
if ($trovato) {
  $b .= '<p class="sm-found-msg">È ' . sm_tornato($s) . ' a casa ' . h(sm_fa($s['chiuso_il'])) . '. Grazie a tutti quelli che hanno condiviso l\'avviso e lo hanno cercato!</p>';
} else {
  $b .= '<dl class="sm-facts"><dt>' . (sm_f($s) ? 'Vista' : 'Visto') . ' l\'ultima volta</dt><dd>' . h($dove) . '</dd><dt>Quando</dt><dd>' . h(ucfirst($quando)) . '</dd>';
  if (!empty($s['descrizione'])) $b .= '<dt>Come riconoscerlo</dt><dd>' . nl2br(h($s['descrizione'])) . '</dd>';
  $b .= '</dl>';
  $b .= '<div class="sm-act"><button class="sm-btn sm-btn-red sm-btn-big" type="button" data-avv="visto">L\'ho visto</button><button class="sm-btn sm-btn-green sm-btn-big" type="button" data-avv="trovato">L\'ho trovato</button></div>';
  if (!empty($s['telefono'])) $b .= '<p class="sm-tel">Oppure chiama il proprietario: <a href="tel:' . h(sm_tel_link($s['telefono'])) . '" data-ev="chiama">' . h($s['telefono']) . '</a></p>';
  if (!empty($s['avvistamenti'])) $b .= '<p class="sm-muted">' . (int)$s['avvistamenti'] . ' ' . ((int)$s['avvistamenti'] === 1 ? 'segnalazione ricevuta' : 'segnalazioni ricevute') . ' finora. Ogni dettaglio aiuta.</p>';
}
$b .= '<div class="sm-share" id="share"><span>' . ($trovato ? 'Condividi la buona notizia' : 'Condividi: più persone lo vedono, prima torna a casa') . '</span><div class="sm-share-btns">'
  . '<a class="sm-btn sm-wa" data-share="whatsapp" target="_blank" rel="noopener" href="#">WhatsApp</a>'
  . '<a class="sm-btn sm-fb" data-share="facebook" target="_blank" rel="noopener" href="#">Facebook</a>'
  . '<button class="sm-btn sm-btn-ghost" type="button" data-share="copia">Copia link</button>'
  . '<button class="sm-btn sm-btn-ghost" type="button" data-share="altro" hidden>Altro…</button></div><p class="sm-share-msg" id="shareMsg" hidden></p></div>';
$b .= '</div></div>';

if (!$trovato) {
  $b .= '<section class="sm-mapbox"><h2 class="sm-h2">Zona in cui si è perso</h2><div id="smMap" class="sm-map" aria-label="Mappa della zona"></div>'
    . '<p class="sm-muted">Il cerchio indica la zona in modo approssimativo: per rispetto della privacy il punto esatto non è pubblico.</p></section>';
  // modulo per gli avvistamenti
  $b .= '<section class="sm-form" id="avvisa" hidden><h2 class="sm-h2" id="fTitle">Dove l\'hai visto?</h2>'
    . '<form id="fAvv" novalidate>'
    . '<div class="sm-seg" role="radiogroup" aria-label="Che cosa è successo"><label><input type="radio" name="tipo" value="visto" checked> L\'ho visto</label><label><input type="radio" name="tipo" value="trovato"> L\'ho trovato, è con me</label></div>'
    . '<p class="sm-muted">Tocca la mappa dove l\'hai visto, oppure usa la tua posizione se sei ancora lì.</p>'
    . '<div class="sm-row"><button class="sm-btn sm-btn-ghost" type="button" id="fGeo">📍 Sono lì adesso</button><span id="fPos" class="sm-muted"></span></div>'
    . '<div id="fMap" class="sm-map sm-map-small"></div>'
    . '<div class="sm-fields"><label>Quando<input type="datetime-local" id="fQuando"></label>'
    . '<label class="sm-wide">Messaggio per il proprietario<textarea id="fMsg" maxlength="1000" placeholder="Es. Era vicino al giardino di via…, andava verso il parco. Sembrava spaventato."></textarea></label>'
    . '<label>Il tuo nome (facoltativo)<input type="text" id="fNome" maxlength="80" autocomplete="name"></label>'
    . '<label>Telefono o email<input type="text" id="fCont" maxlength="120" autocomplete="tel" placeholder="Per farti ricontattare"></label>'
    . '<label class="sm-wide">Una foto (facoltativa)<input type="file" id="fFoto" accept="image/*"></label>'
    . '<label class="sm-hp" aria-hidden="true">Non compilare<input type="text" id="fSito" tabindex="-1" autocomplete="off"></label></div>'
    . '<p class="sm-muted sm-small">Il telefono o l\'email li vede solo il proprietario. <a href="/privacy.html" target="_blank" rel="noopener">Privacy</a></p>'
    . '<div class="sm-row"><button class="sm-btn sm-btn-red sm-btn-big" type="submit" id="fInvia">Invia al proprietario</button><button class="sm-btn sm-btn-ghost" type="button" id="fAnnulla">Annulla</button></div>'
    . '<p class="sm-note" id="fEsito" hidden></p></form></section>';
  $b .= '<div class="sm-boxes"><aside class="sm-box"><h2>Se lo vedi</h2><ul><li>Avvicinati con calma e chiamalo per nome, senza inseguirlo: un animale spaventato scappa.</li><li>Se si lascia prendere, portalo da un veterinario: con il microchip si conferma che è lui.</li><li>Segnala sempre dove e quando l\'hai visto, anche se non riesci a prenderlo.</li></ul><a class="sm-link" href="/veterinari/">Trova un veterinario vicino →</a></aside>'
    . '<aside class="sm-box"><h2>Proteggi il tuo animale</h2><p>Con <b>FurrFinder</b> hai il libretto sanitario sempre in tasca e, se si perde, l\'avviso parte in un minuto. È gratis.</p><a class="sm-btn" data-ev="registrati" href="/app/?per=proprietario">Crea la scheda del tuo animale</a></aside></div>';
} else {
  $b .= '<div class="sm-boxes">' . sm_box_pubblica() . '<aside class="sm-box"><h2>Proteggi il tuo animale</h2><p>Con <b>FurrFinder</b> hai il libretto sanitario sempre in tasca e, se si perde, l\'avviso parte in un minuto. È gratis.</p><a class="sm-btn" data-ev="registrati" href="/app/?per=proprietario">Crea la scheda del tuo animale</a></aside></div>';
}
$b .= '<p class="sm-small sm-muted sm-report">Avviso pubblicato il ' . h(sm_giorno($s['creato_il'])) . ' dal proprietario. <button type="button" class="sm-linkbtn" id="segnala">Segnala un problema</button></p>';
$b .= '</article>';

$jsonld = [];
if ($foto) $jsonld[] = ['@context' => 'https://schema.org', '@type' => 'ImageObject', 'contentUrl' => $foto, 'name' => ($trovato ? 'Ritrovato: ' : ucfirst(sm_perso($s)) . ': ') . $nome . ', ' . $specie . ' a ' . $citta];

sm_pagina(['url' => $s['url'], 'indicizza' => true, 'articolo' => true, 'mappa' => !$trovato,
  'colore' => $trovato ? '#15803d' : '#b91c1c',
  'title' => $title, 'og' => $og, 'description' => $desc, 'immagine' => $foto ?: null, 'crumbs' => $crumbs, 'jsonld' => $jsonld,
  'js' => ['api' => VR_SUPABASE_URL, 'key' => VR_SUPABASE_KEY, 'codice' => $s['codice'], 'id' => $s['id'] ?? null, 'nome' => $nome,
    'stato' => $stato, 'lat' => $s['lat'], 'lon' => $s['lon'], 'raggio' => $s['raggio_m'] ?? 400, 'url' => VR_SITO . $s['url'],
    'testo' => $trovato ? "$nome è " . sm_tornato($s) . " a casa! Grazie a tutti quelli che hanno condiviso l'avviso."
      : 'AIUTO! ' . $nome . ' (' . $specie . ') si è ' . sm_perso($s) . ' a ' . $dove . ' il ' . sm_giorno($s['perso_il']) . '. Se l\'hai visto, segnalalo qui:'],
  'body' => $b]);
