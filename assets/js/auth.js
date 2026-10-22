/* Sign-in / setup pages: password reveal, Caps Lock hint, validation and a loading state on submit. */
(function () {
  'use strict';

  // Show / hide password
  document.querySelectorAll('[data-pw-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-pw-toggle'));
      if (!input) { return; }
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      var icon = btn.querySelector('i');
      if (icon) { icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye'; }
      input.focus();
    });
  });

  // Caps Lock hint
  var hint = document.getElementById('capsHint');
  if (hint) {
    document.querySelectorAll('input[type="password"], input[data-pw]').forEach(function (input) {
      var check = function (ev) {
        if (ev.getModifierState) { hint.classList.toggle('show', ev.getModifierState('CapsLock')); }
      };
      input.addEventListener('keydown', check);
      input.addEventListener('keyup', check);
      input.addEventListener('blur', function () { hint.classList.remove('show'); });
    });
  }

  // Validation + loading state
  document.querySelectorAll('form.needs-validation').forEach(function (form) {
    var submitting = false;
    form.addEventListener('submit', function (ev) {
      if (submitting || !form.checkValidity()) {
        ev.preventDefault();
        ev.stopPropagation();
        form.classList.add('was-validated');
        return;
      }
      submitting = true;
      var btn = form.querySelector('.auth-submit');
      if (btn) {
        btn.classList.add('is-loading');
        btn.setAttribute('aria-busy', 'true');
        var label = btn.querySelector('.label');
        if (label && btn.getAttribute('data-loading-text')) { label.textContent = btn.getAttribute('data-loading-text'); }
      }
    });
    // Back/forward cache: make sure the button is usable again
    window.addEventListener('pageshow', function (ev) {
      if (!ev.persisted) { return; }
      submitting = false;
      var btn = form.querySelector('.auth-submit');
      if (btn) {
        btn.classList.remove('is-loading');
        btn.removeAttribute('aria-busy');
        var label = btn.querySelector('.label');
        if (label && btn.getAttribute('data-label')) { label.textContent = btn.getAttribute('data-label'); }
      }
    });
  });
})();
