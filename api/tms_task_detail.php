<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../core/task_monitoring.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

require_login();

$taskId = (int)($_GET['id'] ?? 0);
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

$actorUserId = (int)($_SESSION['user_id'] ?? 0);
$permissions = tms_task_permissions($conn, $task, $actorUserId);
if (!$permissions['can_view_task']) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'You are not allowed to view this task.']);
  exit;
}

$task['permissions'] = $permissions;
$task['can_edit'] = $permissions['can_edit_task'];
$task['can_delete'] = $permissions['can_delete_task'];
$task['participant_user_ids'] = [];
$task['participant_role_labels'] = [];

foreach ((array)($task['participants'] ?? []) as $participant) {
  $userId = (int)($participant['user_id'] ?? 0);
  if ($userId > 0) {
    $task['participant_user_ids'][] = $userId;
    $task['participant_role_labels'][(string)$userId] = (string)($participant['participant_role_label'] ?? '');
  }
}

echo json_encode([
  'ok' => true,
  'task' => $task,
]);
