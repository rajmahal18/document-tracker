<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

require_admin();
require_csrf();

$id = (int)($_POST['id'] ?? 0);
$fullName = normalize_whitespace((string)($_POST['full_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$role = trim((string)($_POST['role'] ?? 'user'));
$sectionId = (int)($_POST['section_id'] ?? 0);
$officialTitle = trim((string)($_POST['official_title'] ?? ''));
$authorityRole = trim((string)($_POST['authority_role'] ?? 'staff'));
$isActive = ((int)($_POST['is_active'] ?? 1) === 1) ? 1 : 0;
$isChief = ((int)($_POST['is_chief'] ?? 0) === 1) ? 1 : 0;

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
if (!in_array($role, ['user', 'admin'], true)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid system role.']);
  exit;
}
if (!in_array($authorityRole, ['staff', 'section_head', 'division_assistant', 'division_head', 'director', 'admin'], true)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid authority role.']);
  exit;
}
if ($sectionId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Please assign a valid section.']);
  exit;
}
if ($role === 'admin') {
  $authorityRole = 'admin';
}
if (in_array($authorityRole, ['director', 'division_head', 'section_head'], true)) {
  $isChief = 1;
}

$sectionStmt = $conn->prepare('SELECT id FROM sections WHERE id = ? AND is_active = 1 LIMIT 1');
$sectionStmt->bind_param('i', $sectionId);
$sectionStmt->execute();
$sectionExists = $sectionStmt->get_result()->fetch_assoc();
$sectionStmt->close();
if (!$sectionExists) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Selected section was not found.']);
  exit;
}

$dup = $conn->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
$dup->bind_param('si', $email, $id);
$dup->execute();
if ($dup->get_result()->fetch_assoc()) {
  $dup->close();
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Email is already in use by another account.']);
  exit;
}
$dup->close();

$hasUsername = username_column_exists($conn);
$hasOfficialTitle = db_column_exists($conn, 'users', 'official_title');
$hasAuthorityRole = db_column_exists($conn, 'users', 'authority_role');

if ($id > 0) {
  $check = $conn->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
  $check->bind_param('i', $id);
  $check->execute();
  if (!$check->get_result()->fetch_assoc()) {
    $check->close();
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'User not found.']);
    exit;
  }
  $check->close();

  $username = $hasUsername ? generate_unique_username($conn, $fullName, $id) : '';

  if ($hasUsername && $hasOfficialTitle && $hasAuthorityRole) {
    $stmt = $conn->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, role = ?, section_id = ?, is_active = ?, is_chief = ?, official_title = ?, authority_role = ? WHERE id = ?');
    $stmt->bind_param('ssssiiissi', $fullName, $username, $email, $role, $sectionId, $isActive, $isChief, $officialTitle, $authorityRole, $id);
  } elseif ($hasUsername && $hasAuthorityRole) {
    $stmt = $conn->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, role = ?, section_id = ?, is_active = ?, is_chief = ?, authority_role = ? WHERE id = ?');
    $stmt->bind_param('ssssiissi', $fullName, $username, $email, $role, $sectionId, $isActive, $isChief, $authorityRole, $id);
  } elseif ($hasUsername) {
    $stmt = $conn->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, role = ?, section_id = ?, is_active = ?, is_chief = ? WHERE id = ?');
    $stmt->bind_param('ssssiiii', $fullName, $username, $email, $role, $sectionId, $isActive, $isChief, $id);
  } else {
    $stmt = $conn->prepare('UPDATE users SET full_name = ?, email = ?, role = ?, section_id = ?, is_active = ?, is_chief = ? WHERE id = ?');
    $stmt->bind_param('sssiiii', $fullName, $email, $role, $sectionId, $isActive, $isChief, $id);
  }

  if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to update user.']);
    exit;
  }
  $stmt->close();

  refresh_session_identity_if_same_user($conn, $id);

  echo json_encode([
    'ok' => true,
    'message' => 'User updated successfully.',
    'username' => $hasUsername ? $username : $email,
  ]);
  exit;
}

$tempPassword = generate_temp_password();
$passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
$username = $hasUsername ? generate_unique_username($conn, $fullName) : '';

if ($hasUsername && $hasOfficialTitle && $hasAuthorityRole) {
  $stmt = $conn->prepare('INSERT INTO users (full_name, username, email, password_hash, role, section_id, is_active, is_chief, official_title, authority_role, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
  $stmt->bind_param('sssssiiiss', $fullName, $username, $email, $passwordHash, $role, $sectionId, $isActive, $isChief, $officialTitle, $authorityRole);
} elseif ($hasUsername && $hasAuthorityRole) {
  $stmt = $conn->prepare('INSERT INTO users (full_name, username, email, password_hash, role, section_id, is_active, is_chief, authority_role, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
  $stmt->bind_param('sssssiiis', $fullName, $username, $email, $passwordHash, $role, $sectionId, $isActive, $isChief, $authorityRole);
} elseif ($hasUsername) {
  $stmt = $conn->prepare('INSERT INTO users (full_name, username, email, password_hash, role, section_id, is_active, is_chief, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
  $stmt->bind_param('sssssiii', $fullName, $username, $email, $passwordHash, $role, $sectionId, $isActive, $isChief);
} else {
  $stmt = $conn->prepare('INSERT INTO users (full_name, email, password_hash, role, section_id, is_active, is_chief, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
  $stmt->bind_param('ssssiii', $fullName, $email, $passwordHash, $role, $sectionId, $isActive, $isChief);
}

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to create user.']);
  exit;
}
$stmt->close();

echo json_encode([
  'ok' => true,
  'message' => 'User created successfully. Copy the temporary password now.',
  'username' => $hasUsername ? $username : $email,
  'email' => $email,
  'temp_password' => $tempPassword,
]);

function generate_temp_password(int $length = 12): string {
  $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
  $max = strlen($alphabet) - 1;
  $out = '';
  for ($i = 0; $i < $length; $i++) {
    $out .= $alphabet[random_int(0, $max)];
  }
  return substr($out, 0, 4) . '-' . substr($out, 4, 4) . '-' . substr($out, 8);
}

function refresh_session_identity_if_same_user(mysqli $conn, int $updatedUserId): void {
  if ((int)($_SESSION['user_id'] ?? 0) === $updatedUserId) {
    refresh_session_identity($conn, $updatedUserId);
  }
}
