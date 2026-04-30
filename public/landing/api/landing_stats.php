<?php
/**
 * Landing page live metrics endpoint.
 *
 * Place this file at /api/landing_stats.php in the PHP document tracker web root.
 * In this Vite landing repo, keep it in public/api/landing_stats.php so it is copied
 * into dist/api/landing_stats.php during npm run build.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function require_if_exists(string $path): void
{
    if (is_file($path)) {
        require_once $path;
    }
}

function detect_project_root(): string
{
    $dir = __DIR__;

    for ($i = 0; $i < 8; $i++) {
        $hasConfig = is_file($dir . '/includes/app_config.php');
        $hasDb = is_file($dir . '/core/db.php');

        if ($hasConfig || $hasDb) {
            return $dir;
        }

        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    // Fallback for expected repo layout: /public/landing/api
    return dirname(__DIR__, 3);
}

function app_env_value(): string
{
    $env = (string) (getenv('APP_ENV') ?: '');
    return strtolower(trim($env));
}

function is_local_host(): bool
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    if ($host !== '' && str_contains($host, ':')) {
        $host = explode(':', $host, 2)[0];
    }

    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return true;
    }

    $addr = (string)($_SERVER['SERVER_ADDR'] ?? '');
    return in_array($addr, ['127.0.0.1', '::1'], true);
}

function load_production_config_fallback(string $root): void
{
    $prodConfig = $root . '/includes/app_config.production.php';
    if (!is_file($prodConfig)) {
        return;
    }

    $env = app_env_value();
    $shouldForceProdConfig = ($env === 'production') || ($env === '' && !is_local_host());

    if ($shouldForceProdConfig) {
        require_once $prodConfig;
    }
}

function load_project_config(): void
{
    $root = detect_project_root();

    $candidateFiles = [
        $root . '/includes/app_config.php',
        $root . '/core/db.php',
        $root . '/includes/bootstrap.php',
        $root . '/config/database.php',
        $root . '/config/db.php',
        $root . '/includes/database.php',
        $root . '/includes/db.php',
        $root . '/db.php',
        $root . '/db_connect.php',
        $root . '/database.php',
        $root . '/connection.php',
        $root . '/config.php',
    ];

    foreach ($candidateFiles as $file) {
        require_if_exists($file);
    }

    // Production safety net:
    // if APP_ENV is production (or not set on non-local host), enforce production overrides.
    load_production_config_fallback($root);
}

function pdo_from_existing_connection(): ?PDO
{
    foreach (['pdo', 'db', 'dbh', 'conn', 'connection'] as $name) {
        if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
            return $GLOBALS[$name];
        }
    }

    return null;
}

function production_db_config_from_file(): array
{
    $root = detect_project_root();
    $prodConfigFile = $root . '/includes/app_config.production.php';
    if (!is_file($prodConfigFile)) {
        return [];
    }

    $config = require $prodConfigFile;
    if (!is_array($config)) {
        return [];
    }

    return [
        'host' => isset($config['DB_HOST']) ? (string)$config['DB_HOST'] : null,
        'name' => isset($config['DB_NAME']) ? (string)$config['DB_NAME'] : null,
        'user' => isset($config['DB_USER']) ? (string)$config['DB_USER'] : null,
        'pass' => isset($config['DB_PASS']) ? (string)$config['DB_PASS'] : null,
        'port' => isset($config['DB_PORT']) ? (string)$config['DB_PORT'] : null,
    ];
}

function resolve_db_connection_params(): array
{
    $prodConfig = production_db_config_from_file();
    $useProdConfig = app_env_value() === 'production' || (app_env_value() === '' && !is_local_host());

    $host = null;
    $name = null;
    $user = null;
    $pass = null;
    $port = null;
    $source = 'env_or_constants';

    if ($useProdConfig) {
        $host = $prodConfig['host'] ?? null;
        $name = $prodConfig['name'] ?? null;
        $user = $prodConfig['user'] ?? null;
        $pass = $prodConfig['pass'] ?? null;
        $port = $prodConfig['port'] ?? null;
        if ($host || $name || $user) {
            $source = 'includes/app_config.production.php';
        }
    }

    $host = $host ?: (getenv('DB_HOST') ?: (defined('DB_HOST') ? constant('DB_HOST') : null));
    $name = $name ?: (getenv('DB_NAME') ?: (defined('DB_NAME') ? constant('DB_NAME') : null));
    $user = $user ?: (getenv('DB_USER') ?: (defined('DB_USER') ? constant('DB_USER') : null));
    $pass = $pass ?: (getenv('DB_PASS') ?: (defined('DB_PASS') ? constant('DB_PASS') : (defined('DB_PASSWORD') ? constant('DB_PASSWORD') : null)));
    $port = $port ?: (getenv('DB_PORT') ?: (defined('DB_PORT') ? constant('DB_PORT') : '3306'));

    return [
        'host' => $host,
        'name' => $name,
        'user' => $user,
        'pass' => $pass,
        'port' => $port,
        'source' => $source,
    ];
}

function pdo_from_env_or_constants(): ?PDO
{
    $params = resolve_db_connection_params();
    $host = $params['host'];
    $name = $params['name'];
    $user = $params['user'];
    $pass = $params['pass'];
    $port = $params['port'];

    $GLOBALS['landing_stats_db_debug'] = [
        'app_env' => app_env_value() !== '' ? app_env_value() : 'unset',
        'source' => (string)($params['source'] ?? 'env_or_constants'),
        'host' => (string)($host ?? ''),
        'db_name' => (string)($name ?? ''),
        'db_user' => (string)($user ?? ''),
        'port' => (string)($port ?? ''),
    ];

    if (!$host || !$name || !$user) {
        return null;
    }

    return new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        (string) $user,
        (string) ($pass ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function first_existing_table(PDO $pdo, array $candidates): ?string
{
    foreach ($candidates as $table) {
        if (table_exists($pdo, $table)) {
            return $table;
        }
    }

    return null;
}

function table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM ' . quote_identifier($table));
    return array_map(static fn ($row) => $row['Field'], $stmt->fetchAll());
}

function count_rows(PDO $pdo, string $table, array $columns, bool $preferActive = false): int
{
    $where = [];

    if (in_array('deleted_at', $columns, true)) {
        $where[] = 'deleted_at IS NULL';
    }

    if ($preferActive) {
        if (in_array('is_active', $columns, true)) {
            $where[] = '(is_active = 1 OR is_active = "1")';
        } elseif (in_array('active', $columns, true)) {
            $where[] = '(active = 1 OR active = "1")';
        } elseif (in_array('status', $columns, true)) {
            $where[] = "LOWER(status) NOT IN ('inactive', 'disabled', 'deleted', 'archived')";
        }
    }

    $sql = 'SELECT COUNT(*) FROM ' . quote_identifier($table);

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    return (int) $pdo->query($sql)->fetchColumn();
}

try {
    load_project_config();

    $pdo = pdo_from_existing_connection() ?: pdo_from_env_or_constants();

    if (!$pdo) {
        json_response([
            'ok' => false,
            'error' => 'Database connection was not found. Check api/landing_stats.php config candidates or DB_* environment variables.',
            'debug' => $GLOBALS['landing_stats_db_debug'] ?? null,
        ], 500);
    }

    $documentTable = first_existing_table($pdo, [
        'documents',
        'document',
        'docs',
        'tbl_documents',
        'document_records',
    ]);

    $userTable = first_existing_table($pdo, [
        'users',
        'user',
        'tbl_users',
        'system_users',
        'accounts',
    ]);

    if (!$documentTable || !$userTable) {
        json_response([
            'ok' => false,
            'error' => 'Required documents/users tables were not found.',
            'detected' => [
                'documents_table' => $documentTable,
                'users_table' => $userTable,
            ],
        ], 500);
    }

    $documentColumns = table_columns($pdo, $documentTable);
    $userColumns = table_columns($pdo, $userTable);

    json_response([
        'ok' => true,
        'documents' => count_rows($pdo, $documentTable, $documentColumns),
        'users' => count_rows($pdo, $userTable, $userColumns, true),
        'debug' => $GLOBALS['landing_stats_db_debug'] ?? null,
        'source' => [
            'documents_table' => $documentTable,
            'users_table' => $userTable,
        ],
    ]);
} catch (Throwable $error) {
    json_response([
        'ok' => false,
        'error' => $error->getMessage(),
        'debug' => $GLOBALS['landing_stats_db_debug'] ?? null,
    ], 500);
}
