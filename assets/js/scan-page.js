(function () {
  const APP = window.__APP__ || {};
  const tokenForm = document.getElementById('scanTokenForm');
  const tokenInput = document.getElementById('scanTokenInput');
  const statusEl = document.getElementById('scanStatus');
  const resultCard = document.getElementById('scanResultCard');
  const openLink = document.getElementById('scanOpenLink');
  const resultTracking = document.getElementById('scanResultTracking');
  const resultMeta = document.getElementById('scanResultMeta');
  const copyBtn = document.getElementById('scanCopyLinkBtn');
  const capabilityBadge = document.getElementById('scanCapabilityBadge');
  const debugList = document.getElementById('scanDebugList');
  const tabLinks = Array.from(document.querySelectorAll('.scanTabLink'));

  if (!tokenForm || !tokenInput || !statusEl || !resultCard || !openLink || !resultTracking || !resultMeta || !copyBtn || !capabilityBadge || !debugList) {
    return;
  }

  function setCapabilityLabel(text, tone) {
    capabilityBadge.textContent = text;
    capabilityBadge.dataset.tone = tone || 'neutral';
  }

  function setStatus(text, isError) {
    statusEl.textContent = text || '';
    statusEl.dataset.state = isError ? 'error' : 'default';
  }

  function pushDebug(text, tone) {
    const item = document.createElement('div');
    item.className = 'scanDebugItem';
    if (tone) item.dataset.tone = tone;
    item.textContent = text;
    debugList.appendChild(item);
  }

  function resetDebug() {
    debugList.innerHTML = '';
  }

  function extractTarget(raw) {
    const value = (raw || '').trim();
    if (!value) return '';

    try {
      const url = new URL(value, window.location.origin);
      const token = url.searchParams.get('t');
      if (token) {
        return APP.public + '/qr.php?t=' + encodeURIComponent(token);
      }
    } catch (error) {}

    const tokenMatch = value.match(/(?:^|[?&]t=)([A-Za-z0-9]{16,64})$/) || value.match(/^([A-Za-z0-9]{16,64})$/);
    if (tokenMatch && tokenMatch[1]) {
      return APP.public + '/qr.php?t=' + encodeURIComponent(tokenMatch[1]);
    }

    return '';
  }

  function renderResult(target, sourceLabel) {
    resultCard.hidden = false;
    openLink.href = target;

    const url = new URL(target, window.location.origin);
    resultTracking.textContent = sourceLabel || (url.searchParams.get('t') ? 'QR token ready' : 'Document link ready');
    resultMeta.textContent = target;
    setStatus('Link is ready. You can open the document status page below.', false);
    resultCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function activateTabFromHash() {
    const hash = window.location.hash || '#scan-open';
    tabLinks.forEach(function (link) {
      const active = link.getAttribute('href') === hash;
      link.classList.toggle('isActive', active);
    });
  }

  tabLinks.forEach(function (link) {
    link.addEventListener('click', function () {
      window.setTimeout(activateTabFromHash, 0);
    });
  });

  window.addEventListener('hashchange', activateTabFromHash);

  tokenForm.addEventListener('submit', function (event) {
    event.preventDefault();
    resetDebug();

    const value = tokenInput.value || '';
    pushDebug('Checking pasted value…', 'neutral');

    const target = extractTarget(value);
    if (!target) {
      setStatus('Paste a valid QR link or token.', true);
      pushDebug('Input did not match a supported QR link or token.', 'bad');
      resultCard.hidden = true;
      return;
    }

    pushDebug('Valid QR link/token detected.', 'good');
    renderResult(target, 'Manual open ready');
  });

  copyBtn.addEventListener('click', async function () {
    if (!openLink.href) return;
    try {
      await navigator.clipboard.writeText(openLink.href);
      setStatus('Link copied.', false);
      pushDebug('Open link copied to clipboard.', 'good');
    } catch (error) {
      setStatus('Could not copy the link.', true);
      pushDebug('Clipboard copy failed on this browser.', 'warn');
    }
  });

  setCapabilityLabel('Phone camera app', 'good');
  setStatus('Use your normal phone camera app to scan the QR, or paste the link/token below.', false);
  activateTabFromHash();

  const currentUrl = new URL(window.location.href);
  const initialToken = currentUrl.searchParams.get('t');
  if (initialToken) {
    tokenInput.value = initialToken;
    tokenForm.dispatchEvent(new Event('submit', { cancelable: true }));
  }
})();
