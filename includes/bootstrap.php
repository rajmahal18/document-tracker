<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/app_config.php';

function app_normalize_url_path(string $path): string {
  $path = str_replace('\\', '/', trim($path));
  $path = preg_replace('#/+#', '/', $path) ?? $path;

  if ($path === '' || $path === '.') {
    return '';
  }

  if ($path[0] !== '/') {
    $path = '/' . $path;
  }

  if ($path !== '/') {
    $path = rtrim($path, '/');
  }

  return $path === '/' ? '' : $path;
}

function app_join_url_path(string ...$parts): string {
  $joined = '';

  foreach ($parts as $index => $part) {
    $part = trim(str_replace('\\', '/', $part));
    if ($part === '') {
      continue;
    }

    if ($joined === '') {
      $joined = ($index === 0)
        ? app_normalize_url_path($part)
        : '/' . ltrim($part, '/');
      continue;
    }

    $joined .= '/' . ltrim($part, '/');
  }

  $joined = preg_replace('#/+#', '/', $joined) ?? $joined;

  if ($joined === '') {
    return '';
  }

  return $joined === '/' ? '' : rtrim($joined, '/');
}

function app_detect_base_path(): string {
  $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
  $scriptName = str_replace('\\', '/', $scriptName);

  if ($scriptName === '') {
    return '';
  }

  $scriptDir = str_replace('\\', '/', dirname($scriptName));
  if ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '\\') {
    $scriptDir = '';
  }

  $basePath = preg_replace('#/(public|api)$#', '', $scriptDir) ?? $scriptDir;
  return app_normalize_url_path($basePath);
}

$detectedBasePath = app_detect_base_path();
$basePathOverride = defined('APP_BASE_PATH_OVERRIDE') ? (string)APP_BASE_PATH_OVERRIDE : '';
define('BASE_PATH', $basePathOverride !== '' ? app_normalize_url_path($basePathOverride) : $detectedBasePath);
define('PUBLIC_PATH', app_join_url_path(BASE_PATH, 'public'));
define('API_PATH', app_join_url_path(BASE_PATH, 'api'));
define('ASSETS_PATH', app_join_url_path(BASE_PATH, 'assets'));

require_once __DIR__ . "/../core/db.php";
require_once __DIR__ . "/../core/workflow.php";
require_once __DIR__ . "/../core/user_identity.php";
require_once __DIR__ . "/../core/org_permissions.php";
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

function app_request_scheme(): string {
  if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $forwardedProto = strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
    if (in_array($forwardedProto, ['http', 'https'], true)) {
      return $forwardedProto;
    }
  }

  if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
    return 'https';
  }

  $serverPort = (string)($_SERVER['SERVER_PORT'] ?? '');
  return $serverPort === '443' ? 'https' : 'http';
}

function app_origin(): string {
  $configuredOrigin = defined('APP_URL_ORIGIN') ? trim((string)APP_URL_ORIGIN) : '';
  if ($configuredOrigin !== '') {
    return rtrim($configuredOrigin, '/');
  }

  $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
  if ($host === '') {
    return '';
  }

  return 'https://' . $host;
}

function app_url(string $path = ''): string {
  $origin = app_origin();
  $normalizedPath = $path;

  if ($path !== '' && !preg_match('#^https?://#i', $path)) {
    $normalizedPath = ($path[0] === '/')
      ? app_normalize_url_path($path)
      : app_join_url_path(BASE_PATH, $path);

    if ($normalizedPath === '') {
      $normalizedPath = '/';
    }
  }

  if ($origin === '') {
    return $normalizedPath === '' ? '/' : $normalizedPath;
  }

  if ($normalizedPath === '' || $normalizedPath === '/') {
    return $origin . '/';
  }

  return $origin . $normalizedPath;
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

  $url = app_join_url_path(BASE_PATH, $relativePath);
  if ($version !== null) {
    $url .= '?v=' . rawurlencode($version);
  }

  return $url;
}

function redirect(string $path): void {
  if ($path !== '' && !preg_match('#^https?://#i', $path)) {
    $path = ($path[0] === '/')
      ? app_normalize_url_path($path)
      : app_join_url_path(BASE_PATH, $path);
  }
  header('Location: ' . $path);
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

function assistant_fetch_assigned_principals(mysqli $conn, int $assistantUserId): array {
  if ($assistantUserId <= 0 || !db_column_exists($conn, 'users', 'chief_assistant_user_id')) return [];
  $hasAuthorityRole = db_column_exists($conn, 'users', 'authority_role');
  $hasOfficialTitle = db_column_exists($conn, 'users', 'official_title');
  $sql = '
    SELECT u.id, u.full_name, u.section_id, u.role, u.is_chief, '
    . ($hasAuthorityRole ? 'u.authority_role' : 'NULL') . ' AS authority_role, '
    . ($hasOfficialTitle ? 'u.official_title' : 'NULL') . ' AS official_title, '
    . 's.name AS section_name, d.id AS division_id, d.name AS division_name '
    . 'FROM users u '
    . 'LEFT JOIN sections s ON s.id = u.section_id '
    . 'LEFT JOIN divisions d ON d.id = s.division_id '
    . 'WHERE u.is_active = 1 AND u.chief_assistant_user_id = ? '
    . 'ORDER BY u.full_name ASC';
  $stmt = $conn->prepare($sql);
  if (!$stmt) return [];
  $stmt->bind_param('i', $assistantUserId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  foreach ($rows as &$row) {
    $authority = trim((string)($row['authority_role'] ?? ''));
    if ($authority === '') {
      $authority = ((int)($row['is_chief'] ?? 0) === 1) ? 'section_head' : 'staff';
    }
    $row['authority_role'] = $authority;
    $row['acting_label'] = match ($authority) {
      'director' => 'Office of the Director',
      'division_head' => 'Office of the Division Chief',
      'section_head' => 'Office of the Section Chief',
      default => 'Chief Office',
    };
  }
  unset($row);
  return $rows;
}

function assistant_requested_principal_id(): int {
  return (int)($_POST['acting_principal_user_id'] ?? $_GET['acting_principal_user_id'] ?? $_REQUEST['acting_principal_user_id'] ?? 0);
}

function assistant_find_principal_by_id(mysqli $conn, int $assistantUserId, int $principalUserId): ?array {
  foreach (assistant_fetch_assigned_principals($conn, $assistantUserId) as $principal) {
    if ((int)($principal['id'] ?? 0) === $principalUserId) return $principal;
  }
  return null;
}

function effective_document_identity(mysqli $conn): array {
  $base = [
    'assistant_mode' => false,
    'actual_user_id' => (int)($_SESSION['user_id'] ?? 0),
    'actual_full_name' => trim((string)($_SESSION['full_name'] ?? '')),
    'effective_user_id' => (int)($_SESSION['user_id'] ?? 0),
    'effective_section_id' => (int)($_SESSION['section_id'] ?? 0),
    'effective_division_id' => (int)($_SESSION['division_id'] ?? 0),
    'effective_division_name' => trim((string)($_SESSION['division_name'] ?? '')),
    'effective_role' => trim((string)($_SESSION['role'] ?? 'user')),
    'effective_is_chief' => ((int)($_SESSION['is_chief'] ?? 0) === 1),
    'acting_principal_user_id' => 0,
    'acting_principal_name' => '',
    'acting_label' => '',
  ];
  $requested = assistant_requested_principal_id();
  if ($requested <= 0 || $base['actual_user_id'] <= 0) return $base;
  $principal = assistant_find_principal_by_id($conn, $base['actual_user_id'], $requested);
  if (!$principal) return $base;
  $base['assistant_mode'] = true;
  $base['effective_user_id'] = (int)($principal['id'] ?? 0);
  $base['effective_section_id'] = (int)($principal['section_id'] ?? 0);
  $base['effective_division_id'] = (int)($principal['division_id'] ?? 0);
  $base['effective_division_name'] = trim((string)($principal['division_name'] ?? ''));
  $base['effective_role'] = trim((string)($principal['role'] ?? 'user'));
  $base['effective_is_chief'] = in_array((string)($principal['authority_role'] ?? ''), ['director','division_head','section_head'], true) || ((int)($principal['is_chief'] ?? 0) === 1);
  $base['acting_principal_user_id'] = (int)($principal['id'] ?? 0);
  $base['acting_principal_name'] = trim((string)($principal['full_name'] ?? ''));
  $base['acting_label'] = trim((string)($principal['acting_label'] ?? ''));
  return $base;
}

function can_view_document_for_identity(mysqli $conn, int $docId, int $userId, int $sectionId, bool $isChief, bool $isAdmin = false): bool {
  if ($isAdmin) return true;
  $branchMode = workflow_branch_mode_enabled($conn);
  if ($userId <= 0) return false;
  if ($branchMode) {
    $sql = "SELECT 1 FROM documents d WHERE d.id = ? AND (d.created_by_user_id = ? OR EXISTS (SELECT 1 FROM document_user_visibility duv WHERE duv.document_id = d.id AND duv.user_id = ?) OR EXISTS (SELECT 1 FROM routes r WHERE r.document_id = d.id AND (r.to_user_id = ? OR r.sent_by_user_id = ? OR r.received_by_user_id = ?))) LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiiiii', $docId, $userId, $userId, $userId, $userId, $userId);
  } else {
    $chiefInt = $isChief ? 1 : 0;
    $sql = "SELECT 1 FROM documents d LEFT JOIN routes r ON r.document_id = d.id WHERE d.id = ? AND (d.created_by_user_id = ? OR r.to_user_id = ? OR r.sent_by_user_id = ? OR r.received_by_user_id = ? OR (r.to_user_id IS NULL AND r.to_section_id = ? AND ? = 1) OR (d.current_holder_section_id = ? AND ? = 1 AND NOT EXISTS (SELECT 1 FROM routes rr WHERE rr.document_id = d.id AND rr.received_at IS NULL AND rr.cancelled_at IS NULL))) LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiiiiiiii', $docId, $userId, $userId, $userId, $userId, $sectionId, $chiefInt, $sectionId, $chiefInt);
  }
  $stmt->execute();
  return (bool)$stmt->get_result()->fetch_row();
}

function can_view_document(mysqli $conn, int $docId): bool {
  $identity = effective_document_identity($conn);
  return can_view_document_for_identity(
    $conn,
    $docId,
    (int)($identity['effective_user_id'] ?? 0),
    (int)($identity['effective_section_id'] ?? 0),
    (bool)($identity['effective_is_chief'] ?? false),
    is_admin_user() && !(bool)($identity['assistant_mode'] ?? false)
  );
}

function attachment_branch_scope_for_document(mysqli $conn, int $docId, int $requestedBranchId = 0): array {
  $branchMode = workflow_branch_mode_enabled($conn);
  $hasBranchColumn = workflow_branch_attachment_scope_enabled($conn);
  $docHasBranches = $branchMode && workflow_document_has_real_branches($conn, $docId);

  $selectedBranchId = 0;
  if ($docHasBranches && $hasBranchColumn && $requestedBranchId > 0) {
    $identity = effective_document_identity($conn);
    $userId = (int)($identity['effective_user_id'] ?? 0);
    if ((is_admin_user() && !(bool)($identity['assistant_mode'] ?? false)) || workflow_user_can_access_branch($conn, $docId, $requestedBranchId, $userId)) {
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

  $identity = effective_document_identity($conn);
  if (is_admin_user() && !(bool)($identity['assistant_mode'] ?? false)) {
    return true;
  }

  $userId = (int)($identity['effective_user_id'] ?? 0);
  return workflow_user_can_access_branch($conn, $docId, $branchId, $userId);
}
