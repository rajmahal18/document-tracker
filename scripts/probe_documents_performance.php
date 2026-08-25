<?php
declare(strict_types=1);

// Local diagnostics only. Runs public/documents.php under a simulated session,
// then reports the generated list/count SQL timing and EXPLAIN shape.

$opts = getopt('', [
  'user:',
  'view::',
  'quick::',
  'sort::',
  'q::',
  'page::',
  'acting::',
  'explain::',
  'summary::',
]);

$userId = (int)($opts['user'] ?? 0);
if ($userId <= 0) {
  fwrite(STDERR, "Usage: php scripts/probe_documents_performance.php --user=USER_ID [--view=my|admin|assistant|chief] [--quick=...] [--sort=...]\n");
  exit(2);
}

require __DIR__ . '/../includes/app_config.php';
require __DIR__ . '/../core/db.php';

$stmtUser = $conn->prepare("
  SELECT
    u.id,
    u.full_name,
    u.email,
    u.section_id,
    u.role,
    u.is_chief,
    COALESCE(NULLIF(TRIM(u.authority_role), ''), '') AS authority_role,
    COALESCE(NULLIF(TRIM(u.official_title), ''), '') AS official_title,
    COALESCE(s.name, '') AS section_name,
    COALESCE(d.id, 0) AS division_id,
    COALESCE(d.name, '') AS division_name,
    COALESCE(d.code, '') AS division_code
  FROM users u
  LEFT JOIN sections s ON s.id = u.section_id
  LEFT JOIN divisions d ON d.id = s.division_id
  WHERE u.id = ?
  LIMIT 1
");
$stmtUser->bind_param('i', $userId);
$stmtUser->execute();
$user = $stmtUser->get_result()->fetch_assoc();
if (!$user) {
  fwrite(STDERR, "User not found: {$userId}\n");
  exit(2);
}

$_SESSION = [
  'user_id' => (int)$user['id'],
  'full_name' => (string)$user['full_name'],
  'email' => (string)($user['email'] ?? ''),
  'section_id' => (int)$user['section_id'],
  'section_name' => (string)$user['section_name'],
  'division_id' => (int)$user['division_id'],
  'division_name' => (string)$user['division_name'],
  'division_code' => (string)$user['division_code'],
  'role' => (string)$user['role'],
  'is_chief' => (int)$user['is_chief'],
  'authority_role' => (string)$user['authority_role'],
  'official_title' => (string)$user['official_title'],
  'must_change_password' => 0,
  'csrf_token' => str_repeat('0', 64),
];

$_GET = array_filter([
  'view' => $opts['view'] ?? 'my',
  'quick' => $opts['quick'] ?? '',
  'sort' => $opts['sort'] ?? '',
  'q' => $opts['q'] ?? '',
  'page' => $opts['page'] ?? '1',
  'acting_principal_user_id' => $opts['acting'] ?? '',
], static fn($value): bool => (string)$value !== '');

$_REQUEST = $_GET;
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/public/documents.php';
$_SERVER['PHP_SELF'] = '/public/documents.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';

$started = microtime(true);
ob_start();
require __DIR__ . '/../public/documents.php';
ob_end_clean();
$pageMs = (microtime(true) - $started) * 1000;

if (!isset($sql, $countSql, $types2, $params2, $types, $params, $docs, $total)) {
  fwrite(STDERR, "Probe failed: documents.php did not expose expected query variables.\n");
  exit(1);
}

$timePrepared = static function (mysqli $conn, string $query, string $types, array $params): array {
  $start = microtime(true);
  $stmt = $conn->prepare($query);
  if ($types !== '') {
    $stmt->bind_param($types, ...$params);
  }
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  return [
    'ms' => (microtime(true) - $start) * 1000,
    'rows' => count($rows),
  ];
};

$countTiming = $timePrepared($conn, $countSql, $types, $params);
$listTiming = $timePrepared($conn, $sql, $types2, $params2);

$explainRows = [];
if (($opts['explain'] ?? '1') !== '0') {
  $explainSql = 'EXPLAIN ' . $sql;
  $stmtExplain = $conn->prepare($explainSql);
  if ($types2 !== '') {
    $stmtExplain->bind_param($types2, ...$params2);
  }
  $stmtExplain->execute();
  $explainRows = $stmtExplain->get_result()->fetch_all(MYSQLI_ASSOC);
}

$summaryOnly = (($opts['summary'] ?? '0') === '1');
$planRows = $explainRows;
if ($summaryOnly && $planRows !== []) {
  $planRows = array_values(array_filter($planRows, static function (array $row): bool {
    $table = (string)($row['table'] ?? '');
    $key = (string)($row['key'] ?? '');
    $extra = (string)($row['Extra'] ?? '');
    return str_starts_with($table, 'r_')
      || $table === 'routes'
      || str_contains($table, 'e_')
      || str_contains($key, 'idx_routes_doc_')
      || $key === 'idx_events_doc_id'
      || str_contains($extra, 'filesort');
  }));
  $planRows = array_map(static function (array $row): array {
    return [
      'id' => $row['id'] ?? '',
      'select_type' => $row['select_type'] ?? '',
      'table' => $row['table'] ?? '',
      'type' => $row['type'] ?? '',
      'key' => $row['key'] ?? '',
      'rows' => $row['rows'] ?? '',
      'Extra' => $row['Extra'] ?? '',
    ];
  }, $planRows);
}

$summary = [
  'user' => [
    'id' => (int)$user['id'],
    'name' => (string)$user['full_name'],
    'role' => (string)$user['role'],
    'is_chief' => (int)$user['is_chief'],
    'section_id' => (int)$user['section_id'],
  ],
  'get' => $_GET,
  'total' => (int)$total,
  'page_rows' => count($docs),
  'documents_php_ms' => round($pageMs, 2),
  'count_query_ms' => round($countTiming['ms'], 2),
  'list_query_ms' => round($listTiming['ms'], 2),
  'count_sql_chars' => strlen($countSql),
  'list_sql_chars' => strlen($sql),
  'list_explain' => $planRows,
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
