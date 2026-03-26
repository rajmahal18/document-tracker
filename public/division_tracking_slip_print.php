<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

$pageTitle = "Print Division Tracking Slip";
$attId = (int)($_GET['id'] ?? 0);
if ($attId <= 0) {
  http_response_code(400);
  echo 'Bad request';
  exit;
}
require __DIR__ . "/../includes/layout.php";
?>
<div class="card" style="max-width: 1100px;">
  <h2 style="margin:0 0 6px;">Division Document Tracking Slip</h2>
  <div class="mini" style="margin-bottom:12px;">The print dialog should open automatically. If it doesn't, click <b>Print</b>.</div>
  <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
    <button type="button" class="btnSecondary" onclick="triggerPrint()">Print</button>
    <a class="btnSecondary" href="<?= htmlspecialchars(PUBLIC_PATH . '/view_attachment.php?id=' . $attId) ?>" target="_blank" rel="noopener">Open PDF</a>
    <a class="btnSecondary" href="<?= htmlspecialchars(PUBLIC_PATH . '/documents.php') ?>">Back</a>
  </div>
  <div style="border:1px solid rgba(0,0,0,.08); border-radius:12px; overflow:hidden;">
    <iframe id="pdfFrame" src="<?= htmlspecialchars(PUBLIC_PATH . '/view_attachment.php?id=' . $attId) ?>" style="width:100%; height:75vh; border:0;" title="Division Tracking Slip PDF"></iframe>
  </div>
</div>
<script>
function triggerPrint() {
  const f = document.getElementById('pdfFrame');
  if (!f) return;
  try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) { window.print(); }
}
window.addEventListener('load', () => setTimeout(triggerPrint, 400));
</script>
<?php require __DIR__ . "/../includes/footer.php"; ?>
