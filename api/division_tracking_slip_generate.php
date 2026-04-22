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

$identity = effective_document_identity($conn);
$actualUserId = (int)($identity["actual_user_id"] ?? ($_SESSION["user_id"] ?? 0));
$actualUserFullName = trim((string)($identity["actual_full_name"] ?? ($_SESSION["full_name"] ?? "")));
$userId = (int)($identity["effective_user_id"] ?? ($_SESSION["user_id"] ?? 0));
$mySectionId = (int)($identity["effective_section_id"] ?? ($_SESSION["section_id"] ?? 0));
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
$divisionCode = strtoupper(trim((string)$myDivision['code']));
$divisionName = (string)$myDivision['name'];

$roleNorm = strtolower(trim((string)($identity["effective_role"] ?? ($_SESSION["role"] ?? "user"))));

$stmt = $conn->prepare("
  SELECT
    d.id,
    d.tracking_no,
    d.requester,
    d.document_date,
    d.deadline_at,
    d.subject,
    d.content_type,
    d.origin_section_id,
    d.current_holder_section_id,
    d.created_by_user_id,
    COALESCE(NULLIF(TRIM(u.full_name), ''), '') AS created_by_name,
    EXISTS (
      SELECT 1
      FROM routes r_open
      WHERE r_open.document_id = d.id
        AND r_open.received_at IS NULL
        AND r_open.cancelled_at IS NULL
        AND (
          r_open.to_user_id = ?
          OR r_open.to_section_id = ?
        )
    ) AS has_open_route_for_me,
    EXISTS (
      SELECT 1
      FROM document_branches b_holder
      WHERE b_holder.document_id = d.id
        AND b_holder.branch_status = 'ACTIVE'
        AND (
          b_holder.current_assignee_user_id = ?
          OR b_holder.current_assignee_section_id = ?
        )
    ) AS has_branch_holder_for_me
  FROM documents d
  LEFT JOIN users u ON u.id = d.created_by_user_id
  WHERE d.id = ?
  LIMIT 1
");
$stmt->bind_param('iiiii', $userId, $mySectionId, $userId, $mySectionId, $docId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
if (!$doc) {
  http_response_code(404);
  echo json_encode(["ok" => false, "error" => "Document not found"]);
  exit;
}

$canRegenerate = (
  $roleNorm === 'admin'
  || (int)($doc['created_by_user_id'] ?? 0) === $userId
  || (int)($doc['current_holder_section_id'] ?? 0) === $mySectionId
  || (int)($doc['has_open_route_for_me'] ?? 0) === 1
  || (int)($doc['has_branch_holder_for_me'] ?? 0) === 1
);

if (!$canRegenerate) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "Only the origin, current holder, or admin can regenerate this slip"]);
  exit;
}

function division_slip_original_received_meta(mysqli $conn, int $documentId, string $divisionCode): array
{
  $divisionCode = strtoupper(trim($divisionCode));
  $stmt = $conn->prepare("
    SELECT payload_json
    FROM document_events
    WHERE document_id = ?
      AND event_type = 'updated'
      AND payload_json LIKE '%division_tracking_slip_generated%'
    ORDER BY id ASC
  ");
  $stmt->bind_param('i', $documentId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

  foreach ($rows as $row) {
    $payload = json_decode((string)($row['payload_json'] ?? ''), true);
    if (!is_array($payload)) {
      continue;
    }
    if (trim((string)($payload['kind'] ?? '')) !== 'division_tracking_slip_generated') {
      continue;
    }
    $payloadDivision = strtoupper(trim((string)($payload['division_code'] ?? '')));
    if ($divisionCode !== '' && $payloadDivision !== '' && $payloadDivision !== $divisionCode) {
      continue;
    }

    return [
      'received_by' => trim((string)($payload['received_by_name'] ?? '')),
      'received_datetime' => trim((string)($payload['received_datetime'] ?? '')),
      'assistant_user_id' => (int)($payload['assistant_actual_user_id'] ?? 0),
    ];
  }

  return ['received_by' => '', 'received_datetime' => '', 'assistant_user_id' => 0];
}

function division_slip_assistant_name_for_principal(mysqli $conn, int $principalUserId): string
{
  if ($principalUserId <= 0) {
    return '';
  }

  if (function_exists('assistant_assignments_table_ready') && assistant_assignments_table_ready($conn)) {
    $stmt = $conn->prepare("
      SELECT COALESCE(NULLIF(TRIM(u.full_name), ''), '') AS assistant_name
      FROM principal_assistants pa
      JOIN users u ON u.id = pa.assistant_user_id
      WHERE pa.principal_user_id = ?
        AND u.is_active = 1
      ORDER BY u.full_name ASC, u.id ASC
      LIMIT 1
    ");
    $stmt->bind_param('i', $principalUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $name = trim((string)($row['assistant_name'] ?? ''));
    if ($name !== '') {
      return $name;
    }
  }

  if (db_column_exists($conn, 'users', 'chief_assistant_user_id')) {
    $stmt = $conn->prepare("
      SELECT COALESCE(NULLIF(TRIM(a.full_name), ''), '') AS assistant_name
      FROM users p
      JOIN users a ON a.id = p.chief_assistant_user_id
      WHERE p.id = ?
        AND a.is_active = 1
      LIMIT 1
    ");
    $stmt->bind_param('i', $principalUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return trim((string)($row['assistant_name'] ?? ''));
  }

  return '';
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

$requester = trim((string)($doc['requester'] ?? ''));
$fromLabel = '';

if ($requester !== '') {
  $fromLabel = $requester;
} elseif ($originSectionId > 0) {
  $stmt = $conn->prepare("
    SELECT
      COALESCE(NULLIF(TRIM(s.name), ''), '') AS section_name,
      COALESCE(NULLIF(TRIM(d.name), ''), '') AS division_name
    FROM sections s
    JOIN divisions d ON d.id = s.division_id
    WHERE s.id = ?
    LIMIT 1
  ");
  $stmt->bind_param('i', $originSectionId);
  $stmt->execute();
  $r = $stmt->get_result()->fetch_assoc();

  if ($r) {
    $sectionName = trim((string)($r['section_name'] ?? ''));
    $divisionNameFromOrigin = trim((string)($r['division_name'] ?? ''));

    if ($divisionNameFromOrigin !== '' && $sectionName !== '') {
      $fromLabel = $divisionNameFromOrigin . ' / ' . $sectionName;
    } elseif ($divisionNameFromOrigin !== '') {
      $fromLabel = $divisionNameFromOrigin;
    } elseif ($sectionName !== '') {
      $fromLabel = $sectionName;
    }
  }
}

$originalSlipMeta = division_slip_original_received_meta($conn, $docId, $divisionCode);
$receivedBy = trim((string)($originalSlipMeta['received_by'] ?? ''));
$receivedDT = trim((string)($originalSlipMeta['received_datetime'] ?? ''));

if ($receivedBy === '') {
  $createdByUserId = (int)($doc['created_by_user_id'] ?? 0);
  $receivedBy = division_slip_assistant_name_for_principal($conn, $createdByUserId);
}

if ($receivedBy === '') {
  $receivedBy = trim((string)($doc['created_by_name'] ?? ''));
}

if ($receivedBy === '') {
  $receivedBy = $actualUserFullName;
}

$head = resolve_division_head($conn, $divisionId);
$flowRows = build_division_slip_flow_rows($conn, $docId, $divisionId, $receivedBy);
$nameEntries = build_division_name_initial_entries($conn, $divisionId, (int)($head['id'] ?? 0));
$assignedTo = build_division_slip_assigned_to_label($conn, $docId);
$safeTracking = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($doc['tracking_no'] ?? 'document')) ?: 'document';
$safeDivision = preg_replace('/[^A-Za-z0-9._-]+/', '_', $divisionCode) ?: 'DIVISION';

$baseDir = realpath(__DIR__ . "/../storage/attachments");
if ($baseDir === false) {
  $baseDir = __DIR__ . "/../storage/attachments";
  if (!is_dir($baseDir)) mkdir($baseDir, 0775, true);
}
$docDir = rtrim((string)$baseDir, "/\\") . "/doc_" . $docId;
if (!is_dir($docDir)) mkdir($docDir, 0775, true);

$storedName = $safeDivision . '_TRACKING_SLIP_' . $safeTracking . '_' . date('Ymd_His') . '.pdf';
$abs = $docDir . '/' . $storedName;
$rel = 'storage/attachments/doc_' . $docId . '/' . $storedName;

$note = 'AUTO:DIVISION_TRACKING_SLIP:' . $divisionCode;
$supersededNote = $note . ':SUPERSEDED';
$stmt = $conn->prepare("
  UPDATE document_attachments
  SET note = ?, is_append = 0
  WHERE document_id = ?
    AND is_deleted = 0
    AND (
      note = ?
      OR (? = 'PPD' AND note = 'AUTO:PPD_TRACKING_SLIP')
    )
");
$stmt->bind_param('siss', $supersededNote, $docId, $note, $divisionCode);
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
  'document_type' => (string)($doc['content_type'] ?? ''),
  'document_date' => (string)($doc['document_date'] ?? ''),
  'received_by' => $receivedBy,
  'received_datetime' => $receivedDT,
  'assigned_to' => $assignedTo,
  'subject' => (string)($doc['subject'] ?? ''),
  'deadline_date' => trim((string)($doc['deadline_at'] ?? '')) !== '' ? (new DateTime((string)$doc['deadline_at'], new DateTimeZone('Asia/Manila')))->format('m/d/Y') : '',
  'deadline_time' => trim((string)($doc['deadline_at'] ?? '')) !== '' ? (new DateTime((string)$doc['deadline_at'], new DateTimeZone('Asia/Manila')))->format('g:i A') : '',
  'qr_url' => $qrUrl,
  'logo_left_abs' => realpath(__DIR__ . '/../assets/mpwlogo1.png') ?: '',
  'logo_right_abs' => realpath(__DIR__ . '/../assets/ocmlogo.png') ?: '',
  'signatory_name' => (string)($head['full_name'] ?? ''),
  'signatory_title' => 'Chief' . ($divisionName !== '' ? ', ' . $divisionName : ''),
  'flow_rows' => $flowRows,
  'name_entries' => $nameEntries,
], $abs);

$size = (int)@filesize($abs);
if ($size <= 0) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Failed to generate PDF"]);
  exit;
}
if ($size > attachment_max_bytes()) {
  @unlink($abs);
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Generated PDF is too large (max " . attachment_max_mb_label() . ")"]);
  exit;
}

$mime = 'application/pdf';
$orig = $storedName;
$stmt = $conn->prepare("INSERT INTO document_attachments
  (document_id, original_name, stored_name, stored_path, mime, size_bytes, note, is_append, uploaded_by_user_id, uploaded_by_section_id)
  VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
$stmt->bind_param('issssisii', $docId, $orig, $storedName, $rel, $mime, $size, $note, $actualUserId, $mySectionId);
$stmt->execute();
$attId = (int)$conn->insert_id;

$payload = json_encode([
  'kind' => 'division_tracking_slip_generated',
  'attachment_id' => $attId,
  'file' => $orig,
  'division_code' => $divisionCode,
  'received_by_name' => $receivedBy,
  'received_datetime' => $receivedDT,
  'assistant_actual_user_id' => (int)($originalSlipMeta['assistant_user_id'] ?? 0) ?: null,
  'acting_principal_user_id' => ($userId > 0 && $userId !== $actualUserId) ? $userId : null,
  'acting_principal_name' => ($userId > 0 && $userId !== $actualUserId) ? (string)($identity['acting_principal_name'] ?? '') : '',
  'acting_label' => ($userId > 0 && $userId !== $actualUserId) ? (string)($identity['acting_label'] ?? '') : '',
], JSON_UNESCAPED_UNICODE);
$stmt = $conn->prepare("INSERT INTO document_events (document_id, event_type, actor_user_id, actor_section_id, payload_json)
  VALUES (?, 'updated', ?, ?, ?)");
$stmt->bind_param('iiis', $docId, $actualUserId, $mySectionId, $payload);
$stmt->execute();

echo json_encode(['ok' => true, 'attachment_id' => $attId, 'stored_name' => $storedName, 'division_code' => $divisionCode]);
