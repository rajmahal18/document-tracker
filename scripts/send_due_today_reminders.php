<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../core/document_deadline_reminders.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

$options = getopt('', ['slot::', 'dry-run::']);
$slot = document_deadline_reminder_normalize_slot((string)($options['slot'] ?? 'morning'));
$dryRunRaw = strtolower(trim((string)($options['dry-run'] ?? '0')));
$dryRun = in_array($dryRunRaw, ['1', 'true', 'yes'], true);
$tz = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $tz);
try {
    $summary = document_deadline_reminders_send_batch($conn, $slot, $dryRun, $now);
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(count($summary['failures'] ?? []) > 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
