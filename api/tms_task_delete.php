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

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid task id.']);
  exit;
}

$task = tms_fetch_task($conn, $id);
if (!$task) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Task not found.']);
  exit;
}

$actorUserId = (int)($_SESSION['user_id'] ?? 0);
$permissions = tms_task_permissions($conn, $task, $actorUserId);
if (!$permissions['can_delete_task']) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'You are not allowed to delete this task.']);
  exit;
}

try {
  $stmt = $conn->prepare("DELETE FROM tms_tasks WHERE id = ? LIMIT 1");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $stmt->close();
} catch (Throwable) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to delete task.']);
  exit;
}

echo json_encode([
  'ok' => true,
  'message' => 'Task deleted.'
]);
