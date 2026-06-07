(function () {
  function wireDropdowns() {
    document.querySelectorAll('.fl-category-dropdown select:not([data-fl-wired])').forEach(function (s) {
      s.setAttribute('data-fl-wired', '1');
      s.addEventListener('change', function () { s.form.submit(); });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireDropdowns);
  } else {
    wireDropdowns();
  }
})();
