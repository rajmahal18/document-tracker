<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$pageTitle = 'Offline - MPW Document Tracker';
require __DIR__ . '/../includes/layout.php';
?>
<div class="offlineCard">
  <div class="docsEyebrow">Offline mode</div>
  <h1 class="docsTitle" style="font-size:32px; margin-bottom:10px;">You are offline right now</h1>
  <p class="docsLead" style="max-width:unset; margin-top:0;">
    The app shell is available, but live document data and actions need an internet connection.
    Reconnect, then refresh to continue receiving, forwarding, or opening document status pages.
  </p>
  <div class="scanActions" style="margin-top:16px;">
    <a href="<?= PUBLIC_PATH ?>/documents.php" class="btnComp" style="text-decoration:none;">Go to dashboard</a>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
