<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../core/task_monitoring.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

require_login();
require_csrf();

$actorUserId = (int)($_SESSION['user_id'] ?? 0);
$taskId = (int)($_POST['task_id'] ?? 0);
$response = strtolower(trim((string)($_POST['response'] ?? '')));

if ($taskId <= 0 || !in_array($response, ['join', 'decline'], true)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid participant response.']);
  exit;
}

$task = tms_fetch_task($conn, $taskId);
if (!$task) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Task not found.']);
  exit;
}

$participant = null;
foreach ((array)($task['participants'] ?? []) as $row) {
  if ((int)($row['user_id'] ?? 0) === $actorUserId) {
    $participant = $row;
    break;
  }
}

if (!$participant) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'You are not invited to this task.']);
  exit;
}

$nextStatus = $response === 'join' ? 'ACTIVE' : 'DECLINED';

try {
  $conn->begin_transaction();

  $stmt = $conn->prepare("
    UPDATE tms_task_participants
    SET participation_status = ?, responded_at = NOW()
    WHERE task_id = ?
      AND user_id = ?
    LIMIT 1
  ");
  $stmt->bind_param('sii', $nextStatus, $taskId, $actorUserId);
  $stmt->execute();
  $stmt->close();

  tms_log_activity(
    $conn,
    $taskId,
    $actorUserId,
    $response === 'join' ? 'participant_joined' : 'participant_declined',
    $response === 'join' ? 'Participant joined the task.' : 'Participant declined the task.',
    ['participation_status' => (string)($participant['participation_status'] ?? '')],
    ['participation_status' => $nextStatus]
  );

  $conn->commit();
} catch (Throwable) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to update participant response.']);
  exit;
}

echo json_encode([
  'ok' => true,
  'message' => $response === 'join' ? 'You joined the task.' : 'Invitation declined.',
]);
