(function () {
  'use strict';

  var hasBootstrap = typeof window.bootstrap !== 'undefined';

  // Toasts rendered by the server
  if (hasBootstrap) {
    document.querySelectorAll('.toast[data-autoshow]').forEach(function (el) {
      new bootstrap.Toast(el, { delay: 5000 }).show();
    });
  }

  // Mobile sidebar
  var sidebar = document.getElementById('sidebar');
  var backdrop = document.getElementById('sidebarBackdrop');
  var toggle = document.getElementById('sidebarToggle');
  function setSidebar(open) {
    if (sidebar) { sidebar.classList.toggle('open', open); }
    if (backdrop) { backdrop.classList.toggle('open', open); }
    if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  }
  if (toggle && sidebar) toggle.addEventListener('click', function () { setSidebar(!sidebar.classList.contains('open')); });
  if (backdrop) backdrop.addEventListener('click', function () { setSidebar(false); });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { setSidebar(false); } });

  // Confirmation dialog for destructive actions: <button data-confirm="Archive this item?">
  var modalEl = document.getElementById('confirmModal');
  var okBtn = document.getElementById('confirmOk');
  var msgEl = document.getElementById('confirmMessage');
  if (hasBootstrap && modalEl && okBtn && msgEl) {
    var modal = new bootstrap.Modal(modalEl);
    var pending = null;
    document.addEventListener('click', function (ev) {
      var el = ev.target.closest('[data-confirm]');
      if (!el) return;
      ev.preventDefault();
      pending = el;
      msgEl.textContent = el.getAttribute('data-confirm');
      modal.show();
    });
    okBtn.addEventListener('click', function () {
      modal.hide();
      if (!pending) return;
      var el = pending;
      pending = null;
      if (el.tagName === 'A') {
        window.location.href = el.href;
      } else if (el.form) {
        // form.submit() does not send the clicked button's name/value, so destructive forms use hidden inputs
        if (el.name) {   // keep the clicked button's name/value (e.g. confirm_delivery)
          var h = document.createElement('input');
          h.type = 'hidden'; h.name = el.name; h.value = el.value;
          el.form.appendChild(h);
        }
        el.form.submit();
      }
    });
  }

  // Client-side validation styling (server-side validation remains authoritative)
  document.querySelectorAll('form.needs-validation').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!form.checkValidity()) { ev.preventDefault(); ev.stopPropagation(); }
      form.classList.add('was-validated');
    });
  });
})();
