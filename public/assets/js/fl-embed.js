(function () {
  function wireDropdowns() {
    document.querySelectorAll('.fl-category-dropdown select:not([data-fl-wired])').forEach(function (s) {
      s.setAttribute('data-fl-wired', '1');
      s.addEventListener('change', function () { s.form.submit(); });
    });
  }

  function wireDeleteMembershipConfirm() {
    document.querySelectorAll('.fl-embed--update form.fl-form:not([data-fl-delete-wired])').forEach(function (form) {
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

  function wireAll() {
    wireDropdowns();
    wireDeleteMembershipConfirm();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireAll);
  } else {
    wireAll();
  }
})();
