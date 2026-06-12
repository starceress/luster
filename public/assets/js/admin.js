/* =============================================================================
   admin.js -- Luster admin panel interactive layer

   Replaces all inline <script> blocks and inline event handlers across the
   admin template set. Loaded at the bottom of layout.php and all standalone
   auth pages (login, forgot, reset). Every function guards against missing
   DOM elements so it is safe to load unconditionally on every admin page.
   ============================================================================= */

'use strict';

// ── 0. Shared utilities ──────────────────────────────────────────────────────
// Defined once at global scope so all IIFEs below can share them without
// re-declaring.

// Global: .modal-close buttons auto-dismiss their parent .modal-backdrop.
document.addEventListener('click', function (e) {
  var btn = e.target.closest('.modal-close');
  if (!btn) return;
  var backdrop = btn.closest('.modal-backdrop');
  if (backdrop) backdrop.style.display = 'none';
});

// HTML escaping -- use esc() anywhere in this file.
function esc(str) {
  return String(str)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Token replacement -- applies a {token}: value map to a string via split/join.
// §29 (bulk email preview) and §31 (custom templates preview) share this.
function applyTokenReplacements(text, tokenMap) {
  var result = text;
  Object.keys(tokenMap).forEach(function (k) {
    result = result.split(k).join(tokenMap[k]);
  });
  return result;
}

// Select-all checkbox helper -- wires a master checkbox to check/uncheck all
// elements matching rowSelector. §17, §18, §19 all share this.
function wireSelectAll(masterId, rowSelector) {
  var master = document.getElementById(masterId);
  if (!master) return;
  master.addEventListener('change', function () {
    document.querySelectorAll(rowSelector).forEach(function (cb) {
      cb.checked = master.checked;
    });
  });
}

// Enhanced select-all with cross-page "select all N items" banner.
// cfg: { masterId, rowSelector, bannerId, total }
// Requires a hidden input[name="select_all"] inside the same form.
function wireSelectAllPages(cfg) {
  var master = document.getElementById(cfg.masterId);
  var banner = document.getElementById(cfg.bannerId);
  if (!master || !banner) return;
  var form = master.closest('form');
  if (!form) return;

  var flag      = form.querySelector('input[name="select_all"]');
  var total     = cfg.total;
  var statePage = banner.querySelector('.sa-page');
  var stateAll  = banner.querySelector('.sa-all');

  function getRows()    { return form.querySelectorAll(cfg.rowSelector); }
  function getChecked() { return form.querySelectorAll(cfg.rowSelector + ':checked'); }

  function updateBannerCounts() {
    var pageCount = getRows().length;
    banner.querySelectorAll('.sa-page-count').forEach(function(el) { el.textContent = pageCount; });
    banner.querySelectorAll('.sa-total-count').forEach(function(el) { el.textContent = total; });
  }

  function showBannerPage() {
    if (total <= getRows().length) return;
    updateBannerCounts();
    if (statePage) statePage.style.display = '';
    if (stateAll)  stateAll.style.display  = 'none';
    banner.style.display = '';
  }

  function showBannerAll() {
    updateBannerCounts();
    if (statePage) statePage.style.display = 'none';
    if (stateAll)  stateAll.style.display  = '';
    banner.style.display = '';
  }

  function hideBanner() {
    banner.style.display = 'none';
    if (flag) flag.value = '0';
  }

  master.addEventListener('change', function () {
    if (flag) flag.value = '0';
    getRows().forEach(function(cb) { cb.checked = master.checked; });
    master.indeterminate = false;
    if (master.checked) { showBannerPage(); } else { hideBanner(); }
  });

  form.addEventListener('change', function (e) {
    var cb = e.target;
    if (!cb.matches(cfg.rowSelector)) return;
    var rows    = getRows();
    var checked = getChecked();
    master.checked       = rows.length > 0 && checked.length === rows.length;
    master.indeterminate = checked.length > 0 && checked.length < rows.length;
    if (master.checked) {
      showBannerPage();
    } else {
      if (flag) flag.value = '0';
      hideBanner();
    }
  });

  var selectAllBtn = banner.querySelector('.sa-select-all-btn');
  if (selectAllBtn) {
    selectAllBtn.addEventListener('click', function () {
      if (flag) flag.value = '1';
      showBannerAll();
    });
  }

  var clearBtn = banner.querySelector('.sa-clear-btn');
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      if (flag) flag.value = '0';
      master.checked = false;
      master.indeterminate = false;
      getRows().forEach(function(cb) { cb.checked = false; });
      hideBanner();
    });
  }
}

// ── 1. Theme toggle ──────────────────────────────────────────────────────────

function toggleTheme() {
  var html = document.documentElement;
  var next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  localStorage.setItem('lstr_theme', next);
}

// ── 2. Password show/hide ─────────────────────────────────────────────────────

function togglePwd(btn) {
  var inp  = btn.previousElementSibling;
  var show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  btn.querySelector('.eye-on').style.display  = show ? 'none' : '';
  btn.querySelector('.eye-off').style.display = show ? ''     : 'none';
  btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
}

// ── 3. (Sidebar constellation removed -- replaced with static CSS dot grid) ─────

// ── 4. (Auth-page constellation removed -- replaced with static CSS background) ──

// ── 5. Theme toggle button ────────────────────────────────────────────────────

(function () {
  var btn = document.querySelector('.theme-toggle');
  if (btn) btn.addEventListener('click', toggleTheme);
}());

// ── 6. Password toggle buttons (event delegation on [data-pw-toggle]) ─────────

document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-pw-toggle], .input-icon-btn');
  if (btn) togglePwd(btn);
});

// ── 7. Mobile sidebar ─────────────────────────────────────────────────────────

(function () {
  var hamburger = document.getElementById('hamburger');
  if (!hamburger) return;
  var overlay = document.getElementById('sidebarOverlay');
  var sidebar  = document.querySelector('.sidebar');

  function openSidebar() {
    sidebar.classList.add('open');
    overlay.classList.add('active');
    document.body.classList.add('sidebar-open');
  }
  function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
    document.body.classList.remove('sidebar-open');
  }

  hamburger.addEventListener('click', openSidebar);
  overlay.addEventListener('click', closeSidebar);
  document.querySelectorAll('.sidebar .nav-item').forEach(function (el) {
    el.addEventListener('click', closeSidebar);
  });
}());

// ── 8. Form submit handler: data-confirm + bulk validation ────────────────────
//
// data-confirm on a <form>: shows an inline confirmation bar instead of
// window.confirm(). The original submit button is hidden; a "Yes / Cancel"
// pair slides in beside it. Escape and Cancel restore the button.
//
// Forms with select[name="bulk_action"]: validates selection count and shows
// the same inline bar for destructive (delete) bulk actions.
//
// Listings bulk form (select[name="action"] + .fl-row-cb): validates selection.

function showConfirmBar(anchorEl, message, onConfirm) {
  // Only one confirm bar at a time -- dismiss any stale one
  var stale = document.querySelector('.confirm-bar');
  if (stale) stale.remove();

  // Hide the trigger element while the bar is showing
  anchorEl.style.display = 'none';

  var bar = document.createElement('span');
  bar.className = 'confirm-bar';
  bar.setAttribute('role', 'group');
  bar.setAttribute('aria-label', 'Confirm action');
  bar.innerHTML =
    '<span class="confirm-bar-msg">' + message + '</span>' +
    '<button type="button" class="btn btn-danger btn-sm confirm-bar-yes">Yes</button>' +
    '<button type="button" class="btn btn-ghost btn-sm confirm-bar-no">Cancel</button>';

  anchorEl.insertAdjacentElement('afterend', bar);

  // Animate in on next two frames so the transition fires
  requestAnimationFrame(function () {
    requestAnimationFrame(function () { bar.classList.add('is-visible'); });
  });

  function dismiss() {
    bar.classList.remove('is-visible');
    document.removeEventListener('keydown', onKey);
    setTimeout(function () {
      if (bar.parentNode) bar.remove();
      anchorEl.style.display = '';
    }, 90);
  }

  bar.querySelector('.confirm-bar-yes').addEventListener('click', function () {
    if (bar.parentNode) bar.remove();
    document.removeEventListener('keydown', onKey);
    anchorEl.style.display = '';
    onConfirm();
  });

  bar.querySelector('.confirm-bar-no').addEventListener('click', dismiss);

  function onKey(e) { if (e.key === 'Escape') dismiss(); }
  document.addEventListener('keydown', onKey);

  // Focus Cancel so Escape works immediately without a mouse move
  bar.querySelector('.confirm-bar-no').focus();
}

// Per-listing "Approve All" buttons in the global pending view.
// These must NOT use nested <form> elements (invalid HTML — browsers submit the
// wrong outer form), so they are type="button" elements that create and submit
// a temporary form programmatically.
(function () {
  function addInput(form, name, value) {
    var inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = name; inp.value = value;
    form.appendChild(inp);
  }

  document.querySelectorAll('[data-global-approve-listing]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var count = btn.dataset.globalApproveCount || '?';
      showConfirmBar(btn, 'Approve all ' + count + ' pending donation(s) for this listing?', function () {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = btn.dataset.globalApproveUrl;
        form.style.display = 'none';
        addInput(form, '_csrf',       btn.dataset.globalApproveCsrf);
        addInput(form, 'listing_id',  btn.dataset.globalApproveListing);
        addInput(form, '_tab',        'pending');
        document.body.appendChild(form);
        form.submit();
      });
    });
  });
}());

document.addEventListener('submit', function (e) {
  var form = e.target;

  // data-confirm: inline confirmation bar
  var confirmMsg = form.dataset.confirm;
  if (confirmMsg) {
    if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return; }
    e.preventDefault();
    var submitBtn = form.querySelector('[type="submit"]');
    showConfirmBar(submitBtn || form, confirmMsg, function () {
      form.dataset.confirmed = '1';
      form.requestSubmit ? form.requestSubmit() : form.submit();
    });
    return;
  }

  // Members / joined bulk form: select[name="bulk_action"]
  var bulkSel = form.querySelector('select[name="bulk_action"]');
  if (bulkSel) {
    var bulkChecked = form.querySelectorAll('input[name="emails[]"]:checked, input[name="ids[]"]:checked');
    if (!bulkChecked.length) {
      e.preventDefault();
      window.lstrToast && window.lstrToast('warning', 'No items selected.');
      return;
    }
    if (bulkSel.value === 'delete' && !form.hasAttribute('data-del-bulk')) {
      if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return; }
      e.preventDefault();
      var bulkApplyBtn = form.querySelector('[type="submit"]');
      showConfirmBar(
        bulkApplyBtn || form,
        'Delete ' + bulkChecked.length + ' item(s)? This cannot be undone.',
        function () {
          form.dataset.confirmed = '1';
          form.requestSubmit ? form.requestSubmit() : form.submit();
        }
      );
    }
    return;
  }

  // Listings bulk form: select[name="action"] + .fl-row-cb checkboxes
  var actionSel = form.querySelector('select[name="action"]');
  if (actionSel && form.querySelector('.fl-row-cb')) {
    var listingChecked = form.querySelectorAll('.fl-row-cb:checked');
    if (!listingChecked.length) {
      e.preventDefault();
      window.lstrToast && window.lstrToast('warning', 'No listings selected.');
      return;
    }
    if (form.hasAttribute('data-del-bulk')) return; // section 39 handles modal
    if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return; }
    e.preventDefault();
    var listingApplyBtn = form.querySelector('[type="submit"]');
    showConfirmBar(
      listingApplyBtn || form,
      'Apply bulk action to ' + listingChecked.length + ' listing(s)?',
      function () {
        form.dataset.confirmed = '1';
        form.requestSubmit ? form.requestSubmit() : form.submit();
      }
    );
  }

  // Affiliates bulk form: select[name="action"] + .aff-row-cb checkboxes
  if (actionSel && form.querySelector('.aff-row-cb')) {
    var affChecked = form.querySelectorAll('.aff-row-cb:checked');
    if (!affChecked.length) {
      e.preventDefault();
      window.lstrToast && window.lstrToast('warning', 'No affiliates selected.');
      return;
    }
    if (form.hasAttribute('data-del-bulk')) return; // section 39 handles modal
  }
});

// ── 9. data-once-submit buttons (disable on click to prevent double-send) ─────

document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-once-submit]');
  if (!btn) return;
  e.preventDefault();
  btn.disabled    = true;
  btn.textContent = btn.dataset.onceSubmit;
  btn.form.submit();
});

// ── 10. Navigation selects (.js-nav-select → navigate to option value) ─────────

document.querySelectorAll('.js-nav-select').forEach(function (sel) {
  sel.addEventListener('change', function () {
    if (this.value) { location.href = this.value; }
  });
});

// ── 11. Auto-submit selects (.js-autosubmit → submit parent form on change) ────

document.querySelectorAll('.js-autosubmit').forEach(function (sel) {
  sel.addEventListener('change', function () { this.form.submit(); });
});

// ── 12. Reset-form buttons (.js-form-reset → call form.reset()) ────────────────

document.addEventListener('click', function (e) {
  var btn = e.target.closest('.js-form-reset');
  if (btn && btn.form) btn.form.reset();
});

document.addEventListener('click', function (e) {
  var btn = e.target.closest('.js-form-clear');
  if (!btn || !btn.form) return;

  btn.form.querySelectorAll('input, textarea, select').forEach(function (el) {
    if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button' || el.type === 'reset') return;

    if (el.type === 'checkbox' || el.type === 'radio') {
      el.checked = false;
    } else if (el.tagName === 'SELECT') {
      el.selectedIndex = 0;
    } else {
      el.value = '';
    }
  });
});

// ── 13. Tab switching (.tabs-btn / .sidebar-nav-item / #tab-{tabId}) ───
//
// Generic tab switcher used by listings/edit, settings, and anywhere else that
// uses the .tabs-btn / .tabs-panel markup pattern. Also handles the
// settings page vertical sidebar nav (.sidebar-nav-item).
// Activates a tab by URL hash on load (e.g. settings redirects with #tab-email).

(function () {
  var tabs     = document.querySelectorAll('.tabs-btn[data-tab]');
  var navItems = document.querySelectorAll('.sidebar-nav-item');
  if (!tabs.length && !navItems.length) return;

  function activateTab(tabId) {
    document.querySelectorAll('.tabs-btn[data-tab], .tabs-panel, .sidebar-nav-item').forEach(function (el) {
      el.classList.remove('active');
    });
    var btn     = document.querySelector('.tabs-btn[data-tab="' + tabId + '"]');
    var navItem = document.querySelector('.sidebar-nav-item[data-tab="' + tabId + '"]');
    var panel   = document.getElementById('tab-' + tabId);
    if (btn)     btn.classList.add('active');
    if (navItem) navItem.classList.add('active');
    if (panel)   panel.classList.add('active');
  }

  /* Patch activateTab to also update the select value and URL query string */
  var _origActivate = activateTab;
  activateTab = function (tabId, updateUrl) {
    _origActivate(tabId);
    document.querySelectorAll('.tabs-select').forEach(function (sel) {
      sel.value = tabId;
    });
    if (updateUrl !== false && history.replaceState) {
      var url = new URL(window.location.href);
      url.searchParams.set('tab', tabId);
      history.replaceState(null, '', url.toString());
    }
  };

  // Wire click handlers to pass updateUrl=true
  tabs.forEach(function (btn) {
    btn.addEventListener('click', function () { activateTab(btn.dataset.tab, true); });
  });
  navItems.forEach(function (item) {
    if (!item.dataset.tab) return;
    item.addEventListener('click', function () { activateTab(item.dataset.tab, true); });
  });
  document.querySelectorAll('.tabs-select').forEach(function (sel) {
    sel.addEventListener('change', function () { activateTab(sel.value, true); });
  });

  // Activate from URL hash (settings page: redirect with ?...#tab-email etc.)
  var hash = window.location.hash.replace('#tab-', '');
  if (hash && (document.querySelector('.tabs-btn[data-tab="' + hash + '"]') ||
               document.querySelector('.sidebar-nav-item[data-tab="' + hash + '"]'))) {
    activateTab(hash);
  } else {
    // Activate from ?tab= query param (persists across page refreshes)
    var tabParam = new URLSearchParams(window.location.search).get('tab');
    if (tabParam && (document.querySelector('.tabs-btn[data-tab="' + tabParam + '"]') ||
                     document.querySelector('.sidebar-nav-item[data-tab="' + tabParam + '"]'))) {
      activateTab(tabParam, false);
    }
  }
}());

// ── 13b. Tab bar scroll-fade indicator ────────────────────────────────────────
(function () {
  document.querySelectorAll('.tabs-wrap').forEach(function (wrap) {
    var tabs = wrap.querySelector('.tabs');
    if (!tabs) return;
    function update() {
      var atEnd = tabs.scrollLeft + tabs.clientWidth >= tabs.scrollWidth - 4;
      wrap.classList.toggle('at-end', atEnd);
    }
    tabs.addEventListener('scroll', update, { passive: true });
    update();
  });
}());

// ── 14. Category/network widget ───────────────────────────────────────────────
//
// Used on listings/add, listings/edit, and joined/form. Data is carried on
// data-* attributes of #cat-widget so this module needs no PHP interpolation.

(function () {
  var widget = document.getElementById('cat-widget');
  if (!widget) return;
  var rowsEl     = document.getElementById('cat-rows');
  var addLink    = document.getElementById('cat-add');
  var tflCats    = JSON.parse(widget.dataset.tfl);
  var taflCats   = JSON.parse(widget.dataset.tafl);
  var customNets = JSON.parse(widget.dataset.custom);
  var customCats = JSON.parse(widget.dataset.customCats || '{}'); // { "NetworkName": [{id,name}, ...] }
  var existing   = JSON.parse(widget.dataset.existing);

  function buildNetOpts(selected) {
    var h = '<option value="">\u2014 Select Network \u2014</option>';
    var nets = ['TFL', 'TAFL'].concat(customNets).sort(function (a, b) {
      return a.toLowerCase().localeCompare(b.toLowerCase());
    });
    nets.forEach(function (name) {
      h += '<option value="' + esc(name) + '"' +
           (selected === name ? ' selected' : '') + '>' + esc(name) + '</option>';
    });
    h += '<option value="other"' + (selected === 'other' ? ' selected' : '') +
         '>Other (add new)</option>';
    return h;
  }

  function buildCatOpts(cats, selected) {
    var h = '<option value="">\u2014 Select Category \u2014</option>';
    cats.forEach(function (c) {
      h += '<option value="' + esc(c.name) + '"' +
           (selected === c.name ? ' selected' : '') + '>' + esc(c.name) + '</option>';
    });
    return h;
  }

  function syncRow(row, netVal, catVal) {
    var catSel = row.querySelector('[data-role="cat-category"]');
    var catSelWrap = row.querySelector('[data-role="cat-category-wrap"]');
    var catTxt = row.querySelector('[data-role="cat-name-text"]');
    var othTxt = row.querySelector('[data-role="cat-network-other"]');
    catVal = catVal || '';
    if (netVal === 'TFL' || netVal === 'TAFL') {
      catSel.innerHTML     = buildCatOpts(netVal === 'TFL' ? tflCats : taflCats, catVal);
      catSel.disabled      = false; catSel.style.display = '';
      if (catSelWrap) catSelWrap.style.display = '';
      catTxt.disabled      = true;  catTxt.style.display = 'none';
      othTxt.style.display = 'none';
    } else if (netVal === 'other') {
      catSel.disabled      = true;  catSel.style.display = 'none';
      if (catSelWrap) catSelWrap.style.display = 'none';
      catTxt.disabled      = false; catTxt.style.display = '';
      if (catVal) catTxt.value = catVal;
      othTxt.style.display = '';
    } else if (netVal !== '') {
      // Known custom network with saved categories → show a dropdown
      var knownCats = customCats[netVal];
      if (knownCats && knownCats.length > 0) {
        catSel.innerHTML     = buildCatOpts(knownCats, catVal);
        catSel.disabled      = false; catSel.style.display = '';
        if (catSelWrap) catSelWrap.style.display = '';
        catTxt.disabled      = true;  catTxt.style.display = 'none';
      } else {
        // Custom network with no saved categories → free-text input
        catSel.disabled      = true;  catSel.style.display = 'none';
        if (catSelWrap) catSelWrap.style.display = 'none';
        catTxt.disabled      = false; catTxt.style.display = '';
        if (catVal) catTxt.value = catVal;
      }
      othTxt.style.display = 'none';
    } else {
      catSel.disabled      = true;  catSel.style.display = 'none';
      if (catSelWrap) catSelWrap.style.display = 'none';
      catTxt.disabled      = true;  catTxt.style.display = 'none';
      othTxt.style.display = 'none';
    }
  }

  function makeRow(netVal, catVal, otherNetVal) {
    netVal = netVal || ''; catVal = catVal || ''; otherNetVal = otherNetVal || '';
    var row = document.createElement('div');
    row.className = 'cat-row';
    row.innerHTML =
      '<div class="select-wrap" data-role="cat-network-wrap">' +
        '<select name="cat_network[]" data-role="cat-network">' + buildNetOpts(netVal) + '</select>' +
      '</div>' +
      '<div class="select-wrap" data-role="cat-category-wrap" style="display:none">' +
        '<select name="cat_name[]" data-role="cat-category" disabled style="display:none"></select>' +
      '</div>' +
      '<input type="text" name="cat_network_other[]" data-role="cat-network-other" value="' +
          esc(otherNetVal) + '" placeholder="New network name" style="display:none">' +
      '<input type="text" name="cat_name[]" data-role="cat-name-text" disabled ' +
          'style="display:none" placeholder="Category name">' +
      '<button type="button" class="cat-remove-btn btn btn-danger btn-sm" title="Remove">x</button>';
    row.querySelector('[data-role="cat-network"]').addEventListener('change', function () {
      syncRow(row, this.value, '');
    });
    row.querySelector('.cat-remove-btn').addEventListener('click', function () {
      row.remove();
    });
    if (netVal) syncRow(row, netVal, catVal);
    return row;
  }

  existing.forEach(function (entry) {
    rowsEl.appendChild(makeRow(entry.network || '', entry.name || '', ''));
  });
  if (rowsEl.children.length === 0) rowsEl.appendChild(makeRow('', '', ''));

  addLink.addEventListener('click', function (e) {
    e.preventDefault();
    rowsEl.appendChild(makeRow('', '', ''));
  });

  // Exposed so clearJoinedForm (section 15) can call it
  window.addCatRow = function () { rowsEl.appendChild(makeRow('', '', '')); };
}());

// Wishlist add/edit: single network + category picker.
(function () {
  var root = document.getElementById('wl-category-fields');
  if (!root) return;

  var networkSel = document.getElementById('wl-network');
  var networkOther = document.getElementById('wl-network-other');
  var categoryWrap = document.getElementById('wl-category-select-wrap');
  var categorySel = document.getElementById('wl-category-select');
  var categoryText = document.getElementById('wl-category-text');
  if (!networkSel || !networkOther || !categoryWrap || !categorySel || !categoryText) return;

  function parseJson(value, fallback) {
    try { return JSON.parse(value || ''); }
    catch (e) { return fallback; }
  }

  var tflCats = parseJson(root.dataset.tfl, []);
  var taflCats = parseJson(root.dataset.tafl, []);
  var customCats = parseJson(root.dataset.customCats, {});
  var currentCategory = root.dataset.currentCategory || '';

  function catsForNetwork(network) {
    if (network === 'TFL') return tflCats;
    if (network === 'TAFL') return taflCats;
    return customCats[network] || [];
  }

  function buildCategoryOptions(cats, selected) {
    var html = '<option value="">&mdash; Select Category &mdash;</option>';
    cats.forEach(function (cat) {
      html += '<option value="' + esc(cat.name) + '"' +
        (selected === cat.name ? ' selected' : '') + '>' +
        esc(cat.name) +
        '</option>';
    });
    return html;
  }

  function syncCategory() {
    var network = networkSel.value;
    var cats = catsForNetwork(network);
    var useText = network === 'other' || (network !== '' && cats.length === 0);

    networkOther.disabled = network !== 'other';
    networkOther.style.display = network === 'other' ? '' : 'none';

    if (network === '') {
      categoryWrap.style.display = 'none';
      categorySel.disabled = true;
      categoryText.disabled = true;
      categoryText.style.display = 'none';
      return;
    }

    if (useText) {
      categoryWrap.style.display = 'none';
      categorySel.disabled = true;
      categoryText.disabled = false;
      categoryText.style.display = '';
      if (currentCategory !== '' && categoryText.value === '') {
        categoryText.value = currentCategory;
      }
      return;
    }

    categorySel.innerHTML = buildCategoryOptions(cats, currentCategory);
    categoryWrap.style.display = '';
    categorySel.disabled = false;
    categoryText.disabled = true;
    categoryText.style.display = 'none';
  }

  networkSel.addEventListener('change', function () {
    currentCategory = '';
    categoryText.value = '';
    categorySel.innerHTML = '';
    syncCategory();
  });

  var form = root.closest('form');
  if (form) {
    form.addEventListener('reset', function () {
      window.setTimeout(function () {
        currentCategory = root.dataset.currentCategory || '';
        syncCategory();
      }, 0);
    });
  }

  syncCategory();
}());

// ── 15. Clear joined/add form button (#clear-form-btn) ───────────────────────

(function () {
  var btn = document.getElementById('clear-form-btn');
  if (!btn) return;
  btn.addEventListener('click', function () {
    document.querySelectorAll('.form-card input, .form-card textarea, .form-card select')
      .forEach(function (el) {
        if (el.type === 'checkbox' || el.type === 'radio') { el.checked = false; }
        else { el.value = ''; }
      });
    var rows = document.getElementById('cat-rows');
    if (rows && typeof addCatRow === 'function') { rows.innerHTML = ''; addCatRow(); }
    var statusSel = document.querySelector('select[name="status"]');
    if (statusSel) statusSel.value = 'joined';
  });
}());

// ── 16. (embed codes panel copy -- see section 31) ────────────────────────────

// ── 17. Members index: select-all checkboxes ──────────────────────────────────

(function () {
  var b = document.getElementById('check-all-sa-banner');
  wireSelectAllPages({ masterId: 'check-all', rowSelector: '#bulk-form input[name="emails[]"]', bannerId: 'check-all-sa-banner', total: b ? parseInt(b.dataset.total || '0', 10) : 0 });
}());

// ── 18. Listings codes: select-all checkboxes ────────────────────────────────

// ── 19. Listings/Affiliates index: select-all checkboxes ─────────────────────

(function () {
  var bf = document.getElementById('fl-select-all-sa-banner');
  wireSelectAllPages({ masterId: 'fl-select-all', rowSelector: '.fl-row-cb', bannerId: 'fl-select-all-sa-banner', total: bf ? parseInt(bf.dataset.total || '0', 10) : 0 });
  var ba = document.getElementById('aff-select-all-sa-banner');
  wireSelectAllPages({ masterId: 'aff-select-all', rowSelector: '.aff-row-cb', bannerId: 'aff-select-all-sa-banner', total: ba ? parseInt(ba.dataset.total || '0', 10) : 0 });
}());

// ── 20. Joined/Categories index: select-all with banner ──────────────────────

(function () {
  var b = document.getElementById('sel-all-sa-banner');
  wireSelectAllPages({ masterId: 'sel-all', rowSelector: '#bulk-form input[name="ids[]"]', bannerId: 'sel-all-sa-banner', total: b ? parseInt(b.dataset.total || '0', 10) : 0 });
}());

// ── 21. Members overview: per-listing select-all ──────────────────────────────

(function () {
  document.querySelectorAll('.sel-all').forEach(function (selAll) {
    selAll.addEventListener('change', function () {
      var fid = selAll.dataset.fid;
      document.querySelectorAll('.row-cb-' + fid).forEach(function (cb) {
        cb.checked = selAll.checked;
      });
    });
  });
}());

// ── 22. Listings index: network filter (updates category select + submits) ────

(function () {
  var catSel = document.getElementById('fl-cat-sel');
  var netSel = document.getElementById('fl-net-sel');
  if (!catSel || !netSel) return;
  var tflCats   = JSON.parse(catSel.dataset.tfl);
  var taflCats  = JSON.parse(catSel.dataset.tafl);
  var customMap = JSON.parse(catSel.dataset.custom);

  function buildCatOptions(net) {
    var html = '<option value="">All Categories</option>';
    if (net === '') {
      if (tflCats.length) {
        html += '<optgroup label="TFL">';
        tflCats.forEach(function (c) { html += '<option value="' + esc(c.name) + '">' + esc(c.name) + '</option>'; });
        html += '</optgroup>';
      }
      if (taflCats.length) {
        html += '<optgroup label="TAFL">';
        taflCats.forEach(function (c) { html += '<option value="' + esc(c.name) + '">' + esc(c.name) + '</option>'; });
        html += '</optgroup>';
      }
      Object.keys(customMap).forEach(function (n) {
        var arr = customMap[n];
        if (arr.length) {
          html += '<optgroup label="' + esc(n) + '">';
          arr.forEach(function (c) { html += '<option value="' + esc(c.name) + '">' + esc(c.name) + '</option>'; });
          html += '</optgroup>';
        }
      });
    } else {
      var cats = net === 'TFL' ? tflCats : net === 'TAFL' ? taflCats : (customMap[net] || []);
      cats.forEach(function (c) { html += '<option value="' + esc(c.name) + '">' + esc(c.name) + '</option>'; });
    }
    return html;
  }

  var form    = document.getElementById('fl-filter-form');
  var typeSel = form ? form.querySelector('select[name="type"]') : null;

  netSel.addEventListener('change', function () {
    catSel.innerHTML = buildCatOptions(this.value);
    form.submit();
  });

  catSel.addEventListener('change', function () { form.submit(); });
  if (typeSel) typeSel.addEventListener('change', function () { form.submit(); });
}());

// ── 23. Categories index: network filter → category name select ───────────────

(function () {
  var catSel = document.getElementById('cat-cat-sel');
  var netSel = document.getElementById('cat-net-sel');
  if (!catSel || !netSel) return;

  var tflCats   = JSON.parse(catSel.dataset.tfl   || '[]');
  var taflCats  = JSON.parse(catSel.dataset.tafl  || '[]');
  var customMap = JSON.parse(catSel.dataset.custom || '{}');
  var selected  = catSel.querySelector('option[selected]');
  var currentVal = selected ? selected.value : '';

  function optionHtml(c) {
    var sel   = c.name === currentVal ? ' selected' : '';
    var label = esc(c.name) + (c.count > 0 ? ' (' + c.count + ')' : '');
    return '<option value="' + esc(c.name) + '"' + sel + '>' + label + '</option>';
  }

  function groupHtml(label, cats) {
    if (!cats || !cats.length) return '';
    var html = '<optgroup label="' + esc(label) + '">';
    cats.forEach(function (c) { html += optionHtml(c); });
    return html + '</optgroup>';
  }

  function populate(net) {
    var html = '<option value="">All Categories</option>';
    if (net === '') {
      html += groupHtml('TFL', tflCats);
      html += groupHtml('TAFL', taflCats);
      Object.keys(customMap).forEach(function (n) { html += groupHtml(n, customMap[n]); });
    } else {
      var cats = net === 'tfl' ? tflCats : net === 'tafl' ? taflCats : (customMap[net] || []);
      cats.forEach(function (c) { html += optionHtml(c); });
    }
    catSel.innerHTML = html;
  }

  populate(netSel.value);

  netSel.addEventListener('change', function () {
    currentVal = '';
    populate(this.value);
    catSel.closest('form').submit();
  });

  catSel.addEventListener('change', function () {
    catSel.closest('form').submit();
  });
}());

// ── 24. Joined index: network → category filter ───────────────────────────────
// Data stored as JSON in data-net-cats attribute on #net-sel.

(function () {
  var netSel = document.getElementById('net-sel');
  if (!netSel || !netSel.dataset.netCats) return;
  var netCats = JSON.parse(netSel.dataset.netCats);

  function buildJoinedCatOptions(net) {
    var html = '<option value="">All Categories</option>';
    if (net === '') {
      ['TFL', 'TAFL'].forEach(function (n) {
        var arr = netCats[n];
        if (arr && arr.length) {
          html += '<optgroup label="' + esc(n) + '">';
          arr.forEach(function (c) { html += '<option value="' + esc(c) + '">' + esc(c) + '</option>'; });
          html += '</optgroup>';
        }
      });
      Object.keys(netCats).forEach(function (n) {
        if (n === 'TFL' || n === 'TAFL') { return; }
        var arr = netCats[n];
        if (arr && arr.length) {
          html += '<optgroup label="' + esc(n) + '">';
          arr.forEach(function (c) { html += '<option value="' + esc(c) + '">' + esc(c) + '</option>'; });
          html += '</optgroup>';
        }
      });
    } else if (netCats[net]) {
      netCats[net].forEach(function (c) {
        html += '<option value="' + esc(c) + '">' + esc(c) + '</option>';
      });
    }
    return html;
  }

  netSel.addEventListener('change', function () {
    document.getElementById('cat-sel').innerHTML = buildJoinedCatOptions(this.value);
    this.form.submit();
  });

  var catSel = document.getElementById('cat-sel');
  if (catSel) {
    catSel.addEventListener('change', function () { this.form.submit(); });
  }
}());

// ── 24. Dashboard: live date/time ─────────────────────────────────────────────

(function () {
  var el = document.getElementById('live-datetime');
  if (!el) return;
  var days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  var months = ['January','February','March','April','May','June','July',
                'August','September','October','November','December'];
  function updateTime() {
    var now  = new Date();
    var h    = now.getHours();
    var m    = String(now.getMinutes()).padStart(2, '0');
    var ampm = h >= 12 ? 'PM' : 'AM';
    var h12  = h % 12 || 12;
    el.textContent = 'Today is ' + days[now.getDay()] + ', ' +
      months[now.getMonth()] + ' ' + now.getDate() + ', ' + now.getFullYear() +
      ' \u00b7 ' + h12 + ':' + m + ' ' + ampm;
    setTimeout(updateTime, 30000);
  }
  updateTime();
}());

// ── 24a. File-drop labels: forward clicks to the hidden file input ─────────────

document.addEventListener('click', function (e) {
  var label = e.target.closest('.file-drop-zone');
  if (!label) return;
  var input = label.querySelector('input[type="file"]');
  if (!input || e.target === input) return;
  e.preventDefault();
  input.click();
});

document.addEventListener('change', function (e) {
  var input = e.target;
  if (!input || !input.matches || !input.matches('.file-drop-zone input[type="file"]')) return;

  var label = input.closest('.file-drop-zone');
  if (!label) return;

  var title = label.querySelector('strong');
  var hint  = label.querySelector('.form-hint');
  var count = input.files ? input.files.length : 0;

  if (!label.dataset.defaultTitle && title) {
    label.dataset.defaultTitle = title.textContent;
  }
  if (!label.dataset.defaultHint && hint) {
    label.dataset.defaultHint = hint.textContent;
  }

  if (count > 0) {
    if (title) title.textContent = count === 1 ? input.files[0].name : count + ' files selected';
    if (hint) hint.textContent = 'Ready to upload - save the form to apply it.';
    label.classList.add('is-ready');
  } else {
    if (title) title.textContent = label.dataset.defaultTitle || 'Click to upload';
    if (hint) hint.textContent = label.dataset.defaultHint || '';
    label.classList.remove('is-ready');
  }
});

// ── 24b. Listings edit: status pill toggle ────────────────────────────────────
// Active state is handled by CSS :has(input:checked) on .status-pill--* classes.

// ── 25. Listings edit: last-updated date set/clear buttons ────────────────────

(function () {
  var setBtn   = document.getElementById('set-today-btn');
  var clearBtn = document.getElementById('clear-date-btn');
  if (setBtn) {
    setBtn.addEventListener('click', function () {
      var f = document.getElementById('fl-last-updated');
      if (f) f.value = new Date().toISOString().slice(0, 10);
    });
  }
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      var f = document.getElementById('fl-last-updated');
      if (f) f.value = '';
    });
  }
}());

// ── 26. Listings edit: additional field type → options-row visibility ──────────

(function () {
  var s = document.getElementById('af-type');
  var r = document.getElementById('af-options-row');
  if (!s || !r) return;
  function toggle() {
    var show = (s.value === 'dropdown' || s.value === 'checkbox' || s.value === 'radio');
    r.classList.toggle('is-hidden', !show);
    r.style.display = show ? '' : 'none';
  }
  s.addEventListener('change', toggle);
  toggle();
}());

// ── 27. Fields pages: field type → options-row visibility ─────────────────────

(function () {
  // fields/index.php
  var s1 = document.getElementById('fields-type-sel');
  var r1 = document.getElementById('fields-options-row');
  if (s1 && r1) {
    function toggle1() {
      var show = (s1.value === 'dropdown' || s1.value === 'checkbox' || s1.value === 'radio');
      r1.classList.toggle('is-hidden', !show);
      r1.style.display = show ? '' : 'none';
    }
    s1.addEventListener('change', toggle1);
    toggle1();
  }

  // fields/edit.php
  var s2 = document.getElementById('ef-type');
  var r2 = document.getElementById('ef-options-row');
  if (s2 && r2) {
    function toggle2() {
      var show = (s2.value === 'dropdown' || s2.value === 'checkbox' || s2.value === 'radio');
      r2.classList.toggle('is-hidden', !show);
      r2.style.display = show ? '' : 'none';
    }
    s2.addEventListener('change', toggle2);
    toggle2();
  }
}());

// ── 28. Categories add/edit: custom network name row toggle ───────────────────

(function () {
  var sel = document.querySelector('select[name="add_network"]');
  var row = document.getElementById('network-name-row');
  if (!sel || !row) return;
  function toggle() { row.classList.toggle('is-hidden', sel.value !== 'yes'); }
  sel.addEventListener('change', toggle);
  toggle();
}());

// ── 29. Members email: template selector + save template + preview modal ──────

(function () {
  if (!document.getElementById('email-form')) return;

  // Template select: populate subject and body when a saved template is chosen
  var tplSel = document.getElementById('em-template-select');
  if (tplSel) {
    tplSel.addEventListener('change', function () {
      var o = this.options[this.selectedIndex];
      if (o.value) {
        var subj = document.getElementById('subject');
        var body = document.getElementById('body');
        if (subj) subj.value = o.value;
        if (body) body.value = o.dataset.body || '';
      }
    });
  }

  // Save template: copy current field values into hidden inputs before submit
  var saveForm = document.getElementById('em-save-tpl-form');
  if (saveForm) {
    saveForm.addEventListener('submit', function () {
      var subj = document.getElementById('subject');
      var body = document.getElementById('body');
      var hs   = document.getElementById('em-save-subject');
      var hb   = document.getElementById('em-save-body');
      if (hs) hs.value = subj ? subj.value : '';
      if (hb) hb.value = body ? body.value : '';
    });
  }

  // Preview modal
  var modal           = document.getElementById('preview-modal');
  if (!modal) return;
  var listingName      = modal.dataset.listingName || '';
  var previewBtn       = document.getElementById('preview-email-btn');
  var emSendBtn        = document.getElementById('em-send-btn');
  var closeBtn         = document.getElementById('close-preview-btn');
  var emFooterPreview  = document.getElementById('em-footer-preview');
  var emFooterConfirm  = document.getElementById('em-footer-confirm');
  var emConfirmSend    = document.getElementById('em-confirm-send-btn');
  var emConfirmCancel  = document.getElementById('em-confirm-cancel-btn');

  function replaceTokens(text, map) {
    return text.replace(/\{([a-z_]+)\}/g, function (m, key) {
      return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : m;
    });
  }

  function emSetMode(mode) {
    if (!emFooterPreview || !emFooterConfirm) return;
    if (mode === 'confirm') {
      emFooterPreview.style.display = 'none';
      emFooterConfirm.style.display = 'block';
    } else {
      emFooterPreview.style.display = 'flex';
      emFooterConfirm.style.display = 'none';
    }
  }

  function previewEmail(mode) {
    var subj = document.getElementById('subject');
    var body = document.getElementById('body');
    var ps   = document.getElementById('preview-subject');
    var pb   = document.getElementById('preview-body');
    var md   = modal.dataset;
    var tokens = {
      fan_name:        md.fanName        || '',
      fan_email:       md.fanEmail       || '',
      fan_url:         md.fanUrl         || '',
      fan_country:     md.fanCountry     || '',
      fan_password:    '',
      name:            md.fanName        || '',
      email:           md.fanEmail       || '',
      url:             md.fanUrl         || '',
      added_at:        '',
      listing_name:    md.listingName    || '',
      listing_subject: md.listingSubject || '',
      listing_url:     md.listingUrl     || '',
      listing_title:   md.listingName    || '',
      site_name:       md.siteName       || '',
      owner_name:      md.ownerName      || '',
      owner_email:     md.ownerEmail     || '',
      app_name:        md.fanName        || '',
      app_email:       md.fanEmail       || '',
      app_url:         md.fanUrl         || '',
      app_message:     md.appMessage     || '',
      app_status:      md.appStatus      || '',
    };
    var rawSubj = (listingName ? listingName + ': ' : '') + (subj ? subj.value : '');

    // Populate addr-block rows
    var fromName  = md.ownerName  || '';
    var fromEmail = md.ownerEmail || '';
    var fanName   = md.fanName    || '';
    var fanEmail  = md.fanEmail   || '';
    var fn = document.getElementById('em-preview-from-name');
    var fe = document.getElementById('em-preview-from-email');
    var tn = document.getElementById('em-preview-to-name');
    var te = document.getElementById('em-preview-to-email');
    if (fn) fn.textContent = fromName;
    if (fe) fe.textContent = fromEmail ? '<' + fromEmail + '>' : '';
    if (tn) tn.textContent = fanName;
    if (te) te.textContent = fanEmail ? '<' + fanEmail + '>' : '';

    if (ps) ps.textContent = replaceTokens(rawSubj, tokens);
    if (pb) pb.textContent = replaceTokens(body ? body.value : '', tokens);

    emSetMode(mode === 'confirm' ? 'confirm' : 'preview');
    modal.style.display = 'flex';
  }

  function closePreview() {
    modal.style.display = 'none';
    emSetMode('preview');
  }

  if (previewBtn)    previewBtn.addEventListener('click',    function () { previewEmail('preview'); });
  if (emSendBtn)     emSendBtn.addEventListener('click',     function () { previewEmail('confirm'); });
  if (closeBtn)      closeBtn.addEventListener('click', closePreview);
  if (emConfirmCancel) emConfirmCancel.addEventListener('click', closePreview);
  if (emConfirmSend) emConfirmSend.addEventListener('click', function () {
    var form = document.getElementById('email-form');
    if (form) form.submit();
  });
  modal.addEventListener('click', function (e) { if (e.target === modal) closePreview(); });

  // Char counter
  var bodyEl   = document.getElementById('body');
  var charOut  = document.getElementById('em-char-count');
  function updateCharCount() {
    if (!bodyEl || !charOut) return;
    var n = bodyEl.value.length;
    charOut.textContent = n + ' char' + (n !== 1 ? 's' : '');
  }
  if (bodyEl) { bodyEl.addEventListener('input', updateCharCount); updateCharCount(); }
}());

// ── 30. Email template page: live preview ────────────────────────────────────

(function () {
  var pd = document.getElementById('et-preview-data');
  if (!pd) return;

  // Token substitution map (example/dummy values for preview)
  var tokens = {
    '{fan_name}':        'Jane Fan',
    '{fan_email}':       'jane@example.com',
    '{fan_url}':         'https://jane.example.com',
    '{fan_country}':     'United States',
    '{reset_url}':       'https://example.com/reset/1/confirm?token=abc123',
    '{listing_title}':    pd.dataset.listingName    || 'My Fanlisting',
    '{listing_subject}': pd.dataset.listingSubject || 'Music',
    '{listing_url}':     pd.dataset.listingUrl     || 'https://myfanlisting.com',
    '{site_name}':       pd.dataset.siteName       || 'Luster',
    '{owner_name}':      pd.dataset.ownerName      || 'The Admin',
    '{owner_email}':     pd.dataset.ownerEmail     || 'admin@example.com',
    '{aff_name}':        'Example Affiliate',
    '{aff_url}':         'https://affiliate.example.com',
    '{aff_email}':       'owner@affiliate.example.com',
    '{aff_message}':     'Message: I would love to affiliate with you.\n\n',
  };

  function updatePreview(type) {
    var subjInput = document.querySelector('.et-subject[data-type="' + type + '"]');
    var bodyInput = document.querySelector('.et-body[data-type="' + type + '"]');
    var pSubj     = document.querySelector('#et-preview-' + type + ' .et-preview-subject');
    var pBody     = document.querySelector('#et-preview-' + type + ' .et-preview-body');
    if (subjInput && pSubj) pSubj.textContent = applyTokenReplacements(subjInput.value, tokens);
    if (bodyInput && pBody) pBody.textContent = applyTokenReplacements(bodyInput.value, tokens);
  }

  // Wire preview toggle buttons
  document.querySelectorAll('.et-preview-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var type  = btn.dataset.type;
      var panel = document.getElementById('et-preview-' + type);
      if (!panel) return;
      var hidden = panel.classList.contains('is-hidden') || panel.style.display === 'none';
      panel.classList.remove('is-hidden');
      panel.style.display = hidden ? 'block' : 'none';
      btn.textContent = hidden ? 'Hide Preview' : 'Preview';
      if (hidden) updatePreview(type);
    });
  });

  // Wire live updates: subject + body inputs refresh visible preview panel
  document.querySelectorAll('.et-subject, .et-body').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var type  = inp.dataset.type;
      var panel = document.getElementById('et-preview-' + type);
      if (panel && panel.style.display !== 'none') updatePreview(type);
    });
  });
}());

// ── Email template enabled toggle ─────────────────────────────────────────────
//
// Drives the toggle-switch label text and shows/hides the disabled warning
// banner when the enabled checkbox is toggled.

(function () {
  document.querySelectorAll('.et-enabled-cb').forEach(function (cb) {
    var card   = cb.closest('.gradient-card');
    var banner = card ? card.querySelector('.modal-warning') : null;
    var label  = cb.parentElement ? cb.parentElement.querySelector('.et-toggle-label') : null;
    function sync() {
      var on = cb.checked;
      if (banner) banner.style.display = on ? 'none' : '';
      if (label)  label.textContent    = on ? 'Enabled' : 'Disabled';
      if (card)   card.classList.toggle('et-card-disabled', !on);
    }

    cb.addEventListener('change', function () {
      sync();

      // Auto-save the enabled state immediately via AJAX.
      var form  = document.getElementById('et-save-form');
      var csrf  = form ? form.querySelector('input[name="_csrf"]') : null;
      var scope = form ? form.querySelector('input[name="scope"]') : null;
      var type  = cb.name.replace('email_enabled_', '');

      if (!csrf || !scope || !type) { return; }

      var body = new URLSearchParams();
      body.append('_csrf',   csrf.value);
      body.append('scope',   scope.value);
      body.append('type',    type);
      body.append('enabled', cb.checked ? '1' : '0');

      fetch(form.action.replace('/save', '/toggle'), {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    body.toString(),
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok && window.lstrToast) {
            window.lstrToast('success', cb.checked ? 'Email enabled.' : 'Email disabled.');
          } else if (!data.ok && window.lstrToast) {
            window.lstrToast('error', data.error || 'Could not save.');
            cb.checked = !cb.checked; // revert toggle
            sync();
          }
        })
        .catch(function () {
          if (window.lstrToast) { window.lstrToast('error', 'Could not save.'); }
          cb.checked = !cb.checked; // revert toggle
          sync();
        });
    });
  });
}());

// ── Toast notifications ───────────────────────────────────────────────────────

(function () {
  var DURATION = 4000;

  var ICONS = {
    success: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="10" r="8"/><path d="M6.5 10l2.5 2.5 4.5-4.5"/></svg>',
    error:   '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="10" r="8"/><path d="M7 7l6 6M13 7l-6 6"/></svg>',
    warning: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 3L2 17h16L10 3z"/><path d="M10 9v4M10 14.5v.5"/></svg>',
  };

  var TITLES = { success: 'Success', error: 'Error', warning: 'Warning' };

  function getContainer() {
    var c = document.querySelector('.toast-container');
    if (!c) {
      c = document.createElement('div');
      c.className = 'toast-container';
      document.body.appendChild(c);
    }
    return c;
  }

  function dismiss(toast) {
    toast.style.animation = 'toastOut 0.22s ease forwards';
    setTimeout(function () {
      if (toast.parentNode) toast.parentNode.removeChild(toast);
    }, 220);
  }

  function createToast(type, title, message) {
    if (message === undefined) { message = title; title = TITLES[type] || type; }

    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type;

    var iconWrap = document.createElement('div');
    iconWrap.className = 'toast-icon-wrap';
    iconWrap.innerHTML = ICONS[type] || ICONS.error;

    var body = document.createElement('div');
    body.className = 'toast-body';

    var titleEl = document.createElement('div');
    titleEl.className = 'toast-title';
    titleEl.textContent = title;

    var msg = document.createElement('div');
    msg.className = 'toast-msg';
    msg.textContent = message;

    body.appendChild(titleEl);
    body.appendChild(msg);

    var close = document.createElement('button');
    close.className = 'toast-close';
    close.setAttribute('aria-label', 'Dismiss');
    close.innerHTML = '&times;';

    var progress = document.createElement('div');
    progress.className = 'toast-progress';
    progress.style.animation = 'toastDrain ' + (DURATION / 1000) + 's linear forwards';

    toast.appendChild(iconWrap);
    toast.appendChild(body);
    toast.appendChild(close);
    toast.appendChild(progress);

    var timer = setTimeout(function () { dismiss(toast); }, DURATION);

    close.addEventListener('click', function () {
      clearTimeout(timer);
      dismiss(toast);
    });

    return toast;
  }

  function liftAlerts() {
    var selectors = [
      '.page-content .alert-success',
      '.page-content .alert-error',
      '.page-content .alert-warning',
    ];
    var alerts = document.querySelectorAll(selectors.join(','));
    if (!alerts.length) return;

    var container = getContainer();

    alerts.forEach(function (alert) {
      var type = alert.classList.contains('alert-success') ? 'success'
               : alert.classList.contains('alert-warning') ? 'warning'
               : 'error';
      var message = alert.textContent.trim();
      if (!message) return;

      alert.style.display = 'none';
      container.appendChild(createToast(type, message));
    });
  }

  document.addEventListener('DOMContentLoaded', liftAlerts);

  /* Programmatic API:
     lstrToast(type, message)         -- auto title
     lstrToast(type, title, message)  -- custom title */
  window.lstrToast = function (type, title, message) {
    getContainer().appendChild(createToast(type, title, message));
  };
}());

/* ── Form submit loading state ─────────────────────────────────────────── */
(function () {
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.tagName !== 'FORM') return;

    /* Skip on the first (intercepted) submit -- only apply after confirmation */
    if (form.dataset.confirm && form.dataset.confirmed !== '1') return;

    var btn = form.querySelector('[type="submit"]');
    if (!btn || btn.classList.contains('btn-loading')) return;

    /* Only apply to btn-classed submit buttons to avoid bare inputs */
    if (!btn.classList.contains('btn')) return;

    btn.classList.add('btn-loading');
    btn.setAttribute('aria-disabled', 'true');

    /* Restore after 8s as a safety net (e.g. server error, back-navigation) */
    setTimeout(function () {
      btn.classList.remove('btn-loading');
      btn.removeAttribute('aria-disabled');
    }, 8000);
  });
}());

// ── 31. Custom templates: preview toggle + edit modal ────────────────────────

(function () {
  // ── Preview for "Add New Template" form ─────────────────────────────────────
  var previewBtn   = document.getElementById('ct-preview-btn');
  var previewPanel = document.getElementById('ct-new-preview');
  var previewSubj  = document.getElementById('ct-preview-subject');
  var previewBody  = document.getElementById('ct-preview-body');
  var newSubjInp   = document.getElementById('new-tpl-subject');
  var newBodyInp   = document.getElementById('new-tpl-body');

  var CT_TOKENS = {
    '{fan_name}':        'Jane Fan',
    '{fan_email}':       'jane@example.com',
    '{fan_url}':         'https://jane.example.com',
    '{fan_country}':     'United States',
    '{listing_title}':    'My Fanlisting',
    '{listing_subject}': 'Music',
    '{listing_url}':     'https://myfanlisting.com',
    '{site_name}':       'My Collective',
    '{owner_name}':      'The Admin',
    '{owner_email}':     'admin@example.com',
    '{name}':            'Jane Fan',
    '{email}':           'jane@example.com',
  };

  function updateCtPreview() {
    if (previewSubj) previewSubj.textContent = applyTokenReplacements(newSubjInp ? newSubjInp.value : '', CT_TOKENS);
    if (previewBody) previewBody.textContent = applyTokenReplacements(newBodyInp ? newBodyInp.value : '', CT_TOKENS);
  }

  if (previewBtn && previewPanel) {
    previewBtn.addEventListener('click', function () {
      var visible = previewPanel.style.display !== 'none';
      previewPanel.style.display = visible ? 'none' : 'block';
      previewBtn.textContent = visible ? 'Preview' : 'Hide Preview';
      if (!visible) updateCtPreview();
    });
  }

  [newSubjInp, newBodyInp].forEach(function (inp) {
    if (!inp) return;
    inp.addEventListener('input', function () {
      if (previewPanel && previewPanel.style.display !== 'none') updateCtPreview();
    });
  });

  // ── Clear button resets preview too ─────────────────────────────────────────
  var newForm = document.getElementById('ct-new-form');
  if (newForm) {
    var clearBtn = newForm.querySelector('.js-form-reset');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        if (previewPanel) previewPanel.style.display = 'none';
        if (previewBtn)   previewBtn.textContent = 'Preview';
      });
    }
  }

  // ── Edit modal ───────────────────────────────────────────────────────────────
  var editModal   = document.getElementById('ct-edit-modal');
  var editForm    = document.getElementById('ct-edit-form');
  var editName    = document.getElementById('ct-edit-name');
  var editSubject = document.getElementById('ct-edit-subject');
  var editBody    = document.getElementById('ct-edit-body');
  var editClose   = document.getElementById('ct-edit-close');
  var editCancel  = document.getElementById('ct-edit-cancel');

  document.querySelectorAll('.ct-edit-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!editModal || !editForm) return;
      if (editName)    editName.value    = btn.dataset.name    || '';
      if (editSubject) editSubject.value = btn.dataset.subject || '';
      if (editBody)    editBody.value    = btn.dataset.body    || '';
      editForm.action = (editForm.dataset.baseAction || '').replace('__ID__', btn.dataset.id || '0');
      editModal.style.display = 'flex';
    });
  });

  function closeEditModal() {
    if (editModal) editModal.style.display = 'none';
  }

  if (editClose)  editClose.addEventListener('click', closeEditModal);
  if (editCancel) editCancel.addEventListener('click', closeEditModal);
  if (editModal) {
    editModal.addEventListener('click', function (e) {
      if (e.target === editModal) closeEditModal();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && editModal.style.display !== 'none') closeEditModal();
    });
  }
}());

// ── 32. Embed codes: Direction A unified picker ────────────────────────────

(function () {
  var root = document.getElementById('ec-root');
  if (!root) return;

  var embedPath = root.dataset.embedPath || '';
  var adminBase = (root.dataset.baseUrl || '').replace(/\/$/, '');
  var baselineCss = adminBase ? adminBase + '/assets/css/default/style.css' : '';
  var listings  = JSON.parse(root.dataset.listings || '[]');
  var items     = JSON.parse(root.dataset.items    || '{}');

  // ── Tab switching ──────────────────────────────────────────────────────────
  var tabBtns    = root.querySelectorAll('[data-ec-tab]');
  var currentTab = 'main';
  var panels  = {
    main: document.getElementById('ec-panel-main'),
    ref:  document.getElementById('ec-panel-ref'),
  };

  function switchTab(id) {
    currentTab = id;
    tabBtns.forEach(function (b) {
      var on = b.dataset.ecTab === id;
      b.classList.toggle('active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    Object.keys(panels).forEach(function (k) {
      if (panels[k]) panels[k].classList.toggle('active', k === id);
    });
    if (id === 'ref') {
      var activeRefBtn = root.querySelector('.ec-ref-nav-item.act');
      var panelId = activeRefBtn ? activeRefBtn.dataset.ecRef : '';
      if (panelId) history.replaceState(null, '', location.pathname + location.search + '#ref/' + panelId);
    } else if (id === 'main') {
      if (currentKey) {
        var sec = sectionForKey(currentKey);
        if (sec) history.replaceState(null, '', location.pathname + location.search + '#' + sec + '/' + currentKey);
      } else {
        history.replaceState(null, '', location.pathname + location.search);
      }
    }
  }

  tabBtns.forEach(function (btn) {
    btn.addEventListener('click', function () { switchTab(btn.dataset.ecTab); });
  });

  // ── Utilities ──────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function highlight(code) {
    var e = code.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    return e.replace(/(\/\/[^\n]*)|('(?:[^'\\]|\\.)*')|(&lt;\?php|\?&gt;)|(\brequire\b|\btrue\b|\bfalse\b|\bnull\b)|(\$\w+)|(\b\d+\b)/g, function (m,c,s,t,k,v,n) {
      if (c !== undefined) return '<span class="ec-hl-c">' + m + '</span>';
      if (s !== undefined) return '<span class="ec-hl-s">' + m + '</span>';
      if (t !== undefined) return '<span class="ec-hl-k">' + m + '</span>';
      if (k !== undefined) return '<span class="ec-hl-k">' + m + '</span>';
      if (v !== undefined) return '<span class="ec-hl-v">' + m + '</span>';
      if (n !== undefined) return '<span class="ec-hl-n">' + m + '</span>';
      return m;
    });
  }

  // ── Cascading section → embed selectors ───────────────────────────────────
  var pickItems = root.querySelectorAll('.ec-qc-item'); // no-op in new layout; kept for selectItem() loop

  var navGroups = [
    { val: 'l', keys: ['join','members','stats','affiliates','aff-apply','update','remove','reset','codes','codes-submit','contact','updates','adopt','rules-gate'] },
    { val: 'c', keys: ['owned','joined','cstats','aff-col','col-aff-apply','random','latest','col-codes','col-codes-submit','kim','kim-list','kim-update','kim-reset','col-updates','col-contact','wishlist'] },
  ];

  var secSel   = document.getElementById('ec-sec-sel');
  var embSel   = document.getElementById('ec-emb-sel');

  // Returns the section val ('l'/'c') that owns a given embed key, or null
  function sectionForKey(key) {
    for (var i = 0; i < navGroups.length; i++) {
      if (navGroups[i].keys.indexOf(key) !== -1) return navGroups[i].val;
    }
    return null;
  }

  // Populate embSel for a given section val
  function populateEmbSel(sectionVal) {
    var group = navGroups.find(function (g) { return g.val === sectionVal; });
    embSel.innerHTML = '<option value="">&mdash; Choose a Section &mdash;</option>';
    embSel.disabled  = !group;
    if (!group) return;
    group.keys.slice().sort(function (a, b) {
      var aLabel = items[a] ? items[a].label : a;
      var bLabel = items[b] ? items[b].label : b;
      return aLabel.localeCompare(bLabel, undefined, { sensitivity: 'base' });
    }).forEach(function (key) {
      if (!items[key]) return;
      var opt = document.createElement('option');
      opt.value       = key;
      opt.textContent = items[key].label;
      embSel.appendChild(opt);
    });
  }

  if (secSel && embSel) {
    secSel.addEventListener('change', function () {
      populateEmbSel(secSel.value);
    });

    embSel.addEventListener('change', function () {
      if (embSel.value) {
        selectItem(embSel.value);
      }
    });
  }

  // ── Reset selector bar ─────────────────────────────────────────────────────
  var resetBtn = document.getElementById('ec-reset-btn');
  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      if (secSel) secSel.value = '';
      if (embSel) { embSel.innerHTML = '<option value="">Choose an embed…</option>'; embSel.disabled = true; }
      currentKey = '';
      history.replaceState(null, '', location.pathname + location.search);
      var ecEmpty    = document.getElementById('ec-empty');
      var ecPaneHead = document.getElementById('ec-pane-head');
      var ecPaneBody = document.getElementById('ec-pane-body');
      if (ecEmpty)    ecEmpty.style.display    = '';
      if (ecPaneHead) ecPaneHead.style.display = 'none';
      if (ecPaneBody) ecPaneBody.style.display = 'none';
    });
  }

  // ── Right pane elements ────────────────────────────────────────────────────
  var headIco        = document.getElementById('ec-head-ico');
  var headTitle      = document.getElementById('ec-head-title');
  var headBadge      = document.getElementById('ec-head-badge');
  var headDesc       = document.getElementById('ec-head-desc');
  var codePre        = document.getElementById('ec-code-pre');
  var codeFile       = document.getElementById('ec-code-file');
  var codeCpBtn      = document.getElementById('ec-code-cp');
  var prevBtn        = document.getElementById('ec-prev-btn');
  var prevModal      = document.getElementById('ec-prev-modal');
  var prevIframe     = document.getElementById('ec-prev-iframe');
  var prevModalTitle = document.getElementById('ec-prev-modal-title');
  var prevNewTab     = document.getElementById('ec-prev-newtab');
  var optsBody       = document.getElementById('ec-opts-body');
  var optsBadge      = document.getElementById('ec-opts-badge');

  // ── Preview modal open / close ─────────────────────────────────────────────
  function openPreview() {
    var url = buildPreviewUrl();
    if (!url || !prevModal || !prevIframe) return;
    if (prevModalTitle) prevModalTitle.textContent = (items[currentKey] || {}).label || 'Preview';
    if (prevNewTab) prevNewTab.href = url;
    prevIframe.src = url;
    prevModal.style.display = 'flex';
    prevModal.classList.remove('is-hidden');
  }

  function closePreview() {
    if (!prevModal) return;
    prevModal.style.display = 'none';
    if (prevIframe) prevIframe.src = '';
  }

  if (prevBtn) prevBtn.addEventListener('click', openPreview);

  if (prevModal) {
    prevModal.addEventListener('click', function (e) {
      if (e.target === prevModal) closePreview();
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && prevModal && prevModal.style.display !== 'none') closePreview();
  });

  var currentKey  = '';
  var currentCode = '';
  var custVals    = {};

  // ── Code generation ────────────────────────────────────────────────────────
  function phpStr(s) {
    return "'" + String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'") + "'";
  }

  var FILE = {
    join: 'join.php', members: 'members.php', stats: 'stats.php',
    update: 'update.php', remove: 'remove.php', reset: 'reset.php',
    affiliates: 'affiliates.php', owned: 'owned.php', joined: 'joined.php',
    cstats: 'collective-stats.php', 'aff-col': 'affiliates.php', 'col-aff-apply': 'affiliate-apply.php', 'col-codes': 'codes.php', 'col-codes-submit': 'collective-codes-submit.php',
    'aff-apply': 'affiliate-apply.php',
    random: 'random-listing.php', latest: 'latest-listing.php',
    kim: 'kim-signup.php', 'kim-list': 'kim-list.php', 'kim-update': 'kim-update.php', 'kim-reset': 'kim-reset.php',
    codes: 'codes.php', 'codes-submit': 'codes-submit.php',
    contact: 'contact.php', updates: 'updates.php', adopt: 'adopt.php',
    'col-updates': 'collective-updates.php', 'col-contact': 'collective-contact.php',
    wishlist: 'wishlist.php', 'rules-gate': 'rules-gate.php',
  };

  function generateCode(type, vals) {
    var lines = [];
    lines.push('<?php');
    // Listing ID
    var needsId = ['join','members','stats','update','remove','reset','aff-apply','codes','codes-submit','contact','updates','adopt','rules-gate'];
    if (needsId.indexOf(type) !== -1) {
      var lid = parseInt(vals.lid || (listings.length ? listings[0].id : 1), 10) || 1;
      if (type === 'aff-apply') lines.push('// Enable in Admin → Listings → Edit → Affiliates');
      if (type === 'updates')   lines.push('// Requires Updates Feed add-on (Admin → Add-ons)');
      if (type === 'adopt')     lines.push('// Requires Adoption add-on (Admin → Add-ons)');
      lines.push('$luster_fanlisting_id = ' + lid + ';');
    } else if (type === 'affiliates') {
      var aid = (vals.lid === '0' || vals.lid === '') ? 0 : (parseInt(vals.lid, 10) || (listings.length ? listings[0].id : 1));
      lines.push('$luster_fanlisting_id = ' + aid + '; // 0 = collective affiliates');
    } else if (type === 'aff-col') {
      lines.push('$luster_fanlisting_id = 0; // collective affiliates');
      if (vals.per_page && vals.per_page !== '') lines.push('$luster_per_page      = ' + (parseInt(vals.per_page, 10) || 20) + ';');
    } else if (type === 'col-aff-apply') {
      lines.push('// Enable collective affiliate applications in Admin → Settings');
      lines.push('$luster_fanlisting_id = 0; // collective affiliate application');
    } else if (type === 'col-codes' || type === 'col-codes-submit') {
      lines.push('$luster_fanlisting_id = 0; // collective codes');
    }
    // Type-specific options
    if (type === 'members') {
      if (vals.per_page && vals.per_page !== '' && vals.per_page !== '20') lines.push('$luster_per_page     = ' + (parseInt(vals.per_page, 10) || 20) + ';');
      if (vals.sort_by)                                                    lines.push('$luster_sort_by      = ' + phpStr(vals.sort_by) + ';');
      if (vals.show_search === 'false')                                    lines.push('$luster_show_search  = false;');
      if (vals.members_base)                                               lines.push('$luster_members_base = ' + phpStr(vals.members_base) + ';');
    }
    if (type === 'reset' && vals.reset_step) lines.push('$luster_reset_step = ' + phpStr(vals.reset_step) + ';');
    if (type === 'owned') {
      if (vals.browse_by_category === 'true') lines.push('$luster_browse_by_category = true;');
      if (vals.status)                        lines.push('$luster_status             = ' + phpStr(vals.status) + ';');
      if (vals.category_id)                   lines.push('$luster_category_id        = ' + (parseInt(vals.category_id, 10) || 0) + ';');
      if (vals.hide_dropdown === 'true')      lines.push('$luster_hide_dropdown      = true;');
      if (vals.show_list === 'false')         lines.push('$luster_show_list          = false;');
      if (vals.show_cat_switcher === 'false') lines.push('$luster_show_cat_switcher  = false;');
      if (vals.exclude_pending === 'false')   lines.push('$luster_exclude_pending    = false;');
    }
    if (type === 'joined') {
      if (vals.browse_by_category === 'true') lines.push('$luster_browse_by_category = true;');
      if (vals.category)                      lines.push('$luster_category           = ' + phpStr(vals.category) + ';');
      if (vals.show_list === 'false')         lines.push('$luster_show_list          = false;');
      if (vals.show_cat_switcher === 'false') lines.push('$luster_show_cat_switcher  = false;');
      if (vals.show_all_by_default === 'true') lines.push('$luster_show_all_by_default = true;');
    }
    if (type === 'random' && vals.status) lines.push('$luster_status = ' + phpStr(vals.status) + ';');
    if (type === 'updates' || type === 'col-updates') {
      if (vals.per_page && vals.per_page !== '') lines.push('$luster_per_page  = ' + (parseInt(vals.per_page, 10) || 10) + ';');
      if (vals.show_cats === 'false')            lines.push('$luster_show_cats = false;');
    }
    if (type === 'wishlist' && vals.wishlist_type && vals.wishlist_type !== 'all') {
      lines.push('$luster_wishlist_type = ' + phpStr(vals.wishlist_type) + ';');
    }
    if (type === 'kim-list' && vals.only_with_signups === 'false') {
      lines.push('$luster_kim_only_with_signups = false;');
    }
    // Stats template override
    if (type === 'stats' && Array.isArray(vals.tpl) && vals.tpl.length > 0) {
      if (vals.tpl.length === 1) {
        lines.push('$luster_stats_template = ' + phpStr(vals.tpl[0].line) + ';');
      } else {
        lines.push('$luster_stats_template = ' + phpStr(vals.tpl[0].line));
        for (var ti = 1; ti < vals.tpl.length - 1; ti++) {
          lines.push("  . '<br>" + vals.tpl[ti].line.replace(/\\/g,'\\\\').replace(/'/g,"\\'") + "'");
        }
        lines.push("  . '<br>" + vals.tpl[vals.tpl.length-1].line.replace(/\\/g,'\\\\').replace(/'/g,"\\'") + "';");
      }
    }
    // Require line
    if (type === 'kim' || type === 'kim-list' || type === 'kim-update' || type === 'kim-reset')
                                lines.push('// Requires KIM List add-on (Admin → Add-ons)');
    if (type === 'col-updates') lines.push('// Requires Updates Feed add-on (Admin → Add-ons)');
    if (type === 'wishlist')    lines.push('// Requires Wishlist add-on (Admin → Add-ons)');
    if (type === 'latest')      lines.push('// Renders the listing with the most recent open date.');
    if (type === 'rules-gate') {
      lines.push('// Requires Join Rules add-on (Admin -> Add-ons)');
      lines.push('$luster_return = true;');
      lines.push("$gate = require LUSTER_EMBED . '/rules-gate.php';");
      lines.push("if ($gate !== '') { echo $gate; return; }");
      lines.push('// Fan has agreed -- show the join form:');
      lines.push('$luster_return = false;');
      lines.push("require LUSTER_EMBED . '/join.php';");
    } else {
      lines.push("require LUSTER_EMBED . '/" + (FILE[type] || type + '.php') + "';");
    };
    // Collective stats display lines
    if (type === 'cstats') {
      var selVars = Array.isArray(vals.vars) ? vals.vars : [];
      if (selVars.length > 0) {
        selVars.forEach(function (v) {
          var stmt = 'echo $' + v.name + ';';
          var pad  = new Array(Math.max(2, 34 - stmt.length)).join(' ');
          lines.push(stmt + pad + '// ' + v.label);
        });
      } else {
        lines.push('// Choose stats above to add display lines here.');
      }
    }
    lines.push('?>');
    return lines.join('\n');
  }

  // ── CUST definitions ───────────────────────────────────────────────────────
  var CUST = {
    join: {
      fields: [{ id: 'lid', label: 'Listing', type: 'listing', hint: '' }],
    },
    members: {
      fields: [
        { id: 'lid',          label: 'Listing',                 type: 'listing', hint: '' },
        { id: 'per_page',     label: 'Members per page',        type: 'number', ph: '20', hint: 'Leave blank to use your normal Luster setting.' },
        { id: 'sort_by',      label: 'Start sorted by',         type: 'select', opts: [['','Let visitors choose'],['name','Name'],['country','Country']], hint: '' },
        { id: 'show_search',  label: 'Search and sort tools',   type: 'select', opts: [['','Show'],['false','Hide']], hint: '' },
        { id: 'members_base', label: 'Member list page',        type: 'text',   ph: 'https://yoursite.com/members.php', hint: 'Use this if page numbers should stay on your own member page.' },
      ],
    },
    stats: {
      fields: [
        { id: 'lid', label: 'Listing', type: 'listing', hint: '' },
        { id: 'tpl', label: 'Stats to show', type: 'tokens',
          hint: 'Choose stats for this snippet, or leave everything unchecked to use the stats template saved in Luster.',
          tokens: [
            { token: '{stat_approved}',     label: 'Approved members',     line: '{stat_approved} members' },
            { token: '{stat_pending}',      label: 'Pending members',      line: '{stat_pending} pending' },
            { token: '{stat_opened}',       label: 'Date opened',          line: 'Opened: {stat_opened}' },
            { token: '{stat_updated}',      label: 'Newest member date',   line: 'Last member: {stat_updated}' },
            { token: '{stat_last_updated}', label: 'Last updated date',    line: 'Last updated: {stat_last_updated}' },
            { token: '{stat_newmembers}',   label: 'Newest members',       line: 'Newest: {stat_newmembers}' },
            { token: '{stat_growth}',       label: 'Average members per day', line: 'Growth: {stat_growth}/day' },
            { token: '{listing_title}',      label: 'Listing name',         line: '{listing_title}' },
            { token: '{listing_url}',       label: 'Listing link',         line: '{listing_url}' },
            { token: '{approved_by}',       label: 'Approved-by network',   line: '{if_approved_by}Approved by: {approved_by}{/if_approved_by}' },
          ],
        },
      ],
    },
    affiliates: {
      fields: [{ id: 'lid', label: 'Listing', type: 'listing_or_zero', hint: 'Choose Collective for site-wide affiliates.' }],
    },
    'aff-apply': {
      fields: [{ id: 'lid', label: 'Listing', type: 'listing', hint: '' }],
    },
    update: {
      fields: [{ id: 'lid', label: 'Listing', type: 'listing', hint: '' }],
    },
    remove: {
      fields: [{ id: 'lid', label: 'Listing', type: 'listing', hint: '' }],
    },
    reset: {
      fields: [
        { id: 'lid',        label: 'Listing', type: 'listing', hint: '' },
        { id: 'reset_step', label: 'Password reset page', type: 'select',
          opts: [['','Normal reset page'],['confirm','New-password page only']],
          hint: 'Most sites should use "Normal reset page." Use the second option only for a separate new-password page.' },
      ],
    },
    owned: {
      fields: [
        { id: 'browse_by_category', label: 'What visitors see first', type: 'select', opts: [['','Listings'],['true','Category list']], hint: 'Choose Category list if you want visitors to pick a topic before seeing listings.' },
        { id: 'status',             label: 'Listings to show',        type: 'select', opts: [['','Current only'],['upcoming','Upcoming only'],['pending','Pending only'],['all','All statuses']], hint: '' },
        { id: 'category_id',        label: 'Only this category ID',    type: 'number', ph: '0', hint: 'Optional. Leave blank unless you know the category ID you want to lock this snippet to.' },
        { id: 'hide_dropdown',      label: 'Category dropdown',       type: 'select', opts: [['','Show'],['true','Hide']], hint: '' },
        { id: 'show_list',          label: 'Overview style',          type: 'select', opts: [['','Link list'],['false','Dropdown']], hint: 'How categories appear on the main page before a category is selected.' },
        { id: 'show_cat_switcher',  label: 'Per-category switcher',   type: 'select', opts: [['','Show'],['false','Hide']], hint: 'When a visitor is browsing a category, show a dropdown to switch to another.' },
        { id: 'exclude_pending',    label: 'Pending listings',        type: 'select', opts: [['','Hide when showing all'],['false','Show when showing all']], hint: 'Only matters when "Listings to show" is All statuses.' },
      ],
    },
    joined: {
      fields: [
        { id: 'browse_by_category', label: 'What visitors see first', type: 'select', opts: [['','Listings'],['true','Category list']], hint: 'Choose Category list if you want visitors to pick a topic before seeing listings.' },
        { id: 'category',           label: 'Category view',           type: 'select', opts: [['','Let visitors choose'],['all','Show all by category']], hint: 'To show one category only, add its name manually in the snippet.' },
        { id: 'show_list',          label: 'Overview style',          type: 'select', opts: [['','Link list'],['false','Dropdown']], hint: 'How categories appear on the main page before a category is selected.' },
        { id: 'show_cat_switcher',  label: 'Per-category switcher',   type: 'select', opts: [['','Show'],['false','Hide']], hint: 'When a visitor is browsing a category, show a dropdown to switch to another.' },
        { id: 'show_all_by_default', label: 'Show all first',          type: 'select', opts: [['','No'],['true','Yes']], hint: 'Shows all joined listings immediately, while still keeping the category chooser.' },
      ],
    },
    cstats: {
      fields: [
        { id: 'vars', label: 'Stats to show', type: 'cstatvars',
          hint: 'Choose the stats you want printed by the snippet. People comfortable with PHP can still use the rest manually.',
          vars: [
            { name: 'luster_owned_active',        label: 'Current owned listings',   desc: '' },
            { name: 'luster_owned_upcoming',      label: 'Upcoming owned listings',  desc: '' },
            { name: 'luster_owned_pending',       label: 'Pending owned listings',   desc: '' },
            { name: 'luster_owned',               label: 'Total owned listings',     desc: '' },
            { name: 'luster_joined_active',       label: 'Active joined listings',   desc: '' },
            { name: 'luster_joined_approved',     label: 'Approved joined listings', desc: '' },
            { name: 'luster_joined_upcoming',     label: 'Upcoming joined listings', desc: '' },
            { name: 'luster_joined_pending',      label: 'Pending joined listings',  desc: '' },
            { name: 'luster_joined',              label: 'Total joined listings',    desc: '' },
            { name: 'luster_affiliates',          label: 'Collective affiliates',    desc: '' },
            { name: 'luster_members_approved',    label: 'Approved members',         desc: '' },
            { name: 'luster_members_pending',     label: 'Pending members',          desc: '' },
            { name: 'luster_members_total',       label: 'Total members',            desc: '' },
            { name: 'luster_countries_count',     label: 'Countries represented',    desc: '' },
            { name: 'luster_total_cats',          label: 'Owned categories',         desc: '' },
            { name: 'luster_owned_current',       label: 'Owned current alias',      desc: '' },
            { name: 'luster_collective_fans',     label: 'Total fans alias',         desc: '' },
            { name: 'luster_owned_growth_rate',   label: 'Owned listings per year',  desc: '' },
            { name: 'luster_joined_growth_rate',  label: 'Joined listings per year', desc: '' },
            { name: 'luster_members_growth_rate', label: 'Members per day',          desc: '' },
          ],
        },
      ],
    },
    codes: {
      fields: [{ id: 'lid', label: 'Listing', type: 'listing', hint: '' }],
    },
    'codes-submit': {
      fields: [
        { id: 'lid', label: 'Listing', type: 'listing', hint: '' },
      ],
    },
    contact: {
      fields: [{ id: 'lid', label: 'Listing', type: 'listing', hint: '' }],
    },
    updates: {
      fields: [
        { id: 'lid',       label: 'Listing',            type: 'listing', hint: '' },
        { id: 'per_page',  label: 'Updates per page',   type: 'number', ph: '10', hint: '' },
        { id: 'show_cats', label: 'Category labels',    type: 'select', opts: [['','Show'],['false','Hide']], hint: '' },
      ],
    },
    adopt: {
      fields: [{ id: 'lid', label: 'Listing', type: 'listing', hint: '' }],
    },
    'aff-col': {
      fields: [
        { id: 'per_page', label: 'Affiliates per page', type: 'number', ph: '20', hint: 'Leave blank to use the normal amount.' },
      ],
    },
    'col-aff-apply': {
      fields: [],
      note: 'This is already set up for collective affiliate forms.',
    },
    'col-codes': {
      fields: [],
      note: 'This is already set up for collective codes.',
    },
    'col-codes-submit': {
      fields: [],
      note: 'This is already set up for collective code donations.',
    },
    'col-updates': {
      fields: [
        { id: 'per_page',  label: 'Updates per page',   type: 'number', ph: '10', hint: '' },
        { id: 'show_cats', label: 'Category labels',    type: 'select', opts: [['','Show'],['false','Hide']], hint: '' },
      ],
    },
    'col-contact': {
      fields: [],
    },
    wishlist: {
      fields: [
        { id: 'wishlist_type', label: 'Wishlist entries to show', type: 'select',
          opts: [['all','All'],['wanted','Wanted'],['top','Top'],['granted','Granted']],
          hint: 'Choose a type, or leave this on All.' },
      ],
    },
    random: {
      fields: [
        { id: 'status', label: 'Choose from', type: 'select',
          opts: [['current','Current listings'],['upcoming','Upcoming listings'],['all','All listings']], hint: '' },
      ],
    },
    latest: {
      fields: [],
    },
    kim: {
      fields: [],
      note: 'Set your KIM page links in Settings first. After that, this snippet is ready to copy.',
    },
    'kim-list': {
      fields: [
        { id: 'only_with_signups', label: 'Listings to show', type: 'select', opts: [['','Only with sign-ups'],['false','All listings']], hint: 'By default, only fanlistings that have at least one approved KIM member are shown.' },
      ],
      note: 'Set your KIM page links in Settings first. After that, this snippet is ready to copy.',
    },
    'kim-update': {
      fields: [],
      note: 'Set your KIM page links in Settings first. After that, this snippet is ready to copy.',
    },
    'kim-reset': {
      fields: [],
      note: 'Set your KIM page links in Settings first. After that, this snippet is ready to copy.',
    },
    'rules-gate': {
      fields: [{ id: 'lid', label: 'Listing', type: 'listing', hint: '' }],
    },
  };

  // ── Options form builder ───────────────────────────────────────────────────
  function buildOpts(type) {
    var def = CUST[type];
    if (!def || !optsBody) return;
    custVals = {};
    if (!def.fields.length) {
      optsBody.innerHTML = '<p class="ec-opts-empty">' + (def.note || 'No choices needed. This snippet is ready to copy.') + '</p>';
      return;
    }
    var html = '';
    def.fields.forEach(function (f) {
      // Token grid (stats template builder)
      if (f.type === 'tokens') {
        custVals[f.id] = [];
        html += '<div class="listing-field-row listing-field-row--top">'
              + '<label class="listing-field-label">' + f.label + '</label>'
              + '<div><div class="ec-token-grid">';
        (f.tokens || []).forEach(function (t) {
          html += '<label class="ec-token-item">'
                + '<input type="checkbox" class="ec-tok-cb" data-tokfid="' + esc(f.id) + '"'
                + ' data-tok="' + esc(t.token) + '" data-line="' + esc(t.line) + '">'
                + '<span class="ec-token-inner">'
                + '<span class="ec-tok-name">' + esc(t.token) + '</span>'
                + '<span class="ec-tok-desc">' + esc(t.label) + '</span>'
                + '</span></label>';
        });
        html += '</div>';
        if (f.hint) html += '<p class="form-hint">' + f.hint + '</p>';
        html += '</div></div>';
        return;
      }
      // Variable grid (cstats echo builder)
      if (f.type === 'cstatvars') {
        custVals[f.id] = [];
        html += '<div class="listing-field-row listing-field-row--top">'
              + '<label class="listing-field-label">' + f.label + '</label>'
              + '<div><div class="ec-token-grid">';
        (f.vars || []).forEach(function (v) {
          html += '<label class="ec-token-item">'
                + '<input type="checkbox" class="ec-var-cb" data-varfid="' + esc(f.id) + '"'
                + ' data-varname="' + esc(v.name) + '" data-varlabel="' + esc(v.label) + '">'
                + '<span class="ec-token-inner">'
                + '<span class="ec-tok-name">$' + esc(v.name) + '</span>'
                + '<span class="ec-tok-desc">' + esc(v.label) + (v.desc ? ' -- ' + esc(v.desc) : '') + '</span>'
                + '</span></label>';
        });
        html += '</div>';
        if (f.hint) html += '<p class="form-hint">' + f.hint + '</p>';
        html += '</div></div>';
        return;
      }
      // Standard option: label column + control column
      html += '<div class="listing-field-row listing-field-row--top">'
            + '<label class="listing-field-label" for="ec-f-' + esc(f.id) + '">' + f.label + '</label>'
            + '<div>';
      if (f.type === 'listing' || f.type === 'listing_or_zero') {
        html += '<div class="select-wrap"><select id="ec-f-' + esc(f.id) + '" data-fid="' + esc(f.id) + '">';
        if (f.type === 'listing_or_zero') html += '<option value="0">Collective</option>';
        listings.forEach(function (fl) {
          var label = esc(fl.subject || fl.name);
          html += '<option value="' + parseInt(fl.id, 10) + '">' + label + '</option>';
        });
        if (!listings.length) html += '<option value="1">1</option>';
        html += '</select></div>';
      } else if (f.type === 'select') {
        html += '<div class="select-wrap"><select id="ec-f-' + esc(f.id) + '" data-fid="' + esc(f.id) + '">';
        (f.opts || []).forEach(function (o) {
          html += '<option value="' + esc(o[0]) + '">' + esc(o[1]) + '</option>';
        });
        html += '</select></div>';
      } else {
        html += '<input class="form" type="' + (f.type === 'number' ? 'number' : 'text') + '"'
              + ' id="ec-f-' + esc(f.id) + '" data-fid="' + esc(f.id) + '"'
              + (f.ph !== undefined ? ' placeholder="' + esc(f.ph) + '"' : '') + '>';
      }
      if (f.hint) html += '<p class="form-hint">' + esc(f.hint) + '</p>';
      html += '</div></div>';
    });
    optsBody.innerHTML = html;
    // Wire standard inputs
    optsBody.querySelectorAll('[data-fid]').forEach(function (el) {
      custVals[el.dataset.fid] = el.value;
      el.addEventListener('input',  function () { custVals[el.dataset.fid] = el.value; refreshCode(); });
      el.addEventListener('change', function () { custVals[el.dataset.fid] = el.value; refreshCode(); });
    });
    // Wire token checkboxes
    optsBody.querySelectorAll('.ec-tok-cb').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var fid = cb.dataset.tokfid;
        custVals[fid] = [];
        optsBody.querySelectorAll('.ec-tok-cb[data-tokfid="' + fid + '"]:checked').forEach(function (c) {
          custVals[fid].push({ token: c.dataset.tok, line: c.dataset.line });
        });
        refreshCode();
      });
    });
    // Wire var checkboxes
    optsBody.querySelectorAll('.ec-var-cb').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var fid = cb.dataset.varfid;
        custVals[fid] = [];
        optsBody.querySelectorAll('.ec-var-cb[data-varfid="' + fid + '"]:checked').forEach(function (c) {
          custVals[fid].push({ name: c.dataset.varname, label: c.dataset.varlabel });
        });
        refreshCode();
      });
    });
  }

  function countCustomized(type, vals) {
    var n = 0;
    ((CUST[type] || {}).fields || []).forEach(function (f) {
      if (f.id === 'lid') return;
      var v = vals[f.id];
      if (f.type === 'tokens' || f.type === 'cstatvars') {
        if (Array.isArray(v) && v.length > 0) n++;
      } else if (f.type === 'number') {
        if (v && v !== '') n++;
      } else if (f.type === 'select') {
        var def0 = (f.opts && f.opts[0]) ? f.opts[0][0] : '';
        if (v && v !== def0) n++;
      } else if (v && v !== '') {
        n++;
      }
    });
    return n;
  }

  function refreshCode() {
    currentCode = generateCode(currentKey, custVals);
    if (codePre) codePre.innerHTML = highlight(currentCode);
    var n = countCustomized(currentKey, custVals);
    if (optsBadge) {
      optsBadge.textContent = n + ' option' + (n !== 1 ? 's' : '') + ' set';
      optsBadge.style.display = n > 0 ? '' : 'none';
    }
    if (prevBtn) {
      prevBtn.style.display = buildPreviewUrl() ? '' : 'none';
    }
    schedulePreviewRefresh();
  }

  var prevRefreshTimer = null;
  function schedulePreviewRefresh() {
    if (!prevModal || prevModal.style.display === 'none') return;
    clearTimeout(prevRefreshTimer);
    prevRefreshTimer = setTimeout(function () {
      var url = buildPreviewUrl();
      if (url && prevIframe) {
        prevIframe.src = url;
        if (prevNewTab) prevNewTab.href = url;
      }
    }, 600);
  }

  function buildPreviewUrl() {
    if (!currentKey || !adminBase) return '';
    var url = adminBase + '/admin/embed-codes/preview?key=' + encodeURIComponent(currentKey);
    if (currentKey === 'aff-col' || currentKey === 'col-aff-apply' || currentKey === 'col-codes' || currentKey === 'col-codes-submit') {
      url += '&lid=0';
    } else if (custVals.lid !== undefined && custVals.lid !== '') {
      url += '&lid=' + encodeURIComponent(custVals.lid);
    }
    var skip = { lid: true, tpl: true, vars: true };
    Object.keys(custVals).forEach(function (k) {
      if (skip[k] || typeof custVals[k] !== 'string' || custVals[k] === '') return;
      url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(custVals[k]);
    });
    return url;
  }

  // ── (static preview renderer removed — preview opens via buildPreviewUrl) ───
  function buildPreview(key, vals) {
    var listing = listings[0] || { id: 1, name: 'My Fanlisting' };
    var lid = parseInt(vals.lid || listing.id, 10);
    listing = listings.find(function (l) { return l.id === lid; }) || listing;
    var name = esc(listing.name);

    switch (key) {
      case 'join':
        return '<h4>Join ' + name + '</h4>'
          + '<p class="site-sub">Fill in your details to be added to the fanlisting.</p>'
          + '<label>Name</label><input class="fld" placeholder="Your name" />'
          + '<label>Email</label><input class="fld" placeholder="you@example.com" />'
          + '<label>Website <span style="color:#a39cbb;font-weight:400">(optional)</span></label><input class="fld" placeholder="https://" />'
          + '<label>Country</label><select class="fld fld--select"><option>Philippines</option><option>United States</option></select>'
          + '<button class="site-btn">Join the fanlisting</button>';

      case 'members':
        var showCtrl = vals.show_search !== 'false';
        return '<h4>Members <span style="color:#a39cbb;font-weight:400;font-size:.85rem">(33)</span></h4>'
          + (showCtrl ? '<p class="site-sub">Sort: <a class="site-link" href="#">Name</a> &middot; <a class="site-link" href="#">Country</a></p>' : '')
          + ['Alexia','Bea','Cara','Devon','Elise'].map(function (n, i) {
              return '<div class="site-row"><a class="site-link" href="#">' + n + '</a><span class="site-flag">' + ['Philippines','United States','Japan','Canada','France'][i] + '</span></div>';
            }).join('')
          + '<div class="site-pager"><span class="on">1</span><span>2</span><span>3</span><span>&rsaquo;</span></div>';

      case 'stats':
        return '<h4>' + name + ' &mdash; Statistics</h4>'
          + '<div class="site-stats">'
          + '<div class="site-stat"><span class="k">Members</span><span class="v">33</span></div>'
          + '<div class="site-stat"><span class="k">Pending</span><span class="v">2</span></div>'
          + '<div class="site-stat"><span class="k">Opened</span><span class="v">Jan 29, 2026</span></div>'
          + '<div class="site-stat"><span class="k">Updated</span><span class="v">May 12, 2026</span></div>'
          + '<div class="site-stat"><span class="k">Growth</span><span class="v">0.31/day</span></div>'
          + '<div class="site-stat"><span class="k">Newest</span><span class="v">Elise</span></div>'
          + '</div>'
          + '<div class="site-note">Shown using the stats template saved in Luster.</div>';

      case 'col-codes':
      case 'codes':
        return '<h4>Codes' + (key === 'col-codes' ? '' : ' -- ' + name) + '</h4>'
          + '<div class="site-note" style="margin-bottom:.75rem">50&times;50</div>'
          + '<div style="display:flex;flex-wrap:wrap;gap:6px">'
          + [1,2,3,4,5].map(function () { return '<div style="width:50px;height:50px;background:#e4def2;border-radius:4px;border:1px solid #d0c8e8"></div>'; }).join('')
          + '</div>'
          + '<div class="site-note" style="margin-top:.75rem">75&times;50</div>'
          + '<div style="display:flex;flex-wrap:wrap;gap:6px">'
          + [1,2,3].map(function () { return '<div style="width:75px;height:50px;background:#e4def2;border-radius:4px;border:1px solid #d0c8e8"></div>'; }).join('')
          + '</div>';

      case 'col-codes-submit':
      case 'codes-submit':
        return '<h4>Submit a code</h4>'
          + '<p class="site-sub">Contribute a graphic code' + (key === 'col-codes-submit' ? ' to the collective.' : ' to ' + name + '.') + '</p>'
          + '<label>Your name</label><input class="fld" placeholder="Your name" />'
          + '<label>Your website <span style="color:#a39cbb;font-weight:400">(optional)</span></label><input class="fld" placeholder="https://" />'
          + '<label>Code image <span style="color:#c0388a;font-weight:400">*</span></label>'
          + '<div style="border:1.5px dashed #d0c8e8;border-radius:7px;padding:.75rem;text-align:center;color:#a39cbb;font-size:.76rem;margin-bottom:.625rem">Click or drag an image file here</div>'
          + '<button class="site-btn">Submit code</button>';

      case 'contact':
      case 'col-contact':
        return '<h4>' + (key === 'col-contact' ? 'Contact us' : 'Contact') + '</h4>'
          + '<p class="site-sub">Send a message to the ' + (key === 'col-contact' ? 'site' : 'listing') + ' owner.</p>'
          + '<label>Name</label><input class="fld" placeholder="Your name" />'
          + '<label>Email</label><input class="fld" placeholder="you@example.com" />'
          + '<label>Message</label>'
          + '<div style="width:100%;box-sizing:border-box;min-height:60px;padding:.4rem .625rem;border:1px solid #d8d3e8;border-radius:6px;background:#fff;font-size:.76rem;color:#a39cbb;margin-bottom:.625rem">Your message here...</div>'
          + '<button class="site-btn">Send message</button>';

      case 'updates':
      case 'col-updates': {
        var showCats = vals.show_cats === 'true';
        return '<h4>' + (key === 'col-updates' ? 'Updates' : name + ' -- Updates') + '</h4>'
          + [['Site launch', 'May 10, 2026', 'Anime'],['New members', 'Apr 28, 2026', 'News']].map(function (e) {
              return '<div style="padding:.5rem 0;border-bottom:1px solid #ece8f4">'
                + (showCats ? '<span style="font-size:.68rem;color:#a39cbb;margin-bottom:.2rem;display:block">' + e[2] + '</span>' : '')
                + '<div style="font-weight:600;color:#1f1b33;font-size:.82rem">' + e[0] + '</div>'
                + '<div style="font-size:.72rem;color:#79738f">' + e[1] + '</div>'
                + '</div>';
            }).join('');
      }

      case 'adopt':
        return '<h4>Apply to adopt ' + name + '</h4>'
          + '<p class="site-sub">Interested in taking over this fanlisting? Fill in your details.</p>'
          + '<label>Name</label><input class="fld" placeholder="Your name" />'
          + '<label>Email</label><input class="fld" placeholder="you@example.com" />'
          + '<label>Website</label><input class="fld" placeholder="https://" />'
          + '<label>Why are you interested?</label>'
          + '<div style="width:100%;box-sizing:border-box;min-height:50px;padding:.4rem .625rem;border:1px solid #d8d3e8;border-radius:6px;background:#fff;font-size:.76rem;color:#a39cbb;margin-bottom:.625rem">Tell the owner why you&apos;d like to take over...</div>'
          + '<button class="site-btn">Submit application</button>';

      case 'wishlist': {
        var wtype = vals.wishlist_type || 'all';
        var wItems = [
          { label: 'Hayley Williams', type: 'Wanted' },
          { label: 'Taylor Swift', type: 'Top' },
          { label: 'Paramore -- Misery Business', type: 'Wanted' },
        ].filter(function (w) { return wtype === 'all' || w.type.toLowerCase() === wtype; });
        return '<h4>Wishlist</h4>'
          + (wItems.length ? wItems.map(function (w) {
              return '<div class="site-row"><span>' + w.label + '</span><span class="site-flag">' + w.type + '</span></div>';
            }).join('') : '<p class="site-sub">No items to show for this filter.</p>');
      }

      case 'affiliates':
      case 'aff-col':
        return '<h4>Affiliates</h4><div class="site-aff">'
          + ['Stardust','Moonbeam','Velvet','Aurora','Petal','Coda'].map(function (a) {
              return '<a href="#">' + a + '</a>';
            }).join('')
          + '</div>';

      case 'aff-apply':
      case 'col-aff-apply':
        return '<h4>Apply to affiliate</h4>'
          + '<p class="site-sub">Run a related site? Apply to be listed as an affiliate' + (key === 'col-aff-apply' ? ' for the collective.' : '.') + '</p>'
          + '<label>Site name</label><input class="fld" placeholder="Your site" />'
          + '<label>Site URL</label><input class="fld" placeholder="https://" />'
          + '<label>Message <span style="color:#a39cbb;font-weight:400">(optional)</span></label><input class="fld" placeholder="A quick hello" />'
          + '<button class="site-btn">Submit application</button>';

      case 'update':
        return '<h4>Update your details</h4>'
          + '<p class="site-sub">Sign in with the email and password you joined with.</p>'
          + '<label>Email</label><input class="fld" placeholder="you@example.com" />'
          + '<label>Password</label><input class="fld" type="password" placeholder="••••••••" />'
          + '<button class="site-btn">Load my details</button>';

      case 'remove':
        return '<h4>Remove membership</h4>'
          + '<p class="site-sub">This removes you from ' + name + '. It can&apos;t be undone.</p>'
          + '<label>Email</label><input class="fld" placeholder="you@example.com" />'
          + '<label>Password</label><input class="fld" type="password" placeholder="••••••••" />'
          + '<button class="site-btn" style="background:#c0392b">Remove me</button>';

      case 'reset':
        if (vals.reset_step === 'confirm') {
          return '<h4>Reset your password</h4>'
            + '<p class="site-sub">Choose a new password.</p>'
            + '<label>New password</label><input class="fld" type="password" placeholder="••••••••" />'
            + '<label>Confirm</label><input class="fld" type="password" placeholder="••••••••" />'
            + '<button class="site-btn">Save new password</button>';
        }
        return '<h4>Reset your password</h4>'
          + '<p class="site-sub">Enter your email and we&apos;ll send a reset link.</p>'
          + '<label>Email</label><input class="fld" placeholder="you@example.com" />'
          + '<button class="site-btn">Send reset link</button>';

      case 'owned': {
        var browse = vals.browse_by_category === 'true';
        var asList = vals.show_list === 'true';
        return '<h4>Owned fanlistings</h4>'
          + (browse
              ? (asList
                  ? '<ul style="margin:0;padding-left:18px"><li><a class="site-link" href="#">Anime (12)</a></li><li><a class="site-link" href="#">Music (8)</a></li><li><a class="site-link" href="#">Games (5)</a></li></ul>'
                  : '<select class="fld fld--select"><option>Choose a category...</option><option>Anime (12)</option><option>Music (8)</option></select>')
              : [['Huntrix','Current'],['The Pride: 1000% You','Current'],['Golden Hour','Upcoming']].map(function (it) {
                  return '<div class="site-row"><a class="site-link" href="#">' + it[0] + '</a><span class="site-flag">' + it[1] + '</span></div>';
                }).join(''));
      }

      case 'joined': {
        var browse2 = vals.browse_by_category === 'true';
        var asList2 = vals.show_list === 'true';
        return '<h4>Joined fanlistings</h4>'
          + (browse2
              ? (asList2
                  ? '<ul style="margin:0;padding-left:18px"><li><a class="site-link" href="#">Anime (12)</a></li><li><a class="site-link" href="#">Music (8)</a></li></ul>'
                  : '<select class="fld fld--select"><option>Choose a category...</option><option>Anime (12)</option><option>Music (8)</option></select>')
              : [['Sailor Moon','Anime'],['Cowboy Bebop','Anime'],['Radiohead','Music']].map(function (it) {
                  return '<div class="site-row"><a class="site-link" href="#">' + it[0] + '</a><span class="site-flag">' + it[1] + '</span></div>';
                }).join(''));
      }

      case 'cstats':
        return '<h4>Across the collective</h4>'
          + '<div class="site-stats">'
          + '<div class="site-stat"><span class="k">Owned active</span><span class="v">12</span></div>'
          + '<div class="site-stat"><span class="k">Joined</span><span class="v">48</span></div>'
          + '<div class="site-stat"><span class="k">Members</span><span class="v">3,450</span></div>'
          + '<div class="site-stat"><span class="k">Affiliates</span><span class="v">8</span></div>'
          + '</div>'
          + '<div class="site-note">These stats are for custom PHP pages.</div>';

      case 'random':
      case 'latest':
        return '<h4>' + (key === 'random' ? 'Featured listing' : 'Latest listing') + '</h4>'
          + '<div class="site-row"><a class="site-link" href="#">' + name + '</a><span class="site-flag">Current</span></div>'
          + '<div class="site-note">' + (key === 'random' ? 'A different listing each page load.' : 'Your most recently opened listing.') + '</div>';

      case 'kim':
        return '<h4>Keep me in mind</h4>'
          + '<p class="site-sub">Get notified about new fanlistings as they open.</p>'
          + '<label>Name</label><input class="fld" placeholder="Your name" />'
          + '<label>Email</label><input class="fld" placeholder="you@example.com" />'
          + '<button class="site-btn">Sign up</button>';

      case 'kim-list':
        return '<h4>KIM List</h4>'
          + '<p class="site-sub">People waiting to hear about available fanlistings.</p>'
          + '<div class="site-row"><span>Jane Doe</span><span class="site-flag">Wanted: Paramore</span></div>'
          + '<div class="site-row"><span>Alex Smith</span><span class="site-flag">Wanted: Taylor Swift</span></div>';

      case 'kim-update':
        return '<h4>Update your KIM entry</h4>'
          + '<label>Email</label><input class="fld" placeholder="you@example.com" />'
          + '<label>New name</label><input class="fld" placeholder="Your name" />'
          + '<label>New URL</label><input class="fld" placeholder="https://yoursite.com" />'
          + '<button class="site-btn">Save changes</button>';

      case 'kim-reset':
        return '<h4>Reset KIM password</h4>'
          + '<p class="site-sub">Enter your email to receive a reset link.</p>'
          + '<label>Email</label><input class="fld" placeholder="you@example.com" />'
          + '<button class="site-btn">Send reset link</button>';

      case 'rules-gate':
        return '<h4>Joining Rules</h4>'
          + '<p class="site-sub">Please read and agree to the rules before joining.</p>'
          + '<div style="background:#f5f3fc;border:1px solid #d0c8e8;border-radius:6px;padding:.75rem;font-size:.78rem;color:#6a5f87;margin-bottom:.75rem">No jumping ahead in queues. No sharing passwords. Be respectful.</div>'
          + '<label><input type="checkbox" /> I have read and agree to the joining rules</label>'
          + '<button class="site-btn" style="margin-top:.75rem">Agree &amp; Continue</button>'
          + '<div class="site-note">Once agreed, the fan is shown the join form instead.</div>';

      default:
        return '<h4>' + esc(key) + '</h4>';
    }
  }

  // ── Select embed item ──────────────────────────────────────────────────────
  function selectItem(key) {
    if (!key || !CUST[key]) return;
    currentKey = key;
    custVals   = {};
    var _sec = sectionForKey(key);
    if (_sec) history.replaceState(null, '', location.pathname + location.search + '#' + _sec + '/' + key);
    // Reveal pane content, hide empty state
    var ecEmpty    = document.getElementById('ec-empty');
    var ecPaneHead = document.getElementById('ec-pane-head');
    var ecPaneBody = document.getElementById('ec-pane-body');
    if (ecEmpty)    ecEmpty.style.display    = 'none';
    if (ecPaneHead) ecPaneHead.style.display = '';
    if (ecPaneBody) ecPaneBody.style.display = 'flex';
    // Picker active state (no-op: no .ec-qc-item elements in current layout)
    pickItems.forEach(function (b) { b.classList.toggle('active', b.dataset.ecItem === key); });
    // Head
    var item = items[key] || {};
    if (headIco)   headIco.innerHTML     = item.icon_html_lg || '';
    if (headTitle) headTitle.textContent = item.label || key;
    if (headBadge) {
      headBadge.textContent = item.badge_label || '';
      headBadge.className   = 'badge ' + (item.badge_cls || 'badge-info');
    }
    if (headDesc) headDesc.textContent = item.desc || '';
    // Code file label
    var fname = FILE[key] || key + '.php';
    if (codeFile) codeFile.textContent = 'embed/' + fname;
    // Build options + code (also updates preview button via refreshCode)
    buildOpts(key);
    refreshCode();
  }

  // ── Copy ───────────────────────────────────────────────────────────────────
  if (codeCpBtn) {
    var cpOrig = codeCpBtn.innerHTML;
    codeCpBtn.addEventListener('click', function () {
      if (navigator.clipboard) {
        navigator.clipboard.writeText(currentCode).catch(function () {});
      } else {
        var ta = document.createElement('textarea');
        ta.value = currentCode; ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
      }
      codeCpBtn.textContent = 'Copied!';
      codeCpBtn.classList.add('ec-copied');
      setTimeout(function () { codeCpBtn.innerHTML = cpOrig; codeCpBtn.classList.remove('ec-copied'); }, 1800);
    });
  }

  // ── Setup snippet copy ─────────────────────────────────────────────────────
  var setupCpBtn = document.getElementById('ec-setup-cp');
  if (setupCpBtn) {
    setupCpBtn.addEventListener('click', function () {
      var text = setupCpBtn.dataset.setupText || '';
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).catch(function () {});
      } else {
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
      }
      setupCpBtn.textContent = 'Copied!';
      setTimeout(function () { setupCpBtn.textContent = 'Copy'; }, 1800);
    });
  }

  // ── Template help nav ──────────────────────────────────────────────────────
  var refNavBtns = root.querySelectorAll('.ec-ref-nav-item');
  var refPanels  = root.querySelectorAll('.ec-ref-panel');

  function switchRefPanel(id) {
    refNavBtns.forEach(function (b) { b.classList.toggle('act', b.dataset.ecRef === id); });
    refPanels.forEach(function (p)  { p.classList.toggle('act', p.id === id); });
    if (currentTab === 'ref') {
      history.replaceState(null, '', location.pathname + location.search + '#ref/' + id);
    }
  }

  refNavBtns.forEach(function (btn) {
    btn.addEventListener('click', function () { switchRefPanel(btn.dataset.ecRef); });
  });

  // ── Initialise ─────────────────────────────────────────────────────────────
  if (pickItems.length) selectItem(pickItems[0].dataset.ecItem);
  if (refNavBtns.length) switchRefPanel(refNavBtns[0].dataset.ecRef);

  // Restore tab + selection from URL hash
  var _hash = location.hash.slice(1);
  if (_hash) {
    var _parts = _hash.split('/');
    var _sec   = _parts[0];
    var _key   = _parts[1];
    if (_sec === 'ref' && _key && document.getElementById(_key)) {
      switchTab('ref');
      switchRefPanel(_key);
    } else if (_sec && _key && secSel && embSel && sectionForKey(_key) === _sec) {
      secSel.value = _sec;
      populateEmbSel(_sec);
      embSel.disabled = false;
      embSel.value    = _key;
      selectItem(_key);
    }
  }
}());

// ── 32. Sidebar: collapsible nav groups ───────────────────────────────────────

(function () {
  var toggles = document.querySelectorAll('.nav-group-toggle');
  if (!toggles.length) return;

  toggles.forEach(function (toggle) {
    var group = toggle.dataset.group;
    var key   = 'lstr_navgroup_' + group;
    var panel = document.getElementById('nav-group-' + group);
    if (!panel) return;

    // If PHP forced it open (active page inside group), sync localStorage
    if (toggle.getAttribute('aria-expanded') === 'true') {
      localStorage.setItem(key, '1');
    } else if (localStorage.getItem(key) === '1') {
      // Restore from localStorage (user previously opened it)
      toggle.setAttribute('aria-expanded', 'true');
      panel.classList.add('open');
    }

    toggle.addEventListener('click', function () {
      var expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!expanded));
      panel.classList.toggle('open', !expanded);
      localStorage.setItem(key, !expanded ? '1' : '0');
    });
  });

  // ── Collective template live preview (.js-tpl-preview buttons) ────────────
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-tpl-preview');
    if (!btn) return;

    var target  = btn.dataset.target;
    var kind    = btn.dataset.kind;
    var ta      = document.getElementById(target);
    var preview = document.getElementById('preview-' + target);
    if (!ta || !preview) return;

    if (preview.style.display !== 'none') {
      preview.style.display = 'none';
      btn.textContent = 'Preview';
      return;
    }

    var tplDataEl = document.getElementById('luster-tpl-samples');
    var tplData   = tplDataEl ? JSON.parse(tplDataEl.textContent) : {};
    var samples   = (tplData && tplData[kind]) || {};
    var out = ta.value;
    for (var key in samples) { out = out.split(key).join(samples[key]); }
    preview.innerHTML = out;
    preview.style.display = '';
    btn.textContent = 'Hide preview';
  });

}());

// ── Template reset buttons (.btn-tpl-reset → restore default HTML) ────────────

document.addEventListener('click', function (e) {
  var btn = e.target.closest('.btn-tpl-reset');
  if (!btn) return;
  var defaultsEl = document.getElementById('luster-tpl-defaults');
  if (!defaultsEl) return;
  var defaults = JSON.parse(defaultsEl.textContent);
  var id  = btn.dataset.target;
  var def = defaults[id];
  if (def === undefined || def === null) return;
  var ta = document.getElementById(id);
  if (ta) { ta.value = def; ta.focus(); }
});

// ── Bulk email compose page ───────────────────────────────────────────────────

(function () {
  if (!document.getElementById('compose-form')) return;

  // Load saved template into subject and body fields
  var tplSel = document.getElementById('me-tpl-select');
  if (tplSel) {
    tplSel.addEventListener('change', function () {
      var opt = this.options[this.selectedIndex];
      if (!opt.value) return;
      var subj = document.getElementById('me-subject');
      var body = document.getElementById('me-body');
      if (subj) subj.value = opt.value;
      if (body) body.value = opt.dataset.body || '';
    });
  }

  // Save template: copy current field values into hidden inputs before submit
  var saveForm = document.getElementById('me-save-tpl-form');
  if (saveForm) {
    saveForm.addEventListener('submit', function () {
      var subj = document.getElementById('me-subject');
      var body = document.getElementById('me-body');
      var hs   = document.getElementById('me-save-subject');
      var hb   = document.getElementById('me-save-body');
      if (hs) hs.value = subj ? subj.value : '';
      if (hb) hb.value = body ? body.value : '';
    });
  }

  // Listing chip: update label when Send To selector changes
  var listingSel  = document.getElementById('me-listing');
  var chipLabel   = document.getElementById('me-chip-label');
  if (listingSel && chipLabel) {
    listingSel.addEventListener('change', function () {
      var opt = this.options[this.selectedIndex];
      chipLabel.textContent = opt ? opt.textContent.trim() : 'All Listings';
    });
  }

  // Preview modal
  var meModal         = document.getElementById('me-preview-modal');
  var mePreviewBtn    = document.getElementById('me-preview-btn');
  var meSendBtn       = document.getElementById('me-send-btn');
  var meCloseBtn      = document.getElementById('me-close-preview-btn');
  var meFooterPreview = document.getElementById('me-footer-preview');
  var meFooterConfirm = document.getElementById('me-footer-confirm');
  var meConfirmSend   = document.getElementById('me-confirm-send-btn');
  var meConfirmCancel = document.getElementById('me-confirm-cancel-btn');

  function meCurrListingName() {
    if (!listingSel) return '';
    var opt = listingSel.options[listingSel.selectedIndex];
    return opt ? opt.textContent.trim() : '';
  }

  function meReplaceTokens(text, map) {
    return text.replace(/\{([a-z_]+)\}/g, function (m, key) {
      return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : m;
    });
  }

  function meSetMode(mode) {
    if (!meFooterPreview || !meFooterConfirm) return;
    if (mode === 'confirm') {
      meFooterPreview.style.display = 'none';
      meFooterConfirm.style.display = 'block';
    } else {
      meFooterPreview.style.display = 'flex';
      meFooterConfirm.style.display = 'none';
    }
  }

  function meOpenPreview(mode) {
    if (!meModal) return;
    var subj = document.getElementById('me-subject');
    var body = document.getElementById('me-body');
    var md   = meModal.dataset;

    // Selected listing info
    var selOpt     = listingSel ? listingSel.options[listingSel.selectedIndex] : null;
    var toLabel    = selOpt ? (selOpt.dataset.label || selOpt.textContent.trim()) : '';
    var toCount    = selOpt ? parseInt(selOpt.dataset.count || '0', 10) : 0;
    var listName   = meCurrListingName();

    var selSubject = selOpt ? (selOpt.dataset.subject || '') : '';
    var selUrl     = selOpt ? (selOpt.dataset.url     || '') : '';

    var tokens = {
      fan_name:        'Fan Name',
      fan_email:       'fan@example.com',
      fan_url:         'https://example.com',
      fan_country:     'United States',
      name:            'Fan Name',
      email:           'fan@example.com',
      listing_name:    listName,
      listing_title:   listName,
      listing_subject: selSubject,
      listing_url:     selUrl,
      site_name:       md.siteName  || '',
      owner_name:      md.fromName  || '',
      owner_email:     md.fromEmail || '',
    };

    var fromName  = md.fromName  || '';
    var fromEmail = md.fromEmail || '';

    // To row
    var toL = document.getElementById('me-preview-to-label');
    var toC = document.getElementById('me-preview-to-count');
    if (toL) toL.textContent = toLabel;
    if (toC) toC.textContent = toCount + ' recipient' + (toCount !== 1 ? 's' : '');

    // From row
    var fn = document.getElementById('me-preview-from-name');
    var fe = document.getElementById('me-preview-from-email');
    if (fn) fn.textContent = fromName;
    if (fe) fe.textContent = fromEmail ? '<' + fromEmail + '>' : '';

    // Subject + body
    var ps = document.getElementById('me-preview-subject');
    var pb = document.getElementById('me-preview-body');
    if (ps) ps.textContent = meReplaceTokens(subj ? subj.value : '', tokens);
    if (pb) pb.textContent = meReplaceTokens(body ? body.value : '', tokens);

    meSetMode(mode === 'confirm' ? 'confirm' : 'preview');
    meModal.style.display = 'flex';
  }

  function meClosePreview() {
    if (meModal) meModal.style.display = 'none';
    meSetMode('preview');
  }

  if (mePreviewBtn) mePreviewBtn.addEventListener('click', function () { meOpenPreview('preview'); });
  if (meSendBtn)    meSendBtn.addEventListener('click',    function () { meOpenPreview('confirm'); });
  if (meCloseBtn)   meCloseBtn.addEventListener('click', meClosePreview);
  if (meConfirmCancel) meConfirmCancel.addEventListener('click', meClosePreview);
  if (meConfirmSend) meConfirmSend.addEventListener('click', function () {
    var form = document.getElementById('compose-form');
    if (form) form.submit();
  });
  if (meModal) meModal.addEventListener('click', function (e) { if (e.target === meModal) meClosePreview(); });

  // Char counter
  var meBody    = document.getElementById('me-body');
  var meCharOut = document.getElementById('me-char-count');
  function updateMeCharCount() {
    if (!meBody || !meCharOut) return;
    var n = meBody.value.length;
    meCharOut.textContent = n + ' char' + (n !== 1 ? 's' : '');
  }
  if (meBody) { meBody.addEventListener('input', updateMeCharCount); updateMeCharCount(); }
}());

// ── Unsaved-changes beforeunload guard (.js-warn-unsaved) ──────────────────
//
// Forms with class js-warn-unsaved trigger a browser "Leave page?" prompt if
// the user navigates away before submitting. The guard is cleared on submit so
// saving always works without the prompt.

(function () {
  var guards = [];

  function snapshotForm(form) {
    var parts = [];
    var els   = form.elements;
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (!el.name || el.disabled) continue;
      if (el.type === 'submit' || el.type === 'button' || el.type === 'hidden') continue;
      // Browsers/password managers can populate password fields asynchronously
      // after this snapshot is taken, producing a false "unsaved changes" prompt.
      if (el.type === 'password') continue;
      if (el.type === 'checkbox' || el.type === 'radio') {
        parts.push(el.name + ':' + (el.checked ? '1' : '0'));
      } else {
        parts.push(el.name + ':' + el.value);
      }
    }
    return parts.join('|');
  }

  document.querySelectorAll('form.js-warn-unsaved').forEach(function (form) {
    var initial   = snapshotForm(form);
    var submitted = false;
    form.addEventListener('submit', function () { submitted = true; });
    guards.push(function () { return submitted || snapshotForm(form) === initial; });
  });

  if (!guards.length) return;

  window.addEventListener('beforeunload', function (e) {
    var allClean = guards.every(function (g) { return g(); });
    if (!allClean) {
      e.preventDefault();
      e.returnValue = '';
    }
  });
}());

// ── Codes drop-zone feedback ──────────────────────────────────────────────────
// Updates the label when files are selected. Runs on both the standalone codes
// page and the embedded Codes tab in edit.php.

(function () {
  var input = document.getElementById('codes-input');
  if (!input) return;
  var drop  = input.closest('.codes-drop');
  var label = drop ? drop.querySelector('.codes-drop-label') : null;
  var hint  = drop ? drop.querySelector('.codes-drop-hint')  : null;
  if (!label) return;

  var defaultLabel = label.textContent;
  var defaultHint  = hint ? hint.textContent : '';

  input.addEventListener('change', function () {
    var n = input.files ? input.files.length : 0;
    if (n === 0) {
      label.textContent = defaultLabel;
      if (hint) hint.textContent = defaultHint;
    } else {
      label.textContent = n === 1 ? input.files[0].name : n + ' files selected';
      if (hint) hint.textContent = 'Ready to upload -- click Upload below';
    }
  });
}());

// ── Codes management page ─────────────────────────────────────────────────────
// Guards on .code-card so this block only activates on the codes admin page.

(function () {
  function initCodesManager() {
    if (!document.querySelector('.codes-browser')) return;

  // ── Select-all checkbox ──────────────────────────────────────────────────
  var root          = document.querySelector('.codes-browser');
  var scope         = root || document;
  var filterEmpty   = scope.querySelector('#codes-filter-empty');
  var pendingBanner = scope.querySelector('[data-codes-pending-banner]');
  var pendingTitle  = scope.querySelector('[data-codes-pending-title]');
  var pendingMsg    = scope.querySelector('[data-codes-pending-msg]');
  var pendingAction = scope.querySelector('[data-codes-pending-action]');
  var filterLinks   = Array.from(scope.querySelectorAll('[data-code-filter]'));
  var groups        = Array.from(scope.querySelectorAll('[data-code-group]'));
  var _initFilter   = root ? (root.getAttribute('data-initial-code-filter') || '').trim() : '';
  var _knownFilters = ['pending', 'approved', 'rejected'];
  var activeFilter  = _knownFilters.indexOf(_initFilter) >= 0 ? _initFilter : '';
  var selAll        = document.getElementById('codes-select-all');
  // When the codes browser is on its own page (/admin/codes?listing_id=X),
  // data-codes-base-url carries the canonical base URL for filter links and
  // history.replaceState updates. Without it, the edit-tab default applies.
  var codesBaseUrl  = root && root.getAttribute('data-codes-base-url') || null;

  function getVisibleChecks() {
    return Array.from(scope.querySelectorAll('.code-check')).filter(function (cb) {
      var card = cb.closest('.code-card');
      return card && !card.classList.contains('is-hidden');
    });
  }

  function syncSelectAllState() {
    if (!selAll) return;
    var visible = getVisibleChecks();
    var checked = visible.filter(function (cb) { return cb.checked; });
    selAll.checked = visible.length > 0 && checked.length === visible.length;
    selAll.indeterminate = checked.length > 0 && checked.length < visible.length;
  }

  function updateBulkActions(filter) {
    if (!bulkSel) return;
    var saved = bulkSel.value;
    var hasCatsOpt = !!document.getElementById('bulk-cats-wrap');
    while (bulkSel.options.length > 1) { bulkSel.remove(1); }
    function addOpt(val, label) {
      var opt = document.createElement('option');
      opt.value = val; opt.textContent = label;
      bulkSel.appendChild(opt);
    }
    if (filter === 'approved') {
      addOpt('reject', 'Reject');
      addOpt('delete', 'Delete');
    } else if (filter === 'pending') {
      addOpt('approve', 'Approve');
      addOpt('reject', 'Reject');
      addOpt('delete', 'Delete');
    } else if (filter === 'rejected') {
      addOpt('approve', 'Approve');
      addOpt('delete', 'Delete');
    } else {
      addOpt('delete', 'Delete');
      addOpt('approve', 'Approve');
      addOpt('reject', 'Reject');
    }
    if (hasCatsOpt) { addOpt('categorize', 'Add to Categories…'); }
    if (Array.from(bulkSel.options).some(function (o) { return o.value === saved; })) {
      bulkSel.value = saved;
    }
    var catsWrapEl = document.getElementById('bulk-cats-wrap');
    if (catsWrapEl && bulkSel.value !== 'categorize') { catsWrapEl.style.display = 'none'; }
  }

  function applyCodeFilter(nextFilter, updateUrl) {
    var _known = ['pending', 'approved', 'rejected'];
    activeFilter = _known.indexOf(nextFilter) >= 0 ? nextFilter : 'approved';

    filterLinks.forEach(function (link) {
      if (!link.classList.contains('tabs-btn')) return;
      link.classList.toggle('active', (link.getAttribute('data-code-filter') || '').trim() === activeFilter);
    });

    var totalVisible = 0;
    groups.forEach(function (group) {
      var visibleCount = 0;
      group.querySelectorAll('.code-card').forEach(function (card) {
        var show = activeFilter === '' || card.getAttribute('data-code-status') === activeFilter;
        card.classList.toggle('is-hidden', !show);
        if (!show) {
          var cb = card.querySelector('.code-check');
          if (cb) cb.checked = false;
        } else {
          visibleCount += 1;
        }
      });

      group.classList.toggle('is-hidden', visibleCount === 0);
      totalVisible += visibleCount;

      var countEl = group.querySelector('[data-code-group-count]');
      if (countEl) {
        countEl.textContent = visibleCount + ' code' + (visibleCount !== 1 ? 's' : '');
      }
    });

    if (filterEmpty) {
      filterEmpty.classList.toggle('is-hidden', totalVisible > 0);
    }
    if (pendingBanner && pendingAction) {
      if (codesBaseUrl !== null) {
        // Standalone codes page: use the listing-scoped base URL
        if (activeFilter === 'pending') {
          if (pendingTitle) pendingTitle.textContent = 'Showing pending codes';
          if (pendingMsg) pendingMsg.textContent = 'Only pending codes are visible right now.';
          pendingAction.textContent = '\u2190 Show approved';
          pendingAction.setAttribute('data-code-filter', 'approved');
          pendingAction.setAttribute('href', codesBaseUrl + '&tab=approved');
        } else {
          if (pendingTitle) pendingTitle.textContent = 'Codes awaiting review';
          if (pendingMsg) pendingMsg.textContent = 'Review pending codes without leaving this page.';
          pendingAction.textContent = 'Review pending \u2192';
          pendingAction.setAttribute('data-code-filter', 'pending');
          pendingAction.setAttribute('href', codesBaseUrl + '&tab=pending');
        }
      } else {
        // Listing edit tab: use the edit-tab URL format
        if (activeFilter === 'pending') {
          if (pendingTitle) pendingTitle.textContent = 'Showing pending codes';
          if (pendingMsg) pendingMsg.textContent = 'Only pending codes are visible right now.';
          pendingAction.textContent = '\u2190 Show approved';
          pendingAction.setAttribute('data-code-filter', 'approved');
          pendingAction.setAttribute('href', '?tab=codes&status_filter=approved');
        } else {
          if (pendingTitle) pendingTitle.textContent = 'Codes awaiting review';
          if (pendingMsg) pendingMsg.textContent = 'Review pending codes without leaving this page.';
          pendingAction.textContent = 'Review pending \u2192';
          pendingAction.setAttribute('data-code-filter', 'pending');
          pendingAction.setAttribute('href', '?tab=codes&status_filter=pending');
        }
      }
    }

    syncSelectAllState();
    updateBulkActions(activeFilter);

    if (updateUrl !== false && window.history && history.replaceState) {
      if (codesBaseUrl !== null) {
        // Standalone codes page: update URL using base URL + tab param
        var url = new URL(window.location.origin + codesBaseUrl);
        url.searchParams.set('tab', activeFilter);
        history.replaceState(null, '', url.toString());
      } else {
        // Listing edit tab: update the status_filter param
        var url = new URL(window.location.href);
        url.searchParams.set('tab', 'codes');
        url.searchParams.set('status_filter', activeFilter);
        history.replaceState(null, '', url.toString());
      }
    }
  }

  if (selAll) {
    selAll.addEventListener('change', function () {
      getVisibleChecks().forEach(function (cb) {
        cb.checked = selAll.checked;
      });
      syncSelectAllState();
    });
  }

  scope.querySelectorAll('.code-check').forEach(function (cb) {
    cb.addEventListener('change', syncSelectAllState);
  });

  filterLinks.forEach(function (link) {
    link.addEventListener('click', function (e) {
      if (link.tagName === 'A') e.preventDefault();
      applyCodeFilter(link.getAttribute('data-code-filter') || 'approved', true);
    });
  });

  applyCodeFilter(activeFilter, false);

  // ── Bulk action toolbar ──────────────────────────────────────────────────
  var bulkSel    = document.getElementById('bulk-action-sel');
  var catsWrap   = document.getElementById('bulk-cats-wrap');
  var catsSel    = document.getElementById('bulk-cats-sel');
  var applyBtn   = document.getElementById('bulk-apply-btn');
  var saveOrdBtn = document.getElementById('save-order-btn');
  var bulkForm   = document.getElementById('codes-bulk-form');

  // Initialize bulk actions now that bulkSel is available
  updateBulkActions(activeFilter);

  if (bulkSel && catsWrap) {
    bulkSel.addEventListener('change', function () {
      catsWrap.style.display = bulkSel.value === 'categorize' ? '' : 'none';
    });
  }

  if (applyBtn && bulkForm) {
    applyBtn.addEventListener('click', function () {
      var action  = bulkSel ? bulkSel.value : 'delete';
      var checked = document.querySelectorAll('.code-check:checked');

      if (checked.length === 0) {
        window.lstrToast && window.lstrToast('warning', 'No codes selected.');
        return;
      }

      function doSubmit() {
        bulkForm.querySelectorAll('.js-bulk-inject').forEach(function (el) { el.remove(); });

        function addHidden(name, val) {
          var inp = document.createElement('input');
          inp.type = 'hidden'; inp.name = name; inp.value = val;
          inp.className = 'js-bulk-inject';
          bulkForm.appendChild(inp);
        }

        addHidden('bulk_action', action);
        if (action === 'categorize' && catsSel) {
          Array.from(catsSel.selectedOptions).forEach(function (opt) {
            addHidden('bulk_cat_ids[]', opt.value);
          });
        }

        bulkForm.submit();
      }

      if (action === 'delete') {
        var delNames = Array.from(checked).map(function (cb) {
          return cb.closest('.code-card') ? cb.closest('.code-card').getAttribute('data-code-id') || '' : '';
        }).filter(Boolean).join(', ');
        if (window.lstrDelModal) {
          window.lstrDelModal.trigger(doSubmit, 'code', checked.length + ' code(s)', '', 'This will permanently delete the selected codes. This action cannot be undone.');
        } else {
          doSubmit();
        }
        return;
      }

      doSubmit();
    });
  }

  // ── Drag-and-drop reorder ────────────────────────────────────────────────
  // draggable="true" is on .code-drag-handle spans (set below via JS so that
  // browsers won't try to drag the whole card when clicking other controls).
  // dragstart/dragend fire on the handle; dragover/dragleave/drop on the card.
  var reorderForm = document.getElementById('codes-reorder-form');
  var reorderIds  = document.getElementById('reorder-ids');

  if (reorderForm && reorderIds && saveOrdBtn) {
    var allCards   = Array.from(document.querySelectorAll('.code-card'));
    var allHandles = Array.from(document.querySelectorAll('.code-drag-handle'));

    if (allCards.length > 1) {
      saveOrdBtn.style.display = '';

      var dragSrc = null;

      allHandles.forEach(function (handle) {
        handle.setAttribute('draggable', 'true');

        handle.addEventListener('dragstart', function (e) {
          dragSrc = handle.closest('.code-card');
          if (!dragSrc) { e.preventDefault(); return; }
          dragSrc.classList.add('dnd-dragging');
          e.dataTransfer.effectAllowed = 'move';
          // Firefox requires at least one setData call for drag to proceed
          e.dataTransfer.setData('text/plain', dragSrc.dataset.codeId || '');
        });

        handle.addEventListener('dragend', function () {
          if (dragSrc) { dragSrc.classList.remove('dnd-dragging'); }
          document.querySelectorAll('.code-card').forEach(function (c) {
            c.classList.remove('dnd-over');
          });
          dragSrc = null;
        });
      });

      allCards.forEach(function (card) {
        card.addEventListener('dragover', function (e) {
          if (!dragSrc || dragSrc === card) return;
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';
          card.classList.add('dnd-over');
        });

        card.addEventListener('dragleave', function (e) {
          // Only clear highlight if leaving the card entirely (not entering a child)
          if (!card.contains(e.relatedTarget)) {
            card.classList.remove('dnd-over');
          }
        });

        card.addEventListener('drop', function (e) {
          e.preventDefault();
          card.classList.remove('dnd-over');
          if (!dragSrc || dragSrc === card) return;
          var parent = card.parentNode;
          var nodes  = Array.from(parent.children);
          var srcIdx = nodes.indexOf(dragSrc);
          var tgtIdx = nodes.indexOf(card);
          if (srcIdx < tgtIdx) {
            parent.insertBefore(dragSrc, card.nextSibling);
          } else {
            parent.insertBefore(dragSrc, card);
          }
        });
      });

      saveOrdBtn.addEventListener('click', function () {
        var ordered = Array.from(document.querySelectorAll('.code-card')).map(function (c) {
          return parseInt(c.getAttribute('data-code-id'), 10);
        }).filter(function (id) { return id > 0; });
        reorderIds.value = JSON.stringify(ordered);
        reorderForm.submit();
      });
    }
  }

  // ── Upload panel: inline "Add new donor" toggle ─────────────────────────
  var addDonorToggle  = document.getElementById('upload-add-donor-toggle');
  var newDonorWrap    = document.getElementById('upload-new-donor-wrap');
  var uploadDonorSel  = document.getElementById('upload-donor');

  if (addDonorToggle && newDonorWrap) {
    addDonorToggle.addEventListener('click', function (e) {
      e.preventDefault();
      var isHidden = newDonorWrap.classList.contains('is-hidden');
      newDonorWrap.classList.toggle('is-hidden', !isHidden);
      if (isHidden) {
        if (uploadDonorSel) uploadDonorSel.value = '';
        var firstField = newDonorWrap.querySelector('input[type="text"]');
        if (firstField) firstField.focus();
      } else {
        newDonorWrap.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
      }
    });

    if (uploadDonorSel) {
      uploadDonorSel.addEventListener('change', function () {
        if (uploadDonorSel.value !== '') {
          newDonorWrap.classList.add('is-hidden');
          newDonorWrap.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
        }
      });
    }

    // Collapse the new-donor fields when the form is reset (Clear Form button)
    var uploadForm = newDonorWrap.closest('form');
    if (uploadForm) {
      uploadForm.addEventListener('reset', function () {
        newDonorWrap.classList.add('is-hidden');
      });
    }
  }

  // ── Code edit dialogs ────────────────────────────────────────────────────
  document.querySelectorAll('.code-edit-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var dlg = document.getElementById(btn.getAttribute('data-dialog'));
      if (dlg && typeof dlg.showModal === 'function') { dlg.showModal(); }
    });
  });

  document.querySelectorAll('.code-edit-dialog').forEach(function (dlg) {
    dlg.querySelectorAll('[data-close-dialog]').forEach(function (closeBtn) {
      closeBtn.addEventListener('click', function () { dlg.close(); });
    });
    // Click directly on the backdrop (event target is the dialog itself)
    dlg.addEventListener('click', function (e) {
      if (e.target === dlg) { dlg.close(); }
    });
    // Delete button: close the native dialog first so the confirmation modal
    // can appear in front (native dialog top-layer blocks regular div modals).
    var delBtn = dlg.querySelector('.code-dialog-delete-btn');
    if (delBtn) {
      delBtn.addEventListener('click', function () {
        var deleteForm = dlg.querySelector('.code-delete-form');
        if (!deleteForm) return;
        dlg.close();
        if (window.lstrDelModal) {
          window.lstrDelModal.trigger(function () {
            deleteForm.dataset.delConfirmed = '1';
            deleteForm.requestSubmit ? deleteForm.requestSubmit() : deleteForm.submit();
          }, 'code', deleteForm.dataset.delName || '', '', '');
        } else {
          deleteForm.requestSubmit ? deleteForm.requestSubmit() : deleteForm.submit();
        }
      });
    }
  });

  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCodesManager, { once: true });
  } else {
    initCodesManager();
  }
}());

// ── 33. Listings index: filter panel toggle ───────────────────────────────────

function adminFilterStorageKey(panel) {
  return 'fp:' + window.location.pathname + ':' + panel.id;
}

function adminReadFilterCookie() {
  var match = document.cookie.match(/(?:^|; )lstr_fp=([^;]*)/);
  if (!match) return {};
  try {
    var parsed = JSON.parse(decodeURIComponent(match[1]));
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch (e) {
    return {};
  }
}

function adminWriteFilterCookie(map) {
  document.cookie = 'lstr_fp=' + encodeURIComponent(JSON.stringify(map)) + '; path=/; SameSite=Lax';
}

function adminGetFilterState(panel) {
  var key = adminFilterStorageKey(panel);
  var map = adminReadFilterCookie();
  if (Object.prototype.hasOwnProperty.call(map, key)) {
    return map[key];
  }

  try {
    return sessionStorage.getItem(key);
  } catch (e) {
    return null;
  }
}

function adminSetFilterState(panel, open) {
  var key   = adminFilterStorageKey(panel);
  var value = open ? '1' : '0';

  try {
    sessionStorage.setItem(key, value);
  } catch (e) {}

  var map = adminReadFilterCookie();
  map[key] = value;
  adminWriteFilterCookie(map);
}

function adminApplyFilterState(btn, panel, htmlClass, open) {
  document.documentElement.classList.toggle(htmlClass, open);
  panel.classList.toggle('is-open', open);
  btn.setAttribute('aria-expanded', String(open));
}

function adminWireFilterPanel(btnId, panelId, htmlClass) {
  var btn   = document.getElementById(btnId);
  var panel = document.getElementById(panelId);
  if (!btn || !panel) return;

  var stored = adminGetFilterState(panel);
  var open   = stored === null ? true : stored === '1';
  adminApplyFilterState(btn, panel, htmlClass, open);

  btn.addEventListener('click', function () {
    open = !document.documentElement.classList.contains(htmlClass);
    adminApplyFilterState(btn, panel, htmlClass, open);
    adminSetFilterState(panel, open);
  });
}

(function () {
  adminWireFilterPanel('fl-filter-toggle', 'filter-panel', 'fp-fl');
}());

// ── 34. Members index: filter panel toggle + auto-submit selects ──────────────

(function () {
  adminWireFilterPanel('mem-filter-toggle', 'mem-filter-panel', 'fp-mem');
}());

(function () {
  adminWireFilterPanel('lc-filter-toggle', 'lc-filter-panel', 'fp-lc');
}());

(function () {
  adminWireFilterPanel('codes-filter-toggle', 'codes-filter-panel', 'fp-codes');

  ['codes-listing-sel', 'codes-size-sel', 'codes-donor-sel'].forEach(function (id) {
    var sel = document.getElementById(id);
    if (sel) {
      sel.addEventListener('change', function () {
        var form = document.getElementById('codes-filter-form');
        if (form) form.submit();
      });
    }
  });
}());

(function () {
  var form       = document.getElementById('lc-filter-form');
  var listingSel = document.getElementById('lc-listing-sel');
  var statusSel  = document.getElementById('lc-status-sel');
  if (!form) return;
  if (listingSel) listingSel.addEventListener('change', function () { form.submit(); });
  if (statusSel)  statusSel.addEventListener('change',  function () { form.submit(); });
}());

(function () {
  adminWireFilterPanel('wl-filter-toggle', 'wl-filter-panel', 'fp-wl');
}());

(function () {
  var sel  = document.getElementById('wl-category-sel');
  var form = document.getElementById('wl-filter-row');
  if (!sel || !form) return;
  sel.addEventListener('change', function () { form.submit(); });
}());

(function () {
  adminWireFilterPanel('kim-filter-toggle', 'kim-filter-panel', 'fp-kim');
}());

(function () {
  adminWireFilterPanel('adopt-filter-toggle', 'adopt-filter-panel', 'fp-adopt');
}());

(function () {
  adminWireFilterPanel('aff-filter-toggle', 'aff-filter-panel', 'fp-aff');
}());

(function () {
  var sel  = document.getElementById('aff-scope-sel');
  var form = document.getElementById('aff-filter-form');
  if (!sel || !form) return;
  sel.addEventListener('change', function () { form.submit(); });
}());

(function () {
  var sel  = document.getElementById('kim-listing-sel');
  var form = document.getElementById('kim-filter-form');
  if (!sel || !form) return;
  sel.addEventListener('change', function () { form.submit(); });
}());

(function () {
  var sel  = document.getElementById('adopt-listing-sel');
  var form = document.getElementById('adopt-filter-form');
  if (!sel || !form) return;
  sel.addEventListener('change', function () { form.submit(); });
}());

(function () {
  var form       = document.getElementById('mem-filter-form');
  var listingSel = document.getElementById('mem-listing-sel');
  if (!form || !listingSel) return;
  listingSel.addEventListener('change', function () { form.submit(); });
}());

// Cross-listing pending table: select-all checkbox
(function () {
  var master = document.getElementById('pending-check-all');
  if (!master) return;
  master.addEventListener('change', function () {
    document.querySelectorAll('input[name="items[]"]').forEach(function (cb) {
      cb.checked = master.checked;
    });
  });
}());

// ── 35. Affiliate drag-and-drop row reorder ───────────────────────────────────
//
// Requires: <table data-reorder-url="..."> with <tr data-id="N"> rows
// and a .drag-handle element inside each row. Sends ordered IDs via AJAX.

(function () {
  var table = document.querySelector('table[data-reorder-url]');
  if (!table) return;

  var reorderUrl = table.dataset.reorderUrl;
  var tbody      = table.querySelector('tbody');
  var dragged    = null;

  tbody.addEventListener('dragstart', function (e) {
    var row = e.target.closest('tr[data-id]');
    if (!row) return;
    dragged = row;
    row.classList.add('dnd-dragging');
    e.dataTransfer.effectAllowed = 'move';
  });

  tbody.addEventListener('dragover', function (e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    var row = e.target.closest('tr[data-id]');
    if (!row || row === dragged) return;
    tbody.querySelectorAll('tr').forEach(function (r) { r.classList.remove('dnd-over'); });
    row.classList.add('dnd-over');
    var rect = row.getBoundingClientRect();
    if (e.clientY < rect.top + rect.height / 2) {
      tbody.insertBefore(dragged, row);
    } else {
      tbody.insertBefore(dragged, row.nextSibling);
    }
  });

  tbody.addEventListener('drop', function (e) {
    e.preventDefault();
  });

  tbody.addEventListener('dragend', function () {
    if (dragged) dragged.classList.remove('dnd-dragging');
    tbody.querySelectorAll('tr').forEach(function (r) { r.classList.remove('dnd-over'); });
    dragged = null;

    // Update visible sort numbers and save via AJAX
    var ids = [];
    tbody.querySelectorAll('tr[data-id]').forEach(function (r) { ids.push(parseInt(r.dataset.id, 10)); });

    tbody.querySelectorAll('td[data-label="Sort #"]').forEach(function (cell, i) {
      cell.textContent = i + 1;
    });

    var params = new URLSearchParams();
    var csrfInput = document.querySelector('input[name="_csrf"]');
    if (csrfInput) params.append('_csrf', csrfInput.value);
    ids.forEach(function (id) { params.append('ids[]', id); });

    var xhr = new XMLHttpRequest();
    xhr.open('POST', reorderUrl, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.send(params.toString());
  });
}());

// ── 35. Email template type left-nav (.sidebar-nav-item / .et-panel) ──────
//
// Standalone switcher so it does not conflict with the .tabs-btn switcher
// (which would clear the active sub-tab when a sidebar item is clicked).

(function () {
  var items = document.querySelectorAll('.mini-nav-item[data-et-tab]');
  if (!items.length) return;

  function activate(tabId) {
    items.forEach(function (el) { el.classList.remove('active'); });
    document.querySelectorAll('.et-panel').forEach(function (el) { el.classList.remove('active'); });
    var item  = document.querySelector('.mini-nav-item[data-et-tab="' + tabId + '"]');
    var panel = document.getElementById('et-tab-' + tabId);
    if (item)  item.classList.add('active');
    if (panel) panel.classList.add('active');
  }

  items.forEach(function (item) {
    item.addEventListener('click', function () { activate(item.dataset.etTab); });
  });
}());

// ── 36. Add-on uninstall confirmation modal ───────────────────────────────────

(function () {
  var modal = document.getElementById('addon-uninstall-modal');
  if (!modal) return;

  var modalName  = document.getElementById('addon-modal-name');
  var confirmBtn = document.getElementById('addon-modal-confirm');
  var cancelBtn  = document.getElementById('addon-modal-cancel');
  var pendingForm = null;

  document.querySelectorAll('.js-uninstall-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form = btn.closest('.js-uninstall-form');
      pendingForm = form;
      modalName.textContent = form.dataset.addonName || 'this add-on';
      modal.style.display = 'flex';
    });
  });

  function closeModal() {
    modal.style.display = 'none';
    pendingForm = null;
  }

  confirmBtn.addEventListener('click', function () {
    if (pendingForm) pendingForm.submit();
  });

  cancelBtn.addEventListener('click', closeModal);

  modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') closeModal();
  });
}());

// ── 37. Flush activity-log confirmation modal ──────────────────────────────────

(function () {
  var modal      = document.getElementById('flush-activity-modal');
  var openBtn    = document.getElementById('flush-activity-btn');
  if (!modal || !openBtn) return;

  var confirmBtn = document.getElementById('flush-activity-confirm');
  var cancelBtn  = document.getElementById('flush-activity-cancel');
  var form       = document.getElementById('flush-activity-form');

  function openModal()  { modal.style.display = 'flex'; }
  function closeModal() { modal.style.display = 'none'; }

  openBtn.addEventListener('click', openModal);
  cancelBtn.addEventListener('click', closeModal);
  confirmBtn.addEventListener('click', function () { form.submit(); });

  modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') closeModal();
  });
}());

// ── 38. Listings add/edit: opened-on date ↔ TBA toggle ───────────────────────

(function () {
  var openedInput = document.getElementById('fl-opened');
  var openedTba   = document.getElementById('fl-opened-tba');
  var openedText  = document.getElementById('fl-opened-tba-text');
  if (!openedInput) return;

  var radios = document.querySelectorAll('input[name="status"]');
  if (!radios.length) return;

  function getStatusVal() {
    for (var i = 0; i < radios.length; i++) {
      if (radios[i].checked) return radios[i].value;
    }
    return '';
  }

  function isTba(val) {
    // Add listing uses numeric strings: '0'=pending, '1'=upcoming, '2'=current
    // Edit listing uses: 'pending', 'upcoming', 'current'
    return val === '0' || val === '1' || val === 'upcoming' || val === 'pending';
  }

  function setTbaState(tba) {
    if (tba) {
      openedInput.style.display = 'none';
      openedInput.disabled      = true;
      if (openedTba)  { openedTba.disabled = false; }
      if (openedText) { openedText.style.display = ''; }
    } else {
      openedInput.style.display = '';
      openedInput.disabled      = false;
      if (openedTba)  { openedTba.disabled = true; }
      if (openedText) { openedText.style.display = 'none'; }
    }
  }

  radios.forEach(function (r) {
    r.addEventListener('change', function () { setTbaState(isTba(this.value)); });
  });

  setTbaState(isTba(getStatusVal()));
}());

// ── 39. Global delete / destructive-action confirmation modal ────────────────────
//
// Single-item forms: add data-del-modal to the <form>, plus:
//   data-del-entity  -- key into the CFG map below
//   data-del-name    -- display name of the item being deleted
//   data-del-context -- (optional) secondary label (e.g. listing name for member)
//   data-del-warning -- (optional) override the default warning text
//
// Bulk forms: add data-del-bulk to the <form>, plus data-del-entity (and
//   optionally data-del-context). Add data-name="..." to each checkbox.
//   Fires when bulk_action/action = "delete" or "reject".

(function () {
  var modal      = document.getElementById('del-confirm-modal');
  if (!modal) return;

  var titleEl    = document.getElementById('del-modal-title');
  var descEl     = document.getElementById('del-modal-desc');
  var warnEl     = document.getElementById('del-modal-warning-text');
  var confirmBtn = document.getElementById('del-modal-confirm');
  var cancelBtn  = document.getElementById('del-modal-cancel');
  var pending    = null;

  var CFG = {
    listing:               { title: 'Delete Listing',          btn: 'Delete',      desc: 'You are about to delete <strong>$$names$$</strong>.',                                   warn: 'This will permanently delete the listing and all its member data. This action cannot be undone.' },
    member:                { title: 'Delete Member',           btn: 'Delete',      desc: 'You are about to delete <strong>$$names$$</strong> from <strong>$$context$$</strong>.', warn: 'This will permanently delete the member and all their data. This action cannot be undone.' },
    member_reject:         { title: 'Reject Member',           btn: 'Reject',      desc: 'You are about to reject <strong>$$names$$</strong>.',                                   warn: 'The member will be removed from the pending queue. This action cannot be undone.' },
    affiliate:             { title: 'Delete Affiliate',        btn: 'Delete',      desc: 'You are about to delete affiliate <strong>$$names$$</strong>.',                        warn: 'This action cannot be undone.' },
    category:              { title: 'Delete Category',         btn: 'Delete',      desc: 'You are about to delete category <strong>$$names$$</strong>.',                         warn: 'This action cannot be undone.' },
    joined:                { title: 'Delete Joined Listing',   btn: 'Delete',      desc: 'You are about to delete <strong>$$names$$</strong> from your joined listings.',        warn: 'This action cannot be undone.' },
    email_template:        { title: 'Delete Template',         btn: 'Delete',      desc: 'You are about to delete template <strong>$$names$$</strong>.',                        warn: 'This action cannot be undone.' },
    admin_user:            { title: 'Delete Admin Account',    btn: 'Delete',      desc: 'You are about to delete admin account <strong>$$names$$</strong>.',                   warn: 'This will permanently remove admin access for this user. This action cannot be undone.' },
    blacklist:             { title: 'Remove Blacklist Entry',  btn: 'Remove',      desc: 'You are about to remove <strong>$$names$$</strong> from the blacklist.',              warn: 'This action cannot be undone.' },
    field:                 { title: 'Drop Field Column',       btn: 'Drop Column', desc: 'You are about to drop the column for field <strong>$$names$$</strong>.',             warn: 'This permanently removes all member data for this field and cannot be undone.' },
    code:                  { title: 'Delete Code',             btn: 'Delete',      desc: 'You are about to delete code <strong>$$names$$</strong>.',                           warn: 'This action cannot be undone.' },
    code_category:         { title: 'Delete Code Category',    btn: 'Delete',      desc: 'You are about to delete code category <strong>$$names$$</strong>. Assignments will be removed.', warn: 'This action cannot be undone.' },
    code_donor:            { title: 'Delete Code Donor',       btn: 'Delete',      desc: 'You are about to delete donor <strong>$$names$$</strong>. Their codes will be unattributed.', warn: 'This action cannot be undone.' },
    kim_member:            { title: 'Delete KIM Entry',        btn: 'Delete',      desc: 'You are about to delete the KIM entry for <strong>$$names$$</strong>.',              warn: 'This action cannot be undone.' },
    pending_update_reject: { title: 'Reject Update',           btn: 'Reject',      desc: 'You are about to reject the update for <strong>$$names$$</strong>.',                 warn: 'The pending update will be discarded. This action cannot be undone.' },
    merge_member:          { title: 'Delete Duplicate Member', btn: 'Delete',      desc: 'You are about to delete the duplicate account for <strong>$$names$$</strong>.',      warn: 'This permanently deletes the duplicate account. This action cannot be undone.' },
    rename_prefix:         { title: 'Rename Member Table',     btn: 'Rename Table', desc: 'You are about to rename the member table from <strong>$$names$$</strong> to <strong>$$context$$</strong>.', warn: 'This renames the table directly in your database. If anything goes wrong, member data may become unreachable. This action cannot be undone.' },
    update:                { title: 'Delete Update',           btn: 'Delete',      desc: 'You are about to delete the update <strong>$$names$$</strong>.',                       warn: 'This action cannot be undone.' },
    rules:                 { title: 'Delete Rules',            btn: 'Delete',      desc: 'You are about to delete the join rules for <strong>$$names$$</strong>.',               warn: 'Deleting removes the rules text (and rules gate, if enabled) entirely for this listing. This action cannot be undone.' },
    wishlist_entry:        { title: 'Delete Wishlist Entries', btn: 'Delete',      desc: 'You are about to delete <strong>$$names$$</strong>.',                                   warn: 'This action cannot be undone.' }
  };

  function show(cfg, names, context, warnOverride) {
    if (!cfg) return;
    titleEl.textContent    = cfg.title;
    confirmBtn.textContent = cfg.btn;
    var html = cfg.desc
      .replace('$$names$$',   esc(names))
      .replace('$$context$$', esc(context || ''));
    descEl.innerHTML       = html;
    warnEl.textContent     = warnOverride || cfg.warn;
    modal.style.display    = 'flex';
    confirmBtn.focus();
  }

  function close() { modal.style.display = 'none'; pending = null; }

  cancelBtn.addEventListener('click', close);

  // ── Buttons that use form="id" to reference an out-of-flow data-del-modal form ──
  // Some browsers fire submit on the ancestor form instead of the referenced one,
  // so we intercept the click and handle it directly.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('button[type="submit"][form]');
    if (!btn) return;
    var target = document.getElementById(btn.getAttribute('form'));
    if (!target || !target.hasAttribute('data-del-modal')) return;
    if (target.dataset.delConfirmed === '1') { delete target.dataset.delConfirmed; return; }
    e.preventDefault();
    var cfg = CFG[target.dataset.delEntity || ''];
    if (!cfg) return;
    pending = target;
    show(cfg, target.dataset.delName || '', target.dataset.delContext || '', target.dataset.delWarning || '');
  });

  confirmBtn.addEventListener('click', function () {
    if (!pending) return;
    var f = pending;
    close();
    f.dataset.delConfirmed = '1';
    f.requestSubmit ? f.requestSubmit() : f.submit();
  });
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') close();
  });

  // Public API: trigger the del-modal with an arbitrary callback instead of a form submit.
  // Usage: window.lstrDelModal.trigger(callbackFn, 'code', 'filename.jpg', '', '');
  window.lstrDelModal = {
    trigger: function (onConfirm, entity, names, context, warning) {
      var cfg = CFG[entity];
      if (!cfg) { onConfirm(); return; }
      pending = { _cb: onConfirm };
      show(cfg, names, context, warning || '');
      var origClick = confirmBtn.onclick;
      confirmBtn.onclick = function () {
        close();
        confirmBtn.onclick = origClick;
        onConfirm();
      };
    }
  };

  document.addEventListener('submit', function (e) {
    var form = e.target;

    // ── Single-item delete ──────────────────────────────────────────────────
    if (form.hasAttribute('data-del-modal')) {
      if (form.dataset.delConfirmed === '1') { delete form.dataset.delConfirmed; return; }
      var cfg = CFG[form.dataset.delEntity || ''];
      if (!cfg) return;
      e.preventDefault();
      pending = form;
      var ctxFrom = form.dataset.delContextFrom;
      var ctx = ctxFrom ? (form.querySelector('[name="' + ctxFrom + '"]') || {}).value || '' : form.dataset.delContext || '';
      show(cfg, form.dataset.delName || '', ctx, form.dataset.delWarning || '');
      return;
    }

    // ── Bulk delete / reject ────────────────────────────────────────────────
    if (form.hasAttribute('data-del-bulk')) {
      if (form.dataset.delConfirmed === '1') { delete form.dataset.delConfirmed; return; }
      var bulkSel2 = form.querySelector('select[name="bulk_action"], select[name="action"]');
      if (!bulkSel2) return;
      var val = bulkSel2.value;
      if (val !== 'delete' && val !== 'reject') return;
      var cfg2 = CFG[form.dataset.delEntity || ''];
      if (!cfg2) return;
      var cbs = form.querySelectorAll('input[type="checkbox"]:checked[data-name]');
      if (!cbs.length) return; // nothing checked -- validation toast already shown
      var nameList = [];
      cbs.forEach(function (cb) { nameList.push(cb.dataset.name); });
      e.preventDefault();
      pending = form;
      show(cfg2, nameList.join(', '), form.dataset.delContext || '', '');
    }
  });
}());

// ── 42. Collective Templates -- token insert, reset to default ─────────────────

(function () {
  function insertTokenFromRow(row) {
    var ta = document.getElementById(row.dataset.textarea);
    if (!ta) return;
    var token = row.dataset.token || '';
    var start = ta.selectionStart, end = ta.selectionEnd;
    ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
    ta.selectionStart = ta.selectionEnd = start + token.length;
    ta.focus();
  }

  // Token row: insert at cursor in target textarea (event delegation)
  document.addEventListener('click', function (e) {
    var row = e.target.closest('.tok-row[data-textarea]');
    if (!row) return;
    insertTokenFromRow(row);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var row = e.target.closest('.tok-row[data-textarea]');
    if (!row) return;
    e.preventDefault();
    insertTokenFromRow(row);
  });

  // Token search: generic multi-instance implementation.
  // Each [data-token-search] input scopes its search to [data-token-card] elements within
  // the container identified by [data-token-search-scope] (or the whole document when omitted).
  (function () {
    function tokenSearchNorm(s) {
      return (s || '').toLowerCase().replace(/[{}_.\/-]+/g, ' ');
    }

    document.querySelectorAll('[data-token-search]').forEach(function (input) {
      var scopeId    = input.getAttribute('data-token-search-scope');
      var scope      = scopeId ? document.getElementById(scopeId) : document;
      if (!scope) return;

      var clearBtn   = scope.querySelector('[data-token-search-clear]');
      var emptyEl    = scope.querySelector('[data-token-search-empty]');
      var tokenCards = scope.querySelectorAll('[data-token-card]');
      var wasOpen    = {};

      function run() {
        var rawQuery     = input.value.trim();
        var terms        = tokenSearchNorm(rawQuery).split(/\s+/).filter(Boolean);
        var active       = terms.length > 0;
        var totalMatches = 0;

        if (active && !wasOpen._active) {
          tokenCards.forEach(function (card, i) { wasOpen[i] = card.open; });
          wasOpen._active = true;
        }

        tokenCards.forEach(function (card, i) {
          var rows    = card.querySelectorAll('.tok-row[data-token]');
          var helps   = card.querySelectorAll('[data-token-search-help]');
          var matches = 0;

          helps.forEach(function (el) { el.hidden = active; });

          rows.forEach(function (row) {
            var source   = [row.getAttribute('data-token') || '', row.getAttribute('title') || '', row.textContent || ''].join(' ');
            var haystack = source.toLowerCase() + ' ' + tokenSearchNorm(source);
            var match    = !active || terms.every(function (t) { return haystack.indexOf(t) !== -1; });
            row.hidden   = !match;
            if (match) matches++;
          });

          if (active) {
            card.hidden = matches === 0;
            if (matches > 0) card.open = true;
            totalMatches += matches;
          } else {
            card.hidden = false;
            if (wasOpen._active) { card.open = !!wasOpen[i]; delete wasOpen[i]; }
          }
        });

        if (!active) wasOpen._active = false;
        if (emptyEl)  emptyEl.hidden  = !active || totalMatches > 0;
        if (clearBtn) clearBtn.hidden = input.value.length === 0;
      }

      input.addEventListener('input', run);
      if (clearBtn) {
        clearBtn.addEventListener('click', function () {
          input.value = '';
          run();
          input.focus();
        });
      }
    });
  }());

  // Reset to default button
  var defaultsEl = document.getElementById('luster-coltpl-defaults');
  var defaults = {};
  if (defaultsEl) {
    try { defaults = JSON.parse(defaultsEl.textContent); } catch (err) {}
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.tpl-reset-row button[data-textarea]');
    if (!btn || btn.classList.contains('coltpl-preview-btn')) return;
    var ta = document.getElementById(btn.dataset.textarea);
    if (ta && defaults[btn.dataset.textarea] !== undefined) {
      ta.value = defaults[btn.dataset.textarea];
      ta.focus();
    }
  });

  // ── Collective template preview (real-data backend render) ─────────────
  var coltplMeta = {};
  var coltplMetaEl = document.getElementById('luster-coltpl-meta');
  if (coltplMetaEl) { try { coltplMeta = JSON.parse(coltplMetaEl.textContent); } catch (err) {} }

  var coltplModal    = document.getElementById('coltpl-prev-modal');
  var coltplIframe   = document.getElementById('coltpl-prev-iframe');
  var coltplClose    = document.getElementById('coltpl-prev-close');
  var coltplTitle    = document.getElementById('coltpl-prev-title');
  var coltplSpinner  = document.getElementById('coltpl-prev-spinner');

  var COLTPL_LABELS = {
    owned: 'Owned Listings Preview', random: 'Random Listing Preview',
    latest: 'Latest Listing Preview', joined: 'Joined Listings Preview',
    collective_affiliates_section: 'Affiliates Preview', collective_stats: 'Statistics Preview',
    members: 'Members List Preview', affiliates_section: 'Affiliates Section Preview',
    stats: 'Statistics Preview', updates: 'Updates Feed Preview',
    adoption_intro: 'Adoption Form Intro Preview',
    codes_submit_form: 'Code Donation Form Preview', rules_intro: 'Join Rules Intro Preview',
    join_form: 'Join Form Preview', affiliate_apply_form: 'Affiliate Application Form Preview',
    collective_affiliate_apply_form: 'Collective Affiliate Apply Form Preview',
    kim_signup_form: 'KIM Sign-Up Form Preview',
    adopt_form: 'Adopt Form Preview'
  };

  function closeColtplModal() {
    if (coltplModal) { coltplModal.classList.add('is-hidden'); coltplModal.style.display = 'none'; }
    if (coltplIframe) { coltplIframe.srcdoc = ''; }
  }

  function loadColtplPreview(type, tplHtml) {
    if (!coltplModal || !coltplIframe || !coltplMeta.previewUrl) return;
    if (coltplTitle) coltplTitle.textContent = COLTPL_LABELS[type] || 'Preview';
    if (coltplSpinner) coltplSpinner.style.display = '';
    coltplIframe.srcdoc = '';
    coltplModal.classList.remove('is-hidden');
    coltplModal.style.display = 'flex';

    var fd = new FormData();
    fd.append('_csrf', coltplMeta.csrf || '');
    fd.append('template_type', type);
    fd.append('template_html', tplHtml);

    fetch(coltplMeta.previewUrl, { method: 'POST', body: fd })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        if (coltplSpinner) coltplSpinner.style.display = 'none';
        if (coltplIframe) coltplIframe.srcdoc = html;
      })
      .catch(function () {
        if (coltplSpinner) coltplSpinner.style.display = 'none';
        if (coltplIframe) coltplIframe.srcdoc = '<body style="font-family:sans-serif;padding:1rem;color:#c00">Preview request failed.</body>';
      });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.coltpl-preview-btn');
    if (!btn) return;
    var type     = btn.dataset.tpltype;
    var taId     = btn.dataset.textarea;
    var ta       = taId ? document.getElementById(taId) : null;
    var tplHtml  = ta ? ta.value : '';
    if (!type) return;
    if (!tplHtml.trim()) {
      if (!coltplModal || !coltplIframe) return;
      if (coltplTitle) coltplTitle.textContent = COLTPL_LABELS[type] || 'Preview';
      if (coltplSpinner) coltplSpinner.style.display = 'none';
      coltplIframe.srcdoc = '<body style="font-family:sans-serif;padding:1.5rem;color:#777;font-style:italic">The template is empty. Enter tokens and HTML in the textarea above, then click Preview.</body>';
      coltplModal.classList.remove('is-hidden');
      coltplModal.style.display = 'flex';
      return;
    }
    loadColtplPreview(type, tplHtml);
  });

  if (coltplClose) coltplClose.addEventListener('click', closeColtplModal);
  if (coltplModal) {
    coltplModal.addEventListener('click', function (e) {
      if (e.target === coltplModal) closeColtplModal();
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && coltplModal && coltplModal.style.display !== 'none') closeColtplModal();
  });
}());

// ── 40. Updates add-on -- category tag widget ──────────────────────────────────

(function () {
  document.querySelectorAll('[data-tag-hidden]').forEach(function (wrap) {
    var hiddenId  = wrap.getAttribute('data-tag-hidden');
    var hidden    = document.getElementById(hiddenId);
    if (!hidden) return;
    var textInput = wrap.querySelector('.tag-input-field, input:not([type="hidden"])');
    if (!textInput) return;
    var chipsId        = wrap.getAttribute('data-tag-chips');
    var chipsContainer = chipsId ? (document.getElementById(chipsId) || wrap) : wrap;
    var chipClass      = wrap.getAttribute('data-tag-chip-class') || 'badge badge-info-gold';

    function syncHidden() {
      var chips = chipsContainer.querySelectorAll('[data-value]');
      var vals  = [];
      chips.forEach(function (c) { vals.push(c.dataset.value); });
      hidden.value = vals.join(', ');
    }

    function addChip(value) {
      value = value.trim().replace(/,+$/, '').trim();
      if (!value) return;
      var chip = document.createElement('span');
      chip.className      = chipClass;
      chip.dataset.value  = value;
      chip.innerHTML      = '<span>' + value.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</span>'
                          + '<button type="button" class="tag-chip-remove" aria-label="Remove">&times;</button>';
      chip.querySelector('.tag-chip-remove').addEventListener('click', function () {
        chip.remove();
        syncHidden();
      });
      chipsContainer === wrap ? wrap.insertBefore(chip, textInput) : chipsContainer.appendChild(chip);
    }

    // Initialise from existing hidden value
    var existing = hidden.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    existing.forEach(addChip);
    syncHidden();

    textInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault();
        var val = textInput.value.replace(/,+$/, '').trim();
        if (val) { addChip(val); syncHidden(); }
        textInput.value = '';
      }
    });

    wrap.addEventListener('click', function () { textInput.focus(); });
  });
}());

// ── 41. Import wizard -- connect + scope pages ─────────────────────────────────

(function () {
  // Helper: show/hide element using is-hidden class
  function setVisible(el, visible) {
    if (!el) return;
    el.classList.toggle('is-hidden', !visible);
  }

  // BellaBuffs: switch between folder path mode and direct file upload mode.
  var bbPathSection   = document.getElementById('js-bb-path-section');
  var bbUploadSection = document.getElementById('js-bb-upload-section');
  var bbUploadRadio   = document.getElementById('js-bb-mode-upload');
  var bbFolderInput   = document.getElementById('bb_folder_path');
  var bbMembersFile   = document.getElementById('members_file');
  var bbNewbiesFile   = document.getElementById('newbies_file');
  var bbModeRadios    = document.querySelectorAll('input[name="bb_conn_mode"]');

  if (bbPathSection && bbUploadSection && bbUploadRadio && bbModeRadios.length) {
    function applyBellaBuffsMode() {
      var upload = bbUploadRadio.checked;
      setVisible(bbPathSection, !upload);
      setVisible(bbUploadSection, upload);

      if (bbFolderInput) bbFolderInput.disabled = upload;
      if (bbMembersFile) {
        bbMembersFile.disabled = !upload;
        bbMembersFile.required = upload;
      }
      if (bbNewbiesFile) bbNewbiesFile.disabled = !upload;
    }

    bbModeRadios.forEach(function (r) {
      r.addEventListener('change', applyBellaBuffsMode);
    });
    applyBellaBuffsMode();
  }

  // Image options enable/disable toggle
  var imgToggle = document.getElementById('js-images-toggle');
  var imgOpts   = document.getElementById('js-image-options');
  if (imgToggle && imgOpts) {
    imgToggle.addEventListener('change', function () {
      setVisible(imgOpts, this.checked);
    });
  }

  // Image options fields are shown/hidden by the toggle only (no mode radio).

  // Connection method toggle (live DB vs SQL dump upload)
  var cfgForm    = document.getElementById('import-configure-form');
  var liveSec    = document.getElementById('js-live-db-section');
  var dumpSec    = document.getElementById('js-dump-section');
  var submitBtn  = document.getElementById('js-submit-btn');
  var connRadios = document.querySelectorAll('input[name="_conn_mode"]');

  if (cfgForm && liveSec && dumpSec && connRadios.length) {
    function applyConnMode(mode) {
      setVisible(liveSec, mode !== 'dump');
      setVisible(dumpSec, mode === 'dump');
      if (submitBtn) submitBtn.textContent =
        mode === 'dump' ? 'Upload & Continue →' : 'Continue to Options →';
    }
    connRadios.forEach(function (r) {
      r.addEventListener('change', function () { applyConnMode(this.value); });
    });
  }

  // Listing Admin: Members-depends-on-Listings dependency warning
  var listingsCb = document.getElementById('ck-listings');
  var membersCb  = document.getElementById('ck-members');
  var memWarn    = document.getElementById('js-mem-dep-warn');

  if (listingsCb && membersCb && memWarn) {
    function checkMemDep() {
      memWarn.classList.toggle('is-visible', membersCb.checked && !listingsCb.checked);
    }
    listingsCb.addEventListener('change', checkMemDep);
    membersCb.addEventListener('change', checkMemDep);
  }

  // Test connection button
  var testBtn    = document.getElementById('js-test-conn');
  var testResult = document.getElementById('js-test-result');
  if (!testBtn || !testResult) return;

  testBtn.addEventListener('click', function () {
    var action = testBtn.dataset.action;
    if (!action) return;

    testResult.style.display = '';
    testResult.style.color = 'var(--text-muted)';
    testResult.textContent = 'Testing...';

    var fd = new FormData();
    fd.append('_csrf',          (document.querySelector('input[name="_csrf"]') || {}).value || '');
    fd.append('db_host',        (document.getElementById('db_host')        || {}).value || '');
    fd.append('db_name',        (document.getElementById('db_name')        || {}).value || '');
    fd.append('db_user',        (document.getElementById('db_user')        || {}).value || '');
    fd.append('db_pass',        (document.getElementById('db_pass')        || {}).value || '');
    fd.append('fn_table_prefix',(document.getElementById('fn_table_prefix')|| {}).value || '');

    fetch(action, { method: 'POST', body: fd })
      .then(function (r) {
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.json();
      })
      .then(function (d) {
        testResult.textContent = d.ok
          ? ('✓ Connected -- ' + (d.schema || d.message))
          : ('✗ ' + d.message);
        testResult.style.color = d.ok ? 'var(--success)' : 'var(--danger)';
      })
      .catch(function (err) {
        testResult.textContent = '✗ ' + (err.message || 'Request failed.');
        testResult.style.color = 'var(--danger)';
      });
  });
}());
// ── 41b. Bulk email -- recipient chips, template fill, preview modal ────────────

(function () {
  var form        = document.getElementById('es-form');
  if (!form) return;

  var chips       = document.getElementById('es-chip');
  var emailInputs = document.getElementById('es-email-inputs');
  var subjectEl   = document.getElementById('es-subject');
  var bodyEl      = document.getElementById('es-body');
  var sendBtn     = document.getElementById('es-send-btn');
  var sendCount   = document.getElementById('es-send-count');
  var countLabel  = document.getElementById('es-count-label');
  var modal       = document.getElementById('es-preview-modal');
  var previewData = document.getElementById('es-preview-data');
  var scopeEl     = document.getElementById('es-scope');
  var scopeCounts = (previewData && previewData.dataset.scopeCounts)
    ? JSON.parse(previewData.dataset.scopeCounts)
    : null;

  var context        = previewData ? (previewData.dataset.context || '') : '';
  var recipientLabel = scopeEl ? 'affiliate' : (context === 'adoption' ? 'applicant' : 'member');

  // ── Recipient count (chips-based for KIM, scope-based for affiliate email) ─
  function getRecipientCount() {
    if (chips) {
      return chips.querySelectorAll('.es-chip-item:not(.es-chip-removed)').length;
    }
    if (scopeEl && scopeCounts) {
      return parseInt(scopeCounts[scopeEl.value], 10) || 0;
    }
    return 0;
  }

  function syncRecipientCount() {
    var n = getRecipientCount();
    if (sendCount)  sendCount.textContent  = n;
    if (countLabel) countLabel.textContent = n + ' ' + recipientLabel + (n !== 1 ? 's' : '');
    if (sendBtn)    sendBtn.disabled       = (n === 0);
  }

  if (chips) {
    chips.addEventListener('click', function (e) {
      var btn = e.target.closest('.es-chip-remove');
      if (!btn) return;
      var chip  = btn.closest('.es-chip-item');
      var email = chip.dataset.email;
      chip.classList.add('es-chip-removed');
      chip.style.display = 'none';
      if (emailInputs) {
        emailInputs.querySelectorAll('input[name="emails[]"]').forEach(function (inp) {
          if (inp.value === email) inp.remove();
        });
      }
      syncRecipientCount();
    });
  }

  function syncScopePreview() {
    if (!scopeEl) return;
    var opt = scopeEl.options[scopeEl.selectedIndex];
    if (opt && opt.dataset.listingName) {
      sampleListingName = opt.dataset.listingName;
      sampleListingUrl  = opt.dataset.listingUrl || sampleSiteUrl;
    } else {
      sampleListingName = sampleSite;
      sampleListingUrl  = sampleSiteUrl;
    }
    syncRecipientCount();
  }

  if (scopeEl) {
    scopeEl.addEventListener('change', syncScopePreview);
  }

  // ── Template selector ────────────────────────────────────────────────────
  var tplSelect = document.getElementById('es-tpl-select');
  if (tplSelect) {
    tplSelect.addEventListener('change', function () {
      var opt = tplSelect.options[tplSelect.selectedIndex];
      if (!opt || !opt.value) return;
      subjectEl.value = opt.value;
      bodyEl.value    = opt.dataset.body || '';
      tplSelect.value = '';
    });
  }

  // ── Save-as-template form ────────────────────────────────────────────────
  var saveTplForm = document.getElementById('es-save-tpl-form');
  var saveEmails  = document.getElementById('es-save-emails');
  saveTplForm.addEventListener('submit', function () {
    document.getElementById('es-save-subject').value = subjectEl.value;
    document.getElementById('es-save-body').value    = bodyEl.value;
    if (saveEmails && emailInputs) {
      saveEmails.innerHTML = '';
      emailInputs.querySelectorAll('input[name="emails[]"]').forEach(function (inp) {
        var copy   = document.createElement('input');
        copy.type  = 'hidden';
        copy.name  = 'emails[]';
        copy.value = inp.value;
        saveEmails.appendChild(copy);
      });
    }
  });

  // ── Preview / Confirm modal ──────────────────────────────────────────────
  var sampleName           = previewData.dataset.firstName      || 'Alex';
  var sampleEmail          = previewData.dataset.firstEmail     || 'member@example.com';
  var sampleListingName    = previewData.dataset.listingName    || '';
  var sampleListingSubject = previewData.dataset.listingSubject || '';
  var sampleListingUrl     = previewData.dataset.listingUrl     || '';
  var sampleSite           = previewData.dataset.siteName       || '';
  var sampleSiteUrl        = previewData.dataset.siteUrl        || '';
  var sampleOwnerName      = previewData.dataset.ownerName      || '';
  var sampleOwnerEmail     = previewData.dataset.ownerEmail     || '';
  var sampleAppMessage     = previewData.dataset.appMessage     || '';
  var sampleFromName       = previewData.dataset.fromName       || sampleOwnerName;
  var sampleFromEmail      = previewData.dataset.fromEmail      || sampleOwnerEmail;

  // Initialise scope-based preview tokens now that sample vars are declared.
  if (scopeEl) { syncScopePreview(); }

  function substituteTokens(str) {
    return str
      .replace(/\{fan_name\}/g,        sampleName)
      .replace(/\{fan_email\}/g,        sampleEmail)
      .replace(/\{fan_url\}/g,          'https://example.com')
      .replace(/\{fan_country\}/g,      'United States')
      .replace(/\{name\}/g,             sampleName)
      .replace(/\{email\}/g,            sampleEmail)
      .replace(/\{url\}/g,              'https://example.com')
      .replace(/\{added_at\}/g,         new Date().toLocaleDateString())
      .replace(/\{aff_name\}/g,         'My Fanlisting')
      .replace(/\{aff_email\}/g,        'contact@myfanlisting.example')
      .replace(/\{aff_url\}/g,          'https://myfanlisting.example')
      .replace(/\{listing_name\}/g,     sampleListingName  || sampleSite)
      .replace(/\{listing_subject\}/g,  sampleListingSubject)
      .replace(/\{listing_url\}/g,      sampleListingUrl   || sampleSiteUrl)
      .replace(/\{listing_title\}/g,    sampleListingName  || sampleSite)
      .replace(/\{site_name\}/g,        sampleSite)
      .replace(/\{owner_name\}/g,       sampleOwnerName)
      .replace(/\{owner_email\}/g,      sampleOwnerEmail)
      .replace(/\{app_name\}/g,         sampleName)
      .replace(/\{app_email\}/g,        sampleEmail)
      .replace(/\{app_url\}/g,          'https://example.com')
      .replace(/\{app_message\}/g,      sampleAppMessage)
      .replace(/\{app_status\}/g,       'pending');
  }

  function setModalMode(mode) {
    var isConfirm = (mode === 'confirm');
    document.getElementById('es-footer-preview').style.display = isConfirm ? 'none'  : 'flex';
    document.getElementById('es-footer-confirm').style.display = isConfirm ? 'block' : 'none';
  }

  function openModal(mode) {
    var n  = getRecipientCount();
    var fn = document.getElementById('es-preview-from-name');
    var fe = document.getElementById('es-preview-from-email');
    if (fn) fn.textContent = sampleFromName;
    if (fe) fe.textContent = sampleFromEmail ? '<' + sampleFromEmail + '>' : '';
    document.getElementById('es-preview-to').textContent =
      n + ' ' + recipientLabel + (n !== 1 ? 's' : '') + ' selected';
    document.getElementById('es-modal-subject').textContent =
      substituteTokens(subjectEl.value) || '(no subject)';
    document.getElementById('es-modal-body').textContent =
      substituteTokens(bodyEl.value) || '(no message)';
    setModalMode(mode);
    modal.style.display = 'flex';
  }

  function closeModal() {
    modal.style.display = 'none';
    setModalMode('preview');
  }

  sendBtn.addEventListener('click', function () {
    if (!form.reportValidity()) return;
    openModal('confirm');
  });

  document.getElementById('es-preview-btn').addEventListener('click', function () {
    openModal('preview');
  });

  document.getElementById('es-modal-close-preview').addEventListener('click', closeModal);
  document.getElementById('es-modal-cancel').addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

  document.getElementById('es-modal-send').addEventListener('click', function () {
    form.submit();
  });
}());

// ── Skip-detail row toggle (import result page) ──────────────────────────────

document.addEventListener('click', function (e) {
  var btn = e.target.closest('.skip-toggle');
  if (!btn) return;
  var targetId = btn.getAttribute('data-target');
  if (!targetId) return;
  var row = document.getElementById(targetId);
  if (!row) return;
  var opening = row.classList.toggle('table-row-hidden');
  btn.classList.toggle('is-open', !opening);
});

// ── 43. Settings -- scoped panel clear buttons ─────────────────────────────────

(function () {
  [['general-clear-btn', 'panel-general'], ['smtp-clear-btn', 'panel-smtp'], ['embed-clear-btn', 'panel-embed']].forEach(function (pair) {
    var btn   = document.getElementById(pair[0]);
    var panel = document.getElementById(pair[1]);
    if (!btn || !panel) return;
    btn.addEventListener('click', function () {
      panel.querySelectorAll('input[type="text"], input[type="url"], input[type="password"], input[type="number"], textarea').forEach(function (el) {
        el.value = '';
      });
      panel.querySelectorAll('input[type="checkbox"]').forEach(function (el) {
        el.checked = false;
      });
      panel.querySelectorAll('select').forEach(function (el) {
        el.selectedIndex = 0;
      });
    });
  });
}());

// ── 41. Dashboard: To Do List collapse + per-row Mark Updated ─────────────────

(function () {
  // Collapsible section toggles
  document.querySelectorAll('.todo-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var body = document.getElementById(btn.dataset.target || '');
      var chev = btn.querySelector('.todo-chev');
      if (!body) return;
      var nowHidden = body.classList.toggle('todo-body--hidden');
      if (chev) chev.classList.toggle('todo-chev--collapsed', nowHidden);
    });
  });

  // Per-row "Mark Updated" -- fetch POST, update UI without reload
  document.querySelectorAll('.todo-mark-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('button[type="submit"]');
      if (btn && btn.disabled) return;
      if (btn) { btn.disabled = true; btn.textContent = '...'; }

      var params = new URLSearchParams(new FormData(form));
      fetch(form.action, {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    params.toString(),
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) { throw new Error(data.error || 'Failed'); }

          // Update row UI
          var row  = form.closest('.todo-mrow');
          var days = row && row.querySelector('.todo-mrow-days');
          if (days) { days.textContent = 'Just now'; days.style.color = 'var(--success)'; }
          if (btn)  { btn.style.display = 'none'; }

          // Decrement section badge
          var maintBadge = document.getElementById('todo-maint-badge');
          if (maintBadge) {
            var n = parseInt(maintBadge.textContent, 10);
            if (!isNaN(n) && n > 0) maintBadge.textContent = String(n - 1);
          }
          // Decrement header badge
          var totalBadge = document.getElementById('todo-total-badge');
          if (totalBadge) {
            var t = parseInt(totalBadge.textContent, 10);
            if (!isNaN(t) && t > 0) totalBadge.textContent = String(t - 1);
          }
        })
        .catch(function () {
          if (btn) { btn.disabled = false; btn.textContent = 'Update'; }
          if (window.lstrToast) window.lstrToast('error', 'Could not update listing.');
        });
    });
  });

  // Select-all + enable/disable "Mark Selected" for bulk maintenance form
  var selectAll  = document.getElementById('bulk-select-all');
  var bulkBtn    = document.getElementById('bulk-mark-btn');
  var bulkChecks = document.querySelectorAll('[form="bulk-maint-form"][name="listing_ids[]"]');

  function syncBulkBtn() {
    if (!bulkBtn) return;
    var any = Array.prototype.some.call(bulkChecks, function (c) { return c.checked; });
    bulkBtn.disabled = !any;
  }

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      bulkChecks.forEach(function (c) { c.checked = selectAll.checked; });
      syncBulkBtn();
    });
  }

  bulkChecks.forEach(function (c) {
    c.addEventListener('change', function () {
      if (selectAll && !c.checked) selectAll.checked = false;
      if (selectAll && Array.prototype.every.call(bulkChecks, function (x) { return x.checked; })) {
        selectAll.checked = true;
      }
      syncBulkBtn();
    });
  });
}());

// ── 42. Flush error-log confirmation modal ─────────────────────────────────────

(function () {
  var modal      = document.getElementById('flush-logs-modal');
  var openBtn    = document.getElementById('flush-logs-btn');
  if (!modal || !openBtn) return;

  var confirmBtn = document.getElementById('flush-modal-confirm');
  var cancelBtn  = document.getElementById('flush-modal-cancel');
  var form       = document.getElementById('flush-logs-form');

  function openModal()  { modal.style.display = 'flex'; }
  function closeModal() { modal.style.display = 'none'; }

  openBtn.addEventListener('click', openModal);
  cancelBtn.addEventListener('click', closeModal);
  confirmBtn.addEventListener('click', function () { form.submit(); });

  modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') closeModal();
  });
}());

// ── 43. Updates add/edit: preview modal ───────────────────────────────────────

(function () {
  var previewBtn = document.getElementById('upd-preview-btn');
  if (!previewBtn) return;

  var modal    = document.getElementById('upd-preview-modal');
  if (!modal) return;

  var closeX   = document.getElementById('upd-preview-close');
  var closeBtn = document.getElementById('upd-preview-close-btn');

  var MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  var MONTHS_LONG  = ['January','February','March','April','May','June','July','August','September','October','November','December'];

  function phpDate(fmt, d) {
    var h24 = d.getHours(), h12 = h24 % 12 || 12;
    var map = {
      'd': String(d.getDate()).padStart(2, '0'),
      'j': String(d.getDate()),
      'm': String(d.getMonth() + 1).padStart(2, '0'),
      'n': String(d.getMonth() + 1),
      'M': MONTHS_SHORT[d.getMonth()],
      'F': MONTHS_LONG[d.getMonth()],
      'Y': String(d.getFullYear()),
      'y': String(d.getFullYear()).slice(-2),
      'H': String(h24).padStart(2, '0'),
      'G': String(h24),
      'h': String(h12).padStart(2, '0'),
      'g': String(h12),
      'i': String(d.getMinutes()).padStart(2, '0'),
      's': String(d.getSeconds()).padStart(2, '0'),
      'A': h24 < 12 ? 'AM' : 'PM',
      'a': h24 < 12 ? 'am' : 'pm',
    };
    return fmt.replace(/[djmMnFYyHGhgisAa]/g, function (c) { return map[c] !== undefined ? map[c] : c; });
  }

  function openPreview() {
    var titleEl    = document.getElementById('upd-title');
    var bodyEl     = document.getElementById('upd-body');
    var postedAtEl = document.getElementById('upd-posted-at');
    var categoryEl = document.getElementById('upd-category');

    var pvTitle  = document.getElementById('upd-preview-title');
    var pvDate   = document.getElementById('upd-preview-date');
    var pvCats   = document.getElementById('upd-preview-cats');
    var pvCatRow = document.getElementById('upd-preview-cats-row');
    var pvBody   = document.getElementById('upd-preview-body');

    if (pvTitle) pvTitle.textContent = titleEl ? titleEl.value : '';

    if (pvDate && postedAtEl && postedAtEl.value) {
      var d = new Date(postedAtEl.value);
      if (!isNaN(d.getTime())) {
        var dateFmt = modal.dataset.dateFormat || 'j M Y';
        var timeFmt = modal.dataset.timeFormat || 'H:i';
        pvDate.textContent = phpDate(dateFmt, d) + ', ' + phpDate(timeFmt, d);
      } else {
        pvDate.textContent = postedAtEl.value;
      }
    } else if (pvDate) {
      pvDate.textContent = '';
    }

    if (pvCats && categoryEl) {
      var cats = categoryEl.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
      pvCats.innerHTML = '';
      cats.forEach(function (cat) {
        var chip = document.createElement('span');
        chip.className = 'badge badge-inactive';
        chip.style.marginRight = '4px';
        chip.textContent = cat;
        pvCats.appendChild(chip);
      });
      if (pvCatRow) pvCatRow.style.display = cats.length ? '' : 'none';
    }

    if (pvBody) pvBody.textContent = bodyEl ? bodyEl.value : '';

    modal.style.display = 'flex';
  }

  function closePreview() { modal.style.display = 'none'; }

  previewBtn.addEventListener('click', openPreview);
  if (closeX)   closeX.addEventListener('click',   closePreview);
  if (closeBtn) closeBtn.addEventListener('click', closePreview);
  modal.addEventListener('click', function (e) { if (e.target === modal) closePreview(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') closePreview();
  });
}());

// ── 44. Updates list: filter panel toggle ─────────────────────────────────────

(function () {
  adminWireFilterPanel('upd-filter-toggle', 'upd-filter-panel', 'fp-upd');
}());

// ── 45. Import scope: ZIP auto-upload on file select ──────────────────────────

(function () {
  var fileInput = document.getElementById('js-zip-file-input');
  if (!fileInput) return;

  fileInput.addEventListener('change', function () {
    var result = document.getElementById('js-zip-upload-result');
    if (!fileInput.files || !fileInput.files[0]) return;

    var fd = new FormData();
    fd.append('_csrf',      fileInput.dataset.csrf      || '');
    fd.append('import_zip', fileInput.files[0]);

    if (result) { result.style.display = 'block'; result.textContent = 'Uploading...'; result.style.color = 'var(--text-muted)'; }

    fetch(fileInput.dataset.uploadUrl || '', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (result) {
          result.style.display = 'block';
          if (data.ok) {
            result.textContent = '✓ ZIP uploaded (' + (data.files || 0) + ' files). Images will be processed during import.';
            result.style.color = 'var(--success)';
          } else {
            result.textContent = 'Error: ' + (data.error || 'Upload failed.');
            result.style.color = 'var(--danger)';
          }
        }
      })
      .catch(function () {
        if (result) { result.style.display = 'block'; result.textContent = 'Upload failed. Please try again.'; result.style.color = 'var(--danger)'; }
      });
  });
}());

// ── 46. Generic active toggle -- .lstr-toggle-form ─────────────────────────────
//
// Any <form class="lstr-toggle-form"> whose submit button has class .toggle-pill
// will be intercepted: the form POSTs via fetch, the JSON {ok, active} response
// flips the pill label/class and dims/undims the enclosing <tr>.

(function () {
  document.querySelectorAll('.lstr-toggle-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var pill = form.querySelector('.toggle-pill');
      if (pill && pill.disabled) return;
      if (pill) { pill.disabled = true; }

      var params = new URLSearchParams(new FormData(form));
      fetch(form.action, {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    params.toString(),
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) { throw new Error(data.error || 'Failed'); }
          var active = !!data.active;
          if (pill) {
            pill.disabled = false;
            pill.textContent = active ? 'Yes' : 'No';
            pill.classList.toggle('toggle-pill--on', active);
            pill.title = active ? 'Click to hide' : 'Click to show';
          }
          var row = form.closest('tr');
          if (row) { row.classList.toggle('row-inactive', !active); }
        })
        .catch(function () {
          if (pill) { pill.disabled = false; }
        });
    });
  });
}());


// ── 48. Link Checker ──────────────────────────────────────────────────────────

(function () {

  // ── Shared batch check logic ──────────────────────────────────────────────

  function statusLabel(s) {
    var map = { ok: 'Active', dead: 'Dead', redirect: 'Redirect', found: 'Found', missing: 'Missing', unchecked: 'Unchecked' };
    return map[s] || s;
  }

  function statusClass(s) {
    var map = {
      ok: 'badge-current',
      found: 'badge-current',
      dead: 'badge-error',
      missing: 'badge-error',
      redirect: 'badge-redirect',
      unchecked: 'badge-inactive'
    };
    return map[s] || 'badge-inactive';
  }

  var classToStatus = { 'badge-current': 'ok', 'badge-error': 'dead', 'badge-redirect': 'redirect', 'badge-inactive': 'unchecked' };
  var pillLabels    = { ok: 'Active', dead: 'Dead', redirect: 'Redirect', unchecked: 'Unchecked' };
  var pillClasses   = { ok: 'badge-current', dead: 'badge-error', redirect: 'badge-redirect', unchecked: 'badge-inactive' };

  function updatePills(panelId, oldStatus, newStatus) {
    if (oldStatus === newStatus) return;
    var panel = document.getElementById('tab-' + panelId);
    if (!panel) return;
    var pills = panel.querySelector('.stat-pills');
    if (!pills) return;
    var oldCls = pillClasses[oldStatus];
    var newCls = pillClasses[newStatus];
    if (oldCls) {
      var oldPill = pills.querySelector('.' + oldCls);
      if (oldPill) {
        var n = parseInt(oldPill.textContent, 10);
        if (!isNaN(n) && n > 0) oldPill.textContent = (n - 1) + ' ' + (pillLabels[oldStatus] || oldStatus);
      }
    }
    if (newCls) {
      var newPill = pills.querySelector('.' + newCls);
      if (newPill) {
        var m = parseInt(newPill.textContent, 10);
        if (!isNaN(m)) newPill.textContent = (m + 1) + ' ' + (pillLabels[newStatus] || newStatus);
      }
    }
  }

  function applyResult(row, data, panelId) {
    var linkBadge  = row.querySelector('[data-lc-link-badge]');
    var recipBadge = row.querySelector('[data-lc-recip-badge]');
    var checkedCell = row.querySelector('[data-lc-checked]');

    var statusClasses = ['badge-current', 'badge-error', 'badge-redirect', 'badge-inactive'];

    if (linkBadge) {
      var oldStatus = 'unchecked';
      for (var cls in classToStatus) {
        if (linkBadge.classList.contains(cls)) { oldStatus = classToStatus[cls]; break; }
      }
      linkBadge.classList.add('badge');
      statusClasses.forEach(function (c) { linkBadge.classList.remove(c); });
      linkBadge.classList.add(statusClass(data.link_status));
      linkBadge.textContent = statusLabel(data.link_status);
      if (panelId) updatePills(panelId, oldStatus, data.link_status);
    }
    if (recipBadge && data.reciprocal_status !== undefined) {
      recipBadge.classList.add('badge');
      statusClasses.forEach(function (c) { recipBadge.classList.remove(c); });
      recipBadge.classList.add(statusClass(data.reciprocal_status));
      recipBadge.textContent = statusLabel(data.reciprocal_status);
    }
    if (checkedCell) {
      checkedCell.textContent = data.checked_at || '';
    }

    row.classList.remove('lc-busy');
  }

  function checkOne(endpoint, csrf, id, row, panelId, onDone) {
    if (row) row.classList.add('lc-busy');
    var body = '_csrf=' + encodeURIComponent(csrf) + '&id=' + encodeURIComponent(id);
    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (row) applyResult(row, data, panelId);
        if (onDone) onDone();
      })
      .catch(function () {
        if (row) row.classList.remove('lc-busy');
        if (onDone) onDone();
      });
  }

  function runBatch(startBtn, panelId) {
    var progWrap  = document.getElementById('lc-prog-' + panelId);
    var progBar   = document.getElementById('lc-prog-bar-' + panelId);
    var progLabel = document.getElementById('lc-prog-label-' + panelId);
    var endpoint  = startBtn.dataset.endpoint;
    var csrf      = startBtn.dataset.csrf;
    var idsUrl    = startBtn.dataset.idsUrl || null;
    var prefix    = panelId === 'affiliates' ? 'lc-aff-' : 'lc-joined-';

    startBtn.disabled = true;
    if (progWrap) progWrap.classList.remove('is-hidden');

    function processIds(ids) {
      var total = ids.length;
      var done  = 0;

      if (!total) {
        startBtn.disabled = false;
        return;
      }

      function next() {
        if (done >= total) {
          startBtn.disabled = false;
          return;
        }
        var id  = ids[done];
        done++;
        var pct = Math.round((done / total) * 100);
        if (progBar)   progBar.style.width   = pct + '%';
        if (progLabel) progLabel.textContent = done + ' / ' + total;

        // row may be null when the record is on another page -- checkOne handles it
        var row = document.getElementById(prefix + id);
        checkOne(endpoint, csrf, id, row, panelId, function () {
          setTimeout(next, 200);
        });
      }

      next();
    }

    if (idsUrl) {
      fetch(idsUrl)
        .then(function (r) { return r.json(); })
        .then(function (ids) { processIds(ids); })
        .catch(function () { startBtn.disabled = false; });
    } else {
      // Fallback: current page rows only (no ids-url set)
      var panel = document.getElementById('tab-' + panelId);
      var rows  = panel ? Array.from(panel.querySelectorAll('tbody tr')) : [];
      processIds(rows.map(function (r) { return r.id.replace(/^lc-(aff|joined)-/, ''); }));
    }
  }

  // ── "Check All" buttons ───────────────────────────────────────────────────
  var checkAffBtn    = document.getElementById('lc-check-affiliates');
  var checkJoinedBtn = document.getElementById('lc-check-joined');

  if (checkAffBtn) {
    checkAffBtn.addEventListener('click', function () {
      runBatch(checkAffBtn, 'affiliates');
    });
  }
  if (checkJoinedBtn) {
    checkJoinedBtn.addEventListener('click', function () {
      runBatch(checkJoinedBtn, 'joined');
    });
  }

  // ── Toolbar "Run Link Checker" proxy ─────────────────────────────────────
  var lcRunBtn = document.getElementById('lc-run-btn');
  if (lcRunBtn) {
    lcRunBtn.addEventListener('click', function () {
      var activeTab = document.querySelector('.tabs-btn.active[data-tab]');
      var tabId     = activeTab ? activeTab.dataset.tab : 'affiliates';
      var panelBtn  = tabId === 'joined' ? checkJoinedBtn : checkAffBtn;
      if (!panelBtn || panelBtn.disabled) return;
      runBatch(panelBtn, tabId);
    });
  }

  // ── Individual "Check" buttons ────────────────────────────────────────────
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-lc-single');
    if (!btn) return;

    var id       = btn.dataset.id;
    var endpoint = btn.dataset.endpoint;
    var csrf     = btn.dataset.csrf;
    var type     = btn.dataset.type;
    var panelId  = type === 'affiliate' ? 'affiliates' : 'joined';
    var rowId    = 'lc-' + (type === 'affiliate' ? 'aff' : 'joined') + '-' + id;
    var row      = document.getElementById(rowId);

    if (!row) return;

    btn.disabled = true;
    checkOne(endpoint, csrf, id, row, panelId, function () {
      btn.disabled = false;
    });
  });

}());

// ── Embed snippet copy + code toggle ────────────────────────────────
(function () {
  document.addEventListener('click', function (ev) {
    var copyBtn = ev.target.closest('.es-copy');
    if (copyBtn) {
      var text = copyBtn.dataset.snippet || '';
      var original = copyBtn.innerHTML;
      function done() {
        copyBtn.textContent = 'Copied!';
        setTimeout(function () { copyBtn.innerHTML = original; }, 1600);
      }
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(done).catch(function () {});
      } else {
        done();
      }
      return;
    }
    var toggleBtn = ev.target.closest('.es-toggle');
    if (toggleBtn) {
      var item = toggleBtn.closest('.es-item');
      var snippet = item ? item.querySelector('.es-snippet') : null;
      if (!snippet) return;
      var willShow = snippet.hidden;
      snippet.hidden = !willShow;
      toggleBtn.setAttribute('aria-expanded', willShow ? 'true' : 'false');
    }
  });
}());

// ── Nav search ───────────────────────────────────────────────────────────────

(function () {
  var input     = document.getElementById('nav-search-input');
  var clearBtn  = document.getElementById('nav-search-clear');
  var noResults = document.getElementById('nav-search-no-results');
  var extrasEl  = document.getElementById('nav-search-extras');
  if (!input) return;

  var base = (input.dataset.base || '').replace(/\/$/, '');

  // Static index of sub-pages and tabs not represented in the sidebar nav
  var EXTRAS = [
    { label: 'Email Templates',    hint: 'Emails',   url: base + '/admin/emails?tab=templates' },
    { label: 'Bulk Email',         hint: 'Emails',   url: base + '/admin/emails?tab=bulk' },
    { label: 'Custom Templates',   hint: 'Emails',   url: base + '/admin/emails?tab=custom' },
    { label: 'Account Details',    hint: 'Settings', url: base + '/admin/settings#panel-admin' },
    { label: 'Admin Accounts',     hint: 'Settings', url: base + '/admin/settings#panel-accounts' },
    { label: 'General Settings',   hint: 'Settings', url: base + '/admin/settings#panel-general' },
    { label: 'Server Paths',       hint: 'Settings', url: base + '/admin/settings#panel-embed' },
    { label: 'Blacklist',          hint: 'Settings', url: base + '/admin/settings#panel-blacklist' },
    { label: 'System Details',     hint: 'Settings', url: base + '/admin/settings#panel-system' },
    { label: 'System Info',        hint: 'Admin',    url: base + '/admin/sysinfo' },
    { label: 'Members Settings',   hint: 'Members',  url: base + '/admin/members/settings' },
    { label: 'Collective Templates', hint: 'Collective', url: base + '/admin/collective/templates' },
    { label: 'Embed Snippets',       hint: 'Collective', url: base + '/admin/collective/snippets' },
    { label: 'Collective Codes',     hint: 'Collective', url: base + '/admin/codes?collective=1' },
    { label: 'Collective Settings',  hint: 'Collective', url: base + '/admin/collective/settings' },
  ];

  function navLabel(el) {
    return Array.from(el.childNodes)
      .filter(function (n) { return n.nodeType === 3; })
      .map(function (n) { return n.textContent; })
      .join('').trim().toLowerCase();
  }

  function renderExtras(q) {
    if (!extrasEl) return false;
    extrasEl.innerHTML = '';
    if (q === '') return false;

    var matches = EXTRAS.filter(function (e) {
      return e.label.toLowerCase().includes(q);
    });

    if (matches.length === 0) return false;

    var label = document.createElement('div');
    label.className = 'sidebar-label';
    label.textContent = 'Jump to';
    extrasEl.appendChild(label);

    matches.forEach(function (e) {
      var a = document.createElement('a');
      a.href = e.url;
      a.className = 'nav-item nav-item-extra';
      a.innerHTML = e.label + '<span class="nav-extra-hint">' + e.hint + '</span>';
      extrasEl.appendChild(a);
    });

    return true;
  }

  function setClear(show) {
    if (clearBtn) clearBtn.classList.toggle('is-visible', show);
  }

  function run(query) {
    var q        = query.trim().toLowerCase();
    var items    = document.querySelectorAll('.sidebar-nav .nav-item:not(.nav-item-extra)');
    var groups   = document.querySelectorAll('.nav-group');
    var sections = document.querySelectorAll('.sidebar-section');
    var anyVisible = false;

    setClear(query !== '');

    if (q === '') {
      items.forEach(function (el) { el.style.display = ''; });
      groups.forEach(function (g) {
        g.style.display = '';
        var toggle = g.querySelector('.nav-group-toggle');
        var panel  = g.querySelector('.nav-group-panel');
        if (toggle && panel) {
          panel.classList.toggle('open', toggle.getAttribute('aria-expanded') === 'true');
          panel.style.maxHeight = '';
        }
      });
      sections.forEach(function (s) { s.style.display = ''; });
      if (noResults) noResults.classList.remove('is-visible');
      renderExtras('');
      return;
    }

    items.forEach(function (el) {
      el.style.display = navLabel(el).includes(q) ? '' : 'none';
    });

    groups.forEach(function (g) {
      var panel = g.querySelector('.nav-group-panel');
      if (!panel) return;
      var matched = Array.from(panel.querySelectorAll('.nav-item'))
        .some(function (el) { return el.style.display !== 'none'; });
      if (matched) {
        g.style.display = '';
        panel.classList.add('open');
        panel.style.maxHeight = '999px';
        anyVisible = true;
      } else {
        g.style.display = 'none';
      }
    });

    sections.forEach(function (s) {
      var hasItem  = Array.from(s.querySelectorAll('.nav-item:not(.nav-item-extra)')).some(function (el) { return el.style.display !== 'none'; });
      var hasGroup = Array.from(s.querySelectorAll('.nav-group')).some(function (g) { return g.style.display !== 'none'; });
      s.style.display = (hasItem || hasGroup) ? '' : 'none';
      if (hasItem || hasGroup) anyVisible = true;
    });

    var hasExtras = renderExtras(q);
    if (hasExtras) anyVisible = true;
    if (noResults) noResults.classList.toggle('is-visible', !anyVisible);
  }

  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      input.value = '';
      run('');
      input.focus();
    });
  }

  input.addEventListener('input', function () { run(this.value); });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { this.value = ''; run(''); this.blur(); }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'k' && (e.ctrlKey || e.metaKey)) {
      e.preventDefault();
      input.focus();
      input.select();
      return;
    }
    if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
      e.preventDefault();
      input.focus();
      input.select();
    }
  });
}());

// ── Settings page mini-nav dropdown (mobile ≤ 820px) ─────────────────────────
(function () {
  var sel = document.getElementById('settings-nav-select');
  if (!sel) return;

  function sync() {
    var h = location.hash.replace('#', '') || 'panel-admin';
    if (sel.querySelector('option[value="' + h + '"]')) sel.value = h;
  }

  sel.addEventListener('change', function () {
    location.href = '#' + this.value;
  });

  window.addEventListener('hashchange', sync);
  sync();
}());

// ── Skin card preview popup ────────────────────────────────────────────────
(function () {
  var cards = document.querySelectorAll('[data-skin-preview]');
  if (!cards.length) return;

  var PANEL =
    '<div class="sp-side">' +
      '<div class="sp-rail"></div>' +
      '<div class="sp-gem"></div>' +
      '<div class="sp-nav">' +
        '<div class="sp-item"></div>' +
        '<div class="sp-item"></div>' +
        '<div class="sp-item sp-item--active"></div>' +
        '<div class="sp-item"></div>' +
      '</div>' +
    '</div>' +
    '<div class="sp-body">' +
      '<div class="sp-bar"><div class="sp-bar-title"></div><div class="sp-bar-btn"></div></div>' +
      '<div class="sp-main">' +
        '<div class="sp-card">' +
          '<div class="sp-ch"></div>' +
          '<div class="sp-rows">' +
            '<div class="sp-row"></div>' +
            '<div class="sp-row sp-row--short"></div>' +
            '<div class="sp-row"></div>' +
          '</div>' +
        '</div>' +
      '</div>' +
    '</div>';

  var pop = document.createElement('div');
  pop.className = 'skin-pop';
  pop.setAttribute('aria-hidden', 'true');
  pop.innerHTML =
    '<div class="sp-panel" data-theme="light">' + PANEL + '</div>' +
    '<div class="sp-sep"></div>' +
    '<div class="sp-panel" data-theme="dark">'  + PANEL + '</div>';
  document.body.appendChild(pop);

  var POP_W = 340;
  var POP_H = 158;
  var GAP   = 8;
  var timer = null;

  function show(card) {
    clearTimeout(timer);
    var slug   = card.dataset.skinPreview;
    var panels = pop.querySelectorAll('.sp-panel');
    panels.forEach(function (panel) {
      if (slug === '') {
        panel.removeAttribute('data-skin');
        panel.style.setProperty('--va-pink',      '#ff7eb3');
        panel.style.setProperty('--ch-pink',      '255 126 179');
        panel.style.setProperty('--gradient-bar', 'linear-gradient(90deg, #ff7eb3, #78e8d8, #ffd166)');
      } else {
        panel.dataset.skin = slug;
        panel.style.removeProperty('--va-pink');
        panel.style.removeProperty('--ch-pink');
        panel.style.removeProperty('--gradient-bar');
      }
    });
    var r = card.getBoundingClientRect();
    var x = Math.max(8, Math.min(r.left + r.width / 2 - POP_W / 2, window.innerWidth - POP_W - 8));
    var y = r.top - POP_H - GAP;
    if (y < 8) y = r.bottom + GAP;
    pop.style.left = x + 'px';
    pop.style.top  = y + 'px';
    pop.classList.add('skin-pop--visible');
  }

  function hide() {
    timer = setTimeout(function () { pop.classList.remove('skin-pop--visible'); }, 60);
  }

  cards.forEach(function (card) {
    card.addEventListener('mouseenter', function () { show(card); });
    card.addEventListener('mouseleave', hide);
    card.addEventListener('focus',      function () { show(card); });
    card.addEventListener('blur',       hide);
  });
}());


// ── 49. Wishlist: drag-and-drop row reorder ────────────────────────────────────
//
// Active when the table carries [data-wl-reorder-url], which the template sets
// only on a type-specific tab sorted by sort_order ASC with no active filters.
// On drop, POSTs the new ordered ID list to the reorder endpoint; the controller
// splices the page slice into the global order and renumbers all rows of that
// type so cross-page sort_order values never collide.

(function () {
  var table = document.querySelector('table[data-wl-reorder-url]');
  if (!table) return;

  var reorderUrl = table.dataset.wlReorderUrl;
  var csrf       = table.dataset.wlCsrf;
  var type       = table.dataset.wlType;
  var page       = table.dataset.wlPage       || '1';
  var perPage    = table.dataset.wlPerPage     || '10';
  var tbody      = table.querySelector('tbody');
  var dragged    = null;

  tbody.addEventListener('dragstart', function (e) {
    var row = e.target.closest('tr[data-id]');
    if (!row) return;
    dragged = row;
    row.classList.add('dnd-dragging');
    e.dataTransfer.effectAllowed = 'move';
  });

  tbody.addEventListener('dragover', function (e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    var row = e.target.closest('tr[data-id]');
    if (!row || row === dragged) return;
    tbody.querySelectorAll('tr').forEach(function (r) { r.classList.remove('dnd-over'); });
    row.classList.add('dnd-over');
    var rect = row.getBoundingClientRect();
    if (e.clientY < rect.top + rect.height / 2) {
      tbody.insertBefore(dragged, row);
    } else {
      tbody.insertBefore(dragged, row.nextSibling);
    }
  });

  tbody.addEventListener('drop', function (e) { e.preventDefault(); });

  tbody.addEventListener('dragend', function () {
    if (dragged) dragged.classList.remove('dnd-dragging');
    tbody.querySelectorAll('tr').forEach(function (r) { r.classList.remove('dnd-over'); });
    dragged = null;

    var ids = [];
    tbody.querySelectorAll('tr[data-id]').forEach(function (r) {
      ids.push(parseInt(r.dataset.id, 10));
    });

    var params = new URLSearchParams();
    params.append('_csrf',     csrf);
    params.append('type',      type);
    params.append('page',      page);
    params.append('per_page',  perPage);
    ids.forEach(function (id) { params.append('ids[]', id); });

    fetch(reorderUrl, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    params.toString(),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          window.lstrToast('success', 'Order saved.');
        } else {
          window.lstrToast('error', data.error || 'Reorder failed.');
        }
      })
      .catch(function () {
        window.lstrToast('error', 'Reorder failed.');
      });
  });
}());

// ── 47. Wishlist index: select-all + visible toggle ───────────────────────────

(function () {
  var master = document.getElementById('wl-select-all');
  var form   = document.getElementById('wl-bulk-form');
  if (master && form) {
    var getRows = function () { return Array.from(form.querySelectorAll('input[name="ids[]"]')); };
    master.addEventListener('change', function () {
      getRows().forEach(function (cb) { cb.checked = master.checked; });
    });
    form.addEventListener('change', function (e) {
      if (e.target.name !== 'ids[]') return;
      var rows = getRows();
      master.checked       = rows.every(function (cb) { return cb.checked; });
      master.indeterminate = !master.checked && rows.some(function (cb) { return cb.checked; });
    });
  }

  // ── Move-to dropdown: show when "Move" is selected ───────────────────────────────────
  var bulkSel  = document.getElementById('wl-bulk-action');
  var moveWrap = document.getElementById('wl-move-to-wrap');
  if (bulkSel && moveWrap) {
    bulkSel.addEventListener('change', function () {
      var show = bulkSel.value === 'move';
      moveWrap.style.display = show ? 'inline-flex' : 'none';
    });
  }

  document.querySelectorAll('.wl-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (btn.disabled) return;
      btn.disabled = true;
      fetch(btn.dataset.url, {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    '_csrf=' + encodeURIComponent(btn.dataset.csrf),
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) throw new Error(data.error || 'Failed');
          btn.disabled = false;
          var active = !!data.active;
          btn.textContent = active ? 'Yes' : 'No';
          btn.classList.toggle('toggle-pill--on', active);
          btn.title = active ? 'Click to hide' : 'Click to show';
          var row = btn.closest('tr');
          if (row) row.classList.toggle('row-inactive', !active);
        })
        .catch(function () { btn.disabled = false; });
    });
  });
}());

// ── 50. Live search -- debounced AJAX fetch, results-only swap ─────────────────
//
// On each keystroke (debounced 350 ms) fetches the filtered URL and replaces
// only the results section -- everything after the .filter-panel (or the search
// form itself when there is no filter panel). The toolbar, filter panel, and
// search input are never touched, so focus is never lost and interactive
// elements in those areas need no re-initialisation.
//
// After the swap, simple select-all checkboxes and the wishlist bulk-move
// dropdown in the new results are re-wired.

(function () {
  var DELAY   = 350;
  var timer   = null;
  var pending = null;

  function buildUrl(form) {
    var params = new URLSearchParams();
    Array.from(new FormData(form)).forEach(function (pair) {
      params.append(pair[0], pair[1]);
    });
    return form.action + '?' + params.toString();
  }

  function swapResults(input, html) {
    var doc     = (new DOMParser()).parseFromString(html, 'text/html');
    var newPage = doc.querySelector('.page-content');
    var curPage = document.querySelector('.page-content');
    if (!newPage || !curPage) return;

    // Pivot: the filter panel containing the input, or the form itself.
    var pivot    = input.closest('.filter-panel') || input.form;
    var newPivot = newPage.querySelector('.filter-panel') || newPage.querySelector('form[method="get"]');
    if (!pivot || !newPivot) return;

    // Remove everything after the pivot in the current page.
    var dead = [];
    var el   = pivot.nextElementSibling;
    while (el) { dead.push(el); el = el.nextElementSibling; }
    dead.forEach(function (n) { n.remove(); });

    // Append new results from the fetched page.
    var fresh = newPivot.nextElementSibling;
    while (fresh) {
      var next = fresh.nextElementSibling;
      curPage.appendChild(fresh);
      fresh = next;
    }

    reinitResults(curPage);
  }

  function reinitResults(curPage) {
    // Re-wire simple select-all checkboxes inside any newly inserted form.
    curPage.querySelectorAll('input[type="checkbox"][id$="-select-all"]').forEach(function (master) {
      var form = master.closest('form');
      if (!form) return;
      master.addEventListener('change', function () {
        form.querySelectorAll('input[name="ids[]"]').forEach(function (cb) { cb.checked = master.checked; });
      });
      form.addEventListener('change', function (e) {
        if (!e.target.matches('input[name="ids[]"]')) return;
        var all = Array.from(form.querySelectorAll('input[name="ids[]"]'));
        master.checked       = all.length > 0 && all.every(function (c) { return c.checked; });
        master.indeterminate = !master.checked && all.some(function (c) { return c.checked; });
      });
    });

    // Re-wire wishlist bulk move-to dropdown if present in new results.
    var wlSel  = curPage.querySelector('#wl-bulk-action');
    var wlWrap = curPage.querySelector('#wl-move-to-wrap');
    if (wlSel && wlWrap) {
      wlSel.addEventListener('change', function () {
        wlWrap.style.display = wlSel.value === 'move' ? 'inline-flex' : 'none';
      });
    }
  }

  function doSearch(input) {
    var form = input.form;
    if (!form || form.method.toLowerCase() !== 'get') return;

    var url = buildUrl(form);
    if (pending) { pending.abort(); }
    pending = new AbortController();

    fetch(url, { signal: pending.signal })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        pending = null;
        swapResults(input, html);
        history.pushState(null, '', url);
      })
      .catch(function () {});  // AbortError on rapid keystrokes is expected
  }

  document.querySelectorAll('input[name="search"]').forEach(function (input) {
    if (!input.form || input.form.method.toLowerCase() !== 'get') return;
    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () { doSearch(input); }, DELAY);
    });
  });
}());

// ── 50. Affiliates index: drag-and-drop row reorder ───────────────────────────
//
// Active when the table carries [data-aff-reorder-url], set only when the
// approved/inactive tab is sorted by sort_order ASC with no active filters.

(function () {
  var table = document.querySelector('table[data-aff-reorder-url]');
  if (!table) return;

  var reorderUrl = table.dataset.affReorderUrl;
  var csrf       = table.dataset.affCsrf;
  var scope      = table.dataset.affScope;
  var tab        = table.dataset.affTab;
  var page       = table.dataset.affPage    || '1';
  var perPage    = table.dataset.affPerPage || '10';
  var tbody      = table.querySelector('tbody');
  var dragged    = null;

  tbody.addEventListener('dragstart', function (e) {
    var row = e.target.closest('tr[data-id]');
    if (!row) return;
    dragged = row;
    row.classList.add('dnd-dragging');
    e.dataTransfer.effectAllowed = 'move';
  });

  tbody.addEventListener('dragover', function (e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    var row = e.target.closest('tr[data-id]');
    if (!row || row === dragged) return;
    tbody.querySelectorAll('tr').forEach(function (r) { r.classList.remove('dnd-over'); });
    row.classList.add('dnd-over');
    var rect = row.getBoundingClientRect();
    if (e.clientY < rect.top + rect.height / 2) {
      tbody.insertBefore(dragged, row);
    } else {
      tbody.insertBefore(dragged, row.nextSibling);
    }
  });

  tbody.addEventListener('drop', function (e) { e.preventDefault(); });

  tbody.addEventListener('dragend', function () {
    if (dragged) dragged.classList.remove('dnd-dragging');
    tbody.querySelectorAll('tr').forEach(function (r) { r.classList.remove('dnd-over'); });
    dragged = null;

    var ids = [];
    tbody.querySelectorAll('tr[data-id]').forEach(function (r) {
      ids.push(parseInt(r.dataset.id, 10));
    });

    var params = new URLSearchParams();
    params.append('_csrf',    csrf);
    params.append('scope',    scope);
    params.append('tab',      tab);
    params.append('page',     page);
    params.append('per_page', perPage);
    ids.forEach(function (id) { params.append('ids[]', id); });

    fetch(reorderUrl, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    params.toString(),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          window.lstrToast('success', 'Order saved.');
        } else {
          window.lstrToast('error', data.error || 'Reorder failed.');
        }
      })
      .catch(function () {
        window.lstrToast('error', 'Reorder failed.');
      });
  });
}());

// ── 50b. Affiliates embed: Click to Reorder / Exit Reorder JS toggle ──────────
// Normalizes sort_order to match current display order before enabling drag,
// so the sequence is consistent between normal view and reorder mode.

(function () {
  var startBtn = document.getElementById('aff-embed-reorder-start');
  var exitBtn  = document.getElementById('aff-embed-reorder-exit');
  if (!startBtn || !exitBtn) return;

  var table = document.querySelector('table[data-aff-embed]');
  if (!table) return;

  function activate() {
    table.classList.remove('aff-drag-off');
    startBtn.style.display = 'none';
    exitBtn.style.display  = '';
  }

  startBtn.addEventListener('click', function () {
    var ids = Array.from(table.querySelectorAll('tbody tr[data-id]'))
                   .map(function (r) { return parseInt(r.dataset.id, 10); })
                   .filter(Boolean);

    if (!ids.length) { activate(); return; }

    var params = new URLSearchParams();
    params.append('_csrf',    table.dataset.affCsrf);
    params.append('scope',    table.dataset.affScope);
    params.append('tab',      table.dataset.affTab);
    params.append('page',     table.dataset.affPage     || '1');
    params.append('per_page', table.dataset.affPerPage  || '10');
    ids.forEach(function (id) { params.append('ids[]', id); });

    fetch(table.dataset.affReorderUrl, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    params.toString(),
    })
    .then(activate)
    .catch(activate);
  });

  exitBtn.addEventListener('click', function () {
    table.classList.add('aff-drag-off');
    exitBtn.style.display  = 'none';
    startBtn.style.display = '';
  });
}());

// ── 50c. Main affiliates page: normalize sort_order on Click to Reorder ────────
// Intercepts the URL-based "Click to Reorder" hint link, saves current displayed
// order to sort_order, then navigates — so entering reorder mode shows the same
// sequence the user already sees.

(function () {
  var hintLink = document.getElementById('aff-reorder-hint-btn');
  if (!hintLink) return;

  hintLink.addEventListener('click', function (e) {
    var ids = Array.from(document.querySelectorAll('#aff-bulk-form input[name="selected[]"]'))
                   .map(function (cb) { return parseInt(cb.value, 10); })
                   .filter(Boolean);

    if (!ids.length) return; // let the link navigate normally

    e.preventDefault();
    var href = hintLink.href;

    var params = new URLSearchParams();
    params.append('_csrf',    hintLink.dataset.csrf);
    params.append('scope',    hintLink.dataset.scope);
    params.append('tab',      hintLink.dataset.tab);
    params.append('page',     hintLink.dataset.page    || '1');
    params.append('per_page', hintLink.dataset.perPage || '10');
    ids.forEach(function (id) { params.append('ids[]', id); });

    fetch(hintLink.dataset.reorderUrl, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    params.toString(),
    })
    .then(function ()  { window.location.href = href; })
    .catch(function () { window.location.href = href; });
  });
}());

// ── Settings: Check for Updates inline result ─────────────────────────────────

(function () {
  var btn = document.getElementById('update-check-btn');
  if (!btn) return;
  var result = document.getElementById('update-check-result');

  btn.addEventListener('click', function () {
    btn.disabled = true;
    if (result) result.innerHTML = '<span class="text-muted">Checking&hellip;</span>';

    var params = new URLSearchParams();
    params.append('_csrf', btn.dataset.csrf);

    fetch(btn.dataset.url, {
      method:  'POST',
      headers: {
        'Content-Type':     'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: params.toString(),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;
        if (result) {
          result.innerHTML = data.ok
            ? '<span style="color:var(--color-success,#4caf50)">' + esc(data.message) + '</span>'
            : '<span style="color:var(--color-danger,#e05)">'     + esc(data.message) + '</span>';
        }
      })
      .catch(function () {
        btn.disabled = false;
        if (result) result.innerHTML = '<span style="color:var(--color-danger,#e05)">Check failed. Please try again.</span>';
      });
  });
}());

// ── Server Paths: locked inputs with Edit button to unlock ───────────────────

document.addEventListener('click', function (e) {
  var btn = e.target.closest('.path-edit-btn');
  if (!btn) return;
  var input = document.getElementById(btn.dataset.target);
  if (!input) return;
  input.disabled = false;
  input.readOnly = false;
  input.removeAttribute('readonly');
  input.focus();
  btn.remove();
});

// ── Affiliate message read-more / collapse ────────────────────────────────────

document.addEventListener('click', function (e) {
  var btn = e.target.closest('.msg-read-more, .msg-collapse');
  if (!btn) return;
  var cell = btn.closest('td');
  if (!cell) return;
  var ellipsis = cell.querySelector('.msg-ellipsis');
  var tail     = cell.querySelector('.msg-tail');
  if (!ellipsis || !tail) return;
  e.preventDefault();
  var expanding = btn.classList.contains('msg-read-more');
  ellipsis.hidden = expanding;
  tail.hidden     = !expanding;
});

// Listing edit: compatibility-wrapper subtabs.
document.addEventListener('click', function (e) {
  var link = e.target.closest('#compat-wrappers .tab-link');
  if (!link) return;

  var section = document.getElementById('compat-wrappers');
  var target  = link.dataset.target;
  var panel   = target ? document.getElementById(target) : null;
  if (!section || !panel) return;

  e.preventDefault();
  section.querySelectorAll('.tab-link').forEach(function (item) {
    item.classList.remove('tab-link--active');
  });
  section.querySelectorAll('.compat-panel').forEach(function (item) {
    item.style.display = 'none';
  });

  link.classList.add('tab-link--active');
  panel.style.display = '';
});

// Listing edit: dependent rows in the embed settings tab.
(function () {
  var affiliateApplyToggle = document.getElementById('fl-affiliate-apply-enabled');
  var affiliateApplyRow    = document.getElementById('fl-aff-form-url-row');
  if (affiliateApplyToggle && affiliateApplyRow) {
    affiliateApplyToggle.addEventListener('change', function () {
      affiliateApplyRow.style.display = affiliateApplyToggle.checked ? '' : 'none';
    });
  }

  var codesSubmitToggle = document.getElementById('fl-codes-submit-enabled');
  var codesSubmitRow    = document.getElementById('fl-codes-submit-embed-row');
  if (codesSubmitToggle && codesSubmitRow) {
    codesSubmitToggle.addEventListener('change', function () {
      codesSubmitRow.style.display = codesSubmitToggle.checked ? '' : 'none';
    });
  }
}());

// Rules edit: Gate Style + Gate Label rows only apply when the gate is enabled.
(function () {
  var enabledToggle = document.getElementById('rules-enabled');
  var gateStyleRow  = document.getElementById('rules-gate-style-row');
  var gateLabelRow  = document.getElementById('rules-gate-label-row');
  if (!enabledToggle || !gateStyleRow || !gateLabelRow) return;

  function toggle() {
    var show = enabledToggle.checked;
    gateStyleRow.style.display = show ? '' : 'none';
    gateLabelRow.style.display = show ? '' : 'none';
  }
  enabledToggle.addEventListener('change', toggle);
  toggle();
}());
