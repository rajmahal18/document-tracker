<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../core/division_tracking.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

require_admin();
require_csrf();
ensure_division_tracking_tables($conn);

$divisionId = (int)($_POST['division_id'] ?? 0);
$orderRaw = $_POST['order'] ?? [];
if ($divisionId <= 0 || !is_array($orderRaw)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid slip order request.']);
  exit;
}

$divisionStmt = $conn->prepare('SELECT id FROM divisions WHERE id = ? AND is_active = 1 LIMIT 1');
$divisionStmt->bind_param('i', $divisionId);
$divisionStmt->execute();
$divisionExists = $divisionStmt->get_result()->fetch_assoc();
$divisionStmt->close();
if (!$divisionExists) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Division not found.']);
  exit;
}

$submitted = [];
foreach ($orderRaw as $userIdRaw => $sortRaw) {
  $userId = (int)$userIdRaw;
  if ($userId <= 0) continue;
  $sortOrder = max(1, min(999, (int)$sortRaw));
  $submitted[$userId] = $sortOrder;
}

$eligibleStmt = $conn->prepare("
  SELECT u.id
  FROM users u
  JOIN sections s ON s.id = u.section_id
  JOIN divisions d ON d.id = s.division_id
  WHERE d.id = ?
    AND u.is_active = 1
    AND s.is_active = 1
    AND d.is_active = 1
    AND LOWER(TRIM(COALESCE(u.authority_role, ''))) IN ('division_assistant', 'section_head')
");
$eligibleStmt->bind_param('i', $divisionId);
$eligibleStmt->execute();
$eligibleRows = $eligibleStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$eligibleStmt->close();

$eligibleIds = array_fill_keys(array_map(static fn(array $row): int => (int)$row['id'], $eligibleRows), true);
foreach (array_keys($submitted) as $userId) {
  if (!isset($eligibleIds[$userId])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'One of the selected users is not eligible for this division slip order.']);
    exit;
  }
}

$actorId = (int)($_SESSION['user_id'] ?? 0);
$conn->begin_transaction();
try {
  $deleteStmt = $conn->prepare('DELETE FROM division_tracking_slip_user_order WHERE division_id = ?');
  $deleteStmt->bind_param('i', $divisionId);
  $deleteStmt->execute();
  $deleteStmt->close();

  if ($submitted !== []) {
    asort($submitted, SORT_NUMERIC);
    $insertStmt = $conn->prepare('
      INSERT INTO division_tracking_slip_user_order
        (division_id, user_id, sort_order, updated_by_user_id)
      VALUES (?, ?, ?, ?)
    ');
    foreach ($submitted as $userId => $sortOrder) {
      $insertStmt->bind_param('iiii', $divisionId, $userId, $sortOrder, $actorId);
      $insertStmt->execute();
    }
    $insertStmt->close();
  }

  $conn->commit();
  echo json_encode(['ok' => true, 'message' => 'Division tracking slip order saved.']);
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to save division tracking slip order.']);
}
