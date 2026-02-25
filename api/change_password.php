<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "Method not allowed"]);
  exit;
}

require_login();
require_csrf();

$userId = (int)($_SESSION["user_id"] ?? 0);

$current = (string)($_POST["current_password"] ?? "");
$new     = (string)($_POST["new_password"] ?? "");
$confirm = (string)($_POST["confirm_password"] ?? "");

if ($current === "" || $new === "" || $confirm === "") {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => "All fields are required."]);
  exit;
}

if ($new !== $confirm) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => "New password and confirmation do not match."]);
  exit;
}

if (mb_strlen($new) < 8) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => "New password must be at least 8 characters."]);
  exit;
}

// Get current hash
$stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row || !password_verify($current, (string)($row["password_hash"] ?? ""))) {
  http_response_code(401);
  echo json_encode(["ok" => false, "error" => "Current password is incorrect."]);
  exit;
}

$newHash = password_hash($new, PASSWORD_DEFAULT);

$upd = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?");
$upd->bind_param("si", $newHash, $userId);

if (!$upd->execute()) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Failed to update password."]);
  exit;
}

// Update session too
$_SESSION["must_change_password"] = 0;

echo json_encode(["ok" => true]);
