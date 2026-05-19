<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

header("Content-Type: application/json; charset=UTF-8");

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "Method not allowed"]);
  exit;
}

require_csrf();

$role = (string)($_SESSION["role"] ?? "user");
if ($role !== "admin") {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "Only admins can delete attachments."]);
  exit;
}

$attachmentId = (int)($_POST["attachment_id"] ?? 0);
$documentId = (int)($_POST["document_id"] ?? 0);
$actorUserId = (int)($_SESSION["user_id"] ?? 0);
$actorSectionId = (int)($_SESSION["section_id"] ?? 0);

if ($attachmentId <= 0 || $documentId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad attachment_id or document_id"]);
  exit;
}

try {
  if (!can_view_document($conn, $documentId)) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Forbidden"]);
    exit;
  }

  $conn->begin_transaction();

  $stmt = $conn->prepare("
    SELECT id, document_id, original_name, note, is_deleted
    FROM document_attachments
    WHERE id = ? AND document_id = ?
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->bind_param("ii", $attachmentId, $documentId);
  $stmt->execute();
  $attachment = $stmt->get_result()->fetch_assoc();

  if (!$attachment) {
    $conn->rollback();
    http_response_code(404);
    echo json_encode(["ok" => false, "error" => "Attachment not found."]);
    exit;
  }

  if ((int)($attachment["is_deleted"] ?? 0) === 1) {
    $conn->rollback();
    echo json_encode(["ok" => true, "message" => "Attachment already deleted."]);
    exit;
  }

  $stmt = $conn->prepare("
    UPDATE document_attachments
    SET is_deleted = 1
    WHERE id = ? AND document_id = ?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $attachmentId, $documentId);
  $stmt->execute();

  $payload = json_encode([
    "kind" => "attachment_deleted_by_admin",
    "attachment_id" => $attachmentId,
    "file" => (string)($attachment["original_name"] ?? ""),
    "note" => (string)($attachment["note"] ?? ""),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $stmt = $conn->prepare("
    INSERT INTO document_events
      (document_id, event_type, actor_user_id, actor_section_id, payload_json)
    VALUES (?, 'updated', ?, ?, ?)
  ");
  $stmt->bind_param("iiis", $documentId, $actorUserId, $actorSectionId, $payload);
  $stmt->execute();

  $conn->commit();

  echo json_encode([
    "ok" => true,
    "message" => "Attachment deleted. It is now hidden from document views and downloads.",
  ]);
  exit;
} catch (Throwable $e) {
  if ($conn instanceof mysqli) {
    try {
      $conn->rollback();
    } catch (Throwable $rollbackError) {
      // ignore rollback failure
    }
  }
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Failed to delete attachment."]);
  exit;
}
