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
$taskStepId = (int)($_POST['task_step_id'] ?? 0);
$assigneeUserId = (int)($_POST['user_id'] ?? 0);

if ($taskStepId <= 0 || $assigneeUserId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Step and employee are required.']);
  exit;
}

$stmt = $conn->prepare("
  SELECT
    ts.*,
    t.created_by_user_id,
    t.owner_division_id,
    t.owner_section_id
  FROM tms_task_steps ts
  JOIN tms_tasks t ON t.id = ts.task_id
  WHERE ts.id = ?
  LIMIT 1
");
$stmt->bind_param('i', $taskStepId);
$stmt->execute();
$step = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$step) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Task step not found.']);
  exit;
}

$task = tms_fetch_task($conn, (int)$step['task_id']);
if (!$task) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Task not found.']);
  exit;
}

$permissions = tms_task_permissions($conn, $task, $actorUserId);
if (!$permissions['can_supervise_task'] && !$permissions['can_edit_task']) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'You are not allowed to assign this step.']);
  exit;
}

$scope = tms_viewer_scope();
$stepDivisionId = (int)($step['responsible_division_id'] ?? 0);
$stepSectionId = (int)($step['responsible_section_id'] ?? 0);
$inScope = false;
if (tms_user_can_manage_all($conn, $actorUserId)) {
  $inScope = true;
} elseif ($stepSectionId > 0 && $scope['section_id'] > 0 && $stepSectionId === $scope['section_id']) {
  $inScope = true;
} elseif ($stepSectionId <= 0 && $stepDivisionId > 0 && $scope['division_id'] > 0 && $stepDivisionId === $scope['division_id']) {
  $inScope = true;
}

if (!$inScope) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'This step is outside your office scope.']);
  exit;
}

$userSql = "
  SELECT u.id, u.section_id, s.division_id
  FROM users u
  LEFT JOIN sections s ON s.id = u.section_id
  WHERE u.id = ?
    AND u.is_active = 1
    AND s.division_id = ?
";
$params = [$assigneeUserId, $stepDivisionId];
$types = 'ii';
if ($stepSectionId > 0) {
  $userSql .= " AND u.section_id = ?";
  $params[] = $stepSectionId;
  $types .= 'i';
}
$userSql .= " LIMIT 1";

$userStmt = $conn->prepare($userSql);
$userStmt->bind_param($types, ...$params);
$userStmt->execute();
$assignee = $userStmt->get_result()->fetch_assoc() ?: null;
$userStmt->close();

if (!$assignee) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Selected employee does not belong to the step office.']);
  exit;
}

try {
  $conn->begin_transaction();

  $update = $conn->prepare("UPDATE tms_task_steps SET responsible_user_id = ?, status = CASE WHEN status = 'PENDING' THEN 'READY' ELSE status END WHERE id = ? LIMIT 1");
  $update->bind_param('ii', $assigneeUserId, $taskStepId);
  $update->execute();
  $update->close();

  $divisionId = (int)($assignee['division_id'] ?? 0) ?: null;
  $sectionId = (int)($assignee['section_id'] ?? 0) ?: null;
  $roleLabel = trim((string)($step['role_label'] ?? '')) ?: 'Contributor';
  $status = 'INVITED';
  $isLead = 0;

  $participant = $conn->prepare("
    INSERT INTO tms_task_participants
      (task_id, task_step_id, user_id, division_id, section_id, participant_role_label, participation_status, is_lead, invited_by_user_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      division_id = VALUES(division_id),
      section_id = VALUES(section_id),
      participant_role_label = VALUES(participant_role_label),
      participation_status = CASE WHEN participation_status = 'DECLINED' THEN 'INVITED' ELSE participation_status END,
      invited_by_user_id = VALUES(invited_by_user_id)
  ");
  $taskId = (int)$step['task_id'];
  $participant->bind_param('iiiiissii', $taskId, $taskStepId, $assigneeUserId, $divisionId, $sectionId, $roleLabel, $status, $isLead, $actorUserId);
  $participant->execute();
  $participant->close();

  tms_log_activity($conn, $taskId, $actorUserId, 'step_assigned', 'Task step assigned.', null, [
    'task_step_id' => $taskStepId,
    'user_id' => $assigneeUserId,
  ], $taskStepId);

  $conn->commit();
} catch (Throwable $e) {
  $conn->rollback();
  error_log('TMS task step assign failed: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to assign step.']);
  exit;
}

echo json_encode([
  'ok' => true,
  'message' => 'Step assigned.',
]);
