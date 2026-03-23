<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

header("Content-Type: application/json; charset=UTF-8");

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "Method not allowed."]);
  exit;
}

$entry = $_SESSION["add_document_temp_attachment"] ?? null;
if (is_array($entry)) {
  $abs = (string)($entry["temp_path"] ?? "");
  if ($abs !== '' && is_file($abs)) {
    @unlink($abs);
  }
}

unset($_SESSION["add_document_temp_attachment"]);

echo json_encode(["ok" => true]);
