<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();
$pageTitle = 'Scan QR - Document Tracker';
require __DIR__ . '/../includes/layout.php';
?>
<div class="scanPage">
  <section class="scanHero">
    <div class="docsEyebrow">Mobile scanning</div>
    <h1 class="scanTitle">Scan a document QR fast</h1>
    <p class="scanLead">Built for phone-first receiving. Open this page from the installed app, point the camera at a QR code, and jump straight to the document status screen.</p>
  </section>

  <section class="scanShell">
    <div class="scanActions" style="margin-bottom:12px;">
      <button type="button" class="btnComp" id="scanStartBtn">Start camera</button>
      <button type="button" class="btnSecondary" id="scanStopBtn" disabled>Stop camera</button>
      <label class="btnSecondary" style="cursor:pointer; text-decoration:none;">
        Scan from image
        <input type="file" id="scanImageInput" accept="image/*" hidden>
      </label>
    </div>

    <div class="scanVideoWrap">
      <video id="scanVideo" playsinline muted></video>
      <div class="scanFrame"></div>
    </div>

    <p class="scanHint" id="scanHint" style="margin:12px 0 0;">Tip: center the QR inside the frame. Latest Android phones work best in Chrome. iPhone users can still use image upload fallback.</p>

    <form id="scanTokenForm" class="scanTokenForm" autocomplete="off">
      <input id="scanTokenInput" class="search" type="text" placeholder="Paste QR link or token here if camera is unavailable">
      <button type="submit" class="btnPrimary">Open</button>
    </form>

    <div class="scanStatus mini" id="scanStatus" style="margin-top:10px;"></div>
  </section>

  <section class="scanResultCard" id="scanResultCard" hidden>
    <div class="docsSectionTitle">Scan result</div>
    <div class="scanResultTracking" id="scanResultTracking">—</div>
    <div class="scanResultMeta" id="scanResultMeta">—</div>
    <div class="scanActions" style="margin-top:14px;">
      <a href="#" id="scanOpenLink" class="btnComp" style="text-decoration:none;">Open document status</a>
      <button type="button" id="scanCopyLinkBtn" class="btnSecondary">Copy link</button>
    </div>
  </section>
</div>

<script>
(function () {
  const APP = window.__APP__ || {};
  const video = document.getElementById('scanVideo');
  const startBtn = document.getElementById('scanStartBtn');
  const stopBtn = document.getElementById('scanStopBtn');
  const imageInput = document.getElementById('scanImageInput');
  const tokenForm = document.getElementById('scanTokenForm');
  const tokenInput = document.getElementById('scanTokenInput');
  const statusEl = document.getElementById('scanStatus');
  const resultCard = document.getElementById('scanResultCard');
  const openLink = document.getElementById('scanOpenLink');
  const resultTracking = document.getElementById('scanResultTracking');
  const resultMeta = document.getElementById('scanResultMeta');
  const copyBtn = document.getElementById('scanCopyLinkBtn');
  let stream = null;
  let detector = null;
  let rafId = 0;
  let found = false;

  if ('BarcodeDetector' in window) {
    try {
      detector = new BarcodeDetector({ formats: ['qr_code'] });
    } catch (e) {
      detector = null;
    }
  }

  function setStatus(text, isError = false) {
    if (!statusEl) return;
    statusEl.textContent = text || '';
    statusEl.style.color = isError ? '#b42318' : '';
  }

  function extractTarget(raw) {
    const value = (raw || '').trim();
    if (!value) return '';
    try {
      const url = new URL(value, window.location.origin);
      if (url.searchParams.get('t')) {
        return APP.public + '/qr.php?t=' + encodeURIComponent(url.searchParams.get('t'));
      }
    } catch (e) {}

    const tokenMatch = value.match(/(?:^|[?&]t=)([A-Za-z0-9]{16,64})$/) || value.match(/^([A-Za-z0-9]{16,64})$/);
    if (tokenMatch && tokenMatch[1]) {
      return APP.public + '/qr.php?t=' + encodeURIComponent(tokenMatch[1]);
    }
    return '';
  }

  function renderResult(target) {
    resultCard.hidden = false;
    openLink.href = target;
    const url = new URL(target, window.location.origin);
    resultTracking.textContent = url.searchParams.get('t') ? 'QR token detected' : 'Document QR found';
    resultMeta.textContent = target;
    setStatus('QR detected. Ready to open.');
  }

  async function stopCamera() {
    found = true;
    if (rafId) cancelAnimationFrame(rafId);
    rafId = 0;
    if (stream) {
      stream.getTracks().forEach((track) => track.stop());
      stream = null;
    }
    if (video) video.srcObject = null;
    startBtn.disabled = false;
    stopBtn.disabled = true;
  }

  async function handleDetected(rawValue) {
    const target = extractTarget(rawValue);
    if (!target) {
      setStatus('QR detected but the value is not a valid document token.', true);
      return;
    }
    await stopCamera();
    renderResult(target);
  }

  async function scanLoop() {
    if (!detector || !video || video.readyState < 2 || found) {
      rafId = requestAnimationFrame(scanLoop);
      return;
    }
    try {
      const barcodes = await detector.detect(video);
      if (barcodes && barcodes.length) {
        found = true;
        await handleDetected(barcodes[0].rawValue || '');
        return;
      }
    } catch (e) {}
    rafId = requestAnimationFrame(scanLoop);
  }

  async function startCamera() {
    resultCard.hidden = true;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setStatus('Camera access is not available in this browser. Use image upload or paste the token.', true);
      return;
    }
    if (!detector) {
      setStatus('Live QR detection is not supported here. Use image upload or paste the token.', true);
      return;
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: {
          facingMode: { ideal: 'environment' },
          width: { ideal: 1280 },
          height: { ideal: 1280 }
        },
        audio: false
      });
      video.srcObject = stream;
      await video.play();
      found = false;
      startBtn.disabled = true;
      stopBtn.disabled = false;
      setStatus('Camera is live. Hold the QR steady inside the frame.');
      scanLoop();
    } catch (err) {
      setStatus('Could not start camera. Check permissions, then try again.', true);
    }
  }

  startBtn.addEventListener('click', startCamera);
  stopBtn.addEventListener('click', stopCamera);

  copyBtn.addEventListener('click', async () => {
    if (!openLink.href) return;
    try {
      await navigator.clipboard.writeText(openLink.href);
      setStatus('Link copied.');
    } catch (e) {
      setStatus('Could not copy the link.', true);
    }
  });

  tokenForm.addEventListener('submit', (event) => {
    event.preventDefault();
    const target = extractTarget(tokenInput.value || '');
    if (!target) {
      setStatus('Paste a valid QR link or token.', true);
      return;
    }
    renderResult(target);
  });

  imageInput.addEventListener('change', async (event) => {
    const file = event.target.files && event.target.files[0];
    if (!file) return;
    if (!detector) {
      setStatus('Image decoding is not supported in this browser. Paste the token instead.', true);
      return;
    }
    try {
      const bitmap = await createImageBitmap(file);
      const barcodes = await detector.detect(bitmap);
      if (!barcodes || !barcodes.length) {
        setStatus('No QR code found in the selected image.', true);
        return;
      }
      await handleDetected(barcodes[0].rawValue || '');
    } catch (e) {
      setStatus('Could not read the selected image.', true);
    }
  });

  window.addEventListener('beforeunload', stopCamera);
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
