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

// ── 3. Sidebar constellation (#constellation — in the layout sidebar) ─────────

(function () {
  var canvas = document.getElementById('constellation');
  if (!canvas) return;
  var ctx   = canvas.getContext('2d');
  var stars = [];
  var N     = 16;
  var rafId = null;

  function resize() {
    canvas.width  = canvas.parentElement.offsetWidth;
    canvas.height = canvas.parentElement.offsetHeight;
  }

  function init() {
    resize();
    stars = [];
    for (var i = 0; i < N; i++) {
      stars.push({
        x: Math.random() * canvas.width,
        y: Math.random() * canvas.height,
        r: Math.random() * 1.2 + 0.3,
        dx: (Math.random() - 0.5) * 0.22,
        dy: (Math.random() - 0.5) * 0.22,
        opacity: Math.random() * 0.25 + 0.08,
        twinkleSpeed: Math.random() * 0.015 + 0.005,
        twinklePhase: Math.random() * Math.PI * 2,
      });
    }
  }

  function draw() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    var t = Date.now() * 0.001;
    stars.forEach(function (s) {
      s.x += s.dx; s.y += s.dy;
      if (s.x < 0) s.x = canvas.width;
      if (s.x > canvas.width)  s.x = 0;
      if (s.y < 0) s.y = canvas.height;
      if (s.y > canvas.height) s.y = 0;
      var tw = s.opacity + Math.sin(t * s.twinkleSpeed * 60 + s.twinklePhase) * 0.08;
      ctx.beginPath();
      ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(196,181,253,' + Math.max(0.03, Math.min(0.4, tw)) + ')';
      ctx.fill();
    });
    for (var i = 0; i < stars.length; i++) {
      for (var j = i + 1; j < stars.length; j++) {
        var dx = stars[i].x - stars[j].x;
        var dy = stars[i].y - stars[j].y;
        var d  = Math.sqrt(dx * dx + dy * dy);
        if (d < 70) {
          ctx.beginPath();
          ctx.moveTo(stars[i].x, stars[i].y);
          ctx.lineTo(stars[j].x, stars[j].y);
          ctx.strokeStyle = 'rgba(196,181,253,' + (0.05 * (1 - d / 70)) + ')';
          ctx.lineWidth   = 0.5;
          ctx.stroke();
        }
      }
    }
    rafId = requestAnimationFrame(draw);
  }

  function start() { if (!rafId) rafId = requestAnimationFrame(draw); }
  function stop()  { if (rafId) { cancelAnimationFrame(rafId); rafId = null; } }

  document.addEventListener('visibilitychange', function () {
    document.hidden ? stop() : start();
  });
  window.addEventListener('resize', init);
  init();
  start();
}());

// ── 4. Auth-page constellation (#login-constellation — login, forgot, reset) ──

(function () {
  var canvas = document.getElementById('login-constellation');
  if (!canvas) return;
  var ctx   = canvas.getContext('2d');
  var stars = [];
  var N     = 80;
  var rafId = null;

  function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }

  function init() {
    resize();
    stars = [];
    for (var i = 0; i < N; i++) {
      stars.push({
        x: Math.random() * canvas.width,
        y: Math.random() * canvas.height,
        r: Math.random() * 1.5 + 0.3,
        dx: (Math.random() - 0.5) * 0.18,
        dy: (Math.random() - 0.5) * 0.18,
        opacity: Math.random() * 0.5 + 0.15,
        twinkleSpeed: Math.random() * 0.012 + 0.004,
        twinklePhase: Math.random() * Math.PI * 2,
      });
    }
  }

  function draw() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    var t = Date.now() * 0.001;
    stars.forEach(function (s) {
      s.x += s.dx; s.y += s.dy;
      if (s.x < 0) s.x = canvas.width; if (s.x > canvas.width)  s.x = 0;
      if (s.y < 0) s.y = canvas.height; if (s.y > canvas.height) s.y = 0;
      var tw = s.opacity + Math.sin(t * s.twinkleSpeed * 60 + s.twinklePhase) * 0.18;
      ctx.beginPath();
      ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(196,181,253,' + Math.max(0.04, Math.min(0.85, tw)) + ')';
      ctx.fill();
    });
    for (var i = 0; i < stars.length; i++) {
      for (var j = i + 1; j < stars.length; j++) {
        var dx = stars[i].x - stars[j].x;
        var dy = stars[i].y - stars[j].y;
        var d  = Math.sqrt(dx * dx + dy * dy);
        if (d < 90) {
          ctx.beginPath();
          ctx.moveTo(stars[i].x, stars[i].y);
          ctx.lineTo(stars[j].x, stars[j].y);
          ctx.strokeStyle = 'rgba(196,181,253,' + (0.09 * (1 - d / 90)) + ')';
          ctx.lineWidth   = 0.5;
          ctx.stroke();
        }
      }
    }
    rafId = requestAnimationFrame(draw);
  }

  function start() { if (!rafId) rafId = requestAnimationFrame(draw); }
  function stop()  { if (rafId) { cancelAnimationFrame(rafId); rafId = null; } }

  document.addEventListener('visibilitychange', function () { document.hidden ? stop() : start(); });
  window.addEventListener('resize', init);
  init();
  start();
}());

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
// data-confirm on a <form>: shows a confirmation dialog before submitting.
// Forms with select[name="bulk_action"]: validates that at least one row is
// checked and confirms destructive deletes.
// Listings bulk form (select[name="action"] + .fl-row-cb): validates selection.

document.addEventListener('submit', function (e) {
  var form = e.target;

  // data-confirm: simple confirmation dialog
  var msg = form.dataset.confirm;
  if (msg) {
    if (!confirm(msg)) { e.preventDefault(); return; }
  }

  // Members / joined bulk form: select[name="bulk_action"]
  var bulkSel = form.querySelector('select[name="bulk_action"]');
  if (bulkSel) {
    var bulkChecked = form.querySelectorAll('input[name="emails[]"]:checked, input[name="ids[]"]:checked');
    if (!bulkChecked.length) { alert('No items selected.'); e.preventDefault(); return; }
    if (bulkSel.value === 'delete' &&
        !confirm('Delete ' + bulkChecked.length + ' selected item(s)? This cannot be undone.')) {
      e.preventDefault(); return;
    }
    return;
  }

  // Listings bulk form: select[name="action"] + .fl-row-cb checkboxes
  var actionSel = form.querySelector('select[name="action"]');
  if (actionSel && form.querySelector('.fl-row-cb')) {
    var listingChecked = form.querySelectorAll('.fl-row-cb:checked');
    if (!listingChecked.length) { alert('No listings selected.'); e.preventDefault(); return; }
    if (!confirm('Apply bulk action to selected listings?')) { e.preventDefault(); return; }
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

// ── 13. Tab switching (.neb-tab-btn[data-tab] / #tab-{tabId}) ─────────────────
//
// Generic tab switcher used by listings/edit, settings, and anywhere else that
// uses the .neb-tab-btn / .neb-tab-panel markup pattern.
// Activates a tab by URL hash on load (e.g. settings redirects with #tab-email).

(function () {
  var tabs = document.querySelectorAll('.neb-tab-btn');
  if (!tabs.length) return;

  function activateTab(tabId) {
    document.querySelectorAll('.neb-tab-btn, .neb-tab-panel').forEach(function (el) {
      el.classList.remove('active');
    });
    var btn   = document.querySelector('.neb-tab-btn[data-tab="' + tabId + '"]');
    var panel = document.getElementById('tab-' + tabId);
    if (btn)   btn.classList.add('active');
    if (panel) panel.classList.add('active');
  }

  tabs.forEach(function (btn) {
    btn.addEventListener('click', function () { activateTab(btn.dataset.tab); });
  });

  // Activate from URL hash (settings page: redirect with ?...#tab-email etc.)
  var hash = window.location.hash.replace('#tab-', '');
  if (hash && document.querySelector('.neb-tab-btn[data-tab="' + hash + '"]')) {
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
