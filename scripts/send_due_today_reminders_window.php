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

$options = getopt('', ['dry-run::']);
$dryRunRaw = strtolower(trim((string)($options['dry-run'] ?? '0')));
$dryRun = in_array($dryRunRaw, ['1', 'true', 'yes'], true);
$tz = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $tz);
$window = document_deadline_reminders_window_state($now);

if (empty($window['active']) || empty($window['slot'])) {
    $summary = [
        'ok' => true,
        'dry_run' => $dryRun,
        'skipped' => true,
        'reason' => 'Current Manila time is outside the reminder send windows.',
        'manila_now' => (string)($window['manila_now'] ?? $now->format('Y-m-d H:i:s')),
        'active_window' => null,
    ];
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

try {
    $summary = document_deadline_reminders_send_batch($conn, (string)$window['slot'], $dryRun, $now);
    $summary['skipped'] = false;
    $summary['manila_now'] = (string)($window['manila_now'] ?? $now->format('Y-m-d H:i:s'));
    $summary['active_window'] = (string)($window['label'] ?? $window['slot']);
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(count($summary['failures'] ?? []) > 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
