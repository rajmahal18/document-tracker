<?php
declare(strict_types=1);

session_start();

/**
 * Base URL paths (change BASE_PATH only if folder name changes)
 * Example: http://localhost/document-tracker/...
 */
const BASE_PATH   = "/document-tracker";
const PUBLIC_PATH = BASE_PATH . "/public";
const API_PATH    = BASE_PATH . "/api";
const ASSETS_PATH = BASE_PATH . "/assets";

require_once __DIR__ . "/../core/db.php";
require_once __DIR__ . "/constants.php";

function redirect(string $path): void {
  // If dev accidentally passes "public/login.php", normalize it.
  if ($path !== "" && $path[0] !== "/" && !str_starts_with($path, "http://") && !str_starts_with($path, "https://")) {
    $path = "/" . $path;
  }
  header("Location: " . $path);
  exit;
}

function is_logged_in(): bool {
  return isset($_SESSION["user_id"]);
}

function require_login(): void {
  if (!is_logged_in()) {
    redirect(PUBLIC_PATH . "/login.php");
  }
}

function csrf_token(): string {
  if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
  }
  return $_SESSION["csrf_token"];
}

function require_csrf(): void {
  $token = $_POST["csrf_token"] ?? "";
  if (!is_string($token) || $token === "" || !hash_equals($_SESSION["csrf_token"] ?? "", $token)) {
    http_response_code(403);
    header("Content-Type: application/json");
    echo json_encode(["ok" => false, "error" => "Invalid CSRF token"]);
    exit;
  }
}
