<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

$pageTitle = "Add Document";
$error = "";

// ✅ Anyone can add docs now (no records role). Must be logged in only.
$role = $_SESSION["role"] ?? "division";

// ✅ Must have a section_id for routing
$fromSectionId = (int)($_SESSION["section_id"] ?? 0);

// ✅ Load sections for dropdown (division-aware)
$sections = $conn->query("
  SELECT s.id, s.name, d.name AS division_name
  FROM sections s
  JOIN divisions d ON d.id = s.division_id
  WHERE s.is_active = 1 AND d.is_active = 1
  ORDER BY d.name ASC, s.name ASC
")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER["REQUEST_METHOD"] === "POST" && $error === "") {
  $tracking_no   = "TRK-" . time(); // simple unique (ok for now)
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

        // 1) documents: origin + current holder = from section
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

        // 6) optional: attach initial file (PDF/JPG/PNG ONLY)
        if (
          isset($_FILES["attach_file"]) &&
          is_array($_FILES["attach_file"]) &&
          ($_FILES["attach_file"]["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        ) {
          $f = $_FILES["attach_file"];

          $maxBytes = 10 * 1024 * 1024; // 10MB
          $allowedExt = ["pdf", "jpg", "jpeg", "png"];

          $orig = basename((string)($f["name"] ?? "file"));
          $orig = preg_replace('/[^a-zA-Z0-9._\-\s]/', "_", $orig) ?? $orig;

          $size = (int)($f["size"] ?? 0);
          if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException("Attachment too large (max 10MB)");
          }

          $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
          if ($ext === "" || !in_array($ext, $allowedExt, true)) {
            throw new RuntimeException("Unsupported attachment type (PDF/JPG/PNG only)");
          }

          $tmp = (string)($f["tmp_name"] ?? "");
          if ($tmp === "" || !is_uploaded_file($tmp)) {
            throw new RuntimeException("Invalid upload");
          }

          // ✅ Real MIME check (server-side, reliable)
          $finfo = new finfo(FILEINFO_MIME_TYPE);
          $realMime = (string)$finfo->file($tmp);

          $allowedRealMime = ["application/pdf", "image/jpeg", "image/png"];
          if (!in_array($realMime, $allowedRealMime, true)) {
            throw new RuntimeException("Unsupported attachment type (PDF/JPG/PNG only)");
          }

          $baseDir = realpath(__DIR__ . "/../storage/attachments");
          if ($baseDir === false) {
            $baseDir = __DIR__ . "/../storage/attachments";
            if (!is_dir($baseDir)) {
              mkdir($baseDir, 0775, true);
            }
          }

          $docDir = rtrim((string)$baseDir, "/\\") . "/doc_" . $docId;
          if (!is_dir($docDir)) {
            mkdir($docDir, 0775, true);
          }

          $stamp = date("Ymd_His");
          $rand = bin2hex(random_bytes(6));
          $storedName = $stamp . "_u" . $userId . "_" . $rand . "." . $ext;
          $abs = $docDir . "/" . $storedName;

          if (!move_uploaded_file($tmp, $abs)) {
            throw new RuntimeException("Failed to store attachment");
          }

          $rel = "storage/attachments/doc_" . $docId . "/" . $storedName;
          $note = trim((string)($_POST["attach_note"] ?? ""));

          // Use real mime for DB
          $mime = $realMime;

          $stmt = $conn->prepare("
            INSERT INTO document_attachments
              (document_id, original_name, stored_name, stored_path, mime, size_bytes, note, is_append, uploaded_by_user_id, uploaded_by_section_id)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
          ");
          $stmt->bind_param(
            "issssisii",
            $docId, $orig, $storedName, $rel, $mime, $size, $note, $userId, $fromSectionId
          );
          $stmt->execute();
          $attachId = (int)$conn->insert_id;

          // audit
          $payloadAttach = json_encode([
            "kind" => "attachment_added",
            "attachment_id" => $attachId,
            "file" => $orig,
            "is_append" => 0,
            "note" => $note,
          ], JSON_UNESCAPED_UNICODE);

          $stmt = $conn->prepare("
            INSERT INTO document_events
              (document_id, event_type, actor_user_id, actor_section_id, payload_json)
            VALUES
              (?, 'updated', ?, ?, ?)
          ");
          $stmt->bind_param("iiis", $docId, $userId, $fromSectionId, $payloadAttach);
          $stmt->execute();
        }

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
  <form method="POST" enctype="multipart/form-data">
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
          <?= htmlspecialchars($s["division_name"] . " — " . $s["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label style="margin-top:14px;">Attach File (optional)</label>
    <input type="file" name="attach_file" accept=".pdf,.jpg,.jpeg,.png">
    <input type="text" name="attach_note" placeholder="Note (optional)" value="<?= htmlspecialchars($_POST["attach_note"] ?? "") ?>">

    <div style="margin-top:16px;">
      <button type="submit" class="btnPrimary">Save Document</button>
      <a href="<?= PUBLIC_PATH ?>/documents.php" class="btnGhost" style="text-decoration:none;">Cancel</a>
    </div>
  </form>
</div>

<?php require __DIR__ . "/../includes/footer.php"; ?>
