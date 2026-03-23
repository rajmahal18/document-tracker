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
