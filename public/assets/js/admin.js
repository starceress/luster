/* =============================================================================
   admin.js — Luster admin panel interactive layer

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

// HTML escaping — use esc() anywhere in this file.
function esc(str) {
  return String(str)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Token replacement — applies a {token}: value map to a string via split/join.
// §29 (bulk email preview) and §31 (custom templates preview) share this.
function applyTokenReplacements(text, tokenMap) {
  var result = text;
  Object.keys(tokenMap).forEach(function (k) {
    result = result.split(k).join(tokenMap[k]);
  });
  return result;
}

// Select-all checkbox helper — wires a master checkbox to check/uncheck all
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

// ── 3. (Sidebar constellation removed — replaced with static CSS dot grid) ─────

// ── 4. (Auth-page constellation removed — replaced with static CSS background) ──

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
  // Only one confirm bar at a time — dismiss any stale one
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
    onConfirm();
  });

  bar.querySelector('.confirm-bar-no').addEventListener('click', dismiss);

  function onKey(e) { if (e.key === 'Escape') dismiss(); }
  document.addEventListener('keydown', onKey);

  // Focus Cancel so Escape works immediately without a mouse move
  bar.querySelector('.confirm-bar-no').focus();
}

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
    if (form.hasAttribute('data-del-bulk')) return; // section 40 handles modal
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

// ── 16. (embed codes panel copy — see section 31) ────────────────────────────

// ── 17. Members index: select-all checkboxes ──────────────────────────────────

wireSelectAll('check-all', '#bulk-form input[name="emails[]"]');

// ── 18. Listings codes: select-all checkboxes ────────────────────────────────

// ── 19. Listings index: select-all checkboxes ────────────────────────────────

wireSelectAll('fl-select-all', '.fl-row-cb');


// ── 20. Joined index: select-all with indeterminate state ────────────────────

(function () {
  var selAll = document.getElementById('sel-all');
  if (!selAll) return;
  selAll.addEventListener('change', function () {
    document.querySelectorAll('#bulk-form input[name="ids[]"]').forEach(function (cb) {
      cb.checked = selAll.checked;
    });
  });
  document.querySelectorAll('#bulk-form input[name="ids[]"]').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var all     = document.querySelectorAll('#bulk-form input[name="ids[]"]');
      var checked = document.querySelectorAll('#bulk-form input[name="ids[]"]:checked');
      selAll.checked       = all.length === checked.length;
      selAll.indeterminate = checked.length > 0 && checked.length < all.length;
    });
  });
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

  netSel.addEventListener('change', function () {
    var net = this.value;
    var cats;
    if (net === 'TFL')       cats = tflCats;
    else if (net === 'TAFL') cats = taflCats;
    else if (net !== '') {
      cats = customMap[net] || [];
    } else {
      cats = tflCats.concat(taflCats);
      Object.values(customMap).forEach(function (arr) { cats = cats.concat(arr); });
      cats.sort(function (a, b) { return a.name.localeCompare(b.name); });
    }
    var html = '<option value="">All Categories</option>';
    cats.forEach(function (c) {
      html += '<option value="' + esc(c.name) + '">' + esc(c.name) + '</option>';
    });
    catSel.innerHTML = html;
    document.getElementById('fl-filter-form').submit();
  });
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

  function populate(cats) {
    var html = '<option value="">All Categories</option>';
    cats.forEach(function (c) {
      var sel = c.name === currentVal ? ' selected' : '';
      html += '<option value="' + esc(c.name) + '"' + sel + '>' + esc(c.name) + '</option>';
    });
    catSel.innerHTML = html;
  }

  function catsForNet(net) {
    if (net === 'TFL')       return tflCats;
    if (net === 'TAFL')      return taflCats;
    if (net !== '')          return customMap[net] || [];
    var all = tflCats.concat(taflCats);
    Object.values(customMap).forEach(function (arr) { all = all.concat(arr); });
    return all.sort(function (a, b) { return a.name.localeCompare(b.name); });
  }

  populate(catsForNet(netSel.value));

  netSel.addEventListener('change', function () {
    currentVal = '';
    populate(catsForNet(this.value));
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
  netSel.addEventListener('change', function () {
    var net    = this.value;
    var catSel = document.getElementById('cat-sel');
    catSel.innerHTML = '<option value="">All Categories</option>';
    if (net && netCats[net]) {
      netCats[net].forEach(function (c) {
        var o = document.createElement('option');
        o.value = c; o.textContent = c;
        catSel.appendChild(o);
      });
    }
    this.form.submit();
  });
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
      site_name:       md.siteName       || '',
      owner_name:      md.ownerName      || '',
      owner_email:     md.ownerEmail     || '',
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
    '{fan_password}':    '(password)',
    '{reset_url}':       'https://example.com/reset/1/confirm?token=abc123',
    '{listing_name}':    pd.dataset.listingName    || 'My Fanlisting',
    '{listing_subject}': pd.dataset.listingSubject || 'Music',
    '{listing_url}':     pd.dataset.listingUrl     || 'https://myfanlisting.com',
    '{site_name}':       pd.dataset.siteName       || 'Luster',
    '{owner_name}':      pd.dataset.ownerName      || 'The Admin',
    '{owner_email}':     pd.dataset.ownerEmail     || 'admin@example.com',
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
      var visible = panel.style.display !== 'none';
      panel.style.display = visible ? 'none' : 'block';
      btn.textContent = visible ? 'Preview' : 'Hide Preview';
      if (!visible) updatePreview(type);
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
     lstrToast(type, message)         — auto title
     lstrToast(type, title, message)  — custom title */
  window.lstrToast = function (type, title, message) {
    getContainer().appendChild(createToast(type, title, message));
  };
}());

/* ── Form submit loading state ─────────────────────────────────────────── */
(function () {
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.tagName !== 'FORM') return;

    /* Skip on the first (intercepted) submit — only apply after confirmation */
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
    '{listing_name}':    'My Fanlisting',
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

// ── 32. Embed codes: tab system, quick copy & customizer ─────────────────────

(function () {
  var root = document.getElementById('ec-root');
  if (!root) return;

  var embedPath = root.dataset.embedPath || '';
  var listings  = JSON.parse(root.dataset.listings || '[]');
  var items     = JSON.parse(root.dataset.items    || '{}');

  // ── Tab switching ──────────────────────────────────────────────────────────
  var tabBtns = root.querySelectorAll('[data-ec-tab]');
  var panels  = {
    qc:   document.getElementById('ec-panel-qc'),
    cust: document.getElementById('ec-panel-cust'),
    ref:  document.getElementById('ec-panel-ref'),
  };

  function switchTab(id) {
    tabBtns.forEach(function (b) {
      var isActive = b.dataset.ecTab === id;
      b.classList.toggle('active', isActive);
      b.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    Object.keys(panels).forEach(function (k) {
      if (panels[k]) panels[k].classList.toggle('active', k === id);
    });
  }

  tabBtns.forEach(function (btn) {
    btn.addEventListener('click', function () { switchTab(btn.dataset.ecTab); });
  });

  // ── Copy helper ────────────────────────────────────────────────────────────
  function copyText(text, btn, origLabel) {
    origLabel = origLabel || btn.textContent;
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).catch(function () {});
    } else {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(ta);
    }
    btn.textContent = 'Copied!'; btn.classList.add('ec-copied');
    setTimeout(function () { btn.textContent = origLabel; btn.classList.remove('ec-copied'); }, 1800);
  }

  // ── Syntax highlighter ─────────────────────────────────────────────────────
  function highlight(code) {
    var e = code.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return e.replace(/(\/\/[^\n]*)|('(?:[^'\\]|\\.)*')|(&lt;\?php|\?&gt;)|(\brequire\b|\btrue\b|\bfalse\b|\bnull\b)|(\$\w+)|(\b\d+\b)/g, function (m, c, s, t, k, v, n) {
      if (c !== undefined) return '<span class="ec-hl-c">' + m + '</span>';
      if (s !== undefined) return '<span class="ec-hl-s">' + m + '</span>';
      if (t !== undefined) return '<span class="ec-hl-k">' + m + '</span>';
      if (k !== undefined) return '<span class="ec-hl-k">' + m + '</span>';
      if (v !== undefined) return '<span class="ec-hl-v">' + m + '</span>';
      if (n !== undefined) return '<span class="ec-hl-n">' + m + '</span>';
      return m;
    });
  }

  // ════════════════════════════════════════════════════════════
  // QUICK COPY
  // ════════════════════════════════════════════════════════════

  var qcTitle = document.getElementById('ec-qc-title');
  var qcBadge = document.getElementById('ec-qc-badge');
  var qcDesc  = document.getElementById('ec-qc-desc');
  var qcCode  = document.getElementById('ec-qc-code');
  var qcCp    = document.getElementById('ec-qc-cp');
  var qcFile  = document.getElementById('ec-qc-file');
  var qcHint  = document.getElementById('ec-qc-hint');
  var qcCust  = document.getElementById('ec-qc-cust');
  var qcItems = root.querySelectorAll('.ec-qc-item');

  var NO_ID_ITEMS    = ['owned', 'joined', 'cstats', 'kim'];
  var COL_AFF_ITEMS  = ['aff-col'];

  function selectQcItem(id) {
    qcItems.forEach(function (b) { b.classList.toggle('act', b.dataset.ecItem === id); });
    var item = items[id];
    if (!item) return;
    qcTitle.textContent = item.label || id;
    qcBadge.textContent = item.badge ? item.badge.charAt(0).toUpperCase() + item.badge.slice(1) : '';
    qcBadge.className   = 'badge badge-' + ({'form':'info-pink','data':'info','stat':'info-gold','ext':'ext'}[item.badge] || 'info');
    qcDesc.textContent  = item.desc  || '';
    qcCode.innerHTML    = highlight(item.code || '');
    qcCode.dataset.raw  = item.code  || '';
    if (qcFile) qcFile.textContent = item.file || '';
    if (qcHint) {
      if (NO_ID_ITEMS.indexOf(id) !== -1) {
        qcHint.innerHTML = 'No listing ID needed &mdash; collective embed.';
      } else if (COL_AFF_ITEMS.indexOf(id) !== -1) {
        qcHint.innerHTML = 'Uses <code>$luster_fanlisting_id = 0</code> for site-wide affiliates.';
      } else {
        qcHint.innerHTML = 'Set <code>$luster_fanlisting_id</code> to your listing\'s integer ID.';
      }
    }
    if (qcCust) qcCust.dataset.custType = item.custType || id;
  }

  qcItems.forEach(function (btn) {
    btn.addEventListener('click', function () { selectQcItem(btn.dataset.ecItem); });
  });

  if (qcCp) {
    qcCp.addEventListener('click', function () {
      copyText(qcCode.dataset.raw || qcCode.textContent, qcCp, 'Copy');
    });
  }

  if (qcCust) {
    qcCust.addEventListener('click', function () {
      switchTab('cust');
      selectCustType(qcCust.dataset.custType || 'join');
    });
  }

  // Select first item on load
  if (qcItems.length) selectQcItem(qcItems[0].dataset.ecItem);

  // ════════════════════════════════════════════════════════════
  // CUSTOMIZER
  // ════════════════════════════════════════════════════════════

  var custTypeBtns = root.querySelectorAll('.ec-type-btn');
  var optsHead     = document.getElementById('ec-opts-head');
  var optsBody     = document.getElementById('ec-opts-body');
  var liveTa       = document.getElementById('ec-live-ta');
  var liveNote     = document.getElementById('ec-live-note');
  var liveCp       = document.getElementById('ec-live-cp');

  var liveCode = ''; // auto-generated code; reset restores textarea to this

  var curCustType = 'join';
  var custVals    = {};

  // Type definitions: fields = list of option inputs, note = hint HTML
  var CUST = {
    join: {
      label: 'Join form',
      fields: [{ id: 'lid', label: 'Fanlisting', type: 'listing', hint: '' }],
      note: '<strong>Join form</strong> — submissions land in the pending queue. Approve them from <strong>Admin → Members</strong>.',
    },
    members: {
      label: 'Members list',
      fields: [
        { id: 'lid',          label: 'Fanlisting',             type: 'listing',                                                              hint: '' },
        { id: 'per_page',     label: 'Members per page',       type: 'number', ph: '20',                                                    hint: 'Leave blank to use your site’s default.' },
        { id: 'sort_by',      label: 'Default sort order',     type: 'select', opts: [['','Visitor’s choice (default)'],['name','By name'],['country','By country']], hint: '' },
        { id: 'show_search',  label: 'Sort &amp; search controls', type: 'select', opts: [['','Shown (default)'],['false','Hidden']],        hint: '' },
        { id: 'members_base', label: 'URL of this page',       type: 'text',   ph: 'https://yoursite.com/members.php',                      hint: 'Keeps pagination and sort links pointing back to your page.' },
      ],
      note: '<strong>Members list</strong> — pagination links appear when members exceed the per-page limit.',
    },
    stats: {
      label: 'Statistics',
      fields: [
        { id: 'lid', label: 'Fanlisting', type: 'listing', hint: '' },
        { id: 'tpl', label: 'Stats to display', type: 'tokens',
          hint: 'Check the stats you want to show. This builds a <code>$luster_stats_template</code> override. Leave all unchecked to use the template from <strong>Admin → Listings → Edit</strong> instead.',
          tokens: [
            { token: '{stat_approved}',     label: 'Approved members',      line: '{stat_approved} members' },
            { token: '{stat_pending}',      label: 'Pending members',       line: '{stat_pending} pending' },
            { token: '{stat_opened}',       label: 'Opening date',          line: 'Opened: {stat_opened}' },
            { token: '{stat_updated}',      label: 'Last member date',      line: 'Last updated: {stat_updated}' },
            { token: '{stat_last_updated}', label: 'Last updated (admin)',  line: 'Last updated: {stat_last_updated}' },
            { token: '{stat_newmembers}',   label: 'Newest members',        line: 'Newest: {stat_newmembers}' },
            { token: '{stat_growth}',       label: 'Growth rate (per day)', line: 'Growth: {stat_growth}/day' },
            { token: '{listing_name}',      label: 'Listing name',          line: '{listing_name}' },
            { token: '{listing_url}',       label: 'Listing URL',           line: '{listing_url}' },
          ],
        },
      ],
      note: '<strong>Statistics</strong> — check tokens above to generate a quick template, or leave all unchecked to use the template saved in <strong>Admin → Listings → Edit</strong>.',
    },
    update: {
      label: 'Update details',
      fields: [{ id: 'lid', label: 'Fanlisting', type: 'listing', hint: '' }],
      note: '<strong>Update form</strong> — lets existing members change their name, URL, and other details.',
    },
    remove: {
      label: 'Remove membership',
      fields: [{ id: 'lid', label: 'Fanlisting', type: 'listing', hint: '' }],
      note: '<strong>Remove form</strong> — lets members remove themselves from the fanlisting.',
    },
    reset: {
      label: 'Password reset',
      fields: [
        { id: 'lid',        label: 'Fanlisting',        type: 'listing',                                                                                                              hint: '' },
        { id: 'reset_step', label: 'Which step to show',type: 'select', opts: [['','Both steps on one page (default)'],['confirm','Step 2 only — new-password form']], hint: '' },
      ],
      note: '<strong>Password reset</strong> — two-step flow. Both steps appear on the same page by default.',
    },
    affiliates: {
      label: 'Affiliates',
      fields: [{ id: 'lid', label: 'Fanlisting (0 for collective)', type: 'listing_or_zero', hint: 'Use 0 to embed collective-wide affiliates.' }],
      note: '<strong>Affiliates</strong> — set ID to 0 for collective-wide affiliates, or pick a listing for per-listing affiliates.',
    },
    owned: {
      label: 'Owned listings',
      fields: [
        { id: 'browse_by_category', label: 'Browse mode',      type: 'select', opts: [['','Standard (selector / no filter)'],['true','Browse by category (index first)']], hint: 'When enabled, visitors first see a category list with counts; clicking a category shows its listings.' },
        { id: 'status',             label: 'Status filter',    type: 'select', opts: [['','Current only (default)'],['current','Current'],['upcoming','Upcoming'],['pending','Pending'],['all','All statuses']], hint: '' },
        { id: 'hide_dropdown',      label: 'Category filter',  type: 'select', opts: [['','Shown (default)'],['true','Hidden']], hint: 'Hide the selector to show a fixed category with no UI. Combine with a specific category ID in your page.' },
        { id: 'show_list',          label: 'Selector style',   type: 'select', opts: [['','Dropdown (default)'],['true','Bulleted link list']], hint: '' },
      ],
      note: '<strong>Owned listings</strong> — browse-by-category shows a category index first; standard mode shows a dropdown or no filter.',
    },
    joined: {
      label: 'Joined listings',
      fields: [
        { id: 'browse_by_category', label: 'Browse mode',      type: 'select', opts: [['','Standard (dropdown / grouped)'],['true','Browse by category (index first)']], hint: 'When enabled, visitors first see a list of all categories; clicking one shows its listings.' },
        { id: 'category',           label: 'Category filter',  type: 'text',   ph: '', hint: "Blank = dropdown. 'all' = show all grouped. Or enter an exact category name." },
        { id: 'show_list',          label: 'Selector style',   type: 'select', opts: [['','Dropdown (default)'],['true','Bulleted link list']], hint: '' },
      ],
      note: "<strong>Joined listings</strong> — browse-by-category shows a category index first; or use blank/all/name for the classic selector/grouped modes.",
    },
    cstats: {
      label: 'Site statistics',
      fields: [
        { id: 'vars', label: 'Variables to use', type: 'cstatvars',
          hint: 'Check the variables you want. Selected ones appear as <code>echo</code> lines in the generated code — all variables are available regardless.',
          vars: [
            { name: 'luster_owned_active',        label: 'Active owned listings',      desc: 'open fanlistings only' },
            { name: 'luster_owned_upcoming',      label: 'Upcoming owned',             desc: '' },
            { name: 'luster_owned_pending',       label: 'Pending / inactive owned',   desc: '' },
            { name: 'luster_owned',               label: 'Total owned (all)',           desc: '' },
            { name: 'luster_joined_active',       label: 'Active joined listings',     desc: '' },
            { name: 'luster_joined',              label: 'Total joined (all)',          desc: '' },
            { name: 'luster_affiliates',          label: 'Collective affiliates',      desc: '' },
            { name: 'luster_members_approved',    label: 'Approved members',           desc: 'across all listings' },
            { name: 'luster_members_pending',     label: 'Pending members',            desc: '' },
            { name: 'luster_members_total',       label: 'Total members',              desc: 'approved + pending' },
            { name: 'luster_owned_growth_rate',   label: 'Owned growth rate',          desc: 'listings / year' },
            { name: 'luster_joined_growth_rate',  label: 'Joined growth rate',         desc: 'listings / year' },
            { name: 'luster_members_growth_rate', label: 'Member growth rate',         desc: 'members / day' },
          ],
        },
      ],
      note: '<strong>Site statistics</strong> — exposes stats as PHP variables after the <code>require</code>. All variables are always available; the checkboxes just add <code>echo</code> lines for the ones you select.',
    },
    'aff-col': {
      label: 'Collective affiliates',
      fields: [],
      note: '<strong>Collective affiliates</strong> — automatically embeds site-wide affiliates (listing ID = 0).',
    },
    kim: {
      label: 'KIM List sign-up',
      fields: [],
      note: '<strong>KIM List</strong> — the KIM List add-on must be installed and active. Install from <strong>Admin → Add-ons</strong>.',
    },
  };

  function phpStr(s) {
    return "'" + String(s).replace(/\\/g,'\\\\').replace(/'/g,"\'") + "'";
  }

  function generateCode(type, vals) {
    var def = CUST[type];
    if (!def) return '';
    var FILE = { join:'join.php', members:'members.php', stats:'stats.php', update:'update.php', remove:'remove.php', reset:'reset.php', affiliates:'affiliates.php', owned:'owned.php', joined:'joined.php', cstats:'collective-stats.php', 'aff-col':'affiliates.php', kim:'kim-signup.php' };
    var lines = ['<?php'];
    // Listing ID
    if (['join','members','stats','update','remove','reset'].indexOf(type) !== -1) {
      var lid = parseInt(vals.lid || (listings.length ? listings[0].id : 1), 10) || 1;
      lines.push('$luster_fanlisting_id = ' + lid + ';');
    } else if (type === 'affiliates') {
      var aid = (vals.lid === '0' || vals.lid === '') ? 0 : (parseInt(vals.lid, 10) || (listings.length ? listings[0].id : 1));
      lines.push('$luster_fanlisting_id = ' + aid + '; // 0 = collective affiliates');
    } else if (type === 'aff-col') {
      lines.push('$luster_fanlisting_id = 0; // collective affiliates');
    }
    // Extra options
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
      if (vals.hide_dropdown === 'true')      lines.push('$luster_hide_dropdown      = true;');
      if (vals.show_list === 'true')          lines.push('$luster_show_list          = true;');
    }
    if (type === 'joined') {
      if (vals.browse_by_category === 'true') lines.push('$luster_browse_by_category = true;');
      if (vals.category)                      lines.push('$luster_category           = ' + phpStr(vals.category) + ';');
      if (vals.show_list === 'true')          lines.push('$luster_show_list          = true;');
    }
    // Stats template override — set before require so the embed can pick it up
    if (type === 'stats' && Array.isArray(vals.tpl) && vals.tpl.length > 0) {
      if (vals.tpl.length === 1) {
        lines.push('$luster_stats_template = ' + phpStr(vals.tpl[0].line) + ';');
      } else {
        lines.push('$luster_stats_template = ' + phpStr(vals.tpl[0].line));
        for (var ti = 1; ti < vals.tpl.length - 1; ti++) {
          lines.push("  . '<br>" + vals.tpl[ti].line.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'");
        }
        lines.push("  . '<br>" + vals.tpl[vals.tpl.length - 1].line.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "';");
      }
    }
    // Require line
    if (type === 'kim') lines.push('// Requires KIM List add-on (Admin → Add-ons)');
    lines.push("require('" + embedPath + '/' + (FILE[type] || type + '.php') + "');");
    // Collective stats: echo lines for selected variables
    if (type === 'cstats') {
      var selVars = Array.isArray(vals.vars) ? vals.vars : [];
      if (selVars.length > 0) {
        selVars.forEach(function (v) {
          var stmt = 'echo $' + v.name + ';';
          var pad  = new Array(Math.max(2, 34 - stmt.length)).join(' ');
          lines.push(stmt + pad + '// ' + v.label);
        });
      } else {
        lines.push('// All variables are available — select some above to add echo lines.');
      }
    }
    lines.push('?>');
    return lines.join('\n');
  }

  function buildOptsForm(type) {
    var def = CUST[type];
    if (!def) return;
    custVals = {};
    if (optsHead) optsHead.textContent = def.label + ' — options';
    if (!optsBody) return;
    if (!def.fields.length) {
      optsBody.innerHTML = '<p class="ec-opts-empty">No configuration needed — the code on the right is ready to use.</p>';
      return;
    }
    var html = '';
    def.fields.forEach(function (f) {
      if (f.type === 'tokens') {
        // ── Token checkbox grid (stats template builder) ──────────
        custVals[f.id] = [];
        html += '<div class="ec-f"><label class="ec-f-label">' + f.label + '</label>';
        html += '<div class="ec-token-grid">';
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
        if (f.hint) html += '<p class="ec-f-hint">' + f.hint + '</p>';
        html += '</div>';
        return;
      }
      if (f.type === 'cstatvars') {
        // ── Variable checkbox grid (collective stats echo builder) ─
        custVals[f.id] = [];
        html += '<div class="ec-f"><label class="ec-f-label">' + f.label + '</label>';
        html += '<div class="ec-token-grid">';
        (f.vars || []).forEach(function (v) {
          var desc = v.desc ? ' <span class="ec-tok-desc">' + esc(v.desc) + '</span>' : '';
          html += '<label class="ec-token-item">'
                + '<input type="checkbox" class="ec-var-cb" data-varfid="' + esc(f.id) + '"'
                + ' data-varname="' + esc(v.name) + '" data-varlabel="' + esc(v.label) + '">'
                + '<span class="ec-token-inner">'
                + '<span class="ec-tok-name">$' + esc(v.name) + '</span>'
                + '<span class="ec-tok-desc">' + esc(v.label) + (v.desc ? ' — ' + esc(v.desc) : '') + '</span>'
                + '</span></label>';
        });
        html += '</div>';
        if (f.hint) html += '<p class="ec-f-hint">' + f.hint + '</p>';
        html += '</div>';
        return;
      }
      // ── Standard input types ───────────────────────────────────
      html += '<div class="ec-f"><label class="ec-f-label" for="ec-f-' + esc(f.id) + '">' + f.label + '</label>';
      if (f.type === 'listing' || f.type === 'listing_or_zero') {
        html += '<div class="select-wrap"><select id="ec-f-' + esc(f.id) + '" data-fid="' + esc(f.id) + '">';
        if (f.type === 'listing_or_zero') html += '<option value="0">0 — collective / site-wide</option>';
        listings.forEach(function (fl) {
          html += '<option value="' + parseInt(fl.id, 10) + '">' + esc(fl.name) + '</option>';
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
      if (f.hint) html += '<p class="ec-f-hint">' + esc(f.hint) + '</p>';
      html += '</div>';
    });
    optsBody.innerHTML = html;
    // Standard inputs
    optsBody.querySelectorAll('[data-fid]').forEach(function (el) {
      custVals[el.dataset.fid] = el.value;
      el.addEventListener('input',  function () { custVals[el.dataset.fid] = el.value; updateLive(); });
      el.addEventListener('change', function () { custVals[el.dataset.fid] = el.value; updateLive(); });
    });
    // Token checkboxes (stats)
    optsBody.querySelectorAll('.ec-tok-cb').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var fid = cb.dataset.tokfid;
        custVals[fid] = [];
        optsBody.querySelectorAll('.ec-tok-cb[data-tokfid="' + fid + '"]:checked').forEach(function (c) {
          custVals[fid].push({ token: c.dataset.tok, line: c.dataset.line });
        });
        updateLive();
      });
    });
    // Variable checkboxes (cstats)
    optsBody.querySelectorAll('.ec-var-cb').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var fid = cb.dataset.varfid;
        custVals[fid] = [];
        optsBody.querySelectorAll('.ec-var-cb[data-varfid="' + fid + '"]:checked').forEach(function (c) {
          custVals[fid].push({ name: c.dataset.varname, label: c.dataset.varlabel });
        });
        updateLive();
      });
    });
  }

  function updateLive() {
    liveCode = generateCode(curCustType, custVals);
    if (liveTa)   liveTa.value = liveCode;
    if (liveNote) liveNote.innerHTML = (CUST[curCustType] || {}).note || '';
  }

  function selectCustType(type) {
    if (!CUST[type]) type = 'join';
    curCustType = type;
    custTypeBtns.forEach(function (b) { b.classList.toggle('act', b.dataset.custType === type); });
    buildOptsForm(type);
    updateLive();
  }

  custTypeBtns.forEach(function (btn) {
    btn.addEventListener('click', function () { selectCustType(btn.dataset.custType); });
  });

  if (liveCp) {
    liveCp.addEventListener('click', function () {
      copyText(liveTa ? liveTa.value : liveCode, liveCp, 'Copy');
    });
  }

  // ── Variable reference nav ─────────────────────────────────────────────────

  var refNavBtns = root.querySelectorAll('.ec-ref-nav-item');
  var refPanels  = root.querySelectorAll('.ec-ref-panel');

  function switchRefPanel(id) {
    refNavBtns.forEach(function (b) { b.classList.toggle('act', b.dataset.ecRef === id); });
    refPanels.forEach(function (p) { p.classList.toggle('act', p.id === id); });
  }

  refNavBtns.forEach(function (btn) {
    btn.addEventListener('click', function () { switchRefPanel(btn.dataset.ecRef); });
  });

  // Initialise
  selectCustType('join');
  if (refNavBtns.length) switchRefPanel(refNavBtns[0].dataset.ecRef);
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
  if (!def) return;
  var ta = document.getElementById(id);
  if (ta) { ta.value = def; ta.focus(); }
});

// ── Settings page: SMTP validate connection ───────────────────────────────────

(function () {
  var btn    = document.getElementById('smtp-validate-btn');
  var result = document.getElementById('smtp-validate-result');
  if (!btn) return;

  btn.addEventListener('click', function () {
    var host   = (document.getElementById('s-smtp-host')   || {}).value || '';
    var port   = (document.getElementById('s-smtp-port')   || {}).value || '';
    var user   = (document.getElementById('s-smtp-user')   || {}).value || '';
    var pass   = (document.getElementById('s-smtp-pass')   || {}).value || '';
    var secure = (document.getElementById('s-smtp-secure') || {}).value || '';

    if (host.trim() === '') {
      if (result) { result.style.display = ''; result.style.color = 'var(--danger)'; result.textContent = 'Enter an SMTP host first.'; }
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Connecting\u2026';
    if (result) result.style.display = 'none';

    var csrf = document.querySelector('input[name="_csrf"]');
    var body = new URLSearchParams({
      _csrf: csrf ? csrf.value : '',
      smtp_host: host, smtp_port: port, smtp_user: user, smtp_pass: pass, smtp_secure: secure,
    });

    fetch(btn.dataset.url || '', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (result) {
        result.style.display = '';
        result.style.color   = data.ok ? 'var(--success, #2a9d5c)' : 'var(--danger)';
        result.textContent   = (data.ok ? '\u2713 ' : '\u2717 ') + data.message;
      }
    })
    .catch(function () {
      if (result) {
        result.style.display = '';
        result.style.color   = 'var(--danger)';
        result.textContent   = '\u2717 Request failed \u2014 check browser console.';
      }
    })
    .finally(function () {
      btn.disabled = false;
      btn.textContent = 'Validate connection';
    });
  });
}());

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
      fan_password:    '',
      name:            'Fan Name',
      email:           'fan@example.com',
      listing_name:    listName,
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
      if (hint) hint.textContent = 'Ready to upload — click Upload below';
    }
  });
}());

// ── Codes management page ─────────────────────────────────────────────────────
// Guards on .code-card so this block only activates on the codes admin page.

(function () {
  function initCodesManager() {
    if (!document.querySelector('.code-card')) return;

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
  var activeFilter  = root && (root.getAttribute('data-initial-code-filter') || '').trim() === 'pending' ? 'pending' : '';
  var selAll        = document.getElementById('codes-select-all');

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

  function applyCodeFilter(nextFilter, updateUrl) {
    activeFilter = nextFilter === 'pending' ? 'pending' : '';

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
      filterEmpty.classList.toggle('is-hidden', totalVisible > 0 || activeFilter === '');
    }
    if (pendingBanner && pendingAction) {
      if (activeFilter === 'pending') {
        if (pendingTitle) pendingTitle.textContent = 'Showing pending codes';
        if (pendingMsg) pendingMsg.textContent = 'Only pending codes are visible right now.';
        pendingAction.textContent = '\u2190 Show all codes';
        pendingAction.setAttribute('data-code-filter', '');
        pendingAction.setAttribute('href', '?tab=codes');
      } else {
        if (pendingTitle) pendingTitle.textContent = 'Codes awaiting review';
        if (pendingMsg) pendingMsg.textContent = 'Review pending codes without leaving this page.';
        pendingAction.textContent = 'Review pending \u2192';
        pendingAction.setAttribute('data-code-filter', 'pending');
        pendingAction.setAttribute('href', '?tab=codes&status_filter=pending');
      }
    }

    syncSelectAllState();

    if (updateUrl !== false && window.history && history.replaceState) {
      var url = new URL(window.location.href);
      url.searchParams.set('tab', 'codes');
      if (activeFilter === '') url.searchParams.delete('status_filter');
      else url.searchParams.set('status_filter', activeFilter);
      history.replaceState(null, '', url.toString());
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
      applyCodeFilter(link.getAttribute('data-code-filter') || '', true);
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
        showConfirmBar(
          applyBtn,
          'Delete ' + checked.length + ' code(s)? This cannot be undone.',
          doSubmit
        );
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
  });

  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCodesManager, { once: true });
  } else {
    initCodesManager();
  }
}());

// ── 33. Listings index: filter panel toggle ───────────────────────────────────

(function () {
  var btn   = document.getElementById('fl-filter-toggle');
  var panel = document.getElementById('filter-panel');
  if (!btn || !panel) return;

  var LS_KEY = 'lstr_fl_filter_open';

  // If no filters are active, restore the user's last open/closed preference
  if (!panel.classList.contains('is-open') && localStorage.getItem(LS_KEY) === '1') {
    panel.classList.add('is-open');
    btn.setAttribute('aria-expanded', 'true');
  }

  btn.addEventListener('click', function () {
    var open = panel.classList.contains('is-open');
    panel.classList.toggle('is-open', !open);
    btn.setAttribute('aria-expanded', String(!open));
    localStorage.setItem(LS_KEY, !open ? '1' : '0');
  });
}());

// ── 34. Affiliate drag-and-drop row reorder ───────────────────────────────────
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
//   data-del-entity  — key into the CFG map below
//   data-del-name    — display name of the item being deleted
//   data-del-context — (optional) secondary label (e.g. listing name for member)
//   data-del-warning — (optional) override the default warning text
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
    update:                { title: 'Delete Update',           btn: 'Delete',      desc: 'You are about to delete the update <strong>$$names$$</strong>.',                       warn: 'This action cannot be undone.' }
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
      if (!cbs.length) return; // nothing checked — validation toast already shown
      var nameList = [];
      cbs.forEach(function (cb) { nameList.push(cb.dataset.name); });
      e.preventDefault();
      pending = form;
      show(cfg2, nameList.join(', '), form.dataset.delContext || '', '');
    }
  });
}());

// ── 42. Collective Templates — token insert, reset to default ─────────────────

(function () {
  // Token row: insert at cursor in target textarea (event delegation)
  document.addEventListener('click', function (e) {
    var row = e.target.closest('.tok-row[data-textarea]');
    if (!row) return;
    var ta = document.getElementById(row.dataset.textarea);
    if (!ta) return;
    var token = row.dataset.token || '';
    var start = ta.selectionStart, end = ta.selectionEnd;
    ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
    ta.selectionStart = ta.selectionEnd = start + token.length;
    ta.focus();
  });

  // Reset to default button
  var defaultsEl = document.getElementById('luster-coltpl-defaults');
  var defaults = {};
  if (defaultsEl) {
    try { defaults = JSON.parse(defaultsEl.textContent); } catch (err) {}
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-ghost[data-textarea]');
    if (!btn) return;
    var ta = document.getElementById(btn.dataset.textarea);
    if (ta && defaults[btn.dataset.textarea] !== undefined) {
      ta.value = defaults[btn.dataset.textarea];
      ta.focus();
    }
  });
}());

// ── 40. Updates add-on — category tag widget ──────────────────────────────────

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

// ── 41. Import wizard — connect + scope pages ─────────────────────────────────

(function () {
  // Helper: show/hide element using is-hidden class
  function setVisible(el, visible) {
    if (!el) return;
    el.classList.toggle('is-hidden', !visible);
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
    testResult.textContent = 'Testing…';

    var fd = new FormData();
    fd.append('_csrf',   (document.querySelector('input[name="_csrf"]') || {}).value || '');
    fd.append('db_host', (document.getElementById('db_host') || {}).value || '');
    fd.append('db_name', (document.getElementById('db_name') || {}).value || '');
    fd.append('db_user', (document.getElementById('db_user') || {}).value || '');
    fd.append('db_pass', (document.getElementById('db_pass') || {}).value || '');

    fetch(action, { method: 'POST', body: fd })
      .then(function (r) {
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.json();
      })
      .then(function (d) {
        testResult.textContent = d.ok
          ? ('✓ Connected — ' + (d.schema || d.message))
          : ('✗ ' + d.message);
        testResult.style.color = d.ok ? 'var(--success)' : 'var(--danger)';
      })
      .catch(function (err) {
        testResult.textContent = '✗ ' + (err.message || 'Request failed.');
        testResult.style.color = 'var(--danger)';
      });
  });
}());
// ── 41b. Bulk email — recipient chips, template fill, preview modal ────────────

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

  // ── Recipient chips ──────────────────────────────────────────────────────
  function getRecipientCount() {
    return chips.querySelectorAll('.es-chip-item:not(.es-chip-removed)').length;
  }

  function syncRecipientCount() {
    var n = getRecipientCount();
    if (sendCount)  sendCount.textContent  = n;
    if (countLabel) countLabel.textContent = n + ' member' + (n !== 1 ? 's' : '');
    if (sendBtn)    sendBtn.disabled       = (n === 0);
  }

  chips.addEventListener('click', function (e) {
    var btn = e.target.closest('.es-chip-remove');
    if (!btn) return;
    var chip  = btn.closest('.es-chip-item');
    var email = chip.dataset.email;
    chip.classList.add('es-chip-removed');
    chip.style.display = 'none';
    emailInputs.querySelectorAll('input[name="emails[]"]').forEach(function (inp) {
      if (inp.value === email) inp.remove();
    });
    syncRecipientCount();
  });

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
    saveEmails.innerHTML = '';
    emailInputs.querySelectorAll('input[name="emails[]"]').forEach(function (inp) {
      var copy   = document.createElement('input');
      copy.type  = 'hidden';
      copy.name  = 'emails[]';
      copy.value = inp.value;
      saveEmails.appendChild(copy);
    });
  });

  // ── Preview / Confirm modal ──────────────────────────────────────────────
  var sampleName           = previewData.dataset.firstName      || 'Alex';
  var sampleEmail          = previewData.dataset.firstEmail     || 'member@example.com';
  var sampleListingName    = previewData.dataset.listingName    || '';
  var sampleListingSubject = previewData.dataset.listingSubject || '';
  var sampleListingUrl     = previewData.dataset.listingUrl     || '';
  var sampleSite           = previewData.dataset.siteName       || '';
  var sampleOwnerName      = previewData.dataset.ownerName      || '';
  var sampleOwnerEmail     = previewData.dataset.ownerEmail     || '';

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
      .replace(/\{listing_name\}/g,     sampleListingName)
      .replace(/\{listing_subject\}/g,  sampleListingSubject)
      .replace(/\{listing_url\}/g,      sampleListingUrl)
      .replace(/\{site_name\}/g,        sampleSite)
      .replace(/\{owner_name\}/g,       sampleOwnerName)
      .replace(/\{owner_email\}/g,      sampleOwnerEmail);
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
    if (fn) fn.textContent = sampleOwnerName;
    if (fe) fe.textContent = sampleOwnerEmail ? '<' + sampleOwnerEmail + '>' : '';
    document.getElementById('es-preview-to').textContent =
      n + ' member' + (n !== 1 ? 's' : '') + ' selected';
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

// ── 43. Settings — scoped panel clear buttons ─────────────────────────────────

(function () {
  [['general-clear-btn', 'panel-general'], ['embed-clear-btn', 'panel-embed']].forEach(function (pair) {
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

  // Per-row "Mark Updated" — fetch POST, update UI without reload
  document.querySelectorAll('.todo-mark-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('.btn-mark-updated');
      if (btn && btn.disabled) return;
      if (btn) { btn.disabled = true; btn.textContent = '…'; }

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
          if (days) { days.textContent = 'Just now'; }
          if (btn)  { btn.textContent = 'Updated ✓'; }

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
          if (btn) { btn.disabled = false; btn.textContent = 'Mark Updated'; }
          if (window.lstrToast) window.lstrToast('error', 'Could not update listing.');
        });
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
  var btn   = document.getElementById('upd-filter-toggle');
  var panel = document.getElementById('upd-filter-panel');
  if (!btn || !panel) return;

  btn.addEventListener('click', function () {
    var open = panel.classList.contains('is-open');
    panel.classList.toggle('is-open', !open);
    btn.setAttribute('aria-expanded', String(!open));
  });
}());

// ── 45. Import scope: ZIP upload button ───────────────────────────────────────

(function () {
  var btn = document.getElementById('js-zip-upload-btn');
  if (!btn) return;

  btn.addEventListener('click', function () {
    var fileInput = document.getElementById('js-zip-file-input');
    var result    = document.getElementById('js-zip-upload-result');
    if (!fileInput || !fileInput.files || !fileInput.files[0]) {
      if (result) { result.textContent = 'Please select a ZIP file first.'; result.classList.remove('is-hidden'); }
      return;
    }

    var fd = new FormData();
    fd.append('_csrf',      btn.dataset.csrf  || '');
    fd.append('import_zip', fileInput.files[0]);

    btn.disabled    = true;
    btn.textContent = 'Uploading…';
    if (result) { result.classList.add('is-hidden'); }

    fetch(btn.dataset.uploadUrl || '', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled    = false;
        btn.textContent = 'Upload ZIP';
        if (result) {
          result.classList.remove('is-hidden');
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
        btn.disabled    = false;
        btn.textContent = 'Upload ZIP';
        if (result) { result.textContent = 'Upload failed. Please try again.'; result.classList.remove('is-hidden'); result.style.color = 'var(--danger)'; }
      });
  });
}());

// ── 46. Generic active toggle — .lstr-toggle-form ─────────────────────────────
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


// ── 47. Wishlist: filter panel toggle ─────────────────────────────────────────

(function () {
  var btn   = document.getElementById('wl-filter-toggle');
  var panel = document.getElementById('wl-filter-panel');
  if (!btn || !panel) return;

  btn.addEventListener('click', function () {
    var open = panel.classList.contains('is-open');
    panel.classList.toggle('is-open', !open);
    btn.setAttribute('aria-expanded', String(!open));
  });
}());


// ── 48. Link Checker ──────────────────────────────────────────────────────────

(function () {

  // ── Tab switching ─────────────────────────────────────────────────────────
  var tabBtns = document.querySelectorAll('.lc-tab-btn');
  if (!tabBtns.length) return;

  tabBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      tabBtns.forEach(function (b) {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
      });
      btn.classList.add('active');
      btn.setAttribute('aria-selected', 'true');

      var target = btn.dataset.tab;
      document.querySelectorAll('.lc-tab-panel').forEach(function (panel) {
        panel.classList.toggle('is-hidden', panel.id !== 'lc-panel-' + target);
      });
    });
  });

  // ── Shared batch check logic ──────────────────────────────────────────────

  function statusLabel(s) {
    var map = { ok: 'OK', dead: 'Dead', redirect: 'Redirect', found: 'Found', missing: 'Missing', unchecked: 'Unchecked' };
    return map[s] || s;
  }

  function applyResult(row, data, type) {
    var linkBadge  = row.querySelector('[data-lc-link-badge]');
    var recipBadge = row.querySelector('[data-lc-recip-badge]');
    var checkedCell = row.querySelector('[data-lc-checked]');

    var statusClasses = ['lc-ok', 'lc-dead', 'lc-redirect', 'lc-unchecked'];

    if (linkBadge) {
      statusClasses.forEach(function (c) { linkBadge.classList.remove(c); });
      linkBadge.classList.add('lc-' + data.link_status);
      linkBadge.textContent = statusLabel(data.link_status);
    }
    if (recipBadge && data.reciprocal_status !== undefined) {
      ['lc-found', 'lc-missing', 'lc-unchecked'].forEach(function (c) { recipBadge.classList.remove(c); });
      recipBadge.classList.add('lc-' + data.reciprocal_status);
      recipBadge.textContent = statusLabel(data.reciprocal_status);
    }
    if (checkedCell) {
      checkedCell.textContent = data.checked_at || '';
    }

    row.classList.remove('lc-busy');
  }

  function checkOne(endpoint, csrf, id, row, onDone) {
    row.classList.add('lc-busy');
    var body = '_csrf=' + encodeURIComponent(csrf) + '&id=' + encodeURIComponent(id);
    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        applyResult(row, data);
        if (onDone) onDone();
      })
      .catch(function () {
        row.classList.remove('lc-busy');
        if (onDone) onDone();
      });
  }

  function runBatch(startBtn, panelId) {
    var panel       = document.getElementById('lc-panel-' + panelId);
    var progWrap    = document.getElementById('lc-prog-' + panelId);
    var progBar     = document.getElementById('lc-prog-bar-' + panelId);
    var progLabel   = document.getElementById('lc-prog-label-' + panelId);
    var rows        = panel ? Array.from(panel.querySelectorAll('tbody tr')) : [];
    var endpoint    = startBtn.dataset.endpoint;
    var csrf        = startBtn.dataset.csrf;
    var total       = rows.length;
    var done        = 0;

    if (!total) return;

    startBtn.disabled = true;
    if (progWrap)  { progWrap.classList.remove('is-hidden'); }

    function next() {
      if (done >= total) {
        startBtn.disabled = false;
        return;
      }
      var row = rows[done];
      var id  = row.id.replace(/^lc-(aff|joined)-/, '');
      done++;
      var pct = Math.round((done / total) * 100);
      if (progBar)   progBar.style.width = pct + '%';
      if (progLabel) progLabel.textContent = done + ' / ' + total;

      checkOne(endpoint, csrf, id, row, function () {
        setTimeout(next, 200);
      });
    }

    next();
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

  // ── Individual "Check" buttons ────────────────────────────────────────────
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-lc-single');
    if (!btn) return;

    var id       = btn.dataset.id;
    var endpoint = btn.dataset.endpoint;
    var csrf     = btn.dataset.csrf;
    var type     = btn.dataset.type;
    var rowId    = 'lc-' + (type === 'affiliate' ? 'aff' : 'joined') + '-' + id;
    var row      = document.getElementById(rowId);

    if (!row) return;

    btn.disabled = true;
    checkOne(endpoint, csrf, id, row, function () {
      btn.disabled = false;
    });
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
    { label: 'SMTP Settings',      hint: 'Emails',   url: base + '/admin/emails?tab=smtp' },
    { label: 'Account Details',    hint: 'Settings', url: base + '/admin/settings#panel-admin' },
    { label: 'Admin Accounts',     hint: 'Settings', url: base + '/admin/settings#panel-accounts' },
    { label: 'General Settings',   hint: 'Settings', url: base + '/admin/settings#panel-general' },
    { label: 'Server Paths',       hint: 'Settings', url: base + '/admin/settings#panel-embed' },
    { label: 'Blacklist',          hint: 'Settings', url: base + '/admin/settings#panel-blacklist' },
    { label: 'System Details',     hint: 'Settings', url: base + '/admin/settings#panel-system' },
    { label: 'System Info',        hint: 'Admin',    url: base + '/admin/sysinfo' },
    { label: 'Members Settings',   hint: 'Members',  url: base + '/admin/members/settings' },
    { label: 'Collective Templates', hint: 'Listings', url: base + '/admin/listings/collective-templates' },
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
      a.className = 'nav-item nav-item--extra';
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
    var items    = document.querySelectorAll('.sidebar-nav .nav-item:not(.nav-item--extra)');
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
      var hasItem  = Array.from(s.querySelectorAll('.nav-item:not(.nav-item--extra)')).some(function (el) { return el.style.display !== 'none'; });
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
