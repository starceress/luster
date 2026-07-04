/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Install wizard JavaScript. Provides theme toggling and password
 show/hide for the standalone install page.
*/

// Theme toggle
var lstrThemeBtn = document.querySelector('.theme-toggle');
if (lstrThemeBtn) {
  lstrThemeBtn.addEventListener('click', function () {
    var h = document.documentElement;
    var n = h.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    h.setAttribute('data-theme', n);
    try { localStorage.setItem('lstr_theme', n); } catch (e) {}
  });
}

// Password show/hide
document.addEventListener('click', function (e) {
  var btn = e.target.closest('.input-icon-btn');
  if (!btn) return;
  var inp  = btn.previousElementSibling;
  var show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  btn.querySelector('.eye-on').style.display  = show ? 'none' : '';
  btn.querySelector('.eye-off').style.display = show ? ''     : 'none';
  btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});

// Requirements-check "Refresh" button
var lstrRefreshBtn = document.getElementById('lstr-refresh-btn');
if (lstrRefreshBtn) {
  lstrRefreshBtn.addEventListener('click', function () { location.reload(); });
}
