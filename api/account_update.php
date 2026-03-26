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

$userId = (int)($_SESSION['user_id'] ?? 0);
$fullName = normalize_whitespace((string)($_POST['full_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));

if ($userId <= 0) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
  exit;
}
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

$check = $conn->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
$check->bind_param('i', $userId);
$check->execute();
$current = $check->get_result()->fetch_assoc();
$check->close();
if (!$current) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'User not found.']);
  exit;
}

$dup = $conn->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
$dup->bind_param('si', $email, $userId);
$dup->execute();
if ($dup->get_result()->fetch_assoc()) {
  $dup->close();
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Email is already in use by another account.']);
  exit;
}
$dup->close();

$hasUsername = username_column_exists($conn);
$hasEmailVerifiedAt = email_verified_at_column_exists($conn);
$username = $hasUsername ? generate_unique_username($conn, $fullName, $userId) : '';
$emailChanged = strcasecmp((string)$current['email'], $email) !== 0;

if ($hasUsername && $hasEmailVerifiedAt) {
  if ($emailChanged) {
    $stmt = $conn->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, email_verified_at = NULL WHERE id = ?');
    $stmt->bind_param('sssi', $fullName, $username, $email, $userId);
  } else {
    $stmt = $conn->prepare('UPDATE users SET full_name = ?, username = ?, email = ? WHERE id = ?');
    $stmt->bind_param('sssi', $fullName, $username, $email, $userId);
  }
} elseif ($hasUsername) {
  $stmt = $conn->prepare('UPDATE users SET full_name = ?, username = ?, email = ? WHERE id = ?');
  $stmt->bind_param('sssi', $fullName, $username, $email, $userId);
} elseif ($hasEmailVerifiedAt) {
  if ($emailChanged) {
    $stmt = $conn->prepare('UPDATE users SET full_name = ?, email = ?, email_verified_at = NULL WHERE id = ?');
    $stmt->bind_param('ssi', $fullName, $email, $userId);
  } else {
    $stmt = $conn->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?');
    $stmt->bind_param('ssi', $fullName, $email, $userId);
  }
} else {
  $stmt = $conn->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?');
  $stmt->bind_param('ssi', $fullName, $email, $userId);
}

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to update account details.']);
  exit;
}
$stmt->close();

refresh_session_identity($conn, $userId);

echo json_encode([
  'ok' => true,
  'message' => 'Account details updated successfully.',
  'username' => $username,
]);
