<?php
// =============================================================
//  VETROOM · Sezione /veterinari/ — impostazioni
// =============================================================

// Stessi valori di app/js/config.js (la chiave PUBBLICA, mai quella segreta)
define('VR_SUPABASE_URL', getenv('VR_SUPABASE_URL') ?: 'https://mwherdrijnllnuvpzqif.supabase.co');
define('VR_SUPABASE_KEY', getenv('VR_SUPABASE_KEY') ?: 'sb_publishable_QWT2sf0Wn8ajJ-1SCddL8g_YOlEH-GW');

// Indirizzo del sito, senza barra finale
define('VR_SITO', getenv('VR_SITO') ?: 'https://vetroom.it');

// Dati mostrati nelle schede. Se il legale consiglia di non pubblicare il telefono, metti false.
define('VR_MOSTRA_TELEFONO', true);
define('VR_MOSTRA_SITO', true);

// Ogni quanti secondi rileggere le strutture dal database (3600 = un'ora)
define('VR_CACHE_SECONDI', 3600);

// Un comune ha una pagina indicizzata da Google solo se ha almeno questo numero di strutture
define('VR_MIN_STRUTTURE_COMUNE', 3);
