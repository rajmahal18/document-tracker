<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

require_admin();
require_csrf();

$documentId = (int)($_POST['document_id'] ?? 0);
if ($documentId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid document ID.']);
  exit;
}

$stmt = $conn->prepare('SELECT id FROM documents WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $documentId);
$stmt->execute();
$document = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$document) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Document not found.']);
  exit;
}

$attachmentPaths = [];
$attStmt = $conn->prepare('SELECT stored_path FROM document_attachments WHERE document_id = ?');
$attStmt->bind_param('i', $documentId);
$attStmt->execute();
$attResult = $attStmt->get_result();
while ($row = $attResult->fetch_assoc()) {
  $rel = trim((string)($row['stored_path'] ?? ''));
  if ($rel !== '') {
    $attachmentPaths[] = $rel;
  }
}
$attStmt->close();

$conn->begin_transaction();
try {
  delete_by_document_id($conn, 'document_attachments', $documentId);
  delete_by_document_id($conn, 'document_events', $documentId);
  delete_by_document_id($conn, 'document_participants', $documentId);
  delete_by_document_id($conn, 'document_user_visibility', $documentId);
  delete_by_document_id($conn, 'document_qr_tokens', $documentId);
  delete_by_document_id($conn, 'routes', $documentId);
  if (db_column_exists($conn, 'document_division_tracking', 'document_id')) {
    delete_by_document_id($conn, 'document_division_tracking', $documentId);
  }
  delete_by_document_id($conn, 'document_branches', $documentId);

  $docStmt = $conn->prepare('DELETE FROM documents WHERE id = ? LIMIT 1');
  $docStmt->bind_param('i', $documentId);
  if (!$docStmt->execute()) {
    throw new RuntimeException('Failed to delete document record.');
  }
  $docStmt->close();

  $conn->commit();
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Delete failed: ' . $e->getMessage()]);
  exit;
}

foreach ($attachmentPaths as $relativePath) {
  delete_attachment_file_if_exists($relativePath);
}
remove_document_attachment_directory_if_empty($documentId);

echo json_encode([
  'ok' => true,
  'message' => 'Document deleted successfully.',
]);

function delete_by_document_id(mysqli $conn, string $table, int $documentId): void {
  if (!db_column_exists($conn, $table, 'document_id')) {
    return;
  }
  $sql = 'DELETE FROM `' . $table . '` WHERE document_id = ?';
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    throw new RuntimeException('Failed to prepare delete for ' . $table);
  }
  $stmt->bind_param('i', $documentId);
  if (!$stmt->execute()) {
    throw new RuntimeException('Failed deleting from ' . $table);
  }
  $stmt->close();
}

function delete_attachment_file_if_exists(string $relativePath): void {
  $root = realpath(__DIR__ . '/..');
  if ($root === false) return;
  $target = realpath($root . '/' . ltrim($relativePath, '/'));
  $allowedBase = attachments_base_dir();
  $allowedBaseReal = realpath($allowedBase) ?: $allowedBase;
  if ($target === false) return;
  if (strpos($target, $allowedBaseReal) !== 0) return;
  if (is_file($target)) {
    @unlink($target);
  }
}

function remove_document_attachment_directory_if_empty(int $documentId): void {
  $dir = rtrim(attachments_base_dir(), '/\\') . '/doc_' . $documentId;
  if (!is_dir($dir)) return;
  $items = @scandir($dir);
  if ($items === false) return;
  $remaining = array_values(array_diff($items, ['.', '..']));
  if ($remaining === []) {
    @rmdir($dir);
  }
}
