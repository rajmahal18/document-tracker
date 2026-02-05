<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . "/../db.php";

function redirect(string $path): void {
  header("Location: " . $path);
  exit;
}

function is_logged_in(): bool {
  return isset($_SESSION["user_id"]);
}

function require_login(): void {
  if (!is_logged_in()) {
    redirect("/document-tracker/login.php");
  }
}
