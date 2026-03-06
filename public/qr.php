<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";

$pageTitle = "Track Document";

$token = trim((string)($_GET["t"] ?? ""));
if ($token === "" || !preg_match('/^[A-Za-z0-9]{16,64}$/', $token)) {
  http_response_code(400);
  echo "Bad request";
  exit;
}

// 1) Resolve token -> document (minimal public fields)
$stmt = $conn->prepare("
  SELECT
    d.id,
    d.tracking_no,
    d.document_date,
    d.subject,
    d.current_status,
    d.current_holder_section_id,
    s.name AS holder_name
  FROM document_qr_tokens qt
  JOIN documents d ON d.id = qt.document_id
  LEFT JOIN sections s ON s.id = d.current_holder_section_id
  WHERE qt.token = ?
    AND qt.revoked_at IS NULL
  ORDER BY qt.id DESC
  LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if (!$doc) {
  http_response_code(404);
  echo "QR token not found or revoked.";
  exit;
}

$docId = (int)$doc["id"];

// 2) Check current open route (if any) for “pending recipient”
$stmt = $conn->prepare("
  SELECT
    r.id,
    r.from_section_id, fs.name AS from_name,
    r.to_section_id,   ts.name AS to_name,
    r.sent_at
  FROM routes r
  LEFT JOIN sections fs ON fs.id = r.from_section_id
  LEFT JOIN sections ts ON ts.id = r.to_section_id
  WHERE r.document_id = ?
    AND r.received_at IS NULL AND r.cancelled_at IS NULL
  ORDER BY r.id DESC
  LIMIT 1
");
$stmt->bind_param("i", $docId);
$stmt->execute();
$route = $stmt->get_result()->fetch_assoc();

$isLoggedIn  = is_logged_in();
$role        = (string)($_SESSION["role"] ?? "user");
$mySectionId = (int)($_SESSION["section_id"] ?? 0);

$pendingToSectionId = $route ? (int)$route["to_section_id"] : 0;

// Eligibility matches your api/ack_received.php rule:
$canReceive = false;
if ($isLoggedIn && $mySectionId > 0 && $route) {
  $canReceive = ($pendingToSectionId === $mySectionId);
}

require __DIR__ . "/../includes/layout.php";
?>

<div class="card" style="max-width: 860px; margin-top:14px;">
  <h2 style="margin:0 0 6px;">Document Status</h2>
  <div class="mini" style="margin-bottom:12px;">
    Minimal public view. Login is required to receive.
  </div>

  <div style="display:grid; grid-template-columns: 170px 1fr; gap:10px; align-items:start;">
    <div class="mini">Tracking No</div>
    <div><b><?= htmlspecialchars((string)$doc["tracking_no"]) ?></b></div>

    <div class="mini">Document Date</div>
    <div><?= htmlspecialchars((string)$doc["document_date"]) ?></div>

    <div class="mini">Subject</div>
    <div><?= htmlspecialchars((string)$doc["subject"]) ?></div>

    <div class="mini">Status</div>
    <div><?= htmlspecialchars((string)$doc["current_status"]) ?></div>

    <div class="mini">Current Holder</div>
    <div><?= htmlspecialchars((string)($doc["holder_name"] ?? "N/A")) ?></div>

    <div class="mini">In Transit</div>
    <div>
      <?php if ($route): ?>
        Yes — pending recipient: <b><?= htmlspecialchars((string)($route["to_name"] ?? "Unknown")) ?></b>
        <div class="mini" style="margin-top:4px;">Sent at: <?= htmlspecialchars((string)($route["sent_at"] ?? "")) ?></div>
      <?php else: ?>
        No (no open route)
      <?php endif; ?>
    </div>
  </div>

  <hr style="margin:16px 0; border:0; border-top:1px solid #e3e6ea;">

  <?php if (!$isLoggedIn): ?>
    <div class="notice">
      You are not logged in. To receive this document, login using your section account, then scan again.
    </div>
    <a class="btnPrimary" href="<?= PUBLIC_PATH ?>/login.php" style="text-decoration:none;">Login</a>

  <?php elseif (!$route): ?>
    <div class="notice">No pending route to receive.</div>

  <?php elseif ($mySectionId <= 0): ?>
    <div class="notice">Your account has no section assignment. Cannot receive.</div>

  <?php elseif (!$canReceive): ?>
    <div class="notice">
      Not eligible to receive.
      Pending recipient is <b><?= htmlspecialchars((string)($route["to_name"] ?? "Unknown")) ?></b>.
    </div>

  <?php else: ?>
    <h3 style="margin:0 0 10px;">Receive Document</h3>

    <div class="authField" style="margin-bottom:10px;">
      <label>Remarks (optional)</label>
      <input id="remarks" type="text" placeholder="e.g., Received by records staff">
    </div>

    <button class="btnPrimary" type="button" onclick="receiveNow()">Mark as Received</button>
    <div id="msg" class="mini" style="margin-top:10px;"></div>

    <script>
      window.__CSRF__ = "<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>";

      async function receiveNow() {
        const msg = document.getElementById("msg");
        msg.textContent = "Processing...";

        const fd = new FormData();
        fd.set("csrf_token", window.__CSRF__ || "");
        fd.set("document_id", "<?= (int)$docId ?>");
        fd.set("remarks", document.getElementById("remarks")?.value || "");

        const res = await fetch("<?= API_PATH ?>/ack_received.php", { method: "POST", body: fd });
        const data = await res.json().catch(() => null);

        if (!res.ok || !data || !data.ok) {
          msg.textContent = (data && data.error) ? data.error : "Failed.";
          return;
        }

        msg.textContent = "✅ Received. Refreshing...";
        setTimeout(() => location.reload(), 600);
      }
    </script>
  <?php endif; ?>
</div>

<?php require __DIR__ . "/../includes/footer.php"; ?>