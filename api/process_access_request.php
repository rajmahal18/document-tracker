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

$id     = (int)($_POST["id"] ?? 0);
$action = trim((string)($_POST["action"] ?? "")); // APPROVE | REJECT
$notes  = trim((string)($_POST["admin_notes"] ?? ""));
$sectionId = (int)($_POST["section_id"] ?? 0); // required for approve

$officialTitle = trim((string)($_POST["official_title"] ?? ""));
$authorityRole = trim((string)($_POST["authority_role"] ?? "staff"));

$allowedAuthorityRoles = ["staff", "section_head", "division_assistant", "division_head", "director"];
$allowedOfficialTitles = ["", "Director II", "Division Chief", "Assistant Division Chief", "Section Chief", "Acting Section Chief"];

if (!in_array($authorityRole, $allowedAuthorityRoles, true)) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => "Invalid authority role."]);
  exit;
}

if (!in_array($officialTitle, $allowedOfficialTitles, true)) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => "Invalid official title."]);
  exit;
}

if ($id <= 0) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => "Invalid request id."]);
  exit;
}

if (!in_array($action, ["APPROVE", "REJECT"], true)) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => "Invalid action."]);
  exit;
}

if (mb_strlen($notes) > 500) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => "Admin notes max 500 chars."]);
  exit;
}

$adminId = (int)($_SESSION["user_id"] ?? 0);

// Fetch request (must be pending)
$stmt = $conn->prepare("SELECT id, full_name, email, office_section, reason, status FROM access_requests WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();

if (!$req) {
  http_response_code(404);
  echo json_encode(["ok" => false, "error" => "Request not found."]);
  exit;
}

if ((string)$req["status"] !== "PENDING") {
  http_response_code(409);
  echo json_encode(["ok" => false, "error" => "Request already processed."]);
  exit;
}

// REJECT path (simple)
if ($action === "REJECT") {
  $upd = $conn->prepare("UPDATE access_requests SET status='REJECTED', processed_by_user_id=?, processed_at=NOW(), admin_notes=? WHERE id=? AND status='PENDING'");
  $upd->bind_param("isi", $adminId, $notes, $id);
  $upd->execute();

  if ($upd->affected_rows <= 0) {
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Request already processed."]);
    exit;
  }

  echo json_encode(["ok" => true]);
  exit;
}

// APPROVE path (hybrid, no SMTP): create user + return temp password + email draft
if ($sectionId <= 0) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => "Please select a valid section/division."]);
  exit;
}

// Validate section exists
$secStmt = $conn->prepare("SELECT s.id, s.name AS section_name, d.name AS division_name FROM sections s LEFT JOIN divisions d ON d.id = s.division_id WHERE s.id = ? LIMIT 1");
$secStmt->bind_param("i", $sectionId);
$secStmt->execute();
$sec = $secStmt->get_result()->fetch_assoc();
if (!$sec) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => "Selected section does not exist."]);
  exit;
}

$email = trim((string)$req["email"]);
$fullName = trim((string)$req["full_name"]);

// Prevent duplicate emails
$dupe = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$dupe->bind_param("s", $email);
$dupe->execute();
$existing = $dupe->get_result()->fetch_assoc();
if ($existing) {
  http_response_code(409);
  echo json_encode(["ok" => false, "error" => "A user with this email already exists. Reject this request or use the existing account."]);
  exit;
}

// Generate a readable temp password
$temp = generate_temp_password(12);
$hash = password_hash($temp, PASSWORD_DEFAULT);

// Create user (role locked to 'user')
$role = "user";
$hasOfficialTitle = db_column_exists($conn, "users", "official_title");
$hasAuthorityRole = db_column_exists($conn, "users", "authority_role");
$legacyIsChief = in_array($authorityRole, ["director", "division_head", "section_head"], true) ? 1 : 0;

if ($hasOfficialTitle && $hasAuthorityRole) {
  $ins = $conn->prepare("
    INSERT INTO users (
      full_name,
      email,
      password_hash,
      role,
      section_id,
      is_chief,
      official_title,
      authority_role,
      must_change_password
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
  ");
  $ins->bind_param("ssssiiss", $fullName, $email, $hash, $role, $sectionId, $legacyIsChief, $officialTitle, $authorityRole);
} else {
  $ins = $conn->prepare("
    INSERT INTO users (
      full_name,
      email,
      password_hash,
      role,
      section_id,
      is_chief,
      must_change_password
    ) VALUES (?, ?, ?, ?, ?, ?, 1)
  ");
  $ins->bind_param("ssssii", $fullName, $email, $hash, $role, $sectionId, $legacyIsChief);
}

if (!$ins->execute()) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Failed to create user account."]);
  exit;
}

// Mark request approved
$upd = $conn->prepare("UPDATE access_requests SET status='APPROVED', processed_by_user_id=?, processed_at=NOW(), admin_notes=? WHERE id=? AND status='PENDING'");
$upd->bind_param("isi", $adminId, $notes, $id);
$upd->execute();

if ($upd->affected_rows <= 0) {
  // Edge case: request flipped status after user create.
  // Keep user; admin can handle manually.
  http_response_code(409);
  echo json_encode(["ok" => false, "error" => "Request was processed by another admin. User account was created; please check Users list."]);
  exit;
}

// Build email draft
$loginUrl = (string)($_POST["login_url"] ?? "");
if ($loginUrl === "") {
  // Fallback to current host + known public login path
  $loginUrl = app_url(PUBLIC_PATH . '/login.php');
}

$subject = "Document Tracker Access Approved";
$orgLine = trim(((string)($sec['division_name'] ?? '')) . " — " . ((string)($sec['section_name'] ?? '')));

$body = "Hello {$fullName},\n\n"
  . "Your request for access to the Document Tracker has been APPROVED." . "\n\n"
  . ($orgLine !== "—" ? "Assigned Office/Section: {$orgLine}\n\n" : "")
  . "Login URL: {$loginUrl}\n"
  . "Username: {$email}\n"
  . "Temporary Password: {$temp}\n\n"
  . "For security, you will be required to change your password after logging in." . "\n\n"
  . "If you encounter issues accessing the system, please contact the system administrator." . "\n\n"
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
