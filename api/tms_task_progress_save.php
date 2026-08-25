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

if (!tms_tables_ready($conn)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Task Monitoring tables are not ready.']);
  exit;
}

$actorUserId = (int)($_SESSION['user_id'] ?? 0);
$taskId = (int)($_POST['task_id'] ?? 0);
$progress = max(0, min(100, (int)($_POST['progress_percent'] ?? 0)));

if ($taskId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid task id.']);
  exit;
}

$task = tms_fetch_task($conn, $taskId);
if (!$task) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Task not found.']);
  exit;
}

$permissions = tms_task_permissions($conn, $task, $actorUserId);
if (!$permissions['can_edit_task']) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'You are not allowed to update this task.']);
  exit;
}

$context = tms_json_decode((string)($task['context_json'] ?? ''));
$oldProgress = max(0, min(100, (int)($context['progress_percent'] ?? 0)));
$context['progress_percent'] = $progress;
$contextJson = tms_json_encode($context);

try {
  $conn->begin_transaction();

  $stmt = $conn->prepare("UPDATE tms_tasks SET context_json = ?, updated_by_user_id = ? WHERE id = ? LIMIT 1");
  $stmt->bind_param('sii', $contextJson, $actorUserId, $taskId);
  $stmt->execute();
  $stmt->close();

  tms_log_activity(
    $conn,
    $taskId,
    $actorUserId,
    'progress_updated',
    'Task progress updated.',
    ['progress_percent' => $oldProgress],
    ['progress_percent' => $progress]
  );

  $conn->commit();
} catch (Throwable $e) {
  $conn->rollback();
  error_log('TMS progress save failed: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to save progress.']);
  exit;
}

echo json_encode([
  'ok' => true,
  'task_id' => $taskId,
  'progress_percent' => $progress,
  'message' => 'Progress updated.',
]);
