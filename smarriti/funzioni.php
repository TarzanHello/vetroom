<?php
// =============================================================
//  FURRFINDER · Sezione pubblica /smarriti/ — strumenti comuni (patch 38)
//  Compatibile con PHP 7.4 e successivi.
//  Usa le stesse impostazioni di /veterinari/ (indirizzo Supabase e chiave PUBBLICA).
// =============================================================
require_once __DIR__ . '/../veterinari/config.php';

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

function sm_card($s) {
  $foto = sm_foto($s['foto_path'] ?? '');
  $trovato = ($s['stato'] ?? '') === 'ritrovato';
  return '<li class="sm-card' . ($trovato ? ' is-found' : '') . '"><a href="' . h($s['url']) . '">'
    . ($foto ? '<img src="' . h($foto) . '" alt="' . h($s['nome']) . '" loading="lazy" width="320" height="240">' : '<span class="sm-card-nofoto">' . h(mb_substr($s['nome'], 0, 1, 'UTF-8')) . '</span>')
    . '<span class="sm-card-tag">' . ($trovato ? (sm_f($s) ? 'Ritrovata' : 'Ritrovato') : ucfirst(sm_perso($s))) . '</span>'
    . '<span class="sm-card-body"><strong>' . h($s['nome']) . '</strong><span>' . h(ucfirst(sm_specie($s)) . (!empty($s['razza']) ? ' · ' . $s['razza'] : '')) . '</span>'
    . '<span class="sm-card-where">' . h(implode(', ', array_filter([$s['luogo'] ?? '', $s['citta'] ?? '']))) . '</span>'
    . '<span class="sm-card-when">' . ($trovato ? (sm_f($s) ? 'Tornata' : 'Tornato') . ' a casa ' . h(sm_fa($s['chiuso_il'])) : 'Dal ' . h(sm_giorno($s['perso_il']))) . '</span></span></a></li>';
}

// ---------- pagina ----------
function sm_pagina($o) {
  $canon = VR_SITO . $o['url'];
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: public, max-age=60');
  if (empty($o['indicizza'])) header('X-Robots-Tag: noindex, follow');
  $img = $o['immagine'] ?? (VR_SITO . '/app/img/furrfinder-512.png');
  ?><!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="<?= h($o['colore'] ?? '#0b2545') ?>" />
  <title><?= h($o['title']) ?></title>
  <meta name="description" content="<?= h($o['description']) ?>" />
  <meta name="robots" content="<?= empty($o['indicizza']) ? 'noindex,follow' : 'index,follow,max-image-preview:large' ?>" />
  <link rel="canonical" href="<?= h($canon) ?>" />
  <link rel="icon" href="/favicon.ico" />
  <link rel="apple-touch-icon" href="/app/img/furrfinder-180.png" />
  <meta property="og:locale" content="it_IT" />
  <meta property="og:site_name" content="FurrFinder" />
  <meta property="og:type" content="<?= !empty($o['articolo']) ? 'article' : 'website' ?>" />
  <meta property="og:title" content="<?= h($o['og'] ?? $o['title']) ?>" />
  <meta property="og:description" content="<?= h($o['description']) ?>" />
  <meta property="og:url" content="<?= h($canon) ?>" />
  <meta property="og:image" content="<?= h($img) ?>" />
  <meta name="twitter:card" content="summary_large_image" />
  <link rel="stylesheet" href="/assets/site.css?v=2026-10-10" />
<?php if (!empty($o['mappa'])) { ?>
  <link rel="stylesheet" href="/app/vendor/leaflet-1.9.4/leaflet.css" />
<?php } ?>
  <link rel="stylesheet" href="/smarriti/smarriti.css?v=2026-10-11d" />
  <?php foreach (($o['jsonld'] ?? []) as $x) echo '<script type="application/ld+json">' . json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>\n"; ?>
</head>
<body class="sm">
  <header class="sm-header">
    <div class="container sm-header-in">
      <a class="sm-brand" href="/smarriti/" aria-label="FurrFinder, animali smarriti"><img src="/app/img/furrfinder-mark.png" alt="" width="34" height="34" /><span>FurrFinder</span></a>
      <nav class="sm-nav" aria-label="Navigazione">
        <a href="/smarriti/">Animali smarriti</a>
        <a href="/smarriti/hai-trovato-un-animale">Hai trovato un animale?</a>
        <a class="sm-btn sm-btn-red" href="/app/?per=proprietario&amp;dopo=smarrito">Pubblica un avviso</a>
      </nav>
    </div>
  </header>
  <main class="sm-main">
    <div class="container">
<?php if (!empty($o['crumbs'])) { ?>
      <nav class="sm-crumbs" aria-label="Percorso"><?php $u = []; foreach ($o['crumbs'] as $k => $c) $u[] = $k === count($o['crumbs']) - 1 ? '<span aria-current="page">' . h($c[0]) . '</span>' : '<a href="' . h($c[1]) . '">' . h($c[0]) . '</a>'; echo implode(' › ', $u); ?></nav>
<?php } ?>
<?= $o['body'] ?>
    </div>
  </main>
  <footer class="sm-footer">
    <div class="container">
      <div class="sm-footer-grid">
        <div>
          <a class="sm-brand" href="/smarriti/"><img src="/app/img/furrfinder-mark.png" alt="" width="30" height="30" loading="lazy" /><span>FurrFinder</span></a>
          <p>Il libretto sanitario digitale del tuo animale e gli avvisi gratuiti per ritrovarlo se si perde.</p>
        </div>
        <nav aria-label="Smarriti">
          <h4>Animali smarriti</h4>
          <a href="/smarriti/">Avvisi in corso</a>
          <a href="/smarriti/hai-perso-il-tuo-animale">Hai perso il tuo animale?</a>
          <a href="/smarriti/hai-trovato-un-animale">Hai trovato un animale?</a>
          <a href="/veterinari/">Veterinari per città</a>
        </nav>
        <div>
          <h4>Contatti</h4>
          <a href="mailto:info@vetroom.it">info@vetroom.it</a>
          <a href="/privacy.html">Privacy</a>
          <a href="/termini.html">Termini di servizio</a>
        </div>
      </div>
      <p class="sm-legal">© <?= date('Y') ?> FurrFinder e Vetroom sono servizi di AF&amp;B S.r.l.s. · Via Mario Ugo Guattari 48, 00173 Roma (RM) · P.IVA 13698751008. Gli avvisi sono pubblicati dai proprietari, che ne sono responsabili. Mappe © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a>.</p>
    </div>
  </footer>
<?php if (!empty($o['js'])) { ?>
  <script>window.SM = <?= json_encode($o['js'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php if (!empty($o['mappa'])) { ?>
  <script src="/app/vendor/leaflet-1.9.4/leaflet.js"></script>
<?php } ?>
  <script src="/smarriti/smarriti.js?v=2026-10-11d"></script>
<?php } ?>
</body>
</html>
<?php
}
