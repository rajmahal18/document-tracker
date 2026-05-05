<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../core/division_tracking.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

$identity = effective_document_identity($conn);
$sectionId = (int)($identity['effective_section_id'] ?? ($_SESSION['section_id'] ?? 0));
$divisionMeta = get_user_division_meta($conn, $sectionId);
$divisionId = (int)($divisionMeta['id'] ?? 0);
$trackingNo = strtoupper(trim((string)($_GET['tracking_no'] ?? '')));
$excludeDocumentId = (int)($_GET['exclude_document_id'] ?? 0);

if ($divisionId <= 0 || !is_supported_division_tracking_code($divisionMeta['code'] ?? '')) {
  echo json_encode(['ok' => true, 'exists' => false]);
  exit;
}

if ($trackingNo === '') {
  echo json_encode(['ok' => true, 'exists' => false]);
  exit;
}

$match = find_document_by_division_tracking_no($conn, $divisionId, $trackingNo, $excludeDocumentId);
if (!$match) {
  echo json_encode(['ok' => true, 'exists' => false]);
  exit;
}

$subject = trim((string)($match['subject'] ?? ''));
$words = preg_split('/\s+/', $subject) ?: [];
$words = array_values(array_filter($words, static fn($word): bool => trim((string)$word) !== ''));
$subjectShort = implode(' ', array_slice($words, 0, 3));
if (count($words) > 3) {
  $subjectShort .= '...';
}
if ($subjectShort === '') {
  $subjectShort = 'No subject';
}

echo json_encode([
  'ok' => true,
  'exists' => true,
  'document_id' => (int)($match['document_id'] ?? 0),
  'document_tracking_no' => (string)($match['document_tracking_no'] ?? ''),
  'subject_short' => $subjectShort,
]);
