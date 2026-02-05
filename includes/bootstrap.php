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
  if ($path !== "" && $path[0] !== "/") {
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
    redirect(PUBLIC_PATH . "/login.php"); // ✅ updated
  }
}
