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
$qrNext = PUBLIC_PATH . "/qr.php?t=" . rawurlencode($token);
$loginError = "";

if (!is_logged_in() && $_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["qr_login"] ?? "") === "1") {
  $login = login_with_credentials($conn, (string)($_POST["username"] ?? ""), (string)($_POST["password"] ?? ""));
  if (empty($login["ok"])) {
    $loginError = (string)($login["error"] ?? "Invalid login credentials.");
  } else {
    if ((int)($login["must_change_password"] ?? 0) === 1) {
      redirect(PUBLIC_PATH . "/change_password.php");
    }
    redirect($qrNext);
  }
}

// 2) Check current open routes (if any) for pending recipients.
$stmt = $conn->prepare("
  SELECT
    r.id,
    r.to_user_id,
    r.from_section_id, fs.name AS from_name,
    r.to_section_id,   ts.name AS to_name,
    tu.full_name AS to_user_name,
    r.sent_at
  FROM routes r
  LEFT JOIN sections fs ON fs.id = r.from_section_id
  LEFT JOIN sections ts ON ts.id = r.to_section_id
  LEFT JOIN users tu ON tu.id = r.to_user_id
  WHERE r.document_id = ?
    AND r.received_at IS NULL
    AND r.cancelled_at IS NULL
  ORDER BY r.id DESC
  LIMIT 25
");
$stmt->bind_param("i", $docId);
$stmt->execute();
$routes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$displayRoute = $routes[0] ?? null;
$route = $displayRoute;

$isLoggedIn = is_logged_in();
$actualUserId = (int)($_SESSION["user_id"] ?? 0);
$mySectionId = (int)($_SESSION["section_id"] ?? 0);
$myIsChief = ((int)($_SESSION["is_chief"] ?? 0) === 1);

$canReceive = false;
$receiveActingPrincipalId = 0;
$receiveAsLabel = "";
$assistantPrincipals = $isLoggedIn ? assistant_fetch_assigned_principals($conn, $actualUserId) : [];
$assistantPrincipalById = [];

foreach ($assistantPrincipals as $principal) {
  $assistantPrincipalById[(int)($principal["id"] ?? 0)] = $principal;
}

if ($isLoggedIn && $routes) {
  foreach ($routes as $candidate) {
    $toSectionId = (int)($candidate["to_section_id"] ?? 0);
    $toUserId = isset($candidate["to_user_id"]) ? (int)$candidate["to_user_id"] : 0;

    if ($toUserId > 0 && $toUserId === $actualUserId) {
      $route = $candidate;
      $canReceive = true;
      $receiveAsLabel = trim((string)($_SESSION["full_name"] ?? "your account"));
      break;
    }

    if ($toUserId > 0 && isset($assistantPrincipalById[$toUserId])) {
      $principal = $assistantPrincipalById[$toUserId];
      $route = $candidate;
      $canReceive = true;
      $receiveActingPrincipalId = $toUserId;
      $receiveAsLabel = trim((string)($principal["full_name"] ?? "assigned principal"));
      break;
    }

    if ($toUserId <= 0 && $mySectionId > 0 && $toSectionId === $mySectionId && $myIsChief) {
      $route = $candidate;
      $canReceive = true;
      $receiveAsLabel = trim((string)($_SESSION["full_name"] ?? "your account"));
      break;
    }

    if ($toUserId <= 0) {
      foreach ($assistantPrincipals as $principal) {
        if ((int)($principal["section_id"] ?? 0) !== $toSectionId) continue;
        $authorityRole = trim((string)($principal["authority_role"] ?? ""));
        $principalIsAuthority = in_array($authorityRole, ["director", "division_head", "section_head"], true)
          || (int)($principal["is_chief"] ?? 0) === 1;
        if (!$principalIsAuthority) continue;

        $route = $candidate;
        $canReceive = true;
        $receiveActingPrincipalId = (int)($principal["id"] ?? 0);
        $receiveAsLabel = trim((string)($principal["full_name"] ?? "assigned principal"));
        break 2;
      }
    }
  }
}

$pendingRecipient = "Unknown";
if ($displayRoute) {
  $pendingRecipient = trim((string)($displayRoute["to_user_name"] ?? ""));
  if ($pendingRecipient === "") {
    $pendingRecipient = (string)($displayRoute["to_name"] ?? "Unknown");
  } else {
    $pendingRecipient .= " - " . (string)($displayRoute["to_name"] ?? "Unknown");
  }
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
          <?php if ($displayRoute): ?>
            Yes - pending recipient: <strong><?= htmlspecialchars($pendingRecipient) ?></strong>
            <div class="qrMuted" style="margin-top:4px;">Sent at: <?= htmlspecialchars((string)($displayRoute["sent_at"] ?? "")) ?></div>
          <?php else: ?>
            No open route
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <?php if (!$isLoggedIn): ?>
    <section class="qrInfoCard">
      <div class="docsSectionTitle">Login to continue</div>
      <p class="qrLead" style="margin:6px 0 12px;">Use your account here, then this same QR page will continue to receiving.</p>

      <?php if ($loginError !== ""): ?>
        <div class="notice qrLoginError">
          <?= htmlspecialchars($loginError) ?>
        </div>
      <?php endif; ?>

      <form class="authForm qrLoginForm" method="POST" action="<?= htmlspecialchars($qrNext, ENT_QUOTES, "UTF-8") ?>" novalidate>
        <input type="hidden" name="qr_login" value="1">

        <div class="authField">
          <label for="qrUsername">Username / Email</label>
          <input
            id="qrUsername"
            type="text"
            name="username"
            placeholder="Enter your username or email"
            value="<?= htmlspecialchars((string)($_POST["username"] ?? "")) ?>"
            autocomplete="username"
            required
          >
        </div>

        <div class="authField">
          <label for="qrPassword">Password</label>
          <input
            id="qrPassword"
            type="password"
            name="password"
            placeholder="Enter your password"
            autocomplete="current-password"
            required
          >
        </div>

        <div class="qrActionRow">
          <button class="btnPrimary" type="submit">Login and continue</button>
          <a class="btnGhost qrFullLoginLink" href="<?= PUBLIC_PATH ?>/login.php?next=<?= rawurlencode($qrNext) ?>">Open full login page</a>
        </div>
      </form>
    </section>

  <?php elseif (!$route): ?>
    <section class="qrInfoCard">
      <div class="docsSectionTitle">No pending route</div>
      <p class="qrLead" style="margin:6px 0 0;">This document has no active route to receive right now.</p>
    </section>

  <?php elseif ($mySectionId <= 0 && $receiveActingPrincipalId <= 0): ?>
    <section class="qrInfoCard">
      <div class="docsSectionTitle">Section missing</div>
      <p class="qrLead" style="margin:6px 0 0;">Your account has no section assignment, so receiving is blocked.</p>
    </section>

  <?php elseif (!$canReceive): ?>
    <section class="qrInfoCard">
      <div class="docsSectionTitle">Not eligible to receive</div>
      <p class="qrLead" style="margin:6px 0 0;">Pending recipient is <strong><?= htmlspecialchars($pendingRecipient) ?></strong>.</p>
    </section>

  <?php else: ?>
    <section class="qrReceiveCard">
      <div class="docsSectionTitle">Receive document</div>
      <p class="qrLead" style="margin:6px 0 12px;">
        <?php if ($receiveAsLabel !== ""): ?>
          Receiving as <strong><?= htmlspecialchars($receiveAsLabel) ?></strong>. Add an optional remark, then mark the document as received.
        <?php else: ?>
          Add an optional remark, then mark the document as received.
        <?php endif; ?>
      </p>

      <div class="authField" style="margin-bottom:10px;">
        <label for="remarks">Remarks (optional)</label>
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
          fd.set("route_id", "<?= (int)($route["id"] ?? 0) ?>");
          fd.set("remarks", document.getElementById("remarks")?.value || "");
          <?php if ($receiveActingPrincipalId > 0): ?>
          fd.set("acting_principal_user_id", "<?= (int)$receiveActingPrincipalId ?>");
          <?php endif; ?>

          const res = await fetch("<?= API_PATH ?>/ack_received.php", { method: "POST", body: fd });
          const data = await res.json().catch(() => null);

          if (!res.ok || !data || !data.ok) {
            msg.textContent = (data && data.error) ? data.error : "Failed.";
            return;
          }

          msg.textContent = "Received. Refreshing...";
          setTimeout(() => location.reload(), 600);
        }
      </script>
    </section>
  <?php endif; ?>
</div>

<?php require __DIR__ . "/../includes/footer.php"; ?>
