<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

$pageTitle = "Add Document";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $tracking_no = "TRK-" . time(); // simple unique
  $requester = trim($_POST["requester"] ?? "");
  $document_date = trim($_POST["document_date"] ?? "");
  $subject = trim($_POST["subject"] ?? "");
  $content_type = trim($_POST["content_type"] ?? "");
  $comm_type = trim($_POST["comm_type"] ?? "internal");

  if ($requester === "" || $document_date === "" || $subject === "" || $content_type === "") {
    $error = "Please fill in all required fields.";
  } else {
    // Insert into documents
    $stmt = $conn->prepare("
      INSERT INTO documents
      (tracking_no, requester, document_date, subject, content_type, comm_type, current_status, status_updated_at)
      VALUES (?, ?, ?, ?, ?, ?, 'incoming', NOW())
    ");
    $stmt->bind_param("ssssss", $tracking_no, $requester, $document_date, $subject, $content_type, $comm_type);
    $stmt->execute();

    $docId = $conn->insert_id; // ✅ mysqli insert id

    // Insert history = RECEIVED
    $stmt = $conn->prepare("
      INSERT INTO doc_history (document_id, action, remarks, acted_by)
      VALUES (?, 'received', 'Document received at Records', ?)
    ");
    $userId = (int)($_SESSION["user_id"] ?? 0);
    $stmt->bind_param("ii", $docId, $userId);
    $stmt->execute();

    redirect(PUBLIC_PATH . "/documents.php"); // ✅ cleaned
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
    <input type="text" name="requester" required>

    <label>Document Date *</label>
    <input type="date" name="document_date" required>

    <label>Subject *</label>
    <input type="text" name="subject" required>

    <label>Content Type *</label>
    <input type="text" name="content_type" placeholder="Memorandum, Proposal, Letter..." required>

    <label>Communication Type *</label>
    <select name="comm_type" class="select">
      <option value="internal">Internal</option>
      <option value="external">External</option>
    </select>

    <div style="margin-top:16px;">
      <button type="submit" class="btnPrimary">Save Document</button>
      <a href="<?= PUBLIC_PATH ?>/documents.php" class="btnGhost" style="text-decoration:none;">Cancel</a>
    </div>
  </form>
</div>

<?php require __DIR__ . "/../includes/footer.php"; ?>
