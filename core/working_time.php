<?php
declare(strict_types=1);

const DT_WORK_TIMEZONE = 'Asia/Manila';
const DT_WORK_START_TIME = '08:00:00';
const DT_WORK_END_TIME = '17:00:00';
const DT_WORKDAYS = [1, 2, 3, 4, 5];

function dt_default_work_calendar(): array
{
  return [
    'timezone' => DT_WORK_TIMEZONE,
    'default_start_time' => DT_WORK_START_TIME,
    'default_end_time' => DT_WORK_END_TIME,
    'workdays' => DT_WORKDAYS,
    'exceptions' => [],
  ];
}

function dt_work_calendar(?mysqli $conn = null): array
{
  static $cache = [];

  if (!$conn instanceof mysqli) {
    return dt_default_work_calendar();
  }

  $key = spl_object_id($conn);
  if (isset($cache[$key])) {
    return $cache[$key];
  }

  $calendar = dt_default_work_calendar();

  try {
    $settings = $conn->query("
      SELECT timezone, default_start_time, default_end_time, workdays
      FROM working_calendar_settings
      WHERE id = 1
      LIMIT 1
    ")->fetch_assoc();

    if (is_array($settings)) {
      $timezone = trim((string)($settings['timezone'] ?? ''));
      $start = dt_normalize_time((string)($settings['default_start_time'] ?? ''));
      $end = dt_normalize_time((string)($settings['default_end_time'] ?? ''));
      $workdays = dt_parse_workdays((string)($settings['workdays'] ?? ''));

      if ($timezone !== '') {
        $calendar['timezone'] = $timezone;
      }
      if ($start !== null && $end !== null && $start < $end) {
        $calendar['default_start_time'] = $start;
        $calendar['default_end_time'] = $end;
      }
      if ($workdays !== []) {
        $calendar['workdays'] = $workdays;
      }
    }

    $exceptions = $conn->query("
      SELECT exception_date, exception_type, title, start_time, end_time, notes
      FROM working_calendar_exceptions
      ORDER BY exception_date ASC
    ")->fetch_all(MYSQLI_ASSOC);

    foreach ($exceptions as $exception) {
      $date = trim((string)($exception['exception_date'] ?? ''));
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        continue;
      }

      $calendar['exceptions'][$date] = [
        'type' => (string)($exception['exception_type'] ?? 'non_working'),
        'title' => (string)($exception['title'] ?? ''),
        'start_time' => dt_normalize_time((string)($exception['start_time'] ?? '')),
        'end_time' => dt_normalize_time((string)($exception['end_time'] ?? '')),
        'notes' => (string)($exception['notes'] ?? ''),
      ];
    }
  } catch (Throwable) {
    $calendar = dt_default_work_calendar();
  }

  $cache[$key] = $calendar;
  return $calendar;
}

function dt_work_timezone(?array $calendar = null): DateTimeZone
{
  $calendar = $calendar ?: dt_default_work_calendar();
  $timezone = trim((string)($calendar['timezone'] ?? DT_WORK_TIMEZONE));

  try {
    return new DateTimeZone($timezone !== '' ? $timezone : DT_WORK_TIMEZONE);
  } catch (Throwable) {
    return new DateTimeZone(DT_WORK_TIMEZONE);
  }
}

function dt_parse_manila_datetime(?string $value, ?array $calendar = null): ?DateTimeImmutable
{
  $raw = trim((string)$value);
  if ($raw === '') {
    return null;
  }

  try {
    return new DateTimeImmutable($raw, dt_work_timezone($calendar));
  } catch (Throwable) {
    return null;
  }
}

function dt_normalize_time(string $value): ?string
{
  $raw = trim($value);
  if ($raw === '') {
    return null;
  }

  if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
    $hour = (int)$m[1];
    $minute = (int)$m[2];
    $second = isset($m[3]) ? (int)$m[3] : 0;
    if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59 && $second >= 0 && $second <= 59) {
      return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
    }
  }

  return null;
}

function dt_parse_workdays(string $value): array
{
  $days = [];
  foreach (preg_split('/\s*,\s*/', trim($value)) ?: [] as $part) {
    $day = (int)$part;
    if ($day >= 1 && $day <= 7) {
      $days[] = $day;
    }
  }

  return array_values(array_unique($days));
}

function dt_time_to_seconds(string $time): int
{
  [$hour, $minute, $second] = array_map('intval', explode(':', dt_normalize_time($time) ?? '00:00:00'));
  return ($hour * 3600) + ($minute * 60) + $second;
}

function dt_with_time(DateTimeImmutable $date, string $time): DateTimeImmutable
{
  [$hour, $minute, $second] = array_map('intval', explode(':', dt_normalize_time($time) ?? '00:00:00'));
  return $date->setTime($hour, $minute, $second);
}

function dt_day_window(DateTimeImmutable $date, array $calendar): ?array
{
  $local = $date->setTimezone(dt_work_timezone($calendar));
  $key = $local->format('Y-m-d');
  $exception = $calendar['exceptions'][$key] ?? null;

  if (is_array($exception)) {
    $type = (string)($exception['type'] ?? 'non_working');
    if (dt_calendar_exception_is_non_working($type)) {
      return null;
    }

    $start = (string)($exception['start_time'] ?? '');
    $end = (string)($exception['end_time'] ?? '');
    if ($start === '' || $end === '') {
      $start = (string)$calendar['default_start_time'];
      $end = (string)$calendar['default_end_time'];
    }

    if (dt_time_to_seconds($start) >= dt_time_to_seconds($end)) {
      return null;
    }

    return [dt_with_time($local, $start), dt_with_time($local, $end)];
  }

  $dayOfWeek = (int)$local->format('N');
  $workdays = array_map('intval', (array)($calendar['workdays'] ?? DT_WORKDAYS));
  if (!in_array($dayOfWeek, $workdays, true)) {
    return null;
  }

  $start = (string)($calendar['default_start_time'] ?? DT_WORK_START_TIME);
  $end = (string)($calendar['default_end_time'] ?? DT_WORK_END_TIME);
  if (dt_time_to_seconds($start) >= dt_time_to_seconds($end)) {
    return null;
  }

  return [dt_with_time($local, $start), dt_with_time($local, $end)];
}

function dt_calendar_exception_is_non_working(string $type): bool
{
  return in_array($type, ['non_working', 'special_holiday', 'regular_holiday', 'other_non_working'], true);
}

function dt_work_minutes_per_day(?mysqli $conn = null): int
{
  $calendar = dt_work_calendar($conn);
  $start = (string)($calendar['default_start_time'] ?? DT_WORK_START_TIME);
  $end = (string)($calendar['default_end_time'] ?? DT_WORK_END_TIME);
  return max(1, (int)floor((dt_time_to_seconds($end) - dt_time_to_seconds($start)) / 60));
}

function dt_next_work_start(DateTimeImmutable $date, array $calendar): DateTimeImmutable
{
  $candidate = $date->setTimezone(dt_work_timezone($calendar));

  for ($guard = 0; $guard < 370; $guard++) {
    $window = dt_day_window($candidate, $calendar);
    if ($window !== null) {
      [$dayStart, $dayEnd] = $window;
      if ($candidate < $dayStart) {
        return $dayStart;
      }
      if ($candidate < $dayEnd) {
        return $candidate;
      }
    }

    $candidate = $candidate->modify('+1 day')->setTime(0, 0, 0);
  }

  return $candidate;
}

function dt_working_minutes_between(?string $startRaw, ?string $endRaw = null, ?mysqli $conn = null): int
{
  $calendar = dt_work_calendar($conn);
  $start = dt_parse_manila_datetime($startRaw, $calendar);
  if (!$start) {
    return 0;
  }

  $end = $endRaw !== null
    ? dt_parse_manila_datetime($endRaw, $calendar)
    : new DateTimeImmutable('now', dt_work_timezone($calendar));

  if (!$end || $end <= $start) {
    return 0;
  }

  $cursor = dt_next_work_start($start, $calendar);
  $end = $end->setTimezone(dt_work_timezone($calendar));
  $minutes = 0;

  while ($cursor < $end) {
    $window = dt_day_window($cursor, $calendar);
    if ($window === null) {
      $cursor = dt_next_work_start($cursor->modify('+1 day')->setTime(0, 0, 0), $calendar);
      continue;
    }

    [, $dayEnd] = $window;
    $segmentEnd = $end < $dayEnd ? $end : $dayEnd;

    if ($segmentEnd > $cursor) {
      $minutes += max(0, (int)floor(($segmentEnd->getTimestamp() - $cursor->getTimestamp()) / 60));
    }

    $cursor = dt_next_work_start($dayEnd->modify('+1 day')->setTime(0, 0, 0), $calendar);
  }

  return $minutes;
}

function dt_working_days_from_minutes(int $minutes, ?mysqli $conn = null): int
{
  return max(0, intdiv(max(0, $minutes), dt_work_minutes_per_day($conn)));
}

function dt_format_working_elapsed(int $minutes, ?mysqli $conn = null): string
{
  $minutes = max(0, $minutes);
  if ($minutes <= 0) {
    return '0 working hours';
  }

  $dayMinutes = dt_work_minutes_per_day($conn);
  $days = intdiv($minutes, $dayMinutes);
  $remainingMinutes = $minutes % $dayMinutes;
  $hours = intdiv($remainingMinutes, 60);

  if ($days > 0) {
    $label = $days === 1 ? '1 working day' : "{$days} working days";
    return $hours > 0 ? "{$label} {$hours}h" : $label;
  }

  if ($hours > 0) {
    return $hours === 1 ? '1 working hour' : "{$hours} working hours";
  }

  return 'Under 1 working hour';
}
