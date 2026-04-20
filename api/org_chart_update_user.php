<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

require_login();
require_csrf();

$editor = current_org_editor_context();
if (!can_edit_any_org_user()) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'You are not allowed to edit the org chart.']);
  exit;
}

$targetUserId = (int)($_POST['target_user_id'] ?? 0);
$fullName = normalize_whitespace((string)($_POST['full_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$officialTitle = normalize_whitespace((string)($_POST['official_title'] ?? ''));
$authorityRole = trim((string)($_POST['authority_role'] ?? 'staff'));
$permanent = isset($_POST['permanent']) && (string)($_POST['permanent']) === '1' ? 1 : 0;
$hasChiefAssistant = db_column_exists($conn, 'users', 'chief_assistant_user_id');
$hasAssistantAssignments = assistant_assignments_table_ready($conn);
$rawAssistantIds = $_POST['chief_assistant_user_ids'] ?? ($_POST['chief_assistant_user_id'] ?? []);
if (!is_array($rawAssistantIds)) {
  $rawAssistantIds = [$rawAssistantIds];
}
$chiefAssistantUserIds = [];
foreach ($rawAssistantIds as $rawAssistantId) {
  $assistantId = (int)$rawAssistantId;
  if ($assistantId > 0) {
    $chiefAssistantUserIds[$assistantId] = $assistantId;
  }
}
$chiefAssistantUserIds = array_values($chiefAssistantUserIds);
$chiefAssistantUserId = $chiefAssistantUserIds[0] ?? 0;

if ($targetUserId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Missing target user.']);
  exit;
}

$targetSql = 'SELECT u.id, u.full_name, u.email, u.section_id, u.role, u.is_chief, '
  . (db_column_exists($conn, 'users', 'permanent') ? 'u.permanent' : '0') . ' AS permanent, '
  . (db_column_exists($conn, 'users', 'official_title') ? 'u.official_title' : 'NULL') . ' AS official_title, '
  . (db_column_exists($conn, 'users', 'authority_role') ? 'u.authority_role' : 'NULL') . ' AS authority_role, '
  . ($hasChiefAssistant ? 'u.chief_assistant_user_id' : 'NULL') . ' AS chief_assistant_user_id, '
  . 's.name AS section_name, s.id AS resolved_section_id, d.id AS division_id, d.name AS division_name '
  . 'FROM users u '
  . 'LEFT JOIN sections s ON s.id = u.section_id '
  . 'LEFT JOIN divisions d ON d.id = s.division_id '
  . 'WHERE u.id = ? LIMIT 1';
$stmt = $conn->prepare($targetSql);
$stmt->bind_param('i', $targetUserId);
$stmt->execute();
$target = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$target) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Target user not found.']);
  exit;
}

$targetRole = trim((string)($target['authority_role'] ?? ''));
if ($targetRole === '') {
  $targetRole = ((int)($target['is_chief'] ?? 0) === 1) ? 'section_head' : 'staff';
}
$target['authority_role'] = $targetRole;

$canEditBasic = can_edit_org_target($editor, $target);
$canAssignAssistant = ($hasChiefAssistant || $hasAssistantAssignments) && can_assign_assistant_for_target($editor, $target);
if (!$canEditBasic && !$canAssignAssistant) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'You are not allowed to update this user.']);
  exit;
}

if ($canEditBasic) {
  if ($fullName === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Full name is required.']);
    exit;
  }
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'A valid email is required.']);
    exit;
  }

  $allowedRoles = array_keys(org_assignable_roles_for_editor($editor));
  if (!in_array($authorityRole, $allowedRoles, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'That authority role is not allowed for your scope.']);
    exit;
  }
  if (org_role_rank($authorityRole) >= (int)($editor['role_rank'] ?? 0) && empty($editor['is_admin'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'You cannot assign a role equal to or higher than your own.']);
    exit;
  }

  $dup = $conn->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
  $dup->bind_param('si', $email, $targetUserId);
  $dup->execute();
  if ($dup->get_result()->fetch_assoc()) {
    $dup->close();
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Email is already in use by another account.']);
    exit;
  }
  $dup->close();
} else {
  $fullName = (string)($target['full_name'] ?? '');
  $email = (string)($target['email'] ?? '');
  $officialTitle = trim((string)($target['official_title'] ?? ''));
  $authorityRole = $targetRole;
  $permanent = (int)($target['permanent'] ?? 0);
}

if ($canAssignAssistant) {
  $candidateIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), org_fetch_assistant_candidates($conn, $target));
  foreach ($chiefAssistantUserIds as $assistantId) {
    if (!in_array($assistantId, $candidateIds, true)) {
      http_response_code(422);
      echo json_encode(['ok' => false, 'error' => 'One or more selected assistants are outside your allowed domain or not eligible staff users.']);
      exit;
    }
  }
} else {
  $chiefAssistantUserId = (int)($target['chief_assistant_user_id'] ?? 0);
  $chiefAssistantUserIds = $chiefAssistantUserId > 0 ? [$chiefAssistantUserId] : [];
}

$hasUsername = username_column_exists($conn);
$hasPermanent = db_column_exists($conn, 'users', 'permanent');
$hasEmailVerifiedAt = email_verified_at_column_exists($conn);
$username = $hasUsername ? generate_unique_username($conn, $fullName, $targetUserId) : '';
$isChief = in_array($authorityRole, ['director', 'division_head', 'section_head'], true) ? 1 : 0;
$emailChanged = strcasecmp((string)($target['email'] ?? ''), $email) !== 0;

$fields = ['full_name = ?', 'email = ?', 'official_title = ?', 'authority_role = ?', 'is_chief = ?'];
$types = 'ssssi';
$params = [$fullName, $email, $officialTitle, $authorityRole, $isChief];

if ($hasUsername) {
  $fields[] = 'username = ?';
  $types .= 's';
  $params[] = $username;
}
if ($hasPermanent) {
  $fields[] = 'permanent = ?';
  $types .= 'i';
  $params[] = $permanent;
}
if ($hasChiefAssistant) {
  if ($chiefAssistantUserId > 0) {
    $fields[] = 'chief_assistant_user_id = ?';
    $types .= 'i';
    $params[] = $chiefAssistantUserId;
  } else {
    $fields[] = 'chief_assistant_user_id = NULL';
  }
}
if ($hasEmailVerifiedAt && $emailChanged) {
  $fields[] = 'email_verified_at = NULL';
}
$types .= 'i';
$params[] = $targetUserId;

$sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ? LIMIT 1';
$upd = $conn->prepare($sql);
$bind = [];
$bind[] = &$types;
foreach ($params as $k => $v) {
  $bind[] = &$params[$k];
}
call_user_func_array([$upd, 'bind_param'], $bind);
if (!$upd->execute()) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to update org user.']);
  exit;
}
$upd->close();

if ($canAssignAssistant && $hasAssistantAssignments) {
  $del = $conn->prepare('DELETE FROM principal_assistants WHERE principal_user_id = ?');
  if (!$del) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to update assistant assignments.']);
    exit;
  }
  $del->bind_param('i', $targetUserId);
  $del->execute();
  $del->close();

  if ($chiefAssistantUserIds !== []) {
    $assignedBy = (int)($_SESSION['user_id'] ?? 0);
    $ins = $conn->prepare('
      INSERT IGNORE INTO principal_assistants
        (principal_user_id, assistant_user_id, assigned_by_user_id)
      VALUES (?, ?, ?)
    ');
    if (!$ins) {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error' => 'Failed to update assistant assignments.']);
      exit;
    }
    foreach ($chiefAssistantUserIds as $assistantId) {
      $ins->bind_param('iii', $targetUserId, $assistantId, $assignedBy);
      $ins->execute();
    }
    $ins->close();
  }
}

if ($targetUserId === (int)($_SESSION['user_id'] ?? 0)) {
  refresh_session_identity($conn, $targetUserId);
}

echo json_encode(['ok' => true, 'message' => 'Org user updated successfully.', 'username' => $username]);
