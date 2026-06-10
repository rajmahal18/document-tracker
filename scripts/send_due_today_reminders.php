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
$targetDate = document_deadline_reminders_target_date($now)->format('Y-m-d');

if (!document_deadline_reminders_table_exists($conn)) {
    fwrite(STDERR, "Missing email_reminder_log table. Run the latest migration first.\n");
    exit(1);
}

$rows = document_deadline_reminders_due_today_rows($conn, $slot, $now);
$byUser = [];

foreach ($rows as $row) {
    $userId = (int)($row['user_id'] ?? 0);
    if ($userId <= 0) {
        continue;
    }

    if (!isset($byUser[$userId])) {
        $byUser[$userId] = [
            'user_id' => $userId,
            'full_name' => trim((string)($row['full_name'] ?? '')),
            'email' => trim((string)($row['email'] ?? '')),
            'documents' => [],
        ];
    }

    $byUser[$userId]['documents'][] = $row;
}

$documentsUrl = app_url(PUBLIC_PATH . '/documents.php');
$hasAbsoluteAppUrl = preg_match('#^https?://#i', $documentsUrl) === 1;
$summary = [
    'ok' => true,
    'slot' => $slot,
    'date' => $targetDate,
    'dry_run' => $dryRun,
    'users_considered' => count($byUser),
    'users_matched' => count($byUser),
    'users_emailed' => 0,
    'users_logged' => 0,
    'documents_logged' => 0,
    'documents_matched' => count($rows),
    'failures' => [],
    'note' => $hasAbsoluteAppUrl ? '' : 'APP_URL_ORIGIN is not configured, so email links are relative.',
];

foreach ($byUser as $entry) {
    $userId = (int)$entry['user_id'];
    $toEmail = trim((string)$entry['email']);
    $toName = trim((string)$entry['full_name']);
    $documents = $entry['documents'];

    if ($toEmail === '' || $documents === []) {
        continue;
    }

    $docCount = count($documents);
    $periodLabel = $slot === 'afternoon' ? 'this afternoon' : 'this morning';
    $subject = ($docCount === 1 ? '1 document' : ($docCount . ' documents')) . " due today - MPW Document Tracker";

    $htmlItems = [];
    $textItems = [];
    foreach ($documents as $row) {
        $trackingNo = trim((string)($row['tracking_no'] ?? ''));
        $subjectText = trim((string)($row['subject'] ?? ''));
        $effectiveDeadline = trim((string)($row['effective_deadline_at'] ?? ''));
        $sectionName = trim((string)($row['section_name'] ?? ''));
        $label = $trackingNo !== '' ? $trackingNo : ('Document #' . (int)($row['document_id'] ?? 0));
        $title = $subjectText !== '' ? $subjectText : 'Untitled document';
        $deadlineLabel = $effectiveDeadline !== ''
            ? (new DateTimeImmutable($effectiveDeadline, $tz))->format('M j, Y g:i A')
            : 'Today';

        $sectionLine = $sectionName !== '' ? ' | Section: ' . htmlspecialchars($sectionName, ENT_QUOTES, 'UTF-8') : '';
        $htmlItems[] = '<li><strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong> - '
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . ' <br><span>Deadline: ' . htmlspecialchars($deadlineLabel, ENT_QUOTES, 'UTF-8') . $sectionLine . '</span></li>';

        $textItems[] = '- ' . $label . ' | ' . $title . ' | Deadline: ' . $deadlineLabel
            . ($sectionName !== '' ? ' | Section: ' . $sectionName : '');
    }

    $safeToName = htmlspecialchars($toName !== '' ? $toName : $toEmail, ENT_QUOTES, 'UTF-8');
    $safeDocumentsUrl = htmlspecialchars($documentsUrl, ENT_QUOTES, 'UTF-8');
    $htmlBody = <<<HTML
<p>Hello {$safeToName},</p>
<p>This is your {$periodLabel} reminder for document deadlines due today ({$targetDate}, Asia/Manila).</p>
<ul>
  %s
</ul>
<p><a href="{$safeDocumentsUrl}">Open Document Tracker</a></p>
HTML;
    $htmlBody = sprintf($htmlBody, implode("\n  ", $htmlItems));

    $textBody = "Hello " . ($toName !== '' ? $toName : $toEmail) . ",\n"
        . "This is your {$periodLabel} reminder for document deadlines due today ({$targetDate}, Asia/Manila).\n\n"
        . implode("\n", $textItems) . "\n\n"
        . "Open Document Tracker: {$documentsUrl}\n";

    if ($dryRun) {
        continue;
    }

    $mailResult = app_send_mail($toEmail, $toName, $subject, $htmlBody, $textBody);
    if (empty($mailResult['ok'])) {
        $summary['failures'][] = [
            'user_id' => $userId,
            'email' => $toEmail,
            'error' => (string)($mailResult['error'] ?? 'Unknown mail error'),
        ];
        continue;
    }

    try {
        $logResult = document_deadline_reminders_mark_sent($conn, $userId, $documents, $slot, $now);
    } catch (Throwable $e) {
        $summary['ok'] = false;
        $summary['failures'][] = [
            'user_id' => $userId,
            'email' => $toEmail,
            'error' => 'Reminder email was sent, but rerun protection logging failed: ' . $e->getMessage(),
        ];
        continue;
    }

    $summary['users_emailed']++;
    $summary['users_logged']++;
    $summary['documents_logged'] += (int)($logResult['logged'] ?? 0);
}

if ($summary['failures'] !== []) {
    $summary['ok'] = false;
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(count($summary['failures']) > 0 ? 2 : 0);
