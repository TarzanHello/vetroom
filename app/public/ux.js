
// VetRoom UX01: collapsible + close cards
(function(){
  function qs(sel, root){ return (root||document).querySelector(sel); }
  function qsa(sel, root){ return Array.from((root||document).querySelectorAll(sel)); }

  function ensureHeader(card){
    var header = qs('.vr-card-header', card);
    if (header) return header;

    // If the first element is a heading, use it as header.
    var firstEl = null;
    for (var i=0;i<card.childNodes.length;i++){
      var n = card.childNodes[i];
      if (n.nodeType===3 && !n.textContent.trim()) continue;
      if (n.nodeType===1){ firstEl = n; break; }
    }
    if (firstEl && /H2|H3|H4/.test(firstEl.tagName)){
      header = document.createElement('div');
      header.className = 'vr-card-header';
      header.appendChild(firstEl);
      card.insertBefore(header, card.firstChild);
      return header;
    }

    // Fallback: create an empty header (keeps collapse/close controls consistent)
    header = document.createElement('div');
    header.className = 'vr-card-header';
    header.textContent = '';
    card.insertBefore(header, card.firstChild);
    return header;
  }

  function initCollapsibles(){
    // Apply collapsible UX to ALL cards by default.
    // Opt-out: add class "vr-no-collapse".
    qsa('.vr-card').forEach(function(card){
      if (card.classList.contains('vr-no-collapse')) return;
      card.classList.add('vr-collapsible');

      var header = ensureHeader(card);
      // Wrap existing content into body if not present
      var body = qs('.vr-card-body', card);
      if(!body){
        body = document.createElement('div');
        body.className = 'vr-card-body';
        // move all nodes except header into body
        var nodes = [];
        for (var i=0;i<card.childNodes.length;i++){
          var n = card.childNodes[i];
          if(n === header) continue;
          if(n.nodeType===3 && !n.textContent.trim()) continue;
          nodes.push(n);
        }
        nodes.forEach(function(n){ body.appendChild(n); });
        card.appendChild(body);
      }

      // Upgrade header row if not upgraded
      var headerEl = qs('.vr-card-header', card);
      if(headerEl && !headerEl.classList.contains('vr-card-header-row')){
        headerEl.classList.add('vr-card-header-row');
        // create actions container
        var actions = document.createElement('div');
        actions.className = 'vr-card-actions';

        var collapseBtn = document.createElement('button');
        collapseBtn.type = 'button';
        collapseBtn.className = 'vr-icon-btn vr-collapse-btn';
        collapseBtn.title = 'Comprimi/Espandi';
        collapseBtn.setAttribute('aria-label','Comprimi/Espandi');
        collapseBtn.textContent = '▾';

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'vr-icon-btn vr-close-btn';
        closeBtn.title = 'Chiudi';
        closeBtn.setAttribute('aria-label','Chiudi');
        closeBtn.textContent = '✕';

        actions.appendChild(collapseBtn);
        actions.appendChild(closeBtn);
        headerEl.appendChild(actions);

        collapseBtn.addEventListener('click', function(e){
          e.preventDefault();
          card.classList.toggle('is-collapsed');
          collapseBtn.textContent = card.classList.contains('is-collapsed') ? '▸' : '▾';
        });

        closeBtn.addEventListener('click', function(e){
          e.preventDefault();
          card.classList.add('is-closed');
        });
      }
    });
  }

  function initAdminDrawer(){
    var layout = qs('.vr-admin-layout');
    if (!layout) return;

    var btn = qs('[data-vr-admin-menu]');
    var overlay = qs('[data-vr-admin-overlay]');

    function open(){
      layout.classList.add('is-sidebar-open');
      if (btn) btn.setAttribute('aria-expanded','true');
      document.body.classList.add('vr-no-scroll');
      if (overlay) overlay.setAttribute('aria-hidden','false');
    }
    function close(){
      layout.classList.remove('is-sidebar-open');
      if (btn) btn.setAttribute('aria-expanded','false');
      document.body.classList.remove('vr-no-scroll');
      if (overlay) overlay.setAttribute('aria-hidden','true');
    }

    if (btn) {
      btn.addEventListener('click', function(e){
        e.preventDefault();
        if (layout.classList.contains('is-sidebar-open')) close();
        else open();
      });
    }

    if (overlay) {
      overlay.addEventListener('click', function(){ close(); });
    }

    // Close drawer on ESC
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape') close();
    });

    // Close drawer after navigation (mobile only)
    qsa('.vr-admin-sidebar a', layout).forEach(function(a){
      a.addEventListener('click', function(){
        try {
          if (window.matchMedia && window.matchMedia('(max-width: 980px)').matches) close();
        } catch(_) {}
      });
    });
  }

  

function vr_page_no_uppercase(){
  var b = document.body;
  if (!b) return false;
  if (b.dataset && b.dataset.vrNoUppercase === '1') return true;
  if (b.classList.contains('vr-no-uppercase')) return true;
  return false;
}

function vr_should_uppercase_target(el){
  if (!el || !el.tagName) return false;
  if (vr_page_no_uppercase()) return false;
  // Opt-out at field or scope level
  if (el.hasAttribute('data-vr-no-uppercase')) return false;
  if (el.closest && el.closest('[data-vr-no-uppercase-scope]')) return false;

  var tag = el.tagName.toUpperCase();
  if (tag === 'INPUT') {
    var t = (el.getAttribute('type') || 'text').toLowerCase();
    // Only allow text-ish inputs
    if (t !== 'text' && t !== 'search') return false;
  } else if (tag === 'TEXTAREA') {
    // ok
  } else {
    return false;
  }

  // Do not touch readonly/disabled
  if (el.readOnly || el.disabled) return false;

  return true;
}

function vr_uppercase_keep_caret(el){
  try {
    var start = el.selectionStart;
    var end = el.selectionEnd;
    var before = el.value;
    var after = before.toUpperCase();
    if (before === after) return;
    el.value = after;
    if (typeof start === 'number' && typeof end === 'number' && el.setSelectionRange) {
      el.setSelectionRange(start, end);
    }
  } catch (e) {
    // Ignore (some inputs do not support selection APIs)
    try { el.value = (el.value || '').toUpperCase(); } catch (e2) {}
  }
}

function vr_init_auto_uppercase(){
  // Use capture so we catch input events early.
  document.addEventListener('input', function(e){
    var el = e.target;
    if (!vr_should_uppercase_target(el)) return;
    vr_uppercase_keep_caret(el);
  }, true);

  // Also normalize on blur (e.g. for autocomplete or paste in some browsers)
  document.addEventListener('blur', function(e){
    var el = e.target;
    if (!vr_should_uppercase_target(el)) return;
    vr_uppercase_keep_caret(el);
  }, true);
}

document.addEventListener('DOMContentLoaded', function(){
    initCollapsibles();
    initAdminDrawer();
  
  // Auto-uppercase for data entry (all forms except visits)
  vr_init_auto_uppercase();
});
})();
