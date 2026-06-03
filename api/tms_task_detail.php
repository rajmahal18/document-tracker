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

$task['id'] = (int)($task['id'] ?? 0);
$task['task_type_id'] = (int)($task['task_type_id'] ?? 0);
$task['project_id'] = isset($task['project_id']) ? (int)$task['project_id'] : null;
$task['document_id'] = isset($task['document_id']) ? (int)$task['document_id'] : null;
$task['progress_percent'] = isset($task['progress_percent']) ? (float)$task['progress_percent'] : null;
$task['remaining_workdays'] = isset($task['remaining_workdays']) ? (int)$task['remaining_workdays'] : null;
$task['can_edit'] = $permissions['can_edit_task'];
$task['can_delete'] = $permissions['can_delete_task'];
$task['permissions'] = $permissions;
$task['assignee_user_ids'] = [];

$assignees = is_array($task['assignees'] ?? null) ? $task['assignees'] : [];
foreach ($assignees as &$assignee) {
  $assignee['id'] = (int)($assignee['id'] ?? 0);
  $assignee['task_id'] = (int)($assignee['task_id'] ?? 0);
  $assignee['user_id'] = isset($assignee['user_id']) ? (int)$assignee['user_id'] : null;
  $assignee['sort_order'] = (int)($assignee['sort_order'] ?? 0);
  $assignee['is_primary'] = (int)($assignee['is_primary'] ?? 0);
  if (($assignee['user_id'] ?? 0) > 0) {
    $task['assignee_user_ids'][] = (int)$assignee['user_id'];
  }
}
unset($assignee);
$task['assignees'] = $assignees;

echo json_encode([
  'ok' => true,
  'task' => $task,
]);
