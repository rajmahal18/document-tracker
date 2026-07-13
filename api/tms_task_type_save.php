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
$name = trim((string)($_POST['name'] ?? ''));
$description = tms_normalize_textarea((string)($_POST['description'] ?? ''));
$defaultPriority = strtolower(trim((string)($_POST['default_priority'] ?? 'normal')));

if ($name === '') {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Task type name is required.']);
  exit;
}

if (strlen($name) > 150) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Task type name is too long.']);
  exit;
}

if (!in_array($defaultPriority, ['low', 'normal', 'high', 'urgent'], true)) {
  $defaultPriority = 'normal';
}

function tms_task_type_code_from_name(mysqli $conn, string $name): string
{
  $base = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/', '_', $name), '_'));
  if ($base === '') {
    $base = 'task_type';
  }
  $base = substr($base, 0, 54);
  $code = $base;
  $counter = 2;

  $stmt = $conn->prepare("SELECT 1 FROM tms_task_types WHERE code = ? LIMIT 1");
  while (true) {
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    if (!$exists) {
      $stmt->close();
      return $code;
    }
    $suffix = '_' . $counter;
    $code = substr($base, 0, 64 - strlen($suffix)) . $suffix;
    $counter++;
  }
}

try {
  $ownerDivisionId = (int)($_SESSION['division_id'] ?? 0);
  $ownerSectionId = (int)($_SESSION['section_id'] ?? 0);
  $ownerDivisionId = $ownerDivisionId > 0 ? $ownerDivisionId : null;
  $ownerSectionId = $ownerSectionId > 0 ? $ownerSectionId : null;
  $code = tms_task_type_code_from_name($conn, $name);

  $sortOrder = 100;
  $sortResult = $conn->query("SELECT COALESCE(MAX(sort_order), 90) + 10 AS next_sort_order FROM tms_task_types");
  if ($sortResult) {
    $sortOrder = (int)($sortResult->fetch_assoc()['next_sort_order'] ?? 100);
  }

  $conn->begin_transaction();

  $typeStmt = $conn->prepare("
    INSERT INTO tms_task_types (
      code,
      name,
      description,
      owner_division_id,
      owner_section_id,
      default_priority,
      is_ipcr_relevant,
      is_active,
      sort_order,
      created_by_user_id,
      updated_by_user_id
    ) VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?, ?, ?)
  ");
  $typeStmt->bind_param(
    'sssiisiii',
    $code,
    $name,
    $description,
    $ownerDivisionId,
    $ownerSectionId,
    $defaultPriority,
    $sortOrder,
    $actorUserId,
    $actorUserId
  );
  $typeStmt->execute();
  $taskTypeId = (int)$typeStmt->insert_id;
  $typeStmt->close();

  $workflowName = 'Basic Workflow';
  $workflowDescription = 'Default workflow for this task type. Adjust the workflow design later when needed.';
  $flowMode = 'sequential';
  $workflowStmt = $conn->prepare("
    INSERT INTO tms_workflow_templates (
      task_type_id,
      name,
      description,
      flow_mode,
      owner_division_id,
      owner_section_id,
      is_default,
      is_active,
      created_by_user_id,
      updated_by_user_id
    ) VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?, ?)
  ");
  $workflowStmt->bind_param(
    'isssiiii',
    $taskTypeId,
    $workflowName,
    $workflowDescription,
    $flowMode,
    $ownerDivisionId,
    $ownerSectionId,
    $actorUserId,
    $actorUserId
  );
  $workflowStmt->execute();
  $workflowTemplateId = (int)$workflowStmt->insert_id;
  $workflowStmt->close();

  $stepStmt = $conn->prepare("
    INSERT INTO tms_workflow_steps (
      workflow_template_id,
      step_order,
      title,
      instructions,
      default_responsible_division_id,
      default_responsible_section_id,
      default_role_label,
      estimated_working_minutes,
      can_run_parallel,
      requires_output,
      requires_validation,
      is_ipcr_creditable,
      is_completion_step
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");

  $stepOrder = 1;
  $stepTitle = 'Do work';
  $stepInstructions = 'Complete the assigned work and add output or remarks when needed.';
  $roleLabel = 'Lead';
  $estimatedMinutes = 480;
  $canParallel = 0;
  $requiresOutput = 0;
  $requiresValidation = 0;
  $isIpcrCreditable = 1;
  $isCompletion = 1;
  $stepStmt->bind_param(
    'iissiisiiiiii',
    $workflowTemplateId,
    $stepOrder,
    $stepTitle,
    $stepInstructions,
    $ownerDivisionId,
    $ownerSectionId,
    $roleLabel,
    $estimatedMinutes,
    $canParallel,
    $requiresOutput,
    $requiresValidation,
    $isIpcrCreditable,
    $isCompletion
  );
  $stepStmt->execute();
  $stepStmt->close();

  $updateStmt = $conn->prepare("UPDATE tms_task_types SET default_workflow_template_id = ? WHERE id = ? LIMIT 1");
  $updateStmt->bind_param('ii', $workflowTemplateId, $taskTypeId);
  $updateStmt->execute();
  $updateStmt->close();

  $conn->commit();
} catch (Throwable $e) {
  $conn->rollback();
  error_log('TMS task type save failed: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to save task type.']);
  exit;
}

$taskType = null;
foreach (tms_task_types($conn, true) as $row) {
  if ((int)($row['id'] ?? 0) === $taskTypeId) {
    $taskType = $row;
    break;
  }
}

$workflowTemplate = null;
foreach (tms_workflow_templates($conn, true) as $row) {
  if ((int)($row['id'] ?? 0) === $workflowTemplateId) {
    $workflowTemplate = $row;
    break;
  }
}

echo json_encode([
  'ok' => true,
  'message' => 'Task type created.',
  'task_type' => $taskType,
  'workflow_template' => $workflowTemplate,
]);
