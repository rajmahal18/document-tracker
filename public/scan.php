<?php

declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_login();

$pageTitle = 'Open QR / Token - Document Tracker';
$pageScripts = [asset_url('assets/js/scan-page.js')];
require __DIR__ . '/../includes/layout.php';
?>
<div class="scanPage scanPageZoho">
  <section class="scanHero scanHeroCompact">
    <div class="scanHeroTopline">
      <span class="docsEyebrow">QR access</span>
      <span class="scanHeroPill">Honest mode</span>
    </div>

    <div class="scanHeroBody">
      <div>
        <h1 class="scanTitle">Open document QR</h1>
        <p class="scanLead">For reliability, in-app camera scanning has been removed from this page. Use your regular phone camera app to scan the document QR, then open or paste the link/token here.</p>
      </div>
      <div class="scanMiniStats" aria-label="Quick guidance">
        <div class="scanMiniStat">
          <span class="scanMiniLabel">Recommended</span>
          <strong>Phone camera app</strong>
        </div>
        <div class="scanMiniStat">
          <span class="scanMiniLabel">Fallback</span>
          <strong>Paste link / token</strong>
        </div>
      </div>
    </div>
  </section>

  <nav class="scanTabs" aria-label="Open QR sections">
    <a href="#scan-open" class="scanTabLink">Open</a>
    <a href="#scan-how" class="scanTabLink">How</a>
    <a href="#scan-help" class="scanTabLink">Help</a>
  </nav>

  <section class="scanShell scanShellCompact" id="scan-open">
    <div class="scanSectionHead">
      <div>
        <div class="docsSectionTitle">Use your phone camera app</div>
        <p class="scanSectionSub">This is the official and most reliable way to open document QR codes on mobile.</p>
      </div>
      <div class="scanStatusBadge" id="scanCapabilityBadge" data-tone="good">Recommended</div>
    </div>

    <div class="scanHelpGrid">
      <article class="scanHelpCard">
        <div class="scanHelpTitle">Step 1</div>
        <p>Open your normal phone camera app and point it at the document QR.</p>
      </article>
      <article class="scanHelpCard">
        <div class="scanHelpTitle">Step 2</div>
        <p>Tap the link preview shown by your phone. It should open the document status page directly.</p>
      </article>
      <article class="scanHelpCard">
        <div class="scanHelpTitle">Step 3</div>
        <p>If your phone does not open it automatically, copy the QR link or token and paste it below.</p>
      </article>
    </div>

    <div class="scanStatusPanel">
      <div class="scanStatus mini" id="scanStatus">Paste a QR link or token below to open the document status page.</div>
      <div class="scanTinyNote">No false promises: this page no longer pretends to offer built-in scanning. It focuses on the reliable path only.</div>
    </div>
  </section>

  <section class="scanShell scanShellCompact" id="scan-how">
    <div class="scanSectionHead">
      <div>
        <div class="docsSectionTitle">Paste QR link or token</div>
        <p class="scanSectionSub">Accepts a full QR URL or the token itself.</p>
      </div>
    </div>

    <form id="scanTokenForm" class="scanTokenForm scanTokenFormCompact" autocomplete="off">
      <input id="scanTokenInput" class="search" type="text" placeholder="Paste QR link or token" inputmode="text" autocapitalize="off" spellcheck="false">
      <button type="submit" class="btnComp">Open</button>
    </form>

    <div class="scanDebugList" id="scanDebugList" aria-live="polite"></div>
  </section>

  <section class="scanResultCard scanResultCardCompact" id="scanResultCard" hidden>
    <div class="scanSectionHead">
      <div>
        <div class="docsSectionTitle">Ready to open</div>
        <p class="scanSectionSub">The QR content was converted into a document status link.</p>
      </div>
    </div>

    <div class="scanResultStack">
      <div class="scanResultRow">
        <span class="scanResultLabel">Status</span>
        <div class="scanResultTracking" id="scanResultTracking">—</div>
      </div>
      <div class="scanResultRow">
        <span class="scanResultLabel">Target</span>
        <div class="scanResultMeta" id="scanResultMeta">—</div>
      </div>
    </div>

    <div class="scanActions scanPrimaryActions">
      <a href="#" id="scanOpenLink" class="btnComp scanOpenLink">Open document status</a>
      <button type="button" id="scanCopyLinkBtn" class="btnSecondary">Copy link</button>
    </div>
  </section>

  <section class="scanShell scanShellCompact" id="scan-help">
    <div class="scanSectionHead">
      <div>
        <div class="docsSectionTitle">Troubleshooting</div>
        <p class="scanSectionSub">Simple guidance when the QR does not open immediately.</p>
      </div>
    </div>

    <div class="scanHelpGrid">
      <article class="scanHelpCard">
        <div class="scanHelpTitle">Phone camera shows no link</div>
        <p>Move closer, improve lighting, and keep the QR square fully visible. Printed copies and screenshots usually scan better when the code is sharp and not cropped.</p>
      </article>
      <article class="scanHelpCard">
        <div class="scanHelpTitle">You only have the token</div>
        <p>Paste the token into the field above. The page will build the correct document status link for you.</p>
      </article>
      <article class="scanHelpCard">
        <div class="scanHelpTitle">Need a clear workflow</div>
        <p>Use the phone camera app first. Use this page only to open pasted QR links or tokens. That keeps the experience stable and predictable.</p>
      </article>
    </div>
  </section>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
