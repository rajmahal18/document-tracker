<?php
declare(strict_types=1);

function project_codes_tables_ready(mysqli $conn): bool
{
  return db_table_exists($conn, 'projects') && db_table_exists($conn, 'document_projects');
}

function fetch_document_projects(mysqli $conn, int $documentId, bool $includeInactive = true): array
{
  if ($documentId <= 0 || !project_codes_tables_ready($conn)) {
    return [];
  }

  $whereActive = $includeInactive ? '' : 'AND p.is_active = 1';
  $stmt = $conn->prepare("
    SELECT
      p.id,
      p.project_code,
      p.title,
      p.is_active
    FROM document_projects dp
    JOIN projects p ON p.id = dp.project_id
    WHERE dp.document_id = ?
      {$whereActive}
    ORDER BY p.project_code ASC, p.title ASC
  ");
  $stmt->bind_param('i', $documentId);
  $stmt->execute();
  return $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
}

function sync_document_projects(mysqli $conn, int $documentId, array $projectIds, int $addedByUserId): void
{
  if ($documentId <= 0 || $addedByUserId <= 0 || !project_codes_tables_ready($conn)) {
    return;
  }

  $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds), static fn(int $v): bool => $v > 0)));

  if ($projectIds === []) {
    $stmtDelAll = $conn->prepare("DELETE FROM document_projects WHERE document_id = ?");
    $stmtDelAll->bind_param('i', $documentId);
    $stmtDelAll->execute();
    return;
  }

  $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
  $types = str_repeat('i', count($projectIds));

  $stmtActive = $conn->prepare("
    SELECT id
    FROM projects
    WHERE is_active = 1
      AND id IN ($placeholders)
  ");
  $stmtActive->bind_param($types, ...$projectIds);
  $stmtActive->execute();
  $validIds = array_map(static fn(array $r): int => (int)($r['id'] ?? 0), $stmtActive->get_result()->fetch_all(MYSQLI_ASSOC) ?: []);
  $validIds = array_values(array_unique(array_filter($validIds, static fn(int $v): bool => $v > 0)));

  if ($validIds === []) {
    $stmtDelAll = $conn->prepare("DELETE FROM document_projects WHERE document_id = ?");
    $stmtDelAll->bind_param('i', $documentId);
    $stmtDelAll->execute();
    return;
  }

  $phValid = implode(',', array_fill(0, count($validIds), '?'));
  $typesDel = 'i' . str_repeat('i', count($validIds));
  $paramsDel = array_merge([$documentId], $validIds);

  $stmtDel = $conn->prepare("
    DELETE FROM document_projects
    WHERE document_id = ?
      AND project_id NOT IN ($phValid)
  ");
  $stmtDel->bind_param($typesDel, ...$paramsDel);
  $stmtDel->execute();

  $stmtIns = $conn->prepare("
    INSERT IGNORE INTO document_projects (document_id, project_id, added_by_user_id)
    VALUES (?, ?, ?)
  ");
  foreach ($validIds as $projectId) {
    $stmtIns->bind_param('iii', $documentId, $projectId, $addedByUserId);
    $stmtIns->execute();
  }
}

function normalize_project_code(string $value): string
{
  $value = trim($value);
  if ($value === '') {
    return '';
  }
  $value = preg_replace('/\s+/', ' ', $value) ?? $value;
  return strtoupper($value);
}

function parse_project_codes_input(string $raw): array
{
  if (trim($raw) === '') {
    return [];
  }
  $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
  $codes = [];
  foreach ($parts as $part) {
    $code = normalize_project_code((string)$part);
    if ($code !== '') {
      $codes[] = $code;
    }
  }
  return array_values(array_unique($codes));
}

function resolve_project_ids_for_document(mysqli $conn, array $projectIds, array $projectCodes): array
{
  if (!project_codes_tables_ready($conn)) {
    return [];
  }

  $resolved = [];

  $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds), static fn(int $v): bool => $v > 0)));
  if ($projectIds !== []) {
    $ph = implode(',', array_fill(0, count($projectIds), '?'));
    $types = str_repeat('i', count($projectIds));
    $stmtIds = $conn->prepare("
      SELECT id
      FROM projects
      WHERE id IN ($ph)
    ");
    $stmtIds->bind_param($types, ...$projectIds);
    $stmtIds->execute();
    $existingIds = $stmtIds->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    foreach ($existingIds as $row) {
      $resolved[] = (int)($row['id'] ?? 0);
    }
  }

  $projectCodes = array_values(array_unique(array_filter(array_map(
    static fn(string $v): string => normalize_project_code($v),
    $projectCodes
  ))));

  if ($projectCodes !== []) {
    $stmtByCode = $conn->prepare("
      SELECT id
      FROM projects
      WHERE project_code = ?
      LIMIT 1
    ");
    $stmtInsert = $conn->prepare("
      INSERT INTO projects (project_code, title, is_active)
      VALUES (?, ?, 1)
    ");

    foreach ($projectCodes as $code) {
      $stmtByCode->bind_param('s', $code);
      $stmtByCode->execute();
      $existing = $stmtByCode->get_result()->fetch_assoc();
      if ($existing) {
        $resolved[] = (int)($existing['id'] ?? 0);
        continue;
      }

      try {
        $title = $code;
        $stmtInsert->bind_param('ss', $code, $title);
        $stmtInsert->execute();
        $resolved[] = (int)$stmtInsert->insert_id;
      } catch (Throwable $e) {
        // Duplicate race or collation match; retry lookup.
        $stmtByCode->bind_param('s', $code);
        $stmtByCode->execute();
        $retry = $stmtByCode->get_result()->fetch_assoc();
        if ($retry) {
          $resolved[] = (int)($retry['id'] ?? 0);
        } else {
          throw $e;
        }
      }
    }
  }

  return array_values(array_unique(array_filter($resolved, static fn(int $v): bool => $v > 0)));
}

function can_manage_document_projects_for_identity(
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
  if ($isAdmin) {
    return true;
  }

  $branchMode = workflow_branch_mode_enabled($conn);
  if ($branchMode && workflow_document_has_real_branches($conn, $documentId)) {
    $stmtBranch = $conn->prepare("
      SELECT 1
      FROM document_branches
      WHERE document_id = ?
        AND branch_status = 'ACTIVE'
        AND current_assignee_user_id = ?
        AND is_reference = 0
      LIMIT 1
    ");
    $stmtBranch->bind_param('ii', $documentId, $userId);
    $stmtBranch->execute();
    return (bool)$stmtBranch->get_result()->fetch_row();
  }

  return workflow_user_can_act_legacy_document($conn, $documentId, $userId, $sectionId, $isChief, false);
}
