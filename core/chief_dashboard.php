<?php
declare(strict_types=1);

function chief_dashboard_normalize_authority_role(?string $authorityRole, bool $isChief = false): string
{
  $normalized = strtolower(trim((string)$authorityRole));
  if ($normalized !== '') {
    return $normalized;
  }

  return $isChief ? 'section_head' : 'staff';
}

function chief_dashboard_role_label(string $authorityRole): string
{
  return match (chief_dashboard_normalize_authority_role($authorityRole)) {
    'director' => 'Director',
    'division_head' => 'Division Chief',
    'section_head' => 'Section Chief',
    'division_assistant' => 'Division Assistant',
    default => 'Staff',
  };
}

function chief_dashboard_scope_label(array $viewer): string
{
  $role = chief_dashboard_normalize_authority_role((string)($viewer['authority_role'] ?? ''), !empty($viewer['is_chief']));
  $division = trim((string)($viewer['division_name'] ?? ''));
  $section = trim((string)($viewer['section_name'] ?? ''));

  return match ($role) {
    'director' => $division !== '' ? "All offices under {$division}" : 'All offices',
    'division_head' => $division !== '' ? $division : 'Assigned division',
    'section_head' => $section !== '' ? "{$section} section" : 'Assigned section',
    default => 'Assigned office',
  };
}

function chief_dashboard_can_access(array $viewer): bool
{
  $role = chief_dashboard_normalize_authority_role((string)($viewer['authority_role'] ?? ''), !empty($viewer['is_chief']));
  return in_array($role, ['director', 'division_head', 'section_head'], true);
}

function chief_dashboard_viewer_from_identity(array $identity): array
{
  return [
    'user_id' => (int)($identity['effective_user_id'] ?? 0),
    'full_name' => trim((string)($identity['acting_principal_name'] ?? ($identity['actual_full_name'] ?? ''))),
    'authority_role' => chief_dashboard_normalize_authority_role(
      (string)($identity['effective_authority_role'] ?? ''),
      (bool)($identity['effective_is_chief'] ?? false)
    ),
    'is_chief' => (bool)($identity['effective_is_chief'] ?? false),
    'section_id' => (int)($identity['effective_section_id'] ?? 0),
    'section_name' => trim((string)($identity['effective_section_name'] ?? '')),
    'division_id' => (int)($identity['effective_division_id'] ?? 0),
    'division_name' => trim((string)($identity['effective_division_name'] ?? '')),
  ];
}

function chief_dashboard_matches_scope(array $viewer, array $item): bool
{
  $role = chief_dashboard_normalize_authority_role((string)($viewer['authority_role'] ?? ''), !empty($viewer['is_chief']));
  if ($role === 'director') {
    return true;
  }

  $viewerDivisionId = (int)($viewer['division_id'] ?? 0);
  $viewerSectionId = (int)($viewer['section_id'] ?? 0);
  $itemDivisionId = (int)($item['division_id'] ?? 0);
  $itemSectionId = (int)($item['section_id'] ?? 0);

  return match ($role) {
    'division_head' => $viewerDivisionId > 0 && $itemDivisionId === $viewerDivisionId,
    'section_head' => $viewerSectionId > 0 && $itemSectionId === $viewerSectionId,
    default => false,
  };
}

function chief_dashboard_item_is_viewer(array $viewer, array $item): bool
{
  $viewerUserId = (int)($viewer['user_id'] ?? 0);
  return $viewerUserId > 0 && (int)($item['user_id'] ?? 0) === $viewerUserId;
}

function chief_dashboard_matches_personnel_scope(array $viewer, array $item): bool
{
  return chief_dashboard_matches_scope($viewer, $item)
    && !chief_dashboard_item_is_viewer($viewer, $item);
}

function chief_dashboard_scoped_accountable_items(array $viewer, array $accountable): array
{
  $scoped = array_values(array_filter($accountable, static function (array $item) use ($viewer): bool {
    return chief_dashboard_matches_personnel_scope($viewer, $item);
  }));
  if ($scoped === []) {
    return [];
  }

  $senderScopedRequests = [];
  foreach ($scoped as $item) {
    if (
      trim((string)($item['task_kind'] ?? '')) === 'action_request'
      && trim((string)($item['person_route_role'] ?? '')) === 'sender'
    ) {
      $taskId = (int)($item['task_id'] ?? 0);
      if ($taskId > 0) {
        $senderScopedRequests[$taskId] = true;
      }
    }
  }

  if ($senderScopedRequests === []) {
    return $scoped;
  }

  return array_values(array_filter($scoped, static function (array $item) use ($senderScopedRequests): bool {
    if (trim((string)($item['task_kind'] ?? '')) !== 'action_request') {
      return true;
    }
    if (trim((string)($item['person_route_role'] ?? '')) !== 'receiver') {
      return true;
    }

    $taskId = (int)($item['task_id'] ?? 0);
    return $taskId <= 0 || !isset($senderScopedRequests[$taskId]);
  }));
}

function chief_dashboard_documents_url(bool $assistantMode = false, int $actingPrincipalUserId = 0): string
{
  $query = [];
  if ($assistantMode && $actingPrincipalUserId > 0) {
    $query['view'] = 'assistant';
    $query['acting_principal_user_id'] = $actingPrincipalUserId;
  }

  $base = PUBLIC_PATH . '/documents.php';
  return $query === [] ? $base : $base . '?' . http_build_query($query);
}

function chief_dashboard_user_photo_column(mysqli $conn): ?string
{
  static $resolved = null;
  static $resolvedOnce = false;

  if ($resolvedOnce) {
    return $resolved;
  }

  foreach (['profile_photo_url', 'avatar_url', 'photo_url'] as $candidate) {
    if (function_exists('db_column_exists') && db_column_exists($conn, 'users', $candidate)) {
      $resolved = $candidate;
      break;
    }
  }

  $resolvedOnce = true;
  return $resolved;
}

function chief_dashboard_user_photo_sql(mysqli $conn, string $alias, bool $trim = false): string
{
  $column = chief_dashboard_user_photo_column($conn);
  if ($column === null) {
    return "''";
  }

  $column = $conn->real_escape_string($column);
  $expr = "{$alias}.`{$column}`";

  if ($trim) {
    return "NULLIF(TRIM({$expr}), '')";
  }

  return "COALESCE({$expr}, '')";
}

function chief_dashboard_end_of_day(?string $raw, ?DateTimeZone $timezone = null): ?DateTimeImmutable
{
  $value = trim((string)$raw);
  if ($value === '') {
    return null;
  }

  $timezone = $timezone ?: dt_work_timezone(dt_default_work_calendar());

  try {
    $date = new DateTimeImmutable($value, $timezone);
  } catch (Throwable) {
    return null;
  }

  return $date->setTime(23, 59, 59);
}

function chief_dashboard_datetime_label(?string $raw, ?DateTimeZone $timezone = null): string
{
  $value = trim((string)$raw);
  if ($value === '') {
    return 'Not set';
  }

  $timezone = $timezone ?: dt_work_timezone(dt_default_work_calendar());
  try {
    $date = new DateTimeImmutable($value, $timezone);
  } catch (Throwable) {
    return 'Not set';
  }

  return $date->format('M d, Y g:i A');
}

function chief_dashboard_date_label(?string $raw, ?DateTimeZone $timezone = null): string
{
  $value = trim((string)$raw);
  if ($value === '') {
    return 'Not set';
  }

  $timezone = $timezone ?: dt_work_timezone(dt_default_work_calendar());
  try {
    $date = new DateTimeImmutable($value, $timezone);
  } catch (Throwable) {
    return 'Not set';
  }

  return $date->format('M d, Y');
}

function chief_dashboard_status_tone(array $row): string
{
  if (!empty($row['is_overdue'])) {
    return 'overdue';
  }
  if (!empty($row['is_due_today'])) {
    return 'due-today';
  }
  if (!empty($row['is_stale'])) {
    return 'stale';
  }
  return 'default';
}

function chief_dashboard_reason_labels(array $row): array
{
  $labels = [];
  if (!empty($row['is_overdue'])) {
    $labels[] = 'Overdue';
  }
  if (!empty($row['is_due_today'])) {
    $labels[] = 'Due today';
  }
  if (!empty($row['is_stale'])) {
    $labels[] = 'No movement for 5+ working days';
  }
  return $labels;
}

function chief_dashboard_priority_rank(array $row): int
{
  return (!empty($row['is_overdue']) ? 300 : 0)
    + (!empty($row['is_due_today']) ? 200 : 0)
    + (!empty($row['is_stale']) ? 100 : 0);
}

function chief_dashboard_build_accountable_item(array $row, string $source): array
{
  $role = chief_dashboard_normalize_authority_role((string)($row['authority_role'] ?? ''), ((int)($row['is_chief'] ?? 0) === 1));
  $name = trim((string)($row['full_name'] ?? ''));
  $officialTitle = trim((string)($row['official_title'] ?? ''));
  $sectionName = trim((string)($row['section_name'] ?? ''));
  $divisionName = trim((string)($row['division_name'] ?? ''));
  $profilePhotoUrl = function_exists('app_profile_photo_url')
    ? app_profile_photo_url((string)($row['profile_photo_url'] ?? ''))
    : trim((string)($row['profile_photo_url'] ?? ''));
  $avatarName = $name !== '' ? $name : ($sectionName !== '' ? $sectionName : 'Assigned office');
  $avatarInitials = function_exists('app_user_initials')
    ? app_user_initials($avatarName)
    : strtoupper(substr($avatarName, 0, 1));

  $primaryLabel = $name !== '' ? $name : ($sectionName !== '' ? $sectionName : 'Assigned office');
  $secondaryParts = [];
  if ($officialTitle !== '') {
    $secondaryParts[] = $officialTitle;
  } elseif ($name !== '') {
    $secondaryParts[] = chief_dashboard_role_label($role);
  }
  if ($sectionName !== '') {
    $secondaryParts[] = $sectionName;
  }
  if ($divisionName !== '' && !in_array($divisionName, $secondaryParts, true)) {
    $secondaryParts[] = $divisionName;
  }

  return [
    'source' => $source,
    'user_id' => (int)($row['user_id'] ?? 0),
    'section_id' => (int)($row['section_id'] ?? 0),
    'division_id' => (int)($row['division_id'] ?? 0),
    'full_name' => $name,
    'official_title' => $officialTitle,
    'authority_role' => $role,
    'section_name' => $sectionName,
    'division_name' => $divisionName,
    'personal_deadline_at' => trim((string)($row['personal_deadline_at'] ?? '')),
    'accountable_since_at' => trim((string)($row['accountable_since_at'] ?? '')),
    'primary_label' => $primaryLabel,
    'secondary_label' => implode(' | ', array_values(array_filter($secondaryParts, static fn($value): bool => trim((string)$value) !== ''))),
    'profile_photo_url' => $profilePhotoUrl,
    'avatar_initials' => $avatarInitials,
    'in_transit' => (int)($row['in_transit'] ?? 0),
    'person_route_role' => trim((string)($row['person_route_role'] ?? '')),
    'counterpart_name' => trim((string)($row['counterpart_name'] ?? '')),
    'counterpart_section_name' => trim((string)($row['counterpart_section_name'] ?? '')),
    'counterpart_division_name' => trim((string)($row['counterpart_division_name'] ?? '')),
    'task_id' => (int)($row['task_id'] ?? 0),
    'task_kind' => trim((string)($row['task_kind'] ?? '')),
    'task_status' => trim((string)($row['task_status'] ?? '')),
  ];
}

function chief_dashboard_build_route_participant_items(array $row): array
{
  $items = [];

  $receiverRow = $row;
  $receiverRow['user_id'] = (int)($row['receiver_user_id'] ?? 0);
  $receiverRow['section_id'] = (int)($row['receiver_section_id'] ?? 0);
  $receiverRow['division_id'] = (int)($row['receiver_division_id'] ?? 0);
  $receiverRow['full_name'] = (string)($row['receiver_full_name'] ?? '');
  $receiverRow['official_title'] = (string)($row['receiver_official_title'] ?? '');
  $receiverRow['authority_role'] = (string)($row['receiver_authority_role'] ?? '');
  $receiverRow['profile_photo_url'] = (string)($row['receiver_profile_photo_url'] ?? '');
  $receiverRow['section_name'] = (string)($row['receiver_section_name'] ?? '');
  $receiverRow['division_name'] = (string)($row['receiver_division_name'] ?? '');
  $receiverRow['person_route_role'] = 'receiver';
  $receiverRow['counterpart_name'] = (string)($row['sender_full_name'] ?? '');
  $receiverRow['counterpart_section_name'] = (string)($row['sender_section_name'] ?? '');
  $receiverRow['counterpart_division_name'] = (string)($row['sender_division_name'] ?? '');
  $receiverRow['in_transit'] = 1;
  if ((int)$receiverRow['user_id'] > 0 || (int)$receiverRow['section_id'] > 0) {
    $items[] = chief_dashboard_build_accountable_item($receiverRow, 'open_route');
  }

  $senderRow = $row;
  $senderRow['user_id'] = (int)($row['sender_user_id'] ?? 0);
  $senderRow['section_id'] = (int)($row['sender_section_id'] ?? 0);
  $senderRow['division_id'] = (int)($row['sender_division_id'] ?? 0);
  $senderRow['full_name'] = (string)($row['sender_full_name'] ?? '');
  $senderRow['official_title'] = (string)($row['sender_official_title'] ?? '');
  $senderRow['authority_role'] = (string)($row['sender_authority_role'] ?? '');
  $senderRow['profile_photo_url'] = (string)($row['sender_profile_photo_url'] ?? '');
  $senderRow['section_name'] = (string)($row['sender_section_name'] ?? '');
  $senderRow['division_name'] = (string)($row['sender_division_name'] ?? '');
  $senderRow['person_route_role'] = 'sender';
  $senderRow['counterpart_name'] = (string)($row['receiver_full_name'] ?? '');
  $senderRow['counterpart_section_name'] = (string)($row['receiver_section_name'] ?? '');
  $senderRow['counterpart_division_name'] = (string)($row['receiver_division_name'] ?? '');
  $senderRow['in_transit'] = 1;
  if ((int)$senderRow['user_id'] > 0 || (int)$senderRow['section_id'] > 0) {
    $items[] = chief_dashboard_build_accountable_item($senderRow, 'open_route');
  }

  return $items;
}

function chief_dashboard_build_task_participant_items(array $row, string $source, string $taskKind): array
{
  $items = [];
  $taskKind = in_array($taskKind, ['attachment_forward', 'action_request'], true) ? $taskKind : '';

  $receiverRow = $row;
  $receiverRow['user_id'] = (int)($row['recipient_user_id'] ?? 0);
  $receiverRow['section_id'] = (int)($row['recipient_section_id'] ?? 0);
  $receiverRow['division_id'] = (int)($row['recipient_division_id'] ?? 0);
  $receiverRow['full_name'] = (string)($row['recipient_full_name'] ?? '');
  $receiverRow['official_title'] = (string)($row['recipient_official_title'] ?? '');
  $receiverRow['authority_role'] = (string)($row['recipient_authority_role'] ?? '');
  $receiverRow['profile_photo_url'] = (string)($row['recipient_profile_photo_url'] ?? '');
  $receiverRow['section_name'] = (string)($row['recipient_section_name'] ?? '');
  $receiverRow['division_name'] = (string)($row['recipient_division_name'] ?? '');
  $receiverRow['person_route_role'] = 'receiver';
  $receiverRow['counterpart_name'] = (string)($row['sender_full_name'] ?? '');
  $receiverRow['counterpart_section_name'] = (string)($row['sender_section_name'] ?? '');
  $receiverRow['counterpart_division_name'] = (string)($row['sender_division_name'] ?? '');
  $receiverRow['in_transit'] = 1;
  $receiverRow['task_id'] = (int)($row['task_id'] ?? 0);
  $receiverRow['task_kind'] = $taskKind;
  $receiverRow['task_status'] = (string)($row['task_status'] ?? '');
  if ((int)$receiverRow['user_id'] > 0 || (int)$receiverRow['section_id'] > 0) {
    $items[] = chief_dashboard_build_accountable_item($receiverRow, $source);
  }

  $senderRow = $row;
  $senderRow['user_id'] = (int)($row['sender_user_id'] ?? 0);
  $senderRow['section_id'] = (int)($row['sender_section_id'] ?? 0);
  $senderRow['division_id'] = (int)($row['sender_division_id'] ?? 0);
  $senderRow['full_name'] = (string)($row['sender_full_name'] ?? '');
  $senderRow['official_title'] = (string)($row['sender_official_title'] ?? '');
  $senderRow['authority_role'] = (string)($row['sender_authority_role'] ?? '');
  $senderRow['profile_photo_url'] = (string)($row['sender_profile_photo_url'] ?? '');
  $senderRow['section_name'] = (string)($row['sender_section_name'] ?? '');
  $senderRow['division_name'] = (string)($row['sender_division_name'] ?? '');
  $senderRow['person_route_role'] = 'sender';
  $senderRow['counterpart_name'] = (string)($row['recipient_full_name'] ?? '');
  $senderRow['counterpart_section_name'] = (string)($row['recipient_section_name'] ?? '');
  $senderRow['counterpart_division_name'] = (string)($row['recipient_division_name'] ?? '');
  $senderRow['in_transit'] = 1;
  $senderRow['task_id'] = (int)($row['task_id'] ?? 0);
  $senderRow['task_kind'] = $taskKind;
  $senderRow['task_status'] = (string)($row['task_status'] ?? '');
  if ((int)$senderRow['user_id'] > 0 || (int)$senderRow['section_id'] > 0) {
    $items[] = chief_dashboard_build_accountable_item($senderRow, $source);
  }

  return $items;
}

function chief_dashboard_build_holder_item(array $document): array
{
  $hasHolderUser = (int)($document['holder_user_id'] ?? 0) > 0;
  $hasCreatorUser = (int)($document['created_by_user_id'] ?? 0) > 0;
  $useCreator = !$hasHolderUser && $hasCreatorUser;

  $name = trim((string)($useCreator ? ($document['creator_user_name'] ?? '') : ($document['holder_user_name'] ?? '')));
  $sectionName = trim((string)($useCreator ? ($document['creator_section_name'] ?? '') : ($document['current_holder_section_name'] ?? '')));
  $divisionName = trim((string)($useCreator ? ($document['creator_division_name'] ?? '') : ($document['current_holder_division_name'] ?? '')));
  $officialTitle = trim((string)($useCreator ? ($document['creator_user_title'] ?? '') : ($document['holder_user_title'] ?? '')));
  $role = chief_dashboard_normalize_authority_role((string)($useCreator ? ($document['creator_user_authority_role'] ?? '') : ($document['holder_user_authority_role'] ?? '')), false);
  $profilePhotoUrl = function_exists('app_profile_photo_url')
    ? app_profile_photo_url((string)($useCreator ? ($document['creator_user_profile_photo_url'] ?? '') : ($document['holder_user_profile_photo_url'] ?? '')))
    : trim((string)($useCreator ? ($document['creator_user_profile_photo_url'] ?? '') : ($document['holder_user_profile_photo_url'] ?? '')));
  $avatarName = $name !== '' ? $name : ($sectionName !== '' ? $sectionName : ($useCreator ? 'Creator' : 'Current holder'));
  $avatarInitials = function_exists('app_user_initials')
    ? app_user_initials($avatarName)
    : strtoupper(substr($avatarName, 0, 1));

  $secondaryParts = [];
  if ($officialTitle !== '') {
    $secondaryParts[] = $officialTitle;
  } elseif ($name !== '') {
    $secondaryParts[] = chief_dashboard_role_label($role);
  }
  if ($sectionName !== '') {
    $secondaryParts[] = $sectionName;
  }
  if ($divisionName !== '') {
    $secondaryParts[] = $divisionName;
  }

  return [
    'source' => $useCreator ? 'creator' : 'holder',
    'user_id' => (int)($useCreator ? ($document['created_by_user_id'] ?? 0) : ($document['holder_user_id'] ?? 0)),
    'section_id' => (int)($useCreator ? ($document['creator_section_id'] ?? 0) : ($document['current_holder_section_id'] ?? 0)),
    'division_id' => (int)($useCreator ? ($document['creator_division_id'] ?? 0) : ($document['current_holder_division_id'] ?? 0)),
    'full_name' => $name,
    'official_title' => $officialTitle,
    'authority_role' => $role,
    'section_name' => $sectionName,
    'division_name' => $divisionName,
    'personal_deadline_at' => '',
    'accountable_since_at' => trim((string)($document['holder_since_at'] ?? '')),
    'primary_label' => $name !== '' ? $name : ($sectionName !== '' ? $sectionName : ($useCreator ? 'Creator' : 'Current holder')),
    'secondary_label' => implode(' | ', array_values(array_filter($secondaryParts, static fn($value): bool => trim((string)$value) !== ''))),
    'profile_photo_url' => $profilePhotoUrl,
    'avatar_initials' => $avatarInitials,
  ];
}

function chief_dashboard_person_key(array $person): string
{
  return 'u:' . (int)($person['user_id'] ?? 0)
    . '|s:' . (int)($person['section_id'] ?? 0)
    . '|d:' . (int)($person['division_id'] ?? 0)
    . '|p:' . strtolower(trim((string)($person['primary_label'] ?? '')));
}

function chief_dashboard_route_context_label(array $person): string
{
  $role = strtolower(trim((string)($person['person_route_role'] ?? '')));
  if ($role === '') {
    return '';
  }

  $counterpart = trim((string)($person['counterpart_name'] ?? ''));
  if ($counterpart === '') {
    $counterpart = trim((string)($person['counterpart_section_name'] ?? ''));
  }
  if ($counterpart === '') {
    $counterpart = $role === 'receiver' ? 'sender' : 'receiver';
  }

  return $role === 'receiver'
    ? "Receiver from {$counterpart}"
    : "Sender to {$counterpart}";
}

function chief_dashboard_task_kind_label(array $person): string
{
  return match (trim((string)($person['task_kind'] ?? ''))) {
    'attachment_forward' => 'Forward attach',
    'action_request' => 'Signature/approval request',
    default => '',
  };
}

function chief_dashboard_fetch_open_task_accountables(mysqli $conn, int $documentId, bool $hasOfficialTitle, bool $hasAuthorityRole): array
{
  $byDocument = [];
  $documentWhere = $documentId > 0 ? ' AND task_src.document_id = ?' : '';

  $taskQueries = [];
  if (function_exists('workflow_attachment_forwarding_enabled') && workflow_attachment_forwarding_enabled($conn)) {
    $taskQueries[] = [
      'kind' => 'attachment_forward',
      'source' => 'attachment_forward',
      'sql' => "
        SELECT
          task_src.id AS task_id,
          task_src.document_id,
          COALESCE(
            (
              SELECT NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, '$.acting_principal_user_id')) AS UNSIGNED), 0)
              FROM document_events e
              WHERE e.document_id = task_src.document_id
                AND e.actor_user_id = task_src.sender_user_id
                AND e.event_type = 'forwarded'
                AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, '$.kind')) = 'attachment_forwarded'
                AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, '$.batch_id')) = task_src.batch_id
              ORDER BY e.id DESC
              LIMIT 1
            ),
            task_src.sender_user_id
          ) AS sender_user_id,
          task_src.sender_section_id,
          task_src.recipient_user_id,
          task_src.recipient_section_id,
          task_src.task_status,
          COALESCE(task_src.received_at, task_src.created_at) AS accountable_since_at
        FROM attachment_forward_tasks task_src
        WHERE task_src.task_status IN ('PENDING_RECEIVE', 'IN_PROGRESS')
        {$documentWhere}
      ",
    ];
  }
  if (function_exists('workflow_action_requests_enabled') && workflow_action_requests_enabled($conn)) {
    $taskQueries[] = [
      'kind' => 'action_request',
      'source' => 'action_request',
      'sql' => "
        SELECT
          task_src.id AS task_id,
          task_src.document_id,
          COALESCE(
            (
              SELECT NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, '$.acting_principal_user_id')) AS UNSIGNED), 0)
              FROM document_events e
              WHERE e.document_id = task_src.document_id
                AND e.actor_user_id = task_src.sender_user_id
                AND e.event_type = 'forwarded'
                AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, '$.kind')) = 'action_request_created'
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload_json, '$.request_id')) AS UNSIGNED) = task_src.id
              ORDER BY e.id DESC
              LIMIT 1
            ),
            task_src.sender_user_id
          ) AS sender_user_id,
          task_src.sender_section_id,
          task_src.recipient_user_id,
          task_src.recipient_section_id,
          task_src.task_status,
          COALESCE(task_src.received_at, task_src.created_at) AS accountable_since_at
        FROM document_action_requests task_src
        WHERE task_src.task_status IN ('PENDING_RECEIVE', 'IN_PROGRESS')
        {$documentWhere}
      ",
    ];
  }

  foreach ($taskQueries as $taskQuery) {
    $sql = "
      SELECT
        base.task_id,
        base.document_id,
        base.sender_user_id,
        base.recipient_user_id,
        base.task_status,
        base.accountable_since_at,
        COALESCE(u_sender.full_name, '') AS sender_full_name,
        " . ($hasOfficialTitle ? "COALESCE(u_sender.official_title, '')" : "''") . " AS sender_official_title,
        " . ($hasAuthorityRole ? "COALESCE(u_sender.authority_role, '')" : "''") . " AS sender_authority_role,
        " . chief_dashboard_user_photo_sql($conn, 'u_sender') . " AS sender_profile_photo_url,
        COALESCE(sec_sender.id, NULLIF(base.sender_section_id, 0), u_sender.section_id, 0) AS sender_section_id,
        COALESCE(sec_sender.name, '') AS sender_section_name,
        COALESCE(div_sender.id, 0) AS sender_division_id,
        COALESCE(div_sender.name, '') AS sender_division_name,
        COALESCE(u_recipient.full_name, '') AS recipient_full_name,
        " . ($hasOfficialTitle ? "COALESCE(u_recipient.official_title, '')" : "''") . " AS recipient_official_title,
        " . ($hasAuthorityRole ? "COALESCE(u_recipient.authority_role, '')" : "''") . " AS recipient_authority_role,
        " . chief_dashboard_user_photo_sql($conn, 'u_recipient') . " AS recipient_profile_photo_url,
        COALESCE(sec_recipient.id, NULLIF(base.recipient_section_id, 0), u_recipient.section_id, 0) AS recipient_section_id,
        COALESCE(sec_recipient.name, '') AS recipient_section_name,
        COALESCE(div_recipient.id, 0) AS recipient_division_id,
        COALESCE(div_recipient.name, '') AS recipient_division_name
      FROM ({$taskQuery['sql']}) base
      LEFT JOIN users u_sender ON u_sender.id = base.sender_user_id
      LEFT JOIN sections sec_sender ON sec_sender.id = COALESCE(NULLIF(base.sender_section_id, 0), u_sender.section_id)
      LEFT JOIN divisions div_sender ON div_sender.id = sec_sender.division_id
      LEFT JOIN users u_recipient ON u_recipient.id = base.recipient_user_id
      LEFT JOIN sections sec_recipient ON sec_recipient.id = COALESCE(NULLIF(base.recipient_section_id, 0), u_recipient.section_id)
      LEFT JOIN divisions div_recipient ON div_recipient.id = sec_recipient.division_id
    ";

    if ($documentId > 0) {
      $stmt = $conn->prepare($sql);
      $stmt->bind_param('i', $documentId);
      $stmt->execute();
      $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    } else {
      $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC) ?: [];
    }

    foreach ($rows as $row) {
      $rowDocumentId = (int)($row['document_id'] ?? 0);
      if ($rowDocumentId <= 0) {
        continue;
      }
      foreach (chief_dashboard_build_task_participant_items($row, (string)$taskQuery['source'], (string)$taskQuery['kind']) as $item) {
        $byDocument[$rowDocumentId][] = $item;
      }
    }
  }

  return $byDocument;
}

function chief_dashboard_document_scope_allows(mysqli $conn, array $viewer, int $documentId): bool
{
  if ($documentId <= 0 || !chief_dashboard_can_access($viewer)) {
    return false;
  }

  $hasOfficialTitle = function_exists('db_column_exists') ? db_column_exists($conn, 'users', 'official_title') : false;
  $hasAuthorityRole = function_exists('db_column_exists') ? db_column_exists($conn, 'users', 'authority_role') : false;
  $routePersonalDeadlineEnabled = function_exists('workflow_has_column') ? workflow_has_column($conn, 'routes', 'personal_deadline_at') : false;

  $docSql = "
    SELECT
      d.id,
      d.current_status,
      d.current_holder_section_id,
      sh.name AS current_holder_section_name,
      dh.id AS current_holder_division_id,
      dh.name AS current_holder_division_name,
      COALESCE(r_last.received_at, '') AS last_received_at,
      COALESCE(r_last.received_by_user_id, r_last.to_user_id, 0) AS holder_user_id,
      COALESCE(NULLIF(TRIM(u_holder_recv.full_name), ''), NULLIF(TRIM(u_holder_to.full_name), ''), '') AS holder_user_name,
      " . ($hasOfficialTitle ? "COALESCE(NULLIF(TRIM(u_holder_recv.official_title), ''), NULLIF(TRIM(u_holder_to.official_title), ''), '')" : "''") . " AS holder_user_title,
      " . ($hasAuthorityRole ? "COALESCE(NULLIF(TRIM(u_holder_recv.authority_role), ''), NULLIF(TRIM(u_holder_to.authority_role), ''), '')" : "''") . " AS holder_user_authority_role,
      COALESCE(" . chief_dashboard_user_photo_sql($conn, 'u_holder_recv', true) . ", " . chief_dashboard_user_photo_sql($conn, 'u_holder_to', true) . ", '') AS holder_user_profile_photo_url,
      COALESCE(d.created_by_user_id, 0) AS created_by_user_id,
      COALESCE(NULLIF(TRIM(u_creator.full_name), ''), '') AS creator_user_name,
      " . ($hasOfficialTitle ? "COALESCE(NULLIF(TRIM(u_creator.official_title), ''), '')" : "''") . " AS creator_user_title,
      " . ($hasAuthorityRole ? "COALESCE(NULLIF(TRIM(u_creator.authority_role), ''), '')" : "''") . " AS creator_user_authority_role,
      " . chief_dashboard_user_photo_sql($conn, 'u_creator') . " AS creator_user_profile_photo_url,
      COALESCE(sec_creator.id, u_creator.section_id, 0) AS creator_section_id,
      COALESCE(sec_creator.name, '') AS creator_section_name,
      COALESCE(div_creator.id, 0) AS creator_division_id,
      COALESCE(div_creator.name, '') AS creator_division_name,
      COALESCE(d.created_at, '') AS created_at
    FROM documents d
    LEFT JOIN sections sh ON sh.id = d.current_holder_section_id
    LEFT JOIN divisions dh ON dh.id = sh.division_id
    LEFT JOIN routes r_last ON r_last.id = (
      SELECT r2.id
      FROM routes r2
      WHERE r2.document_id = d.id
        AND r2.route_kind = 'ACTION'
        AND r2.received_at IS NOT NULL
      ORDER BY r2.received_at DESC, r2.id DESC
      LIMIT 1
    )
    LEFT JOIN users u_holder_recv ON u_holder_recv.id = r_last.received_by_user_id
    LEFT JOIN users u_holder_to ON u_holder_to.id = r_last.to_user_id
    LEFT JOIN users u_creator ON u_creator.id = d.created_by_user_id
    LEFT JOIN sections sec_creator ON sec_creator.id = u_creator.section_id
    LEFT JOIN divisions div_creator ON div_creator.id = sec_creator.division_id
    WHERE d.id = ?
    LIMIT 1
  ";
  $docStmt = $conn->prepare($docSql);
  $docStmt->bind_param('i', $documentId);
  $docStmt->execute();
  $document = $docStmt->get_result()->fetch_assoc();
  if (!$document) {
    return false;
  }

  $document['holder_since_at'] = trim((string)($document['last_received_at'] ?? '')) !== ''
    ? trim((string)$document['last_received_at'])
    : trim((string)($document['created_at'] ?? ''));

  $openRouteDeadlineSql = $routePersonalDeadlineEnabled ? 'r.personal_deadline_at' : 'NULL';
  $openRoutesSql = "
    SELECT
      COALESCE(r.to_user_id, 0) AS receiver_user_id,
      COALESCE(sec_recv.id, r.to_section_id, 0) AS receiver_section_id,
      COALESCE(div_recv.id, 0) AS receiver_division_id,
      COALESCE(u_recv.full_name, '') AS receiver_full_name,
      " . ($hasOfficialTitle ? "COALESCE(u_recv.official_title, '')" : "''") . " AS receiver_official_title,
      " . ($hasAuthorityRole ? "COALESCE(u_recv.authority_role, '')" : "''") . " AS receiver_authority_role,
      " . chief_dashboard_user_photo_sql($conn, 'u_recv') . " AS receiver_profile_photo_url,
      COALESCE(sec_recv.name, '') AS receiver_section_name,
      COALESCE(div_recv.name, '') AS receiver_division_name,
      COALESCE(NULLIF(r.from_user_id, 0), NULLIF(r.sent_by_user_id, 0), 0) AS sender_user_id,
      COALESCE(sec_send.id, r.from_section_id, u_send.section_id, 0) AS sender_section_id,
      COALESCE(div_send.id, 0) AS sender_division_id,
      COALESCE(u_send.full_name, '') AS sender_full_name,
      " . ($hasOfficialTitle ? "COALESCE(u_send.official_title, '')" : "''") . " AS sender_official_title,
      " . ($hasAuthorityRole ? "COALESCE(u_send.authority_role, '')" : "''") . " AS sender_authority_role,
      " . chief_dashboard_user_photo_sql($conn, 'u_send') . " AS sender_profile_photo_url,
      COALESCE(sec_send.name, '') AS sender_section_name,
      COALESCE(div_send.name, '') AS sender_division_name,
      {$openRouteDeadlineSql} AS personal_deadline_at,
      r.sent_at AS accountable_since_at
    FROM routes r
    LEFT JOIN document_branches b ON b.id = r.branch_id
    LEFT JOIN users u_recv ON u_recv.id = r.to_user_id
    LEFT JOIN sections sec_recv ON sec_recv.id = COALESCE(r.to_section_id, u_recv.section_id)
    LEFT JOIN divisions div_recv ON div_recv.id = sec_recv.division_id
    LEFT JOIN users u_send ON u_send.id = COALESCE(NULLIF(r.from_user_id, 0), NULLIF(r.sent_by_user_id, 0))
    LEFT JOIN sections sec_send ON sec_send.id = COALESCE(r.from_section_id, u_send.section_id)
    LEFT JOIN divisions div_send ON div_send.id = sec_send.division_id
    WHERE r.document_id = ?
      AND r.route_kind = 'ACTION'
      AND r.received_at IS NULL
      AND r.cancelled_at IS NULL
      AND (
        r.branch_id IS NULL
        OR b.current_assignee_user_id = r.to_user_id
      )
  ";
  $routeStmt = $conn->prepare($openRoutesSql);
  $routeStmt->bind_param('i', $documentId);
  $routeStmt->execute();
  $openRoutes = $routeStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

  $branchSql = "
    SELECT
      COALESCE(b.current_assignee_user_id, 0) AS user_id,
      COALESCE(sec.id, b.current_assignee_section_id, 0) AS section_id,
      COALESCE(divi.id, 0) AS division_id,
      COALESCE(u.full_name, '') AS full_name,
      " . ($hasOfficialTitle ? "COALESCE(u.official_title, '')" : "''") . " AS official_title,
      " . ($hasAuthorityRole ? "COALESCE(u.authority_role, '')" : "''") . " AS authority_role,
      " . chief_dashboard_user_photo_sql($conn, 'u') . " AS profile_photo_url,
      COALESCE(sec.name, '') AS section_name,
      COALESCE(divi.name, '') AS division_name,
      NULL AS personal_deadline_at,
      COALESCE(b.updated_at, b.created_at) AS accountable_since_at
    FROM document_branches b
    LEFT JOIN users u ON u.id = b.current_assignee_user_id
    LEFT JOIN sections sec ON sec.id = COALESCE(b.current_assignee_section_id, u.section_id)
    LEFT JOIN divisions divi ON divi.id = sec.division_id
    WHERE b.document_id = ?
      AND b.branch_status = 'ACTIVE'
      AND b.is_reference = 0
  ";
  $branchStmt = $conn->prepare($branchSql);
  $branchStmt->bind_param('i', $documentId);
  $branchStmt->execute();
  $branchRows = $branchStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

  $taskByDocument = chief_dashboard_fetch_open_task_accountables($conn, $documentId, $hasOfficialTitle, $hasAuthorityRole);
  $accountable = $taskByDocument[$documentId] ?? [];
  if ($accountable === []) {
    $accountable = [];
    foreach ($openRoutes as $row) {
      foreach (chief_dashboard_build_route_participant_items($row) as $item) {
        $accountable[] = $item;
      }
    }
  }
  if ($accountable === []) {
    $accountable = array_map(static fn(array $row): array => chief_dashboard_build_accountable_item($row, 'branch'), $branchRows);
  }
  if ($accountable === []) {
    $accountable = [chief_dashboard_build_holder_item($document)];
  }

  return chief_dashboard_scoped_accountable_items($viewer, $accountable) !== [];
}

function chief_dashboard_fetch_attention(mysqli $conn, array $viewer, array $filters = []): array
{
  $calendar = dt_work_calendar($conn);
  $timezone = dt_work_timezone($calendar);
  $now = new DateTimeImmutable('now', $timezone);
  $today = $now->format('Y-m-d');
  $bucket = strtolower(trim((string)($filters['bucket'] ?? 'all')));
  if (!in_array($bucket, ['all', 'overdue', 'due_today', 'stale'], true)) {
    $bucket = 'all';
  }
  $search = strtolower(trim((string)($filters['q'] ?? '')));

  $hasOfficialTitle = function_exists('db_column_exists') ? db_column_exists($conn, 'users', 'official_title') : false;
  $hasAuthorityRole = function_exists('db_column_exists') ? db_column_exists($conn, 'users', 'authority_role') : false;
  $routePersonalDeadlineEnabled = function_exists('workflow_has_column') ? workflow_has_column($conn, 'routes', 'personal_deadline_at') : false;

  $baseSql = "
    SELECT
      d.id,
      d.tracking_no,
      d.requester,
      d.subject,
      d.document_date,
      d.deadline_at,
      d.created_at,
      d.updated_at,
      d.current_status,
      d.current_holder_section_id,
      sh.name AS current_holder_section_name,
      dh.id AS current_holder_division_id,
      dh.name AS current_holder_division_name,
      COALESCE(r_last.received_at, '') AS last_received_at,
      COALESCE(r_last.received_by_user_id, r_last.to_user_id, 0) AS holder_user_id,
      COALESCE(NULLIF(TRIM(u_holder_recv.full_name), ''), NULLIF(TRIM(u_holder_to.full_name), ''), '') AS holder_user_name,
      " . ($hasOfficialTitle ? "COALESCE(NULLIF(TRIM(u_holder_recv.official_title), ''), NULLIF(TRIM(u_holder_to.official_title), ''), '')" : "''") . " AS holder_user_title,
      " . ($hasAuthorityRole ? "COALESCE(NULLIF(TRIM(u_holder_recv.authority_role), ''), NULLIF(TRIM(u_holder_to.authority_role), ''), '')" : "''") . " AS holder_user_authority_role,
      COALESCE(" . chief_dashboard_user_photo_sql($conn, 'u_holder_recv', true) . ", " . chief_dashboard_user_photo_sql($conn, 'u_holder_to', true) . ", '') AS holder_user_profile_photo_url,
      COALESCE(d.created_by_user_id, 0) AS created_by_user_id,
      COALESCE(NULLIF(TRIM(u_creator.full_name), ''), '') AS creator_user_name,
      " . ($hasOfficialTitle ? "COALESCE(NULLIF(TRIM(u_creator.official_title), ''), '')" : "''") . " AS creator_user_title,
      " . ($hasAuthorityRole ? "COALESCE(NULLIF(TRIM(u_creator.authority_role), ''), '')" : "''") . " AS creator_user_authority_role,
      " . chief_dashboard_user_photo_sql($conn, 'u_creator') . " AS creator_user_profile_photo_url,
      COALESCE(sec_creator.id, u_creator.section_id, 0) AS creator_section_id,
      COALESCE(sec_creator.name, '') AS creator_section_name,
      COALESCE(div_creator.id, 0) AS creator_division_id,
      COALESCE(div_creator.name, '') AS creator_division_name
    FROM documents d
    LEFT JOIN sections sh ON sh.id = d.current_holder_section_id
    LEFT JOIN divisions dh ON dh.id = sh.division_id
    LEFT JOIN routes r_last ON r_last.id = (
      SELECT r2.id
      FROM routes r2
      WHERE r2.document_id = d.id
        AND r2.route_kind = 'ACTION'
        AND r2.received_at IS NOT NULL
      ORDER BY r2.received_at DESC, r2.id DESC
      LIMIT 1
    )
    LEFT JOIN users u_holder_recv ON u_holder_recv.id = r_last.received_by_user_id
    LEFT JOIN users u_holder_to ON u_holder_to.id = r_last.to_user_id
    LEFT JOIN users u_creator ON u_creator.id = d.created_by_user_id
    LEFT JOIN sections sec_creator ON sec_creator.id = u_creator.section_id
    LEFT JOIN divisions div_creator ON div_creator.id = sec_creator.division_id
    WHERE d.current_status = 'ACTIVE'
    ORDER BY d.updated_at DESC, d.id DESC
  ";

  $baseRows = $conn->query($baseSql)->fetch_all(MYSQLI_ASSOC) ?: [];
  if ($baseRows === []) {
    return [
      'stats' => ['all' => 0, 'overdue' => 0, 'due_today' => 0, 'stale' => 0],
      'documents' => [],
      'bucket' => $bucket,
      'search' => $search,
      'timezone_label' => $timezone->getName(),
    ];
  }

  $documents = [];
  foreach ($baseRows as $row) {
    $row['id'] = (int)($row['id'] ?? 0);
    $row['current_holder_section_id'] = (int)($row['current_holder_section_id'] ?? 0);
    $row['current_holder_division_id'] = (int)($row['current_holder_division_id'] ?? 0);
    $row['holder_user_id'] = (int)($row['holder_user_id'] ?? 0);
    $row['holder_since_at'] = trim((string)($row['last_received_at'] ?? '')) !== ''
      ? trim((string)$row['last_received_at'])
      : trim((string)($row['created_at'] ?? ''));
    $creatorName = trim((string)($row['creator_user_name'] ?? ''));
    $holderSectionLabel = trim((string)(($row['current_holder_section_name'] ?? '') . (((string)($row['current_holder_division_name'] ?? '') !== '') ? ' | ' . (string)$row['current_holder_division_name'] : '')));
    $row['holder_display_label'] = (int)$row['holder_user_id'] <= 0 && $creatorName !== ''
      ? $creatorName
      : $holderSectionLabel;
    $documents[$row['id']] = $row;
  }

  $openRouteDeadlineSql = $routePersonalDeadlineEnabled ? 'r.personal_deadline_at' : 'NULL';
  $openRoutesSql = "
    SELECT
      r.document_id,
      COALESCE(r.to_user_id, 0) AS receiver_user_id,
      COALESCE(sec_recv.id, r.to_section_id, 0) AS receiver_section_id,
      COALESCE(div_recv.id, 0) AS receiver_division_id,
      COALESCE(u_recv.full_name, '') AS receiver_full_name,
      " . ($hasOfficialTitle ? "COALESCE(u_recv.official_title, '')" : "''") . " AS receiver_official_title,
      " . ($hasAuthorityRole ? "COALESCE(u_recv.authority_role, '')" : "''") . " AS receiver_authority_role,
      " . chief_dashboard_user_photo_sql($conn, 'u_recv') . " AS receiver_profile_photo_url,
      COALESCE(sec_recv.name, '') AS receiver_section_name,
      COALESCE(div_recv.name, '') AS receiver_division_name,
      COALESCE(NULLIF(r.from_user_id, 0), NULLIF(r.sent_by_user_id, 0), 0) AS sender_user_id,
      COALESCE(sec_send.id, r.from_section_id, u_send.section_id, 0) AS sender_section_id,
      COALESCE(div_send.id, 0) AS sender_division_id,
      COALESCE(u_send.full_name, '') AS sender_full_name,
      " . ($hasOfficialTitle ? "COALESCE(u_send.official_title, '')" : "''") . " AS sender_official_title,
      " . ($hasAuthorityRole ? "COALESCE(u_send.authority_role, '')" : "''") . " AS sender_authority_role,
      " . chief_dashboard_user_photo_sql($conn, 'u_send') . " AS sender_profile_photo_url,
      COALESCE(sec_send.name, '') AS sender_section_name,
      COALESCE(div_send.name, '') AS sender_division_name,
      {$openRouteDeadlineSql} AS personal_deadline_at,
      r.sent_at AS accountable_since_at
    FROM routes r
    LEFT JOIN document_branches b ON b.id = r.branch_id
    LEFT JOIN users u_recv ON u_recv.id = r.to_user_id
    LEFT JOIN sections sec_recv ON sec_recv.id = COALESCE(r.to_section_id, u_recv.section_id)
    LEFT JOIN divisions div_recv ON div_recv.id = sec_recv.division_id
    LEFT JOIN users u_send ON u_send.id = COALESCE(NULLIF(r.from_user_id, 0), NULLIF(r.sent_by_user_id, 0))
    LEFT JOIN sections sec_send ON sec_send.id = COALESCE(r.from_section_id, u_send.section_id)
    LEFT JOIN divisions div_send ON div_send.id = sec_send.division_id
    WHERE r.route_kind = 'ACTION'
      AND r.received_at IS NULL
      AND r.cancelled_at IS NULL
      AND (
        r.branch_id IS NULL
        OR b.current_assignee_user_id = r.to_user_id
      )
  ";

  $openByDocument = [];
  $openRouteRows = $conn->query($openRoutesSql)->fetch_all(MYSQLI_ASSOC) ?: [];
  foreach ($openRouteRows as $row) {
    $documentId = (int)($row['document_id'] ?? 0);
    if ($documentId <= 0 || !isset($documents[$documentId])) {
      continue;
    }
    foreach (chief_dashboard_build_route_participant_items($row) as $item) {
      $openByDocument[$documentId][] = $item;
    }
  }

  $branchSql = "
    SELECT
      b.document_id,
      COALESCE(b.current_assignee_user_id, 0) AS user_id,
      COALESCE(sec.id, b.current_assignee_section_id, 0) AS section_id,
      COALESCE(divi.id, 0) AS division_id,
      COALESCE(u.full_name, '') AS full_name,
      " . ($hasOfficialTitle ? "COALESCE(u.official_title, '')" : "''") . " AS official_title,
      " . ($hasAuthorityRole ? "COALESCE(u.authority_role, '')" : "''") . " AS authority_role,
      " . chief_dashboard_user_photo_sql($conn, 'u') . " AS profile_photo_url,
      COALESCE(sec.name, '') AS section_name,
      COALESCE(divi.name, '') AS division_name,
      NULL AS personal_deadline_at,
      COALESCE(b.updated_at, b.created_at) AS accountable_since_at
    FROM document_branches b
    LEFT JOIN users u ON u.id = b.current_assignee_user_id
    LEFT JOIN sections sec ON sec.id = COALESCE(b.current_assignee_section_id, u.section_id)
    LEFT JOIN divisions divi ON divi.id = sec.division_id
    WHERE b.branch_status = 'ACTIVE'
      AND b.is_reference = 0
  ";

  $branchesByDocument = [];
  $branchRows = $conn->query($branchSql)->fetch_all(MYSQLI_ASSOC) ?: [];
  foreach ($branchRows as $row) {
    $documentId = (int)($row['document_id'] ?? 0);
    if ($documentId <= 0 || !isset($documents[$documentId])) {
      continue;
    }
    $branchesByDocument[$documentId][] = chief_dashboard_build_accountable_item($row, 'branch');
  }

  $taskByDocument = chief_dashboard_fetch_open_task_accountables($conn, 0, $hasOfficialTitle, $hasAuthorityRole);
  $attentionRows = [];
  $stats = ['all' => 0, 'overdue' => 0, 'due_today' => 0, 'stale' => 0];

  foreach ($documents as $documentId => $document) {
    $accountable = $taskByDocument[$documentId] ?? [];
    if ($accountable === []) {
      $accountable = $openByDocument[$documentId] ?? [];
    }
    if ($accountable === []) {
      $accountable = $branchesByDocument[$documentId] ?? [];
    }
    if ($accountable === []) {
      $accountable = [chief_dashboard_build_holder_item($document)];
    }

    $scopedAccountable = chief_dashboard_scoped_accountable_items($viewer, $accountable);
    if ($scopedAccountable === []) {
      continue;
    }

    $personalDeadlines = array_values(array_filter(array_map(static fn(array $item): string => trim((string)($item['personal_deadline_at'] ?? '')), $accountable), static fn(string $value): bool => $value !== ''));
    sort($personalDeadlines);
    $effectiveDeadlineRaw = $personalDeadlines[0] ?? trim((string)($document['deadline_at'] ?? ''));
    $effectiveDeadlineAt = chief_dashboard_end_of_day($effectiveDeadlineRaw, $timezone);
    $stuckSinceRaw = trim((string)(($accountable[0]['accountable_since_at'] ?? '') ?: ($document['last_received_at'] ?? '') ?: ($document['created_at'] ?? '')));
    $workingMinutes = dt_working_minutes_between($stuckSinceRaw !== '' ? $stuckSinceRaw : null, null, $conn);
    $workingDays = dt_working_days_from_minutes($workingMinutes, $conn);

    $isOverdue = $effectiveDeadlineAt instanceof DateTimeImmutable && $effectiveDeadlineAt < $now;
    $isDueToday = $effectiveDeadlineAt instanceof DateTimeImmutable && $effectiveDeadlineAt->format('Y-m-d') === $today;
    $isStale = $workingDays >= 5;

    if (!$isOverdue && !$isDueToday && !$isStale) {
      continue;
    }

    $searchHaystack = strtolower(implode(' ', array_filter([
      (string)($document['tracking_no'] ?? ''),
      (string)($document['subject'] ?? ''),
      (string)($document['requester'] ?? ''),
      (string)($document['current_holder_section_name'] ?? ''),
      implode(' ', array_map(static fn(array $item): string => ($item['primary_label'] ?? '') . ' ' . ($item['secondary_label'] ?? ''), $scopedAccountable)),
      implode(' ', array_map(static fn(array $item): string => chief_dashboard_route_context_label($item), $scopedAccountable)),
      implode(' ', array_map(static fn(array $item): string => chief_dashboard_task_kind_label($item), $scopedAccountable)),
    ], static fn($value): bool => trim((string)$value) !== '')));

    if ($search !== '' && !str_contains($searchHaystack, $search)) {
      continue;
    }

    $stats['all']++;
    if ($isOverdue) {
      $stats['overdue']++;
    }
    if ($isDueToday) {
      $stats['due_today']++;
    }
    if ($isStale) {
      $stats['stale']++;
    }

    if (
      ($bucket === 'overdue' && !$isOverdue)
      || ($bucket === 'due_today' && !$isDueToday)
      || ($bucket === 'stale' && !$isStale)
    ) {
      continue;
    }

    $attentionRows[] = [
      'id' => $documentId,
      'tracking_no' => (string)($document['tracking_no'] ?? ''),
      'requester' => (string)($document['requester'] ?? ''),
      'subject' => (string)($document['subject'] ?? ''),
      'document_date' => (string)($document['document_date'] ?? ''),
      'deadline_at' => (string)($document['deadline_at'] ?? ''),
      'effective_deadline_at' => $effectiveDeadlineRaw,
      'effective_deadline_label' => chief_dashboard_date_label($effectiveDeadlineRaw, $timezone),
      'current_holder_section_name' => (string)($document['current_holder_section_name'] ?? ''),
      'current_holder_division_name' => (string)($document['current_holder_division_name'] ?? ''),
      'holder_display_label' => (string)($document['holder_display_label'] ?? ''),
      'stuck_since_at' => $stuckSinceRaw,
      'stuck_since_label' => chief_dashboard_datetime_label($stuckSinceRaw, $timezone),
      'working_days_stuck' => $workingDays,
      'working_elapsed_label' => dt_format_working_elapsed($workingMinutes, $conn),
      'accountable_people' => $scopedAccountable,
      'is_overdue' => $isOverdue,
      'is_due_today' => $isDueToday,
      'is_stale' => $isStale,
      'tone' => chief_dashboard_status_tone([
        'is_overdue' => $isOverdue,
        'is_due_today' => $isDueToday,
        'is_stale' => $isStale,
      ]),
    ];
  }

  usort($attentionRows, static function (array $a, array $b): int {
    $scoreDiff = chief_dashboard_priority_rank($b) <=> chief_dashboard_priority_rank($a);
    if ($scoreDiff !== 0) {
      return $scoreDiff;
    }

    $deadlineCompare = strcmp((string)($a['effective_deadline_at'] ?? ''), (string)($b['effective_deadline_at'] ?? ''));
    if ($deadlineCompare !== 0) {
      return $deadlineCompare;
    }

    $stuckDiff = ((int)($b['working_days_stuck'] ?? 0)) <=> ((int)($a['working_days_stuck'] ?? 0));
    if ($stuckDiff !== 0) {
      return $stuckDiff;
    }

    return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
  });

  return [
    'stats' => $stats,
    'documents' => $attentionRows,
    'bucket' => $bucket,
    'search' => $search,
    'timezone_label' => $timezone->getName(),
  ];
}

function chief_dashboard_group_attention(array $documents): array
{
  $groups = [];

  foreach ($documents as $document) {
    foreach ((array)($document['accountable_people'] ?? []) as $person) {
      $key = chief_dashboard_person_key($person);
      if (!isset($groups[$key])) {
        $groups[$key] = [
          'key' => $key,
          'person' => $person,
          'documents' => [],
          'document_ids' => [],
          'stats' => [
            'all' => 0,
            'overdue' => 0,
            'due_today' => 0,
            'stale' => 0,
          ],
          'highest_priority' => 0,
        ];
      }

      $documentId = (int)($document['id'] ?? 0);
      if ($documentId > 0 && isset($groups[$key]['document_ids'][$documentId])) {
        continue;
      }

      $documentForGroup = $document;
      $documentForGroup['chief_person_context'] = $person;
      $groups[$key]['documents'][] = $documentForGroup;
      if ($documentId > 0) {
        $groups[$key]['document_ids'][$documentId] = true;
      }
      $groups[$key]['stats']['all']++;
      if (!empty($document['is_overdue'])) {
        $groups[$key]['stats']['overdue']++;
      }
      if (!empty($document['is_due_today'])) {
        $groups[$key]['stats']['due_today']++;
      }
      if (!empty($document['is_stale'])) {
        $groups[$key]['stats']['stale']++;
      }
      $groups[$key]['highest_priority'] = max($groups[$key]['highest_priority'], chief_dashboard_priority_rank($document));
    }
  }

  foreach ($groups as &$group) {
    unset($group['document_ids']);
    usort($group['documents'], static function (array $a, array $b): int {
      $scoreDiff = chief_dashboard_priority_rank($b) <=> chief_dashboard_priority_rank($a);
      if ($scoreDiff !== 0) {
        return $scoreDiff;
      }

      $deadlineCompare = strcmp((string)($a['effective_deadline_at'] ?? ''), (string)($b['effective_deadline_at'] ?? ''));
      if ($deadlineCompare !== 0) {
        return $deadlineCompare;
      }

      return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
    });
  }
  unset($group);

  $groupList = array_values($groups);
  usort($groupList, static function (array $a, array $b): int {
    $priorityDiff = ((int)($b['highest_priority'] ?? 0)) <=> ((int)($a['highest_priority'] ?? 0));
    if ($priorityDiff !== 0) {
      return $priorityDiff;
    }

    $overdueDiff = ((int)($b['stats']['overdue'] ?? 0)) <=> ((int)($a['stats']['overdue'] ?? 0));
    if ($overdueDiff !== 0) {
      return $overdueDiff;
    }

    return ((int)($b['stats']['all'] ?? 0)) <=> ((int)($a['stats']['all'] ?? 0));
  });

  return $groupList;
}

function chief_dashboard_filter_groups(array $groups, array $filters): array
{
  $divisionId = (int)($filters['division_id'] ?? 0);
  $sectionId = (int)($filters['section_id'] ?? 0);
  $personKey = trim((string)($filters['person_key'] ?? ''));

  return array_values(array_filter($groups, static function (array $group) use ($divisionId, $sectionId, $personKey): bool {
    $person = (array)($group['person'] ?? []);
    if ($divisionId > 0 && (int)($person['division_id'] ?? 0) !== $divisionId) {
      return false;
    }
    if ($sectionId > 0 && (int)($person['section_id'] ?? 0) !== $sectionId) {
      return false;
    }
    if ($personKey !== '' && (string)($group['key'] ?? '') !== $personKey) {
      return false;
    }
    return true;
  }));
}

function chief_dashboard_filter_options(array $groups, array $viewer): array
{
  $role = chief_dashboard_normalize_authority_role((string)($viewer['authority_role'] ?? ''), !empty($viewer['is_chief']));
  $divisionOptions = [];
  $sectionOptions = [];
  $personOptions = [];

  foreach ($groups as $group) {
    $person = (array)($group['person'] ?? []);
    $divisionId = (int)($person['division_id'] ?? 0);
    $sectionId = (int)($person['section_id'] ?? 0);
    $groupKey = trim((string)($group['key'] ?? ''));

    if ($divisionId > 0 && !isset($divisionOptions[$divisionId])) {
      $divisionOptions[$divisionId] = [
        'id' => $divisionId,
        'name' => trim((string)($person['division_name'] ?? 'Division')),
      ];
    }

    if ($sectionId > 0 && !isset($sectionOptions[$sectionId])) {
      $sectionOptions[$sectionId] = [
        'id' => $sectionId,
        'division_id' => $divisionId,
        'name' => trim((string)($person['section_name'] ?? 'Section')),
      ];
    }

    if ($groupKey !== '' && !isset($personOptions[$groupKey])) {
      $personOptions[$groupKey] = [
        'key' => $groupKey,
        'division_id' => $divisionId,
        'section_id' => $sectionId,
        'label' => trim((string)($person['primary_label'] ?? 'Assigned office')),
        'meta' => trim((string)($person['secondary_label'] ?? '')),
      ];
    }
  }

  usort($divisionOptions, static fn(array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));
  usort($sectionOptions, static fn(array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));
  usort($personOptions, static fn(array $a, array $b): int => strcasecmp((string)$a['label'], (string)$b['label']));

  return [
    'role' => $role,
    'divisions' => array_values($divisionOptions),
    'sections' => array_values($sectionOptions),
    'people' => array_values($personOptions),
  ];
}
