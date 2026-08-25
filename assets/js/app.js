(function () {
  if (window.DTAppSwitcher) return;

  const FLIP_CLASS = 'app-page-flipping';
  const SWITCHING_CLASS = 'isSwitching';
  const FLIP_MS = 660;

  function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
  }

  document.addEventListener('click', (evt) => {
    const link = evt.target instanceof Element ? evt.target.closest('.appModeSwitch') : null;
    if (!link || !(link instanceof HTMLAnchorElement)) return;
    if (evt.defaultPrevented || evt.button !== 0 || evt.metaKey || evt.ctrlKey || evt.shiftKey || evt.altKey) return;
    if (link.target && link.target !== '_self') return;
    if (!link.href) return;

    if (prefersReducedMotion()) return;

    evt.preventDefault();
    link.classList.add(SWITCHING_CLASS);
    document.body.classList.add(FLIP_CLASS);
    window.setTimeout(() => {
      window.location.href = link.href;
    }, FLIP_MS);
  }, true);

  window.addEventListener('pageshow', () => {
    document.body.classList.remove(FLIP_CLASS);
    document.querySelectorAll('.appModeSwitch.' + SWITCHING_CLASS).forEach((link) => {
      link.classList.remove(SWITCHING_CLASS);
    });
  });

  window.DTAppSwitcher = true;
})();

(function () {
  if (window.DTToast) return;

  const TOAST_HOST_ID = 'dtToastHost';
  const DEFAULT_DURATION = 3200;

  function ensureHost() {
    let host = document.getElementById(TOAST_HOST_ID);
    if (host) return host;

    host = document.createElement('div');
    host.id = TOAST_HOST_ID;
    host.className = 'dtToastHost';
    host.setAttribute('aria-live', 'polite');
    host.setAttribute('aria-atomic', 'true');
    document.body.appendChild(host);
    return host;
  }

  function show(message, type = 'info', opts = {}) {
    const text = (message ?? '').toString().trim();
    if (!text) return null;

    const host = ensureHost();
    const toast = document.createElement('div');
    toast.className = `dtToast dtToast--${type}`;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

    const body = document.createElement('div');
    body.className = 'dtToast__body';
    body.textContent = text;
    toast.appendChild(body);

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'dtToast__close';
    closeBtn.setAttribute('aria-label', 'Dismiss notification');
    closeBtn.textContent = '✕';
    closeBtn.addEventListener('click', () => dismiss());
    toast.appendChild(closeBtn);

    host.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('is-visible'));

    let timeoutId = null;
    function dismiss() {
      if (!toast.isConnected) return;
      toast.classList.remove('is-visible');
      window.setTimeout(() => toast.remove(), 180);
      if (timeoutId) window.clearTimeout(timeoutId);
    }

    const duration = Number(opts.duration || DEFAULT_DURATION);
    if (duration > 0) {
      timeoutId = window.setTimeout(dismiss, duration);
    }

    return { dismiss, element: toast };
  }

  window.DTToast = {
    show,
    success(message, opts = {}) { return show(message, 'success', opts); },
    error(message, opts = {}) { return show(message, 'error', opts); },
    warning(message, opts = {}) { return show(message, 'warning', opts); },
    info(message, opts = {}) { return show(message, 'info', opts); }
  };
})();

(function () {
  if (window.DTButtonLoading) return;

  const ACTIVE_CLASS = 'dtBtnLoading';
  const TEXT_CLASS = 'dtBtnLoadingText';
  const activeTimers = new WeakMap();

  function isButtonLike(el) {
    if (!el) return false;
    if (el instanceof HTMLButtonElement) return true;
    if (el instanceof HTMLInputElement) {
      const t = (el.type || '').toLowerCase();
      return t === 'submit' || t === 'button';
    }
    return false;
  }

  function isExcluded(el) {
    if (!isButtonLike(el)) return true;
    if (el.disabled) return true;
    if (el.hasAttribute('data-no-loading')) return true;
    if (el.closest('[data-no-button-loading]')) return true;
    if (el.classList.contains('themeOrb')) return true;
    if (el.classList.contains('navToggle')) return true;
    if (el.classList.contains('appDrawerClose')) return true;
    if (el.classList.contains('navGroupToggle')) return true;
    if (el.classList.contains('modalClose')) return true;
    return false;
  }

  function shouldAutoLoadOnClick(el) {
    if (!isButtonLike(el)) return false;
    if (el.hasAttribute('data-loading')) return true;
    if (el.hasAttribute('data-loading-text')) return true;
    return false;
  }

  function getLabel(el) {
    if (el instanceof HTMLInputElement) return (el.value || '').trim();
    return (el.textContent || '').trim();
  }

  function ensureTextWrapper(el) {
    if (el instanceof HTMLInputElement) return;
    const first = el.querySelector(':scope > .' + TEXT_CLASS);
    if (first) return;
    const span = document.createElement('span');
    span.className = TEXT_CLASS;
    while (el.firstChild) span.appendChild(el.firstChild);
    el.appendChild(span);
  }

  function start(el, opts = {}) {
    if (isExcluded(el)) return;
    if (el.classList.contains(ACTIVE_CLASS)) return;

    const loadingText = (opts.loadingText || el.getAttribute('data-loading-text') || '').trim();
    el.dataset.wasDisabled = el.disabled ? '1' : '0';
    el.dataset.originalLabel = getLabel(el);
    ensureTextWrapper(el);
    el.classList.add(ACTIVE_CLASS);
    el.setAttribute('aria-busy', 'true');
    el.disabled = true;

    if (loadingText) {
      if (el instanceof HTMLInputElement) {
        el.value = loadingText;
      } else {
        const txt = el.querySelector(':scope > .' + TEXT_CLASS);
        if (txt) txt.textContent = loadingText;
      }
    }

    const timeoutMs = Number(opts.timeoutMs || 15000);
    if (timeoutMs > 0) {
      const timer = window.setTimeout(() => stop(el), timeoutMs);
      activeTimers.set(el, timer);
    }
  }

  function stop(el) {
    if (!isButtonLike(el)) return;
    const timer = activeTimers.get(el);
    if (timer) {
      window.clearTimeout(timer);
      activeTimers.delete(el);
    }

    if (!el.classList.contains(ACTIVE_CLASS)) return;
    const originalLabel = (el.dataset.originalLabel || '').trim();

    el.classList.remove(ACTIVE_CLASS);
    el.removeAttribute('aria-busy');
    if (el.dataset.wasDisabled !== '1') {
      el.disabled = false;
    }

    if (originalLabel) {
      if (el instanceof HTMLInputElement) {
        el.value = originalLabel;
      } else {
        const txt = el.querySelector(':scope > .' + TEXT_CLASS);
        if (txt) txt.textContent = originalLabel;
      }
    }
  }

  function startForSubmitter(evt) {
    const submitter = evt.submitter;
    if (submitter && isButtonLike(submitter) && !isExcluded(submitter)) {
      start(submitter);
    }
  }

  document.addEventListener('submit', startForSubmitter, true);

  document.addEventListener('click', (evt) => {
    const btn = evt.target instanceof Element ? evt.target.closest('button, input[type="submit"], input[type="button"]') : null;
    if (!btn || isExcluded(btn)) return;

    const type = btn instanceof HTMLButtonElement ? (btn.type || 'submit').toLowerCase() : (btn.type || '').toLowerCase();
    if (type === 'submit' || type === 'reset') return;
    if (!shouldAutoLoadOnClick(btn)) return;

    start(btn);
  }, true);

  window.addEventListener('pageshow', () => {
    document.querySelectorAll('.' + ACTIVE_CLASS).forEach((el) => stop(el));
  });

  window.DTButtonLoading = { start, stop };
})();
