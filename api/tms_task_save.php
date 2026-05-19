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

if (!db_table_exists($conn, 'tms_tasks') || !db_table_exists($conn, 'tms_task_types')) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Task Monitoring tables are not ready.']);
  exit;
}

$actorUserId = (int)($_SESSION['user_id'] ?? 0);
$id = (int)($_POST['id'] ?? 0);
$taskTypeId = (int)($_POST['task_type_id'] ?? 0);
$projectId = (int)($_POST['project_id'] ?? 0);
$documentId = (int)($_POST['document_id'] ?? 0);
$projectCode = trim((string)($_POST['project_code'] ?? ''));
$projectTitle = trim((string)($_POST['project_title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$deo = trim((string)($_POST['deo'] ?? ''));
$lgu = trim((string)($_POST['lgu'] ?? ''));
$dateSurveyed = trim((string)($_POST['date_surveyed'] ?? ''));
$dateReceived = trim((string)($_POST['date_received'] ?? ''));
$dateStarted = trim((string)($_POST['date_started'] ?? ''));
$targetCompletion = trim((string)($_POST['target_completion'] ?? ''));
$progressInput = trim((string)($_POST['progress_percent'] ?? ''));
$referenceCode = trim((string)($_POST['reference_code'] ?? ''));
$remarks = tms_normalize_textarea((string)($_POST['remarks'] ?? ''));
$assigneeUserIdsRaw = $_POST['assignee_user_ids'] ?? [];

if ($taskTypeId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Task type is required.']);
  exit;
}

$typeStmt = $conn->prepare("SELECT * FROM tms_task_types WHERE id = ? LIMIT 1");
$typeStmt->bind_param('i', $taskTypeId);
$typeStmt->execute();
$taskType = $typeStmt->get_result()->fetch_assoc();
$typeStmt->close();

if (!$taskType) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Task type not found.']);
  exit;
}

if ($projectId > 0) {
  $projStmt = $conn->prepare("SELECT project_code, title FROM projects WHERE id = ? LIMIT 1");
  $projStmt->bind_param('i', $projectId);
  $projStmt->execute();
  $project = $projStmt->get_result()->fetch_assoc();
  $projStmt->close();
  if ($project) {
    if ($projectCode === '') $projectCode = trim((string)($project['project_code'] ?? ''));
    if ($projectTitle === '') $projectTitle = trim((string)($project['title'] ?? ''));
  }
}

if ($projectCode === '' || $projectTitle === '' || $description === '') {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Project code, project title, and description are required.']);
  exit;
}

$assigneeUserIds = is_array($assigneeUserIdsRaw) ? $assigneeUserIdsRaw : [$assigneeUserIdsRaw];
$assigneeUserIds = array_values(array_unique(array_filter(array_map('intval', $assigneeUserIds), static fn(int $value): bool => $value > 0)));
if ($assigneeUserIds === []) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'At least one assigned user is required.']);
  exit;
}

$ph = implode(',', array_fill(0, count($assigneeUserIds), '?'));
$userStmt = $conn->prepare("
  SELECT id, full_name
  FROM users
  WHERE id IN ($ph)
    AND is_active = 1
  ORDER BY FIELD(id, $ph)
");
$bindTypes = str_repeat('i', count($assigneeUserIds) * 2);
$bindValues = array_merge($assigneeUserIds, $assigneeUserIds);
$userStmt->bind_param($bindTypes, ...$bindValues);
$userStmt->execute();
$assigneeRows = $userStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$userStmt->close();

if ($assigneeRows === []) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Assigned users are invalid.']);
  exit;
}

$orderedAssignees = [];
foreach ($assigneeUserIds as $userId) {
  foreach ($assigneeRows as $row) {
    if ((int)($row['id'] ?? 0) === $userId) {
      $orderedAssignees[] = $row;
      break;
    }
  }
}

$ownerUserId = (int)($orderedAssignees[0]['id'] ?? 0);
$assigneeDisplay = implode(', ', array_map(static fn(array $row): string => trim((string)($row['full_name'] ?? '')), $orderedAssignees));

$existing = $id > 0 ? tms_fetch_task($conn, $id) : null;
if ($id > 0 && !$existing) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Task not found.']);
  exit;
}
if ($existing && !tms_user_can_edit_task($conn, $existing, $actorUserId)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'You are not allowed to edit this task.']);
  exit;
}

$payload = [
  'progress_percent' => $progressInput,
  'reference_code' => $referenceCode,
  'date_started' => $dateStarted,
  'target_completion' => $targetCompletion,
  'status_label' => trim((string)($_POST['status_label'] ?? '')),
];
$derived = tms_compute_derived($conn, $taskType, $payload, $existing);

$divisionId = isset($_SESSION['division_id']) ? (int)$_SESSION['division_id'] : 0;
$sectionId = isset($_SESSION['section_id']) ? (int)$_SESSION['section_id'] : 0;
$scopeCode = trim((string)($taskType['scope_code'] ?? ''));

try {
  $conn->begin_transaction();

  if ($existing) {
    $stmt = $conn->prepare("
      UPDATE tms_tasks
      SET
        task_type_id = ?,
        project_id = NULLIF(?, 0),
        document_id = NULLIF(?, 0),
        updated_by_user_id = ?,
        owner_user_id = ?,
        division_id = NULLIF(?, 0),
        section_id = NULLIF(?, 0),
        scope_code = ?,
        project_code = ?,
        project_title = ?,
        description = ?,
        deo = ?,
        lgu = ?,
        assignee_display = ?,
        date_surveyed = ?,
        date_received = ?,
        date_started = ?,
        target_completion = ?,
        remaining_workdays = ?,
        progress_percent = ?,
        status_label = ?,
        reference_code = ?,
        remarks = ?,
        completed_at = ?
      WHERE id = ?
      LIMIT 1
    ");
    $stmt->bind_param(
      'iiiiiiisssssssssssidssssi',
      $taskTypeId,
      $projectId,
      $documentId,
      $actorUserId,
      $ownerUserId,
      $divisionId,
      $sectionId,
      $scopeCode,
      $projectCode,
      $projectTitle,
      $description,
      $deo,
      $lgu,
      $assigneeDisplay,
      $dateSurveyed,
      $dateReceived,
      $dateStarted,
      $targetCompletion,
      $derived['remaining_workdays'],
      $derived['progress_percent'],
      $derived['status_label'],
      $referenceCode,
      $remarks,
      $derived['completed_at'],
      $id
    );
    $stmt->execute();
    $stmt->close();
    $taskId = $id;
  } else {
    $stmt = $conn->prepare("
      INSERT INTO tms_tasks (
        task_type_id,
        project_id,
        document_id,
        created_by_user_id,
        updated_by_user_id,
        owner_user_id,
        division_id,
        section_id,
        scope_code,
        project_code,
        project_title,
        description,
        deo,
        lgu,
        assignee_display,
        date_surveyed,
        date_received,
        date_started,
        target_completion,
        remaining_workdays,
        progress_percent,
        status_label,
        reference_code,
        remarks,
        completed_at
      ) VALUES (?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
      'iiiiiiiisssssssssssidssss',
      $taskTypeId,
      $projectId,
      $documentId,
      $actorUserId,
      $actorUserId,
      $ownerUserId,
      $divisionId,
      $sectionId,
      $scopeCode,
      $projectCode,
      $projectTitle,
      $description,
      $deo,
      $lgu,
      $assigneeDisplay,
      $dateSurveyed,
      $dateReceived,
      $dateStarted,
      $targetCompletion,
      $derived['remaining_workdays'],
      $derived['progress_percent'],
      $derived['status_label'],
      $referenceCode,
      $remarks,
      $derived['completed_at']
    );
    $stmt->execute();
    $taskId = (int)$stmt->insert_id;
    $stmt->close();
  }

  $deleteAssignees = $conn->prepare("DELETE FROM tms_task_assignees WHERE task_id = ?");
  $deleteAssignees->bind_param('i', $taskId);
  $deleteAssignees->execute();
  $deleteAssignees->close();

  $insertAssignee = $conn->prepare("
    INSERT INTO tms_task_assignees
      (task_id, user_id, display_name, assignment_role, sort_order, is_primary)
    VALUES (?, ?, ?, 'assignee', ?, ?)
  ");
  $sortOrder = 1;
  foreach ($orderedAssignees as $row) {
    $assigneeUserId = (int)($row['id'] ?? 0);
    $displayName = trim((string)($row['full_name'] ?? ''));
    $isPrimary = $sortOrder === 1 ? 1 : 0;
    $insertAssignee->bind_param('iisii', $taskId, $assigneeUserId, $displayName, $sortOrder, $isPrimary);
    $insertAssignee->execute();
    $sortOrder++;
  }
  $insertAssignee->close();

  tms_log_activity(
    $conn,
    $taskId,
    $actorUserId,
    $existing ? 'updated' : 'created',
    $existing ? 'Task updated.' : 'Task created.',
    $existing ? [
      'status_label' => (string)($existing['status_label'] ?? ''),
      'progress_percent' => $existing['progress_percent'] ?? null,
      'reference_code' => (string)($existing['reference_code'] ?? ''),
    ] : null,
    [
      'status_label' => $derived['status_label'],
      'progress_percent' => $derived['progress_percent'],
      'reference_code' => $referenceCode,
    ]
  );

  $conn->commit();
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to save task.']);
  exit;
}

echo json_encode([
  'ok' => true,
  'task_id' => $taskId,
  'message' => $existing ? 'Task updated.' : 'Task created.',
]);
