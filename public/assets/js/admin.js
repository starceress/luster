/* =============================================================================
   admin.js — Luster admin panel interactive layer

   Replaces all inline <script> blocks and inline event handlers across the
   admin template set. Loaded at the bottom of layout.php and all standalone
   auth pages (login, forgot, reset). Every function guards against missing
   DOM elements so it is safe to load unconditionally on every admin page.
   ============================================================================= */

'use strict';

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

// ── 6. Password toggle buttons (event delegation on .input-icon-btn) ──────────

document.addEventListener('click', function (e) {
  var btn = e.target.closest('.input-icon-btn');
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
    if (bulkSel.value === 'delete') {
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

// ── 13. Tab switching (.neb-tab-btn / .settings-nav-item / #tab-{tabId}) ──────
//
// Generic tab switcher used by listings/edit, settings, and anywhere else that
// uses the .neb-tab-btn / .neb-tab-panel markup pattern. Also handles the
// settings page vertical sidebar nav (.settings-nav-item).
// Activates a tab by URL hash on load (e.g. settings redirects with #tab-email).

(function () {
  var tabs     = document.querySelectorAll('.neb-tab-btn');
  var navItems = document.querySelectorAll('.settings-nav-item');
  if (!tabs.length && !navItems.length) return;

  function activateTab(tabId) {
    document.querySelectorAll('.neb-tab-btn, .neb-tab-panel, .settings-nav-item').forEach(function (el) {
      el.classList.remove('active');
    });
    var btn     = document.querySelector('.neb-tab-btn[data-tab="' + tabId + '"]');
    var navItem = document.querySelector('.settings-nav-item[data-tab="' + tabId + '"]');
    var panel   = document.getElementById('tab-' + tabId);
    if (btn)     btn.classList.add('active');
    if (navItem) navItem.classList.add('active');
    if (panel)   panel.classList.add('active');
  }

  tabs.forEach(function (btn) {
    btn.addEventListener('click', function () { activateTab(btn.dataset.tab); });
  });
  navItems.forEach(function (item) {
    item.addEventListener('click', function () { activateTab(item.dataset.tab); });
  });

  // Activate from URL hash (settings page: redirect with ?...#tab-email etc.)
  var hash = window.location.hash.replace('#tab-', '');
  if (hash && (document.querySelector('.neb-tab-btn[data-tab="' + hash + '"]') ||
               document.querySelector('.settings-nav-item[data-tab="' + hash + '"]'))) {
    activateTab(hash);
  }
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
  var existing   = JSON.parse(widget.dataset.existing);

  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function buildNetOpts(selected) {
    var h = '<option value="">\u2014 select network \u2014</option>';
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
    var h = '<option value="">\u2014 select category \u2014</option>';
    cats.forEach(function (c) {
      h += '<option value="' + esc(c.name) + '"' +
           (selected === c.name ? ' selected' : '') + '>' + esc(c.name) + '</option>';
    });
    return h;
  }

  function syncRow(row, netVal, catVal) {
    var catSel = row.querySelector('.cat-name-sel');
    var catTxt = row.querySelector('.cat-name-txt');
    var othTxt = row.querySelector('.cat-other-net');
    catVal = catVal || '';
    if (netVal === 'TFL' || netVal === 'TAFL') {
      catSel.innerHTML     = buildCatOpts(netVal === 'TFL' ? tflCats : taflCats, catVal);
      catSel.disabled      = false; catSel.style.display = '';
      catTxt.disabled      = true;  catTxt.style.display = 'none';
      othTxt.style.display = 'none';
    } else if (netVal === 'other') {
      catSel.disabled      = true;  catSel.style.display = 'none';
      catTxt.disabled      = false; catTxt.style.display = '';
      if (catVal) catTxt.value = catVal;
      othTxt.style.display = '';
    } else if (netVal !== '') {
      catSel.disabled      = true;  catSel.style.display = 'none';
      catTxt.disabled      = false; catTxt.style.display = '';
      if (catVal) catTxt.value = catVal;
      othTxt.style.display = 'none';
    } else {
      catSel.disabled      = true;  catSel.style.display = 'none';
      catTxt.disabled      = true;  catTxt.style.display = 'none';
      othTxt.style.display = 'none';
    }
  }

  function makeRow(netVal, catVal, otherNetVal) {
    netVal = netVal || ''; catVal = catVal || ''; otherNetVal = otherNetVal || '';
    var row = document.createElement('div');
    row.className = 'cat-row';
    row.innerHTML =
      '<select name="cat_network[]" class="cat-net-sel">' + buildNetOpts(netVal) + '</select>' +
      '<select name="cat_name[]" class="cat-name-sel" disabled style="display:none"></select>' +
      '<input type="text" name="cat_network_other[]" class="cat-other-net" value="' +
          esc(otherNetVal) + '" placeholder="New network name" style="display:none">' +
      '<input type="text" name="cat_name[]" class="cat-name-txt" disabled ' +
          'style="display:none" placeholder="Category name">' +
      '<button type="button" class="cat-remove-btn" title="Remove">&times;</button>';
    row.querySelector('.cat-net-sel').addEventListener('change', function () {
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

// ── 16. Embed codes: panel switcher + copy buttons ───────────────────────────

(function () {
  var opts = document.querySelectorAll('.ec-opt');
  if (!opts.length) return;
  var current = null;

  function showPanel(target) {
    if (current) {
      var prev = document.getElementById('ec-panel-' + current);
      if (prev) prev.classList.remove('ec-panel--active');
    }
    var next = document.getElementById('ec-panel-' + target);
    if (next) next.classList.add('ec-panel--active');
    current = target;
    opts.forEach(function (btn) {
      btn.setAttribute('aria-selected', btn.dataset.target === target ? 'true' : 'false');
    });
  }

  opts.forEach(function (btn) {
    btn.addEventListener('click', function () { showPanel(btn.dataset.target); });
  });
  showPanel('join');
}());

// Embed copy buttons: data-ec-copy="preId" (event delegation)
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-ec-copy]');
  if (!btn) return;
  var text = (document.getElementById(btn.dataset.ecCopy) || {}).textContent || '';
  if (navigator.clipboard) {
    navigator.clipboard.writeText(text).catch(function () {});
  } else {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (err) {}
    document.body.removeChild(ta);
  }
  btn.textContent = 'Copied!';
  btn.classList.add('ec-copied');
  setTimeout(function () { btn.textContent = 'Copy'; btn.classList.remove('ec-copied'); }, 1800);
});

// ── 17. Members index: select-all checkboxes ──────────────────────────────────

(function () {
  var checkAll = document.getElementById('check-all');
  if (!checkAll) return;
  checkAll.addEventListener('change', function () {
    document.querySelectorAll('#bulk-form input[name="emails[]"]').forEach(function (cb) {
      cb.checked = checkAll.checked;
    });
  });
}());

// ── 18. Listings codes: select-all checkboxes ────────────────────────────────

(function () {
  var all = document.getElementById('codes-select-all');
  if (!all) return;
  all.addEventListener('change', function () {
    document.querySelectorAll('.code-check').forEach(function (cb) { cb.checked = all.checked; });
  });
}());

// ── 19. Listings index: select-all checkboxes ────────────────────────────────

(function () {
  var all = document.getElementById('fl-select-all');
  if (!all) return;
  all.addEventListener('change', function () {
    document.querySelectorAll('.fl-row-cb').forEach(function (cb) { cb.checked = all.checked; });
  });
}());

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

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

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

// ── 23. Joined index: network → category filter ───────────────────────────────
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
    r.style.display = (s.value === 'dropdown' || s.value === 'checkbox') ? '' : 'none';
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
      r1.style.display =
        (s1.value === 'dropdown' || s1.value === 'checkbox' || s1.value === 'radio') ? '' : 'none';
    }
    s1.addEventListener('change', toggle1);
    toggle1();
  }

  // fields/edit.php
  var s2 = document.getElementById('ef-type');
  var r2 = document.getElementById('ef-options-row');
  if (s2 && r2) {
    function toggle2() {
      r2.style.display =
        (s2.value === 'dropdown' || s2.value === 'checkbox' || s2.value === 'radio') ? '' : 'none';
    }
    s2.addEventListener('change', toggle2);
    toggle2();
  }
}());

// ── 28. Categories add/edit: custom network name row toggle ───────────────────

(function () {
  var sel = document.querySelector('select[name="custom_network"]');
  var row = document.getElementById('network-name-row');
  if (!sel || !row) return;
  function toggle() { row.style.display = sel.value === 'yes' ? 'block' : 'none'; }
  sel.addEventListener('change', toggle);
  toggle();
}());

// ── 29. Members email: template selector + preview modal ─────────────────────

(function () {
  // Template select: populate subject and body when a saved template is chosen
  var tplSel = document.getElementById('template-select');
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

  // Preview modal
  var modal      = document.getElementById('preview-modal');
  if (!modal) return;
  var listingName = modal.dataset.listingName || '';
  var previewBtn  = document.getElementById('preview-email-btn');
  var closeBtn    = document.getElementById('close-preview-btn');

  function previewEmail() {
    var subj = document.getElementById('subject');
    var body = document.getElementById('body');
    var ps   = document.getElementById('preview-subject');
    var pb   = document.getElementById('preview-body');
    if (ps) ps.textContent = (listingName ? listingName + ': ' : '') + (subj ? subj.value : '');
    if (pb) pb.textContent = body ? body.value : '';
    modal.style.display = 'flex';
  }

  function closePreview() { modal.style.display = 'none'; }

  if (previewBtn) previewBtn.addEventListener('click', previewEmail);
  if (closeBtn)   closeBtn.addEventListener('click', closePreview);
  modal.addEventListener('click', function (e) { if (e.target === modal) closePreview(); });
}());

// ── 30. Email template page: live preview ────────────────────────────────────

(function () {
  var pd = document.getElementById('et-preview-data');
  if (!pd) return;

  // Token substitution map (example/dummy values for preview)
  var tokens = {
    '$fan_name$':        'Jane Fan',
    '$fan_email$':       'jane@example.com',
    '$fan_url$':         'https://jane.example.com',
    '$fan_country$':     'United States',
    '$fan_password$':    '(password)',
    '$reset_url$':       'https://example.com/reset/1/confirm?token=abc123',
    '$listing_name$':    pd.dataset.listingName    || 'My Fanlisting',
    '$listing_subject$': pd.dataset.listingSubject || 'Music',
    '$listing_url$':     pd.dataset.listingUrl     || 'https://myfanlisting.com',
    '$site_name$':       pd.dataset.siteName       || 'Luster',
    '$owner_name$':      pd.dataset.ownerName      || 'The Admin',
    '$owner_email$':     pd.dataset.ownerEmail     || 'admin@example.com',
  };

  function applyTokens(text) {
    var result = text;
    Object.keys(tokens).forEach(function (k) {
      result = result.split(k).join(tokens[k]);
    });
    return result;
  }

  function updatePreview(type) {
    var subjInput = document.querySelector('.et-subject[data-type="' + type + '"]');
    var bodyInput = document.querySelector('.et-body[data-type="' + type + '"]');
    var pSubj     = document.querySelector('#et-preview-' + type + ' .et-preview-subject');
    var pBody     = document.querySelector('#et-preview-' + type + ' .et-preview-body');
    if (subjInput && pSubj) pSubj.textContent = applyTokens(subjInput.value);
    if (bodyInput && pBody) pBody.textContent = applyTokens(bodyInput.value);
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

// ── Toast notifications ───────────────────────────────────────────────────────

(function () {
  var DURATION = 4000;

  var ICONS = {
    success: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>',
    error:   '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    warning: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
  };

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

  function createToast(type, message) {
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type;

    var icon = document.createElement('span');
    icon.className = 'toast-icon';
    icon.innerHTML = ICONS[type] || ICONS.error;

    var msg = document.createElement('span');
    msg.className = 'toast-msg';
    msg.textContent = message;

    var close = document.createElement('button');
    close.className = 'toast-close';
    close.setAttribute('aria-label', 'Dismiss');
    close.innerHTML = '&times;';

    var progress = document.createElement('div');
    progress.className = 'toast-progress';
    progress.style.animation = 'toastDrain ' + (DURATION / 1000) + 's linear forwards';

    toast.appendChild(icon);
    toast.appendChild(msg);
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

  /* Programmatic API — call from anywhere to fire a toast */
  window.lstrToast = function (type, message) {
    getContainer().appendChild(createToast(type, message));
  };
}());

/* ── Row-actions overflow menu ──────────────────────────────────────────── */
(function () {
  var THREE_DOTS_SVG =
    '<svg viewBox="0 0 16 4" fill="currentColor" width="16" height="4" aria-hidden="true">' +
    '<circle cx="2" cy="2" r="1.5"/><circle cx="8" cy="2" r="1.5"/><circle cx="14" cy="2" r="1.5"/></svg>';

  function closeAll(except) {
    document.querySelectorAll('.row-actions-toggle[aria-expanded="true"]').forEach(function (btn) {
      if (btn === except) return;
      btn.setAttribute('aria-expanded', 'false');
      var menu = btn.closest('.row-actions').querySelector('.row-actions-menu');
      if (menu) menu.hidden = true;
    });
  }

  document.addEventListener('click', function (e) {
    var toggle = e.target.closest('.row-actions-toggle');
    if (toggle) {
      var isOpen = toggle.getAttribute('aria-expanded') === 'true';
      closeAll(toggle);
      if (!isOpen) {
        toggle.setAttribute('aria-expanded', 'true');
        var menu = toggle.closest('.row-actions').querySelector('.row-actions-menu');
        if (menu) {
          menu.hidden = false;
          /* Flip upward if the menu would overflow the viewport bottom */
          var rect = menu.getBoundingClientRect();
          if (rect.bottom > window.innerHeight - 8) {
            menu.style.top = 'auto';
            menu.style.bottom = 'calc(100% + 5px)';
          } else {
            menu.style.top = '';
            menu.style.bottom = '';
          }
        }
      } else {
        toggle.setAttribute('aria-expanded', 'false');
        var menu = toggle.closest('.row-actions').querySelector('.row-actions-menu');
        if (menu) menu.hidden = true;
      }
      e.stopPropagation();
      return;
    }

    /* Clicking a link item navigates — close the menu first */
    var linkItem = e.target.closest('.row-action-item');
    if (linkItem && linkItem.tagName === 'A') {
      closeAll();
      return;
    }

    /* Click outside any open menu */
    if (!e.target.closest('.row-actions')) closeAll();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAll();
  });
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

// ── 31. Embed codes: quick-setup wizard ───────────────────────────────────────

(function () {
  var wiz = document.getElementById('ec-wiz');
  if (!wiz) return;

  var embedPath = wiz.dataset.embedPath || '';
  var listings  = JSON.parse(wiz.dataset.listings || '[]');

  var TYPES = [
    { key: 'join',       file: 'join.php',            label: 'Join form',            group: 'fl',  needsId: true,  idVal: null, opts: [] },
    { key: 'members',    file: 'members.php',          label: 'Member list',          group: 'fl',  needsId: true,  idVal: null, opts: ['per_page','sort_by','show_search','members_base'] },
    { key: 'stats',      file: 'stats.php',            label: 'Statistics',           group: 'fl',  needsId: true,  idVal: null, opts: [] },
    { key: 'update',     file: 'update.php',           label: 'Update details',       group: 'fl',  needsId: true,  idVal: null, opts: [] },
    { key: 'remove',     file: 'remove.php',           label: 'Leave listing',        group: 'fl',  needsId: true,  idVal: null, opts: [] },
    { key: 'reset',      file: 'reset.php',            label: 'Password reset',       group: 'fl',  needsId: true,  idVal: null, opts: ['reset_step'] },
    { key: 'affiliates', file: 'affiliates.php',       label: 'Affiliates',           group: 'fl',  needsId: true,  idVal: null, opts: ['affiliates_base'] },
    { key: 'owned',      file: 'owned.php',            label: 'Owned listings',       group: 'col', needsId: false, idVal: null, opts: ['status','hide_dropdown'] },
    { key: 'joined',     file: 'joined.php',           label: 'Joined listings',      group: 'col', needsId: false, idVal: null, opts: ['category'] },
    { key: 'cstats',     file: 'collective-stats.php', label: 'Site statistics',      group: 'col', needsId: false, idVal: null, opts: [] },
    { key: 'aff-col',    file: 'affiliates.php',       label: 'Collective affiliates',group: 'col', needsId: false, idVal: 0,    opts: ['affiliates_base'] },
    { key: 'kim',        file: 'kim-signup.php',       label: 'KIM sign-up',          group: 'col', needsId: false, idVal: null, opts: [] },
  ];

  var OPT_DEFS = {
    per_page:       { label: 'Members per page', type: 'number', placeholder: '20',
                      hint: 'Leave blank to use your site\u2019s default.' },
    sort_by:        { label: 'Default sort order', type: 'select',
                      choices: [['', 'Visitor\u2019s choice (default)'], ['name', 'By name'], ['country', 'By country']] },
    show_search:    { label: 'Sort & search controls', type: 'select',
                      choices: [['', 'Shown (default)'], ['false', 'Hidden']] },
    members_base:   { label: 'URL of this page', type: 'text', placeholder: 'https://yoursite.com/members.php',
                      hint: 'The URL of the page where you\u2019re pasting this embed \u2014 keeps pagination links pointing back to your page.' },
    reset_step:     { label: 'Which step to show', type: 'select',
                      choices: [['', 'Both steps on one page (default)'], ['confirm', 'Step 2 only \u2014 new-password form']] },
    affiliates_base:{ label: 'URL of this page', type: 'text', placeholder: 'https://yoursite.com/affiliates.php',
                      hint: 'The URL of the page where you\u2019re pasting this embed \u2014 keeps pagination links pointing back to your page.' },
    status:         { label: 'Which listings to show', type: 'select',
                      choices: [['', 'Current only (default)'], ['current', 'Current'], ['upcoming', 'Upcoming'], ['trouble', 'Trouble'], ['all', 'All statuses']] },
    hide_dropdown:  { label: 'Category filter dropdown', type: 'select',
                      choices: [['', 'Shown (default)'], ['true', 'Hidden']] },
    category:       { label: 'Category filter', type: 'text', placeholder: 'Leave blank for a dropdown, or enter a category name',
                      hint: 'Blank = show category dropdown. \u2018all\u2019 = show all categories. Or enter an exact category name.' },
  };

  var state = { step: 1, type: null, skippedStep2: false, opts: {} };

  var p1         = document.getElementById('ec-wiz-p1');
  var p2         = document.getElementById('ec-wiz-p2');
  var p3         = document.getElementById('ec-wiz-p3');
  var btnBack    = document.getElementById('ec-wiz-back');
  var btnNext    = document.getElementById('ec-wiz-next');
  var btnRestart = document.getElementById('ec-wiz-restart');
  var optsEl     = document.getElementById('ec-wiz-opts');
  var codeEl     = document.getElementById('ec-wiz-code');
  var stepLabel  = document.getElementById('ec-wiz-step-label');
  var dots       = wiz.querySelectorAll('.ec-wiz-dot');

  var STEP_LABELS = [
    '',
    'Step 1 of 3 \u2014 Choose embed',
    'Step 2 of 3 \u2014 Configure',
    'Step 3 of 3 \u2014 Copy code',
  ];

  function getDef(key) {
    for (var i = 0; i < TYPES.length; i++) {
      if (TYPES[i].key === key) return TYPES[i];
    }
    return null;
  }

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function phpStr(s) {
    return "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
  }

  function phpVal(optKey, val) {
    if (optKey === 'per_page')     return String(parseInt(val, 10) || 1);
    if (optKey === 'hide_dropdown' && val === 'true')  return 'true';
    if (optKey === 'show_search'   && val === 'false') return 'false';
    return phpStr(val);
  }

  function generateCode() {
    var def = getDef(state.type);
    if (!def) return '';
    var D = '# -----------------------------------------------------------';
    var lines = ['<?php'];
    lines.push(D);
    if (def.needsId) {
      var lid = state.opts['wiz_listing_id'];
      if (!lid && listings.length) lid = listings[0].id;
      if (!lid) lid = 1;
      lines.push('$luster_fanlisting_id = ' + parseInt(lid, 10) + ';');
      lines.push(D);
    } else if (def.idVal === 0) {
      lines.push('$luster_fanlisting_id = 0; // 0 = collective / site-wide');
      lines.push(D);
    }
    def.opts.forEach(function (optKey) {
      var val = (state.opts[optKey] || '').trim();
      if (!val) return;
      lines.push('$luster_' + optKey + ' = ' + phpVal(optKey, val) + ';');
      lines.push(D);
    });
    lines.push("require('" + embedPath + '/' + def.file + "');");
    lines.push(D);
    lines.push('?>');
    return lines.join('\n');
  }

  function updateDots(step) {
    dots.forEach(function (dot, i) {
      var n = i + 1;
      dot.classList.toggle('active', n === step);
      dot.classList.toggle('done', n < step);
    });
  }

  function showStep(n) {
    state.step = n;
    p1.hidden = n !== 1;
    p2.hidden = n !== 2;
    p3.hidden = n !== 3;
    btnBack.hidden    = n === 1;
    btnRestart.hidden = n < 3;
    updateDots(n);
    if (stepLabel) stepLabel.textContent = STEP_LABELS[n] || '';
    if (n === 1) {
      btnNext.disabled    = !state.type;
      btnNext.textContent = state.type ? 'Next \u2192' : 'Choose an option above';
    } else if (n === 2) {
      btnNext.disabled    = false;
      btnNext.textContent = 'Next \u2192';
    } else {
      btnNext.disabled    = false;
      btnNext.textContent = 'Done';
    }
  }

  function buildOptsForm() {
    var def = getDef(state.type);
    if (!def) { optsEl.innerHTML = ''; return; }
    var html = '';

    if (def.needsId) {
      html += '<div class="ec-wiz-field"><label class="ec-wiz-label" for="wiz-lid">Which fanlisting? <em>*</em></label>';
      if (listings.length) {
        html += '<select class="form-input" id="wiz-lid" name="wiz_listing_id">';
        listings.forEach(function (fl) {
          var sel = String(fl.id) === String(state.opts['wiz_listing_id'] || '') ? ' selected' : '';
          html += '<option value="' + parseInt(fl.id, 10) + '"' + sel + '>' + esc(fl.name) + '</option>';
        });
        html += '</select>';
      } else {
        html += '<input class="form-input" type="number" id="wiz-lid" name="wiz_listing_id" min="1" placeholder="1" style="max-width:100px" value="' + esc(state.opts['wiz_listing_id'] || '') + '">';
      }
      html += '</div>';
    }

    if (def.opts.length > 0) {
      html += '<p class="ec-wiz-opts-note">Optional &mdash; leave at default if you&rsquo;re not sure.</p>';
      def.opts.forEach(function (optKey) {
        var d = OPT_DEFS[optKey];
        if (!d) return;
        var curVal = state.opts[optKey] || '';
        html += '<div class="ec-wiz-field"><label class="ec-wiz-label" for="wiz-' + optKey + '">' + esc(d.label) + '</label>';
        if (d.type === 'select') {
          html += '<select class="form-input" id="wiz-' + optKey + '" name="' + optKey + '">';
          d.choices.forEach(function (ch) {
            html += '<option value="' + esc(ch[0]) + '"' + (curVal === ch[0] ? ' selected' : '') + '>' + esc(ch[1]) + '</option>';
          });
          html += '</select>';
        } else {
          html += '<input class="form-input" type="' + d.type + '" id="wiz-' + optKey + '" name="' + optKey + '"' +
            ' value="' + esc(curVal) + '"' +
            (d.placeholder ? ' placeholder="' + esc(d.placeholder) + '"' : '') + '>';
        }
        if (d.hint) html += '<p class="ec-wiz-hint">' + esc(d.hint) + '</p>';
        html += '</div>';
      });
    }

    if (!def.needsId && def.opts.length === 0) {
      html += '<p class="ec-wiz-no-opts">No configuration needed. Click <strong>Next</strong> to get your code.</p>';
    }

    optsEl.innerHTML = html;

    // Capture initial values (pre-selected / default values)
    optsEl.querySelectorAll('input, select').forEach(function (el) {
      if (el.name && (el.value || el.value === 0)) state.opts[el.name] = el.value;
      el.addEventListener('change', collectOpts);
      el.addEventListener('input',  collectOpts);
    });
  }

  function collectOpts() {
    if (!optsEl) return;
    optsEl.querySelectorAll('input, select').forEach(function (el) {
      if (el.name) state.opts[el.name] = el.value;
    });
  }

  // Type card clicks
  wiz.querySelectorAll('.ec-wiz-type-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      wiz.querySelectorAll('.ec-wiz-type-btn').forEach(function (b) {
        b.setAttribute('aria-selected', 'false');
      });
      btn.setAttribute('aria-selected', 'true');
      state.type = btn.dataset.wizType;
      btnNext.disabled    = false;
      btnNext.textContent = 'Next \u2192';
    });
  });

  // Navigation
  btnNext.addEventListener('click', function () {
    if (state.step === 1) {
      if (!state.type) return;
      var def = getDef(state.type);
      if (!def.needsId && def.opts.length === 0) {
        // No options at all — skip step 2
        state.skippedStep2 = true;
        if (codeEl) codeEl.textContent = generateCode();
        showStep(3);
      } else {
        state.skippedStep2 = false;
        state.opts = {};
        buildOptsForm();
        showStep(2);
      }
    } else if (state.step === 2) {
      collectOpts();
      if (codeEl) codeEl.textContent = generateCode();
      showStep(3);
    } else {
      reset();
    }
  });

  btnBack.addEventListener('click', function () {
    if (state.step === 3) {
      if (state.skippedStep2) {
        showStep(1);
      } else {
        buildOptsForm();
        showStep(2);
      }
    } else if (state.step === 2) {
      showStep(1);
    }
  });

  btnRestart.addEventListener('click', reset);

  function reset() {
    state = { step: 1, type: null, skippedStep2: false, opts: {} };
    wiz.querySelectorAll('.ec-wiz-type-btn').forEach(function (b) {
      b.setAttribute('aria-selected', 'false');
    });
    if (optsEl) optsEl.innerHTML = '';
    if (codeEl) codeEl.textContent = '';
    showStep(1);
  }

  showStep(1);
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

    var samples = (window.lusterTplSamples && window.lusterTplSamples[kind]) || {};
    var out = ta.value;
    for (var key in samples) { out = out.split(key).join(samples[key]); }
    preview.innerHTML = out;
    preview.style.display = '';
    btn.textContent = 'Hide preview';
  });

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
