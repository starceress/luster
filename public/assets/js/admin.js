/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin panel JavaScript. Handles theme toggling, toast notifications,
 nav search, confirmation dialogs, and all other interactive behavior
 across the admin panel. Loaded at the bottom of layout.php and all
 standalone auth pages. Every function guards against missing DOM
 elements so it's safe to load unconditionally on every admin page.
*/

'use strict';

// ── 0. Shared utilities ──────────────────────────────────────────────────────
// Defined once at global scope so all IIFEs below can share them without
// re-declaring.

// Global: .modal-close buttons auto-dismiss their parent .modal-backdrop.
// Modals with data-managed-close handle their own dismiss (focus-restore, etc.).
document.addEventListener('click', function (e) {
  var btn = e.target.closest('.modal-close');
  if (!btn) return;
  var backdrop = btn.closest('.modal-backdrop');
  if (backdrop && !backdrop.hasAttribute('data-managed-close')) backdrop.style.display = 'none';
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

// ── 6b. Remember Me checkbox persistence ─────────────────────────────────────

(function () {
  var cb = document.getElementById('remember_me');
  if (!cb) return;
  if (localStorage.getItem('lstr_remember_me') === '1') cb.checked = true;
  cb.addEventListener('change', function () {
    localStorage.setItem('lstr_remember_me', cb.checked ? '1' : '0');
  });
}());

// ── 6c. Checkbox → row visibility toggle ([data-toggle-row]) ─────────────────

(function () {
  document.querySelectorAll('[data-toggle-row]').forEach(function (cb) {
    var ids = cb.getAttribute('data-toggle-row').split(',');
    var targets = ids.map(function (id) { return document.getElementById(id.trim()); }).filter(Boolean);
    if (!targets.length) return;
    cb.addEventListener('change', function () {
      var show = cb.checked ? '' : 'none';
      targets.forEach(function (t) { t.style.display = show; });
    });
  });
}());

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
        addInput(form, 'collective',  btn.dataset.globalApproveCollective || '0');
        addInput(form, '_tab',        'pending');
        document.body.appendChild(form);
        form.submit();
      });
    });
  });

  // Same nested-form problem as above, for the per-row Approve/Reject buttons
  // inside each listing's bulk-action <form> on the global pending-codes view.
  document.querySelectorAll('[data-code-row-url]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form = document.createElement('form');
      form.method = 'POST';
      form.action = btn.dataset.codeRowUrl;
      form.style.display = 'none';
      addInput(form, '_csrf',        btn.dataset.codeRowCsrf);
      addInput(form, 'listing_id',   '0');
      addInput(form, '_tab',         'pending');
      addInput(form, '_from_global', '1');
      document.body.appendChild(form);
      form.submit();
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
    if (title) title.textContent = label.dataset.defaultTitle || 'Drag & drop or click to browse';
    if (hint) hint.textContent = label.dataset.defaultHint || '';
    label.classList.remove('is-ready');
  }
});

document.addEventListener('dragover', function (e) {
  var label = e.target.closest('.file-drop-zone');
  if (!label) return;
  e.preventDefault();
  label.classList.add('is-dragover');
});

document.addEventListener('dragleave', function (e) {
  var label = e.target.closest('.file-drop-zone');
  if (!label) return;
  if (!label.contains(e.relatedTarget)) {
    label.classList.remove('is-dragover');
  }
});

document.addEventListener('drop', function (e) {
  var label = e.target.closest('.file-drop-zone');
  if (!label) return;
  e.preventDefault();
  label.classList.remove('is-dragover');

  var input = label.querySelector('input[type="file"]');
  if (!input) return;

  var dt    = e.dataTransfer;
  var files = dt ? dt.files : null;
  if (!files || files.length === 0) return;

  if (!input.multiple) {
    var single = files[0];
    var dts = new DataTransfer();
    dts.items.add(single);
    input.files = dts.files;
  } else {
    var dtm = new DataTransfer();
    for (var i = 0; i < files.length; i++) { dtm.items.add(files[i]); }
    input.files = dtm.files;
  }

  input.dispatchEvent(new Event('change', { bubbles: true }));
});

// ── 24a-ii. Image upload panel: immediate delete with confirmation modal ──────
// Shared by the Listing/Affiliate/Joined/Wishlist edit forms' "Delete Image"
// button — only one such form is ever on screen at a time, so reusing the
// same #fl-delete-image-btn / #fl-img-current-card ids across those pages is
// safe. data-image-entity/data-max-size-hint let each page customize the
// confirm-modal wording and the reset hint text without duplicating this
// handler; both default to the Listing page's original values.
(function () {
  var deleteBtn = document.getElementById('fl-delete-image-btn');
  if (!deleteBtn) return;

  deleteBtn.addEventListener('click', function () {
    var url      = deleteBtn.dataset.deleteUrl || '';
    var csrf     = deleteBtn.dataset.csrf || '';
    var entity   = deleteBtn.dataset.imageEntity || 'listing_image';
    var sizeHint = deleteBtn.dataset.maxSizeHint || 'JPEG, PNG, GIF, WebP — max 2 MB';
    if (!url) return;

    window.lstrDelModal.trigger(function () {
      var body = new URLSearchParams();
      body.append('_csrf', csrf);

      fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok) {
            window.lstrToast('success', 'Image deleted.');
            var panel = document.getElementById('fl-img-current-card');
            if (panel) panel.closest('.img-upload-panel--two-col').outerHTML =
              '<div class="img-upload-panel">'
              + '<label class="file-drop-zone">'
              + '<input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">'
              + '<span class="file-drop-icon"></span>'
              + '<strong>Drag &amp; drop or click to browse</strong>'
              + '<span class="form-hint">' + esc(sizeHint) + '</span>'
              + '</label></div>';
          } else {
            window.lstrToast('error', data.error || 'Could not delete image.');
          }
        })
        .catch(function () { window.lstrToast('error', 'Request failed.'); });
    }, entity, '', '', '');
  });
}());

// ── 24b. Listings edit: status pill toggle ────────────────────────────────────
// Active state is handled by CSS :has(input:checked) on .status-pill--* classes.

// Local (not UTC) today as YYYY-MM-DD. new Date().toISOString() is UTC, which
// can silently roll over to tomorrow's date for anyone west of UTC in the
// evening — used by both the Last Updated and Closed/Adopted On "Set Today's
// Date" buttons below.
function lstrTodayIso() {
  var d = new Date();
  var mm = String(d.getMonth() + 1).padStart(2, '0');
  var dd = String(d.getDate()).padStart(2, '0');
  return d.getFullYear() + '-' + mm + '-' + dd;
}

// ── 25. Listings edit: last-updated date set/clear buttons ────────────────────

(function () {
  var setBtn   = document.getElementById('set-today-btn');
  var clearBtn = document.getElementById('clear-date-btn');
  if (setBtn) {
    setBtn.addEventListener('click', function () {
      var f = document.getElementById('fl-last-updated');
      if (f) f.value = lstrTodayIso();
    });
  }
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      var f = document.getElementById('fl-last-updated');
      if (f) f.value = '';
    });
  }
}());

// ── 25b. Listings edit/add: Closed/Adopted On date set-today/reset buttons ────
//
// "Reset" restores the field to its data-original-value (the real last-saved
// date on Edit, or blank on Add — see edit.php/add.php) rather than blanking
// it: blank-on-submit means "keep the current stored date" at the repo layer
// (ListingLifecycleRepo::upsert()), so a button that visibly blanked the field
// would misleadingly suggest the date gets cleared when saving would actually
// silently restore the old value.

(function () {
  var setBtn   = document.getElementById('set-archived-today-btn');
  var resetBtn = document.getElementById('reset-archived-date-btn');
  if (setBtn) {
    setBtn.addEventListener('click', function () {
      var f = document.getElementById('fl-archived-at');
      if (f) f.value = lstrTodayIso();
    });
  }
  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      var f = document.getElementById('fl-archived-at');
      if (f) f.value = f.dataset.originalValue || '';
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

// ── 28b. Categories edit: "New Network" row + "Other (add new)" name field ────

(function () {
  var addSel  = document.querySelector('select[name="add_network"]');
  var repSel  = document.getElementById('replacement-network');
  var repRow  = document.getElementById('replacement-network-row');
  var nameTxt = document.getElementById('cat-network');
  if (!addSel || !repSel || !repRow || !nameTxt) return;

  function toggleName() {
    nameTxt.style.display = repSel.value === 'other' ? '' : 'none';
  }
  function toggleReplacement() {
    repRow.classList.toggle('is-hidden', addSel.value !== 'yes');
    toggleName();
  }

  addSel.addEventListener('change', toggleReplacement);
  repSel.addEventListener('change', toggleName);
  toggleReplacement();
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
      listing_title:    md.listingName    || '',
      listing_subject: md.listingSubject || '',
      listing_type:    md.listingType    || 'listing',
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
  var isCollective = pd.dataset.isCollective === '1';
  var siteName = pd.dataset.siteName || 'Luster';
  var tokens = {
    '{fan_name}':        'Bruce Wayne',
    '{fan_email}':       'bruce@waynetech.net',
    '{fan_url}':         'https://waynetech.net/bruce',
    '{fan_country}':     'United States',
    '{fan_password}':    'darkknight123',
    '{reset_url}':       'https://batmanfan.com/reset/1/confirm?token=abc123',
    '{listing_title}':   pd.dataset.listingName || 'The Dark Knight',
    '{listing_subject}': pd.dataset.listingSubject || 'Batman',
    '{listing_type}':    pd.dataset.listingType    || 'fanlisting',
    '{listing_url}':     pd.dataset.listingUrl     || 'https://batmanfan.com/',
    '{join_url}':        pd.dataset.listingUrl     || 'https://batmanfan.com/join',
    '{update_url}':      pd.dataset.listingUrl     || 'https://batmanfan.com/update',
    '{form_url_join}':   pd.dataset.listingUrl     || 'https://batmanfan.com/join',
    '{form_url_update}': pd.dataset.listingUrl     || 'https://batmanfan.com/update',
    '{form_url_reset}':  pd.dataset.listingUrl     || 'https://batmanfan.com/reset',
    '{site_name}':       siteName,
    '{site_url}':        pd.dataset.siteUrl        || 'https://waynetech.net',
    '{owner_name}':      pd.dataset.ownerName      || 'Alfred',
    '{owner_email}':     pd.dataset.ownerEmail     || 'alfred@waynetech.net',
    '{aff_name}':        'James Gordon',
    '{aff_url}':         'https://gcpd.org/',
    '{aff_email}':       'gordon@gcpd.org',
    '{aff_message}':     "I've had my eye on your site for a while now. It's solid work. The GCPD covers similar ground, and I think our visitors would get along. Think of it as a good old-fashioned alliance. I'll bring the coffee.",
    '{applicant_name}':    'Selina Kyle',
    '{applicant_email}':   'selina@catlady.net',
    '{applicant_url}':     'https://catlady.net/',
    '{applicant_message}': "This listing has piqued my interest... let's just say I have a habit of making things mine. You won't find a better candidate. I've got an eye for design and impeccable taste. You can trust me. Mostly.",
    '{kim_name}':        'Dick Grayson',
    '{kim_email}':       'hello@nightwing.com',
    '{kim_url}':         'https://theboywonder.com/',
    '{kim_password}':    'darkknight123',
    '{kim_update_url}':  'https://batmanfan.com/kim-update.php',
    '{verify_url}':      'https://waynetech.net/update?_luster_cu_token=abc123',
    '{membership_list}': '- The Dark Knight (Batman)\n- Justice League Watchtower (DC Heroes)',
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
    var card   = cb.closest('.card--gradient');
    var banner = card ? card.querySelector('.modal-warning') : null;
    var label  = cb.parentElement ? cb.parentElement.querySelector('.toggle-label') : null;
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

    /* Restore after 5s as a safety net (e.g. server error, back-navigation) */
    setTimeout(function () {
      btn.classList.remove('btn-loading');
      btn.removeAttribute('aria-disabled');
    }, 5000);
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
    '{fan_name}':        'Bruce Wayne',
    '{fan_email}':       'bruce@waynetech.net',
    '{fan_url}':         'https://waynetech.net/bruce',
    '{fan_country}':     'United States',
    '{listing_title}':   'The Dark Knight',
    '{listing_subject}': 'Batman',
    '{listing_type}':    'listing',
    '{listing_url}':     'https://waynetech.net/the-dark-knight',
    '{site_name}':       'Wayne Enterprises',
    '{site_url}':        'https://waynetech.net',
    '{owner_name}':      'Alfred',
    '{owner_email}':     'alfred@waynetech.net',
    '{name}':            'Bruce Wayne',
    '{email}':           'bruce@waynetech.net',
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
  var panels  = {
    main: document.getElementById('ec-panel-main'),
    ref:  document.getElementById('ec-panel-ref'),
  };

  function switchTab(id) {
    tabBtns.forEach(function (b) {
      var on = b.dataset.ecTab === id;
      b.classList.toggle('active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    Object.keys(panels).forEach(function (k) {
      if (panels[k]) panels[k].classList.toggle('active', k === id);
    });
    if (id === 'main') {
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

  // ── Cascading section → embed selectors ───────────────────────────────────
  var pickItems = root.querySelectorAll('.ec-qc-item'); // no-op in new layout; kept for selectItem() loop

  var navGroups = [
    { val: 'l', keys: ['join','members','stats','affiliates','aff-apply','update','reset','codes','codes-submit','contact','newsfeed','rules-gate'] },
    { val: 'c', keys: ['owned','joined','cstats','aff-col','col-aff-apply','random','latest','random-joined','latest-joined','col-codes','col-codes-submit','kim','kim-list','kim-update','kim-reset','col-newsfeed','col-contact','col-update','wishlist','adopt'] },
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
  var headTitle      = document.getElementById('ec-head-title');
  var headBadge      = document.getElementById('ec-head-badge');
  var headDesc       = document.getElementById('ec-head-desc');
  var codePre        = document.getElementById('ec-code-pre');
  var codeCpBtn      = document.getElementById('ec-code-cp');
  var prevBtn        = document.getElementById('ec-prev-btn');
  var prevModal      = document.getElementById('ec-prev-modal');
  var prevIframe     = document.getElementById('ec-prev-iframe');
  var prevModalTitle = document.getElementById('ec-prev-modal-title');
  var prevNewTab     = document.getElementById('ec-prev-newtab');
  var prevCloseBtn   = document.getElementById('ec-prev-close');
  var optsBody       = document.getElementById('ec-opts-body');
  var optsBadge      = document.getElementById('ec-opts-badge');

  // ── Preview modal open / close ─────────────────────────────────────────────
  var cstatsSampleValues = {
    luster_owned_active: '12', luster_owned_upcoming: '2', luster_owned_pending: '1', luster_owned: '15',
    luster_joined_active: '48', luster_joined_approved: '45', luster_joined_upcoming: '3', luster_joined_pending: '0', luster_joined: '48',
    luster_affiliates: '8', luster_affiliates_sitewide: '8', luster_affiliates_total: '22', luster_members_approved: '3,450', luster_members_pending: '12',
    luster_countries_count: '27', luster_total_cats: '9', luster_joined_total_cats: '6',
    luster_owned_growth_rate: '2.50', luster_joined_growth_rate: '4.80', luster_members_growth_rate: '1.25',
  };

  function buildCstatsVarPreview(vars) {
    var rows = vars.map(function (v) {
      return '<tr><td style="font-family:monospace;padding:4px 12px 4px 0;color:#889;white-space:nowrap">$' + v.name + '</td>'
           + '<td style="padding:4px 0;font-weight:600">' + (cstatsSampleValues[v.name] || '—') + '</td></tr>';
    }).join('');
    return '<!DOCTYPE html><html><head><meta charset="utf-8">'
      + '<style>body{font-family:system-ui,sans-serif;font-size:14px;padding:1.5rem;margin:0;background:#fff;color:#333}'
      + 'table{border-collapse:collapse}td{border-bottom:1px solid #eee}</style></head><body>'
      + '<h3 style="margin:0 0 1rem">Selected Variables (sample data)</h3>'
      + '<table>' + rows + '</table>'
      + '<p style="margin-top:1rem;font-size:12px;color:#999">These values are samples. Your page will show live data from your collective.</p>'
      + '</body></html>';
  }

  function isCstatsVarMode() {
    return currentKey === 'cstats' && Array.isArray(custVals.vars) && custVals.vars.length > 0;
  }

  function applyPreviewSource() {
    if (!prevIframe) return;
    if (isCstatsVarMode()) {
      prevIframe.srcdoc = buildCstatsVarPreview(custVals.vars);
      prevIframe.removeAttribute('src');
      if (prevNewTab) prevNewTab.style.display = 'none';
    } else {
      var url = buildPreviewUrl();
      if (!url) return;
      prevIframe.removeAttribute('srcdoc');
      prevIframe.src = url;
      if (prevNewTab) { prevNewTab.href = url; prevNewTab.style.display = ''; }
    }
  }

  function openPreview() {
    if (!prevModal || !prevIframe) return;
    if (prevModalTitle) prevModalTitle.textContent = (items[currentKey] || {}).label || 'Preview';
    applyPreviewSource();
    prevModal.style.display = 'flex';
    prevModal.classList.remove('is-hidden');
  }

  function closePreview() {
    if (!prevModal) return;
    prevModal.style.display = 'none';
    if (prevIframe) prevIframe.src = '';
  }

  if (prevBtn) prevBtn.addEventListener('click', openPreview);
  if (prevCloseBtn) prevCloseBtn.addEventListener('click', closePreview);

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
    update: 'update.php', reset: 'reset.php',
    affiliates: 'affiliates.php', owned: 'owned.php', joined: 'joined.php',
    cstats: 'collective-stats.php', 'aff-col': 'affiliates.php', 'col-aff-apply': 'affiliate-apply.php', 'col-codes': 'codes.php', 'col-codes-submit': 'collective-codes-submit.php',
    'aff-apply': 'affiliate-apply.php',
    random: 'random-listing.php', latest: 'latest-listing.php',
    'random-joined': 'random-joined-listing.php', 'latest-joined': 'latest-joined-listing.php',
    kim: 'kim-signup.php', 'kim-list': 'kim-list.php', 'kim-update': 'kim-update.php', 'kim-reset': 'kim-reset.php',
    codes: 'codes.php', 'codes-submit': 'codes-submit.php',
    contact: 'contact.php', newsfeed: 'newsfeed.php', adopt: 'adopt.php',
    'col-newsfeed': 'collective-newsfeed.php', 'col-contact': 'collective-contact.php',
    'col-update': 'collective-update.php',
    wishlist: 'wishlist.php', 'rules-gate': 'rules-gate.php',
  };

  function generateCode(type, vals) {
    var lines = [];
    lines.push('<?php');
    // Listing ID
    var needsId = ['join','members','stats','update','reset','aff-apply','codes','codes-submit','contact','newsfeed','rules-gate'];
    if (needsId.indexOf(type) !== -1) {
      var lid = parseInt(vals.lid || (listings.length ? listings[0].id : 1), 10) || 1;
      if (type === 'aff-apply') lines.push('// Enable in Admin → Listings → Edit → Affiliates');
      if (type === 'newsfeed')  lines.push('// Requires Newsfeed add-on (Admin → Add-ons)');
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
    } else if (type === 'adopt') {
      lines.push('// Requires Adoption add-on (Admin → Add-ons)');
    }
    // Type-specific options
    if (type === 'members') {
      if (vals.per_page && vals.per_page !== '') lines.push('$luster_per_page     = ' + (parseInt(vals.per_page, 10) || 25) + ';');
      if (vals.sort_by)                                                    lines.push('$luster_sort_by      = ' + phpStr(vals.sort_by) + ';');
      if (vals.show_search === 'false')                                    lines.push('$luster_show_search  = false;');
      if (vals.date_format)                                                lines.push('$luster_date_format  = ' + phpStr(vals.date_format) + ';');
    }
    if (type === 'affiliates') {
      if (vals.per_page && vals.per_page !== '')       lines.push('$luster_per_page        = ' + (parseInt(vals.per_page, 10) || 50) + ';');
    }
    if (type === 'owned') {
      if (vals.display_mode === 'browse')     lines.push('$luster_browse_by_category = true;');
      if (vals.display_mode === 'none')       lines.push('$luster_hide_dropdown      = true;');
      if (vals.status)                        lines.push('$luster_status             = ' + phpStr(vals.status) + ';');
      if (vals.exclude_pending === 'false')   lines.push('$luster_exclude_pending    = false;');
      if (vals.archived_categories === 'closed' || vals.archived_categories === 'both')
        lines.push('$luster_show_closed        = true;');
      if (vals.archived_categories === 'adopted_out' || vals.archived_categories === 'both')
        lines.push('$luster_show_adopted_out   = true;');
    }
    if (type === 'joined') {
      if (vals.display_mode === 'browse')     lines.push('$luster_browse_by_category = true;');
      if (vals.display_mode === 'all')        lines.push('$luster_category           = ' + phpStr('all') + ';');
      if (vals.show_all_by_default === 'true') lines.push('$luster_show_all_by_default = true;');
    }
    if (type === 'codes' || type === 'col-codes') {
      if (vals.per_page && vals.per_page !== '') lines.push('$luster_per_page = ' + (parseInt(vals.per_page, 10) || 20) + ';');
    }
    if (type === 'random' && vals.status) lines.push('$luster_status = ' + phpStr(vals.status) + ';');
    if (type === 'newsfeed' || type === 'col-newsfeed') {
      if (vals.per_page && vals.per_page !== '') lines.push('$luster_per_page  = ' + (parseInt(vals.per_page, 10) || 20) + ';');
      if (vals.show_cats === 'false')            lines.push('$luster_show_cats = false;');
    }
    if (type === 'wishlist') {
      if (vals.wishlist_type && vals.wishlist_type !== 'all') lines.push('$luster_wishlist_type = ' + phpStr(vals.wishlist_type) + ';');
      if (vals.per_page && vals.per_page !== '')              lines.push('$luster_per_page      = ' + (parseInt(vals.per_page, 10) || 25) + ';');
    }
    if (type === 'kim-list') {
      if (vals.only_with_signups === 'false')    lines.push('$luster_kim_only_with_signups = false;');
      if (vals.per_page && vals.per_page !== '') lines.push('$luster_per_page              = ' + (parseInt(vals.per_page, 10) || 25) + ';');
    }
    // Stats template override — builds a PHP concatenation string.
    // Tokens with join:true append to the previous line (no <br>).
    if (type === 'stats' && Array.isArray(vals.tpl) && vals.tpl.length > 0) {
      var esc = function (s) { return s.replace(/\\/g,'\\\\').replace(/'/g,"\\'"); };
      // Group consecutive join tokens onto the same segment
      var segments = [];
      vals.tpl.forEach(function (t) {
        if (t.join && segments.length > 0) {
          segments[segments.length - 1] += t.line;
        } else {
          segments.push(t.line);
        }
      });
      if (segments.length === 1) {
        lines.push('$luster_stats_template = ' + phpStr(segments[0]) + ';');
      } else {
        lines.push('$luster_stats_template = ' + phpStr(segments[0]));
        for (var ti = 1; ti < segments.length - 1; ti++) {
          lines.push("  . '<br>" + esc(segments[ti]) + "'");
        }
        lines.push("  . '<br>" + esc(segments[segments.length - 1]) + "';");
      }
    }
    // Date format override
    if (['stats','owned','joined','random','latest','random-joined','latest-joined'].indexOf(type) !== -1 && vals.date_format) {
      lines.push('$luster_date_format = ' + phpStr(vals.date_format) + ';');
    }
    // Require line
    if (type === 'kim' || type === 'kim-list' || type === 'kim-update' || type === 'kim-reset')
                                lines.push('// Requires KIM List add-on (Admin → Add-ons)');
    if (type === 'col-newsfeed') lines.push('// Requires Newsfeed add-on (Admin → Add-ons)');
    if (type === 'wishlist')    lines.push('// Requires Wishlist add-on (Admin → Add-ons)');
    if (type === 'latest')      lines.push('// Renders the listing with the most recent open date.');
    if (type === 'random-joined') lines.push('// Renders one active, approved joined listing at random.');
    if (type === 'latest-joined') lines.push('// Renders the joined listing with the most recent join date.');
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
    // Collective stats display lines — when the user picks individual
    // variables, suppress the auto-echoed HTML block so only the raw
    // variables are output on the host page.
    if (type === 'cstats') {
      var selVars = Array.isArray(vals.vars) ? vals.vars : [];
      if (selVars.length > 0) {
        // Insert $luster_return = true before the require so the rendered
        // HTML block is captured but not echoed — the user echoes only the
        // variables they selected.
        var reqIdx = lines.findIndex(function (l) { return l.indexOf("require LUSTER_EMBED") !== -1; });
        if (reqIdx !== -1) {
          lines.splice(reqIdx, 0, '$luster_return = true;');
        }
        lines.push('$luster_return = false;');
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
        { id: 'per_page',     label: 'Members per page',        type: 'number', ph: '25', hint: 'How many members to show per page. Leave blank to use your per-listing/global default, or 25 if neither is set.' },
        { id: 'sort_by',      label: 'Start sorted by',         type: 'select', opts: [['country','Country'],['name','Name'],['added','Date Joined']], hint: '' },
        { id: 'show_search',  label: 'Sort and filter tools',   type: 'select', opts: [['','Show'],['false','Hide']], hint: 'Shows or hides the sort links (Date Joined / Name / Country) above the member list.' },
        { id: 'date_format', label: 'Date format', type: 'select',
          opts: [['','Automatic'],['F j, Y','November 15, 2026'],['M j, Y','Nov 15, 2026'],['Y-m-d','2026-11-15']],
          hint: 'How each member\'s join date ({fan_added}) appears.' },
      ],
    },
    stats: {
      fields: [
        { id: 'lid', label: 'Listing', type: 'listing', hint: '' },
        { id: 'tpl', label: 'Stats to show', type: 'tokens',
          hint: 'Choose stats for this snippet, or leave everything unchecked to use the stats template saved in Luster.',
          tokens: [
            { token: '{stat_approved}',     label: 'Approved members',              line: '<b>Members:</b> {stat_approved} total' },
            { token: '{if_stat_pending}',   label: 'Pending only when present',     line: ' {if_stat_pending}(+{stat_pending} pending){/if_stat_pending}', join: true },
            { token: '{stat_countries}',    label: 'Countries represented',         line: ' across {stat_countries} countries', join: true },
            { token: '{stat_opened}',       label: 'Date opened',                   line: '<b>Opened On:</b> {stat_opened}' },
            { token: '{stat_updated}',      label: 'Most recent activity date',     line: '<b>Last Updated:</b> {stat_updated}' },
            { token: '{stat_newmembers}',   label: 'Newest members (linked)', line: '<b>Newest Members:</b> {stat_newmembers}' },
            { token: '{stat_growth}',       label: 'Growth rate with unit',         line: '<b>Growth:</b> {stat_growth}' },
            { token: '{stat_growth_rate}',  label: 'Growth rate (number only)',     line: '' },
            { token: '{approved_by}',       label: 'Approved-by network',           line: '{if_approved_by}<b>Approved By:</b> {approved_by}{/if_approved_by}' },
            { token: '{previous_owners}',   label: 'Previous owners',               line: '{if_previous_owners}<b>Previously owned by:</b> {previous_owners}{/if_previous_owners}' },
          ],
        },
        { id: 'date_format', label: 'Date format', type: 'select',
          opts: [['','Automatic'],['F j, Y','November 15, 2026'],['M j, Y','Nov 15, 2026'],['Y-m-d','2026-11-15']],
          hint: 'How dates appear in the stats snippet.' },
      ],
    },
    affiliates: {
      fields: [
        { id: 'lid',             label: 'Listing',             type: 'listing_or_zero', hint: 'Choose Collective for site-wide affiliates.' },
        { id: 'per_page',        label: 'Affiliates per page', type: 'number', ph: '', hint: 'Leave blank to show all affiliates at once. Enter a number to paginate — e.g. 20 shows 20 affiliates per page.' },
      ],
    },
    'aff-apply': {
      fields: [{ id: 'lid', label: 'Listing', type: 'listing', hint: '' }],
    },
    update: {
      fields: [{ id: 'lid', label: 'Listing', type: 'listing', hint: '' }],
    },
    reset: {
      fields: [
        { id: 'lid', label: 'Listing', type: 'listing', hint: '' },
      ],
    },
    owned: {
      fields: [
        { id: 'display_mode', label: 'How should visitors browse?', type: 'select', opts: [['browse','List view'],['','Dropdown view'],['none','No categories']], hint: 'List view: visitors click a category name to see its listings. Dropdown view: a dropdown menu lets visitors filter by category. No categories: just show all listings.' },
        { id: 'status',             label: 'Listings shown',        type: 'select', opts: [['','Current only'],['upcoming','Upcoming only'],['pending','Pending only'],['past','Past fanlistings'],['all','All statuses']], hint: '' },
        { id: 'exclude_pending',    label: 'Pending listings',        type: 'select', opts: [['','Hide when showing all'],['false','Show when showing all']], hint: 'Only matters when "Listings shown" is All statuses.' },
        { id: 'archived_categories', label: 'Closed / Adopted Out categories', type: 'select',
          opts: [['','Hide (default — Past Fanlistings only)'],['closed','Show Closed'],['adopted_out','Show Adopted Out'],['both','Show both']],
          hint: 'Adds separate "Closed"/"Adopted Out" entries to the category nav, alongside the combined Past Fanlistings entry.' },
        { id: 'date_format', label: 'Date format', type: 'select',
          opts: [['','Automatic'],['F j, Y','November 15, 2026'],['M j, Y','Nov 15, 2026'],['Y-m-d','2026-11-15']],
          hint: 'How listing dates appear.' },
      ],
    },
    joined: {
      fields: [
        { id: 'display_mode', label: 'Display', type: 'select', opts: [['browse','List View'],['','Dropdown View'],['all','Show All']], hint: 'Choose whether listings are organized by a category list, a category dropdown, or shown all at once' },
        { id: 'show_all_by_default', label: 'Show All By Default', type: 'select', opts: [['','No'],['true','Yes']], hint: 'Listings are shown all at once and can be filtered through a category dropdown.', showIf: { field: 'display_mode', equals: '' } },
        { id: 'date_format', label: 'Date Format', type: 'select',
          opts: [['','Automatic'],['F j, Y','November 15, 2026'],['M j, Y','Nov 15, 2026'],['Y-m-d','2026-11-15']],
          hint: 'How listing dates appear.' },
      ],
    },
    cstats: {
      fields: [
        { id: 'vars', label: 'Stats to show', type: 'cstatvars',
          hint: 'Choose the stats you want printed by the snippet. People comfortable with PHP can still use the rest manually.',
          vars: [
            { name: 'luster_owned_active',        label: 'Current owned listings',   desc: '', checked: true },
            { name: 'luster_owned_upcoming',      label: 'Upcoming owned listings',  desc: '', checked: true },
            { name: 'luster_owned_pending',       label: 'Pending owned listings',   desc: '' },
            { name: 'luster_owned',               label: 'Total owned listings',     desc: '' },
            { name: 'luster_joined_active',       label: 'Active joined listings',   desc: '', checked: true },
            { name: 'luster_joined_approved',     label: 'Approved joined listings', desc: '' },
            { name: 'luster_joined_upcoming',     label: 'Upcoming joined listings', desc: '' },
            { name: 'luster_joined_pending',      label: 'Pending joined listings',  desc: '' },
            { name: 'luster_joined',              label: 'Total joined listings',    desc: '' },
            { name: 'luster_affiliates_sitewide', label: 'Site-wide affiliates',      desc: '' },
            { name: 'luster_affiliates_total',    label: 'All affiliates (any scope)', desc: '' },
            { name: 'luster_members_approved',    label: 'Approved members',         desc: '', checked: true },
            { name: 'luster_members_pending',     label: 'Pending members',          desc: '', checked: true },
            { name: 'luster_countries_count',     label: 'Countries represented',    desc: '' },
            { name: 'luster_total_cats',          label: 'Owned categories',         desc: '' },
            { name: 'luster_joined_total_cats',  label: 'Joined categories',        desc: '' },
            { name: 'luster_owned_growth_rate',   label: 'Owned listings per year',  desc: '' },
            { name: 'luster_joined_growth_rate',  label: 'Joined listings per year', desc: '' },
            { name: 'luster_members_growth_rate', label: 'Members per day',          desc: '', checked: true },
          ],
        },
      ],
    },
    codes: {
      fields: [
        { id: 'lid',      label: 'Listing',        type: 'listing', hint: '' },
        { id: 'per_page', label: 'Codes per page', type: 'number',  ph: '', hint: 'Leave blank to show all codes at once. Enter a number to paginate — e.g. 20 shows 20 codes per page.' },
      ],
    },
    'codes-submit': {
      fields: [
        { id: 'lid', label: 'Listing', type: 'listing', hint: '' },
      ],
    },
    contact: {
      fields: [{ id: 'lid', label: 'Listing', type: 'listing', hint: '' }],
    },
    newsfeed: {
      fields: [
        { id: 'lid',       label: 'Listing',            type: 'listing', hint: '' },
        { id: 'per_page',  label: 'Entries per page',   type: 'number', ph: '20', hint: 'Leave blank to use the default (20).' },
        { id: 'show_cats', label: 'Category labels',    type: 'select', opts: [['','Show'],['false','Hide']], hint: '' },
      ],
    },
    adopt: {
      fields: [],
      note: 'Visitors choose which listing to apply for from a dropdown in the form.',
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
      fields: [
        { id: 'per_page', label: 'Codes per page', type: 'number', ph: '', hint: 'Leave blank to show all codes at once. Enter a number to paginate — e.g. 20 shows 20 codes per page.' },
      ],
    },
    'col-codes-submit': {
      fields: [],
      note: 'This is already set up for collective code donations.',
    },
    'col-newsfeed': {
      fields: [
        { id: 'per_page',  label: 'Entries per page',   type: 'number', ph: '20', hint: 'Leave blank to use the default (20).' },
        { id: 'show_cats', label: 'Category labels',    type: 'select', opts: [['','Show'],['false','Hide']], hint: '' },
      ],
    },
    'col-contact': {
      fields: [],
    },
    'col-update': {
      fields: [],
      note: 'Does not require a listing selection -- it looks up every fanlisting the entered email belongs to.',
    },
    wishlist: {
      fields: [
        { id: 'wishlist_type', label: 'Wishlist entries to show', type: 'select',
          opts: [['all','All'],['wanted','Wanted'],['top','Top'],['granted','Granted']],
          hint: 'Choose a type, or leave this on All.' },
        { id: 'per_page', label: 'Entries per page', type: 'number', ph: '25',
          hint: 'Counted across all categories combined, even when showing All.' },
      ],
    },
    random: {
      fields: [
        { id: 'status', label: 'Choose from', type: 'select',
          opts: [['current','Current listings'],['upcoming','Upcoming listings'],['all','All listings']], hint: '' },
        { id: 'date_format', label: 'Date format', type: 'select',
          opts: [['','Automatic'],['F j, Y','November 15, 2026'],['M j, Y','Nov 15, 2026'],['Y-m-d','2026-11-15']],
          hint: 'How listing dates appear.' },
      ],
    },
    latest: {
      fields: [
        { id: 'date_format', label: 'Date format', type: 'select',
          opts: [['','Automatic'],['F j, Y','November 15, 2026'],['M j, Y','Nov 15, 2026'],['Y-m-d','2026-11-15']],
          hint: 'How listing dates appear.' },
      ],
    },
    'random-joined': {
      fields: [
        { id: 'date_format', label: 'Date format', type: 'select',
          opts: [['','Automatic'],['F j, Y','November 15, 2026'],['M j, Y','Nov 15, 2026'],['Y-m-d','2026-11-15']],
          hint: 'How listing dates appear.' },
      ],
    },
    'latest-joined': {
      fields: [
        { id: 'date_format', label: 'Date format', type: 'select',
          opts: [['','Automatic'],['F j, Y','November 15, 2026'],['M j, Y','Nov 15, 2026'],['Y-m-d','2026-11-15']],
          hint: 'How listing dates appear.' },
      ],
    },
    kim: {
      fields: [],
      note: 'Set your KIM page links in Settings first. After that, this snippet is ready to copy.',
    },
    'kim-list': {
      fields: [
        { id: 'only_with_signups', label: 'Listings to show', type: 'select', opts: [['','Only with sign-ups'],['false','All listings']], hint: 'By default, only fanlistings that have at least one approved KIM member are shown.' },
        { id: 'per_page', label: 'Entries per page', type: 'number', ph: '25',
          hint: 'Members shown per page when viewing a single fanlisting\'s KIM list.' },
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
      optsBody.innerHTML = '<p class="form-hint">' + (def.note || 'No choices needed. This snippet is ready to copy.') + '</p>';
      return;
    }
    var html = '';
    def.fields.forEach(function (f) {
      // Token grid (stats template builder)
      if (f.type === 'tokens') {
        custVals[f.id] = (f.tokens || []).filter(function (t) { return t.checked; })
          .map(function (t) { return { token: t.token, line: t.line }; });
        html += '<div class="field-row">'
              + '<label class="field-label">' + f.label + '</label>'
              + '<div><div class="ec-token-grid">';
        (f.tokens || []).forEach(function (t) {
          html += '<label class="surface ec-token-item">'
                + '<input type="checkbox" class="ec-tok-cb" data-tokfid="' + esc(f.id) + '"'
                + ' data-tok="' + esc(t.token) + '" data-line="' + esc(t.line) + '"'
                + (t.checked ? ' checked' : '') + '>'
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
        custVals[f.id] = (f.vars || []).filter(function (v) { return v.checked; })
          .map(function (v) { return { name: v.name, label: v.label }; });
        html += '<div class="field-row">'
              + '<label class="field-label">' + f.label + '</label>'
              + '<div><div class="ec-token-grid">';
        (f.vars || []).forEach(function (v) {
          html += '<label class="surface ec-token-item">'
                + '<input type="checkbox" class="ec-var-cb" data-varfid="' + esc(f.id) + '"'
                + ' data-varname="' + esc(v.name) + '" data-varlabel="' + esc(v.label) + '"'
                + (v.checked ? ' checked' : '') + '>'
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
      html += '<div class="field-row" id="ec-row-' + esc(f.id) + '">'
            + '<label class="field-label" for="ec-f-' + esc(f.id) + '">' + f.label + '</label>'
            + '<div>';
      if (f.type === 'listing' || f.type === 'listing_or_zero') {
        var isListingSection = secSel && secSel.value === 'l';
        var defaultToFirst   = f.type === 'listing_or_zero' && isListingSection && listings.length > 0;
        html += '<div class="select-wrap"><select id="ec-f-' + esc(f.id) + '" data-fid="' + esc(f.id) + '">';
        if (f.type === 'listing_or_zero') html += '<option value="0"' + (defaultToFirst ? '' : ' selected') + '>Collective</option>';
        listings.forEach(function (fl, idx) {
          var label = esc(fl.subject || fl.name);
          var sel   = (f.type === 'listing' && idx === 0) || (defaultToFirst && idx === 0) ? ' selected' : '';
          html += '<option value="' + parseInt(fl.id, 10) + '"' + sel + '>' + label + '</option>';
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
    // Show/hide fields whose `showIf` / `hideIf` conditions depend on other
    // fields, resetting any hidden field back to its default so the generated
    // snippet never carries an override for an option the user can no longer see.
    function applyConditionalRows() {
      def.fields.forEach(function (f) {
        if (!f.showIf && !f.hideIf) return;
        var visible = true;
        if (f.showIf) {
          var conds = Array.isArray(f.showIf) ? f.showIf : [f.showIf];
          visible = conds.every(function (c) { return custVals[c.field] === c.equals; });
        }
        if (visible && f.hideIf) {
          var hc = Array.isArray(f.hideIf) ? f.hideIf : [f.hideIf];
          if (hc.every(function (c) { return custVals[c.field] === c.equals; })) visible = false;
        }
        var row = optsBody.querySelector('#ec-row-' + f.id);
        if (row) row.style.display = visible ? '' : 'none';
        if (!visible) {
          var def0 = (f.opts && f.opts[0]) ? f.opts[0][0] : '';
          if (custVals[f.id] !== def0) {
            custVals[f.id] = def0;
            var ctrl = optsBody.querySelector('#ec-f-' + f.id);
            if (ctrl) ctrl.value = def0;
          }
        }
      });
    }
    // Wire standard inputs
    optsBody.querySelectorAll('[data-fid]').forEach(function (el) {
      custVals[el.dataset.fid] = el.value;
      el.addEventListener('input',  function () { custVals[el.dataset.fid] = el.value; applyConditionalRows(); refreshCode(); });
      el.addEventListener('change', function () { custVals[el.dataset.fid] = el.value; applyConditionalRows(); refreshCode(); });
    });
    applyConditionalRows();
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
        if (v !== undefined && v !== def0) n++;
      } else if (v && v !== '') {
        n++;
      }
    });
    return n;
  }

  function refreshCode() {
    currentCode = generateCode(currentKey, custVals);
    if (codePre) codePre.textContent = currentCode;
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
    prevRefreshTimer = setTimeout(function () { applyPreviewSource(); }, 600);
  }

  function buildPreviewUrl() {
    if (!currentKey || !adminBase) return '';
    var url = adminBase + '/admin/embed-codes/preview?key=' + encodeURIComponent(currentKey);
    if (currentKey === 'aff-col' || currentKey === 'col-aff-apply' || currentKey === 'col-codes' || currentKey === 'col-codes-submit') {
      url += '&lid=0';
    } else if (custVals.lid !== undefined && custVals.lid !== '') {
      url += '&lid=' + encodeURIComponent(custVals.lid);
    }
    var skip = { lid: true, vars: true };
    Object.keys(custVals).forEach(function (k) {
      if (k === 'tpl') {
        if (Array.isArray(custVals.tpl) && custVals.tpl.length > 0) {
          url += '&stats_template=' + encodeURIComponent(custVals.tpl.map(function (t) { return t.line; }).join('<br>'));
        }
        return;
      }
      if (skip[k] || typeof custVals[k] !== 'string' || custVals[k] === '') return;
      url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(custVals[k]);
    });
    return url;
  }

  // ── (static preview renderer removed — preview opens via buildPreviewUrl) ───
  function buildPreview(key, vals) {
    var listing = listings[0] || { id: 1, name: 'The Dark Knight' };
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
          + '<label>Country</label><select class="fld fld--select"><option>Japan</option><option>United States</option></select>'
          + '<button class="site-btn">Join the fanlisting</button>';

      case 'members': {
        var showCtrl = vals.show_search !== 'false';
        var sortBy = vals.sort_by || 'country';
        var memberRows = sortBy === 'name'
          ? [['Misty','United States'],['Naminé','Canada'],['Sakura','Japan'],['Usagi','Japan'],['Winry','Germany']]
          : [['Winry','Germany'],['Sakura','Japan'],['Usagi','Japan'],['Naminé','Canada'],['Misty','United States']];
        return '<h4>Members <span style="color:#a39cbb;font-weight:400;font-size:.85rem">(33)</span></h4>'
          + (showCtrl ? '<p class="site-sub">Sort: <a class="site-link" href="#">' + (sortBy === 'name' ? '<u>Name</u>' : 'Name') + '</a> &middot; <a class="site-link" href="#">' + (sortBy !== 'name' ? '<u>Country</u>' : 'Country') + '</a></p>' : '')
          + memberRows.map(function (r) {
              return '<div class="site-row"><a class="site-link" href="#">' + r[0] + '</a><span class="site-flag">' + r[1] + '</span></div>';
            }).join('')
          + '<div class="site-pager"><span class="on">1</span><span>2</span><span>3</span><span>&rsaquo;</span></div>';
      }

      case 'stats': {
        var statsDfMap = {'F j, Y':'November 15, 2026','M j, Y':'Nov 15, 2026','Y-m-d':'2026-11-15'};
        var statsDfNote = vals.date_format && statsDfMap[vals.date_format] ? '<div class="site-note">Dates: ' + statsDfMap[vals.date_format] + '</div>' : '';
        return '<h4>' + name + ' &mdash; Statistics</h4>'
          + '<div class="site-stats">'
          + '<div class="site-stat"><span class="k">Members</span><span class="v">33</span></div>'
          + '<div class="site-stat"><span class="k">Pending</span><span class="v">2</span></div>'
          + '<div class="site-stat"><span class="k">Opened</span><span class="v">Jan 29, 2026</span></div>'
          + '<div class="site-stat"><span class="k">Updated</span><span class="v">May 12, 2026</span></div>'
          + '<div class="site-stat"><span class="k">Growth</span><span class="v">0.31/day</span></div>'
          + '<div class="site-stat"><span class="k">Newest</span><span class="v">Usagi</span></div>'
          + '</div>'
          + '<div class="site-note">Shown using the stats template saved in Luster.</div>'
          + statsDfNote;
      }

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

      case 'newsfeed':
      case 'col-newsfeed': {
        var showCats = vals.show_cats !== 'false';
        return '<h4>' + (key === 'col-newsfeed' ? 'News Feed' : name + ' -- News Feed') + '</h4>'
          + [['Silver Crystal relaunched!', 'May 10, 2026', 'Anime'],['Welcome new Scouts!', 'Apr 28, 2026', 'Members']].map(function (e) {
              return '<div style="padding:.5rem 0;border-bottom:1px solid #ece8f4">'
                + (showCats ? '<span style="font-size:.68rem;color:#a39cbb;margin-bottom:.2rem;display:block">' + e[2] + '</span>' : '')
                + '<div style="font-weight:600;color:#1f1b33;font-size:.82rem">' + e[0] + '</div>'
                + '<div style="font-size:.72rem;color:#79738f">' + e[1] + '</div>'
                + '</div>';
            }).join('');
      }

      case 'adopt':
        return '<h4>Apply to Adopt a Fanlisting</h4>'
          + '<p class="site-sub">Interested in taking over a fanlisting? Choose one and fill in your details.</p>'
          + '<label>Fanlisting</label><select class="fld fld--select"><option>— Choose a fanlisting —</option><option>Silver Crystal</option><option>Jedi Order</option></select>'
          + '<label>Name</label><input class="fld" placeholder="Your name" />'
          + '<label>Email</label><input class="fld" placeholder="you@example.com" />'
          + '<label>Website</label><input class="fld" placeholder="https://" />'
          + '<label>Why are you interested?</label>'
          + '<div style="width:100%;box-sizing:border-box;min-height:50px;padding:.4rem .625rem;border:1px solid #d8d3e8;border-radius:6px;background:#fff;font-size:.76rem;color:#a39cbb;margin-bottom:.625rem">Tell the owner why you&apos;d like to take over...</div>'
          + '<button class="site-btn">Submit application</button>';

      case 'wishlist': {
        var wtype = vals.wishlist_type || 'all';
        var wItems = [
          { label: 'Tifa Lockhart', type: 'Wanted' },
          { label: 'Sailor Saturn', type: 'Top' },
          { label: 'Kingdom Hearts -- Destiny Islands', type: 'Wanted' },
        ].filter(function (w) { return wtype === 'all' || w.type.toLowerCase() === wtype; });
        return '<h4>Wishlist</h4>'
          + (wItems.length ? wItems.map(function (w) {
              return '<div class="site-row"><span>' + w.label + '</span><span class="site-flag">' + w.type + '</span></div>';
            }).join('') : '<p class="site-sub">No items to show for this filter.</p>');
      }

      case 'affiliates':
      case 'aff-col':
        return '<h4>Affiliates</h4><div class="site-aff">'
          + ['Moon Kingdom','Pallet Town','Jedi Archives','Hyrule','Midgar','Destiny Islands'].map(function (a) {
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
          + '<button class="site-btn">Load my details</button>'
          + '<p class="site-sub" style="margin-top:0.75em">Once signed in, members can also check '
          + '"Permanently delete my membership instead of updating it" to leave ' + name + '.</p>';

      case 'col-update':
        return '<h4>Update your details</h4>'
          + '<p class="site-sub">Enter your email and we&apos;ll send a link to update your details across every fanlisting you belong to.</p>'
          + '<label>Email</label><input class="fld" placeholder="you@example.com" />'
          + '<button class="site-btn">Send update link</button>';

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
        var modeO = vals.display_mode || '';
        var statusVal = vals.status || '';
        var ownedDfMap = {'F j, Y':'November 15, 2026','M j, Y':'Nov 15, 2026','Y-m-d':'2026-11-15'};
        var ownedDfNote = vals.date_format && ownedDfMap[vals.date_format] ? '<div class="site-note">Dates: ' + ownedDfMap[vals.date_format] + '</div>' : '';
        var allSamplesO = {
          '':        [['Silver Crystal', ''],         ['Jedi Order', ''],       ['Pallet Town', '']],
          'upcoming':[['Hyrule Castle', 'Upcoming'],  ['Mako Reactor', 'Upcoming']],
          'pending': [['Destiny Islands', 'Pending'], ['Traverse Town', 'Pending']],
          'past':    [['Moonlit Ruins', 'Closed'],    ['Starlight Vale', 'Adopted Out']],
          'all':     [['Silver Crystal', 'Current'],  ['Hyrule Castle', 'Upcoming'], ['Destiny Islands', 'Pending']]
        };
        var samplesO = allSamplesO[statusVal] || allSamplesO[''];
        var rowsO = samplesO.map(function (it) {
          return '<div class="site-row"><a class="site-link" href="#">' + it[0] + '</a>'
            + (it[1] ? '<span class="site-flag">' + it[1] + '</span>' : '') + '</div>';
        }).join('');
        var catDropdownO = '<select class="fld fld--select"><option>— Choose a category —</option><option>Anime (5)</option><option>Games (3)</option><option>Movies (2)</option></select>';
        var catListO = '<ul style="margin:0 0 .25rem;padding-left:18px"><li><a class="site-link" href="#">Anime (5)</a></li><li><a class="site-link" href="#">Games (3)</a></li><li><a class="site-link" href="#">Movies (2)</a></li></ul>';
        var switcherO = '<select class="fld fld--select" style="margin-bottom:.35rem"><option>Anime</option><option>Games</option><option>Movies</option></select>';
        if (modeO === 'browse') {
          return '<h4>Owned fanlistings</h4>'
            + '<div class="site-note" style="margin-bottom:.25rem">Category list:</div>'
            + catListO
            + '<div class="site-note" style="margin:.4rem 0 .2rem">After selecting a category:</div>'
            + switcherO
            + rowsO + ownedDfNote;
        }
        if (modeO === 'none') {
          return '<h4>Owned fanlistings</h4>' + rowsO + ownedDfNote;
        }
        return '<h4>Owned fanlistings</h4>'
          + catDropdownO
          + rowsO + ownedDfNote;
      }

      case 'joined': {
        var mode2 = vals.display_mode || '';
        var showAllFirst2 = vals.show_all_by_default === 'true';
        var joinedDfMap = {'F j, Y':'November 15, 2026','M j, Y':'Nov 15, 2026','Y-m-d':'2026-11-15'};
        var joinedDfNote = vals.date_format && joinedDfMap[vals.date_format] ? '<div class="site-note">Dates: ' + joinedDfMap[vals.date_format] + '</div>' : '';
        var sampleRows2 = [['Sailor Moon','Anime'],['Pokémon','Games'],['Star Wars','Movies']].map(function (it) {
          return '<div class="site-row"><a class="site-link" href="#">' + it[0] + '</a><span class="site-flag">' + it[1] + '</span></div>';
        }).join('');
        var catDropdown2 = '<select class="fld fld--select"><option>— Choose a category —</option><option>Anime (12)</option><option>Games (8)</option><option>Movies (5)</option></select>';
        var catList2 = '<ul style="margin:0 0 .25rem;padding-left:18px"><li><a class="site-link" href="#">Anime (12)</a></li><li><a class="site-link" href="#">Games (8)</a></li><li><a class="site-link" href="#">Movies (5)</a></li></ul>';
        var switcher2 = '<select class="fld fld--select" style="margin-bottom:.35rem"><option>Anime</option><option>Games</option><option>Movies</option></select>';
        if (mode2 === 'browse') {
          return '<h4>Joined fanlistings</h4>'
            + '<div class="site-note" style="margin-bottom:.25rem">Category index:</div>'
            + catList2
            + '<div class="site-note" style="margin:.4rem 0 .2rem">After selecting a category:</div>'
            + switcher2
            + sampleRows2 + joinedDfNote;
        }
        if (mode2 === 'all') {
          return '<h4>Joined fanlistings</h4>' + sampleRows2 + joinedDfNote;
        }
        if (showAllFirst2) {
          return '<h4>Joined fanlistings</h4>' + catDropdown2 + sampleRows2 + joinedDfNote;
        }
        return '<h4>Joined fanlistings</h4>'
          + catDropdown2
          + '<div class="site-note">Listings appear after a category is chosen.</div>'
          + joinedDfNote;
      }

      case 'cstats': {
        var selVars = Array.isArray(vals.vars) ? vals.vars : [];
        if (selVars.length === 0) {
          return '<div class="site-note" style="font-style:italic;color:#777">Select stats above to preview the generated code, or leave all unchecked to use the collective stats template.</div>';
        }
        var sampleValues = {
          luster_owned_active: '12', luster_owned_upcoming: '2', luster_owned_pending: '1', luster_owned: '15',
          luster_joined_active: '48', luster_joined_approved: '45', luster_joined_upcoming: '3', luster_joined_pending: '0', luster_joined: '48',
          luster_affiliates: '8', luster_affiliates_sitewide: '8', luster_affiliates_total: '22', luster_members_approved: '3,450', luster_members_pending: '12',
          luster_countries_count: '27', luster_total_cats: '9', luster_joined_total_cats: '6',
          luster_owned_growth_rate: '2.50', luster_joined_growth_rate: '4.80', luster_members_growth_rate: '1.25',
        };
        var statsHtml = '<h4>Selected Variables</h4><div class="site-stats">';
        selVars.forEach(function (v) {
          statsHtml += '<div class="site-stat"><span class="k">$' + v.name + '</span><span class="v">' + (sampleValues[v.name] || '—') + '</span></div>';
        });
        statsHtml += '</div><div class="site-note">These values are samples. The real page will show live data.</div>';
        return statsHtml;
      }

      case 'random': {
        var randStatus = vals.status || 'current';
        var randBadge = randStatus === 'upcoming' ? 'Upcoming' : randStatus === 'all' ? 'Any status' : 'Current';
        var randDfMap = {'F j, Y':'November 15, 2026','M j, Y':'Nov 15, 2026','Y-m-d':'2026-11-15'};
        var randDfNote = vals.date_format && randDfMap[vals.date_format] ? '<div class="site-note">Dates: ' + randDfMap[vals.date_format] + '</div>' : '';
        return '<h4>Featured listing</h4>'
          + '<div class="site-row"><a class="site-link" href="#">' + name + '</a><span class="site-flag">' + randBadge + '</span></div>'
          + '<div class="site-note">A different listing each page load.' + (randStatus === 'upcoming' ? ' Pool: upcoming listings only.' : randStatus === 'all' ? ' Pool: all statuses.' : '') + '</div>'
          + randDfNote;
      }

      case 'latest': {
        var latestDfMap = {'F j, Y':'November 15, 2026','M j, Y':'Nov 15, 2026','Y-m-d':'2026-11-15'};
        var latestDfNote = vals.date_format && latestDfMap[vals.date_format] ? '<div class="site-note">Dates: ' + latestDfMap[vals.date_format] + '</div>' : '';
        return '<h4>Latest listing</h4>'
          + '<div class="site-row"><a class="site-link" href="#">' + name + '</a><span class="site-flag">Current</span></div>'
          + '<div class="site-note">Your most recently opened listing.</div>'
          + latestDfNote;
      }

      case 'random-joined':
      case 'latest-joined': {
        var rjlDfMap = {'F j, Y':'November 15, 2026','M j, Y':'Nov 15, 2026','Y-m-d':'2026-11-15'};
        var rjlDfNote = vals.date_format && rjlDfMap[vals.date_format] ? '<div class="site-note">Dates: ' + rjlDfMap[vals.date_format] + '</div>' : '';
        return '<h4>' + (key === 'random-joined' ? 'Featured joined listing' : 'Latest joined listing') + '</h4>'
          + '<div class="site-row"><a class="site-link" href="#">Sailor Moon</a><span class="site-flag">Anime</span></div>'
          + '<div class="site-note">' + (key === 'random-joined' ? 'A different joined listing each page load.' : 'The fanlisting you most recently joined.') + '</div>'
          + rjlDfNote;
      }

      case 'kim':
        return '<h4>Keep me in mind</h4>'
          + '<p class="site-sub">Get notified about new fanlistings as they open.</p>'
          + '<label>Name</label><input class="fld" placeholder="Your name" />'
          + '<label>Email</label><input class="fld" placeholder="you@example.com" />'
          + '<button class="site-btn">Sign up</button>';

      case 'kim-list': {
        var kimAll = vals.only_with_signups === 'false';
        return '<h4>KIM List</h4>'
          + '<p class="site-sub">' + (kimAll ? 'All fanlistings, including those with no sign-ups.' : 'Fanlistings that have at least one approved sign-up.') + '</p>'
          + '<div class="site-row"><span>Usagi</span><span class="site-flag">Wanted: Sailor Moon</span></div>'
          + '<div class="site-row"><span>Misty</span><span class="site-flag">Wanted: Pokémon</span></div>'
          + (kimAll ? '<div class="site-note" style="margin-top:.35rem">+ Listings with no sign-ups also shown.</div>' : '');
      }

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
          + '<label><input type="checkbox" /> Proceed</label>'
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
    if (headTitle) headTitle.textContent = item.label || key;
    if (headBadge) {
      headBadge.textContent = item.badge_label || '';
      headBadge.className   = 'badge ' + (item.badge_cls || 'badge-info');
    }
    if (headDesc) headDesc.textContent = item.desc || '';
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


  // ── Template help: jump pills open + scroll to their topic ─────────────────
  var refJumpPills = root.querySelectorAll('[data-ref-jump]');
  refJumpPills.forEach(function (pill) {
    pill.addEventListener('click', function (ev) {
      ev.preventDefault();
      var target = document.getElementById(pill.dataset.refJump);
      if (!target) return;
      target.open = true;
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  // ── Initialise ─────────────────────────────────────────────────────────────
  if (pickItems.length) selectItem(pickItems[0].dataset.ecItem);

  // Restore tab + selection from URL hash
  var _hash = location.hash.slice(1);
  if (_hash) {
    var _parts = _hash.split('/');
    var _sec   = _parts[0];
    var _key   = _parts[1];
    if (_sec === 'ref' && _key && document.getElementById(_key)) {
      switchTab('ref');
      var _refTarget = document.getElementById(_key);
      _refTarget.open = true;
      _refTarget.scrollIntoView({ block: 'start' });
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
    var selType    = selOpt ? (selOpt.dataset.type    || 'listing') : 'listing';
    var selUrl     = selOpt ? (selOpt.dataset.url     || '') : '';

    var tokens = {
      fan_name:        'Fan Name',
      fan_email:       'fan@example.com',
      fan_url:         'https://example.com',
      fan_country:     'United States',
      name:            'Fan Name',
      email:           'fan@example.com',
      listing_title:    listName,
      listing_title:   listName,
      listing_subject: selSubject,
      listing_type:    selType,
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
    var initial   = null;
    var submitted = false;
    // Snapshot after autofill has settled. Guard is clean (initial === null)
    // until the snapshot fires so we never block legitimate navigation.
    // readyState check handles async/dynamic script loads gracefully.
    function takeSnapshot() { initial = snapshotForm(form); }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', takeSnapshot);
    } else {
      takeSnapshot();
    }
    form.addEventListener('submit', function () { submitted = true; });
    guards.push(function () { return submitted || initial === null || snapshotForm(form) === initial; });
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
        // Standalone codes page: update URL using base URL + tab param.
        // codesBaseUrl (data-codes-base-url) is already an absolute URL
        // (built from the app's base_url) — do not prepend location.origin,
        // or new URL() produces a mangled double-origin string.
        var url = new URL(codesBaseUrl);
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
      saveOrdBtn.classList.remove('is-hidden');

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

  var codesListingSel = document.getElementById('codes-listing-sel');
  if (codesListingSel) {
    codesListingSel.addEventListener('change', function () {
      // When switching away from All Listings, suppress the global tab param so
      // per-listing and collective views don't inherit an all/pending value.
      if (this.value !== '0') {
        var globalTab = document.getElementById('codes-global-tab');
        if (globalTab) globalTab.disabled = true;
      }
      var form = document.getElementById('codes-filter-form');
      if (form) form.submit();
    });
  }
  ['codes-size-sel', 'codes-donor-sel'].forEach(function (id) {
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

  var tokenSearch = document.getElementById('et-token-search');
  var tokenEmpty  = document.getElementById('et-token-empty');

  // Filters the rows of the currently-visible token group by the search box text.
  function filterTokenGroup(group) {
    if (!group) return;
    var q       = tokenSearch ? tokenSearch.value.trim().toLowerCase() : '';
    var visible = 0;
    group.querySelectorAll('.tok-row').forEach(function (row) {
      var haystack = ((row.getAttribute('data-token') || '') + ' ' + (row.getAttribute('title') || '')).toLowerCase();
      var match = !q || haystack.indexOf(q) !== -1;
      row.hidden = !match;
      if (match) visible++;
    });
    if (tokenEmpty) tokenEmpty.classList.toggle('is-hidden', visible > 0);
  }

  function activate(tabId) {
    items.forEach(function (el) { el.classList.remove('active'); });
    document.querySelectorAll('.et-panel').forEach(function (el) { el.classList.remove('active'); });
    var item  = document.querySelector('.mini-nav-item[data-et-tab="' + tabId + '"]');
    var panel = document.getElementById('et-tab-' + tabId);
    if (item)  item.classList.add('active');
    if (panel) panel.classList.add('active');

    var activeGroup = null;
    document.querySelectorAll('.et-token-group').forEach(function (el) {
      var isActive = el.getAttribute('data-et-tokens') === tabId;
      el.classList.toggle('is-hidden', !isActive);
      if (isActive) activeGroup = el;
    });
    filterTokenGroup(activeGroup);
  }

  items.forEach(function (item) {
    item.addEventListener('click', function () { activate(item.dataset.etTab); });
  });

  if (tokenSearch) {
    tokenSearch.addEventListener('input', function () {
      filterTokenGroup(document.querySelector('.et-token-group:not(.is-hidden)'));
    });
  }
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
    return val === 'upcoming' || val === 'pending';
  }

  function setTbaState(tba) {
    if (tba) {
      openedInput.style.display = 'none';
      openedInput.disabled      = true;
      if (openedTba)  { openedTba.disabled = false; }
      if (openedText) { openedText.style.display = 'block'; }
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

  // Archive note and the archived-date field show for Closed or Adopted Out;
  // the "Adopted By" name/URL fields only make sense for Adopted Out specifically.
  var archiveNoteRow  = document.getElementById('archive-note-row');
  var adoptedNameRow  = document.getElementById('adopted-to-name-row');
  var adoptedUrlRow   = document.getElementById('adopted-to-url-row');
  var archivedAtRow   = document.getElementById('archived-at-row');
  var archivedAtLabel = document.getElementById('fl-archived-at-label');
  var urlInput        = document.getElementById('fl-url');

  function setArchiveRowsState(val) {
    var isArchived   = val === 'closed' || val === 'adopted_out';
    var isAdoptedOut = val === 'adopted_out';
    if (archiveNoteRow) { archiveNoteRow.style.display = isArchived   ? '' : 'none'; }
    if (adoptedNameRow) { adoptedNameRow.style.display = isAdoptedOut ? '' : 'none'; }
    if (adoptedUrlRow)  { adoptedUrlRow.style.display  = isAdoptedOut ? '' : 'none'; }
    if (archivedAtRow)  { archivedAtRow.style.display  = isArchived   ? '' : 'none'; }
    if (archivedAtLabel) { archivedAtLabel.textContent = isAdoptedOut ? 'Adopted On' : 'Closed On'; }
    if (urlInput) {
      if (isArchived) { urlInput.removeAttribute('required'); }
      else            { urlInput.setAttribute('required', ''); }
    }
  }

  radios.forEach(function (r) {
    r.addEventListener('change', function () { setArchiveRowsState(this.value); });
  });

  setArchiveRowsState(getStatusVal());
}());

// ── 39. Global delete / destructive-action confirmation modal ────────────────────
//
// Single-item forms: add data-del-modal to the <form>, plus:
//   data-del-entity   -- key into the CFG map below
//   data-del-name     -- display name of the item being deleted
//   data-del-name-from -- (alternative to data-del-name) name of a field within
//     this form whose live value is read at submit time instead — for forms
//     where the thing being deleted is user-entered (e.g. member-merge's
//     duplicate email), not known until the admin fills it in
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
    affiliate_application: { title: 'Reject Application',      btn: 'Reject',      desc: 'You are about to reject the affiliate application from <strong>$$names$$</strong>.',    warn: 'This action cannot be undone.' },
    category:              { title: 'Delete Category',         btn: 'Delete',      desc: 'You are about to delete category <strong>$$names$$</strong>.',                         warn: 'This action cannot be undone.' },
    joined:                { title: 'Delete Joined Listing',   btn: 'Delete',      desc: 'You are about to delete <strong>$$names$$</strong> from your joined listings.',        warn: 'This action cannot be undone.' },
    email_template:        { title: 'Delete Template',         btn: 'Delete',      desc: 'You are about to delete template <strong>$$names$$</strong>.',                        warn: 'This action cannot be undone.' },
    admin_user:            { title: 'Delete Admin Account',    btn: 'Delete',      desc: 'You are about to delete admin account <strong>$$names$$</strong>.',                   warn: 'This will permanently remove admin access for this user. This action cannot be undone.' },
    blacklist:             { title: 'Remove Spam Filter Entry', btn: 'Remove',     desc: 'You are about to remove <strong>$$names$$</strong> from spam filtering.',             warn: 'This action cannot be undone.' },
    field:                 { title: 'Drop Field Column',       btn: 'Drop Column', desc: 'You are about to drop the column for field <strong>$$names$$</strong>.',             warn: 'This permanently removes all member data for this field and cannot be undone.' },
    code:                  { title: 'Delete Code',             btn: 'Delete',      desc: 'You are about to delete code <strong>$$names$$</strong>.',                           warn: 'This action cannot be undone.' },
    code_category:         { title: 'Delete Code Category',    btn: 'Delete',      desc: 'You are about to delete code category <strong>$$names$$</strong>. Assignments will be removed.', warn: 'This action cannot be undone.' },
    code_donor:            { title: 'Delete Code Donor',       btn: 'Delete',      desc: 'You are about to delete donor <strong>$$names$$</strong>. Their codes will be unattributed.', warn: 'This action cannot be undone.' },
    kim_member:            { title: 'Delete KIM Entry',        btn: 'Delete',      desc: 'You are about to delete the KIM entry for <strong>$$names$$</strong>.',              warn: 'This action cannot be undone.' },
    pending_update_reject: { title: 'Reject Update',           btn: 'Reject',      desc: 'You are about to reject the update for <strong>$$names$$</strong>.',                 warn: 'The pending update will be discarded. This action cannot be undone.' },
    merge_member:          { title: 'Delete Duplicate Member', btn: 'Delete',      desc: 'You are about to delete <strong>$$names$$</strong> as a duplicate. <strong>$$context$$</strong> will be kept.', warn: 'This permanently deletes the duplicate account. This action cannot be undone.' },
    rename_prefix:         { title: 'Rename Member Table',     btn: 'Rename Table', desc: 'You are about to rename the member table from <strong>$$names$$</strong> to <strong>$$context$$</strong>.', warn: 'This renames the table directly in your database. If anything goes wrong, member data may become unreachable. This action cannot be undone.' },
    entry:                 { title: 'Delete Entry',            btn: 'Delete',      desc: 'You are about to delete the entry <strong>$$names$$</strong>.',                       warn: 'This action cannot be undone.' },
    rules:                 { title: 'Delete Rules',            btn: 'Delete',      desc: 'You are about to delete the join rules for <strong>$$names$$</strong>.',               warn: 'Deleting removes the rules text (and rules gate, if enabled) entirely for this listing. This action cannot be undone.' },
    wishlist_entry:        { title: 'Delete Wishlist Entries', btn: 'Delete',      desc: 'You are about to delete <strong>$$names$$</strong>.',                                   warn: 'This action cannot be undone.' },
    'reset-all-templates': { title: 'Reset All Templates',     btn: 'Reset All',   desc: 'You are about to reset <strong>$$names$$</strong>.',                                      warn: 'Every template for this listing will revert to the global default. Any per-listing customisations will be lost.' },
    adoption_application:  { title: 'Reject Application',      btn: 'Reject',      desc: 'You are about to reject the application from <strong>$$names$$</strong>.',               warn: 'The applicant will be marked as rejected. This action cannot be undone.' },
    listing_image:         { title: 'Delete Image',            btn: 'Delete',      desc: 'You are about to delete the image for this listing.',                                    warn: 'This will permanently remove the image file. This action cannot be undone.' },
    affiliate_image:       { title: 'Delete Image',            btn: 'Delete',      desc: 'You are about to delete the image for this affiliate.',                                  warn: 'This will permanently remove the image file. This action cannot be undone.' },
    joined_image:          { title: 'Delete Image',            btn: 'Delete',      desc: 'You are about to delete the image for this joined listing.',                             warn: 'This will permanently remove the image file. This action cannot be undone.' },
    wishlist_image:        { title: 'Delete Image',            btn: 'Delete',      desc: 'You are about to delete the image for this wishlist entry.',                             warn: 'This will permanently remove the image file. This action cannot be undone.' }
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

  // Single source of truth for what a confirm click does: `pending` is either
  // a form Element (data-del-modal flow) or a plain {_cb} marker object
  // (trigger() flow) — never a temporary confirmBtn.onclick override. That
  // override approach used to leak: cancelling a trigger()'d modal (Cancel,
  // backdrop click, or Escape) only cleared `pending` and hid the modal, but
  // never restored/cleared confirmBtn.onclick, so a *later, unrelated* modal's
  // confirm click could still fire the stale callback — e.g. cancel an image
  // delete, then confirm a totally different delete elsewhere, and the
  // cancelled image got deleted anyway. Routing everything through this one
  // listener means every close path (Cancel/backdrop/Escape/confirm) clears
  // `pending` the same way, so there's nothing left to go stale.
  confirmBtn.addEventListener('click', function () {
    if (!pending) return;
    if (pending instanceof Element) {
      var f = pending;
      close();
      f.dataset.delConfirmed = '1';
      f.requestSubmit ? f.requestSubmit() : f.submit();
    } else if (typeof pending._cb === 'function') {
      var cb = pending._cb;
      close();
      cb();
    }
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
      // data-del-name-from reads the live value of a field at submit time —
      // for forms like member-merge where the name of the item actually being
      // deleted (the duplicate's email) is only known once the admin types it
      // in, not at page-render time when data-del-name would have to be static.
      var nameFrom = form.dataset.delNameFrom;
      var name = nameFrom ? (form.querySelector('[name="' + nameFrom + '"]') || {}).value || '' : form.dataset.delName || '';
      show(cfg, name, ctx, form.dataset.delWarning || '');
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
  // Matches paired conditional/loop tokens stored as a single concatenated
  // "{if_x}{/if_x}" or "{each_x}{/each_x}" string in data-token.
  var PAIR_TOKEN_RE = /^(\{(?:if|each)_[a-zA-Z0-9_]+\})(\{\/(?:if|each)_[a-zA-Z0-9_]+\})$/;

  // Inserts text at the current selection while preserving the textarea's
  // native undo/redo stack (direct .value assignment resets it).
  function insertTextPreservingUndo(ta, text) {
    if (typeof document.execCommand === 'function' && document.execCommand('insertText', false, text)) {
      return;
    }
    var start = ta.selectionStart, end = ta.selectionEnd;
    ta.value = ta.value.slice(0, start) + text + ta.value.slice(end);
    ta.selectionStart = ta.selectionEnd = start + text.length;
  }

  function insertTokenFromRow(row) {
    var ta = document.getElementById(row.dataset.textarea);
    if (!ta) return;
    var token = row.dataset.token || '';
    var start = ta.selectionStart, end = ta.selectionEnd;

    ta.focus();

    // For paired conditional/loop tokens with an active selection, wrap the
    // selection instead of replacing it: open tag before, close tag after.
    var pairMatch = token.match(PAIR_TOKEN_RE);
    if (pairMatch && start !== end) {
      var selected = ta.value.slice(start, end);
      ta.setSelectionRange(start, end);
      insertTextPreservingUndo(ta, pairMatch[1] + selected + pairMatch[2]);
      var innerStart = start + pairMatch[1].length;
      ta.setSelectionRange(innerStart, innerStart + selected.length);
      return;
    }

    insertTextPreservingUndo(ta, token);
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
        var groupSel     = document.getElementById('tpl-group-select');
        var activeGroup  = groupSel ? groupSel.value : 'all';

        if (active && !wasOpen._active) {
          tokenCards.forEach(function (card, i) { wasOpen[i] = card.open; });
          wasOpen._active = true;
        }

        tokenCards.forEach(function (card, i) {
          var cardGroup   = card.getAttribute('data-tpl-group') || 'all';
          var groupMatch  = activeGroup === 'all' || cardGroup === activeGroup;
          var rows        = card.querySelectorAll('.tok-row[data-token]');
          var helps       = card.querySelectorAll('[data-token-search-help]');
          var matches     = 0;

          helps.forEach(function (el) { el.hidden = active; });

          rows.forEach(function (row) {
            var source   = [row.getAttribute('data-token') || '', row.getAttribute('title') || '', row.textContent || ''].join(' ');
            var haystack = source.toLowerCase() + ' ' + tokenSearchNorm(source);
            var match    = !active || terms.every(function (t) { return haystack.indexOf(t) !== -1; });
            row.hidden   = !match;
            if (match) matches++;
          });

          if (active) {
            card.hidden = !(groupMatch && matches > 0);
            if (groupMatch && matches > 0) card.open = true;
            if (groupMatch) totalMatches += matches;
          } else {
            card.hidden = !groupMatch;
            if (wasOpen._active) { card.open = !!wasOpen[i]; delete wasOpen[i]; }
          }
        });

        if (!active) wasOpen._active = false;
        if (emptyEl)  emptyEl.hidden  = !active || totalMatches > 0;
        if (clearBtn) clearBtn.classList.toggle('is-visible', input.value.length > 0);
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

  // Group selector (Collective Templates page)
  (function () {
    var sel = document.getElementById('tpl-group-select');
    if (!sel) return;

    var allowedGroups = (function () {
      var set = {};
      sel.querySelectorAll('option').forEach(function (o) { set[o.value] = true; });
      return set;
    }());

    function applyGroup(group) {
      if (!allowedGroups[group]) group = 'all';
      sel.value = group;

      document.querySelectorAll('[data-tpl-group]').forEach(function (el) {
        var g = el.getAttribute('data-tpl-group');
        el.hidden = group !== 'all' && g !== group;
      });

      var returnInput = document.getElementById('tpl-return-group');
      if (returnInput) returnInput.value = group;

      var url = new URL(window.location.href);
      if (group === 'all') {
        url.searchParams.delete('group');
      } else {
        url.searchParams.set('group', group);
      }
      history.replaceState(null, '', url.toString());

      document.querySelectorAll('[data-token-search]').forEach(function (input) {
        if (input.value.trim() !== '') {
          input.dispatchEvent(new Event('input', { bubbles: false }));
        }
      });
    }

    sel.addEventListener('change', function () { applyGroup(sel.value); });
  }());

  // Template group search bar (listing edit — shows all matching template sections)
  (function () {
    var sel       = document.getElementById('tpl-group-select');
    var input     = document.getElementById('tpl-group-search');
    var searchBtn = document.getElementById('tpl-group-search-btn');
    var resetBtn  = document.getElementById('tpl-group-search-reset');
    if (!sel || !input) return;

    var sections = document.querySelectorAll('[data-tpl-group]');
    var groupLabels = {};
    sel.querySelectorAll('option').forEach(function (o) {
      if (o.value) groupLabels[o.value] = o.textContent.trim().toLowerCase();
    });

    function doSearch() {
      var q = input.value.trim().toLowerCase();
      if (q === '') { doReset(); return; }

      sections.forEach(function (el) {
        var g = el.getAttribute('data-tpl-group');
        if (g === 'all') { el.hidden = true; return; }
        var label = groupLabels[g] || g;
        el.hidden = label.indexOf(q) === -1;
      });
    }

    function doReset() {
      input.value = '';
      sel.value = 'all';
      sel.dispatchEvent(new Event('change'));
    }

    input.addEventListener('input', doSearch);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
    });

    if (searchBtn) searchBtn.addEventListener('click', doSearch);
    if (resetBtn)  resetBtn.addEventListener('click', doReset);
  }());

  // Bidirectional textarea ↔ token-card linking (all template pages)
  (function () {
    var cards = document.querySelectorAll('details[data-token-card][data-tpl-group]');
    if (!cards.length) return;

    var cardByGroup = {};
    cards.forEach(function (card) {
      cardByGroup[card.getAttribute('data-tpl-group')] = card;
    });

    var suppressCardToggle = false;

    // Textarea focus → open matching sidebar card, close others
    document.querySelectorAll('.tpl-group-section[data-tpl-group] textarea').forEach(function (ta) {
      ta.addEventListener('focus', function () {
        var group = ta.closest('[data-tpl-group]').getAttribute('data-tpl-group');
        var target = cardByGroup[group];
        if (!target || target.open) return;

        suppressCardToggle = true;
        cards.forEach(function (c) { if (c !== target && c.open) c.open = false; });
        target.open = true;
        target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        suppressCardToggle = false;
      });
    });

    // Sidebar card toggle → scroll to and focus matching textarea
    cards.forEach(function (card) {
      var summary = card.querySelector('summary');
      if (!summary) return;
      summary.addEventListener('click', function () {
        if (suppressCardToggle) return;
        // The click fires before the browser toggles open, so !card.open means it's about to open
        if (card.open) return;
        var group = card.getAttribute('data-tpl-group');
        var section = document.querySelector('.tpl-group-section[data-tpl-group="' + group + '"]');
        if (!section) return;
        var ta = section.querySelector('textarea');
        if (!ta) return;
        requestAnimationFrame(function () {
          section.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          ta.focus({ preventScroll: true });
        });
      });
    });
  }());

  // Reset All Templates button (listing edit — posts via dynamic form to avoid nesting)
  (function () {
    var btn = document.getElementById('fl-reset-all-templates');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var msg = btn.getAttribute('data-confirm') || 'Reset all templates to their defaults?\n\nAny customisations will be lost.';
      if (!confirm(msg)) return;
      var f = document.createElement('form');
      f.method = 'POST';
      f.action = btn.getAttribute('data-action');
      var t = document.createElement('input');
      t.type = 'hidden'; t.name = '_csrf'; t.value = btn.getAttribute('data-csrf');
      f.appendChild(t);
      document.body.appendChild(f);
      f.submit();
    });
  }());

  // Reset to default button
  var defaultsEl = document.getElementById('luster-coltpl-defaults');
  var defaults = {};
  if (defaultsEl) {
    try { defaults = JSON.parse(defaultsEl.textContent); } catch (err) {}
  }

  function syncLayoutPresetActive(taId) {
    var ta = document.getElementById(taId);
    if (!ta) return;
    var val = ta.value.trim();
    document.querySelectorAll('button[data-layout-preset][data-textarea="' + taId + '"]').forEach(function (btn) {
      var preset = defaults[btn.dataset.layoutPreset];
      btn.classList.toggle('is-active', preset !== undefined && preset.trim() === val);
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-layout-preset], .tpl-reset-row button[data-textarea]');
    if (!btn || btn.classList.contains('coltpl-preview-btn')) return;
    // Layout preset buttons use a different defaults key
    var key = btn.dataset.layoutPreset || btn.dataset.textarea;
    var ta  = document.getElementById(btn.dataset.textarea);
    if (ta && defaults[key] !== undefined) {
      ta.value = defaults[key];
      ta.focus();
      if (btn.dataset.layoutPreset) {
        syncLayoutPresetActive(ta.id);
      }
    }
  });

  // Initialise active state for all layout-preset button groups on page load
  document.querySelectorAll('button[data-layout-preset]').forEach(function (btn) {
    syncLayoutPresetActive(btn.dataset.textarea);
  });

  // Rules pages: "Reset to Default" composer-footer button restores the whole
  // form (body, gate enabled, gate type, gate label) to built-in defaults —
  // not just the page-load values that a native type="reset" would restore.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-rules-reset-defaults');
    if (!btn) return;
    var taId = btn.dataset.formReset;
    if (!taId || defaults[taId] === undefined) return;
    var prefix = taId.replace(/-body$/, '');

    var ta = document.getElementById(taId);
    if (ta) ta.value = defaults[taId];

    var enabledCb = document.getElementById(prefix + '-enabled');
    if (enabledCb) {
      enabledCb.checked = false;
      enabledCb.dispatchEvent(new Event('change', { bubbles: true }));
    }

    var gateTypeSel = document.getElementById(prefix + '-gate-type');
    if (gateTypeSel) gateTypeSel.value = 'checkbox';

    var gateLabelInput = document.getElementById(prefix + '-gate-label');
    if (gateLabelInput) gateLabelInput.value = '';
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
    owned: 'Owned Listings Preview', past_owned: 'Past Fanlistings Preview', random: 'Random Listing Preview',
    latest: 'Latest Listing Preview', joined: 'Joined Listings Preview',
    'random-joined': 'Random Joined Listing Preview', 'latest-joined': 'Latest Joined Listing Preview',
    collective_affiliates_section: 'Affiliates Preview', collective_stats: 'Statistics Preview',
    members: 'Members List Preview', affiliates_section: 'Affiliates Preview',
    stats: 'Statistics Preview', newsfeed: 'News Feed Preview',
    codes_submit_form: 'Code Donation Form Preview', rules_intro: 'Join Rules Intro Preview',
    rules: 'Join Rules Preview',
    join_form: 'Join Form Preview', update_form: 'Update Form Preview', reset_form: 'Password Reset Form Preview',
    affiliate_apply_form: 'Affiliate Application Preview',
    contact_form: 'Contact Form Preview',
    collective_affiliate_apply_form: 'Collective Affiliate Application Preview',
    collective_adopt_form: 'Collective Adoption Form Preview',
    kim_signup_form: 'KIM Sign-Up Form Preview',
    wishlist: 'Wishlist Preview', collective_newsfeed: 'Collective News Feed Preview',
    codes: 'Codes Preview', collective_codes: 'Collective Codes Preview',
    collective_codes_submit_form: 'Collective Code Donation Form Preview',
    collective_contact_form: 'Contact Form Preview',
    collective_kim_list_overview: 'KIM List Overview Preview',
    collective_kim_list_subject: 'KIM Subject View Preview',
    kim_update_form: 'KIM Update Form Preview',
    kim_reset_form: 'KIM Reset Form Preview'
  };

  function closeColtplModal() {
    if (coltplModal) { coltplModal.classList.add('is-hidden'); coltplModal.style.display = 'none'; }
    if (coltplIframe) { coltplIframe.srcdoc = ''; }
  }

  function loadColtplPreview(type, tplHtml, extra) {
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
    if (extra) {
      for (var key in extra) {
        if (Object.prototype.hasOwnProperty.call(extra, key)) fd.append(key, extra[key]);
      }
    }

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
    var extra;
    if (type === 'rules' && taId) {
      var prefix    = taId.replace(/-body$/, '');
      var labelEl   = document.getElementById(prefix + '-gate-label');
      var enabledEl = document.getElementById(prefix + '-enabled');
      var typeEl    = document.getElementById(prefix + '-gate-type');
      extra = {
        gate_label:   labelEl   ? labelEl.value                    : '',
        gate_enabled: enabledEl ? (enabledEl.checked ? '1' : '0')  : '1',
        gate_type:    typeEl    ? typeEl.value                      : '',
      };
    } else if (type === 'affiliates_section' || type === 'codes' || type === 'collective_affiliates_section' || type === 'collective_codes') {
      // Collect current style/label inputs adjacent to this textarea
      var ta2 = taId ? document.getElementById(taId) : null;
      var section = ta2 ? ta2.closest('[data-tpl-group]') : null;
      if (section) {
        var styleEl = section.querySelector('select[name="affiliate_apply_style"], select[name="codes_submit_style"], select[name="codes_submit_toggle_style"], select[name="collective_affiliate_apply_style"], select[name="collective_codes_submit_style"]');
        var labelEl2 = section.querySelector('input[name="affiliate_apply_toggle_label"], input[name="codes_submit_toggle_label"], input[name="collective_affiliate_apply_toggle_label"], input[name="collective_codes_submit_toggle_label"]');
        extra = {
          toggle_style: styleEl  ? styleEl.value  : '',
          toggle_label: labelEl2 ? labelEl2.value : '',
        };
      }
    } else if (type === 'stats') {
      var ta3 = taId ? document.getElementById(taId) : null;
      var section3 = ta3 ? ta3.closest('[data-tpl-group]') : null;
      if (section3) {
        var limitEl = section3.querySelector('select[name="stat_newmembers_limit"]');
        extra = { stat_newmembers_limit: limitEl ? limitEl.value : '' };
      }
    }
    loadColtplPreview(type, tplHtml, extra);
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
    var chipClass      = wrap.getAttribute('data-tag-chip-class') || 'badge badge-info3';

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

    // Typing a category and clicking Save (or tabbing away) without pressing
    // Enter/comma first left it sitting in the text field, never turned into
    // a chip, and so never synced into the hidden field the form submits -
    // it looked added but silently vanished. Flush it on blur and again just
    // before the form submits, so nothing typed is ever lost.
    function flushPending() {
      var val = textInput.value.replace(/,+$/, '').trim();
      if (val) { addChip(val); syncHidden(); }
      textInput.value = '';
    }
    textInput.addEventListener('blur', flushPending);
    var ownerForm = wrap.closest('form');
    if (ownerForm) ownerForm.addEventListener('submit', flushPending);

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

  // Listing Admin: Join Rules-depends-on-Listings dependency warning
  var rulesCb   = document.getElementById('ck-rules');
  var rulesWarn = document.getElementById('js-rules-dep-warn');

  if (listingsCb && rulesCb && rulesWarn) {
    function checkRulesDep() {
      rulesWarn.classList.toggle('is-visible', rulesCb.checked && !listingsCb.checked);
    }
    listingsCb.addEventListener('change', checkRulesDep);
    rulesCb.addEventListener('change', checkRulesDep);
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
  // Hidden-recipient-input container: es-email-inputs (KIM: kim_member_ids[];
  // member: emails[]) or es-id-inputs (adoption: ids[]) — mutually exclusive per page.
  var idInputsEl    = document.getElementById('es-id-inputs');
  var emailInputsEl = document.getElementById('es-email-inputs');
  var emailInputs   = emailInputsEl || idInputsEl;
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
  var recipientLabel = scopeEl ? 'affiliate' : (context === 'adoption' ? 'applicant' : (context === 'affiliate' ? 'affiliate' : 'member'));

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
      var chip = btn.closest('.es-chip-item');
      chip.classList.add('es-chip-removed');
      chip.style.display = 'none';
      if (emailInputs) {
        var id = chip.dataset.id;
        if (idInputsEl) {
          // ID-based tracking (adoption email-selected)
          idInputsEl.querySelectorAll('input[name="ids[]"]').forEach(function (inp) {
            if (inp.value === id) inp.remove();
          });
        } else if (id) {
          // ID-based tracking (KIM email-selected)
          emailInputs.querySelectorAll('input[name="kim_member_ids[]"]').forEach(function (inp) {
            if (inp.value === id) inp.remove();
          });
        } else {
          // Email-based tracking (member email-selected)
          var email = chip.dataset.email;
          emailInputs.querySelectorAll('input[name="emails[]"]').forEach(function (inp) {
            if (inp.value === email) inp.remove();
          });
        }
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
      .replace(/\{aff_name\}/g,         'James Gordon')
      .replace(/\{aff_email\}/g,        'gordon@gcpd.org')
      .replace(/\{aff_url\}/g,          'https://gcpd.org/')
      .replace(/\{listing_title\}/g,    sampleListingName  || sampleSite)
      .replace(/\{listing_subject\}/g,  sampleListingSubject)
      .replace(/\{listing_type\}/g,     'listing')
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

// ── 42. Import preview — run with progress ────────────────────────────────────
// POSTs the run form via fetch (redirect:'manual' so PHP's 302 to /result is
// never followed by fetch), shows a progress animation while PHP works, then
// navigates the main window to /result once fetch resolves. Session data written
// by /run is not consumed by fetch because we stop at the 302.

(function () {
  var runForm     = document.getElementById('js-import-run-form');
  if (!runForm) return;

  var previewCard = document.getElementById('js-import-preview-card');
  var processing  = document.getElementById('js-import-processing');
  if (!processing) return;

  function showProcessing() {
    if (previewCard) previewCard.style.display = 'none';
    processing.style.display = 'flex';

    var activeNode = document.querySelector('.wizard-step-node--active');
    if (activeNode) {
      activeNode.classList.replace('wizard-step-node--active', 'wizard-step-node--done');
      var bubble = activeNode.querySelector('.wizard-step-bubble');
      if (bubble) {
        bubble.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
      }
    }
  }

  runForm.addEventListener('submit', function (e) {
    e.preventDefault();
    showProcessing();

    var resultUrl = runForm.action.replace(/\/run(\?.*)?$/, '/result');

    fetch(runForm.action, {
      method:      'POST',
      body:        new FormData(runForm),
      credentials: 'same-origin',
      redirect:    'manual'        // stop at PHP's 302; do NOT fetch /result
    })
    .then(function () {
      window.location.href = resultUrl;
    })
    .catch(function () {
      window.location.href = resultUrl;
    });
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
  var opening = row.classList.toggle('is-hidden');
  btn.classList.toggle('is-open', !opening);
});

// ── 43. Scoped "Clear" buttons ([data-clear-target="containerId"]) ─────────────
//
// Blanks text-like inputs and textareas, unchecks checkboxes, and resets
// selects to their first option, scoped to the container the button names.
// Used by the Settings panels (General/SMTP/Notifications) and the Adoption
// edit form's Clear button.

document.querySelectorAll('[data-clear-target]').forEach(function (btn) {
  var target = document.getElementById(btn.dataset.clearTarget);
  if (!target) return;
  btn.addEventListener('click', function () {
    target.querySelectorAll('input[type="text"], input[type="email"], input[type="url"], input[type="password"], input[type="number"], textarea').forEach(function (el) {
      el.value = '';
    });
    target.querySelectorAll('input[type="checkbox"]').forEach(function (el) {
      el.checked = false;
    });
    target.querySelectorAll('select').forEach(function (el) {
      el.selectedIndex = 0;
    });
  });
});

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

  // Client-side mirror of NewsfeedBodySanitizer (src/Support/NewsfeedBodySanitizer.php).
  // Approximation only, for preview responsiveness — the PHP sanitizer run at
  // render/save time is always the source of truth. Kept structurally identical
  // to the PHP version (same escape-first, atomic-pair, allowlist approach) so
  // the two are easy to keep in sync by eye when either one changes.
  var NEWSFEED_TAG_SPECS = {
    'b':          { required: [],       optional: ['class', 'title'] },
    'strong':     { required: [],       optional: ['class', 'title'] },
    'i':          { required: [],       optional: ['class', 'title'] },
    'em':         { required: [],       optional: ['class', 'title'] },
    'u':          { required: [],       optional: ['class', 'title'] },
    's':          { required: [],       optional: ['class', 'title'] },
    'p':          { required: [],       optional: ['class', 'title'] },
    'blockquote': { required: [],       optional: ['class', 'title'] },
    'a':          { required: ['href'], optional: ['class', 'title'] },
  };
  var NEWSFEED_CLASS_TOKEN_PATTERN = /^(fl-newsfeed-|nf-)[a-zA-Z0-9-]+$/;

  function newsfeedEscapeHtml(raw) {
    return String(raw)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function newsfeedFindTagEnd(escaped, pos) {
    var len = escaped.length, inQuote = false;
    while (pos < len) {
      if (!inQuote && escaped.substr(pos, 4) === '&gt;') return pos;
      if (escaped.substr(pos, 6) === '&quot;') { inQuote = !inQuote; pos += 6; continue; }
      pos++;
    }
    return null;
  }

  function newsfeedIsValidHttpUrl(url) {
    var m = /^([a-zA-Z][a-zA-Z0-9+.-]*):/.exec(url);
    if (!m) return false;
    var scheme = m[1].toLowerCase();
    if (scheme !== 'http' && scheme !== 'https') return false;
    try { new URL(url); return true; } catch (e) { return false; }
  }

  function newsfeedFilterClassTokens(value) {
    var tokens = value.trim().split(/\s+/).filter(Boolean);
    return tokens.filter(function (t) { return NEWSFEED_CLASS_TOKEN_PATTERN.test(t); }).join(' ');
  }

  function newsfeedExtractAttrs(blob) {
    var re = /([a-zA-Z][a-zA-Z0-9:_-]*)\s*=\s*&quot;((?:(?!&quot;).){0,2000})&quot;/g;
    var attrs = {}, m;
    while ((m = re.exec(blob)) !== null) { attrs[m[1].toLowerCase()] = m[2]; }
    return attrs;
  }

  function newsfeedRebuildOpeningTag(tag, spec, blob) {
    var attrs = newsfeedExtractAttrs(blob);
    for (var i = 0; i < spec.required.length; i++) {
      var reqName = spec.required[i];
      if (attrs[reqName] === undefined || !newsfeedIsValidHttpUrl(newsfeedEntityDecode(attrs[reqName]))) return null;
    }
    var out = '<' + tag;
    spec.required.forEach(function (reqName) { out += ' ' + reqName + '="' + attrs[reqName] + '"'; });
    if (tag === 'a') out += ' target="_blank" rel="noopener noreferrer"';
    if (attrs['class'] !== undefined) {
      var filtered = newsfeedFilterClassTokens(attrs['class']);
      if (filtered !== '') out += ' class="' + filtered + '"';
    }
    if (attrs['title'] !== undefined) out += ' title="' + attrs['title'] + '"';
    return out + '>';
  }

  function newsfeedEntityDecode(s) {
    var el = document.createElement('textarea');
    el.innerHTML = s;
    return el.value;
  }

  function newsfeedConvertPairedTag(escaped, tag, spec) {
    var namePattern = new RegExp('&lt;' + tag + '(?=&gt;|\\s|/)', 'i');
    var closingPattern = new RegExp('&lt;/' + tag + '(?=&gt;)&gt;', 'i');
    var result = '', cursor = 0;

    while (true) {
      var rem = escaped.slice(cursor);
      var m = namePattern.exec(rem);
      if (!m) break;
      var matchStart = cursor + m.index;
      var afterName  = matchStart + m[0].length;
      result += escaped.slice(cursor, matchStart);

      var openEnd = newsfeedFindTagEnd(escaped, afterName);
      if (openEnd === null) {
        result += escaped.slice(matchStart, afterName);
        cursor = afterName;
        continue;
      }

      var blob = escaped.slice(afterName, openEnd);
      var rest = escaped.slice(openEnd + 4);
      var cm = closingPattern.exec(rest);
      if (!cm) {
        result += escaped.slice(matchStart, openEnd + 4);
        cursor = openEnd + 4;
        continue;
      }

      var innerContent = rest.slice(0, cm.index);
      var afterClosing = openEnd + 4 + cm.index + cm[0].length;
      var rebuilt = newsfeedRebuildOpeningTag(tag, spec, blob);
      result += rebuilt !== null
        ? rebuilt + innerContent + '</' + tag + '>'
        : escaped.slice(matchStart, afterClosing);
      cursor = afterClosing;
    }

    result += escaped.slice(cursor);
    return result;
  }

  function newsfeedConvertImg(escaped) {
    var namePattern = /&lt;img(?=&gt;|\s|\/)/i;
    var result = '', cursor = 0;

    while (true) {
      var rem = escaped.slice(cursor);
      var m = namePattern.exec(rem);
      if (!m) break;
      var matchStart = cursor + m.index;
      var afterName  = matchStart + m[0].length;
      result += escaped.slice(cursor, matchStart);

      var end = newsfeedFindTagEnd(escaped, afterName);
      if (end === null) {
        result += escaped.slice(matchStart, afterName);
        cursor = afterName;
        continue;
      }

      var blob = escaped.slice(afterName, end);
      var attrs = newsfeedExtractAttrs(blob);
      var rebuilt = null;
      if (attrs['src'] !== undefined && newsfeedIsValidHttpUrl(newsfeedEntityDecode(attrs['src']))) {
        var classTokens = 'fl-newsfeed-img';
        if (attrs['class'] !== undefined) {
          var filtered = newsfeedFilterClassTokens(attrs['class']);
          if (filtered !== '') classTokens += ' ' + filtered;
        }
        rebuilt = '<img src="' + attrs['src'] + '" class="' + classTokens + '"';
        if (attrs['alt'] !== undefined) rebuilt += ' alt="' + attrs['alt'] + '"';
        if (attrs['title'] !== undefined) rebuilt += ' title="' + attrs['title'] + '"';
        rebuilt += '>';
      }
      result += rebuilt !== null ? rebuilt : escaped.slice(matchStart, end + 4);
      cursor = end + 4;
    }

    result += escaped.slice(cursor);
    return result;
  }

  function newsfeedConvertBr(escaped) {
    var namePattern = /&lt;br(?=&gt;|\s|\/)/i;
    var result = '', cursor = 0;

    while (true) {
      var rem = escaped.slice(cursor);
      var m = namePattern.exec(rem);
      if (!m) break;
      var matchStart = cursor + m.index;
      var afterName  = matchStart + m[0].length;
      result += escaped.slice(cursor, matchStart);

      var end = newsfeedFindTagEnd(escaped, afterName);
      if (end === null) {
        result += escaped.slice(matchStart, afterName);
        cursor = afterName;
        continue;
      }

      result += '<br>';
      cursor = end + 4;
    }

    result += escaped.slice(cursor);
    return result;
  }

  function newsfeedSanitizePreview(raw) {
    var escaped = newsfeedEscapeHtml(raw);
    Object.keys(NEWSFEED_TAG_SPECS).forEach(function (tag) {
      escaped = newsfeedConvertPairedTag(escaped, tag, NEWSFEED_TAG_SPECS[tag]);
    });
    escaped = newsfeedConvertImg(escaped);
    escaped = newsfeedConvertBr(escaped);
    return escaped.replace(/\n/g, '<br>\n');
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

    if (pvBody) pvBody.innerHTML = newsfeedSanitizePreview(bodyEl ? bodyEl.value : '');

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

(function () {
  adminWireFilterPanel('rules-filter-toggle', 'rules-filter-panel', 'fp-rules');
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

// ── 48. Link Checker ──────────────────────────────────────────────────────────

(function () {

  // ── Shared batch check logic ──────────────────────────────────────────────

  function statusLabel(s) {
    var map = { ok: 'Active', dead: 'Dead', redirect: 'Redirect', found: 'Found', missing: 'Missing', unchecked: 'Unchecked', no_url: 'No URL' };
    return map[s] || s;
  }

  function statusClass(s) {
    var map = {
      ok: 'badge-current',
      found: 'badge-current',
      dead: 'badge-error',
      missing: 'badge-error',
      redirect: 'badge-redirect',
      unchecked: 'badge-inactive',
      no_url: 'badge-inactive'
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
    var linkBadge    = row.querySelector('[data-lc-link-badge]');
    var recipBadge   = row.querySelector('[data-lc-recip-badge]');
    var contentBadge = row.querySelector('[data-lc-content-badge]');
    var checkedCell  = row.querySelector('[data-lc-checked]');

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
    if (contentBadge) {
      var flags = data.content_flags;
      var flagLabel = '';
      if (flags) {
        if (flags.parked)             flagLabel = 'Parked?';
        else if (flags.directory_listing) flagLabel = 'Directory';
        else if (flags.meta_refresh)  flagLabel = 'Meta redirect';
      }
      if (flagLabel) {
        contentBadge.textContent = flagLabel;
        contentBadge.removeAttribute('hidden');
      } else {
        contentBadge.setAttribute('hidden', '');
      }
    }
    if (checkedCell) {
      checkedCell.textContent = data.checked_at || '';
    }
    if (panelId === 'joined' && data.listed_status !== undefined) {
      applyListedResult(row, data);
    }
    if (panelId === 'members' && data.backlink_status !== undefined) {
      applyBacklinkResult(row, data);
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
        if (data && data.error) {
          if (row) row.classList.remove('lc-busy');
          window.lstrToast('error', data.error);
          if (onDone) onDone();
          return;
        }
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
    var prefix    = panelId === 'affiliates' ? 'lc-aff-' : panelId === 'members' ? 'lc-mem-' : 'lc-joined-';

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
          if (progLabel) progLabel.textContent = done + ' / ' + total + ' — done.';
          if (progBar)   progBar.style.width   = '100%';
          startBtn.disabled = false;
          if (panelId === 'members') {
            setTimeout(function () { location.reload(); }, 400);
          }
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
      processIds(rows.map(function (r) { return r.id.replace(/^lc-(aff|joined|mem)-/, ''); }));
    }
  }

  // ── "Check All" buttons ───────────────────────────────────────────────────
  var checkAffBtn     = document.getElementById('lc-check-affiliates');
  var checkJoinedBtn  = document.getElementById('lc-check-joined');
  var checkMembersBtn = document.getElementById('lc-check-members');

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
  if (checkMembersBtn) {
    checkMembersBtn.addEventListener('click', function () {
      runBatch(checkMembersBtn, 'members');
    });
  }

  // ── Toolbar "Run Link Checker" proxy ─────────────────────────────────────
  var lcRunBtn = document.getElementById('lc-run-btn');
  if (lcRunBtn) {
    lcRunBtn.addEventListener('click', function () {
      var activeTab = document.querySelector('.tabs-btn.active[data-tab]');
      var tabId     = activeTab ? activeTab.dataset.tab : 'affiliates';
      var panelBtn  = tabId === 'joined' ? checkJoinedBtn
                    : tabId === 'members' ? checkMembersBtn
                    : checkAffBtn;
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
    var panelId  = type === 'affiliate' ? 'affiliates'
                 : type === 'member'    ? 'members'
                 : 'joined';
    var rowId    = type === 'affiliate' ? 'lc-aff-' + id
                 : type === 'member'    ? 'lc-mem-' + id
                 : 'lc-joined-' + id;
    var row      = document.getElementById(rowId);

    if (!row) return;

    var origText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Checking…';
    checkOne(endpoint, csrf, id, row, panelId, function () {
      btn.textContent = origText;
      btn.disabled = false;
    });
  });

  // ── Individual "Check Backlink" buttons (member backlink check, Step 4.1/4.2) ──
  function applyBacklinkResult(row, data) {
    var badge = row.querySelector('[data-lc-backlink-badge]');
    if (!badge) return;
    var newStatus = data.backlink_status || 'unchecked';
    var clsMap    = { found: 'badge-current', missing: 'badge-error', unchecked: 'badge-inactive' };
    var labelMap  = { found: 'Found', missing: 'Missing', unchecked: '—' };

    var oldStatus = badge.classList.contains('badge-current') ? 'found'
                  : badge.classList.contains('badge-error')   ? 'missing'
                  : 'unchecked';

    ['badge-current', 'badge-error', 'badge-inactive'].forEach(function (c) { badge.classList.remove(c); });
    badge.classList.add(clsMap[newStatus] || 'badge-inactive');
    badge.textContent = labelMap[newStatus] || newStatus;

    if (oldStatus !== newStatus) {
      var foundPill   = document.querySelector('[data-lc-backlink-pill="found"]');
      var missingPill = document.querySelector('[data-lc-backlink-pill="missing"]');
      if (oldStatus === 'found' && foundPill) {
        var n = parseInt(foundPill.textContent, 10);
        if (!isNaN(n)) foundPill.textContent = Math.max(0, n - 1) + ' Backlinked';
      } else if (oldStatus === 'missing' && missingPill) {
        var m = parseInt(missingPill.textContent, 10);
        if (!isNaN(m)) missingPill.textContent = Math.max(0, m - 1) + ' No Backlink';
      }
      if (newStatus === 'found' && foundPill) {
        var p = parseInt(foundPill.textContent, 10);
        if (!isNaN(p)) foundPill.textContent = (p + 1) + ' Backlinked';
      } else if (newStatus === 'missing' && missingPill) {
        var q = parseInt(missingPill.textContent, 10);
        if (!isNaN(q)) missingPill.textContent = (q + 1) + ' No Backlink';
      }
    }
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-lc-backlink');
    if (!btn) return;

    var id          = btn.dataset.id;
    var endpoint    = btn.dataset.endpoint;
    var csrf        = btn.dataset.csrf;
    var listingUrl  = btn.dataset.listingUrl || '';
    var row         = document.getElementById('lc-mem-' + id);

    if (!row || btn.disabled) return;

    btn.disabled = true;
    row.classList.add('lc-busy');

    var body = '_csrf=' + encodeURIComponent(csrf)
             + '&id=' + encodeURIComponent(id)
             + '&listing_url=' + encodeURIComponent(listingUrl);

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        row.classList.remove('lc-busy');
        if (data && data.error) { window.lstrToast('error', data.error); }
        else { applyBacklinkResult(row, data); }
        btn.disabled = false;
      })
      .catch(function () {
        row.classList.remove('lc-busy');
        btn.disabled = false;
      });
  });

  // ── Individual "Listed?" buttons (owner-listed check, Step 4.3) ───────────
  function applyListedResult(row, data) {
    var badge = row.querySelector('[data-lc-listed-badge]');
    if (!badge) return;
    var status    = data.listed_status || 'unchecked';
    var heuristic = status === 'listed' && data.heuristic !== false;
    var clsMap    = { listed: 'badge-current', not_found: 'badge-error', unchecked: 'badge-inactive' };
    var labelMap  = { listed: heuristic ? 'Name Match' : 'Listed', not_found: 'Not Listed', unchecked: '—' };
    ['badge-current', 'badge-error', 'badge-inactive'].forEach(function (c) { badge.classList.remove(c); });
    badge.classList.add(clsMap[status] || 'badge-inactive');
    badge.textContent = labelMap[status] || status;

    // Show/update the members URL link when available.
    var container = badge.parentElement;
    if (container) {
      var existing = container.querySelector('.lc-listed-members-url');
      if (data.members_url) {
        if (!existing) {
          existing = document.createElement('div');
          existing.className = 'text-muted link-break lc-listed-members-url';
          container.appendChild(existing);
        }
        existing.innerHTML = '<a href="' + data.members_url.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '" target="_blank" rel="noopener noreferrer" class="link">' + data.members_url.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</a>';
      } else if (existing) {
        existing.remove();
      }
    }
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-lc-listed');
    if (!btn) return;

    var id        = btn.dataset.id;
    var endpoint  = btn.dataset.endpoint;
    var csrf      = btn.dataset.csrf;
    var ownerName = btn.dataset.ownerName || '';
    var row       = document.getElementById('lc-joined-' + id);

    if (!row || btn.disabled) return;

    btn.disabled = true;
    row.classList.add('lc-busy');

    var body = '_csrf=' + encodeURIComponent(csrf)
             + '&id=' + encodeURIComponent(id)
             + '&owner_name=' + encodeURIComponent(ownerName);

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        row.classList.remove('lc-busy');
        if (data && data.error) { window.lstrToast('error', data.error); }
        else { applyListedResult(row, data); }
        btn.disabled = false;
      })
      .catch(function () {
        row.classList.remove('lc-busy');
        btn.disabled = false;
      });
  });

  // ── Link-checker tab persistence ─────────────────────────────────────────
  var lcTabInput = document.getElementById('lc-filter-tab');
  function lcOnTabChange(tabId) {
    if (lcTabInput) lcTabInput.value = tabId;
  }
  document.querySelectorAll('.tabs-btn[data-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () { lcOnTabChange(btn.dataset.tab); });
  });
  var lcTabSelect = document.querySelector('.tabs-select');
  if (lcTabSelect) {
    lcTabSelect.addEventListener('change', function () { lcOnTabChange(lcTabSelect.value); });
  }

  // Auto-activate tab from ?tab= URL param
  (function () {
    var params  = new URLSearchParams(window.location.search);
    var initTab = params.get('tab');
    if (!initTab) return;
    var tabBtn = document.querySelector('.tabs-btn[data-tab="' + initTab + '"]');
    if (tabBtn) tabBtn.click();
  })();

  // ── Per-panel "Run Link Checker" buttons ─────────────────────────────────
  document.querySelectorAll('.js-lc-run-panel').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var panelId = btn.dataset.panel;
      var map     = { affiliates: 'lc-check-affiliates', joined: 'lc-check-joined', members: 'lc-check-members' };
      var hidden  = document.getElementById(map[panelId] || '');
      if (hidden && !hidden.disabled) hidden.click();
    });
  });

  // ── Members a scan skipped: one callout per listing in #lc-scan-notices ────
  // Cloned from #lc-scan-notice-tpl and filled with textContent only (emails come
  // from member tables). Callouts aren't lifted into toasts, so they stay until
  // dismissed or the page is left.
  function scanNoticeId(listingId) {
    return 'lc-scan-notice-' + listingId;
  }

  function clearScanNotice(listingId) {
    var old = document.getElementById(scanNoticeId(listingId));
    if (old) old.remove();
  }

  function showScanNotice(data) {
    var box = document.getElementById('lc-scan-notices');
    var tpl = document.getElementById('lc-scan-notice-tpl');
    if (!box || !tpl || !tpl.content || !tpl.content.firstElementChild) return;

    var count   = parseInt(data.skipped, 10) || 0;
    var members = Array.isArray(data.skipped_members) ? data.skipped_members : [];
    var notice  = tpl.content.firstElementChild.cloneNode(true);
    notice.id   = scanNoticeId(data.listing_id);

    notice.querySelector('[data-notice-title]').textContent =
      (data.listing_name || 'Listing') + ' (#' + data.listing_id + '): ' +
      count + ' member' + (count !== 1 ? 's' : '') + ' skipped';

    var list = notice.querySelector('[data-notice-list]');
    members.forEach(function (m) {
      var li = document.createElement('li');
      li.textContent = (m.email ? m.email : '(no email)') + ' — ' + (m.reason || '');
      list.appendChild(li);
    });

    var more = count - members.length;
    notice.querySelector('[data-notice-more]').textContent = more > 0 ? 'and ' + more + ' more.' : '';

    var old = document.getElementById(notice.id);
    if (old) {
      old.replaceWith(notice);
    } else {
      box.appendChild(notice);
    }
  }

  // ── Scan All Listings ────────────────────────────────────────────────────
  var scanAllBtn = document.getElementById('lc-scan-all-btn');
  if (scanAllBtn) {
    scanAllBtn.addEventListener('click', function () {
      var ids      = JSON.parse(scanAllBtn.dataset.listingIds || '[]');
      var csrf     = scanAllBtn.dataset.csrf;
      var endpoint = scanAllBtn.dataset.endpoint;
      if (ids.length === 0) return;

      var progWrap  = document.getElementById('lc-scan-prog');
      var progBar   = document.getElementById('lc-scan-prog-bar');
      var progLabel = document.getElementById('lc-scan-prog-label');

      scanAllBtn.disabled = true;
      if (progWrap) progWrap.classList.remove('is-hidden');

      var done    = 0;
      var total   = ids.length;
      var skipped = 0;   // listings with no member table
      var errors  = 0;
      var membersSkipped    = 0; // members a scan couldn't store
      var listingsWithSkips = 0;

      function scanNext() {
        if (done >= total) {
          var parts = ['Sync complete.'];
          if (membersSkipped > 0) {
            parts.push(membersSkipped + ' member' + (membersSkipped !== 1 ? 's' : '') + ' skipped in ' +
              listingsWithSkips + ' listing' + (listingsWithSkips !== 1 ? 's' : '') + ' — details below.');
          }
          if (skipped > 0) parts.push(skipped + ' skipped (no member table).');
          if (errors > 0) parts.push(errors + ' error' + (errors !== 1 ? 's' : '') + '.');
          if (progBar) progBar.style.width = '100%';

          if (membersSkipped > 0) {
            // Something to read: no automatic reload, so the details stay on the page.
            if (progLabel) progLabel.textContent = parts.join(' ');
            if (progWrap && !document.getElementById('lc-scan-reload')) {
              var reloadBtn = document.createElement('button');
              reloadBtn.type        = 'button';
              reloadBtn.id          = 'lc-scan-reload';
              reloadBtn.className   = 'btn btn-secondary btn-xs';
              reloadBtn.textContent = 'Reload page';
              reloadBtn.addEventListener('click', function () { window.location.reload(); });
              progWrap.appendChild(reloadBtn);
            }
            return;
          }

          parts.push('Reloading…');
          if (progLabel) progLabel.textContent = parts.join(' ');
          setTimeout(function () { window.location.reload(); }, 800);
          return;
        }
        var id = ids[done];
        if (progLabel) progLabel.textContent = 'Syncing listing ' + (done + 1) + ' / ' + total + '…';
        if (progBar)   progBar.style.width   = Math.round((done / total) * 100) + '%';

        var fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('listing_id', id);

        fetch(endpoint, { method: 'POST', body: fd })
          .then(function (r) { return r.ok ? r.json() : r.json().catch(function () { return { error: 'Request failed' }; }); })
          .catch(function () { return { error: 'Network error' }; })
          .then(function (data) {
            if (data.error) {
              if (typeof data.error === 'string' && data.error.indexOf('no member table') !== -1) {
                skipped++;
              } else {
                errors++;
              }
            } else if ((parseInt(data.skipped, 10) || 0) > 0) {
              membersSkipped    += parseInt(data.skipped, 10);
              listingsWithSkips += 1;
              showScanNotice(data);
            } else if (data.listing_id !== undefined) {
              clearScanNotice(data.listing_id);
            }
            done++;
            scanNext();
          });
      }

      scanNext();
    });
  }

  // ── Per-row Scan buttons (delegated) ─────────────────────────────────────
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-lc-scan-listing');
    if (!btn) return;

    var lid      = btn.dataset.listingId;
    var csrf     = btn.dataset.csrf;
    var endpoint = btn.dataset.endpoint;
    var row      = document.getElementById('lc-mem-sum-' + lid);

    btn.disabled    = true;
    btn.textContent = 'Scanning…';

    var fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('listing_id', lid);

    fetch(endpoint, { method: 'POST', body: fd })
      .then(function (r) { return r.ok ? r.json() : r.json().catch(function () { return { error: 'Request failed' }; }); })
      .catch(function () { return { error: 'Network error' }; })
      .then(function (data) {
        if (data.error) {
          btn.disabled    = false;
          btn.textContent = 'Error';
          btn.classList.add('btn-danger');
          setTimeout(function () {
            btn.textContent = 'Scan';
            btn.classList.remove('btn-danger');
          }, 2000);
          return;
        }
        if (row) {
          var s = data.stats || {};
          function setCell(cls, val) {
            var el = row.querySelector(cls);
            if (el) el.innerHTML = val;
          }
          function fmtCell(key, v) {
            if (v === undefined || v === null) return '<span class="text-dim">&mdash;</span>';
            v = parseInt(v, 10);
            if (v === 0) return '<span class="text-dim">0</span>';
            if (key === 'dead')     return '<span class="badge badge-error">' + v + '</span>';
            if (key === 'redirect') return '<span class="badge badge-redirect">' + v + '</span>';
            return '' + v;
          }
          setCell('.lc-ms-total',     data.synced !== undefined ? (parseInt(data.synced,10) === 0 ? '<span class="text-dim">0</span>' : '' + data.synced) : '<span class="text-dim">&mdash;</span>');
          setCell('.lc-ms-ok',        fmtCell('ok', s.ok));
          setCell('.lc-ms-dead',      fmtCell('dead', s.dead));
          setCell('.lc-ms-redirect',  fmtCell('redirect', s.redirect));
          setCell('.lc-ms-unchecked', fmtCell('unchecked', s.unchecked));
          setCell('.lc-ms-no-url',    fmtCell('no_url', s.no_url));
        }
        if ((parseInt(data.skipped, 10) || 0) > 0) {
          showScanNotice(data);
        } else {
          clearScanNotice(lid);
        }
        btn.disabled    = false;
        btn.textContent = 'Scan';
      });
  });

}());

// ── Dismissible callouts ─────────────────────────────────────────────────────
// A click on [data-dismiss-callout] removes the .callout around it.
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-dismiss-callout]');
  if (!btn) return;
  var callout = btn.closest('.callout');
  if (callout) callout.remove();
});

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
    { label: 'General',            hint: 'Settings', url: base + '/admin/settings#panel-general' },
    { label: 'Account Details',    hint: 'Settings', url: base + '/admin/settings#panel-admin' },
    { label: 'Admin Accounts',     hint: 'Settings', url: base + '/admin/settings#panel-accounts' },
    { label: 'Notifications',      hint: 'Settings', url: base + '/admin/settings#panel-notifications' },
    { label: 'Appearance',         hint: 'Settings', url: base + '/admin/settings#panel-appearance' },
    { label: 'SMTP & Email',       hint: 'Settings', url: base + '/admin/settings#panel-smtp' },
    { label: 'Embed Form URL',      hint: 'Settings', url: base + '/admin/settings#panel-general' },
    { label: 'Link Checker Settings', hint: 'Link Checker', url: base + '/admin/link-checker/settings' },
    { label: 'Spam Filtering',     hint: 'Settings', url: base + '/admin/settings#panel-blacklist' },
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
    var h = location.hash.replace('#', '') || 'panel-general';
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
        '<div class="surface sp-card">' +
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
  pop.className = 'pop-anchor skin-pop';
  pop.setAttribute('aria-hidden', 'true');
  pop.innerHTML =
    '<div class="sp-panel" data-theme="light">' + PANEL + '</div>' +
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
        panel.style.setProperty('--brand-primary', '#ff7eb3');
        panel.style.setProperty('--gradient', '#ff7eb3, #78e8d8, #ffd166');
      } else {
        panel.dataset.skin = slug;
        panel.style.removeProperty('--brand-primary');
        panel.style.removeProperty('--gradient');
      }
    });
    var r = card.getBoundingClientRect();
    var x = Math.max(8, Math.min(r.left + r.width / 2 - POP_W / 2, window.innerWidth - POP_W - 8));
    var y = r.top - POP_H - GAP;
    if (y < 8) y = r.bottom + GAP;
    pop.style.left = x + 'px';
    pop.style.top  = y + 'px';
    pop.classList.add('pop-anchor--visible');
  }

  function hide() {
    timer = setTimeout(function () { pop.classList.remove('pop-anchor--visible'); }, 60);
  }

  cards.forEach(function (card) {
    card.addEventListener('mouseenter', function () { show(card); });
    card.addEventListener('mouseleave', hide);
    card.addEventListener('focus',      function () { show(card); });
    card.addEventListener('blur',       hide);
  });
}());


// ── 48b. Default mode picker (Light / Dark radio buttons) ─────────────────────
(function () {
  var radios = document.querySelectorAll('[name="default_theme_radio"]');
  if (!radios.length) return;

  radios.forEach(function (radio) {
    radio.addEventListener('change', function () {
      var theme = this.value;
      // Update active-class on labels immediately so selection is visible
      document.querySelectorAll('.theme-mode-btn').forEach(function (lbl) {
        lbl.classList.toggle('theme-mode-btn--active', lbl.querySelector('[name="default_theme_radio"]').value === theme);
      });
      // Persist to localStorage BEFORE form submit to prevent FOUC on redirect
      try { localStorage.setItem('lstr_theme', theme); } catch (e) {}
      document.documentElement.setAttribute('data-theme', theme);
      var hidden = document.getElementById('default-theme-value');
      if (hidden) { hidden.value = theme; }
      var form = document.getElementById('default-theme-form');
      if (form) { form.requestSubmit(); }
    });
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
        if (result) {
          result.innerHTML = data.ok
            ? '<span style="color:var(--color-success,#4caf50)">' + esc(data.message) + ' Reloading&hellip;</span>'
            : '<span style="color:var(--danger)">'     + esc(data.message) + '</span>';
        }
        if (data.ok) {
          setTimeout(function () { window.location.reload(); }, 800);
        } else {
          btn.disabled = false;
        }
      })
      .catch(function () {
        btn.disabled = false;
        if (result) result.innerHTML = '<span style="color:var(--danger)">Check failed. Please try again.</span>';
      });
  });
}());

// ── Settings: Outbound HTTPS health check ────────────────────────────────────

(function () {
  var btn = document.getElementById('health-check-http-btn');
  if (!btn) return;
  var result = document.getElementById('health-check-http-result');

  btn.addEventListener('click', function () {
    btn.disabled = true;
    if (result) result.innerHTML = '<span class="text-muted">Testing&hellip;</span>';

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
            : '<span style="color:var(--danger)">'     + esc(data.message) + '</span>';
        }
      })
      .catch(function () {
        btn.disabled = false;
        if (result) result.innerHTML = '<span style="color:var(--danger)">Test failed. Please try again.</span>';
      });
  });
}());

// ── Affiliate message modal ───────────────────────────────────────────────────

document.addEventListener('click', function (e) {
  var btn = e.target.closest('.js-aff-msg-open');
  if (!btn) return;
  e.preventDefault();
  var modal = document.getElementById('aff-msg-modal');
  if (!modal) return;

  var d       = btn.dataset;
  var name    = d.affName    || '';
  var url     = d.affUrl     || '';
  var email   = d.affEmail   || '';
  var image   = d.affImage   || '';
  var listing = d.affListing || '';
  var date    = d.affDate    || '';
  var msg     = d.affMsg     || '';

  var elTitle   = document.getElementById('aff-msg-modal-title');
  var elName    = document.getElementById('aff-msg-modal-name');
  var elUrl     = document.getElementById('aff-msg-modal-url');
  var elEmail   = document.getElementById('aff-msg-modal-email');
  var elListing = document.getElementById('aff-msg-modal-listing');
  var elDate    = document.getElementById('aff-msg-modal-date');
  var elImage   = document.getElementById('aff-msg-modal-image');
  var elBody    = document.getElementById('aff-msg-modal-body');

  if (elTitle) elTitle.textContent = name || 'Affiliate Application';
  if (elName)  elName.textContent  = name;
  if (elUrl)   elUrl.innerHTML     = url ? '<a href="' + esc(url) + '" target="_blank" rel="noopener noreferrer" class="link">' + esc(url) + '</a>' : '<span class="text-dim">&mdash;</span>';
  if (elEmail) elEmail.innerHTML   = email ? '<a href="mailto:' + esc(email) + '" class="link">' + esc(email) + '</a>' : '<span class="text-dim">&mdash;</span>';
  if (elListing) elListing.textContent = listing || '—';
  if (elDate)  elDate.textContent  = date || '—';
  if (elImage) {
    if (image) {
      elImage.innerHTML = '<img src="' + esc(image) + '" alt="" style="max-width:200px;max-height:100px;border-radius:3px;object-fit:contain">';
      elImage.hidden = false;
    } else {
      elImage.innerHTML = '';
      elImage.hidden = true;
    }
  }
  if (elBody) elBody.textContent = msg;
  var elMsgWrap = document.getElementById('aff-msg-modal-msg-wrap');
  if (elMsgWrap) elMsgWrap.hidden = !msg;

  modal._affPrevFocus = document.activeElement;
  modal.classList.remove('is-hidden');
  modal.style.display = '';
  var closeBtn = modal.querySelector('.modal-close');
  if (closeBtn) closeBtn.focus();
});

function affMsgClose() {
  var modal = document.getElementById('aff-msg-modal');
  if (!modal || modal.classList.contains('is-hidden')) return;
  modal.classList.add('is-hidden');
  if (modal._affPrevFocus && typeof modal._affPrevFocus.focus === 'function') {
    modal._affPrevFocus.focus();
  }
}

document.addEventListener('click', function (e) {
  if (e.target.closest('#aff-msg-modal .modal-close')) { affMsgClose(); return; }
  var modal = document.getElementById('aff-msg-modal');
  if (!modal || modal.classList.contains('is-hidden')) return;
  if (e.target === modal) affMsgClose();
});

document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') affMsgClose();
});

// ── Adoption application modal ────────────────────────────────────────────────

document.addEventListener('click', function (e) {
  var btn = e.target.closest('.js-adopt-app-open');
  if (!btn) return;
  e.preventDefault();
  var modal = document.getElementById('adopt-app-modal');
  if (!modal) return;

  var d       = btn.dataset;
  var name    = d.appName    || '';
  var url     = d.appUrl     || '';
  var email   = d.appEmail   || '';
  var listing = d.appListing || '';
  var date    = d.appDate    || '';
  var msg     = d.appMsg     || '';

  var elTitle   = document.getElementById('adopt-app-modal-title');
  var elName    = document.getElementById('adopt-app-modal-name');
  var elUrl     = document.getElementById('adopt-app-modal-url');
  var elEmail   = document.getElementById('adopt-app-modal-email');
  var elListing = document.getElementById('adopt-app-modal-listing');
  var elDate    = document.getElementById('adopt-app-modal-date');
  var elBody    = document.getElementById('adopt-app-modal-body');

  if (elTitle)   elTitle.textContent   = name || 'Adoption Application';
  if (elName)    elName.textContent    = name;
  if (elUrl)     elUrl.innerHTML       = url ? '<a href="' + esc(url) + '" target="_blank" rel="noopener noreferrer" class="link">' + esc(url) + '</a>' : '<span class="text-dim">&mdash;</span>';
  if (elEmail)   elEmail.innerHTML     = email ? '<a href="mailto:' + esc(email) + '" class="link">' + esc(email) + '</a>' : '<span class="text-dim">&mdash;</span>';
  if (elListing) elListing.textContent = listing || '—';
  if (elDate)    elDate.textContent    = date || '—';
  if (elBody)    elBody.textContent    = msg;
  var elMsgWrap = document.getElementById('adopt-app-modal-msg-wrap');
  if (elMsgWrap) elMsgWrap.hidden = !msg;

  modal._adoptPrevFocus = document.activeElement;
  modal.classList.remove('is-hidden');
  modal.style.display = '';
  var closeBtn = modal.querySelector('.modal-close');
  if (closeBtn) closeBtn.focus();
});

function adoptAppClose() {
  var modal = document.getElementById('adopt-app-modal');
  if (!modal || modal.classList.contains('is-hidden')) return;
  modal.classList.add('is-hidden');
  if (modal._adoptPrevFocus && typeof modal._adoptPrevFocus.focus === 'function') {
    modal._adoptPrevFocus.focus();
  }
}

document.addEventListener('click', function (e) {
  if (e.target.closest('#adopt-app-modal .modal-close')) { adoptAppClose(); return; }
  var modal = document.getElementById('adopt-app-modal');
  if (!modal || modal.classList.contains('is-hidden')) return;
  if (e.target === modal) adoptAppClose();
});

document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') adoptAppClose();
});

// Listing edit: dependent rows in the embed settings tab.
(function () {
  var affiliateApplyToggle = document.getElementById('fl-affiliate-apply-enabled');
  var affiliateApplyRow    = document.getElementById('fl-aff-form-url-row');
  if (affiliateApplyToggle) {
    affiliateApplyToggle.addEventListener('change', function () {
      var show = affiliateApplyToggle.checked;
      if (affiliateApplyRow)  affiliateApplyRow.style.display  = show ? '' : 'none';
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

// ── 47. Join Rules admin pages ────────────────────────────────────────────────

(function () {

  // ── Select-all for Rules index ─────────────────────────────────────────────
  var selAll   = document.getElementById('rules-sel-all');
  var bulkForm = document.getElementById('rules-bulk-form');
  if (selAll && bulkForm) {
    selAll.addEventListener('change', function () {
      var boxes = document.getElementsByClassName('rules-row-cb');
      for (var i = 0; i < boxes.length; i++) { boxes[i].checked = selAll.checked; }
    });
    bulkForm.addEventListener('change', function (e) {
      if (!e.target.classList.contains('rules-row-cb')) return;
      var boxes = document.getElementsByClassName('rules-row-cb');
      var count = 0;
      for (var i = 0; i < boxes.length; i++) { if (boxes[i].checked) count++; }
      selAll.checked       = boxes.length > 0 && count === boxes.length;
      selAll.indeterminate = count > 0 && count < boxes.length;
    });
  }

  // ── Type filter auto-submit ────────────────────────────────────────────────
  var typeSelect = document.getElementById('rules-type-select');
  if (typeSelect) {
    typeSelect.addEventListener('change', function () { typeSelect.form.submit(); });
  }

  // ── Gate show/hide helper ─────────────────────────────────────────────────
  // Gate Label stays visible regardless of the toggle — it's a single setting
  // shared by both gate styles, so admins can pre-configure it before enabling.
  function wireGate(toggleId, styleRowId) {
    var tog  = document.getElementById(toggleId);
    var sRow = document.getElementById(styleRowId);
    if (!tog || !sRow) return;
    function sync() {
      sRow.style.display = tog.checked ? '' : 'none';
    }
    tog.addEventListener('change', sync);
    sync(); // Correct initial state regardless of server-rendered style attribute
  }

  // Standalone edit page
  wireGate('rules-enabled',        'rules-gate-style-row');
  // Listing edit — Rules tab
  wireGate('rules-tab-enabled',    'rules-tab-gate-style-row');
  // Global rules template page
  wireGate('global-rules-enabled', 'global-rules-gate-style-row');

  // After a native form reset, re-sync wireGate visibility for whichever
  // rules checkbox lives in the form that was reset.
  document.addEventListener('reset', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') return;
    setTimeout(function () {
      ['rules-enabled', 'rules-tab-enabled', 'global-rules-enabled'].forEach(function (id) {
        var cb = document.getElementById(id);
        if (cb && form.contains(cb)) {
          cb.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    }, 0);
  });

}());

// ── 48. Collective Quick Actions: drag-and-drop tile reorder ───────────────────
//
// Each .collective-action-tile in #qa-grid carries a .qa-drag-handle (grip icon).
// draggable="true" is set on the handle so dragging doesn't fight with the tile's
// own link navigation; dragstart/dragend fire on the handle, dragover/drop on the
// tile. On drop, the new tile order is posted to the reorder endpoint so it
// persists across page loads.

(function () {
  var grid = document.getElementById('qa-grid');
  if (!grid) return;

  var reorderUrl = grid.dataset.qaReorderUrl;
  var csrf       = grid.dataset.qaCsrf;
  var dragSrc    = null;

  grid.querySelectorAll('.qa-drag-handle').forEach(function (handle) {
    handle.setAttribute('draggable', 'true');

    handle.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
    });

    handle.addEventListener('dragstart', function (e) {
      dragSrc = handle.closest('.collective-action-tile');
      if (!dragSrc) { e.preventDefault(); return; }
      dragSrc.classList.add('dnd-dragging');
      e.dataTransfer.effectAllowed = 'move';
      // Firefox requires at least one setData call for drag to proceed
      e.dataTransfer.setData('text/plain', dragSrc.dataset.actionKey || '');
    });

    handle.addEventListener('dragend', function () {
      if (dragSrc) { dragSrc.classList.remove('dnd-dragging'); }
      grid.querySelectorAll('.collective-action-tile').forEach(function (t) {
        t.classList.remove('dnd-over');
      });
      dragSrc = null;
    });
  });

  grid.querySelectorAll('.collective-action-tile').forEach(function (tile) {
    tile.addEventListener('dragover', function (e) {
      if (!dragSrc || dragSrc === tile) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      tile.classList.add('dnd-over');
    });

    tile.addEventListener('dragleave', function (e) {
      if (!tile.contains(e.relatedTarget)) {
        tile.classList.remove('dnd-over');
      }
    });

    tile.addEventListener('drop', function (e) {
      e.preventDefault();
      tile.classList.remove('dnd-over');
      if (!dragSrc || dragSrc === tile) return;

      var nodes  = Array.from(grid.children);
      var srcIdx = nodes.indexOf(dragSrc);
      var tgtIdx = nodes.indexOf(tile);
      if (srcIdx < tgtIdx) {
        grid.insertBefore(dragSrc, tile.nextSibling);
      } else {
        grid.insertBefore(dragSrc, tile);
      }

      var order = Array.from(grid.querySelectorAll('.collective-action-tile')).map(function (t) {
        return t.dataset.actionKey;
      });

      var params = new URLSearchParams();
      params.append('_csrf', csrf);
      order.forEach(function (key) { params.append('order[]', key); });

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
  });
}());

// ── Scroll-to-top button ──────────────────────────────────────────────────────
(function () {
  var btn = document.getElementById('scroll-to-top');
  if (!btn) return;

  var threshold = 400;
  var visible   = false;

  window.addEventListener('scroll', function () {
    var shouldShow = window.scrollY > threshold;
    if (shouldShow !== visible) {
      visible = shouldShow;
      btn.classList.toggle('is-visible', visible);
    }
  }, { passive: true });

  btn.addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
}());

// ── Embed snippet copy buttons ────────────────────────────────────────────────
document.addEventListener('click', function (e) {
  var btn = e.target.closest('.embed-copy-btn');
  if (!btn) return;
  var text = btn.getAttribute('data-copy') || '';
  navigator.clipboard.writeText(text).then(function () {
    var orig = btn.textContent;
    btn.textContent = 'Copied!';
    setTimeout(function () { btn.textContent = orig; }, 1500);
  });
});

// ── Template pack: export / import / copy / revision history ─────────────────
(function () {
  'use strict';

  // tplPanel may be null on global/collective template pages; only revision
  // history depends on it -- export/import/copy work without it.
  var tplPanel = document.getElementById('tab-templates');
  var tplBase  = tplPanel ? (tplPanel.dataset.tplBase || '') : '';
  var tplCsrf  = tplPanel ? (tplPanel.dataset.tplCsrf || '') : '';

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // --- Inject "History" buttons into each template section (per-listing only) ---
  if (tplPanel) {
    tplPanel.querySelectorAll('.tpl-group-section').forEach(function (section) {
      var textarea = section.querySelector('textarea[name]');
      var resetRow = section.querySelector('.tpl-reset-row');
      if (!textarea || !resetRow) return;

      var key = textarea.name;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-ghost btn-xs tpl-history-btn';
      btn.dataset.tplKey = key;
      btn.textContent = 'History';
      resetRow.appendChild(document.createTextNode('  '));
      resetRow.appendChild(btn);
    });
  }

  // ── Export ────────────────────────────────────────────────────────────────
  var exportBtn = document.getElementById('fl-tpack-export-btn');
  if (exportBtn) {
    exportBtn.addEventListener('click', function () {
      var form = document.createElement('form');
      form.method = 'POST';
      form.action = exportBtn.dataset.endpoint || '';

      var csrf = document.createElement('input');
      csrf.type = 'hidden'; csrf.name = '_csrf'; csrf.value = exportBtn.dataset.csrf || '';
      form.appendChild(csrf);
      document.body.appendChild(form);
      form.submit();
      document.body.removeChild(form);
    });
  }

  // ── Export as ZIP ─────────────────────────────────────────────────────────
  var exportZipBtn = document.getElementById('fl-tpack-export-zip-btn');
  if (exportZipBtn) {
    exportZipBtn.addEventListener('click', function () {
      var form = document.createElement('form');
      form.method = 'POST';
      form.action = exportZipBtn.dataset.endpoint || '';

      var csrf = document.createElement('input');
      csrf.type = 'hidden'; csrf.name = '_csrf'; csrf.value = exportZipBtn.dataset.csrf || '';
      form.appendChild(csrf);
      document.body.appendChild(form);
      form.submit();
      document.body.removeChild(form);
    });
  }

  // ── Import ────────────────────────────────────────────────────────────────
  var importBtn  = document.getElementById('fl-tpack-import-btn');
  var fileInput  = document.getElementById('fl-tpack-file-input');

  if (importBtn && fileInput) {
    importBtn.addEventListener('click', function () { fileInput.click(); });

    fileInput.addEventListener('change', function () {
      var file = fileInput.files && fileInput.files[0];
      if (!file) return;

      var fd = new FormData();
      fd.append('_csrf', importBtn.dataset.csrf || '');
      fd.append('template_pack', file);

      importBtn.disabled = true;
      importBtn.textContent = 'Parsing…';

      fetch(importBtn.dataset.endpoint || '', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          importBtn.disabled = false;
          importBtn.textContent = 'Import Templates…';
          fileInput.value = '';

          if (data.error) { window.lstrToast('error', data.error); return; }

          showTpackModal('Import Templates', buildImportPreviewHtml(data), function (modal) {
            var applyBtn = modal.querySelector('.tpack-modal-apply');
            if (!applyBtn) return;

            applyBtn.addEventListener('click', function () {
              var selected = {};
              modal.querySelectorAll('.tpack-key-check:checked').forEach(function (cb) {
                var key = cb.dataset.key;
                var val = cb.dataset.value;
                selected[key] = val === '__null__' ? null : val;
              });

              var body = new URLSearchParams();
              var applyEndpoint = importBtn.dataset.applyEndpoint || '';
              body.append('_csrf', importBtn.dataset.csrf || '');
              body.append('templates_json', JSON.stringify(selected));

              applyBtn.disabled = true;
              applyBtn.textContent = 'Applying…';
              fetch(applyEndpoint, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                  closeTpackModal(modal);
                  if (d.ok) {
                    window.lstrToast('success', 'Imported ' + (d.saved || 0) + ' template(s). Reloading…');
                    setTimeout(function () { window.location.reload(); }, 1000);
                  } else {
                    window.lstrToast('error', d.error || 'Import failed.');
                  }
                })
                .catch(function () { applyBtn.disabled = false; applyBtn.textContent = 'Apply'; window.lstrToast('error', 'Request failed.'); });
            });
          });
        })
        .catch(function () {
          importBtn.disabled = false;
          importBtn.textContent = 'Import Templates…';
          window.lstrToast('error', 'Failed to parse pack. Please try again.');
        });
    });
  }

  function buildImportPreviewHtml(data) {
    var meta = data.metadata || {};
    var changes = data.changes || [];
    var html = '<p class="form-hint" style="margin-bottom:.5rem">';
    if (meta.listing_title) html += 'From: <strong>' + esc(meta.listing_title) + '</strong>.  ';
    if (meta.luster_version) html += 'Luster v' + esc(meta.luster_version) + '.  ';
    if (meta.exported_at) html += 'Exported: ' + esc(meta.exported_at.replace('T', ' ').replace('Z', ' UTC')) + '.';
    html += '</p>';

    if (meta.missing_addons && meta.missing_addons.length > 0) {
      html += '<p class="form-hint" style="color:var(--color-warning)">Warning: pack requires add-on(s) not installed: ' + esc(meta.missing_addons.join(', ')) + '</p>';
    }

    var hasChanges = changes.some(function (c) { return c.changed; });
    if (!hasChanges) {
      html += '<p class="form-hint">All incoming templates are identical to current values.</p>';
    }

    html += '<table class="data-table" style="width:100%;font-size:.85rem"><thead><tr><th>Template</th><th>Status</th><th style="width:1px">Apply</th></tr></thead><tbody>';
    changes.forEach(function (c) {
      var statusText = c.changed ? '<span class="badge badge-current badge-inline-sm">Changed</span>' : '<span class="badge badge-inactive badge-inline-sm">Same</span>';
      var encodedVal = encodeURIComponent(c.incoming === null ? '__null__' : c.incoming);
      html += '<tr>'
        + '<td>' + esc(c.label) + '</td>'
        + '<td>' + statusText + '</td>'
        + '<td><input type="checkbox" class="tpack-key-check" data-key="' + esc(c.key) + '" data-value="' + esc(c.incoming === null ? '__null__' : c.incoming) + '"' + (c.changed ? ' checked' : '') + '></td>'
        + '</tr>';
    });
    html += '</tbody></table>';
    html += '<div style="margin-top:1rem;display:flex;gap:.5rem"><button type="button" class="btn btn-primary tpack-modal-apply">Apply Selected</button><button type="button" class="btn btn-ghost tpack-modal-cancel">Cancel</button></div>';
    return html;
  }

  // ── Copy From ─────────────────────────────────────────────────────────────
  var copyBtn = document.getElementById('fl-tpack-copy-btn');
  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      fetch(copyBtn.dataset.listingsEndpoint || '', { method: 'GET' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) { window.lstrToast('error', data.error || 'Could not load listings.'); return; }
          showTpackModal('Copy Templates From Another Listing', buildCopyPickerHtml(data.listings), function (modal) {
            var sel = modal.querySelector('#tpack-copy-listing-sel');
            var previewArea = modal.querySelector('#tpack-copy-preview');
            var applyBtn = modal.querySelector('.tpack-modal-apply');
            if (!sel || !previewArea || !applyBtn) return;

            function loadSource() {
              var fromId = sel.value;
              if (!fromId) { previewArea.innerHTML = ''; return; }
              previewArea.innerHTML = '<p class="form-hint">Loading…</p>';
              fetch((copyBtn.dataset.sourceEndpoint || '') + '?from=' + encodeURIComponent(fromId))
                .then(function (r) { return r.json(); })
                .then(function (d) {
                  if (!d.ok) { previewArea.innerHTML = '<p class="form-hint" style="color:var(--danger)">' + esc(d.error || 'Error') + '</p>'; return; }
                  previewArea.innerHTML = buildCopyChangesHtml(d.changes, d.listing_subject);
                });
            }

            sel.addEventListener('change', loadSource);

            applyBtn.addEventListener('click', function () {
              var fromId = sel.value;
              if (!fromId) { window.lstrToast('error', 'Select a source listing first.'); return; }
              var keys = [];
              modal.querySelectorAll('.tpack-key-check:checked').forEach(function (cb) { keys.push(cb.dataset.key); });
              if (!keys.length) { window.lstrToast('error', 'Select at least one template to copy.'); return; }

              var body = new URLSearchParams();
              body.append('_csrf', copyBtn.dataset.csrf || '');
              body.append('from', fromId);
              body.append('keys_json', JSON.stringify(keys));

              applyBtn.disabled = true; applyBtn.textContent = 'Copying…';
              fetch(copyBtn.dataset.applyEndpoint || '', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                  closeTpackModal(modal);
                  if (d.ok) {
                    window.lstrToast('success', 'Copied ' + (d.saved || 0) + ' template(s). Reloading…');
                    setTimeout(function () { window.location.reload(); }, 1000);
                  } else {
                    window.lstrToast('error', d.error || 'Copy failed.');
                  }
                })
                .catch(function () { applyBtn.disabled = false; applyBtn.textContent = 'Apply'; window.lstrToast('error', 'Request failed.'); });
            });
          });
        })
        .catch(function () { window.lstrToast('error', 'Failed to load listings.'); });
    });
  }

  function buildCopyPickerHtml(listings) {
    var html = '<div class="field-row"><label class="field-label" for="tpack-copy-listing-sel">Source Listing</label><div><div class="select-wrap"><select id="tpack-copy-listing-sel" class="form"><option value="">Select a listing&hellip;</option>';
    listings.forEach(function (l) { html += '<option value="' + esc(l.id) + '">' + esc(l.subject) + '</option>'; });
    html += '</select></div></div></div><div id="tpack-copy-preview" style="margin-top:1rem"></div>';
    html += '<div style="margin-top:1rem;display:flex;gap:.5rem"><button type="button" class="btn btn-primary tpack-modal-apply">Copy Selected</button><button type="button" class="btn btn-ghost tpack-modal-cancel">Cancel</button></div>';
    return html;
  }

  function buildCopyChangesHtml(changes, fromSubject) {
    var html = '<p class="form-hint">Templates from <strong>' + esc(fromSubject || '') + '</strong>:</p>';
    html += '<table class="data-table" style="width:100%;font-size:.85rem"><thead><tr><th>Template</th><th>Status</th><th style="width:1px">Copy</th></tr></thead><tbody>';
    changes.forEach(function (c) {
      var statusText = c.changed ? '<span class="badge badge-current badge-inline-sm">Different</span>' : '<span class="badge badge-inactive badge-inline-sm">Same</span>';
      var noteHtml = c.linked_note
        ? '<br><span class="form-hint" style="font-size:.75rem">Also copies: ' + esc(c.linked_note) + '</span>'
        : '';
      html += '<tr><td>' + esc(c.label) + noteHtml + '</td><td>' + statusText + '</td><td><input type="checkbox" class="tpack-key-check" data-key="' + esc(c.key) + '" checked></td></tr>';
    });
    html += '</tbody></table>';
    return html;
  }

  // ── Revision History ──────────────────────────────────────────────────────
  var histModal    = document.getElementById('tpl-hist-modal');
  var histTitle    = document.getElementById('tpl-hist-title');
  var histBody     = document.getElementById('tpl-hist-body');
  var histClose    = document.getElementById('tpl-hist-close');
  var histCancel   = document.getElementById('tpl-hist-cancel');
  var histFlush    = document.getElementById('tpl-hist-flush');
  var _histKey     = '';
  var _histTextarea = null;

  function openHistModal(title) {
    if (!histModal) return;
    if (histTitle) histTitle.textContent = title;
    histModal.classList.remove('is-hidden');
  }
  function closeHistModal() {
    if (histModal) histModal.classList.add('is-hidden');
    _histKey = '';
    _histTextarea = null;
  }

  if (histClose)  histClose.addEventListener('click', closeHistModal);
  if (histCancel) histCancel.addEventListener('click', closeHistModal);
  if (histModal)  histModal.addEventListener('click', function (e) { if (e.target === histModal) closeHistModal(); });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && histModal && !histModal.classList.contains('is-hidden')) closeHistModal();
  });

  function renderHistoryBody(revisions) {
    if (!revisions || !revisions.length) {
      histBody.innerHTML = '<p class="form-hint">No revision history yet. Revisions are recorded each time you save the template.</p>';
      return;
    }
    var html = '<p class="form-hint" style="margin-bottom:.5rem">Clicking &ldquo;Restore&rdquo; puts the old value into the editor &mdash; save the form to keep it.</p>';
    html += '<table class="data-table" style="width:100%;font-size:.85rem"><thead><tr><th>Saved At</th><th>Preview</th><th></th></tr></thead><tbody>';
    revisions.forEach(function (r) {
      var preview = r.preview ? r.preview.substring(0, 60).replace(/\s+/g, ' ') : '(empty)';
      html += '<tr><td style="white-space:nowrap">' + esc(r.saved_at) + '</td>'
            + '<td style="font-family:monospace;font-size:.8em">' + esc(preview) + (r.preview && r.preview.length > 60 ? '&hellip;' : '') + '</td>'
            + '<td><button type="button" class="btn btn-xs btn-ghost tpack-restore-btn" data-rev-id="' + esc(String(r.id)) + '"' + (!r.has_value ? ' disabled' : '') + '>Restore</button></td></tr>';
    });
    html += '</tbody></table>';
    histBody.innerHTML = html;
  }

  if (histBody) {
    histBody.addEventListener('click', function (ev) {
      var restoreBtn = ev.target.closest('.tpack-restore-btn');
      if (!restoreBtn || !_histKey) return;
      var revId = restoreBtn.dataset.revId;
      fetch(tplBase + '/template-revisions/' + encodeURIComponent(revId))
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) { window.lstrToast('error', 'Could not load revision.'); return; }
          if (_histTextarea) {
            _histTextarea.value = d.value !== null ? d.value : '';
            _histTextarea.dispatchEvent(new Event('input'));
            window.lstrToast('success', 'Revision loaded into editor. Save the form to keep it.');
          }
          closeHistModal();
        })
        .catch(function () { window.lstrToast('error', 'Request failed.'); });
    });
  }

  if (histFlush) {
    histFlush.addEventListener('click', function () {
      if (!_histKey || !tplBase) return;
      var body = new URLSearchParams();
      body.append('_csrf', tplCsrf || '');
      body.append('key', _histKey);
      fetch(tplBase + '/template-revisions/flush', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok) {
            renderHistoryBody([]);
            window.lstrToast('success', 'Revision history cleared.');
          } else {
            window.lstrToast('error', data.error || 'Could not flush history.');
          }
        })
        .catch(function () { window.lstrToast('error', 'Request failed.'); });
    });
  }

  if (tplPanel) {
    tplPanel.addEventListener('click', function (e) {
      var btn = e.target.closest('.tpl-history-btn');
      if (!btn) return;

      var key     = btn.dataset.tplKey || '';
      var section = btn.closest('.tpl-group-section');
      _histTextarea = section ? section.querySelector('textarea[name="' + key + '"]') : null;
      _histKey      = key;

      openHistModal('Revision History: ' + key.replace(/_/g, ' '));
      if (histBody) histBody.innerHTML = '<p class="form-hint">Loading&hellip;</p>';

      fetch(tplBase + '/template-revisions?key=' + encodeURIComponent(key))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) { window.lstrToast('error', data.error || 'Could not load history.'); closeHistModal(); return; }
          renderHistoryBody(data.revisions);
        })
        .catch(function () { window.lstrToast('error', 'Failed to load history.'); closeHistModal(); });
    });
  }

  // ── Modal helpers ─────────────────────────────────────────────────────────
  function showTpackModal(title, bodyHtml, onReady) {
    var existing = document.getElementById('tpack-modal');
    if (existing) existing.remove();

    var overlay = document.createElement('div');
    overlay.id = 'tpack-modal';
    overlay.className = 'modal-backdrop';

    var dialog = document.createElement('div');
    dialog.className = 'modal modal--wide';

    var hdr = document.createElement('div');
    hdr.className = 'modal-header';

    var titleEl = document.createElement('span');
    titleEl.className = 'modal-title';
    titleEl.textContent = title;

    var headerActions = document.createElement('div');
    headerActions.className = 'modal-header-actions';
    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'btn btn-tertiary btn-sm';
    closeBtn.setAttribute('aria-label', 'Close');
    closeBtn.innerHTML = '× Close';
    closeBtn.addEventListener('click', function () { closeTpackModal(overlay); });
    headerActions.appendChild(closeBtn);

    hdr.appendChild(titleEl);
    hdr.appendChild(headerActions);

    var body = document.createElement('div');
    body.className = 'modal-body';
    body.innerHTML = bodyHtml;

    body.addEventListener('click', function (e) {
      if (e.target.closest('.tpack-modal-cancel')) closeTpackModal(overlay);
    });

    dialog.appendChild(hdr);
    dialog.appendChild(body);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeTpackModal(overlay);
    });

    var escHandler = function (e) { if (e.key === 'Escape') closeTpackModal(overlay); };
    overlay._escHandler = escHandler;
    document.addEventListener('keydown', escHandler);

    if (onReady) onReady(body);
    return overlay;
  }

  function closeTpackModal(el) {
    var modal = el.closest ? el.closest('#tpack-modal') : (el.id === 'tpack-modal' ? el : document.getElementById('tpack-modal'));
    if (modal) {
      if (modal._escHandler) document.removeEventListener('keydown', modal._escHandler);
      modal.remove();
    }
  }
}());

// ── TFL/TAFL approval URL lookup ─────────────────────────────────────────────
(function () {
  'use strict';

  var approvedBySel = document.getElementById('fl-approved-by');
  var approvalUrlRow = document.getElementById('fl-approval-url-row');
  var approvalUrlInput = document.getElementById('fl-approval-url');
  var lookupBtn = document.getElementById('fl-tfl-lookup-btn');
  var clearCacheBtn = document.getElementById('fl-tfl-clear-cache-btn');
  var lookupResult = document.getElementById('fl-tfl-lookup-result');

  if (!approvedBySel) return;

  function showApprovalRow() {
    if (approvalUrlRow) {
      approvalUrlRow.style.display = approvedBySel.value !== '' ? '' : 'none';
    }
  }

  // Show/hide the approval URL row based on network selection
  approvedBySel.addEventListener('change', function () {
    showApprovalRow();
    // Clear the saved URL when the admin switches networks to avoid stale data
    if (approvalUrlInput && approvalUrlInput.value !== '') {
      approvalUrlInput.value = '';
      if (lookupResult) {
        lookupResult.style.display = 'none';
        lookupResult.innerHTML = '';
      }
    }
  });

  function performLookup() {
    var network = approvedBySel.value;
    if (network !== 'tfl' && network !== 'tafl') {
      lookupResult.style.display = '';
      lookupResult.innerHTML = '<p class="form-hint" style="color:var(--danger)">Select TFL or TAFL first.</p>';
      return;
    }

    var listingId = lookupBtn.dataset.listingId || '0';
    lookupBtn.disabled = true;
    lookupBtn.textContent = 'Searching…';
    lookupResult.style.display = '';
    lookupResult.innerHTML = '<p class="form-hint">Searching ' + network.toUpperCase() + '…</p>';

    var csrf = (document.querySelector('input[name="_csrf"]') || {}).value || '';
    var params = new URLSearchParams({ _csrf: csrf, listing_id: listingId, network: network });
    var endpoint = lookupBtn.dataset.endpoint || '';

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        lookupBtn.disabled = false;
        lookupBtn.textContent = 'Look up';

        if (data.error) {
          lookupResult.innerHTML = '<p class="form-hint" style="color:var(--danger)">' + esc(data.error) + '</p>';
          return;
        }

        var html = '';
        // The clickable link shown per match is always the fanlisting's own
        // URL, exactly as it appears on the network — so the admin can click
        // through and visually confirm this is really their own site before
        // trusting the match. The "Use" button writes data.network_url
        // instead (the network's own category page): {tfl_url}/{tafl_url}
        // render on this fanlisting's own stats page, so the saved Approval
        // Page URL needs to point AT the network, not back at this same site.
        var useLabel = 'Use ' + network.toUpperCase() + ' Page URL';

        if (data.url) {
          html += '<p class="form-hint"><strong>Exact match found</strong> &mdash; listed at '
            + '<a href="' + esc(data.url) + '" target="_blank" rel="noopener noreferrer" class="link">' + esc(data.url) + '</a> '
            + '&mdash; <button type="button" class="btn btn-tertiary" data-use-url="' + esc(data.network_url || '') + '">' + esc(useLabel) + '</button></p>';
        } else if (data.candidates && data.candidates.length > 0) {
          html += '<p class="form-hint"><strong>No exact match. Possible matches:</strong></p><ul style="margin:.25rem 0 0 1rem;font-size:.85rem">';
          data.candidates.forEach(function (c) {
            html += '<li><a href="' + esc(c.url) + '" target="_blank" rel="noopener noreferrer" class="link">' + esc(c.text) + '</a> '
              + '&mdash; <button type="button" class="btn btn-tertiary" data-use-url="' + esc(data.network_url || '') + '">' + esc(useLabel) + '</button></li>';
          });
          html += '</ul>';
        } else {
          html += '<p class="form-hint">No match found. Try entering the URL manually.</p>';
        }

        if (data.cache_age !== null && data.cache_age !== undefined) {
          var ageMin = Math.round(data.cache_age / 60);
          html += '<p class="form-hint" style="color:var(--color-muted);font-size:.8rem">Index cache: '
            + (ageMin < 1 ? 'just fetched' : ageMin + ' min old') + '</p>';
        }

        lookupResult.innerHTML = html;
      })
      .catch(function () {
        lookupBtn.disabled = false;
        lookupBtn.textContent = 'Look up';
        lookupResult.innerHTML = '<p class="form-hint" style="color:var(--danger)">Request failed. Please try again.</p>';
      });
  }

  // Lookup button
  if (lookupBtn && lookupResult && approvalUrlInput) {
    lookupBtn.addEventListener('click', performLookup);

    // "Use this URL" button delegation
    lookupResult.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-use-url]');
      if (!btn) return;
      approvalUrlInput.value = btn.dataset.useUrl;
    });
  }

  // Clear cache button — forces the next lookup to re-fetch from the network
  // instead of reusing a cached result for up to 24 hours, then immediately
  // re-runs the lookup so fresh results show right away.
  if (clearCacheBtn && lookupResult) {
    clearCacheBtn.addEventListener('click', function () {
      var network = approvedBySel.value;
      if (network !== 'tfl' && network !== 'tafl') {
        lookupResult.style.display = '';
        lookupResult.innerHTML = '<p class="form-hint" style="color:var(--danger)">Select TFL or TAFL first.</p>';
        return;
      }

      var listingId = clearCacheBtn.dataset.listingId || '0';
      clearCacheBtn.disabled = true;
      clearCacheBtn.textContent = 'Clearing…';

      var csrf = (document.querySelector('input[name="_csrf"]') || {}).value || '';
      var params = new URLSearchParams({ _csrf: csrf, listing_id: listingId, network: network });
      var endpoint = clearCacheBtn.dataset.endpoint || '';

      fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString(),
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          clearCacheBtn.disabled = false;
          clearCacheBtn.textContent = 'Clear cache';

          if (!data.ok) {
            lookupResult.style.display = '';
            lookupResult.innerHTML = '<p class="form-hint" style="color:var(--danger)">' + esc(data.error || 'Could not clear cache.') + '</p>';
            return;
          }

          performLookup();
        })
        .catch(function () {
          clearCacheBtn.disabled = false;
          clearCacheBtn.textContent = 'Clear cache';
          lookupResult.style.display = '';
          lookupResult.innerHTML = '<p class="form-hint" style="color:var(--danger)">Request failed. Please try again.</p>';
        });
    });
  }

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
}());

// ── TFL/TAFL lookup buttons in Link Checker (dead + moved joined rows) ────────
(function () {
  'use strict';

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // Lookup button click — fetch TFL/TAFL index and display results inline
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.lc-tfl-lookup-btn') : null;
    if (!btn) return;

    var joinedId      = btn.dataset.joinedId;
    var network       = btn.dataset.network;
    var endpoint      = btn.dataset.endpoint;
    var applyEndpoint = btn.dataset.applyEndpoint;
    var csrf          = btn.dataset.csrf;
    var resultRow     = document.getElementById('lc-tfl-result-' + joinedId);
    var resultInner   = document.getElementById('lc-tfl-result-inner-' + joinedId);

    if (!resultRow || !resultInner) return;

    resultRow.style.display = '';
    btn.disabled = true;
    btn.textContent = '…'; // …
    resultInner.innerHTML = '<span class="text-muted">Searching ' + esc(network.toUpperCase()) + '…</span>';

    var params = new URLSearchParams({ _csrf: csrf, joined_id: joinedId, network: network });

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;
        btn.textContent = network.toUpperCase();

        if (data.error) {
          resultInner.innerHTML = '<span style="color:var(--danger)">' + esc(data.error) + '</span>';
          return;
        }

        var html = '';

        if (data.url) {
          html += '<strong>' + esc(network.toUpperCase()) + ' exact match:</strong> '
            + '<a href="' + esc(data.url) + '" target="_blank" rel="noopener noreferrer" class="link">' + esc(data.url) + '</a>'
            + ' &mdash; <button type="button" class="btn btn-primary btn-xs lc-tfl-apply-btn"'
            + ' data-apply-url="' + esc(data.url) + '"'
            + ' data-apply-endpoint="' + esc(applyEndpoint) + '"'
            + ' data-joined-id="' + esc(joinedId) + '"'
            + ' data-csrf="' + esc(csrf) + '">Apply URL</button>';
        } else if (data.candidates && data.candidates.length > 0) {
          html += '<strong>' + esc(network.toUpperCase()) + ' &mdash; no exact match. Candidates:</strong>'
            + '<ul style="margin:.25rem 0 0 1rem;font-size:.85rem">';
          data.candidates.forEach(function (c) {
            html += '<li><a href="' + esc(c.url) + '" target="_blank" rel="noopener noreferrer" class="link">' + esc(c.text) + '</a>'
              + ' &mdash; <button type="button" class="btn btn-secondary btn-xs lc-tfl-apply-btn"'
              + ' data-apply-url="' + esc(c.url) + '"'
              + ' data-apply-endpoint="' + esc(applyEndpoint) + '"'
              + ' data-joined-id="' + esc(joinedId) + '"'
              + ' data-csrf="' + esc(csrf) + '">Apply</button></li>';
          });
          html += '</ul>';
        } else {
          html += '<span class="text-muted">No match found on ' + esc(network.toUpperCase()) + '. Try editing the entry manually.</span>';
        }

        if (data.cache_age !== null && data.cache_age !== undefined) {
          var ageMin = Math.round(data.cache_age / 60);
          html += ' <span style="color:var(--color-muted);font-size:.8rem">('
            + (ageMin < 1 ? 'just fetched' : ageMin + ' min old') + ')</span>';
        }

        resultInner.innerHTML = html;
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = network.toUpperCase();
        resultInner.innerHTML = '<span style="color:var(--danger)">Request failed. Please try again.</span>';
      });
  });

  // "Apply URL" click — build and submit a hidden form POST (full redirect with flash)
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.lc-tfl-apply-btn') : null;
    if (!btn) return;

    var url      = btn.dataset.applyUrl;
    var endpoint = btn.dataset.applyEndpoint;
    var joinedId = btn.dataset.joinedId;
    var csrf     = btn.dataset.csrf;

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = endpoint;

    [['_csrf', csrf], ['joined_id', joinedId], ['url', url]].forEach(function (pair) {
      var inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = pair[0];
      inp.value = pair[1];
      form.appendChild(inp);
    });

    document.body.appendChild(form);
    form.submit();
  });
}());

// ── 53. Email Templates: template-name sidebar filter ──────────────────────────

(function () {
  var inp = document.getElementById('et-nav-search');
  if (!inp) return;
  inp.addEventListener('input', function () {
    var q = inp.value.trim().toLowerCase();
    document.querySelectorAll('[data-et-nav-label]').forEach(function (btn) {
      var match = !q || btn.dataset.etNavLabel.indexOf(q) !== -1;
      btn.classList.toggle('is-hidden', !match);
    });
  });
}());

// ── 54. Feedback report: copy composed report body to clipboard ────────────────

(function () {
  var btn = document.getElementById('lstr-copy-btn');
  var ta  = document.getElementById('lstr-composed-body');
  if (!btn || !ta) return;
  btn.addEventListener('click', function () {
    if (navigator.clipboard) {
      navigator.clipboard.writeText(ta.value).then(function () {
        btn.textContent = 'Copied!';
        setTimeout(function () { btn.textContent = 'Copy to clipboard'; }, 2500);
      });
    } else {
      ta.select();
      document.execCommand('copy');
      btn.textContent = 'Copied!';
      setTimeout(function () { btn.textContent = 'Copy to clipboard'; }, 2500);
    }
  });
}());

// ── 55. Fanatically template import: "select all" checkbox ─────────────────────

(function () {
  var selectAll = document.getElementById('select-all-importable');
  if (!selectAll) return;
  selectAll.addEventListener('change', function () {
    document.querySelectorAll('.importable-cb').forEach(function (cb) { cb.checked = this.checked; }, this);
  });
}());

// ── 56. Import wizard connect step: legacy config-file auto-detect ─────────────

(function () {
  var btn    = document.getElementById('js-autodetect-btn');
  var result = document.getElementById('js-autodetect-result');
  if (!btn || !result) return;

  var detectUrl   = btn.dataset.detectUrl;
  var prefixField = btn.dataset.prefixField || '';

  function setResult(msg, color) {
    result.textContent = msg;
    result.style.color = color;
    result.style.display = '';
  }

  btn.addEventListener('click', function () {
    var path = (document.getElementById('js-config-path').value || '').trim();
    if (!path) {
      setResult('Enter a file path first.', 'var(--c-error, #c00)');
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Detecting…';

    var fd = new FormData();
    fd.append('_csrf', document.querySelector('[name="_csrf"]').value);
    fd.append('config_path', path);

    fetch(detectUrl, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;
        btn.textContent = 'Detect';
        if (!data.ok) {
          setResult(data.message || 'Detection failed.', 'var(--c-error, #c00)');
          return;
        }
        var fieldMap = {host: 'db_host', name: 'db_name', user: 'db_user', pass: 'db_pass'};
        var filled = 0;
        for (var key in fieldMap) {
          if (data[key]) {
            var el = document.getElementById(fieldMap[key]);
            if (el) { el.value = data[key]; filled++; }
          }
        }
        if (prefixField && data.prefix) {
          var pfx = document.getElementById(prefixField);
          if (pfx) { pfx.value = data.prefix; filled++; }
        }
        if (filled > 0) {
          setResult(
            filled + ' field' + (filled === 1 ? '' : 's') + ' filled in — review before continuing.',
            'var(--c-success, green)'
          );
        } else {
          setResult(
            'No recognised credentials found in that file.',
            'var(--c-warning, #a60)'
          );
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Detect';
        setResult('Request failed — check the path and try again.', 'var(--c-error, #c00)');
      });
  });
}());

// ── 57. Generic inline-handler replacements (CSP: no inline on* attributes) ────
//
// Small delegated handlers standing in for what used to be onclick/onchange
// attributes scattered across admin templates. Kept together here since each
// one is a one-line behavior, not worth its own numbered section.

// Status/type filter dropdowns reuse the existing .js-nav-select pattern
// (section 10) — no new handler needed here.

// [data-set-field][data-set-value]: stamp a hidden field's value before a
// multi-button form submits (e.g. Join Rules settings: Save / Reset / Clear
// all post to the same form, distinguished by a hidden "action" field).
document.addEventListener('click', function (e) {
  var btn = e.target.closest ? e.target.closest('[data-set-field]') : null;
  if (!btn) return;
  var field = document.getElementById(btn.dataset.setField);
  if (field) field.value = btn.dataset.setValue || '';
});

// [data-jump-tab]: activate a .tabs-btn[data-tab] from elsewhere on the page.
document.addEventListener('click', function (e) {
  var link = e.target.closest ? e.target.closest('[data-jump-tab]') : null;
  if (!link) return;
  e.preventDefault();
  var tabBtn = document.querySelector('.tabs-btn[data-tab="' + link.dataset.jumpTab + '"]');
  if (tabBtn) tabBtn.click();
});

// [data-submit-form]: submit a form referenced by id from a button outside it.
document.addEventListener('click', function (e) {
  var btn = e.target.closest ? e.target.closest('[data-submit-form]') : null;
  if (!btn) return;
  var form = document.getElementById(btn.dataset.submitForm);
  if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
});

// [data-po-add]/[data-po-remove]: Previous Owners repeatable row editor on the
// Edit Listing page (Previous Owners feature). Clones a hidden <template> row
// instead of building HTML from strings, so field names/escaping stay in one
// place (the server-rendered template markup) rather than being duplicated in JS.
document.addEventListener('click', function (e) {
  var addBtn = e.target.closest ? e.target.closest('[data-po-add]') : null;
  if (addBtn) {
    var tpl  = document.getElementById('po-row-template');
    var rows = document.querySelector('[data-po-rows]');
    if (tpl && rows) rows.appendChild(tpl.content.cloneNode(true));
    return;
  }
  var rmBtn = e.target.closest ? e.target.closest('[data-po-remove]') : null;
  if (rmBtn) {
    var row = rmBtn.closest('[data-po-row]');
    if (row) row.remove();
  }
});

// ── 58. Additional Fields: "Detect Field Types" upload-and-scan modal ─────────
//
// Lets an admin upload a legacy per-listing template file (e.g. Enthusiast's
// addform.inc.php) and have Luster scan it for dropdown/radio/checkbox
// markup, then review and map each detected candidate onto an existing
// additional field before applying. The file itself is only ever scanned as
// text server-side — never executed — and nothing is saved here until the
// admin explicitly confirms a mapping and clicks Apply.

(function () {
  var openBtn = document.getElementById('fl-scan-template-btn');
  var modal   = document.getElementById('fl-scan-modal');
  if (!openBtn || !modal) return;

  var closeBtn  = document.getElementById('fl-scan-close');
  var cancelBtn = document.getElementById('fl-scan-cancel');
  var body      = document.getElementById('fl-scan-modal-body');
  var errorEl   = document.getElementById('fl-scan-error');

  var uploadStepHtml = body.innerHTML;

  function showError(msg) {
    if (!errorEl) return;
    errorEl.textContent = msg;
    errorEl.style.display = msg ? 'block' : 'none';
  }

  function closeModal() {
    modal.classList.add('is-hidden');
    body.innerHTML = uploadStepHtml;
  }

  openBtn.addEventListener('click', function () {
    body.innerHTML = uploadStepHtml;
    showError('');
    modal.classList.remove('is-hidden');
  });

  if (closeBtn)  closeBtn.addEventListener('click', closeModal);
  if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.classList.contains('is-hidden')) closeModal();
  });

  function typeLabel(t) {
    return { dropdown: 'Dropdown', radio: 'Radio Button', checkbox: 'Checkbox', text: 'Text Field' }[t] || t;
  }

  function buildReviewHtml(candidates, existingFields) {
    var fieldOptionsHtml = '<option value="">&mdash; Skip &mdash;</option>';
    existingFields.forEach(function (f) {
      fieldOptionsHtml += '<option value="' + esc(f.name) + '">' + esc(f.label) + ' (' + esc(f.name) + ')</option>';
    });

    var html = '<p class="form-hint">Found ' + candidates.length + ' possible field' + (candidates.length === 1 ? '' : 's') + '. Review each one, then choose which existing field it corresponds to (or skip it).</p>';
    html += '<div id="fl-scan-rows">';
    candidates.forEach(function (c, i) {
      html += '<div class="composer-block" style="padding:.75rem 0" data-scan-row data-type="' + esc(c.type) + '">';
      html += '<div class="field-row"><label class="field-label">Detected Type</label><div><span class="badge badge-info">' + esc(typeLabel(c.type)) + '</span></div></div>';
      html += '<div class="field-row"><label class="field-label" for="fl-scan-label-' + i + '">Label</label><div><input type="text" id="fl-scan-label-' + i + '" class="form" value="' + esc(c.label) + '"></div></div>';
      if (c.type !== 'checkbox') {
        html += '<div class="field-row"><label class="field-label" for="fl-scan-opts-' + i + '">Options</label><div><textarea id="fl-scan-opts-' + i + '" class="form" rows="2">' + esc(c.options.join(', ')) + '</textarea></div></div>';
      }
      html += '<div class="field-row"><label class="field-label" for="fl-scan-map-' + i + '">Apply to field</label><div><div class="select-wrap"><select id="fl-scan-map-' + i + '" class="form">' + fieldOptionsHtml + '</select></div></div></div>';
      html += '</div>';
    });
    html += '</div>';
    html += '<div class="modal-footer" style="padding:1rem 0 0"><button type="button" class="btn btn-primary" id="fl-scan-apply-btn">Apply Selected</button></div>';
    return html;
  }

  // Delegated on document.body (not bound directly to the button) because
  // closeModal()/openBtn's click handler both reset body.innerHTML back to
  // the upload step, which destroys and recreates #fl-scan-submit each time
  // the modal reopens — a direct listener would only survive the first open.
  document.getElementById('fl-scan-submit') && document.body.addEventListener('click', function (e) {
    var submitBtn = e.target.closest ? e.target.closest('#fl-scan-submit') : null;
    if (!submitBtn) return;

    var fileInput = document.getElementById('fl-scan-file');
    if (!fileInput || !fileInput.files || !fileInput.files[0]) {
      showError('Please choose a file first.');
      return;
    }

    var fd = new FormData();
    fd.append('_csrf', openBtn.dataset.csrf || '');
    fd.append('template_file', fileInput.files[0]);

    showError('');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Scanning…';

    fetch(openBtn.dataset.scanEndpoint || '', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Scan File';
        if (!data.ok) {
          showError(data.error || 'Could not scan that file.');
          return;
        }
        if (!data.candidates || !data.candidates.length) {
          body.innerHTML = '<p class="form-hint">No dropdown, radio, or checkbox markup was found in that file.</p>';
          return;
        }
        body.innerHTML = buildReviewHtml(data.candidates, data.existing_fields || []);

        var applyBtn = document.getElementById('fl-scan-apply-btn');
        if (applyBtn) {
          applyBtn.addEventListener('click', function () {
            var mappings = [];
            document.querySelectorAll('#fl-scan-rows [data-scan-row]').forEach(function (row, i) {
              var mapSel = document.getElementById('fl-scan-map-' + i);
              var fieldName = mapSel ? mapSel.value : '';
              if (!fieldName) return; // skipped

              var labelInput = document.getElementById('fl-scan-label-' + i);
              var optsInput  = document.getElementById('fl-scan-opts-' + i);
              mappings.push({
                field_name: fieldName,
                type:       row.dataset.type,
                label:      labelInput ? labelInput.value : '',
                options:    optsInput ? optsInput.value : '',
                required:   false,
              });
            });

            if (!mappings.length) {
              window.lstrToast('error', 'Choose at least one field to apply to.');
              return;
            }

            applyBtn.disabled = true;
            applyBtn.textContent = 'Applying…';

            var applyFd = new URLSearchParams();
            applyFd.append('_csrf', openBtn.dataset.csrf || '');
            applyFd.append('mappings_json', JSON.stringify(mappings));

            fetch(openBtn.dataset.applyEndpoint || '', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: applyFd.toString(),
            })
              .then(function (r) { return r.json(); })
              .then(function (d) {
                if (d.ok) {
                  closeModal();
                  window.lstrToast('success', 'Updated ' + d.updated + ' field(s). Reloading…');
                  setTimeout(function () { window.location.reload(); }, 1000);
                } else {
                  applyBtn.disabled = false;
                  applyBtn.textContent = 'Apply Selected';
                  window.lstrToast('error', d.error || 'Could not apply changes.');
                }
              })
              .catch(function () {
                applyBtn.disabled = false;
                applyBtn.textContent = 'Apply Selected';
                window.lstrToast('error', 'Request failed.');
              });
          });
        }
      })
      .catch(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Scan File';
        showError('Request failed. Please try again.');
      });
  });
}());

// ── 59. "?" setup-help tooltips: fixed-position popover (escapes .card's overflow:hidden) ──
(function () {
  var triggers = document.querySelectorAll('.ec-setup-help[data-desc]');
  if (!triggers.length) return;

  var pop = document.createElement('div');
  pop.className = 'pop-anchor ec-help-pop';
  pop.setAttribute('aria-hidden', 'true');
  document.body.appendChild(pop);

  var GAP = 6;
  var hideTimer = null;

  function show(trigger) {
    clearTimeout(hideTimer);
    pop.textContent = trigger.dataset.desc || '';
    // Position off-screen first so getBoundingClientRect() reflects this
    // tooltip's actual wrapped size (text length varies per trigger).
    pop.style.left = '-9999px';
    pop.style.top  = '0px';
    pop.classList.add('pop-anchor--visible');
    var r   = trigger.getBoundingClientRect();
    var pr  = pop.getBoundingClientRect();
    var x   = Math.max(8, Math.min(r.left + r.width / 2 - pr.width / 2, window.innerWidth - pr.width - 8));
    var y   = r.bottom + GAP;
    if (y + pr.height > window.innerHeight - 8) y = r.top - pr.height - GAP;
    pop.style.left = x + 'px';
    pop.style.top  = y + 'px';
  }

  function hide() {
    hideTimer = setTimeout(function () { pop.classList.remove('pop-anchor--visible'); }, 60);
  }

  triggers.forEach(function (trigger) {
    trigger.addEventListener('mouseenter', function () { show(trigger); });
    trigger.addEventListener('mouseleave', hide);
    trigger.addEventListener('focus',      function () { show(trigger); });
    trigger.addEventListener('blur',       hide);
  });
}());
