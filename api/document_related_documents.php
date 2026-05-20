<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../core/document_split.php';

header('Content-Type: application/json; charset=utf-8');

require_login();

$documentId = (int)($_GET['document_id'] ?? 0);
if ($documentId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid document.']);
  exit;
}

if (!can_view_document_family($conn, $documentId)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Access denied.']);
  exit;
}

$parent = document_split_get_parent_summary($conn, $documentId);
$children = $parent
  ? document_split_get_child_summaries($conn, (int)($parent['parent_document_id'] ?? 0))
  : document_split_get_child_summaries($conn, $documentId);

echo json_encode([
  'ok' => true,
  'parent' => $parent ? [
    'id' => (int)($parent['parent_document_id'] ?? 0),
    'tracking_no' => (string)($parent['parent_tracking_no'] ?? ''),
    'subject' => (string)($parent['parent_subject'] ?? ''),
    'status' => (string)($parent['parent_status'] ?? ''),
  ] : null,
  'children' => array_map(static function (array $row): array {
    return [
      'id' => (int)($row['id'] ?? 0),
      'tracking_no' => (string)($row['tracking_no'] ?? ''),
      'subject' => (string)($row['subject'] ?? ''),
      'status' => (string)($row['current_status'] ?? ''),
      'current_holder_section_name' => (string)($row['current_holder_section_name'] ?? ''),
      'project_codes' => array_values(array_filter(array_map('strval', (array)($row['project_codes'] ?? [])))),
    ];
  }, $children),
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
