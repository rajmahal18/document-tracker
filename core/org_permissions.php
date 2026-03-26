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
  return [
    'user_id' => (int)($_SESSION['user_id'] ?? 0),
    'division_id' => (int)($_SESSION['division_id'] ?? 0),
    'section_id' => (int)($_SESSION['section_id'] ?? 0),
    'authority_role' => trim((string)($_SESSION['authority_role'] ?? 'staff')),
    'role_rank' => org_role_rank(trim((string)($_SESSION['authority_role'] ?? 'staff'))),
    'is_admin' => (string)($_SESSION['role'] ?? 'user') === 'admin',
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
  if ($editorId <= 0 || $targetId <= 0 || $editorId === $targetId) {
    return false;
  }
  if (!empty($editor['is_admin'])) {
    return true;
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
