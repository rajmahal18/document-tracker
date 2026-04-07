<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();
header("Content-Type: application/json; charset=utf-8");

$docId = (int)($_GET['document_id'] ?? 0);
$branchId = (int)($_GET['branch_id'] ?? 0);

if ($docId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing document id']);
  exit;
}

if (!can_view_document($conn, $docId)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Forbidden']);
  exit;
}

$identity = effective_document_identity($conn);
$effectiveUserId = (int)($identity['effective_user_id'] ?? 0);
if ($effectiveUserId <= 0) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Invalid user']);
  exit;
}

$sql = "
  SELECT
    r.id,
    r.branch_id,
    r.remarks,
    r.sent_at,
    r.sent_by_user_id,
    r.route_kind
  FROM routes r
  WHERE r.document_id = ?
    AND r.received_at IS NULL
    AND r.cancelled_at IS NULL
    AND r.sent_by_user_id = ?
    AND r.route_kind = 'ACTION'
";
$params = [$docId, $effectiveUserId];
$types = 'ii';

if ($branchId > 0) {
  $sql .= " AND COALESCE(r.branch_id, 0) = ?";
  $types .= 'i';
  $params[] = $branchId;
}

$sql .= " ORDER BY r.id DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$route = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$route) {
  echo json_encode([
    'ok' => true,
    'editable' => false,
    'route_id' => 0,
    'remarks' => '',
    'has_remark' => false,
    'button_label' => 'Add pending remarks',
    'helper_text' => 'You can edit remarks only while your pending route is still waiting to be received.',
    'branch_id' => $branchId,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$remarks = trim((string)($route['remarks'] ?? ''));
if (strcasecmp($remarks, 'none') === 0) {
  $remarks = '';
}

$hasRemark = ($remarks !== '');

echo json_encode([
  'ok' => true,
  'editable' => true,
  'route_id' => (int)($route['id'] ?? 0),
  'remarks' => $remarks,
  'has_remark' => $hasRemark,
  'button_label' => $hasRemark ? 'Edit pending remarks' : 'Add pending remarks',
  'helper_text' => 'This stays editable until the recipient receives the route. Every change is logged in the timeline.',
  'branch_id' => (int)($route['branch_id'] ?? 0),
], JSON_UNESCAPED_UNICODE);
