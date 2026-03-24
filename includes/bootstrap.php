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
require_once __DIR__ . "/../core/workflow.php";
require_once __DIR__ . "/constants.php";


function app_is_dev_environment(): bool {
  $host = strtolower((string)($_SERVER["HTTP_HOST"] ?? $_SERVER["SERVER_NAME"] ?? ""));
  $serverAddr = (string)($_SERVER["SERVER_ADDR"] ?? "");

  if ($host === '' && $serverAddr === '') {
    return false;
  }

  $hostOnly = $host;
  if (str_contains($hostOnly, ':')) {
    $hostOnly = explode(':', $hostOnly, 2)[0];
  }

  if (in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true)) {
    return true;
  }

  if (preg_match('/^192\.168\./', $hostOnly) === 1) {
    return true;
  }

  if (preg_match('/^10\./', $hostOnly) === 1) {
    return true;
  }

  if (preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $hostOnly) === 1) {
    return true;
  }

  return in_array($serverAddr, ['127.0.0.1', '::1'], true);
}

function asset_url(string $relativePath): string {
  $relativePath = ltrim($relativePath, '/');
  $absolutePath = realpath(__DIR__ . '/../' . $relativePath);
  $version = null;

  if (is_string($absolutePath) && is_file($absolutePath)) {
    $mtime = filemtime($absolutePath);
    if ($mtime !== false) {
      $version = (string)$mtime;
    }
  }

  $url = BASE_PATH . '/' . $relativePath;
  if ($version !== null) {
    $url .= '?v=' . rawurlencode($version);
  }

  return $url;
}

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



function db_column_exists(mysqli $conn, string $table, string $column): bool {
  static $cache = [];

  $key = $table . "." . $column;
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }

  $tableEsc = $conn->real_escape_string($table);
  $columnEsc = $conn->real_escape_string($column);
  $sql = "SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'";
  $result = $conn->query($sql);
  $cache[$key] = $result instanceof mysqli_result && $result->num_rows > 0;

  if ($result instanceof mysqli_result) {
    $result->free();
  }

  return $cache[$key];
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
  $branchMode = workflow_branch_mode_enabled($conn);

  if ($userId <= 0) {
    return false;
  }

  if ($branchMode) {
    $sql = "
    SELECT 1
    FROM documents d
    WHERE d.id = ?
      AND (
        d.created_by_user_id = ?
        OR EXISTS (
          SELECT 1
          FROM document_user_visibility duv
          WHERE duv.document_id = d.id
            AND duv.user_id = ?
        )
        OR EXISTS (
          SELECT 1
          FROM routes r
          WHERE r.document_id = d.id
            AND (
              r.to_user_id = ?
              OR r.sent_by_user_id = ?
              OR r.received_by_user_id = ?
            )
        )
      )
    LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
      "iiiiii",
      $docId,
      $userId,
      $userId,
      $userId,
      $userId,
      $userId
    );
  } else {
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
  }

  $stmt->execute();

  return (bool)$stmt->get_result()->fetch_row();
}
function attachment_branch_scope_for_document(mysqli $conn, int $docId, int $requestedBranchId = 0): array {
  $branchMode = workflow_branch_mode_enabled($conn);
  $hasBranchColumn = workflow_branch_attachment_scope_enabled($conn);
  $docHasBranches = $branchMode && workflow_document_has_real_branches($conn, $docId);

  $selectedBranchId = 0;
  if ($docHasBranches && $hasBranchColumn && $requestedBranchId > 0) {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if (is_admin_user() || workflow_user_can_access_branch($conn, $docId, $requestedBranchId, $userId)) {
      $selectedBranchId = $requestedBranchId;
    }
  }

  return [
    'branch_mode' => $branchMode,
    'has_branch_column' => $hasBranchColumn,
    'doc_has_branches' => $docHasBranches,
    'selected_branch_id' => $selectedBranchId,
    'scoped' => ($docHasBranches && $hasBranchColumn),
  ];
}

function can_view_attachment(mysqli $conn, int $attachmentId): bool {
  if ($attachmentId <= 0) {
    return false;
  }

  $hasBranchColumn = workflow_branch_attachment_scope_enabled($conn);
  $sql = $hasBranchColumn
    ? "SELECT document_id, branch_id FROM document_attachments WHERE id = ? AND is_deleted = 0 LIMIT 1"
    : "SELECT document_id, NULL AS branch_id FROM document_attachments WHERE id = ? AND is_deleted = 0 LIMIT 1";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param('i', $attachmentId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();

  if (!$row) {
    return false;
  }

  $docId = (int)($row['document_id'] ?? 0);
  if ($docId <= 0 || !can_view_document($conn, $docId)) {
    return false;
  }

  $branchId = (int)($row['branch_id'] ?? 0);
  if ($branchId <= 0 || !workflow_branch_mode_enabled($conn) || !workflow_document_has_real_branches($conn, $docId)) {
    return true;
  }

  if (is_admin_user()) {
    return true;
  }

  $userId = (int)($_SESSION['user_id'] ?? 0);
  return workflow_user_can_access_branch($conn, $docId, $branchId, $userId);
}
