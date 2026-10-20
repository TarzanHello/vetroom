<?php
// =============================================================
//  FURRFINDER / VETROOM · Sezione /smarriti/ — impostazioni (patch 38c)
//  La STESSA cartella funziona su vetroom.it e su furrfinder.com:
//  riconosce da sola il sito e usa intestazione, grafica e indirizzi giusti.
// =============================================================

// Database: stessi valori di app/js/config.js di Vetroom (la chiave PUBBLICA, mai quella segreta)
define('VR_SUPABASE_URL', getenv('VR_SUPABASE_URL') ?: 'https://mwherdrijnllnuvpzqif.supabase.co');
define('VR_SUPABASE_KEY', getenv('VR_SUPABASE_KEY') ?: 'sb_publishable_QWT2sf0Wn8ajJ-1SCddL8g_YOlEH-GW');

// Su quale sito siamo? (furrfinder.com oppure vetroom.it)
$sm_host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
define('SM_MARCA', getenv('SM_MARCA') ?: (strpos($sm_host, 'furrfinder') !== false ? 'furrfinder' : 'vetroom'));

// Indirizzo di questo sito, senza barra finale (compare nei link condivisi e per Google)
define('VR_SITO', getenv('VR_SITO') ?: (SM_MARCA === 'furrfinder' ? 'https://www.furrfinder.com' : 'https://www.vetroom.it'));

// Dove sta l'app (accesso, pubblicazione dell'avviso) e l'elenco dei veterinari: su vetroom.it
define('SM_APP', getenv('SM_APP') !== false ? getenv('SM_APP') : (SM_MARCA === 'furrfinder' ? 'https://www.vetroom.it' : ''));

// Quando le pagine saranno online su furrfinder.com, scrivi qui sotto 'https://www.furrfinder.com'
// (fra gli apici): da quel momento vetroom.it/smarriti/… porterà da solo alle pagine di furrfinder.com.
// Finché resta vuoto, le pagine restano anche su vetroom.it con la grafica di Vetroom.
define('SM_RIMANDA', '');
