<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "Method not allowed"]);
  exit;
}

/**
 * ✅ Compatible with your system:
 * - Uses require_csrf() which expects POST csrf_token
 * - Does NOT require_login() because this is for non-users
 */
require_csrf();

$fullName = trim((string)($_POST["full_name"] ?? ""));
$office   = trim((string)($_POST["office_section"] ?? ""));
$email    = trim((string)($_POST["email"] ?? ""));
$reason   = trim((string)($_POST["reason"] ?? ""));

$errors = [];
if ($fullName === "" || mb_strlen($fullName) > 150) $errors[] = "Full Name is required (max 150 chars).";
if ($office === "" || mb_strlen($office) > 150) $errors[] = "Office/Section is required (max 150 chars).";
if ($email === "" || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid Email is required.";
if ($reason === "" || mb_strlen($reason) > 500) $errors[] = "Reason is required (max 500 chars).";

if ($errors) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => implode(" ", $errors)]);
  exit;
}

// Optional: basic spam protection (session-based)
$_SESSION["access_req_count"] = ($_SESSION["access_req_count"] ?? 0) + 1;
if ((int)$_SESSION["access_req_count"] > 15) {
  http_response_code(429);
  echo json_encode(["ok" => false, "error" => "Too many requests. Please try again later."]);
  exit;
}

$stmt = $conn->prepare("
  INSERT INTO access_requests (full_name, office_section, email, reason)
  VALUES (?, ?, ?, ?)
");
$stmt->bind_param("ssss", $fullName, $office, $email, $reason);

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Failed to submit request. Please try again."]);
  exit;
}

echo json_encode(["ok" => true, "message" => "Request submitted. ICT/Admin will review your request."]);