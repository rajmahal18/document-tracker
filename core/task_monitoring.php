<?php
declare(strict_types=1);

require_once __DIR__ . '/working_time.php';

function tms_normalize_textarea(string $value): string
{
  return trim((string)(preg_replace("/\r\n?/", "\n", $value) ?? $value));
}

function tms_json_encode(?array $value): ?string
{
  if ($value === null) {
    return null;
  }

  return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function tms_json_decode(?string $value): array
{
  $value = trim((string)$value);
  if ($value === '') {
    return [];
  }

  $decoded = json_decode($value, true);
  return is_array($decoded) ? $decoded : [];
}

function tms_progress_percent_from_context(?string $contextJson): int
{
  $context = tms_json_decode($contextJson);
  $progress = (int)($context['progress_percent'] ?? 0);
  return max(0, min(100, $progress));
}

function tms_tables_ready(mysqli $conn): bool
{
  foreach ([
    'tms_task_types',
    'tms_workflow_templates',
    'tms_workflow_steps',
    'tms_tasks',
    'tms_task_steps',
    'tms_task_participants',
    'tms_task_activity',
  ] as $table) {
    if (!db_table_exists($conn, $table)) {
      return false;
    }
  }

  return true;
}

function tms_user_profile(mysqli $conn, int $userId): array
{
  if ($userId <= 0 || !db_table_exists($conn, 'tms_user_profiles')) {
    return [
      'can_manage_all_tasks' => 0,
      'can_edit_templates' => 0,
      'can_edit_task_types' => 0,
    ];
  }

  $stmt = $conn->prepare("
    SELECT can_manage_all_tasks, can_edit_templates, can_edit_task_types
    FROM tms_user_profiles
    WHERE user_id = ?
    LIMIT 1
  ");
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: [];
  $stmt->close();

  return [
    'can_manage_all_tasks' => (int)($row['can_manage_all_tasks'] ?? 0),
    'can_edit_templates' => (int)($row['can_edit_templates'] ?? 0),
    'can_edit_task_types' => (int)($row['can_edit_task_types'] ?? 0),
  ];
}

function tms_user_can_manage_all(mysqli $conn, int $userId): bool
{
  if (strtolower(trim((string)($_SESSION['role'] ?? ''))) === 'admin') {
    return true;
  }

  return (int)(tms_user_profile($conn, $userId)['can_manage_all_tasks'] ?? 0) === 1;
}

function tms_user_can_configure(mysqli $conn, int $userId): bool
{
  if (tms_user_can_manage_all($conn, $userId)) {
    return true;
  }

  $profile = tms_user_profile($conn, $userId);
  return (int)($profile['can_edit_templates'] ?? 0) === 1 || (int)($profile['can_edit_task_types'] ?? 0) === 1;
}

function tms_viewer_scope(): array
{
  return [
    'division_id' => (int)($_SESSION['division_id'] ?? 0),
    'section_id' => (int)($_SESSION['section_id'] ?? 0),
    'role' => strtolower(trim((string)($_SESSION['role'] ?? ''))),
    'official_title' => strtolower(trim((string)($_SESSION['official_title'] ?? ''))),
  ];
}

function tms_viewer_is_supervisor(): bool
{
  $scope = tms_viewer_scope();
  $haystack = trim($scope['role'] . ' ' . $scope['official_title']);
  foreach (['chief', 'director', 'supervisor', 'head'] as $needle) {
    if ($haystack !== '' && str_contains($haystack, $needle)) {
      return true;
    }
  }

  return false;
}

function tms_task_types(mysqli $conn, bool $activeOnly = true): array
{
  $sql = "
    SELECT
      id,
      code,
      name,
      COALESCE(description, '') AS description,
      owner_division_id,
      owner_section_id,
      default_priority,
      default_workflow_template_id,
      is_ipcr_relevant,
      is_active,
      sort_order
    FROM tms_task_types
    " . ($activeOnly ? "WHERE is_active = 1" : "") . "
    ORDER BY sort_order ASC, name ASC
  ";

  $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC) ?: [];
  foreach ($rows as &$row) {
    $row['id'] = (int)($row['id'] ?? 0);
    $row['owner_division_id'] = isset($row['owner_division_id']) ? (int)$row['owner_division_id'] : null;
    $row['owner_section_id'] = isset($row['owner_section_id']) ? (int)$row['owner_section_id'] : null;
    $row['default_workflow_template_id'] = isset($row['default_workflow_template_id']) ? (int)$row['default_workflow_template_id'] : null;
    $row['is_ipcr_relevant'] = (int)($row['is_ipcr_relevant'] ?? 0);
    $row['is_active'] = (int)($row['is_active'] ?? 0);
    $row['sort_order'] = (int)($row['sort_order'] ?? 0);
  }
  unset($row);

  return $rows;
}

function tms_workflow_templates(mysqli $conn, bool $activeOnly = true): array
{
  $averageSelect = db_table_exists($conn, 'tms_task_metrics')
    ? "(SELECT COALESCE(AVG(tm.metric_value), 0) FROM tms_task_metrics tm WHERE tm.workflow_template_id = wt.id AND tm.metric_key = 'actual_working_days')"
    : "0";

  $sql = "
    SELECT
      wt.id,
      wt.task_type_id,
      COALESCE(tt.name, '') AS task_type_name,
      wt.name,
      COALESCE(wt.description, '') AS description,
      wt.flow_mode,
      wt.owner_division_id,
      wt.owner_section_id,
      wt.is_default,
      wt.is_active,
      COALESCE(creator.full_name, '') AS created_by_name,
      COUNT(DISTINCT ws.id) AS step_count,
      COALESCE(SUM(ws.estimated_working_minutes), 0) AS estimated_working_minutes,
      {$averageSelect} AS average_working_days
    FROM tms_workflow_templates wt
    LEFT JOIN tms_task_types tt ON tt.id = wt.task_type_id
    LEFT JOIN tms_workflow_steps ws ON ws.workflow_template_id = wt.id
    LEFT JOIN users creator ON creator.id = wt.created_by_user_id
    " . ($activeOnly ? "WHERE wt.is_active = 1" : "") . "
    GROUP BY wt.id
    ORDER BY wt.is_default DESC, wt.name ASC
  ";

  $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC) ?: [];
  foreach ($rows as &$row) {
    $row['id'] = (int)($row['id'] ?? 0);
    $row['task_type_id'] = isset($row['task_type_id']) ? (int)$row['task_type_id'] : null;
    $row['owner_division_id'] = isset($row['owner_division_id']) ? (int)$row['owner_division_id'] : null;
    $row['owner_section_id'] = isset($row['owner_section_id']) ? (int)$row['owner_section_id'] : null;
    $row['is_default'] = (int)($row['is_default'] ?? 0);
    $row['is_active'] = (int)($row['is_active'] ?? 0);
    $row['step_count'] = (int)($row['step_count'] ?? 0);
    $row['estimated_working_minutes'] = (int)($row['estimated_working_minutes'] ?? 0);
    $averageDays = (float)($row['average_working_days'] ?? 0);
    $row['average_working_days'] = $averageDays > 0 ? round($averageDays, 1) : null;
  }
  unset($row);

  return $rows;
}

function tms_workflow_steps(mysqli $conn, int $templateId): array
{
  if ($templateId <= 0) {
    return [];
  }

  $stmt = $conn->prepare("
    SELECT
      ws.*,
      COALESCE(d.name, '') AS responsible_division_name,
      COALESCE(s.name, '') AS responsible_section_name
    FROM tms_workflow_steps ws
    LEFT JOIN divisions d ON d.id = ws.default_responsible_division_id
    LEFT JOIN sections s ON s.id = ws.default_responsible_section_id
    WHERE ws.workflow_template_id = ?
    ORDER BY ws.step_order ASC, ws.id ASC
  ");
  $stmt->bind_param('i', $templateId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();

  foreach ($rows as &$row) {
    $row['id'] = (int)($row['id'] ?? 0);
    $row['workflow_template_id'] = (int)($row['workflow_template_id'] ?? 0);
    $row['step_order'] = (int)($row['step_order'] ?? 0);
    $row['default_responsible_division_id'] = isset($row['default_responsible_division_id']) ? (int)$row['default_responsible_division_id'] : null;
    $row['default_responsible_section_id'] = isset($row['default_responsible_section_id']) ? (int)$row['default_responsible_section_id'] : null;
    $row['estimated_working_minutes'] = isset($row['estimated_working_minutes']) ? (int)$row['estimated_working_minutes'] : null;
    $row['can_run_parallel'] = (int)($row['can_run_parallel'] ?? 0);
    $row['requires_output'] = (int)($row['requires_output'] ?? 0);
    $row['requires_validation'] = (int)($row['requires_validation'] ?? 0);
    $row['is_ipcr_creditable'] = (int)($row['is_ipcr_creditable'] ?? 0);
    $row['is_completion_step'] = (int)($row['is_completion_step'] ?? 0);
  }
  unset($row);

  return $rows;
}

function tms_workflow_transitions(mysqli $conn, int $templateId): array
{
  if ($templateId <= 0 || !db_table_exists($conn, 'tms_workflow_transitions')) {
    return [];
  }

  $stmt = $conn->prepare("
    SELECT
      tr.*,
      from_step.step_order AS from_step_order,
      to_step.step_order AS to_step_order
    FROM tms_workflow_transitions tr
    LEFT JOIN tms_workflow_steps from_step ON from_step.id = tr.from_step_id
    LEFT JOIN tms_workflow_steps to_step ON to_step.id = tr.to_step_id
    WHERE tr.workflow_template_id = ?
    ORDER BY tr.sort_order ASC, tr.id ASC
  ");
  $stmt->bind_param('i', $templateId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();

  foreach ($rows as &$row) {
    foreach (['id', 'workflow_template_id', 'from_step_id', 'to_step_id', 'sort_order', 'from_step_order', 'to_step_order'] as $key) {
      $row[$key] = isset($row[$key]) ? (int)$row[$key] : null;
    }
  }
  unset($row);

  return $rows;
}

function tms_workflow_templates_with_details(mysqli $conn, bool $activeOnly = true): array
{
  $templates = tms_workflow_templates($conn, $activeOnly);
  foreach ($templates as &$template) {
    $templateId = (int)($template['id'] ?? 0);
    $template['steps'] = tms_workflow_steps($conn, $templateId);
    $template['transitions'] = tms_workflow_transitions($conn, $templateId);
  }
  unset($template);

  return $templates;
}

function tms_role_presets(mysqli $conn): array
{
  if (!db_table_exists($conn, 'tms_participant_role_presets')) {
    return [];
  }

  return $conn->query("
    SELECT id, role_label, COALESCE(description, '') AS description, sort_order
    FROM tms_participant_role_presets
    WHERE is_active = 1
    ORDER BY sort_order ASC, role_label ASC
  ")->fetch_all(MYSQLI_ASSOC) ?: [];
}

function tms_divisions(mysqli $conn): array
{
  $rows = $conn->query("
    SELECT id, name
    FROM divisions
    ORDER BY name ASC
  ")->fetch_all(MYSQLI_ASSOC) ?: [];

  foreach ($rows as &$row) {
    $row['id'] = (int)($row['id'] ?? 0);
    $row['name'] = (string)($row['name'] ?? '');
  }
  unset($row);

  return $rows;
}

function tms_sections(mysqli $conn): array
{
  $rows = $conn->query("
    SELECT s.id, s.name, s.division_id, COALESCE(d.name, '') AS division_name
    FROM sections s
    LEFT JOIN divisions d ON d.id = s.division_id
    ORDER BY d.name ASC, s.name ASC
  ")->fetch_all(MYSQLI_ASSOC) ?: [];

  foreach ($rows as &$row) {
    $row['id'] = (int)($row['id'] ?? 0);
    $row['division_id'] = isset($row['division_id']) ? (int)$row['division_id'] : null;
    $row['name'] = (string)($row['name'] ?? '');
    $row['division_name'] = (string)($row['division_name'] ?? '');
  }
  unset($row);

  return $rows;
}

function tms_fetch_users_for_assignment(mysqli $conn): array
{
  $hasOfficialTitle = db_column_exists($conn, 'users', 'official_title');
  $photoColumn = null;
  foreach (['profile_photo_url', 'avatar_url', 'photo_url'] as $candidate) {
    if (db_column_exists($conn, 'users', $candidate)) {
      $photoColumn = $candidate;
      break;
    }
  }

  $sql = "
    SELECT
      u.id,
      u.full_name,
      COALESCE(u.username, '') AS username,
      COALESCE(u.email, '') AS email,
      " . ($photoColumn !== null ? "COALESCE(u.`" . $conn->real_escape_string($photoColumn) . "`, '')" : "''") . " AS profile_photo_url,
      u.section_id,
      s.division_id,
      COALESCE(s.name, '') AS section_name,
      COALESCE(d.name, '') AS division_name,
      " . ($hasOfficialTitle ? "COALESCE(u.official_title, '')" : "''") . " AS official_title
    FROM users u
    LEFT JOIN sections s ON s.id = u.section_id
    LEFT JOIN divisions d ON d.id = s.division_id
    WHERE u.is_active = 1
    ORDER BY u.full_name ASC
  ";

  $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC) ?: [];
  foreach ($rows as &$row) {
    $row['id'] = (int)($row['id'] ?? 0);
    $row['section_id'] = isset($row['section_id']) ? (int)$row['section_id'] : null;
    $row['division_id'] = isset($row['division_id']) ? (int)$row['division_id'] : null;
    $name = (string)($row['full_name'] ?? '');
    $row['profile_photo_url'] = function_exists('app_profile_photo_url')
      ? app_profile_photo_url((string)($row['profile_photo_url'] ?? ''))
      : trim((string)($row['profile_photo_url'] ?? ''));
    $row['avatar_initials'] = function_exists('app_user_initials')
      ? app_user_initials($name)
      : strtoupper(substr($name !== '' ? $name : 'U', 0, 1));
  }
  unset($row);

  return $rows;
}

function tms_fetch_user_map(mysqli $conn, array $userIds): array
{
  $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
  if ($userIds === []) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($userIds), '?'));
  $stmt = $conn->prepare("
    SELECT
      u.id,
      u.full_name,
      u.section_id,
      s.division_id
    FROM users u
    LEFT JOIN sections s ON s.id = u.section_id
    WHERE u.id IN ($placeholders)
      AND u.is_active = 1
  ");
  $stmt->bind_param(str_repeat('i', count($userIds)), ...$userIds);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();

  $map = [];
  foreach ($rows as $row) {
    $map[(int)$row['id']] = [
      'id' => (int)$row['id'],
      'full_name' => (string)$row['full_name'],
      'section_id' => isset($row['section_id']) ? (int)$row['section_id'] : null,
      'division_id' => isset($row['division_id']) ? (int)$row['division_id'] : null,
    ];
  }

  return $map;
}

function tms_working_days_label(mysqli $conn, ?string $startRaw, ?string $endRaw): string
{
  if (!$startRaw || !$endRaw) {
    return 'No timing data';
  }

  $days = dt_working_days_between_ceil($startRaw, $endRaw, $conn);
  return $days === 1 ? '1 working day' : $days . ' working days';
}

function tms_task_timing_state(mysqli $conn, array $task): array
{
  $status = strtoupper(trim((string)($task['lifecycle_status'] ?? 'OPEN')));
  $completedAt = trim((string)($task['completed_at'] ?? ''));
  $startedAt = trim((string)($task['started_at'] ?? '')) ?: trim((string)($task['created_at'] ?? ''));
  $dueAt = trim((string)($task['target_due_at'] ?? ''));

  if (in_array($status, ['COMPLETED', 'CANCELLED'], true) && $completedAt !== '') {
    return [
      'tone' => 'done',
      'label' => 'Completed in ' . tms_working_days_label($conn, $startedAt, $completedAt),
      'days' => dt_working_days_between_ceil($startedAt, $completedAt, $conn),
    ];
  }

  if ($dueAt === '') {
    return ['tone' => 'open', 'label' => 'Indefinite timeline', 'days' => null];
  }

  try {
    $calendar = dt_work_calendar($conn);
    $now = new DateTimeImmutable('now', dt_work_timezone($calendar));
    $due = new DateTimeImmutable($dueAt, dt_work_timezone($calendar));
  } catch (Throwable) {
    return ['tone' => 'open', 'label' => 'Indefinite timeline', 'days' => null];
  }

  if ($now > $due) {
    $days = dt_working_days_between_ceil($dueAt, $now->format('Y-m-d H:i:s'), $conn);
    return [
      'tone' => 'overdue',
      'label' => $days . ($days === 1 ? ' working day overdue' : ' working days overdue'),
      'days' => -$days,
    ];
  }

  $days = dt_working_days_between_ceil($now->format('Y-m-d H:i:s'), $dueAt, $conn);
  return [
    'tone' => $days <= 3 ? 'soon' : 'open',
    'label' => $days . ($days === 1 ? ' working day left' : ' working days left'),
    'days' => $days,
  ];
}

function tms_user_can_view_task(mysqli $conn, array $task, int $userId): bool
{
  if ($userId <= 0) {
    return false;
  }

  if (tms_user_can_manage_all($conn, $userId)) {
    return true;
  }

  if ((int)($task['created_by_user_id'] ?? 0) === $userId) {
    return true;
  }

  if ((int)($task['viewer_is_participant'] ?? 0) === 1) {
    return true;
  }

  if ((int)($task['viewer_in_step_scope'] ?? 0) === 1 && tms_viewer_is_supervisor()) {
    return true;
  }

  if (!tms_viewer_is_supervisor()) {
    return false;
  }

  $scope = tms_viewer_scope();
  $taskDivisionId = (int)($task['owner_division_id'] ?? 0);
  $taskSectionId = (int)($task['owner_section_id'] ?? 0);

  if ($scope['section_id'] > 0 && $taskSectionId === $scope['section_id']) {
    return true;
  }

  return $scope['division_id'] > 0 && $taskDivisionId === $scope['division_id'];
}

function tms_task_permissions(mysqli $conn, array $task, int $userId): array
{
  $manageAll = $userId > 0 && tms_user_can_manage_all($conn, $userId);
  $isCreator = (int)($task['created_by_user_id'] ?? 0) === $userId;
  $isParticipant = (int)($task['viewer_is_participant'] ?? 0) === 1;
  $isLead = (int)($task['viewer_is_lead'] ?? 0) === 1;
  $isSupervisor = tms_user_can_view_task($conn, $task, $userId) && tms_viewer_is_supervisor();
  $canEdit = $manageAll || $isCreator || $isLead || $isParticipant;

  return [
    'can_view_task' => tms_user_can_view_task($conn, $task, $userId),
    'can_edit_task' => $canEdit,
    'can_delete_task' => $manageAll || $isCreator,
    'can_supervise_task' => $manageAll || $isSupervisor,
    'is_creator' => $isCreator,
    'is_participant' => $isParticipant,
    'is_lead' => $isLead,
    'is_invited' => (int)($task['viewer_is_invited'] ?? 0) === 1,
  ];
}

function tms_fetch_tasks(mysqli $conn, array $filters, int $viewerUserId): array
{
  $manageAll = tms_user_can_manage_all($conn, $viewerUserId);
  $viewMode = strtolower(trim((string)($filters['view_mode'] ?? 'my')));
  if (!in_array($viewMode, ['my', 'section', 'division', 'all'], true)) {
    $viewMode = 'my';
  }
  if ($viewMode === 'all' && !$manageAll) {
    $viewMode = 'division';
  }

  $typeCode = trim((string)($filters['type_code'] ?? ''));
  $status = strtoupper(trim((string)($filters['status'] ?? '')));
  $search = trim((string)($filters['q'] ?? ''));
  $scope = tms_viewer_scope();

  $where = [];
  $params = [$viewerUserId, $viewerUserId, $viewerUserId];
  $types = 'iii';

  if ($typeCode !== '') {
    $where[] = 'tt.code = ?';
    $params[] = $typeCode;
    $types .= 's';
  }

  if ($status !== '') {
    $where[] = 't.lifecycle_status = ?';
    $params[] = $status;
    $types .= 's';
  }

  if ($search !== '') {
    $where[] = "(t.title LIKE CONCAT('%', ?, '%') OR COALESCE(t.description, '') LIKE CONCAT('%', ?, '%') OR tt.name LIKE CONCAT('%', ?, '%') OR COALESCE(wt.name, '') LIKE CONCAT('%', ?, '%'))";
    array_push($params, $search, $search, $search, $search);
    $types .= 'ssss';
  }

  if ($viewMode === 'my') {
    $where[] = '(
      t.created_by_user_id = ?
      OR EXISTS (
        SELECT 1 FROM tms_task_participants tvp
        WHERE tvp.task_id = t.id
          AND tvp.user_id = ?
          AND tvp.participation_status IN ("INVITED", "ACTIVE")
      )
      OR (
        ? = 1
        AND EXISTS (
          SELECT 1 FROM tms_task_steps tss
          WHERE tss.task_id = t.id
            AND tss.responsible_user_id IS NULL
            AND (
              (? > 0 AND tss.responsible_section_id = ?)
              OR (? > 0 AND tss.responsible_division_id = ?)
            )
        )
      )
    )';
    $supervisorInt = tms_viewer_is_supervisor() ? 1 : 0;
    array_push($params, $viewerUserId, $viewerUserId, $supervisorInt, $scope['section_id'], $scope['section_id'], $scope['division_id'], $scope['division_id']);
    $types .= 'iiiiiii';
  } elseif ($viewMode === 'section') {
    $where[] = '(
      t.owner_section_id = ?
      OR EXISTS (SELECT 1 FROM tms_task_participants tsp WHERE tsp.task_id = t.id AND tsp.section_id = ?)
      OR EXISTS (SELECT 1 FROM tms_task_steps tss WHERE tss.task_id = t.id AND tss.responsible_section_id = ?)
    )';
    array_push($params, $scope['section_id'], $scope['section_id'], $scope['section_id']);
    $types .= 'iii';
  } elseif ($viewMode === 'division') {
    $where[] = '(
      t.owner_division_id = ?
      OR EXISTS (SELECT 1 FROM tms_task_participants tdp WHERE tdp.task_id = t.id AND tdp.division_id = ?)
      OR EXISTS (SELECT 1 FROM tms_task_steps tds WHERE tds.task_id = t.id AND tds.responsible_division_id = ?)
    )';
    array_push($params, $scope['division_id'], $scope['division_id'], $scope['division_id']);
    $types .= 'iii';
  }

  $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
  $sql = "
    SELECT
      t.*,
      tt.code AS task_type_code,
      tt.name AS task_type_name,
      COALESCE(wt.name, '') AS workflow_template_name,
      COALESCE(wt.flow_mode, t.flow_mode) AS workflow_flow_mode,
      COALESCE(cs.title, '') AS current_step_title,
      COALESCE(cs.status, '') AS current_step_status,
      COALESCE(cs.role_label, '') AS current_role_label,
      COALESCE(cu.full_name, '') AS current_responsible_name,
      COALESCE(cd.name, '') AS current_responsible_division_name,
      COALESCE(csec.name, '') AS current_responsible_section_name,
      COALESCE(creator.full_name, '') AS created_by_name,
      COALESCE(od.name, '') AS owner_division_name,
      COALESCE(os.name, '') AS owner_section_name,
      MAX(CASE WHEN vp.user_id = ? AND vp.participation_status IN ('INVITED', 'ACTIVE') THEN 1 ELSE 0 END) AS viewer_is_participant,
      MAX(CASE WHEN vp.user_id = ? AND vp.is_lead = 1 AND vp.participation_status = 'ACTIVE' THEN 1 ELSE 0 END) AS viewer_is_lead,
      MAX(CASE WHEN vp.user_id = ? AND vp.participation_status = 'INVITED' THEN 1 ELSE 0 END) AS viewer_is_invited,
      MAX(CASE WHEN tsscope.responsible_user_id IS NULL AND ((? > 0 AND tsscope.responsible_section_id = ?) OR (? > 0 AND tsscope.responsible_division_id = ?)) THEN 1 ELSE 0 END) AS viewer_in_step_scope,
      GROUP_CONCAT(DISTINCT CONCAT(tp.participant_role_label, ': ', pu.full_name, ' (', tp.participation_status, ')') ORDER BY tp.is_lead DESC, tp.id ASC SEPARATOR ', ') AS participants_text
    FROM tms_tasks t
    JOIN tms_task_types tt ON tt.id = t.task_type_id
    LEFT JOIN tms_workflow_templates wt ON wt.id = t.workflow_template_id
    LEFT JOIN tms_task_steps cs ON cs.id = t.current_step_id
    LEFT JOIN users cu ON cu.id = cs.responsible_user_id
    LEFT JOIN divisions cd ON cd.id = cs.responsible_division_id
    LEFT JOIN sections csec ON csec.id = cs.responsible_section_id
    LEFT JOIN users creator ON creator.id = t.created_by_user_id
    LEFT JOIN divisions od ON od.id = t.owner_division_id
    LEFT JOIN sections os ON os.id = t.owner_section_id
    LEFT JOIN tms_task_participants vp ON vp.task_id = t.id AND vp.user_id = ?
    LEFT JOIN tms_task_participants tp ON tp.task_id = t.id
    LEFT JOIN users pu ON pu.id = tp.user_id
    LEFT JOIN tms_task_steps tsscope ON tsscope.task_id = t.id
    {$whereSql}
    GROUP BY t.id
    ORDER BY
      CASE
        WHEN t.lifecycle_status IN ('COMPLETED', 'CANCELLED') THEN 4
        WHEN t.target_due_at IS NOT NULL AND t.target_due_at < NOW() THEN 0
        WHEN t.lifecycle_status = 'BLOCKED' THEN 1
        ELSE 2
      END ASC,
      t.target_due_at IS NULL ASC,
      t.target_due_at ASC,
      t.updated_at DESC
  ";

  array_splice($params, 3, 0, [$scope['section_id'], $scope['section_id'], $scope['division_id'], $scope['division_id'], $viewerUserId]);
  $types = 'iiiiiiii' . substr($types, 3);

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();

  foreach ($rows as &$row) {
    tms_cast_task_row($row);
    $timing = tms_task_timing_state($conn, $row);
    $row['timing_tone'] = $timing['tone'];
    $row['timing_label'] = $timing['label'];
    $row['timing_days'] = $timing['days'];
    $row['progress_percent'] = tms_progress_percent_from_context($row['context_json'] ?? null);
    $permissions = tms_task_permissions($conn, $row, $viewerUserId);
    $row['can_edit'] = $permissions['can_edit_task'];
    $row['can_delete'] = $permissions['can_delete_task'];
  }
  unset($row);

  return $rows;
}

function tms_cast_task_row(array &$row): void
{
  foreach (['id', 'task_type_id', 'workflow_template_id', 'current_step_id', 'project_id', 'document_id', 'created_by_user_id', 'updated_by_user_id', 'owner_division_id', 'owner_section_id', 'estimated_working_minutes', 'actual_working_minutes'] as $key) {
    $row[$key] = isset($row[$key]) ? (int)$row[$key] : null;
  }

  foreach (['viewer_is_participant', 'viewer_is_lead', 'viewer_is_invited', 'viewer_in_step_scope'] as $key) {
    $row[$key] = (int)($row[$key] ?? 0);
  }
}

function tms_fetch_task(mysqli $conn, int $taskId): ?array
{
  if ($taskId <= 0) {
    return null;
  }

  $stmt = $conn->prepare("
    SELECT
      t.*,
      tt.code AS task_type_code,
      tt.name AS task_type_name,
      COALESCE(wt.name, '') AS workflow_template_name,
      COALESCE(wt.flow_mode, t.flow_mode) AS workflow_flow_mode,
      MAX(CASE WHEN vp.user_id = ? AND vp.participation_status IN ('INVITED', 'ACTIVE') THEN 1 ELSE 0 END) AS viewer_is_participant,
      MAX(CASE WHEN vp.user_id = ? AND vp.is_lead = 1 AND vp.participation_status = 'ACTIVE' THEN 1 ELSE 0 END) AS viewer_is_lead,
      MAX(CASE WHEN vp.user_id = ? AND vp.participation_status = 'INVITED' THEN 1 ELSE 0 END) AS viewer_is_invited,
      MAX(CASE WHEN tsscope.responsible_user_id IS NULL AND ((? > 0 AND tsscope.responsible_section_id = ?) OR (? > 0 AND tsscope.responsible_division_id = ?)) THEN 1 ELSE 0 END) AS viewer_in_step_scope
    FROM tms_tasks t
    JOIN tms_task_types tt ON tt.id = t.task_type_id
    LEFT JOIN tms_workflow_templates wt ON wt.id = t.workflow_template_id
    LEFT JOIN tms_task_participants vp ON vp.task_id = t.id AND vp.user_id = ?
    LEFT JOIN tms_task_steps tsscope ON tsscope.task_id = t.id
    WHERE t.id = ?
    GROUP BY t.id
    LIMIT 1
  ");
  $viewerUserId = (int)($_SESSION['user_id'] ?? 0);
  $scope = tms_viewer_scope();
  $stmt->bind_param(
    'iiiiiiiii',
    $viewerUserId,
    $viewerUserId,
    $viewerUserId,
    $scope['section_id'],
    $scope['section_id'],
    $scope['division_id'],
    $scope['division_id'],
    $viewerUserId,
    $taskId
  );
  $stmt->execute();
  $task = $stmt->get_result()->fetch_assoc() ?: null;
  $stmt->close();

  if (!$task) {
    return null;
  }

  tms_cast_task_row($task);
  $task['context'] = tms_json_decode($task['context_json'] ?? null);
  $task['progress_percent'] = tms_progress_percent_from_context($task['context_json'] ?? null);
  $task['ipcr_metadata'] = tms_json_decode($task['ipcr_metadata_json'] ?? null);
  $task['timing'] = tms_task_timing_state($conn, $task);
  $task['steps'] = tms_fetch_task_steps($conn, $taskId);
  $task['participants'] = tms_fetch_task_participants($conn, $taskId);
  $task['outputs'] = tms_fetch_task_outputs($conn, $taskId);

  return $task;
}

function tms_fetch_task_steps(mysqli $conn, int $taskId): array
{
  $stmt = $conn->prepare("
    SELECT
      ts.*,
      COALESCE(u.full_name, '') AS responsible_user_name,
      COALESCE(d.name, '') AS responsible_division_name,
      COALESCE(s.name, '') AS responsible_section_name
    FROM tms_task_steps ts
    LEFT JOIN users u ON u.id = ts.responsible_user_id
    LEFT JOIN divisions d ON d.id = ts.responsible_division_id
    LEFT JOIN sections s ON s.id = ts.responsible_section_id
    WHERE ts.task_id = ?
    ORDER BY ts.step_order ASC, ts.id ASC
  ");
  $stmt->bind_param('i', $taskId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();

  foreach ($rows as &$row) {
    foreach (['id', 'task_id', 'workflow_step_id', 'step_order', 'responsible_division_id', 'responsible_section_id', 'responsible_user_id', 'estimated_working_minutes', 'actual_working_minutes'] as $key) {
      $row[$key] = isset($row[$key]) ? (int)$row[$key] : null;
    }
    foreach (['can_run_parallel', 'requires_output', 'requires_validation', 'is_ipcr_creditable', 'is_completion_step'] as $key) {
      $row[$key] = (int)($row[$key] ?? 0);
    }
  }
  unset($row);

  return $rows;
}

function tms_fetch_task_participants(mysqli $conn, int $taskId): array
{
  $stmt = $conn->prepare("
    SELECT
      tp.*,
      COALESCE(u.full_name, '') AS full_name,
      COALESCE(s.name, '') AS section_name,
      COALESCE(d.name, '') AS division_name
    FROM tms_task_participants tp
    JOIN users u ON u.id = tp.user_id
    LEFT JOIN sections s ON s.id = tp.section_id
    LEFT JOIN divisions d ON d.id = tp.division_id
    WHERE tp.task_id = ?
    ORDER BY tp.is_lead DESC, tp.id ASC
  ");
  $stmt->bind_param('i', $taskId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();

  foreach ($rows as &$row) {
    foreach (['id', 'task_id', 'task_step_id', 'user_id', 'division_id', 'section_id', 'invited_by_user_id'] as $key) {
      $row[$key] = isset($row[$key]) ? (int)$row[$key] : null;
    }
    $row['is_lead'] = (int)($row['is_lead'] ?? 0);
  }
  unset($row);

  return $rows;
}

function tms_fetch_task_outputs(mysqli $conn, int $taskId): array
{
  if (!db_table_exists($conn, 'tms_task_outputs')) {
    return [];
  }

  $stmt = $conn->prepare("
    SELECT
      o.*,
      COALESCE(u.full_name, '') AS uploaded_by_name
    FROM tms_task_outputs o
    LEFT JOIN users u ON u.id = o.uploaded_by_user_id
    WHERE o.task_id = ?
    ORDER BY o.created_at DESC, o.id DESC
  ");
  $stmt->bind_param('i', $taskId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();

  return $rows;
}

function tms_log_activity(mysqli $conn, int $taskId, ?int $actorUserId, string $action, ?string $message, ?array $oldValues = null, ?array $newValues = null, ?int $taskStepId = null): void
{
  $oldJson = tms_json_encode($oldValues);
  $newJson = tms_json_encode($newValues);

  $stmt = $conn->prepare("
    INSERT INTO tms_task_activity
      (task_id, task_step_id, actor_user_id, action, message, old_values_json, new_values_json)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  ");
  $stmt->bind_param('iiissss', $taskId, $taskStepId, $actorUserId, $action, $message, $oldJson, $newJson);
  $stmt->execute();
  $stmt->close();
}
