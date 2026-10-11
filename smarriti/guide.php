<?php
// =============================================================
//  FURRFINDER · Guide pratiche della sezione /smarriti/ (patch 38)
//  Testi pensati per chi cerca su Google "cane smarrito cosa fare", "ho trovato un cane"…
// =============================================================

function sm_guida($slug) {
  $pubblica = '/app/?per=proprietario&amp;dopo=smarrito';
  if ($slug === 'hai-perso-il-tuo-animale') {
    $b = '<article class="sm-guide">'
      . '<span class="sm-kicker">Guida pratica</span><h1>Cane o gatto smarrito: cosa fare subito</h1>'
      . '<p class="sm-lead">Le prime ore sono le più importanti: un animale che si è appena perso di solito è ancora vicino. Ecco cosa fare, in ordine, per avere più probabilità di ritrovarlo presto.</p>'
      . '<aside class="sm-box sm-box-red sm-guide-cta"><p><b>Pubblica subito l\'avviso con FurrFinder.</b> È gratis e ci vuole un minuto: ottieni la pagina e la locandina con il codice QR da condividere, e chi lo vede ti avvisa sul telefono.</p><a class="sm-btn sm-btn-red" href="' . $pubblica . '">Pubblica l\'avviso</a></aside>'
      . '<h2>1. Nelle prime ore: cerca nella zona</h2><ul>'
      . '<li>Torna nel punto in cui l\'hai visto l\'ultima volta e percorri le strade vicine a piedi, chiamandolo con calma per nome.</li>'
      . '<li>Porta con te qualcosa che conosce: le sue crocchette, un gioco, il guinzaglio. Lascia una sua coperta o un tuo indumento davanti a casa: l\'odore familiare aiuta a farlo tornare.</li>'
      . '<li>Chiedi a chi lavora in zona (negozi, bar, portieri, edicole): vedono passare molte persone e molti animali.</li>'
      . '<li>Un animale spaventato può nascondersi e non rispondere: guarda sotto le auto, nei cortili, nei garage e nelle cantine.</li></ul>'
      . '<h2>2. Se è un gatto: cerca vicino a casa</h2><p>Un gatto che esce di casa per la prima volta di solito non va lontano: si nasconde in silenzio a poche decine di metri. Cerca di sera e di notte, quando è più tranquillo, con una torcia (gli occhi riflettono la luce). Controlla le cantine e i garage dei vicini, che potrebbero averlo chiuso dentro senza accorgersene.</p>'
      . '<h2>3. Fai girare l\'avviso</h2><ul>'
      . '<li>Condividi la pagina dell\'avviso nei gruppi WhatsApp del palazzo, del quartiere, della scuola e del lavoro.</li>'
      . '<li>Pubblicala nei gruppi Facebook di animali smarriti della tua città e del quartiere.</li>'
      . '<li>Stampa la locandina A4 e lasciala a negozi, bar, farmacie e studi veterinari della zona: il codice QR porta alla pagina, dove chiunque può segnalare un avvistamento.</li></ul>'
      . '<h2>4. Avvisa chi può trovarlo</h2><ul>'
      . '<li><b>Polizia locale</b> del tuo Comune e <b>canile comunale</b>: spesso sono i primi a ricevere gli animali trovati.</li>'
      . '<li><b>Servizio veterinario della ASL</b>: per il cane lo smarrimento va denunciato; in molte regioni la legge chiede di farlo entro pochi giorni. Chiedi alla tua ASL o al tuo veterinario come fare.</li>'
      . '<li><b>Studi e cliniche veterinarie</b> vicine: chi trova un animale spesso lo porta da un veterinario per leggere il microchip. <a href="/veterinari/">Trova i veterinari della tua zona</a>.</li>'
      . '<li><b>Rifugi e associazioni</b> della zona.</li></ul>'
      . '<h2>5. Controlla il microchip</h2><p>Il microchip è il modo ufficiale per risalire al proprietario. Assicurati che nell\'anagrafe degli animali da compagnia ci siano il tuo nome e il tuo numero di telefono aggiornati: se hai cambiato numero o indirizzo, chiedi al tuo veterinario o alla ASL di correggerli subito.</p>'
      . '<h2>6. Attenzione alle truffe</h2><ul>'
      . '<li>Non pagare mai nulla prima di aver visto il tuo animale, nemmeno "per le spese" o "per la spedizione".</li>'
      . '<li>Chiedi una foto con un dettaglio che solo tu conosci: una macchia, una cicatrice, il collare.</li>'
      . '<li>Fai l\'incontro in un luogo pubblico, meglio in uno studio veterinario: può leggere il microchip e confermare che è lui.</li>'
      . '<li>Se offri una ricompensa, non scrivere la cifra nell\'avviso.</li></ul>'
      . '<h2>7. Non arrenderti</h2><p>Molti animali vengono ritrovati dopo giorni o settimane, a volte lontano da dove si sono persi. Continua a condividere l\'avviso, aggiornalo con le nuove segnalazioni e ricontrolla i canili della zona. Quando torna a casa, segnalo come ritrovato: chi ti ha aiutato sarà felice di saperlo.</p>'
      . '<div class="sm-boxes"><aside class="sm-box sm-box-red"><h2>Pubblica l\'avviso adesso</h2><p>Pagina, locandina con QR e avvistamenti sul telefono. Il punto esatto resta privato. Gratis.</p><a class="sm-btn sm-btn-red" href="' . $pubblica . '">Pubblica un avviso</a></aside>'
      . '<aside class="sm-box"><h2>Avvisi in corso</h2><p>Guarda gli animali smarriti nella tua città: magari qualcuno ha visto il tuo.</p><a class="sm-btn" href="/smarriti/">Vedi gli avvisi</a></aside></div>'
      . '</article>';
    sm_pagina(['url' => '/smarriti/hai-perso-il-tuo-animale', 'indicizza' => true, 'articolo' => true,
      'title' => 'Cane o gatto smarrito: cosa fare subito, passo per passo | FurrFinder',
      'description' => 'Hai perso il cane o il gatto? Cosa fare nelle prime ore, chi avvisare (Comune, ASL, canile, veterinari), il microchip e come far girare l\'avviso. Con l\'avviso gratuito di FurrFinder.',
      'crumbs' => [['Animali smarriti', '/smarriti/'], ['Hai perso il tuo animale?', '/smarriti/hai-perso-il-tuo-animale']],
      'body' => $b]);
    return;
  }

  // hai-trovato-un-animale
  $b = '<article class="sm-guide">'
    . '<span class="sm-kicker">Guida pratica</span><h1>Hai trovato un cane o un gatto? Ecco cosa fare</h1>'
    . '<p class="sm-lead">Da qualche parte c\'è qualcuno che lo sta cercando. Con pochi passi puoi aiutarlo a tornare a casa, in sicurezza per te e per lui.</p>'
    . '<aside class="sm-box sm-guide-cta"><p><b>Qualcuno lo sta già cercando?</b> Guarda gli avvisi della tua città: se lo riconosci, apri l\'avviso e premi <b>L\'ho trovato</b>. Il proprietario riceve subito il tuo messaggio, anche se non hai un account.</p><a class="sm-btn" href="/smarriti/">Guarda gli avvisi</a></aside>'
    . '<h2>1. Prima di tutto la sicurezza</h2><ul>'
    . '<li>Avvicinati con calma, di lato, parlando a voce bassa. Non inseguirlo: un animale spaventato scappa, anche verso la strada.</li>'
    . '<li>Se è ferito, aggressivo o in un posto pericoloso, non rischiare: chiama la polizia locale o il servizio veterinario della ASL.</li>'
    . '<li>Se si lascia prendere, mettilo al sicuro con un guinzaglio (anche improvvisato) o in un trasportino, e offrigli dell\'acqua.</li></ul>'
    . '<h2>2. Guarda se ha una medaglietta</h2><p>Controlla il collare: a volte c\'è un numero di telefono o un nome. Se c\'è, chiama subito.</p>'
    . '<h2>3. Fai leggere il microchip</h2><p>Quasi tutti i cani, e molti gatti, hanno un microchip sotto la pelle. Un veterinario, la polizia locale o il canile possono leggerlo con un lettore e risalire al proprietario tramite l\'anagrafe. Chiedi prima al veterinario: di solito la lettura è rapida e spesso gratuita. <a href="/veterinari/">Trova un veterinario vicino</a>.</p>'
    . '<h2>4. Segnalalo</h2><ul>'
    . '<li>Se trovi il suo avviso su FurrFinder, premi <b>L\'ho trovato</b> e lascia un contatto: il proprietario riceve una notifica.</li>'
    . '<li>Avvisa comunque la polizia locale o il servizio veterinario della ASL del tuo Comune: è lì che il proprietario lo cercherà, e per un cane la segnalazione è prevista dalla legge.</li>'
    . '<li>Se non trovi il proprietario, l\'animale va affidato al canile o alle autorità indicate dal Comune: non è possibile tenerlo come proprio senza passare da loro.</li></ul>'
    . '<h2>5. Un gatto in strada non è sempre smarrito</h2><p>Molti gatti vivono liberi o fanno parte di una colonia curata dal quartiere. Se il gatto è in buona salute, pulito e tranquillo, probabilmente sa dove andare. Se invece sembra spaesato, è molto magro, ha un collare o è chiaramente un gatto di casa, fallo controllare da un veterinario.</p>'
    . '<h2>6. Attenzione a chi lo reclama</h2><p>Prima di consegnarlo, chiedi una foto del proprietario con l\'animale o un dettaglio che solo lui può conoscere. Il modo più sicuro è fare la consegna in uno studio veterinario, che può verificare il microchip.</p>'
    . '<div class="sm-boxes"><aside class="sm-box"><h2>Avvisi in corso</h2><p>Cerca l\'animale che hai trovato fra gli avvisi della tua città.</p><a class="sm-btn" href="/smarriti/">Vedi gli avvisi</a></aside>'
    . '<aside class="sm-box sm-box-red"><h2>Hai perso il tuo animale?</h2><p>Pubblica l\'avviso gratis in un minuto e ricevi gli avvistamenti sul telefono.</p><a class="sm-btn sm-btn-red" href="' . $pubblica . '">Pubblica un avviso</a></aside></div>'
    . '</article>';
  sm_pagina(['url' => '/smarriti/hai-trovato-un-animale', 'indicizza' => true, 'articolo' => true,
    'title' => 'Ho trovato un cane o un gatto: cosa fare | FurrFinder',
    'description' => 'Hai trovato un cane o un gatto per strada? Come avvicinarlo in sicurezza, far leggere il microchip, chi avvisare e come ritrovare il proprietario con gli avvisi di FurrFinder.',
    'crumbs' => [['Animali smarriti', '/smarriti/'], ['Hai trovato un animale?', '/smarriti/hai-trovato-un-animale']],
    'body' => $b]);
}
