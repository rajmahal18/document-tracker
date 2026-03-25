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

<div class="qrPageZoho">
  <section class="qrDocCard qrDocCardCompact">
    <div class="qrHeroTop">
      <div>
        <div class="docsEyebrow">Document status</div>
        <h2 style="margin:4px 0 6px;">Track document</h2>
        <div class="qrLead">Minimal public view. Login is required to receive the document.</div>
      </div>
      <span class="qrStatusPill"><?= htmlspecialchars((string)$doc["current_status"]) ?></span>
    </div>

    <div class="qrDocGrid" style="margin-top:12px;">
      <div class="qrDocRow">
        <div class="qrDocLabel">Tracking No</div>
        <div class="qrDocValue"><strong><?= htmlspecialchars((string)$doc["tracking_no"]) ?></strong></div>
      </div>
      <div class="qrDocRow">
        <div class="qrDocLabel">Document Date</div>
        <div class="qrDocValue"><?= htmlspecialchars((string)$doc["document_date"]) ?></div>
      </div>
      <div class="qrDocRow">
        <div class="qrDocLabel">Subject</div>
        <div class="qrDocValue"><?= htmlspecialchars((string)$doc["subject"]) ?></div>
      </div>
      <div class="qrDocRow">
        <div class="qrDocLabel">Current Holder</div>
        <div class="qrDocValue"><?= htmlspecialchars((string)($doc["holder_name"] ?? "N/A")) ?></div>
      </div>
      <div class="qrDocRow">
        <div class="qrDocLabel">In Transit</div>
        <div class="qrDocValue">
          <?php if ($route): ?>
            Yes — pending recipient: <strong><?= htmlspecialchars((string)($route["to_name"] ?? "Unknown")) ?></strong>
            <div class="qrMuted" style="margin-top:4px;">Sent at: <?= htmlspecialchars((string)($route["sent_at"] ?? "")) ?></div>
          <?php else: ?>
            No open route
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <?php if (!$isLoggedIn): ?>
    <section class="qrInfoCard">
      <div class="docsSectionTitle">Login required</div>
      <p class="qrLead" style="margin:6px 0 0;">You are not logged in. To receive this document, login using your section account, then scan again.</p>
      <div class="qrActionRow">
        <a class="btnPrimary" href="<?= PUBLIC_PATH ?>/login.php" style="text-decoration:none;">Login</a>
      </div>
    </section>

  <?php elseif (!$route): ?>
    <section class="qrInfoCard">
      <div class="docsSectionTitle">No pending route</div>
      <p class="qrLead" style="margin:6px 0 0;">This document has no active route to receive right now.</p>
    </section>

  <?php elseif ($mySectionId <= 0): ?>
    <section class="qrInfoCard">
      <div class="docsSectionTitle">Section missing</div>
      <p class="qrLead" style="margin:6px 0 0;">Your account has no section assignment, so receiving is blocked.</p>
    </section>

  <?php elseif (!$canReceive): ?>
    <section class="qrInfoCard">
      <div class="docsSectionTitle">Not eligible to receive</div>
      <p class="qrLead" style="margin:6px 0 0;">Pending recipient is <strong><?= htmlspecialchars((string)($route["to_name"] ?? "Unknown")) ?></strong>.</p>
    </section>

  <?php else: ?>
    <section class="qrReceiveCard">
      <div class="docsSectionTitle">Receive document</div>
      <p class="qrLead" style="margin:6px 0 12px;">Add an optional remark, then mark the document as received.</p>

      <div class="authField" style="margin-bottom:10px;">
        <label>Remarks (optional)</label>
        <input id="remarks" type="text" placeholder="e.g., Received by records staff">
      </div>

      <div class="qrActionRow">
        <button class="btnPrimary" type="button" onclick="receiveNow()">Mark as Received</button>
      </div>
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
    </section>
  <?php endif; ?>
</div>

<?php require __DIR__ . "/../includes/footer.php"; ?>
