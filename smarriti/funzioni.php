<?php
// =============================================================
//  FURRFINDER · Sezione pubblica /smarriti/ — strumenti comuni (patch 38)
//  Compatibile con PHP 7.4 e successivi.
//  Le impostazioni (database, indirizzo del sito, indirizzo dell'app) sono in config.php.
// =============================================================
require_once __DIR__ . '/config.php';
if (!defined('SM_APP')) define('SM_APP', '');
if (!defined('SM_MARCA')) define('SM_MARCA', 'vetroom');
if (!defined('SM_RIMANDA')) define('SM_RIMANDA', '');

// Per quanti secondi tenere la copia locale di una pagina (le pagine condivise su WhatsApp ricevono molte visite insieme)
define('SM_CACHE_SECONDI', 60);

// Se il server non ha l'estensione mbstring (raro), versioni semplici delle stesse funzioni
if (!function_exists('mb_strtoupper')) { function mb_strtoupper($s, $e = null) { return strtoupper($s); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $e = null) { return strtolower($s); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $a, $l = null, $e = null) { return $l === null ? substr($s, $a) : substr($s, $a, $l); } }

if (!function_exists('h')) {
  function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// ---------- dati ----------
function sm_cache_dir() {
  $dir = sys_get_temp_dir();
  if (!is_writable($dir)) { $dir = __DIR__ . '/cache'; if (!is_dir($dir)) @mkdir($dir, 0755); }
  return rtrim($dir, '/');
}
function sm_chiama($nome, $args) {
  $url = VR_SUPABASE_URL . '/rest/v1/rpc/' . $nome;
  $body = json_encode((object)$args);
  $headers = ['apikey: ' . VR_SUPABASE_KEY, 'Content-Type: application/json', 'Accept: application/json'];
  if (strpos(VR_SUPABASE_KEY, 'eyJ') === 0) $headers[] = 'Authorization: Bearer ' . VR_SUPABASE_KEY;
  if (function_exists('curl_init')) {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6]);
    $out = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
  } else {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => 15, 'ignore_errors' => true]]);
    $out = @file_get_contents($url, false, $ctx);
    $code = 0; if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
  }
  if ($code !== 200 || $out === false || $out === null) return ['errore' => true];
  return ['dati' => json_decode($out, true)];
}
// Chiama una funzione pubblica del database, con una copia locale per pochi secondi
function sm_rpc($nome, $args = [], $secondi = SM_CACHE_SECONDI) {
  $f = sm_cache_dir() . '/furrfinder_' . $nome . '_' . md5(VR_SUPABASE_URL . json_encode($args)) . '.json';
  if ($secondi > 0 && is_file($f) && (time() - filemtime($f)) < $secondi) {
    $j = json_decode((string)file_get_contents($f), true);
    if (is_array($j) && array_key_exists('dati', $j)) return $j['dati'];
  }
  $r = sm_chiama($nome, $args);
  if (empty($r['errore'])) { if ($secondi > 0) @file_put_contents($f, json_encode($r, JSON_UNESCAPED_UNICODE), LOCK_EX); return $r['dati']; }
  if (is_file($f)) { $j = json_decode((string)file_get_contents($f), true); if (is_array($j) && array_key_exists('dati', $j)) return $j['dati']; }   // meglio vecchi che niente
  return false;
}

// ---------- testi ----------
function sm_foto($path) {
  if (!$path) return '';
  return VR_SUPABASE_URL . '/storage/v1/object/public/smarriti/' . implode('/', array_map('rawurlencode', explode('/', $path)));
}
function sm_dt($iso) { $d = new DateTime($iso); $d->setTimezone(new DateTimeZone('Europe/Rome')); return $d; }
function sm_giorno($iso, $conGiornoSettimana = false) {
  if (!$iso) return '';
  $mesi = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
  $gg = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];
  $d = sm_dt($iso);
  $s = (int)$d->format('j') . ' ' . $mesi[(int)$d->format('n') - 1];
  if ($d->format('Y') !== date('Y')) $s .= ' ' . $d->format('Y');
  return $conGiornoSettimana ? $gg[(int)$d->format('w')] . ' ' . $s : $s;
}
function sm_ora($iso) { return $iso ? sm_dt($iso)->format('H:i') : ''; }
function sm_fa($iso) {
  $s = time() - strtotime($iso);
  if ($s < 3600) return 'meno di un\'ora fa';
  if ($s < 86400) { $o = (int)floor($s / 3600); return $o === 1 ? 'un\'ora fa' : "$o ore fa"; }
  $g = (int)floor($s / 86400); return $g === 1 ? 'ieri' : "$g giorni fa";
}
function sm_f($s) { return ($s['sesso'] ?? '') === 'F'; }
function sm_perso($s) { return sm_f($s) ? 'smarrita' : 'smarrito'; }
function sm_tornato($s) { return sm_f($s) ? 'tornata' : 'tornato'; }
function sm_specie($s) { return mb_strtolower((string)($s['specie'] ?? ''), 'UTF-8') ?: 'animale'; }
function sm_tel_link($t) { $n = preg_replace('/[^\d+]/', '', (string)$t); if ($n !== '' && $n[0] !== '+' && preg_match('/^[03]\d{5,10}$/', $n)) $n = '+39' . $n; return $n; }

// I link all'app e all'elenco dei veterinari puntano a vetroom.it
function sm_assoluti($html) {
  return str_replace(['href="/app/', 'href="/veterinari/'], ['href="' . SM_APP . '/app/', 'href="' . SM_APP . '/veterinari/'], $html);
}

// ---------- zona mostrata in pubblico ----------
// Quando l'animale è tornato a casa la via non serve più: resta solo una zona generica (municipio o quartiere) e la città.
function sm_zona_vaga($s) {
  $l = trim((string)($s['luogo'] ?? ''));
  if ($l === '') return '';
  $z = preg_split('/,?\s*\bvicino\s+(a|al|alla|allo|ai|agli|alle|all\')\s*/iu', $l)[0];   // "Municipio Roma VII, vicino a Via …" → "Municipio Roma VII"
  $z = trim(explode(',', $z)[0]);
  $citta = trim((string)($s['citta'] ?? ''));
  if ($citta !== '') $z = trim(preg_replace('/^(Municipio|Municipalit[àa])\s+' . preg_quote($citta, '/') . '\s+/iu', '$1 ', $z));   // "Municipio Roma VII" → "Municipio VII"
  if ($z === '' || mb_strlen($z, 'UTF-8') > 40 || ($citta !== '' && mb_strtolower($z, 'UTF-8') === mb_strtolower($citta, 'UTF-8'))) return '';
  if (preg_match('/^(Municipio|Municipalit[àa]|Circoscrizione|Quartiere|Rione|Zona)\b/iu', $z)) return $z;
  // Tutto il resto lo teniamo solo se non sembra un indirizzo (via, piazza… o un numero civico)
  if (preg_match('/^(via|viale|v\.le|piazza|p\.za|piazzale|piazzetta|largo|corso|c\.so|vicolo|vico|lungotevere|lungomare|lungolago|strada|stradone|salita|discesa|circonvallazione|borgo|contrada|c\.da|localit[àa]|loc\.|traversa|rampa|scalinata|galleria|ponte|parco|giardino|villa|via\.)\b/iu', $z) || preg_match('/\d/', $z)) return '';
  return $z;
}
// Il testo della zona da mostrare: per gli avvisi aperti quello scelto dal proprietario, per i ritrovati solo la zona generica
function sm_luogo_pubblico($s) { return ($s['stato'] ?? '') === 'ritrovato' ? sm_zona_vaga($s) : trim((string)($s['luogo'] ?? '')); }
// Coordinate: per i ritrovati arrotondate a circa un chilometro
function sm_coord($s, $k) { if (!isset($s[$k]) || $s[$k] === null || $s[$k] === '') return null; return ($s['stato'] ?? '') === 'ritrovato' ? round((float)$s[$k], 2) : $s[$k]; }

function sm_card($s) {
  $foto = sm_foto($s['foto_path'] ?? '');
  $trovato = ($s['stato'] ?? '') === 'ritrovato';
  $luogo = sm_luogo_pubblico($s);
  $cerca = mb_strtolower(implode(' ', [$s['nome'] ?? '', $s['specie'] ?? '', $s['razza'] ?? '', $luogo, $s['citta'] ?? '', $s['provincia'] ?? '']), 'UTF-8');
  return '<li class="sm-card' . ($trovato ? ' is-found' : '') . '" data-cerca="' . h($cerca) . '"'
    . (sm_coord($s, 'lat') !== null ? ' data-lat="' . h(sm_coord($s, 'lat')) . '" data-lon="' . h(sm_coord($s, 'lon')) . '"' : '') . '><a href="' . h($s['url']) . '">'
    . ($foto ? '<img src="' . h($foto) . '" alt="' . h($s['nome']) . '" loading="lazy" width="320" height="240">' : '<span class="sm-card-nofoto">' . h(mb_substr($s['nome'], 0, 1, 'UTF-8')) . '</span>')
    . '<span class="sm-card-tag">' . ($trovato ? (sm_f($s) ? 'Ritrovata' : 'Ritrovato') : ucfirst(sm_perso($s))) . '</span>'
    . '<span class="sm-card-body"><strong>' . h($s['nome']) . '</strong><span>' . h(ucfirst(sm_specie($s)) . (!empty($s['razza']) ? ' · ' . $s['razza'] : '')) . '</span>'
    . '<span class="sm-card-where">' . h(implode(', ', array_filter([$luogo, $s['citta'] ?? '']))) . '</span>'
    . '<span class="sm-card-km" hidden></span><span class="sm-card-when">' . ($trovato ? (sm_f($s) ? 'Tornata' : 'Tornato') . ' a casa ' . h(sm_fa($s['chiuso_il'])) : 'Dal ' . h(sm_giorno($s['perso_il']))) . '</span></span></a></li>';
}

// ---------- pagina ----------
function sm_pagina($o) {
  $canon = VR_SITO . $o['url'];
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: public, max-age=60');
  if (empty($o['indicizza'])) header('X-Robots-Tag: noindex, follow');
  $img = $o['immagine'] ?? (SM_MARCA === 'furrfinder' ? VR_SITO . '/assets/og-image.png' : VR_SITO . '/assets/og-vetroom.png');
  ?><!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="<?= h($o['colore'] ?? (SM_MARCA === 'furrfinder' ? '#0b2545' : '#033860')) ?>" />
  <title><?= h($o['title']) ?></title>
  <meta name="description" content="<?= h($o['description']) ?>" />
  <meta name="robots" content="<?= empty($o['indicizza']) ? 'noindex,follow' : 'index,follow,max-image-preview:large' ?>" />
  <link rel="canonical" href="<?= h($canon) ?>" />
<?php if (SM_MARCA === 'furrfinder') { ?>
  <link rel="icon" href="/favicon.ico" sizes="any" />
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png" />
  <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png" />
  <link rel="manifest" href="/manifest.webmanifest" />
  <link rel="preload" href="/assets/fonts/nunito-latin-900-normal.woff2" as="font" type="font/woff2" crossorigin />
  <link rel="preload" href="/assets/fonts/source-sans-3-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin />
  <link rel="stylesheet" href="/assets/site.css" />
<?php } else { ?>
  <link rel="icon" href="/favicon.ico" />
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png" />
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
  <link rel="stylesheet" href="/assets/site.css?v=2026-10-10" />
<?php } ?>
  <meta property="og:locale" content="it_IT" />
  <meta property="og:site_name" content="<?= SM_MARCA === 'furrfinder' ? 'FurrFinder' : 'Vetroom' ?>" />
  <meta property="og:type" content="<?= !empty($o['articolo']) ? 'article' : 'website' ?>" />
  <meta property="og:title" content="<?= h($o['og'] ?? $o['title']) ?>" />
  <meta property="og:description" content="<?= h($o['description']) ?>" />
  <meta property="og:url" content="<?= h($canon) ?>" />
  <meta property="og:image" content="<?= h($img) ?>" />
  <meta name="twitter:card" content="summary_large_image" />
<?php if (!empty($o['mappa'])) { ?>
  <link rel="stylesheet" href="/smarriti/vendor/leaflet-1.9.4/leaflet.css" />
<?php } ?>
  <link rel="stylesheet" href="/smarriti/smarriti.css?v=2026-10-11d" />
  <?php foreach (($o['jsonld'] ?? []) as $x) echo '<script type="application/ld+json">' . json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>\n"; ?>
</head>
<body class="sm <?= SM_MARCA === 'furrfinder' ? 'sm-ff' : 'sm-vr' ?>">
<?php if (SM_MARCA === 'furrfinder') { ?>
<a class="skip" href="#contenuto">Vai al contenuto</a>
<header class="site-header" id="header">
  <div class="wrap">
    <div class="bar">
      <a class="brand" href="/" aria-label="FurrFinder, home"><img src="/assets/logo-mark.png" alt="" width="38" height="38">FurrFinder</a>
      <nav class="nav" aria-label="Principale">
        <a href="/come-funziona.html">Come funziona</a>
        <a href="/funzionalita.html">Funzioni</a>
        <a href="/guide.html">Guide</a>
        <a href="/smarriti/" aria-current="page">Animali smarriti</a>
        <a href="/#veterinari">Per i veterinari</a>
      </nav>
      <div class="bar-actions">
        <a class="btn btn-ghost btn-sm" href="<?= h(SM_APP) ?>/app/?per=proprietario">Accedi</a>
        <a class="btn btn-primary btn-sm" href="<?= h(SM_APP) ?>/app/?per=proprietario&amp;dopo=smarrito">Pubblica un avviso</a>
        <button class="menu-btn" type="button" aria-expanded="false" aria-controls="mobile-nav" aria-label="Apri il menu" data-menu>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
      </div>
    </div>
    <nav class="mobile-nav" id="mobile-nav" aria-label="Menu">
      <a href="/come-funziona.html">Come funziona</a>
      <a href="/funzionalita.html">Funzioni</a>
      <a href="/guide.html">Guide</a>
      <a href="/smarriti/">Animali smarriti</a>
      <a href="/#veterinari">Per i veterinari</a>
      <a href="<?= h(SM_APP) ?>/app/?per=proprietario">Accedi</a>
    </nav>
  </div>
</header>
<?php } else { ?>
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
          <a href="/smarriti/">Animali smarriti</a>
          <a href="/trova-struttura.html">Sei un veterinario?</a>
          <a class="btn small primary" href="/app/">Accedi</a>
        </nav>
        <button class="nav-toggle" type="button" aria-controls="nav" aria-expanded="false" aria-label="Apri menu">☰</button>
      </div>
    </div>
  </header>
<?php } ?>
  <main class="sm-main" id="contenuto">
    <div class="container">
<?php if (!empty($o['crumbs'])) { ?>
      <nav class="sm-crumbs" aria-label="Percorso"><?php $u = []; foreach ($o['crumbs'] as $k => $c) $u[] = $k === count($o['crumbs']) - 1 ? '<span aria-current="page">' . h($c[0]) . '</span>' : '<a href="' . h($c[1]) . '">' . h($c[0]) . '</a>'; echo implode(' › ', $u); ?></nav>
<?php } ?>
<?= sm_assoluti($o['body']) ?>
    </div>
  </main>
<?php if (SM_MARCA === 'furrfinder') { ?>
<footer class="site-footer">
  <div class="wrap">
    <div class="foot-cols">
      <div>
        <a class="brand" href="/" style="font-size:1.15rem"><img src="/assets/logo-mark.png" alt="" width="30" height="30" style="width:30px;height:30px">FurrFinder</a>
        <p style="margin-top:12px;max-width:34ch">Il libretto sanitario digitale di cane e gatto, collegato al tuo veterinario.</p>
        <p style="margin-top:12px"><a href="https://www.vetroom.it/">Sei un veterinario? Scopri Vetroom</a></p>
      </div>
      <div><h4>FurrFinder</h4><ul><li><a href="/come-funziona.html">Come funziona</a></li><li><a href="/funzionalita.html">Funzionalità</a></li><li><a href="/per-cani-e-gatti.html">Per cani e gatti</a></li><li><a href="/sicurezza.html">Sicurezza dei dati</a></li></ul></div>
      <div><h4>Aiuto</h4><ul><li><a href="/guide.html">Guide</a></li><li><a href="/smarriti/">Animali smarriti</a></li><li><a href="/faq.html">Domande frequenti</a></li><li><a href="/chi-siamo.html">Chi siamo</a></li><li><a href="/contatti.html">Contatti</a></li></ul></div>
      <div><h4>Note legali</h4><ul><li><a href="/privacy.html">Privacy</a></li><li><a href="/cookie-policy.html">Cookie</a></li><li><a href="/termini.html">Termini</a></li></ul></div>
    </div>
    <p class="legal">FurrFinder è un servizio di AF&amp;B S.r.l.s. · Via Mario Ugo Guattari 48, 00173 Roma (RM) · P.IVA 13698751008 · REA RM-1466364 · Capitale sociale € 2.700,00 i.v. · info@vetroom.it<br>Gli avvisi sono pubblicati dai proprietari, che ne sono responsabili. Mappe © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a>.</p>
  </div>
</footer>
<script src="/assets/site.js" defer></script>
<?php } else { ?>
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
          <a href="/smarriti/">Animali smarriti</a>
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
        <span>© <?= date('Y') ?> Vetroom è un servizio di AF&amp;B S.r.l.s. · Via Mario Ugo Guattari 48, 00173 Roma (RM) · P.IVA 13698751008 · REA RM-1466364 · Capitale sociale € 2.700,00 i.v. · Gli avvisi sono pubblicati dai proprietari, che ne sono responsabili. Mappe © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a>.</span>
        <span><a href="/privacy.html">Informativa privacy</a> · <a href="/termini.html">Termini</a></span>
      </div>
    </div>
  </footer>
  <script src="/assets/cookie-notice.js" defer></script>
  <script>
    (function () {
      var t = document.querySelector('.nav-toggle'), n = document.getElementById('nav');
      if (t && n) t.addEventListener('click', function () { var o = n.classList.toggle('open'); t.setAttribute('aria-expanded', String(o)); });
    })();
  </script>
<?php } ?>
<?php if (!empty($o['js'])) { ?>
  <script>window.SM = <?= json_encode($o['js'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php if (!empty($o['mappa'])) { ?>
  <script src="/smarriti/vendor/leaflet-1.9.4/leaflet.js"></script>
<?php } ?>
  <script src="/smarriti/smarriti.js?v=2026-10-11d"></script>
<?php } ?>
</body>
</html>
<?php
}
