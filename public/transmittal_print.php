<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

$pageTitle = "Print Transmittal Memo";

$attId = (int)($_GET['id'] ?? 0);
if ($attId <= 0) {
  http_response_code(400);
  echo "Bad request";
  exit;
}

// We intentionally rely on view_attachment.php for strict access checks.
// Here we only show a print-friendly wrapper.

require __DIR__ . "/../includes/layout.php";
?>

<div class="card" style="max-width: 1100px;">
  <h2 style="margin:0 0 6px;">Transmittal Memo</h2>
  <div class="mini" style="margin-bottom:12px;">
    The print dialog should open automatically. If it doesn't, click <b>Print</b>.
  </div>

  <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
    <button type="button" class="btnSecondary" onclick="triggerPrint()">Print</button>
    <a class="btnGhost" href="<?= PUBLIC_PATH ?>/documents.php" style="text-decoration:none;">Back to Documents</a>
  </div>

  <div style="border:1px solid #e3e6ea; border-radius:12px; overflow:hidden; background:#fff;">
    <iframe
      id="pdfFrame"
      src="<?= PUBLIC_PATH ?>/view_attachment.php?id=<?= (int)$attId ?>"
      style="width:100%; height:78vh; border:0; display:block;"
      title="Transmittal Memo PDF"></iframe>
  </div>
</div>

<script>
  function triggerPrint(){
    const f = document.getElementById('pdfFrame');
    if (!f) return;
    // Works on most browsers when same-origin.
    try {
      f.contentWindow.focus();
      f.contentWindow.print();
    } catch (e) {
      // fallback: open PDF in new tab
      window.open(f.src, '_blank');
    }

    // "Then go" behavior: return to documents after a short delay.
    // Print dialog may block JS; this is best-effort.
  }

  // Auto-trigger once the PDF loads
  document.getElementById('pdfFrame')?.addEventListener('load', () => {
    setTimeout(triggerPrint, 250);
  });
</script>

<?php require __DIR__ . "/../includes/footer.php"; ?>
