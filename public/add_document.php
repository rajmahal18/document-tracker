<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

$pageTitle = "Add Document";
$error = "";

// ✅ Only Records (receiver) and Admin can add new docs
$role = $_SESSION["role"] ?? "viewer";
if (!in_array($role, ["admin", "receiver"], true)) {
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
    // Insert into documents
    $currentSectionId = (int)($_SESSION["section_id"] ?? 1); // or set to Records section id

    $stmt = $conn->prepare("
      INSERT INTO documents (tracking_no, requester, document_date, subject, content_type, comm_type, current_status, current_section_id, status_updated_at)
      VALUES (?, ?, ?, ?, ?, ?, 'incoming', ?, NOW())
    ");

    $stmt->bind_param("ssssssi",
      $tracking_no, $requester, $document_date, $subject, $content_type, $comm_type, $currentSectionId
    );

    $stmt->execute();

    $docId = (int)$conn->insert_id;

    // Insert initial history (created at records)
    $stmt = $conn->prepare("
      INSERT INTO doc_history (document_id, from_section_id, to_section_id, action, remarks, acted_by)
      VALUES (?, ?, ?, 'created', 'Document created at Records', ?)
    ");
    $userId = (int)($_SESSION["user_id"] ?? 0);

    // for "created", keep it anchored to Records (no handoff yet)
    $stmt->bind_param("iiii", $docId, $fromSectionId, $fromSectionId, $userId);
    $stmt->execute();


    redirect(PUBLIC_PATH . "/documents.php");
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
