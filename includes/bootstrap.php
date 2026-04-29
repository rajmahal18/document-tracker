<?php
declare(strict_types=1);

// Keep authenticated sessions for up to 7 days.
$sessionLifetime = 7 * 24 * 60 * 60;
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
  || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
  || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

if (PHP_SESSION_ACTIVE !== session_status()) {
  ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
  ini_set('session.cookie_lifetime', (string)$sessionLifetime);
  ini_set('session.use_strict_mode', '1');
  ini_set('session.cookie_httponly', '1');
  ini_set('session.cookie_secure', $isHttps ? '1' : '0');
  ini_set('session.cookie_samesite', 'Lax');
  session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

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
require_once __DIR__ . "/email_verification.php";
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


function attachment_max_bytes(): int {
  return ATTACHMENT_MAX_BYTES;
}

function attachment_max_mb_label(): string {
  return (string)ATTACHMENT_MAX_MB . 'MB';
}

function attachment_allowed_extensions(): array {
  return ATTACHMENT_ALLOWED_EXTENSIONS;
}

function attachment_allowed_mime_types(): array {
  return ATTACHMENT_ALLOWED_MIME_TYPES;
}

function attachment_allowed_types_label(): string {
  return 'PDF/JPG/PNG only';
}

function attachment_upload_error_message(int $code): string {
  return match ($code) {
    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Attachment too large (max is ' . attachment_max_mb_label() . ').',
    UPLOAD_ERR_PARTIAL => 'Attachment upload was interrupted. Please try again.',
    UPLOAD_ERR_NO_FILE => 'No attachment was uploaded.',
    UPLOAD_ERR_NO_TMP_DIR => 'Upload failed because the server temp folder is missing.',
    UPLOAD_ERR_CANT_WRITE => 'Upload failed because the server could not write the file.',
    UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension.',
    default => 'Failed to upload attachment.',
  };
}

function attachment_sanitize_original_name(string $name): string {
  $base = basename($name);
  $base = preg_replace('/[^a-zA-Z0-9._\-\s]/', '_', $base) ?? $base;
  $base = trim($base);
  return $base !== '' ? $base : 'file';
}

function attachment_detect_mime(string $path): string {
  if (!is_file($path)) {
    return 'application/octet-stream';
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = (string)$finfo->file($path);
  return $mime !== '' ? $mime : 'application/octet-stream';
}

function ensure_storage_dir(string $path): string {
  if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
    throw new RuntimeException('Failed to prepare storage directory.');
  }

  return $path;
}

function attachments_base_dir(): string {
  $baseDir = realpath(__DIR__ . '/../storage/attachments');
  if ($baseDir === false) {
    $baseDir = __DIR__ . '/../storage/attachments';
  }

  return ensure_storage_dir($baseDir);
}

function temp_attachment_dir(): string {
  return ensure_storage_dir(rtrim(attachments_base_dir(), '/\\') . '/_tmp');
}

function attachment_validate_uploaded_file(array $file, bool $requireUploadedTmp = true): array {
  $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($errorCode !== UPLOAD_ERR_OK) {
    throw new RuntimeException(attachment_upload_error_message($errorCode));
  }

  $originalName = attachment_sanitize_original_name((string)($file['name'] ?? 'file'));
  $size = (int)($file['size'] ?? 0);
  if ($size <= 0) {
    throw new RuntimeException('Attachment is empty or did not upload correctly. Please try again.');
  }

  if ($size > attachment_max_bytes()) {
    throw new RuntimeException('Attachment too large (max ' . attachment_max_mb_label() . ')');
  }

  $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
  if ($extension === '' || !in_array($extension, attachment_allowed_extensions(), true)) {
    throw new RuntimeException('Unsupported attachment type (' . attachment_allowed_types_label() . ')');
  }

  $tmpPath = (string)($file['tmp_name'] ?? '');
  if ($tmpPath === '') {
    throw new RuntimeException('Invalid upload');
  }

  if ($requireUploadedTmp) {
    if (!is_uploaded_file($tmpPath)) {
      throw new RuntimeException('Invalid upload');
    }
  } elseif (!is_file($tmpPath)) {
    throw new RuntimeException('Invalid upload');
  }

  $mime = attachment_detect_mime($tmpPath);
  if (!in_array($mime, attachment_allowed_mime_types(), true)) {
    throw new RuntimeException('Unsupported attachment type (' . attachment_allowed_types_label() . ')');
  }

  return [
    'original_name' => $originalName,
    'size_bytes' => $size,
    'extension' => $extension,
    'tmp_path' => $tmpPath,
    'mime' => $mime,
  ];
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

function app_safe_next_path(string $next, string $fallback = ''): string {
  $fallback = $fallback !== '' ? $fallback : PUBLIC_PATH . '/documents.php';
  $next = trim(str_replace(["\r", "\n"], '', $next));
  if ($next === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $next) || str_starts_with($next, '//')) {
    return $fallback;
  }

  $parts = parse_url($next);
  if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
    return $fallback;
  }

  $path = app_normalize_url_path((string)($parts['path'] ?? ''));
  if ($path === '') {
    return $fallback;
  }

  $base = BASE_PATH;
  if ($base !== '' && $path !== $base && !str_starts_with($path . '/', $base . '/')) {
    return $fallback;
  }

  $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
  return $path . $query;
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

function login_with_credentials(mysqli $conn, string $username, string $password): array {
  $username = trim($username);
  if ($username === '' || $password === '') {
    return ['ok' => false, 'error' => 'Please enter your username/email and password.'];
  }

  $hasUsername = username_column_exists($conn);
  $sql = '
    SELECT u.id, u.password_hash
    FROM users u
    WHERE ' . ($hasUsername ? '(u.email = ? OR u.username = ?)' : 'u.email = ?') . '
    LIMIT 1
  ';
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return ['ok' => false, 'error' => 'Unable to process login right now.'];
  }
  if ($hasUsername) {
    $stmt->bind_param('ss', $username, $username);
  } else {
    $stmt->bind_param('s', $username);
  }
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || !password_verify($password, (string)($user['password_hash'] ?? ''))) {
    return ['ok' => false, 'error' => 'Invalid login credentials.'];
  }

  refresh_session_identity($conn, (int)$user['id']);
  return [
    'ok' => true,
    'user_id' => (int)$user['id'],
    'must_change_password' => (int)($_SESSION['must_change_password'] ?? 0),
  ];
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

function php_upload_size_to_bytes(string $value): int {
  $value = trim($value);
  if ($value === '') {
    return 0;
  }

  $unit = strtolower(substr($value, -1));
  $number = (float)$value;

  return match ($unit) {
    'g' => (int)($number * 1024 * 1024 * 1024),
    'm' => (int)($number * 1024 * 1024),
    'k' => (int)($number * 1024),
    default => (int)$number,
  };
}

function require_csrf(): void {
  $token = $_POST["csrf_token"] ?? "";
  $contentLength = (int)($_SERVER["CONTENT_LENGTH"] ?? 0);
  $postMaxBytes = php_upload_size_to_bytes((string)ini_get("post_max_size"));

  if ($_SERVER["REQUEST_METHOD"] === "POST" && $contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    http_response_code(413);
    header("Content-Type: application/json");
    echo json_encode([
      "ok" => false,
      "error" => "Upload is too large for the server limit. Server post_max_size is " . ini_get("post_max_size") . ".",
    ]);
    exit;
  }

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

function db_table_exists(mysqli $conn, string $table): bool {
  static $cache = [];

  if (array_key_exists($table, $cache)) {
    return $cache[$table];
  }

  $tableEsc = $conn->real_escape_string($table);
  $sql = "SHOW TABLES LIKE '{$tableEsc}'";
  $result = $conn->query($sql);
  $cache[$table] = $result instanceof mysqli_result && $result->num_rows > 0;

  if ($result instanceof mysqli_result) {
    $result->free();
  }

  return $cache[$table];
}

function assistant_assignments_table_ready(mysqli $conn): bool {
  static $ready = null;
  if ($ready !== null) return $ready;

  $ok = $conn->query("
    CREATE TABLE IF NOT EXISTS principal_assistants (
      principal_user_id INT NOT NULL,
      assistant_user_id INT NOT NULL,
      assigned_by_user_id INT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (principal_user_id, assistant_user_id),
      KEY idx_principal_assistants_assistant (assistant_user_id),
      KEY idx_principal_assistants_principal (principal_user_id),
      CONSTRAINT fk_principal_assistants_principal
        FOREIGN KEY (principal_user_id) REFERENCES users(id)
        ON DELETE CASCADE,
      CONSTRAINT fk_principal_assistants_assistant
        FOREIGN KEY (assistant_user_id) REFERENCES users(id)
        ON DELETE CASCADE,
      CONSTRAINT fk_principal_assistants_assigned_by
        FOREIGN KEY (assigned_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  if (!$ok) {
    $ready = db_table_exists($conn, 'principal_assistants');
    return $ready;
  }

  if (db_column_exists($conn, 'users', 'chief_assistant_user_id')) {
    $conn->query("
      INSERT IGNORE INTO principal_assistants (principal_user_id, assistant_user_id)
      SELECT id, chief_assistant_user_id
      FROM users
      WHERE chief_assistant_user_id IS NOT NULL
        AND chief_assistant_user_id > 0
    ");
  }

  $ready = true;
  return $ready;
}

/**
 * ===== Permission Helpers =====
 */

function is_admin_user(): bool {
  return strtolower(trim((string)($_SESSION["role"] ?? ""))) === "admin";
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
  if ($assistantUserId <= 0) return [];
  $hasAuthorityRole = db_column_exists($conn, 'users', 'authority_role');
  $hasOfficialTitle = db_column_exists($conn, 'users', 'official_title');
  $hasProfilePhotoUrl = db_column_exists($conn, 'users', 'profile_photo_url');
  $hasLegacyAssistant = db_column_exists($conn, 'users', 'chief_assistant_user_id');
  $hasAssignmentTable = assistant_assignments_table_ready($conn);
  if (!$hasLegacyAssistant && !$hasAssignmentTable) return [];

  $assignmentWhere = [];
  if ($hasAssignmentTable) {
    $assignmentWhere[] = 'EXISTS (
      SELECT 1
      FROM principal_assistants pa
      WHERE pa.principal_user_id = u.id
        AND pa.assistant_user_id = ?
    )';
  }
  if ($hasLegacyAssistant) {
    $assignmentWhere[] = 'u.chief_assistant_user_id = ?';
  }

  $sql = '
    SELECT u.id, u.full_name, u.section_id, u.role, u.is_chief, '
    . ($hasAuthorityRole ? 'u.authority_role' : 'NULL') . ' AS authority_role, '
    . ($hasOfficialTitle ? 'u.official_title' : 'NULL') . ' AS official_title, '
    . ($hasProfilePhotoUrl ? 'u.profile_photo_url' : 'NULL') . ' AS profile_photo_url, '
    . 's.name AS section_name, d.id AS division_id, d.name AS division_name '
    . 'FROM users u '
    . 'LEFT JOIN sections s ON s.id = u.section_id '
    . 'LEFT JOIN divisions d ON d.id = s.division_id '
    . 'WHERE u.is_active = 1 AND (' . implode(' OR ', $assignmentWhere) . ') '
    . ($hasAuthorityRole ? "AND u.authority_role IN ('director', 'division_head', 'section_head') " : 'AND u.is_chief = 1 ')
    . 'ORDER BY u.full_name ASC';
  $stmt = $conn->prepare($sql);
  if (!$stmt) return [];
  $types = str_repeat('i', count($assignmentWhere));
  $params = array_fill(0, count($assignmentWhere), $assistantUserId);
  $bind = [&$types];
  foreach ($params as $k => $v) {
    $bind[] = &$params[$k];
  }
  call_user_func_array([$stmt, 'bind_param'], $bind);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  foreach ($rows as &$row) {
    $authority = trim((string)($row['authority_role'] ?? ''));
    if ($authority === '') {
      $authority = ((int)($row['is_chief'] ?? 0) === 1) ? 'section_head' : 'staff';
    }
    $row['authority_role'] = $authority;
    $row['profile_photo_url'] = app_profile_photo_url((string)($row['profile_photo_url'] ?? ''));
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
