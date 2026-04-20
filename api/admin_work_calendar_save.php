<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

require_csrf();

function admin_calendar_time(?string $value): ?string
{
  $raw = trim((string)$value);
  if ($raw === '') {
    return null;
  }

  if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
    return null;
  }

  $hour = (int)$m[1];
  $minute = (int)$m[2];
  $second = isset($m[3]) ? (int)$m[3] : 0;
  if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
    return null;
  }

  return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
}

function admin_calendar_time_less(string $start, string $end): bool
{
  return strtotime("2000-01-01 {$start}") < strtotime("2000-01-01 {$end}");
}

function admin_calendar_date(?string $value): ?string
{
  $raw = trim((string)$value);
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
    return null;
  }

  $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('Asia/Manila'));
  $errors = DateTimeImmutable::getLastErrors();
  if (!$dt || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))) {
    return null;
  }

  return $dt->format('Y-m-d');
}

$action = strtolower(trim((string)($_POST['action'] ?? '')));
$userId = (int)($_SESSION['user_id'] ?? 0);

try {
  if ($action === 'settings') {
    $timezone = trim((string)($_POST['timezone'] ?? 'Asia/Manila'));
    try {
      new DateTimeZone($timezone);
    } catch (Throwable) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Invalid timezone.']);
      exit;
    }

    $start = admin_calendar_time($_POST['default_start_time'] ?? '');
    $end = admin_calendar_time($_POST['default_end_time'] ?? '');
    if (!$start || !$end || !admin_calendar_time_less($start, $end)) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Default start time must be earlier than end time.']);
      exit;
    }

    $workdayValues = $_POST['workdays'] ?? [];
    if (!is_array($workdayValues)) {
      $workdayValues = [];
    }
    $workdays = [];
    foreach ($workdayValues as $dayRaw) {
      $day = (int)$dayRaw;
      if ($day >= 1 && $day <= 7) {
        $workdays[] = $day;
      }
    }
    $workdays = array_values(array_unique($workdays));
    sort($workdays);
    if ($workdays === []) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Select at least one working day.']);
      exit;
    }

    $workdaysText = implode(',', $workdays);
    $stmt = $conn->prepare("
      INSERT INTO working_calendar_settings
        (id, timezone, default_start_time, default_end_time, workdays, updated_by_user_id)
      VALUES
        (1, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE
        timezone = VALUES(timezone),
        default_start_time = VALUES(default_start_time),
        default_end_time = VALUES(default_end_time),
        workdays = VALUES(workdays),
        updated_by_user_id = VALUES(updated_by_user_id)
    ");
    $stmt->bind_param('ssssi', $timezone, $start, $end, $workdaysText, $userId);
    $stmt->execute();

    echo json_encode(['ok' => true, 'message' => 'Working calendar settings saved.']);
    exit;
  }

  if ($action === 'exception') {
    $dateFrom = admin_calendar_date($_POST['date_from'] ?? '');
    $dateTo = admin_calendar_date($_POST['date_to'] ?? ($_POST['date_from'] ?? ''));
    if (!$dateFrom || !$dateTo) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Enter a valid date or date range.']);
      exit;
    }

    $from = new DateTimeImmutable($dateFrom, new DateTimeZone('Asia/Manila'));
    $to = new DateTimeImmutable($dateTo, new DateTimeZone('Asia/Manila'));
    if ($to < $from) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Date to must be the same as or later than date from.']);
      exit;
    }

    if ($from->diff($to)->days > 370) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Date range is too large. Limit one entry to 371 days.']);
      exit;
    }

    $type = strtolower(trim((string)($_POST['exception_type'] ?? 'non_working')));
    $nonWorkingTypes = ['non_working', 'special_holiday', 'regular_holiday', 'other_non_working'];
    if (!in_array($type, array_merge($nonWorkingTypes, ['custom_hours', 'special_working']), true)) {
      $type = 'non_working';
    }

    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') {
      $title = match ($type) {
        'custom_hours' => 'Custom office hours',
        'special_working' => 'Special working day',
        'special_holiday' => 'Special holiday',
        'regular_holiday' => 'Regular holiday',
        'other_non_working' => 'Other non-working day',
        default => 'Non-working day',
      };
    }
    if (mb_strlen($title) > 160) {
      $title = mb_substr($title, 0, 160);
    }

    $start = null;
    $end = null;
    if (!in_array($type, $nonWorkingTypes, true)) {
      $start = admin_calendar_time($_POST['start_time'] ?? '');
      $end = admin_calendar_time($_POST['end_time'] ?? '');
      if (!$start || !$end || !admin_calendar_time_less($start, $end)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Exception start time must be earlier than end time.']);
        exit;
      }
    }

    $notes = trim((string)($_POST['notes'] ?? ''));
    $stmt = $conn->prepare("
      INSERT INTO working_calendar_exceptions
        (exception_date, exception_type, title, start_time, end_time, notes, created_by_user_id, updated_by_user_id)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE
        exception_type = VALUES(exception_type),
        title = VALUES(title),
        start_time = VALUES(start_time),
        end_time = VALUES(end_time),
        notes = VALUES(notes),
        updated_by_user_id = VALUES(updated_by_user_id)
    ");

    $saved = 0;
    for ($cursor = $from; $cursor <= $to; $cursor = $cursor->modify('+1 day')) {
      $date = $cursor->format('Y-m-d');
      $stmt->bind_param('ssssssii', $date, $type, $title, $start, $end, $notes, $userId, $userId);
      $stmt->execute();
      $saved++;
    }

    echo json_encode(['ok' => true, 'message' => "Saved {$saved} calendar exception" . ($saved === 1 ? '.' : 's.')]);
    exit;
  }

  if ($action === 'delete_exception') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Missing calendar exception id.']);
      exit;
    }

    $stmt = $conn->prepare('DELETE FROM working_calendar_exceptions WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();

    echo json_encode(['ok' => true, 'message' => 'Calendar exception deleted.']);
    exit;
  }

  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Unknown calendar action.']);
  exit;
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to save working calendar.', 'debug' => $e->getMessage()]);
  exit;
}
