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
