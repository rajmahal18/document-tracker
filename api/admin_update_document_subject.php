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

$identity = effective_document_identity($conn);
$assistantMode = (bool)($identity["assistant_mode"] ?? false);
if (!is_admin_user() || $assistantMode) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "Only admins can edit document subjects."]);
  exit;
}

$documentId = (int)($_POST["document_id"] ?? 0);
$subject = trim((string)($_POST["subject"] ?? ""));
$subject = preg_replace('/\s+/', ' ', $subject) ?? $subject;

if ($documentId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad document_id"]);
  exit;
}

if ($subject === "") {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => "Subject is required."]);
  exit;
}

if (mb_strlen($subject) > 255) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => "Subject must be 255 characters or fewer."]);
  exit;
}

$actorUserId = (int)($_SESSION["user_id"] ?? 0);
$actorSectionId = (int)($_SESSION["section_id"] ?? 0);

try {
  $conn->begin_transaction();

  $stmt = $conn->prepare("
    SELECT id, subject
    FROM documents
    WHERE id = ?
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->bind_param("i", $documentId);
  $stmt->execute();
  $document = $stmt->get_result()->fetch_assoc();

  if (!$document) {
    $conn->rollback();
    http_response_code(404);
    echo json_encode(["ok" => false, "error" => "Document not found."]);
    exit;
  }

  $oldSubject = trim((string)($document["subject"] ?? ""));
  if ($oldSubject === $subject) {
    $conn->commit();
    echo json_encode([
      "ok" => true,
      "message" => "No subject changes to save.",
      "document_id" => $documentId,
      "subject" => $subject,
    ]);
    exit;
  }

  $stmt = $conn->prepare("
    UPDATE documents
    SET subject = ?,
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param("si", $subject, $documentId);
  $stmt->execute();

  $payload = json_encode([
    "kind" => "document_subject_updated",
    "previous_subject" => $oldSubject,
    "subject" => $subject,
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
    "message" => "Subject updated.",
    "document_id" => $documentId,
    "subject" => $subject,
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
  echo json_encode(["ok" => false, "error" => "Failed to update subject."]);
  exit;
}
