<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "Method not allowed"]);
  exit;
}

require_admin();
require_csrf();

$id = (int)($_POST["id"] ?? 0);
if ($id <= 0) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => "Invalid request id."]);
  exit;
}

// Fetch request (must be approved)
$stmt = $conn->prepare("SELECT id, full_name, email, status FROM access_requests WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();

if (!$req) {
  http_response_code(404);
  echo json_encode(["ok" => false, "error" => "Request not found."]);
  exit;
}

if ((string)$req["status"] !== "APPROVED") {
  http_response_code(409);
  echo json_encode(["ok" => false, "error" => "Credentials are available only for APPROVED requests."]);
  exit;
}

$email = trim((string)$req["email"]);
$fullName = trim((string)$req["full_name"]);

// Find the created user account
$u = $conn->prepare("
  SELECT
    u.id,
    u.email,
    u.full_name,
    u.must_change_password,
    s.name AS section_name,
    d.name AS division_name
  FROM users u
  LEFT JOIN sections s ON s.id = u.section_id
  LEFT JOIN divisions d ON d.id = s.division_id
  WHERE u.email = ?
  LIMIT 1
");
$u->bind_param("s", $email);
$u->execute();
$user = $u->get_result()->fetch_assoc();

if (!$user) {
  http_response_code(404);
  echo json_encode(["ok" => false, "error" => "Approved request exists but user account was not found (email mismatch?)."]);
  exit;
}

// Generate NEW temp password + force password change on next login
$temp = generate_temp_password(12);
$hash = password_hash($temp, PASSWORD_DEFAULT);

$upd = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?");
$uid = (int)$user["id"];
$upd->bind_param("si", $hash, $uid);
$upd->execute();

// Optional audit trail on the request record (if columns exist, ignore errors safely)
try {
  $adminId = (int)($_SESSION["user_id"] ?? 0);
  $audit = $conn->prepare("UPDATE access_requests SET last_cred_reset_by_user_id = ?, last_cred_reset_at = NOW() WHERE id = ?");
  $audit->bind_param("ii", $adminId, $id);
  $audit->execute();
} catch (Throwable $e) {
  // no-op (columns may not exist)
}

// Build email draft
$loginUrl = (string)($_POST["login_url"] ?? "");
if ($loginUrl === "") {
  $loginUrl = app_url(PUBLIC_PATH . '/login.php');
}

$subject = "Document Tracker Access Credentials";
$orgLine = trim(((string)($user['division_name'] ?? '')) . " — " . ((string)($user['section_name'] ?? '')));

$body = "Hello {$fullName},\n\n"
  . "Here are your Document Tracker login credentials:\n\n"
  . ($orgLine !== "—" ? "Assigned Office/Section: {$orgLine}\n\n" : "")
  . "Login URL: {$loginUrl}\n"
  . "Username: {$email}\n"
  . "Temporary Password: {$temp}\n\n"
  . "For security, you will be required to change your password after logging in.\n\n"
  . "If you encounter issues accessing the system, please contact the system administrator.\n\n"
  . "Thank you.";

echo json_encode([
  "ok" => true,
  "username" => $email,
  "temp_password" => $temp,
  "email_subject" => $subject,
  "email_body" => $body,
]);

// --- helpers ---
function generate_temp_password(int $length = 12): string {
  // Avoid confusing characters: 0 O o, 1 l I
  $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
  $max = strlen($alphabet) - 1;

  $out = '';
  for ($i = 0; $i < $length; $i++) {
    $out .= $alphabet[random_int(0, $max)];
  }
  // Make it a bit more readable
  return substr($out, 0, 4) . '-' . substr($out, 4, 4) . '-' . substr($out, 8);
}
