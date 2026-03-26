<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_once __DIR__ . "/../core/division_tracking.php";
require_once __DIR__ . "/../core/DivisionTrackingSlip.php";
require_login();

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "Method not allowed"]);
  exit;
}

require_csrf();

$docId = (int)($_POST["document_id"] ?? 0);
if ($docId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad document id"]);
  exit;
}

$userId      = (int)($_SESSION["user_id"] ?? 0);
$mySectionId = (int)($_SESSION["section_id"] ?? 0);
if ($mySectionId <= 0 || !can_view_document($conn, $docId)) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "Access denied"]);
  exit;
}

$myDivision = get_user_division_meta($conn, $mySectionId);
if (!is_array($myDivision) || !is_supported_division_tracking_code($myDivision['code'] ?? '')) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "Division tracking slip is not available for your division"]);
  exit;
}
$divisionId = (int)$myDivision['id'];
$divisionCode = (string)$myDivision['code'];
$divisionName = (string)$myDivision['name'];

$stmt = $conn->prepare("SELECT id, tracking_no, document_date, deadline_at, subject, origin_section_id FROM documents WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $docId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
if (!$doc) {
  http_response_code(404);
  echo json_encode(["ok" => false, "error" => "Document not found"]);
  exit;
}

$trackingRow = get_document_division_tracking($conn, $docId, $divisionId);
if (!$trackingRow) {
  $defaultNo = preview_next_division_tracking_number($conn, $divisionId, new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')));
  upsert_document_division_tracking($conn, $docId, $divisionId, $defaultNo, $userId, false);
  $trackingRow = get_document_division_tracking($conn, $docId, $divisionId);
}
if (!$trackingRow) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Unable to resolve division tracking number"]);
  exit;
}

$originSectionId = (int)($doc['origin_section_id'] ?? 0);
$fromLabel = '';
if ($originSectionId > 0) {
  $stmt = $conn->prepare("SELECT s.name AS section_name, d.name AS division_name FROM sections s JOIN divisions d ON d.id = s.division_id WHERE s.id = ? LIMIT 1");
  $stmt->bind_param('i', $originSectionId);
  $stmt->execute();
  $r = $stmt->get_result()->fetch_assoc();
  if ($r) {
    $fromLabel = trim((string)$r['division_name']) . ' / ' . trim((string)$r['section_name']);
  }
}

$receivedBy = trim((string)($_SESSION['full_name'] ?? ''));
$receivedDT = date('m/d/y  g:ia');
$head = resolve_division_head($conn, $divisionId);
$flowRows = []; // movement auto-generation intentionally disabled
$nameEntries = build_division_name_initial_entries($conn, $divisionId, (int)($head['id'] ?? 0));
$safeTracking = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($doc['tracking_no'] ?? 'document')) ?: 'document';
$safeDivision = preg_replace('/[^A-Za-z0-9._-]+/', '_', $divisionCode) ?: 'DIVISION';

$baseDir = realpath(__DIR__ . "/../storage/attachments");
if ($baseDir === false) {
  $baseDir = __DIR__ . "/../storage/attachments";
  if (!is_dir($baseDir)) mkdir($baseDir, 0775, true);
}
$docDir = rtrim((string)$baseDir, "/\\") . "/doc_" . $docId;
if (!is_dir($docDir)) mkdir($docDir, 0775, true);

$storedName = $safeDivision . '_TRACKING_SLIP_' . $safeTracking . '.pdf';
$abs = $docDir . '/' . $storedName;
$rel = 'storage/attachments/doc_' . $docId . '/' . $storedName;

$stmt = $conn->prepare("UPDATE document_attachments SET is_deleted = 1 WHERE document_id = ? AND is_deleted = 0 AND note = ?");
$note = 'AUTO:DIVISION_TRACKING_SLIP:' . $divisionCode;
$stmt->bind_param('is', $docId, $note);
$stmt->execute();

$qrToken = null;
$stmt = $conn->prepare("SELECT token FROM document_qr_tokens WHERE document_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1");
$stmt->bind_param('i', $docId);
$stmt->execute();
$rowTok = $stmt->get_result()->fetch_assoc();
if ($rowTok && !empty($rowTok['token'])) {
  $qrToken = (string)$rowTok['token'];
} else {
  $qrToken = bin2hex(random_bytes(16));
  $stmt = $conn->prepare("INSERT INTO document_qr_tokens (document_id, token) VALUES (?, ?)");
  $stmt->bind_param('is', $docId, $qrToken);
  $stmt->execute();
}
$qrUrl = app_url(PUBLIC_PATH . '/qr.php?t=' . urlencode($qrToken));

DivisionTrackingSlip::generateA4([
  'mpw_tracking_no' => (string)($doc['tracking_no'] ?? ''),
  'division_tracking_no' => (string)$trackingRow['tracking_no'],
  'division_name' => $divisionName,
  'division_code' => $divisionCode,
  'from_label' => $fromLabel,
  'document_date' => (string)($doc['document_date'] ?? ''),
  'received_by' => $receivedBy,
  'received_datetime' => $receivedDT,
  'subject' => (string)($doc['subject'] ?? ''),
  'deadline_date' => trim((string)($doc['deadline_at'] ?? '')) !== '' ? (new DateTime((string)$doc['deadline_at'], new DateTimeZone('Asia/Manila')))->format('m/d/Y') : '',
  'deadline_time' => trim((string)($doc['deadline_at'] ?? '')) !== '' ? (new DateTime((string)$doc['deadline_at'], new DateTimeZone('Asia/Manila')))->format('g:i A') : '',
  'qr_url' => $qrUrl,
  'logo_left_abs' => realpath(__DIR__ . '/../assets/mpwlogo1.png') ?: '',
  'logo_right_abs' => realpath(__DIR__ . '/../assets/ocmlogo.png') ?: '',
  'signatory_name' => (string)($head['full_name'] ?? ''),
  'signatory_title' => trim((string)($head['official_title'] ?? '')) . (trim((string)($head['official_title'] ?? '')) !== '' ? ', ' : '') . $divisionName,
  'flow_rows' => $flowRows,
  'name_entries' => $nameEntries,
], $abs);

$size = (int)@filesize($abs);
if ($size <= 0) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Failed to generate PDF"]);
  exit;
}

$mime = 'application/pdf';
$orig = $storedName;
$stmt = $conn->prepare("INSERT INTO document_attachments
  (document_id, original_name, stored_name, stored_path, mime, size_bytes, note, is_append, uploaded_by_user_id, uploaded_by_section_id)
  VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
$stmt->bind_param('issssisii', $docId, $orig, $storedName, $rel, $mime, $size, $note, $userId, $mySectionId);
$stmt->execute();
$attId = (int)$conn->insert_id;

$payload = json_encode([
  'kind' => 'division_tracking_slip_generated',
  'attachment_id' => $attId,
  'file' => $orig,
  'division_code' => $divisionCode,
], JSON_UNESCAPED_UNICODE);
$stmt = $conn->prepare("INSERT INTO document_events (document_id, event_type, actor_user_id, actor_section_id, payload_json)
  VALUES (?, 'updated', ?, ?, ?)");
$stmt->bind_param('iiis', $docId, $userId, $mySectionId, $payload);
$stmt->execute();

echo json_encode(['ok' => true, 'attachment_id' => $attId, 'stored_name' => $storedName, 'division_code' => $divisionCode]);
