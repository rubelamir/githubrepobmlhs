(() => {
  'use strict';
  if (/[;\s]wv\b/.test(navigator.userAgent || '')) {
    document.documentElement.classList.add('bmlhs-android-app');
  }

  const mobileQuery = window.matchMedia('(max-width: 991.98px)');

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const toggle = target.closest('[data-bmlhs-sidebar-toggle]');
    const body = document.body;

    if (toggle) {
      event.preventDefault();
      if (mobileQuery.matches) {
        const open = !body.classList.contains('sidebar-open');
        body.classList.toggle('sidebar-open', open);
        body.classList.toggle('sidebar-collapse', !open);
        toggle.setAttribute('aria-expanded', String(open));
      } else {
        const collapsed = body.classList.toggle('sidebar-collapse');
        toggle.setAttribute('aria-expanded', String(!collapsed));
      }
    } else if (target.closest('.sidebar-overlay') && body.classList.contains('sidebar-open')) {
      body.classList.remove('sidebar-open');
      body.classList.add('sidebar-collapse');
      document.querySelector('[data-bmlhs-sidebar-toggle]')?.setAttribute('aria-expanded', 'false');
    }
  });
})();
