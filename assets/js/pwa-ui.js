(function () {
  const APP = window.__APP__ || {};
  const STORAGE_KEY = 'mpw-doc-tracker-theme';
  const installBtn = document.getElementById('installAppBtn');
  const navToggle = document.getElementById('navToggle');
  const navCloseBtn = document.getElementById('navCloseBtn');
  const mainNav = document.getElementById('mainNav');
  const navBackdrop = document.getElementById('appNavBackdrop');
  const themeButtons = Array.from(document.querySelectorAll('[data-theme-value]'));
  let deferredPrompt = null;

  function safeTheme(value) {
    return ['light', 'dark'].includes(value) ? value : 'light';
  }

  function setThemeMeta(theme) {
    const meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) return;
    meta.setAttribute('content', theme === 'dark' ? '#0a1623' : '#0b3a66');
  }

  function applyTheme(theme) {
    const next = safeTheme(theme);
    document.documentElement.setAttribute('data-theme', next);
    document.body.setAttribute('data-theme', next);
    try { localStorage.setItem(STORAGE_KEY, next); } catch (e) {}

    themeButtons.forEach((btn) => {
      btn.classList.toggle('isActive', btn.getAttribute('data-theme-value') === next);
    });

    setThemeMeta(next);
  }

  function initTheme() {
    let theme = 'light';
    try { theme = safeTheme(localStorage.getItem(STORAGE_KEY) || 'light'); } catch (e) {}
    applyTheme(theme);

    themeButtons.forEach((btn) => {
      btn.addEventListener('click', function () {
        applyTheme(btn.getAttribute('data-theme-value') || 'light');
      });
    });
  }

  function openNav() {
    if (!mainNav || !navToggle) return;
    mainNav.classList.add('isOpen');
    mainNav.setAttribute('aria-hidden', 'false');
    navToggle.setAttribute('aria-expanded', 'true');
    document.body.classList.add('nav-drawer-open');
    if (navBackdrop) {
      navBackdrop.hidden = false;
      requestAnimationFrame(() => navBackdrop.classList.add('isOpen'));
    }
  }

  function closeNav() {
    if (!mainNav || !navToggle) return;
    mainNav.classList.remove('isOpen');
    mainNav.setAttribute('aria-hidden', 'true');
    navToggle.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('nav-drawer-open');
    if (navBackdrop) {
      navBackdrop.classList.remove('isOpen');
      window.setTimeout(() => {
        if (!mainNav.classList.contains('isOpen')) navBackdrop.hidden = true;
      }, 220);
    }
  }

  function initNav() {
    if (!navToggle || !mainNav) return;

    navToggle.addEventListener('click', function (event) {
      event.stopPropagation();
      const open = mainNav.classList.contains('isOpen');
      if (open) closeNav(); else openNav();
    });

    if (navCloseBtn) {
      navCloseBtn.addEventListener('click', closeNav);
    }

    if (navBackdrop) {
      navBackdrop.addEventListener('click', closeNav);
    }

    mainNav.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', closeNav);
    });

    window.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeNav();
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth > 980) {
        closeNav();
      }
    });
  }

  function initInstallPrompt() {
    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();
      deferredPrompt = e;
      if (installBtn && window.innerWidth <= 980) installBtn.hidden = false;
    });

    if (installBtn) {
      installBtn.addEventListener('click', async function () {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        try {
          await deferredPrompt.userChoice;
        } catch (e) {}
        deferredPrompt = null;
        installBtn.hidden = true;
      });
    }

    window.addEventListener('appinstalled', function () {
      deferredPrompt = null;
      if (installBtn) installBtn.hidden = true;
    });

    window.addEventListener('resize', function () {
      if (!installBtn || installBtn.hidden || !deferredPrompt) return;
      installBtn.hidden = window.innerWidth > 980;
    });
  }

  function isDevelopmentEnvironment() {
    if (APP.isDevelopment === true) return true;
    const hostname = window.location.hostname || '';
    return hostname === 'localhost'
      || hostname === '127.0.0.1'
      || hostname === '::1'
      || /^192\.168\./.test(hostname)
      || /^10\./.test(hostname)
      || /^172\.(1[6-9]|2\d|3[0-1])\./.test(hostname);
  }

  async function clearServiceWorkerCaches() {
    if (!('caches' in window)) return;
    try {
      const keys = await caches.keys();
      await Promise.all(keys.map(function (key) { return caches.delete(key); }));
    } catch (e) {}
  }

  async function unregisterServiceWorkers() {
    if (!('serviceWorker' in navigator)) return;
    try {
      const registrations = await navigator.serviceWorker.getRegistrations();
      await Promise.all(registrations.map(function (registration) {
        return registration.unregister();
      }));
    } catch (e) {}
  }

  function initServiceWorker() {
    if (!('serviceWorker' in navigator) || !APP.base) return;
    window.addEventListener('load', function () {
      if (isDevelopmentEnvironment()) {
        void unregisterServiceWorkers().then(clearServiceWorkerCaches);
        return;
      }

      navigator.serviceWorker.register(APP.base + '/sw.js').catch(function () {});
    });
  }

  function initResponsiveTableLabels() {
    const tables = Array.from(document.querySelectorAll('.docsTableModern'));
    tables.forEach((table) => {
      const labels = Array.from(table.querySelectorAll('thead th')).map((th) => (th.textContent || '').trim());
      table.querySelectorAll('tbody tr').forEach((row) => {
        Array.from(row.children).forEach((td, index) => {
          if (!td.getAttribute('data-label') && labels[index]) {
            td.setAttribute('data-label', labels[index]);
          }
        });
      });
    });
  }

  function initStandaloneClass() {
    const isStandalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;
    if (isStandalone) {
      document.documentElement.classList.add('is-standalone');
    }
  }

  initTheme();
  initNav();
  initInstallPrompt();
  initServiceWorker();
  initResponsiveTableLabels();
  initStandaloneClass();
})();
