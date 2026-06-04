<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../core/org_chart_activity_stats.php';
require_login();

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

$targetUserId = (int)($_GET['user_id'] ?? 0);
if ($targetUserId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing user_id']);
  exit;
}

$stats = org_chart_fetch_user_activity_stats($conn, $targetUserId);
$context = org_chart_fetch_user_activity_context($conn, $targetUserId);
if ($context === null) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'User not found']);
  exit;
}

echo json_encode([
  'ok' => true,
  'stats' => $stats,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
