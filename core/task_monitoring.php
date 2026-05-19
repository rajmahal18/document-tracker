<?php
declare(strict_types=1);

require_once __DIR__ . '/working_time.php';

function tms_task_types(mysqli $conn, bool $activeOnly = true): array
{
  $sql = "
    SELECT
      id,
      code,
      name,
      COALESCE(scope_code, '') AS scope_code,
      workflow_rule,
      assignment_role_label,
      COALESCE(reference_label, '') AS reference_label,
      allow_multi_assignees,
      show_date_surveyed,
      show_date_received,
      show_date_started,
      show_target_completion,
      show_progress,
      show_reference_code,
      sort_order,
      is_active
    FROM tms_task_types
    " . ($activeOnly ? "WHERE is_active = 1" : "") . "
    ORDER BY sort_order ASC, name ASC
  ";

  $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC) ?: [];
  foreach ($rows as &$row) {
    $row['id'] = (int)($row['id'] ?? 0);
    $row['allow_multi_assignees'] = (int)($row['allow_multi_assignees'] ?? 0);
    $row['show_date_surveyed'] = (int)($row['show_date_surveyed'] ?? 0);
    $row['show_date_received'] = (int)($row['show_date_received'] ?? 0);
    $row['show_date_started'] = (int)($row['show_date_started'] ?? 0);
    $row['show_target_completion'] = (int)($row['show_target_completion'] ?? 0);
    $row['show_progress'] = (int)($row['show_progress'] ?? 0);
    $row['show_reference_code'] = (int)($row['show_reference_code'] ?? 0);
    $row['sort_order'] = (int)($row['sort_order'] ?? 0);
    $row['is_active'] = (int)($row['is_active'] ?? 0);
  }
  unset($row);

  return $rows;
}

function tms_task_type_map(mysqli $conn, bool $activeOnly = false): array
{
  $map = [];
  foreach (tms_task_types($conn, $activeOnly) as $row) {
    $map[(string)$row['code']] = $row;
    $map['id:' . (int)$row['id']] = $row;
  }
  return $map;
}

function tms_user_profile(mysqli $conn, int $userId): array
{
  if ($userId <= 0 || !db_table_exists($conn, 'tms_user_profiles')) {
    return [
      'scope_code' => '',
      'can_manage_all_tasks' => 0,
      'can_edit_protected_fields' => 0,
    ];
  }

  $stmt = $conn->prepare("
    SELECT
      COALESCE(scope_code, '') AS scope_code,
      can_manage_all_tasks,
      can_edit_protected_fields
    FROM tms_user_profiles
    WHERE user_id = ?
    LIMIT 1
  ");
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: [];
  $stmt->close();

  return [
    'scope_code' => trim((string)($row['scope_code'] ?? '')),
    'can_manage_all_tasks' => (int)($row['can_manage_all_tasks'] ?? 0),
    'can_edit_protected_fields' => (int)($row['can_edit_protected_fields'] ?? 0),
  ];
}

function tms_user_can_manage_all(mysqli $conn, int $userId): bool
{
  if (strtolower(trim((string)($_SESSION['role'] ?? ''))) === 'admin') {
    return true;
  }

  $profile = tms_user_profile($conn, $userId);
  return (int)($profile['can_manage_all_tasks'] ?? 0) === 1;
}

function tms_user_can_edit_protected(mysqli $conn, int $userId): bool
{
  if (tms_user_can_manage_all($conn, $userId)) {
    return true;
  }

  $profile = tms_user_profile($conn, $userId);
  return (int)($profile['can_edit_protected_fields'] ?? 0) === 1;
}

function tms_normalize_progress(mixed $value): ?float
{
  if ($value === null) {
    return null;
  }

  $raw = trim((string)$value);
  if ($raw === '') {
    return null;
  }

  if (str_ends_with($raw, '%')) {
    $raw = substr($raw, 0, -1);
  }

  if (!is_numeric($raw)) {
    return null;
  }

  $number = (float)$raw;
  if ($number < 0) $number = 0;
  if ($number > 100) $number = 100;
  return round($number, 2);
}

function tms_normalize_textarea(string $value): string
{
  return trim((string)(preg_replace("/\r\n?/", "\n", $value) ?? $value));
}

function tms_workday_for_date(array $calendar, DateTimeImmutable $date): bool
{
  $date = $date->setTimezone(dt_work_timezone($calendar));
  $key = $date->format('Y-m-d');
  $exception = $calendar['exceptions'][$key] ?? null;
  if (is_array($exception)) {
    return !dt_calendar_exception_is_non_working((string)($exception['type'] ?? 'non_working'));
  }

  $workdays = array_map('intval', (array)($calendar['workdays'] ?? DT_WORKDAYS));
  return in_array((int)$date->format('N'), $workdays, true);
}

function tms_remaining_workdays(mysqli $conn, ?string $dateStarted, ?string $targetCompletion): ?int
{
  $dateStarted = trim((string)$dateStarted);
  $targetCompletion = trim((string)$targetCompletion);
  if ($dateStarted === '' || $targetCompletion === '') {
    return null;
  }

  $tz = dt_work_timezone(dt_work_calendar($conn));
  try {
    $today = new DateTimeImmutable('now', $tz);
    $target = new DateTimeImmutable($targetCompletion, $tz);
  } catch (Throwable) {
    return null;
  }

  $today = $today->setTime(0, 0, 0);
  $target = $target->setTime(0, 0, 0);
  $calendar = dt_work_calendar($conn);

  if ($today == $target) {
    return tms_workday_for_date($calendar, $today) ? 1 : 0;
  }

  $forward = $today < $target;
  $cursor = $forward ? $today : $target;
  $end = $forward ? $target : $today;
  $count = 0;
  $guard = 0;

  while ($cursor <= $end && $guard < 2500) {
    if (tms_workday_for_date($calendar, $cursor)) {
      $count++;
    }
    $cursor = $cursor->modify('+1 day');
    $guard++;
  }

  return $forward ? $count : -$count;
}

function tms_compute_derived(mysqli $conn, array $taskType, array $payload, ?array $existing = null): array
{
  $rule = trim((string)($taskType['workflow_rule'] ?? 'manual'));
  $progress = tms_normalize_progress($payload['progress_percent'] ?? null);
  $referenceCode = trim((string)($payload['reference_code'] ?? ''));
  $dateStarted = trim((string)($payload['date_started'] ?? ''));
  $targetCompletion = trim((string)($payload['target_completion'] ?? ''));

  $status = trim((string)($payload['status_label'] ?? ''));
  $remaining = tms_remaining_workdays($conn, $dateStarted, $targetCompletion);
  $completedAt = null;

  if ($rule === 'progress_remaining') {
    $progress = $progress ?? 0.0;
    if ($progress >= 100) {
      $status = 'Completed/Approved';
      $remaining = 0;
      $completedAt = date('Y-m-d H:i:s');
    } elseif ($progress >= 95) {
      $status = 'For Signatories';
    } elseif ($progress >= 80) {
      $status = 'For Review';
    } else {
      $status = 'In Progress';
    }
  } elseif ($rule === 'status_from_reference') {
    $status = $referenceCode !== '' ? 'Submitted' : 'Ongoing';
    if ($status === 'Submitted') {
      $completedAt = date('Y-m-d H:i:s');
    }
  } else {
    if ($status === '') {
      $status = 'Ongoing';
    }
  }

  if ($existing && trim((string)($existing['status_label'] ?? '')) === 'Completed/Approved' && $completedAt === null) {
    $completedAt = (string)($existing['completed_at'] ?? '') ?: null;
  }

  return [
    'progress_percent' => $progress,
    'status_label' => $status,
    'remaining_workdays' => $remaining,
    'completed_at' => $completedAt,
  ];
}

function tms_fetch_users_for_assignment(mysqli $conn): array
{
  $hasOfficialTitle = db_column_exists($conn, 'users', 'official_title');
  $sql = "
    SELECT
      u.id,
      u.full_name,
      COALESCE(u.username, '') AS username,
      COALESCE(u.email, '') AS email,
      COALESCE(s.name, '') AS section_name,
      COALESCE(d.name, '') AS division_name,
      " . ($hasOfficialTitle ? "COALESCE(u.official_title, '')" : "''") . " AS official_title
    FROM users u
    LEFT JOIN sections s ON s.id = u.section_id
    LEFT JOIN divisions d ON d.id = s.division_id
    WHERE u.is_active = 1
    ORDER BY u.full_name ASC
  ";
  return $conn->query($sql)->fetch_all(MYSQLI_ASSOC) ?: [];
}

function tms_user_can_edit_task(mysqli $conn, array $task, int $userId): bool
{
  if ($userId <= 0) return false;
  if (tms_user_can_manage_all($conn, $userId)) return true;
  if ((int)($task['created_by_user_id'] ?? 0) === $userId) return true;
  if ((int)($task['owner_user_id'] ?? 0) === $userId) return true;
  return false;
}

function tms_fetch_tasks(mysqli $conn, array $filters, int $viewerUserId): array
{
  $manageAll = tms_user_can_manage_all($conn, $viewerUserId);
  $viewMode = strtolower(trim((string)($filters['view_mode'] ?? 'my')));
  if ($manageAll && !in_array($viewMode, ['my', 'all'], true)) {
    $viewMode = 'all';
  }

  $typeCode = trim((string)($filters['type_code'] ?? ''));
  $status = trim((string)($filters['status'] ?? ''));
  $search = trim((string)($filters['q'] ?? ''));

  $where = [];
  $params = [];
  $types = '';

  if ($typeCode !== '') {
    $where[] = 'tt.code = ?';
    $params[] = $typeCode;
    $types .= 's';
  }

  if ($status !== '') {
    $where[] = 't.status_label = ?';
    $params[] = $status;
    $types .= 's';
  }

  if ($search !== '') {
    $where[] = "(t.project_code LIKE CONCAT('%', ?, '%') OR t.project_title LIKE CONCAT('%', ?, '%') OR t.description LIKE CONCAT('%', ?, '%') OR COALESCE(t.deo, '') LIKE CONCAT('%', ?, '%') OR COALESCE(t.lgu, '') LIKE CONCAT('%', ?, '%'))";
    array_push($params, $search, $search, $search, $search, $search);
    $types .= 'sssss';
  }

  if (!$manageAll || $viewMode === 'my') {
    $where[] = '(
      t.created_by_user_id = ?
      OR t.owner_user_id = ?
      OR EXISTS (
        SELECT 1
        FROM tms_task_assignees ta_view
        WHERE ta_view.task_id = t.id
          AND ta_view.user_id = ?
      )
    )';
    array_push($params, $viewerUserId, $viewerUserId, $viewerUserId);
    $types .= 'iii';
  }

  $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
  $sql = "
    SELECT
      t.id,
      t.task_type_id,
      tt.code AS task_type_code,
      tt.name AS task_type_name,
      COALESCE(tt.scope_code, '') AS task_type_scope_code,
      tt.assignment_role_label,
      COALESCE(tt.reference_label, '') AS reference_label,
      tt.workflow_rule,
      tt.allow_multi_assignees,
      tt.show_date_surveyed,
      tt.show_date_received,
      tt.show_date_started,
      tt.show_target_completion,
      tt.show_progress,
      tt.show_reference_code,
      t.project_id,
      t.document_id,
      t.created_by_user_id,
      t.updated_by_user_id,
      t.owner_user_id,
      t.division_id,
      t.section_id,
      COALESCE(t.scope_code, '') AS scope_code,
      t.project_code,
      t.project_title,
      t.description,
      COALESCE(t.deo, '') AS deo,
      COALESCE(t.lgu, '') AS lgu,
      COALESCE(t.assignee_display, '') AS assignee_display,
      t.date_surveyed,
      t.date_received,
      t.date_started,
      t.target_completion,
      t.remaining_workdays,
      t.progress_percent,
      t.status_label,
      COALESCE(t.reference_code, '') AS reference_code,
      COALESCE(t.remarks, '') AS remarks,
      t.completed_at,
      t.created_at,
      t.updated_at,
      COALESCE(creator.full_name, '') AS created_by_name,
      COALESCE(owner.full_name, '') AS owner_name,
      COALESCE(p.project_code, '') AS linked_project_code,
      COALESCE(p.title, '') AS linked_project_title,
      GROUP_CONCAT(DISTINCT ta.display_name ORDER BY ta.sort_order ASC SEPARATOR ', ') AS assignees_text
    FROM tms_tasks t
    JOIN tms_task_types tt ON tt.id = t.task_type_id
    LEFT JOIN users creator ON creator.id = t.created_by_user_id
    LEFT JOIN users owner ON owner.id = t.owner_user_id
    LEFT JOIN projects p ON p.id = t.project_id
    LEFT JOIN tms_task_assignees ta ON ta.task_id = t.id
    {$whereSql}
    GROUP BY t.id
    ORDER BY
      CASE
        WHEN t.status_label = 'Completed/Approved' THEN 2
        WHEN t.status_label = 'Submitted' THEN 2
        WHEN t.remaining_workdays IS NOT NULL AND t.remaining_workdays < 0 THEN 0
        ELSE 1
      END ASC,
      CASE WHEN t.remaining_workdays IS NULL THEN 999999 ELSE t.remaining_workdays END ASC,
      t.updated_at DESC,
      t.id DESC
  ";

  $stmt = $conn->prepare($sql);
  if ($types !== '') {
    $stmt->bind_param($types, ...$params);
  }
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();

  foreach ($rows as &$row) {
    $row['id'] = (int)($row['id'] ?? 0);
    $row['task_type_id'] = (int)($row['task_type_id'] ?? 0);
    $row['project_id'] = isset($row['project_id']) ? (int)$row['project_id'] : null;
    $row['document_id'] = isset($row['document_id']) ? (int)$row['document_id'] : null;
    $row['created_by_user_id'] = (int)($row['created_by_user_id'] ?? 0);
    $row['owner_user_id'] = isset($row['owner_user_id']) ? (int)$row['owner_user_id'] : null;
    $row['remaining_workdays'] = isset($row['remaining_workdays']) ? (int)$row['remaining_workdays'] : null;
    $row['progress_percent'] = isset($row['progress_percent']) ? (float)$row['progress_percent'] : null;
    $row['can_edit'] = tms_user_can_edit_task($conn, $row, $viewerUserId);
  }
  unset($row);

  return $rows;
}

function tms_fetch_task(mysqli $conn, int $taskId): ?array
{
  if ($taskId <= 0) return null;

  $stmt = $conn->prepare("
    SELECT
      t.*,
      tt.code AS task_type_code,
      tt.name AS task_type_name,
      tt.workflow_rule,
      tt.assignment_role_label,
      COALESCE(tt.reference_label, '') AS reference_label,
      tt.allow_multi_assignees,
      tt.show_date_surveyed,
      tt.show_date_received,
      tt.show_date_started,
      tt.show_target_completion,
      tt.show_progress,
      tt.show_reference_code
    FROM tms_tasks t
    JOIN tms_task_types tt ON tt.id = t.task_type_id
    WHERE t.id = ?
    LIMIT 1
  ");
  $stmt->bind_param('i', $taskId);
  $stmt->execute();
  $task = $stmt->get_result()->fetch_assoc() ?: null;
  $stmt->close();
  if (!$task) return null;

  $assigneeStmt = $conn->prepare("
    SELECT
      id,
      task_id,
      user_id,
      display_name,
      assignment_role,
      sort_order,
      is_primary
    FROM tms_task_assignees
    WHERE task_id = ?
    ORDER BY sort_order ASC, id ASC
  ");
  $assigneeStmt->bind_param('i', $taskId);
  $assigneeStmt->execute();
  $task['assignees'] = $assigneeStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $assigneeStmt->close();

  return $task;
}

function tms_log_activity(mysqli $conn, int $taskId, ?int $actorUserId, string $action, ?string $message, ?array $oldValues = null, ?array $newValues = null): void
{
  $oldJson = $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
  $newJson = $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

  $stmt = $conn->prepare("
    INSERT INTO tms_task_activity
      (task_id, actor_user_id, action, message, old_values_json, new_values_json)
    VALUES (?, ?, ?, ?, ?, ?)
  ");
  $stmt->bind_param('iissss', $taskId, $actorUserId, $action, $message, $oldJson, $newJson);
  $stmt->execute();
  $stmt->close();
}
