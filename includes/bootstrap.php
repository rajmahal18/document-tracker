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

  // Force first-time / temporary-password users to update password
  $mustChange = (int)($_SESSION["must_change_password"] ?? 0);
  if ($mustChange === 1) {
    $self = basename($_SERVER["PHP_SELF"] ?? "");
    // Allow the change password page + its API + logout
    $allowed = ["change_password.php", "logout.php"]; 
    $isApiChange = str_contains((string)($_SERVER["REQUEST_URI"] ?? ""), "/api/change_password.php");

    if (!in_array($self, $allowed, true) && !$isApiChange) {
      redirect(PUBLIC_PATH . "/change_password.php");
    }
  }
}

/**
 * Admin-only guard.
 *
 * Roles are currently: admin/user
 */
function require_admin(): void {
  require_login();

  $role = (string)($_SESSION["role"] ?? "user");
  if ($role !== "admin") {
    http_response_code(403);
    echo "Forbidden";
    exit;
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

/**
 * ===== Permission Helpers =====
 */

function is_admin_user(): bool {
  return (($_SESSION["role"] ?? "") === "admin");
}

function is_chief_user(): bool {
  return ((int)($_SESSION["is_chief"] ?? 0) === 1);
}

/**
 * Determine if current user can view a document.
 *
 * Rules:
 * admin → everything
 * creator → allowed
 * assigned recipient → allowed
 * section chief for section-only route → allowed
 * section chief for section-held doc → allowed
 */
function can_view_document(mysqli $conn, int $docId): bool {

  if (is_admin_user()) {
    return true;
  }

  $userId = (int)($_SESSION["user_id"] ?? 0);
  $sectionId = (int)($_SESSION["section_id"] ?? 0);
  $isChief = is_chief_user() ? 1 : 0;

  $sql = "
  SELECT 1
  FROM documents d
  LEFT JOIN routes r ON r.document_id = d.id
  WHERE d.id = ?
  AND (
        d.created_by_user_id = ?
        OR r.to_user_id = ?
        OR r.sent_by_user_id = ?
        OR r.received_by_user_id = ?
        OR (
              r.to_user_id IS NULL
              AND r.to_section_id = ?
              AND ? = 1
           )
        OR (
              d.current_holder_section_id = ?
              AND ? = 1
              AND NOT EXISTS (
                SELECT 1
                FROM routes rr
                WHERE rr.document_id = d.id
                  AND rr.received_at IS NULL
                  AND rr.cancelled_at IS NULL
              )
           )
      )
  LIMIT 1
  ";

  $stmt = $conn->prepare($sql);

  $stmt->bind_param(
    "iiiiiiiii",
    $docId,
    $userId,
    $userId,
    $userId,
    $userId,
    $sectionId,
    $isChief,
    $sectionId,
    $isChief
  );

  $stmt->execute();

  return (bool)$stmt->get_result()->fetch_row();
}