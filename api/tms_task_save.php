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
$id = (int)($_POST['id'] ?? 0);
$taskTypeId = (int)($_POST['task_type_id'] ?? 0);
$workflowTemplateId = (int)($_POST['workflow_template_id'] ?? 0);
$projectId = (int)($_POST['project_id'] ?? 0);
$documentId = (int)($_POST['document_id'] ?? 0);
$title = trim((string)($_POST['title'] ?? ''));
$description = tms_normalize_textarea((string)($_POST['description'] ?? ''));
$priority = strtolower(trim((string)($_POST['priority'] ?? 'normal')));
$flowMode = strtolower(trim((string)($_POST['flow_mode'] ?? '')));
$targetStartAt = trim((string)($_POST['target_start_at'] ?? ''));
$targetDueAt = trim((string)($_POST['target_due_at'] ?? ''));
$hasIndefiniteTimeline = (int)($_POST['has_indefinite_timeline'] ?? 0) === 1;
$remarks = tms_normalize_textarea((string)($_POST['remarks'] ?? ''));
$participantUserIdsRaw = $_POST['participant_user_ids'] ?? [];
$participantRoleLabelsRaw = $_POST['participant_role_labels'] ?? [];
$timelineStepsRaw = $_POST['timeline_steps'] ?? [];
$appendTimelineSteps = (int)($_POST['append_timeline_steps'] ?? 0) === 1;
$leadUserId = (int)($_POST['lead_user_id'] ?? 0);

if ($taskTypeId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Task type is required.']);
  exit;
}

if ($title === '') {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Task title is required.']);
  exit;
}

if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
  $priority = 'normal';
}

$typeStmt = $conn->prepare("SELECT * FROM tms_task_types WHERE id = ? AND is_active = 1 LIMIT 1");
$typeStmt->bind_param('i', $taskTypeId);
$typeStmt->execute();
$taskType = $typeStmt->get_result()->fetch_assoc();
$typeStmt->close();

if (!$taskType) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Task type not found.']);
  exit;
}

if ($workflowTemplateId <= 0 && (int)($taskType['default_workflow_template_id'] ?? 0) > 0) {
  $workflowTemplateId = (int)$taskType['default_workflow_template_id'];
}

if ($workflowTemplateId <= 0) {
  $fallbackStmt = $conn->prepare("
    SELECT id
    FROM tms_workflow_templates
    WHERE is_active = 1
      AND (task_type_id = ? OR task_type_id IS NULL)
    ORDER BY task_type_id IS NULL ASC, is_default DESC, name ASC
    LIMIT 1
  ");
  $fallbackStmt->bind_param('i', $taskTypeId);
  $fallbackStmt->execute();
  $workflowTemplateId = (int)($fallbackStmt->get_result()->fetch_assoc()['id'] ?? 0);
  $fallbackStmt->close();
}

$workflowStmt = $conn->prepare("
  SELECT *
  FROM tms_workflow_templates
  WHERE id = ?
    AND is_active = 1
  LIMIT 1
");
$workflowStmt->bind_param('i', $workflowTemplateId);
$workflowStmt->execute();
$workflow = $workflowStmt->get_result()->fetch_assoc();
$workflowStmt->close();

if (!$workflow) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Workflow template is required.']);
  exit;
}

if ($flowMode === '') {
  $flowMode = strtolower(trim((string)($workflow['flow_mode'] ?? 'sequential')));
}
if (!in_array($flowMode, ['sequential', 'parallel', 'mixed'], true)) {
  $flowMode = 'sequential';
}

function tms_indexed_rows(mixed $raw): array
{
  if (!is_array($raw)) {
    return [];
  }

  ksort($raw);
  $rows = [];
  foreach ($raw as $row) {
    if (is_array($row)) {
      $rows[] = $row;
    }
  }

  return $rows;
}

function tms_id_exists(mysqli $conn, string $table, int $id): bool
{
  if ($id <= 0) {
    return false;
  }

  $allowed = ['divisions', 'sections', 'users'];
  if (!in_array($table, $allowed, true)) {
    return false;
  }

  $sql = "SELECT 1 FROM {$table} WHERE id = ? " . ($table === 'users' ? "AND is_active = 1 " : "") . "LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $exists = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  return $exists;
}

function tms_section_belongs_to_division(mysqli $conn, int $sectionId, int $divisionId): bool
{
  if ($sectionId <= 0 || $divisionId <= 0) {
    return false;
  }

  $stmt = $conn->prepare("SELECT 1 FROM sections WHERE id = ? AND division_id = ? LIMIT 1");
  $stmt->bind_param('ii', $sectionId, $divisionId);
  $stmt->execute();
  $exists = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  return $exists;
}

function tms_workflow_step_belongs_to_template(mysqli $conn, int $workflowStepId, int $workflowTemplateId): bool
{
  if ($workflowStepId <= 0 || $workflowTemplateId <= 0) {
    return false;
  }

  $stmt = $conn->prepare("SELECT 1 FROM tms_workflow_steps WHERE id = ? AND workflow_template_id = ? LIMIT 1");
  $stmt->bind_param('ii', $workflowStepId, $workflowTemplateId);
  $stmt->execute();
  $exists = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  return $exists;
}

function tms_user_belongs_to_scope(mysqli $conn, int $userId, int $divisionId, ?int $sectionId): bool
{
  if ($userId <= 0 || $divisionId <= 0) {
    return false;
  }

  $sql = "
    SELECT u.id
    FROM users u
    LEFT JOIN sections s ON s.id = u.section_id
    WHERE u.id = ?
      AND u.is_active = 1
      AND s.division_id = ?
  ";
  $params = [$userId, $divisionId];
  $types = 'ii';
  if ($sectionId !== null && $sectionId > 0) {
    $sql .= " AND u.section_id = ?";
    $params[] = $sectionId;
    $types .= 'i';
  }
  $sql .= " LIMIT 1";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $exists = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  return $exists;
}

$timelineSteps = [];
foreach (tms_indexed_rows($timelineStepsRaw) as $index => $row) {
  $stepTitle = trim((string)($row['title'] ?? ''));
  $divisionId = (int)($row['division_id'] ?? 0);
  $sectionId = (int)($row['section_id'] ?? 0);
  $responsibleUserId = (int)($row['responsible_user_id'] ?? 0);
  $workflowStepId = (int)($row['workflow_step_id'] ?? 0);
  $durationRaw = trim((string)($row['duration_working_days'] ?? ''));
  $durationDays = $durationRaw !== '' ? (int)$durationRaw : null;

  if ($stepTitle === '' && $divisionId <= 0 && $sectionId <= 0 && $durationDays === null && $responsibleUserId <= 0) {
    continue;
  }

  if ($stepTitle === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Each subtask needs a title.']);
    exit;
  }

  if ($divisionId <= 0 || !tms_id_exists($conn, 'divisions', $divisionId)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Each subtask needs a valid division.']);
    exit;
  }

  if ($sectionId > 0 && !tms_section_belongs_to_division($conn, $sectionId, $divisionId)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'A selected subtask section does not belong to its division.']);
    exit;
  }

  if ($responsibleUserId > 0 && !tms_user_belongs_to_scope($conn, $responsibleUserId, $divisionId, $sectionId > 0 ? $sectionId : null)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'A selected subtask employee does not belong to the chosen office.']);
    exit;
  }

  if ($workflowStepId > 0 && !tms_workflow_step_belongs_to_template($conn, $workflowStepId, $workflowTemplateId)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'A selected workflow step does not belong to the chosen template.']);
    exit;
  }

  if ($durationDays !== null && $durationDays <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Subtask duration must be at least 1 working day when provided.']);
    exit;
  }

  $timelineSteps[] = [
    'id' => 0,
    'workflow_step_id' => $workflowStepId > 0 ? $workflowStepId : null,
    'step_order' => $index + 1,
    'title' => $stepTitle,
    'instructions' => tms_normalize_textarea((string)($row['instructions'] ?? '')),
    'default_responsible_division_id' => $divisionId,
    'default_responsible_section_id' => $sectionId > 0 ? $sectionId : null,
    'responsible_user_id' => $responsibleUserId > 0 ? $responsibleUserId : null,
    'default_role_label' => 'Lead',
    'estimated_working_minutes' => $durationDays !== null ? $durationDays * dt_work_minutes_per_day($conn) : null,
    'can_run_parallel' => 0,
    'requires_output' => 0,
    'requires_validation' => 0,
    'is_ipcr_creditable' => 1,
    'is_completion_step' => 0,
    'duration_working_days' => $durationDays,
  ];
}

if ($id <= 0 && $timelineSteps !== [] && $targetStartAt === '') {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Target start is required when creating a timeline.']);
  exit;
}

if ($targetDueAt === '' && !$hasIndefiniteTimeline && ($timelineSteps === [] || $targetStartAt === '')) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Target completion is required unless the task has an indefinite timeline.']);
  exit;
}

$participantUserIds = is_array($participantUserIdsRaw) ? $participantUserIdsRaw : [$participantUserIdsRaw];
$participantUserIds = array_values(array_unique(array_filter(array_map('intval', $participantUserIds), static fn(int $value): bool => $value > 0)));
foreach ($timelineSteps as $timelineStep) {
  $stepUserId = (int)($timelineStep['responsible_user_id'] ?? 0);
  if ($stepUserId > 0 && !in_array($stepUserId, $participantUserIds, true)) {
    $participantUserIds[] = $stepUserId;
  }
}
if (!in_array($actorUserId, $participantUserIds, true)) {
  array_unshift($participantUserIds, $actorUserId);
}

$leadUserId = $leadUserId > 0 ? $leadUserId : $actorUserId;
if (!in_array($leadUserId, $participantUserIds, true)) {
  $participantUserIds[] = $leadUserId;
}

$userMap = tms_fetch_user_map($conn, $participantUserIds);
if (!isset($userMap[$actorUserId])) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Your user account is not available for task creation.']);
  exit;
}

foreach ($participantUserIds as $participantUserId) {
  if (!isset($userMap[$participantUserId])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'One or more selected participants are invalid.']);
    exit;
  }
}

$existing = $id > 0 ? tms_fetch_task($conn, $id) : null;
if ($id > 0 && !$existing) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Task not found.']);
  exit;
}

if ($existing) {
  $permissions = tms_task_permissions($conn, $existing, $actorUserId);
  if (!$permissions['can_edit_task']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You are not allowed to edit this task.']);
    exit;
  }

  $taskTypeId = (int)($existing['task_type_id'] ?? $taskTypeId);
  $workflowTemplateId = (int)($existing['workflow_template_id'] ?? $workflowTemplateId);
  $flowMode = strtolower(trim((string)($existing['flow_mode'] ?? $flowMode))) ?: 'sequential';

  $typeStmt = $conn->prepare("SELECT * FROM tms_task_types WHERE id = ? LIMIT 1");
  $typeStmt->bind_param('i', $taskTypeId);
  $typeStmt->execute();
  $taskType = $typeStmt->get_result()->fetch_assoc();
  $typeStmt->close();

  $workflowStmt = $conn->prepare("SELECT * FROM tms_workflow_templates WHERE id = ? LIMIT 1");
  $workflowStmt->bind_param('i', $workflowTemplateId);
  $workflowStmt->execute();
  $workflow = $workflowStmt->get_result()->fetch_assoc();
  $workflowStmt->close();

  if (!$taskType || !$workflow) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Existing task configuration is incomplete.']);
    exit;
  }
}

$ownerDivisionId = (int)($_SESSION['division_id'] ?? 0);
$ownerSectionId = (int)($_SESSION['section_id'] ?? 0);
$targetStartSql = $targetStartAt !== '' ? str_replace('T', ' ', $targetStartAt) : null;
$targetDueSql = (!$hasIndefiniteTimeline && $targetDueAt !== '') ? str_replace('T', ' ', $targetDueAt) : null;
$existingContext = $existing ? tms_json_decode((string)($existing['context_json'] ?? '')) : [];
$contextJson = tms_json_encode(array_merge($existingContext, [
  'project_id' => $projectId > 0 ? $projectId : null,
  'document_id' => $documentId > 0 ? $documentId : null,
]));
$ipcrJson = tms_json_encode([
  'ready_for_future_ipcr' => (int)($taskType['is_ipcr_relevant'] ?? 1) === 1,
]);

$steps = $existing ? [] : $timelineSteps;

$estimatedValues = array_values(array_filter(
  array_map(static fn(array $step): ?int => isset($step['estimated_working_minutes']) ? (int)$step['estimated_working_minutes'] : null, $steps),
  static fn(?int $value): bool => $value !== null && $value > 0
));
$estimatedMinutes = $estimatedValues !== [] ? array_sum($estimatedValues) : null;
if (!$existing && $timelineSteps !== [] && $targetStartSql !== null && $targetDueSql === null && $estimatedMinutes !== null) {
  $computedDue = dt_add_working_minutes($targetStartSql, $estimatedMinutes, $conn);
  if ($computedDue instanceof DateTimeImmutable) {
    $targetDueSql = $computedDue->format('Y-m-d H:i:s');
  }
}

$participantRoleLabels = is_array($participantRoleLabelsRaw) ? $participantRoleLabelsRaw : [];
$hasInvitedOtherParticipants = false;
foreach ($participantUserIds as $participantUserId) {
  if ($participantUserId !== $actorUserId) {
    $hasInvitedOtherParticipants = true;
    break;
  }
}
$previousParticipantStatus = [];
if ($existing) {
  foreach ((array)($existing['participants'] ?? []) as $participant) {
    $previousParticipantStatus[(int)($participant['user_id'] ?? 0)] = (string)($participant['participation_status'] ?? 'INVITED');
  }
}

try {
  $conn->begin_transaction();

  if ($existing) {
    $stmt = $conn->prepare("
      UPDATE tms_tasks
      SET
        task_type_id = ?,
        workflow_template_id = ?,
        project_id = NULLIF(?, 0),
        document_id = NULLIF(?, 0),
        updated_by_user_id = ?,
        owner_division_id = NULLIF(?, 0),
        owner_section_id = NULLIF(?, 0),
        title = ?,
        description = ?,
        priority = ?,
        flow_mode = ?,
        target_start_at = ?,
        target_due_at = ?,
        context_json = ?,
        ipcr_metadata_json = ?,
        remarks = ?
      WHERE id = ?
      LIMIT 1
    ");
    $stmt->bind_param(
      'iiiiiiisssssssssi',
      $taskTypeId,
      $workflowTemplateId,
      $projectId,
      $documentId,
      $actorUserId,
      $ownerDivisionId,
      $ownerSectionId,
      $title,
      $description,
      $priority,
      $flowMode,
      $targetStartSql,
      $targetDueSql,
      $contextJson,
      $ipcrJson,
      $remarks,
      $id
    );
    $stmt->execute();
    $stmt->close();
    $taskId = $id;
  } else {
    $stmt = $conn->prepare("
      INSERT INTO tms_tasks (
        task_type_id,
        workflow_template_id,
        project_id,
        document_id,
        created_by_user_id,
        updated_by_user_id,
        owner_division_id,
        owner_section_id,
        title,
        description,
        priority,
        flow_mode,
        lifecycle_status,
        target_start_at,
        target_due_at,
        started_at,
        estimated_working_minutes,
        context_json,
        ipcr_metadata_json,
        remarks
      ) VALUES (?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, ?, 'OPEN', ?, ?, NOW(), ?, ?, ?, ?)
    ");
    $stmt->bind_param(
      'iiiiiiiissssssisss',
      $taskTypeId,
      $workflowTemplateId,
      $projectId,
      $documentId,
      $actorUserId,
      $actorUserId,
      $ownerDivisionId,
      $ownerSectionId,
      $title,
      $description,
      $priority,
      $flowMode,
      $targetStartSql,
      $targetDueSql,
      $estimatedMinutes,
      $contextJson,
      $ipcrJson,
      $remarks
    );
    $stmt->execute();
    $taskId = (int)$stmt->insert_id;
    $stmt->close();

    $insertStep = $conn->prepare("
      INSERT INTO tms_task_steps (
        task_id,
        workflow_step_id,
        step_order,
        title,
        instructions,
        responsible_division_id,
        responsible_section_id,
        responsible_user_id,
        role_label,
        status,
        planned_start_at,
        planned_due_at,
        estimated_working_minutes,
        can_run_parallel,
        requires_output,
        requires_validation,
        is_ipcr_creditable,
        is_completion_step
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $currentStepId = 0;
    $stepCursor = $targetStartSql;
    $lastStepIndex = count($steps) - 1;
    foreach ($steps as $index => $step) {
      $stepStatus = $flowMode === 'parallel' || $index === 0 ? 'READY' : 'PENDING';
      $responsibleDivisionId = (int)($step['default_responsible_division_id'] ?? 0) ?: $ownerDivisionId;
      $responsibleSectionId = (int)($step['default_responsible_section_id'] ?? 0) ?: $ownerSectionId;
      $responsibleDivisionId = $responsibleDivisionId > 0 ? $responsibleDivisionId : null;
      $responsibleSectionId = $responsibleSectionId > 0 ? $responsibleSectionId : null;
      $stepResponsibleUserId = (int)($step['responsible_user_id'] ?? 0);
      $responsibleUserId = $stepResponsibleUserId > 0 ? $stepResponsibleUserId : ($index === 0 && $timelineSteps === [] && $leadUserId > 0 ? $leadUserId : null);
      $roleLabel = trim((string)($step['default_role_label'] ?? '')) ?: 'Contributor';
      $estimated = isset($step['estimated_working_minutes']) ? (int)$step['estimated_working_minutes'] : null;
      $plannedStart = $index === 0 ? $targetStartSql : null;
      $plannedDue = $index === 0 ? $targetDueSql : null;
      if ($stepCursor !== null && $estimated !== null) {
        $plannedStart = $stepCursor;
        $computedStepDue = dt_add_working_minutes($plannedStart, $estimated, $conn);
        if ($computedStepDue instanceof DateTimeImmutable) {
          $plannedDue = $computedStepDue->format('Y-m-d H:i:s');
          $stepCursor = $plannedDue;
        }
      }
      $canParallel = (int)($step['can_run_parallel'] ?? 0);
      $requiresOutput = (int)($step['requires_output'] ?? 0);
      $requiresValidation = (int)($step['requires_validation'] ?? 0);
      $isIpcrCreditable = (int)($step['is_ipcr_creditable'] ?? 1);
      $isCompletion = $timelineSteps !== [] && $index === $lastStepIndex ? 1 : (int)($step['is_completion_step'] ?? 0);
      $workflowStepId = (int)($step['workflow_step_id'] ?? $step['id'] ?? 0);
      $workflowStepId = $workflowStepId > 0 ? $workflowStepId : null;
      $stepOrder = (int)$step['step_order'];
      $stepTitle = (string)$step['title'];
      $instructions = (string)($step['instructions'] ?? '');

      $insertStep->bind_param(
        'iiissiiissssiiiiii',
        $taskId,
        $workflowStepId,
        $stepOrder,
        $stepTitle,
        $instructions,
        $responsibleDivisionId,
        $responsibleSectionId,
        $responsibleUserId,
        $roleLabel,
        $stepStatus,
        $plannedStart,
        $plannedDue,
        $estimated,
        $canParallel,
        $requiresOutput,
        $requiresValidation,
        $isIpcrCreditable,
        $isCompletion
      );
      $insertStep->execute();
      if ($currentStepId <= 0) {
        $currentStepId = (int)$insertStep->insert_id;
      }
    }
    $insertStep->close();

    if ($currentStepId > 0) {
      $stepStmt = $conn->prepare("UPDATE tms_tasks SET current_step_id = ? WHERE id = ? LIMIT 1");
      $stepStmt->bind_param('ii', $currentStepId, $taskId);
      $stepStmt->execute();
      $stepStmt->close();
    }
  }

  if ($existing && $appendTimelineSteps && $timelineSteps !== []) {
    $stepInfoStmt = $conn->prepare("
      SELECT COALESCE(MAX(ts.step_order), 0) AS max_step_order, COALESCE(t.current_step_id, 0) AS current_step_id
      FROM tms_tasks t
      LEFT JOIN tms_task_steps ts ON ts.task_id = t.id
      WHERE t.id = ?
      GROUP BY t.id, t.current_step_id
    ");
    $stepInfoStmt->bind_param('i', $taskId);
    $stepInfoStmt->execute();
    $stepInfo = $stepInfoStmt->get_result()->fetch_assoc() ?: [];
    $stepInfoStmt->close();

    $nextStepOrder = (int)($stepInfo['max_step_order'] ?? 0);
    $hasCurrentStep = (int)($stepInfo['current_step_id'] ?? 0) > 0;

    $insertStep = $conn->prepare("
      INSERT INTO tms_task_steps (
        task_id,
        workflow_step_id,
        step_order,
        title,
        instructions,
        responsible_division_id,
        responsible_section_id,
        responsible_user_id,
        role_label,
        status,
        planned_start_at,
        planned_due_at,
        estimated_working_minutes,
        can_run_parallel,
        requires_output,
        requires_validation,
        is_ipcr_creditable,
        is_completion_step
      ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?)
    ");

    $newCurrentStepId = 0;
    $lastStepIndex = count($timelineSteps) - 1;
    foreach ($timelineSteps as $index => $step) {
      $stepOrder = ++$nextStepOrder;
      $stepTitle = (string)$step['title'];
      $instructions = (string)($step['instructions'] ?? '');
      $responsibleDivisionId = (int)($step['default_responsible_division_id'] ?? 0) ?: $ownerDivisionId;
      $responsibleSectionId = (int)($step['default_responsible_section_id'] ?? 0) ?: $ownerSectionId;
      $responsibleDivisionId = $responsibleDivisionId > 0 ? $responsibleDivisionId : null;
      $responsibleSectionId = $responsibleSectionId > 0 ? $responsibleSectionId : null;
      $stepResponsibleUserId = (int)($step['responsible_user_id'] ?? 0);
      $responsibleUserId = $stepResponsibleUserId > 0 ? $stepResponsibleUserId : null;
      $roleLabel = trim((string)($step['default_role_label'] ?? '')) ?: 'Contributor';
      $stepStatus = $hasCurrentStep || $index > 0 ? 'PENDING' : 'READY';
      $estimated = isset($step['estimated_working_minutes']) ? (int)$step['estimated_working_minutes'] : null;
      $canParallel = (int)($step['can_run_parallel'] ?? 0);
      $requiresOutput = (int)($step['requires_output'] ?? 0);
      $requiresValidation = (int)($step['requires_validation'] ?? 0);
      $isIpcrCreditable = (int)($step['is_ipcr_creditable'] ?? 1);
      $isCompletion = $index === $lastStepIndex ? 1 : 0;

      $insertStep->bind_param(
        'iissiiissiiiiii',
        $taskId,
        $stepOrder,
        $stepTitle,
        $instructions,
        $responsibleDivisionId,
        $responsibleSectionId,
        $responsibleUserId,
        $roleLabel,
        $stepStatus,
        $estimated,
        $canParallel,
        $requiresOutput,
        $requiresValidation,
        $isIpcrCreditable,
        $isCompletion
      );
      $insertStep->execute();
      if (!$hasCurrentStep && $newCurrentStepId <= 0) {
        $newCurrentStepId = (int)$insertStep->insert_id;
      }
    }
    $insertStep->close();

    if ($newCurrentStepId > 0) {
      $stepStmt = $conn->prepare("UPDATE tms_tasks SET current_step_id = ? WHERE id = ? LIMIT 1");
      $stepStmt->bind_param('ii', $newCurrentStepId, $taskId);
      $stepStmt->execute();
      $stepStmt->close();
    }
  }

  $deleteParticipants = $conn->prepare("DELETE FROM tms_task_participants WHERE task_id = ?");
  $deleteParticipants->bind_param('i', $taskId);
  $deleteParticipants->execute();
  $deleteParticipants->close();

  $insertParticipant = $conn->prepare("
    INSERT INTO tms_task_participants
      (task_id, task_step_id, user_id, division_id, section_id, participant_role_label, participation_status, is_lead, invited_by_user_id, responded_at)
    VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)
  ");

  foreach ($participantUserIds as $participantUserId) {
    $user = $userMap[$participantUserId];
    $roleLabel = trim((string)($participantRoleLabels[$participantUserId] ?? ''));
    if ($roleLabel === '') {
      $roleLabel = $participantUserId === $leadUserId ? 'Lead' : 'Contributor';
    }
    $isLead = $participantUserId === $leadUserId ? 1 : 0;
    $priorStatus = strtoupper((string)($previousParticipantStatus[$participantUserId] ?? ''));
    $status = $participantUserId === $actorUserId || $priorStatus === 'ACTIVE' ? 'ACTIVE' : 'INVITED';
    if ($priorStatus === 'DECLINED') {
      $status = 'INVITED';
    }
    $respondedAt = $status === 'ACTIVE' ? date('Y-m-d H:i:s') : null;
    $divisionId = (int)($user['division_id'] ?? 0);
    $sectionId = (int)($user['section_id'] ?? 0);
    $divisionId = $divisionId > 0 ? $divisionId : null;
    $sectionId = $sectionId > 0 ? $sectionId : null;
    $insertParticipant->bind_param(
      'iiiissiis',
      $taskId,
      $participantUserId,
      $divisionId,
      $sectionId,
      $roleLabel,
      $status,
      $isLead,
      $actorUserId,
      $respondedAt
    );
    $insertParticipant->execute();
  }
  $insertParticipant->close();

  tms_log_activity(
    $conn,
    $taskId,
    $actorUserId,
    $existing ? 'task_updated' : 'task_created',
    $existing ? 'Task updated.' : 'Task created.',
    $existing ? ['title' => (string)($existing['title'] ?? ''), 'status' => (string)($existing['lifecycle_status'] ?? '')] : null,
    ['title' => $title, 'workflow_template_id' => $workflowTemplateId, 'participants' => $participantUserIds]
  );

  $conn->commit();
} catch (Throwable $e) {
  $conn->rollback();
  error_log('TMS task save failed: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to save task.']);
  exit;
}

echo json_encode([
  'ok' => true,
  'task_id' => $taskId,
  'message' => $existing
    ? 'Task updated.'
    : ($hasInvitedOtherParticipants ? 'Task created. Invitations were recorded for added participants.' : 'Task created.'),
]);
