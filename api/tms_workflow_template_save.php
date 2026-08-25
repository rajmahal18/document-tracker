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
$templateId = (int)($_POST['id'] ?? 0);
$taskTypeId = (int)($_POST['task_type_id'] ?? 0);
$newTaskTypeName = trim((string)($_POST['new_task_type_name'] ?? ''));
$templateName = trim((string)($_POST['name'] ?? ''));
$description = tms_normalize_textarea((string)($_POST['description'] ?? ''));
$flowMode = strtolower(trim((string)($_POST['flow_mode'] ?? 'sequential')));
$stepsRaw = $_POST['steps'] ?? [];
$transitionsRaw = $_POST['transitions'] ?? [];

if ($templateName === '') {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Template name is required.']);
  exit;
}

if (!in_array($flowMode, ['sequential', 'parallel', 'mixed'], true)) {
  $flowMode = 'sequential';
}

$existingTemplate = null;
if ($templateId > 0) {
  $templateStmt = $conn->prepare("SELECT * FROM tms_workflow_templates WHERE id = ? AND is_active = 1 LIMIT 1");
  $templateStmt->bind_param('i', $templateId);
  $templateStmt->execute();
  $existingTemplate = $templateStmt->get_result()->fetch_assoc() ?: null;
  $templateStmt->close();

  if (!$existingTemplate) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Workflow template not found.']);
    exit;
  }

  if ($taskTypeId <= 0) {
    $taskTypeId = (int)($existingTemplate['task_type_id'] ?? 0);
  }
}

function tms_template_indexed_rows(mixed $raw): array
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

function tms_template_task_type_code(mysqli $conn, string $name): string
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
    if (!$stmt->get_result()->fetch_row()) {
      $stmt->close();
      return $code;
    }
    $suffix = '_' . $counter;
    $code = substr($base, 0, 64 - strlen($suffix)) . $suffix;
    $counter++;
  }
}

function tms_template_id_exists(mysqli $conn, string $table, int $id): bool
{
  if ($id <= 0 || !in_array($table, ['divisions', 'sections'], true)) {
    return false;
  }

  $stmt = $conn->prepare("SELECT 1 FROM {$table} WHERE id = ? LIMIT 1");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $exists = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  return $exists;
}

function tms_template_section_belongs_to_division(mysqli $conn, int $sectionId, int $divisionId): bool
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

if ($taskTypeId <= 0) {
  if ($newTaskTypeName === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Select a task type or enter a new task type name.']);
    exit;
  }
} else {
  $typeStmt = $conn->prepare("SELECT id FROM tms_task_types WHERE id = ? AND is_active = 1 LIMIT 1");
  $typeStmt->bind_param('i', $taskTypeId);
  $typeStmt->execute();
  $typeExists = (bool)$typeStmt->get_result()->fetch_row();
  $typeStmt->close();
  if (!$typeExists) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Task type not found.']);
    exit;
  }
}

$steps = [];
foreach (tms_template_indexed_rows($stepsRaw) as $index => $row) {
  $title = trim((string)($row['title'] ?? ''));
  $divisionId = (int)($row['division_id'] ?? 0);
  $sectionId = (int)($row['section_id'] ?? 0);
  $durationRaw = trim((string)($row['duration_working_days'] ?? ''));
  $durationDays = $durationRaw !== '' ? (int)$durationRaw : null;
  $instructions = tms_normalize_textarea((string)($row['instructions'] ?? ''));
  $placement = strtolower(trim((string)($row['placement'] ?? 'main')));
  if (!in_array($placement, ['main', 'conditional', 'optional'], true)) {
    $placement = 'main';
  }

  if ($title === '' && $divisionId <= 0 && $sectionId <= 0 && $durationDays === null && $instructions === '') {
    continue;
  }

  if ($title === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Each template step needs a title.']);
    exit;
  }

  if ($divisionId > 0 && !tms_template_id_exists($conn, 'divisions', $divisionId)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'A selected step division is invalid.']);
    exit;
  }

  if ($sectionId > 0) {
    if ($divisionId <= 0 || !tms_template_section_belongs_to_division($conn, $sectionId, $divisionId)) {
      http_response_code(422);
      echo json_encode(['ok' => false, 'error' => 'A selected step section does not belong to its division.']);
      exit;
    }
  }

  if ($durationDays !== null && $durationDays <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Step duration must be at least 1 working day when provided.']);
    exit;
  }

  $steps[] = [
    'step_order' => count($steps) + 1,
    'title' => $title,
    'instructions' => $instructions,
    'placement' => $placement,
    'division_id' => $divisionId > 0 ? $divisionId : null,
    'section_id' => $sectionId > 0 ? $sectionId : null,
    'role_label' => trim((string)($row['role_label'] ?? '')) ?: 'Lead',
    'estimated_working_minutes' => $durationDays !== null ? $durationDays * dt_work_minutes_per_day($conn) : null,
    'can_run_parallel' => $flowMode === 'parallel' || (int)($row['can_run_parallel'] ?? 0) === 1 ? 1 : 0,
    'requires_output' => (int)($row['requires_output'] ?? 0) === 1 ? 1 : 0,
    'requires_validation' => (int)($row['requires_validation'] ?? 0) === 1 ? 1 : 0,
    'is_ipcr_creditable' => (int)($row['is_ipcr_creditable'] ?? 1) === 1 ? 1 : 0,
  ];
}

if ($steps === []) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Add at least one template step.']);
  exit;
}

try {
  $ownerDivisionId = (int)($_SESSION['division_id'] ?? 0);
  $ownerSectionId = (int)($_SESSION['section_id'] ?? 0);
  $ownerDivisionId = $ownerDivisionId > 0 ? $ownerDivisionId : null;
  $ownerSectionId = $ownerSectionId > 0 ? $ownerSectionId : null;

  $conn->begin_transaction();

  if ($taskTypeId <= 0) {
    $code = tms_template_task_type_code($conn, $newTaskTypeName);
    $sortOrder = 100;
    $sortResult = $conn->query("SELECT COALESCE(MAX(sort_order), 90) + 10 AS next_sort_order FROM tms_task_types");
    if ($sortResult) {
      $sortOrder = (int)($sortResult->fetch_assoc()['next_sort_order'] ?? 100);
    }

    $typeStmt = $conn->prepare("
      INSERT INTO tms_task_types (
        code, name, description, owner_division_id, owner_section_id, default_priority,
        is_ipcr_relevant, is_active, sort_order, created_by_user_id, updated_by_user_id
      ) VALUES (?, ?, ?, ?, ?, 'normal', 1, 1, ?, ?, ?)
    ");
    $typeStmt->bind_param(
      'sssiiiii',
      $code,
      $newTaskTypeName,
      $description,
      $ownerDivisionId,
      $ownerSectionId,
      $sortOrder,
      $actorUserId,
      $actorUserId
    );
    $typeStmt->execute();
    $taskTypeId = (int)$typeStmt->insert_id;
    $typeStmt->close();
  }

  if ($existingTemplate) {
    $templateStmt = $conn->prepare("
      UPDATE tms_workflow_templates
      SET
        task_type_id = ?,
        name = ?,
        description = ?,
        flow_mode = ?,
        owner_division_id = ?,
        owner_section_id = ?,
        updated_by_user_id = ?
      WHERE id = ?
      LIMIT 1
    ");
    $templateStmt->bind_param(
      'isssiiii',
      $taskTypeId,
      $templateName,
      $description,
      $flowMode,
      $ownerDivisionId,
      $ownerSectionId,
      $actorUserId,
      $templateId
    );
    $templateStmt->execute();
    $templateStmt->close();

    $deleteTransitions = $conn->prepare("DELETE FROM tms_workflow_transitions WHERE workflow_template_id = ?");
    $deleteTransitions->bind_param('i', $templateId);
    $deleteTransitions->execute();
    $deleteTransitions->close();

    $deleteSteps = $conn->prepare("DELETE FROM tms_workflow_steps WHERE workflow_template_id = ?");
    $deleteSteps->bind_param('i', $templateId);
    $deleteSteps->execute();
    $deleteSteps->close();
  } else {
    $templateStmt = $conn->prepare("
      INSERT INTO tms_workflow_templates (
        task_type_id, name, description, flow_mode, owner_division_id, owner_section_id,
        is_default, is_active, created_by_user_id, updated_by_user_id
      ) VALUES (?, ?, ?, ?, ?, ?, 0, 1, ?, ?)
    ");
    $templateStmt->bind_param(
      'isssiiii',
      $taskTypeId,
      $templateName,
      $description,
      $flowMode,
      $ownerDivisionId,
      $ownerSectionId,
      $actorUserId,
      $actorUserId
    );
    $templateStmt->execute();
    $templateId = (int)$templateStmt->insert_id;
    $templateStmt->close();
  }

  $stepStmt = $conn->prepare("
    INSERT INTO tms_workflow_steps (
      workflow_template_id, step_order, title, instructions, default_responsible_division_id,
      default_responsible_section_id, default_role_label, estimated_working_minutes,
      can_run_parallel, requires_output, requires_validation, is_ipcr_creditable, is_completion_step
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");

  $stepIdsByOrder = [];
  $completionOrder = count($steps);
  foreach ($steps as $step) {
    if (($step['placement'] ?? 'main') === 'main') {
      $completionOrder = (int)$step['step_order'];
    }
  }
  foreach ($steps as $step) {
    $isCompletion = (int)$step['step_order'] === $completionOrder ? 1 : 0;
    $stepStmt->bind_param(
      'iissiisiiiiii',
      $templateId,
      $step['step_order'],
      $step['title'],
      $step['instructions'],
      $step['division_id'],
      $step['section_id'],
      $step['role_label'],
      $step['estimated_working_minutes'],
      $step['can_run_parallel'],
      $step['requires_output'],
      $step['requires_validation'],
      $step['is_ipcr_creditable'],
      $isCompletion
    );
    $stepStmt->execute();
    $stepIdsByOrder[(int)$step['step_order']] = (int)$stepStmt->insert_id;
  }
  $stepStmt->close();

  $transitionRows = [];
  $transitionKeys = [];
  $explicitFromOrders = [];

  $addTransitionRow = function (?int $fromOrder, int $toOrder, string $label, string $type) use (&$transitionRows, &$transitionKeys, &$explicitFromOrders, $stepIdsByOrder): void {
    if ($toOrder <= 0 || !isset($stepIdsByOrder[$toOrder])) {
      return;
    }
    if ($fromOrder !== null && $fromOrder > 0 && !isset($stepIdsByOrder[$fromOrder])) {
      return;
    }
    $fromKey = $fromOrder !== null && $fromOrder > 0 ? $fromOrder : 0;
    $key = $fromKey . '|' . $toOrder . '|' . $type;
    if (isset($transitionKeys[$key])) {
      return;
    }
    $transitionKeys[$key] = true;
    if ($fromOrder !== null && $fromOrder > 0) {
      $explicitFromOrders[$fromOrder] = true;
    }
    $transitionRows[] = [
      'from_order' => $fromOrder !== null && $fromOrder > 0 ? $fromOrder : null,
      'to_order' => $toOrder,
      'label' => $label !== '' ? $label : 'Next',
      'type' => $type,
    ];
  };

  foreach (tms_template_indexed_rows($transitionsRaw) as $row) {
    $fromOrder = (int)($row['from_step_order'] ?? 0);
    $toOrder = (int)($row['to_step_order'] ?? 0);
    $label = trim((string)($row['label'] ?? ''));
    $type = strtolower(trim((string)($row['type'] ?? 'next')));

    if ($label === '' && $fromOrder <= 0 && $toOrder <= 0) {
      continue;
    }
    if ($toOrder <= 0 || !isset($stepIdsByOrder[$toOrder])) {
      continue;
    }
    if (!in_array($type, ['next', 'approved', 'not_approved', 'rejected', 'returned', 'blocked'], true)) {
      $type = 'next';
    }

    $addTransitionRow($fromOrder > 0 ? $fromOrder : null, $toOrder, $label, $type);
  }

  $mainOrders = [];
  foreach ($steps as $step) {
    if (($step['placement'] ?? 'main') === 'main') {
      $mainOrders[] = (int)$step['step_order'];
    }
  }

  for ($index = 0, $count = count($mainOrders); $index < $count - 1; $index++) {
    $fromOrder = $mainOrders[$index];
    if (isset($explicitFromOrders[$fromOrder])) {
      continue;
    }
    $addTransitionRow($fromOrder, $mainOrders[$index + 1], 'Next', 'next');
  }

  $transitionStmt = $conn->prepare("
    INSERT INTO tms_workflow_transitions
      (workflow_template_id, from_step_id, to_step_id, transition_label, transition_type, sort_order)
    VALUES (?, ?, ?, ?, ?, ?)
  ");

  $transitionCount = 0;
  foreach ($transitionRows as $row) {
    $fromStepId = $row['from_order'] !== null ? $stepIdsByOrder[(int)$row['from_order']] : null;
    $toStepId = $stepIdsByOrder[(int)$row['to_order']];
    $label = $row['label'];
    $type = $row['type'];
    $sortOrder = (++$transitionCount) * 10;
    $transitionStmt->bind_param('iiissi', $templateId, $fromStepId, $toStepId, $label, $type, $sortOrder);
    $transitionStmt->execute();
  }
  $transitionStmt->close();

  $defaultStmt = $conn->prepare("
    UPDATE tms_task_types
    SET default_workflow_template_id = COALESCE(default_workflow_template_id, ?)
    WHERE id = ?
    LIMIT 1
  ");
  $defaultStmt->bind_param('ii', $templateId, $taskTypeId);
  $defaultStmt->execute();
  $defaultStmt->close();

  $conn->commit();
} catch (Throwable $e) {
  $conn->rollback();
  error_log('TMS workflow template save failed: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to save workflow template.']);
  exit;
}

$template = null;
foreach (tms_workflow_templates_with_details($conn, true) as $row) {
  if ((int)($row['id'] ?? 0) === $templateId) {
    $template = $row;
    break;
  }
}

echo json_encode([
  'ok' => true,
  'message' => $existingTemplate ? 'Workflow template updated.' : 'Workflow template saved.',
  'workflow_template' => $template,
  'task_types' => tms_task_types($conn, true),
]);
