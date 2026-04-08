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

$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid user ID.']);
  exit;
}

$stmt = $conn->prepare('SELECT id, email, COALESCE(username, "") AS username FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'User not found.']);
  exit;
}

$tempPassword = generate_temp_password();
$passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
$upd = $conn->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?');
$upd->bind_param('si', $passwordHash, $userId);
if (!$upd->execute()) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to reset password.']);
  exit;
}
$upd->close();

echo json_encode([
  'ok' => true,
  'message' => 'Temporary password generated successfully.',
  'username' => trim((string)($user['username'] ?? '')) !== '' ? (string)$user['username'] : (string)$user['email'],
  'email' => (string)$user['email'],
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
