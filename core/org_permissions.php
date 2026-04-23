<?php
declare(strict_types=1);

function org_role_rank(string $authorityRole): int {
  return match (trim($authorityRole)) {
    'director', 'division_head' => 100,
    'division_assistant' => 80,
    'section_head' => 60,
    'admin' => 120,
    default => 20,
  };
}

function current_org_editor_context(): array {
  $sessionRole = strtolower(trim((string)($_SESSION['role'] ?? 'user')));
  return [
    'user_id' => (int)($_SESSION['user_id'] ?? 0),
    'division_id' => (int)($_SESSION['division_id'] ?? 0),
    'section_id' => (int)($_SESSION['section_id'] ?? 0),
    'authority_role' => trim((string)($_SESSION['authority_role'] ?? 'staff')),
    'role_rank' => org_role_rank(trim((string)($_SESSION['authority_role'] ?? 'staff'))),
    'is_admin' => $sessionRole === 'admin',
  ];
}

function can_edit_any_org_user(): bool {
  $ctx = current_org_editor_context();
  if ($ctx['is_admin']) return true;
  return in_array($ctx['authority_role'], ['director', 'division_head', 'division_assistant', 'section_head'], true);
}

function can_edit_org_target(array $editor, array $target): bool {
  $editorId = (int)($editor['user_id'] ?? 0);
  $targetId = (int)($target['id'] ?? 0);
  if ($editorId <= 0 || $targetId <= 0) {
    return false;
  }
  if (!empty($editor['is_admin'])) {
    return true;
  }
  if ($editorId === $targetId) {
    return false;
  }

  $editorRole = trim((string)($editor['authority_role'] ?? 'staff'));
  $editorRank = (int)($editor['role_rank'] ?? org_role_rank($editorRole));
  $targetRank = org_role_rank((string)($target['authority_role'] ?? 'staff'));

  if ($targetRank >= $editorRank) {
    return false;
  }

  $editorDivisionId = (int)($editor['division_id'] ?? 0);
  $editorSectionId = (int)($editor['section_id'] ?? 0);
  $targetDivisionId = (int)($target['division_id'] ?? 0);
  $targetSectionId = (int)($target['section_id'] ?? 0);

  return match ($editorRole) {
    'director', 'division_head', 'division_assistant' => $editorDivisionId > 0 && $editorDivisionId === $targetDivisionId,
    'section_head' => $editorSectionId > 0 && $editorSectionId === $targetSectionId,
    default => false,
  };
}

function org_assignable_roles_for_editor(array $editor): array {
  if (!empty($editor['is_admin'])) {
    return ['director', 'division_head', 'division_assistant', 'section_head', 'staff'];
  }

  $editorRank = (int)($editor['role_rank'] ?? 0);
  $all = [
    'director' => 'Director',
    'division_head' => 'Division Chief',
    'division_assistant' => 'Division Assistant',
    'section_head' => 'Section Head',
    'staff' => 'Staff',
  ];

  $allowed = [];
  foreach ($all as $key => $label) {
    if (org_role_rank($key) < $editorRank) {
      $allowed[$key] = $label;
    }
  }
  return $allowed;
}


function org_user_is_assistant_assignable_principal(string $authorityRole): bool {
  return in_array(trim($authorityRole), ['director', 'division_head', 'section_head'], true);
}

function can_assign_assistant_for_target(array $editor, array $target): bool {
  $editorId = (int)($editor['user_id'] ?? 0);
  $targetId = (int)($target['id'] ?? 0);
  if ($editorId <= 0 || $targetId <= 0) {
    return false;
  }
  if (!org_user_is_assistant_assignable_principal((string)($target['authority_role'] ?? ''))) {
    return false;
  }
  if (!empty($editor['is_admin'])) {
    return true;
  }

  $editorRole = trim((string)($editor['authority_role'] ?? 'staff'));
  $editorDivisionId = (int)($editor['division_id'] ?? 0);
  $editorSectionId = (int)($editor['section_id'] ?? 0);
  $targetDivisionId = (int)($target['division_id'] ?? 0);
  $targetSectionId = (int)($target['section_id'] ?? 0);

  return match ($editorRole) {
    'director' => $editorDivisionId > 0 && $editorDivisionId === $targetDivisionId,
    'division_head' => $editorDivisionId > 0
      && $editorDivisionId === $targetDivisionId
      && ($targetId === $editorId || trim((string)($target['authority_role'] ?? '')) === 'section_head'),
    'section_head' => $targetId === $editorId && $editorSectionId > 0 && $editorSectionId === $targetSectionId,
    default => false,
  };
}

function org_assistant_scope_for_target(array $target): array {
  $targetRole = trim((string)($target['authority_role'] ?? ''));
  return match ($targetRole) {
    'section_head' => [
      'division_id' => (int)($target['division_id'] ?? 0),
      'section_id' => (int)($target['section_id'] ?? 0),
    ],
    'director', 'division_head' => [
      'division_id' => (int)($target['division_id'] ?? 0),
      'section_id' => 0,
    ],
    default => [
      'division_id' => 0,
      'section_id' => 0,
    ],
  };
}

function org_fetch_assistant_candidates(mysqli $conn, array $target): array {
  if (!org_user_is_assistant_assignable_principal((string)($target['authority_role'] ?? ''))) {
    return [];
  }

  $scope = org_assistant_scope_for_target($target);
  $divisionId = (int)($scope['division_id'] ?? 0);
  $sectionId = (int)($scope['section_id'] ?? 0);
  $targetUserId = (int)($target['id'] ?? 0);
  if ($divisionId <= 0 || $targetUserId <= 0) {
    return [];
  }

  $hasOfficialTitle = function_exists('db_column_exists') ? db_column_exists($conn, 'users', 'official_title') : false;
  $hasAuthorityRole = function_exists('db_column_exists') ? db_column_exists($conn, 'users', 'authority_role') : false;

  $sql = 'SELECT u.id, u.full_name, u.role, u.is_chief, '
    . ($hasOfficialTitle ? 'u.official_title' : 'NULL') . ' AS official_title, '
    . ($hasAuthorityRole ? 'u.authority_role' : 'NULL') . ' AS authority_role, '
    . 's.id AS section_id, s.name AS section_name, d.id AS division_id, d.name AS division_name '
    . 'FROM users u '
    . 'JOIN sections s ON s.id = u.section_id '
    . 'JOIN divisions d ON d.id = s.division_id '
    . 'WHERE u.is_active = 1 AND s.is_active = 1 AND d.is_active = 1 AND d.id = ?';
  $types = 'i';
  $params = [$divisionId];
  if ($sectionId > 0) {
    $sql .= ' AND s.id = ?';
    $types .= 'i';
    $params[] = $sectionId;
  }
  $sql .= ' ORDER BY u.full_name ASC';

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return [];
  }
  $bind = [];
  $bind[] = &$types;
  foreach ($params as $k => $v) {
    $bind[] = &$params[$k];
  }
  call_user_func_array([$stmt, 'bind_param'], $bind);
  $stmt->execute();
  $result = $stmt->get_result();

  $rows = [];
  while ($row = $result->fetch_assoc()) {
    $resolvedRole = trim((string)($row['authority_role'] ?? ''));
    if ($resolvedRole === '') {
      if ((string)($row['role'] ?? '') === 'admin') {
        $resolvedRole = 'admin';
      } elseif ((int)($row['is_chief'] ?? 0) === 1) {
        $resolvedRole = 'section_head';
      } else {
        $resolvedRole = 'staff';
      }
    }
    if ($resolvedRole !== 'staff') {
      continue;
    }

    $userId = (int)($row['id'] ?? 0);
    if ($userId <= 0 || $userId === $targetUserId) {
      continue;
    }

    $rows[] = [
      'id' => $userId,
      'full_name' => (string)($row['full_name'] ?? ''),
      'display_title' => trim((string)($row['official_title'] ?? '')),
      'section_id' => (int)($row['section_id'] ?? 0),
      'section_name' => (string)($row['section_name'] ?? ''),
      'division_id' => (int)($row['division_id'] ?? 0),
      'division_name' => (string)($row['division_name'] ?? ''),
    ];
  }
  $stmt->close();

  return $rows;
}
