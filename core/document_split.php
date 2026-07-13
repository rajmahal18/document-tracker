<?php
declare(strict_types=1);

require_once __DIR__ . '/project_codes.php';

function document_split_parent_link_ready(mysqli $conn): bool
{
  return db_column_exists($conn, 'documents', 'parent_document_id');
}

function document_split_schema_ready(mysqli $conn): bool
{
  return document_split_parent_link_ready($conn)
    && db_table_exists($conn, 'document_tracking_sequences');
}

function generate_document_tracking_no_for_split(mysqli $conn, ?DateTimeImmutable $now = null): string
{
  if (!db_table_exists($conn, 'document_tracking_sequences')) {
    throw new RuntimeException('Document split schema is not installed.');
  }
  $now = $now ?: new DateTimeImmutable('now');
  $year = (int)$now->format('Y');

  $stmt = $conn->prepare("
    INSERT INTO document_tracking_sequences (tracking_year, last_number)
    VALUES (?, 0)
    ON DUPLICATE KEY UPDATE tracking_year = VALUES(tracking_year)
  ");
  $stmt->bind_param('i', $year);
  $stmt->execute();

  $stmt = $conn->prepare("
    SELECT last_number
    FROM document_tracking_sequences
    WHERE tracking_year = ?
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->bind_param('i', $year);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: ['last_number' => 0];
  $nextNumber = ((int)($row['last_number'] ?? 0)) + 1;

  $stmt = $conn->prepare("
    UPDATE document_tracking_sequences
    SET last_number = ?
    WHERE tracking_year = ?
  ");
  $stmt->bind_param('ii', $nextNumber, $year);
  $stmt->execute();

  return sprintf('DOC-%04d-%05d', $year, $nextNumber);
}

function document_split_get_parent_summary(mysqli $conn, int $documentId): ?array
{
  if (!document_split_parent_link_ready($conn)) {
    return null;
  }
  if ($documentId <= 0) {
    return null;
  }

  $stmt = $conn->prepare("
    SELECT
      d.parent_document_id,
      p.tracking_no AS parent_tracking_no,
      p.subject AS parent_subject,
      p.current_status AS parent_status
    FROM documents d
    JOIN documents p ON p.id = d.parent_document_id
    WHERE d.id = ?
      AND d.parent_document_id IS NOT NULL
    LIMIT 1
  ");
  $stmt->bind_param('i', $documentId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  return $row ?: null;
}

function document_split_get_child_summaries(mysqli $conn, int $documentId): array
{
  if (!document_split_parent_link_ready($conn)) {
    return [];
  }
  if ($documentId <= 0) {
    return [];
  }

  $stmt = $conn->prepare("
    SELECT
      d.id,
      d.tracking_no,
      d.subject,
      d.current_status,
      s.name AS current_holder_section_name,
      COALESCE((
        SELECT GROUP_CONCAT(DISTINCT p.project_code ORDER BY p.project_code SEPARATOR '||')
        FROM document_projects dp
        JOIN projects p ON p.id = dp.project_id
        WHERE dp.document_id = d.id
      ), '') AS project_codes_concat
    FROM documents d
    LEFT JOIN sections s ON s.id = d.current_holder_section_id
    WHERE d.parent_document_id = ?
    ORDER BY d.created_at ASC, d.id ASC
  ");
  $stmt->bind_param('i', $documentId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

  foreach ($rows as &$row) {
    $row['project_codes'] = array_values(array_filter(
      array_map('trim', explode('||', (string)($row['project_codes_concat'] ?? ''))),
      static fn(string $value): bool => $value !== ''
    ));
    unset($row['project_codes_concat']);
  }
  unset($row);

  return $rows;
}

function document_split_can_create_children(
  mysqli $conn,
  int $documentId,
  int $userId,
  int $sectionId,
  bool $isChief,
  bool $isAdmin
): bool {
  if ($documentId <= 0 || $userId <= 0) {
    return false;
  }
  if (!document_split_schema_ready($conn)) {
    return false;
  }

  $stmt = $conn->prepare("
    SELECT current_status, parent_document_id
    FROM documents
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param('i', $documentId);
  $stmt->execute();
  $doc = $stmt->get_result()->fetch_assoc();
  if (!$doc) {
    return false;
  }

  if ((int)($doc['parent_document_id'] ?? 0) > 0) {
    return false;
  }
  if (strtoupper((string)($doc['current_status'] ?? 'ACTIVE')) !== 'ACTIVE') {
    return false;
  }

  return can_manage_document_projects_for_identity($conn, $documentId, $userId, $sectionId, $isChief, $isAdmin);
}

function document_split_build_child_subject(string $parentSubject, string $projectCode): string
{
  $parentSubject = trim($parentSubject);
  $projectCode = trim($projectCode);
  if ($projectCode === '') {
    return document_split_truncate_text($parentSubject, 255);
  }
  if ($parentSubject === '') {
    return document_split_truncate_text($projectCode, 255);
  }

  $suffix = ' [' . $projectCode . ']';
  $candidate = $parentSubject . $suffix;
  if (document_split_text_length($candidate) <= 255) {
    return $candidate;
  }

  if (document_split_text_length($suffix) >= 255) {
    return document_split_truncate_text($suffix, 255);
  }

  return document_split_truncate_text($parentSubject, 255 - document_split_text_length($suffix)) . $suffix;
}

function document_split_project_codes_label(array $projectCodes): string
{
  $projectCodes = array_values(array_filter(array_map(
    static fn(mixed $value): string => trim((string)$value),
    $projectCodes
  ), static fn(string $value): bool => $value !== ''));

  if ($projectCodes === []) {
    return '';
  }

  if (count($projectCodes) <= 3) {
    return implode(', ', $projectCodes);
  }

  $visible = array_slice($projectCodes, 0, 3);
  return implode(', ', $visible) . ' +' . (count($projectCodes) - count($visible)) . ' more';
}

function document_split_text_length(string $value): int
{
  return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function document_split_truncate_text(string $value, int $maxLength): string
{
  $value = trim($value);
  $maxLength = max(0, $maxLength);
  if ($maxLength <= 0 || $value === '') {
    return '';
  }
  if (document_split_text_length($value) <= $maxLength) {
    return $value;
  }

  return function_exists('mb_substr')
    ? rtrim(mb_substr($value, 0, $maxLength, 'UTF-8'))
    : rtrim(substr($value, 0, $maxLength));
}

function document_split_normalize_project_groups(array $projectIds, ?array $projectGroups = null): array
{
  $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds), static fn(int $v): bool => $v > 0)));

  if ($projectIds === []) {
    return [];
  }

  if ($projectGroups === null) {
    return array_map(static fn(int $projectId): array => [$projectId], $projectIds);
  }

  $groups = [];
  $seen = [];
  foreach ($projectGroups as $group) {
    if (!is_array($group)) {
      continue;
    }

    $normalizedGroup = [];
    foreach ($group as $projectId) {
      $projectId = (int)$projectId;
      if ($projectId <= 0 || !in_array($projectId, $projectIds, true) || isset($seen[$projectId])) {
        continue;
      }
      $normalizedGroup[] = $projectId;
      $seen[$projectId] = true;
    }

    if ($normalizedGroup !== []) {
      $groups[] = $normalizedGroup;
    }
  }

  foreach ($projectIds as $projectId) {
    if (!isset($seen[$projectId])) {
      $groups[] = [$projectId];
    }
  }

  return $groups;
}

function document_split_create_children(
  mysqli $conn,
  int $parentDocumentId,
  array $projectIds,
  int $actorUserId,
  int $actorSectionId,
  ?array $projectGroups = null
): array {
  if (!document_split_schema_ready($conn)) {
    throw new RuntimeException('Document split schema is not installed.');
  }

  if ($parentDocumentId <= 0 || $actorUserId <= 0 || $actorSectionId <= 0) {
    throw new RuntimeException('Invalid split request.');
  }

  $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds), static fn(int $v): bool => $v > 0)));
  if ($projectIds === []) {
    throw new RuntimeException('Please select at least one project to split.');
  }

  $normalizedGroups = document_split_normalize_project_groups($projectIds, $projectGroups);
  if ($normalizedGroups === []) {
    throw new RuntimeException('Please select at least one project to split.');
  }

  $stmtParent = $conn->prepare("
    SELECT id, tracking_no, requester, document_date, deadline_at, subject, content_type, comm_type, current_status
    FROM documents
    WHERE id = ?
    LIMIT 1
  ");
  $stmtParent->bind_param('i', $parentDocumentId);
  $stmtParent->execute();
  $parent = $stmtParent->get_result()->fetch_assoc();
  if (!$parent) {
    throw new RuntimeException('Parent document not found.');
  }

  if (strtoupper((string)($parent['current_status'] ?? 'ACTIVE')) !== 'ACTIVE') {
    throw new RuntimeException('Only active parent documents can be split.');
  }

  $parentProjects = fetch_document_projects($conn, $parentDocumentId, true);
  if ($parentProjects === []) {
    throw new RuntimeException('This document has no attached projects to split.');
  }

  $projectMap = [];
  foreach ($parentProjects as $projectRow) {
    $projectMap[(int)($projectRow['id'] ?? 0)] = $projectRow;
  }

  foreach ($projectIds as $projectId) {
    if (!isset($projectMap[$projectId])) {
      throw new RuntimeException('One of the selected projects does not belong to the parent document.');
    }
  }

  $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
  $types = 'i' . str_repeat('i', count($projectIds));
  $params = array_merge([$parentDocumentId], $projectIds);
  $stmtDup = $conn->prepare("
    SELECT dp.project_id, d.tracking_no
    FROM documents d
    JOIN document_projects dp ON dp.document_id = d.id
    WHERE d.parent_document_id = ?
      AND dp.project_id IN ($placeholders)
    LIMIT 1
  ");
  $stmtDup->bind_param($types, ...$params);
  $stmtDup->execute();
  $dup = $stmtDup->get_result()->fetch_assoc();
  if ($dup) {
    throw new RuntimeException('Project already split to child document ' . (string)($dup['tracking_no'] ?? ''));
  }

  $created = [];

  $stmtInsertDoc = $conn->prepare("
    INSERT INTO documents (
      tracking_no, requester, document_date, deadline_at, subject, content_type, comm_type,
      current_status, origin_section_id, current_holder_section_id, parent_document_id, created_by_user_id
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVE', ?, ?, ?, ?)
  ");

  $stmtParticipant = $conn->prepare("
    INSERT IGNORE INTO document_participants
      (document_id, section_id, added_via, added_by_user_id)
    VALUES (?, ?, 'origin', ?)
  ");

  $stmtEvent = $conn->prepare("
    INSERT INTO document_events
      (document_id, event_type, actor_user_id, actor_section_id, from_section_id, to_section_id, payload_json)
    VALUES (?, ?, ?, ?, NULL, NULL, ?)
  ");

  foreach ($normalizedGroups as $groupProjectIds) {
    $groupProjectCodes = [];
    foreach ($groupProjectIds as $projectId) {
      $project = $projectMap[$projectId] ?? null;
      if (!$project) {
        throw new RuntimeException('One of the selected projects does not belong to the parent document.');
      }
      $projectCode = trim((string)($project['project_code'] ?? ''));
      if ($projectCode !== '') {
        $groupProjectCodes[] = $projectCode;
      }
    }

    $projectLabel = document_split_project_codes_label($groupProjectCodes);
    $childTrackingNo = generate_document_tracking_no_for_split($conn);
    $childSubject = document_split_build_child_subject((string)($parent['subject'] ?? ''), $projectLabel);
    $requester = (string)($parent['requester'] ?? '');
    $documentDate = (string)($parent['document_date'] ?? '');
    $deadlineAt = ($parent['deadline_at'] ?? null);
    $contentType = (string)($parent['content_type'] ?? '');
    $commType = (string)($parent['comm_type'] ?? '');

    $stmtInsertDoc->bind_param(
      'sssssssiiii',
      $childTrackingNo,
      $requester,
      $documentDate,
      $deadlineAt,
      $childSubject,
      $contentType,
      $commType,
      $actorSectionId,
      $actorSectionId,
      $parentDocumentId,
      $actorUserId
    );
    $stmtInsertDoc->execute();
    $childDocumentId = (int)$conn->insert_id;

    sync_document_projects($conn, $childDocumentId, $groupProjectIds, $actorUserId);

    $stmtParticipant->bind_param('iii', $childDocumentId, $actorSectionId, $actorUserId);
    $stmtParticipant->execute();

    if (workflow_branch_mode_enabled($conn)) {
      workflow_grant_visibility($conn, $childDocumentId, $actorUserId, 'CREATOR', null, $actorUserId);
    }

    $createdPayload = json_encode([
      'tracking_no' => $childTrackingNo,
      'subject' => $childSubject,
      'parent_document_id' => $parentDocumentId,
      'parent_tracking_no' => (string)($parent['tracking_no'] ?? ''),
      'project_id' => count($groupProjectIds) === 1 ? (int)$groupProjectIds[0] : null,
      'project_ids' => array_values(array_map('intval', $groupProjectIds)),
      'project_code' => count($groupProjectCodes) === 1 ? (string)$groupProjectCodes[0] : $projectLabel,
      'project_codes' => array_values($groupProjectCodes),
      'kind' => 'split_child_created',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $createdEventType = 'created';
    $stmtEvent->bind_param('isiis', $childDocumentId, $createdEventType, $actorUserId, $actorSectionId, $createdPayload);
    $stmtEvent->execute();

    $parentPayload = json_encode([
      'kind' => 'project_split_child_created',
      'child_document_id' => $childDocumentId,
      'child_tracking_no' => $childTrackingNo,
      'project_id' => count($groupProjectIds) === 1 ? (int)$groupProjectIds[0] : null,
      'project_ids' => array_values(array_map('intval', $groupProjectIds)),
      'project_code' => count($groupProjectCodes) === 1 ? (string)$groupProjectCodes[0] : $projectLabel,
      'project_codes' => array_values($groupProjectCodes),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $updatedEventType = 'updated';
    $stmtEvent->bind_param('isiis', $parentDocumentId, $updatedEventType, $actorUserId, $actorSectionId, $parentPayload);
    $stmtEvent->execute();

    $created[] = [
      'id' => $childDocumentId,
      'tracking_no' => $childTrackingNo,
      'subject' => $childSubject,
      'project_id' => count($groupProjectIds) === 1 ? (int)$groupProjectIds[0] : null,
      'project_ids' => array_values(array_map('intval', $groupProjectIds)),
      'project_code' => count($groupProjectCodes) === 1 ? (string)$groupProjectCodes[0] : $projectLabel,
      'project_codes' => array_values($groupProjectCodes),
    ];
  }

  return $created;
}
