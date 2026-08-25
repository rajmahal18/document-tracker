<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../core/task_monitoring.php';
header('Content-Type: application/json; charset=utf-8');

require_login();

if (!tms_tables_ready($conn)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Task Monitoring tables are not ready.']);
  exit;
}

$startAt = trim((string)($_GET['start_at'] ?? ''));
$workingDays = (int)($_GET['working_days'] ?? 0);

if ($startAt === '' || $workingDays <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Target start and working days are required.']);
  exit;
}

$due = dt_add_working_days(str_replace('T', ' ', $startAt), $workingDays, $conn);
if (!$due instanceof DateTimeImmutable) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Unable to calculate target completion.']);
  exit;
}

echo json_encode([
  'ok' => true,
  'target_due_at' => $due->format('Y-m-d H:i:s'),
]);
