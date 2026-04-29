<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

require_login();
require_csrf();

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
  exit;
}

if (!email_verified_at_column_exists($conn)) {
  http_response_code(409);
  echo json_encode(['ok' => false, 'error' => 'Email verification is not available in this database yet.']);
  exit;
}

$stmt = $conn->prepare('SELECT id, full_name, email, email_verified_at, is_active FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || (int)($user['is_active'] ?? 0) !== 1) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Active user not found.']);
  exit;
}

if (!empty($user['email_verified_at'])) {
  echo json_encode(['ok' => true, 'message' => 'Your email is already verified.']);
  exit;
}

$toEmail = trim((string)($user['email'] ?? ''));
if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Please add a valid email address first.']);
  exit;
}

$issue = email_verification_issue_token($conn, $userId, (string)($_SERVER['REMOTE_ADDR'] ?? ''), 24);
if (empty($issue['ok'])) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => (string)($issue['error'] ?? 'Failed to create verification token.')]);
  exit;
}

$token = (string)($issue['token'] ?? '');
$verifyUrl = app_url(PUBLIC_PATH . '/verify_email.php?token=' . rawurlencode($token));
$safeName = htmlspecialchars((string)($user['full_name'] ?? 'User'), ENT_QUOTES, 'UTF-8');
$safeVerifyUrl = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');
$safeExpires = htmlspecialchars((string)($issue['expires_at'] ?? ''), ENT_QUOTES, 'UTF-8');

$subject = 'Verify your email - MPW Document Tracker';
$htmlBody = <<<HTML
<p>Hello {$safeName},</p>
<p>Please verify your email for your MPW Document Tracker account.</p>
<p><a href="{$safeVerifyUrl}">Verify Email Address</a></p>
<p>This link expires on: {$safeExpires} (Asia/Manila).</p>
HTML;
$textBody = "Hello {$user['full_name']},\nPlease verify your email for MPW Document Tracker.\n"
  . "Verify link: {$verifyUrl}\n"
  . "This link expires on: " . (string)($issue['expires_at'] ?? '') . " (Asia/Manila).\n";

$send = app_send_mail($toEmail, (string)($user['full_name'] ?? ''), $subject, $htmlBody, $textBody);
if (empty($send['ok'])) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => (string)($send['error'] ?? 'Failed to send verification email.')]);
  exit;
}

echo json_encode([
  'ok' => true,
  'message' => 'Verification email sent. Please check your inbox.',
]);
