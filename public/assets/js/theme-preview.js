(function () {
  'use strict';

  var root = document.documentElement;
  var isDark = true;

  /* ── Palette switching ── */
  function setPalette(pal) {
    root.setAttribute('data-palette', pal);
    document.querySelectorAll('.palette-btn').forEach(function (b) {
      b.classList.toggle('active', b.dataset.pal === pal);
    });
  }

  /* ── Light / dark toggle ── */
  function toggleMode() {
    isDark = !isDark;
    var theme = isDark ? 'dark' : 'light';
    root.setAttribute('data-theme', theme);

    var label = document.getElementById('mode-label');
    if (label) label.textContent = isDark ? 'Light mode' : 'Dark mode';

    var icon = document.getElementById('mode-icon');
    if (icon) {
      icon.innerHTML = isDark
        ? '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>'
        : '<circle cx="12" cy="12" r="5"/><path d="M12 1v3M12 20v3M4.22 4.22l2.12 2.12M17.66 17.66l2.12 2.12M1 12h3M20 12h3M4.22 19.78l2.12-2.12M17.66 6.34l2.12-2.12"/>';
    }
  }

  /* ── Logs group toggle ── */
  function toggleLogs() {
    var panel = document.getElementById('logs-panel');
    var btn   = document.querySelector('.nav-group-toggle');
    if (!panel || !btn) return;
    var open = panel.classList.toggle('open');
    var chev = btn.querySelector('.nav-group-chevron');
    if (chev) chev.style.transform = open ? 'rotate(180deg)' : '';
  }

  /* ── Wire up events after DOM ready ── */
  document.addEventListener('DOMContentLoaded', function () {

    /* Palette buttons */
    document.querySelectorAll('.palette-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setPalette(btn.dataset.pal);
      });
    });

    /* Mode toggle */
    var modeBtn = document.querySelector('.mode-toggle');
    if (modeBtn) modeBtn.addEventListener('click', toggleMode);

    /* Logs toggle */
    var logsBtn = document.querySelector('.nav-group-toggle');
    if (logsBtn) logsBtn.addEventListener('click', toggleLogs);

  });

}());
