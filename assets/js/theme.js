/* Applies the saved (or system) colour theme before the page paints, and handles the toggle button. */
(function () {
  'use strict';
  var KEY = 'agri-theme';
  var root = document.documentElement;

  function saved() {
    try { return window.localStorage.getItem(KEY); } catch (e) { return null; }
  }
  function preferred() {
    var s = saved();
    if (s === 'light' || s === 'dark') { return s; }
    return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
  }

  root.setAttribute('data-bs-theme', preferred());

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-theme-toggle]') : null;
    if (!btn) { return; }
    var next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-bs-theme', next);
    try { window.localStorage.setItem(KEY, next); } catch (e) { /* private mode: theme just won't persist */ }
    btn.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
  });
})();
