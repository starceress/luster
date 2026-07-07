(function () {
  function wireDropdowns() {
    document.querySelectorAll('.fl-category-dropdown select:not([data-fl-wired])').forEach(function (s) {
      s.setAttribute('data-fl-wired', '1');
      s.addEventListener('change', function () { s.form.submit(); });
    });
  }

  function wireDeleteMembershipConfirm() {
    document.querySelectorAll('form.fl-form-update:not([data-fl-delete-wired])').forEach(function (form) {
      var box = form.querySelector('#upd-delete-membership');
      if (!box) { return; }
      form.setAttribute('data-fl-delete-wired', '1');
      form.addEventListener('submit', function (e) {
        if (box.checked && !window.confirm('Are you sure you want to permanently delete your membership? This cannot be undone.')) {
          e.preventDefault();
        }
      });
    });
  }

  function wireToggleForms() {
    document.querySelectorAll('[data-fl-toggle]:not([data-fl-wired-toggle])').forEach(function (btn) {
      btn.setAttribute('data-fl-wired-toggle', '1');
      btn.addEventListener('click', function () {
        var target = document.getElementById(btn.getAttribute('data-fl-toggle'));
        if (!target) { return; }
        var willShow = target.hidden;
        target.hidden = !willShow;
        btn.setAttribute('aria-expanded', willShow ? 'true' : 'false');
      });
    });
  }

  function wireAll() {
    wireDropdowns();
    wireDeleteMembershipConfirm();
    wireToggleForms();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireAll);
  } else {
    wireAll();
  }
})();
