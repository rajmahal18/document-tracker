<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

$pageTitle = "Add Document";
$error = "";

// ✅ Only Records and Admin can add new docs
$role = $_SESSION["role"] ?? "division";
if (!in_array($role, ["admin", "records"], true)) {
  http_response_code(403);
  $error = "Forbidden. Only Records/Admin can add documents.";
}

// ✅ Must have a section_id for routing
$fromSectionId = (int)($_SESSION["section_id"] ?? 0);

// ✅ Load sections for dropdown (exclude your own section if you want)
$sections = $conn->query("
  SELECT id, name
  FROM sections
  ORDER BY name ASC
")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER["REQUEST_METHOD"] === "POST" && $error === "") {
  $tracking_no   = "TRK-" . time(); // simple unique
  $requester     = trim($_POST["requester"] ?? "");
  $document_date = trim($_POST["document_date"] ?? "");
  $subject       = trim($_POST["subject"] ?? "");
  $content_type  = trim($_POST["content_type"] ?? "");
  $comm_type     = trim($_POST["comm_type"] ?? "internal");
  $toSectionId   = (int)($_POST["to_section_id"] ?? 0);

  if ($requester === "" || $document_date === "" || $subject === "" || $content_type === "" || $toSectionId <= 0) {
    $error = "Please fill in all required fields (including Forward To).";
  } elseif ($fromSectionId <= 0) {
    $error = "Your account has no section assigned. Ask admin to set your section_id.";
  } else {
    // NEW SCHEMA INSERT (Model B: in-transit)
    $userId = (int)($_SESSION["user_id"] ?? 0);

    if ($toSectionId === $fromSectionId) {
      $error = "Forward To must be a different section.";
    } else {
      try {
        $conn->begin_transaction();

        // 1) documents: origin + current holder = Records (from section)
        $stmt = $conn->prepare("
          INSERT INTO documents (
            tracking_no, requester, document_date, subject, content_type, comm_type,
            current_status,
            origin_section_id, current_holder_section_id,
            created_by_user_id
          )
          VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE', ?, ?, ?)
        ");

        $stmt->bind_param(
          "ssssssiii",
          $tracking_no, $requester, $document_date, $subject, $content_type, $comm_type,
          $fromSectionId, $fromSectionId, $userId
        );

        $stmt->execute();
        $docId = (int)$conn->insert_id;

        // 2) participants: origin is always a participant
        $stmt = $conn->prepare("
          INSERT IGNORE INTO document_participants
            (document_id, section_id, added_via, added_by_user_id)
          VALUES (?, ?, 'origin', ?)
        ");
        $stmt->bind_param("iii", $docId, $fromSectionId, $userId);
        $stmt->execute();

        // 3) participants: pending recipient can SEE immediately (rule #2)
        $stmt = $conn->prepare("
          INSERT IGNORE INTO document_participants
            (document_id, section_id, added_via, added_by_user_id)
          VALUES (?, ?, 'movement', ?)
        ");
        $stmt->bind_param("iii", $docId, $toSectionId, $userId);
        $stmt->execute();

        // 4) routes: create open route (sent but not received)
        $remarks = "Initial forward on creation";
        $stmt = $conn->prepare("
          INSERT INTO routes
            (document_id, from_section_id, to_section_id, sent_by_user_id, remarks)
          VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iiiis", $docId, $fromSectionId, $toSectionId, $userId, $remarks);
        $stmt->execute();

        // 5) events: created + sent
        $payloadCreated = json_encode([
          "tracking_no" => $tracking_no,
          "subject" => $subject,
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $conn->prepare("
          INSERT INTO document_events
            (document_id, event_type, actor_user_id, actor_section_id, payload_json)
          VALUES (?, 'created', ?, ?, ?)
        ");
        $stmt->bind_param("iiis", $docId, $userId, $fromSectionId, $payloadCreated);
        $stmt->execute();

        $payloadSent = json_encode([
          "remarks" => $remarks
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $conn->prepare("
          INSERT INTO document_events
            (document_id, event_type, actor_user_id, actor_section_id, from_section_id, to_section_id, payload_json)
          VALUES (?, 'sent', ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iiiiis", $docId, $userId, $fromSectionId, $fromSectionId, $toSectionId, $payloadSent);
        $stmt->execute();

        $conn->commit();
        redirect(PUBLIC_PATH . "/documents.php");

      } catch (Throwable $e) {
        $conn->rollback();
        $error = "Failed to add document: " . $e->getMessage();
      }
}
  }
}

require __DIR__ . "/../includes/layout.php";
?>

<h1>Add New Document</h1>

<?php if ($error): ?>
  <div class="notice" style="background:#f8d7da;border:1px solid #f5c2c7;">
    <?= htmlspecialchars($error) ?>
  </div>
<?php endif; ?>

<div class="card" style="max-width:720px;margin-top:14px;">
  <form method="POST">
    <label>Requester *</label>
    <input type="text" name="requester" required value="<?= htmlspecialchars($_POST["requester"] ?? "") ?>">

    <label>Document Date *</label>
    <input type="date" name="document_date" required value="<?= htmlspecialchars($_POST["document_date"] ?? "") ?>">

    <label>Subject *</label>
    <input type="text" name="subject" required value="<?= htmlspecialchars($_POST["subject"] ?? "") ?>">

    <label>Content Type *</label>
    <input type="text" name="content_type" placeholder="Memorandum, Proposal, Letter..." required value="<?= htmlspecialchars($_POST["content_type"] ?? "") ?>">

    <label>Communication Type *</label>
    <select name="comm_type" class="select">
      <option value="internal" <?= (($_POST["comm_type"] ?? "internal") === "internal") ? "selected" : "" ?>>Internal</option>
      <option value="external" <?= (($_POST["comm_type"] ?? "") === "external") ? "selected" : "" ?>>External</option>
    </select>

    <label>Forward To (Initial Section) *</label>
    <select name="to_section_id" class="select" required>
      <option value="">-- Select --</option>
      <?php foreach ($sections as $s): ?>
        <option
          value="<?= (int)$s["id"] ?>"
          <?= ((string)($s["id"]) === (string)($_POST["to_section_id"] ?? "")) ? "selected" : "" ?>
        >
          <?= htmlspecialchars($s["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <div style="margin-top:16px;">
      <button type="submit" class="btnPrimary">Save Document</button>
      <a href="<?= PUBLIC_PATH ?>/documents.php" class="btnGhost" style="text-decoration:none;">Cancel</a>
    </div>
  </form>
</div>

<?php require __DIR__ . "/../includes/footer.php"; ?>
