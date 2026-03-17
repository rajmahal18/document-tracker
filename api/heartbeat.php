<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "Method not allowed"]);
  exit;
}

if (!is_logged_in()) {
  http_response_code(401);
  echo json_encode(["ok" => false, "error" => "Unauthorized"]);
  exit;
}

require_csrf();

if (!db_column_exists($conn, "users", "last_seen_at")) {
  echo json_encode(["ok" => true, "supported" => false]);
  exit;
}

$userId = (int)($_SESSION["user_id"] ?? 0);
if ($userId <= 0) {
  http_response_code(401);
  echo json_encode(["ok" => false, "error" => "Unauthorized"]);
  exit;
}

$stmt = $conn->prepare("UPDATE users SET last_seen_at = NOW() WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();

echo json_encode(["ok" => true, "supported" => true]);
