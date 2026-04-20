<?php
declare(strict_types=1);

const DT_WORK_TIMEZONE = 'Asia/Manila';
const DT_WORK_START_HOUR = 8;
const DT_WORK_END_HOUR = 17;

function dt_work_timezone(): DateTimeZone
{
  static $timezone = null;
  if (!$timezone instanceof DateTimeZone) {
    $timezone = new DateTimeZone(DT_WORK_TIMEZONE);
  }
  return $timezone;
}

function dt_parse_manila_datetime(?string $value): ?DateTimeImmutable
{
  $raw = trim((string)$value);
  if ($raw === '') {
    return null;
  }

  try {
    return new DateTimeImmutable($raw, dt_work_timezone());
  } catch (Throwable) {
    return null;
  }
}

function dt_is_workday(DateTimeImmutable $date): bool
{
  $dayOfWeek = (int)$date->format('N');
  return $dayOfWeek >= 1 && $dayOfWeek <= 5;
}

function dt_work_minutes_per_day(): int
{
  return max(1, (DT_WORK_END_HOUR - DT_WORK_START_HOUR) * 60);
}

function dt_next_work_start(DateTimeImmutable $date): DateTimeImmutable
{
  $candidate = $date->setTimezone(dt_work_timezone());

  while (!dt_is_workday($candidate)) {
    $candidate = $candidate->modify('+1 day')->setTime(DT_WORK_START_HOUR, 0, 0);
  }

  $dayStart = $candidate->setTime(DT_WORK_START_HOUR, 0, 0);
  $dayEnd = $candidate->setTime(DT_WORK_END_HOUR, 0, 0);

  if ($candidate < $dayStart) {
    return $dayStart;
  }

  if ($candidate >= $dayEnd) {
    return dt_next_work_start($candidate->modify('+1 day')->setTime(DT_WORK_START_HOUR, 0, 0));
  }

  return $candidate;
}

function dt_working_minutes_between(?string $startRaw, ?string $endRaw = null): int
{
  $start = dt_parse_manila_datetime($startRaw);
  if (!$start) {
    return 0;
  }

  $end = $endRaw !== null
    ? dt_parse_manila_datetime($endRaw)
    : new DateTimeImmutable('now', dt_work_timezone());

  if (!$end || $end <= $start) {
    return 0;
  }

  $cursor = dt_next_work_start($start);
  $end = $end->setTimezone(dt_work_timezone());
  $minutes = 0;

  while ($cursor < $end) {
    $dayEnd = $cursor->setTime(DT_WORK_END_HOUR, 0, 0);
    $segmentEnd = $end < $dayEnd ? $end : $dayEnd;

    if ($segmentEnd > $cursor) {
      $minutes += max(0, (int)floor(($segmentEnd->getTimestamp() - $cursor->getTimestamp()) / 60));
    }

    $cursor = dt_next_work_start($dayEnd->modify('+1 day')->setTime(DT_WORK_START_HOUR, 0, 0));
  }

  return $minutes;
}

function dt_working_days_from_minutes(int $minutes): int
{
  return max(0, intdiv(max(0, $minutes), dt_work_minutes_per_day()));
}

function dt_format_working_elapsed(int $minutes): string
{
  $minutes = max(0, $minutes);
  if ($minutes <= 0) {
    return '0 working hours';
  }

  $dayMinutes = dt_work_minutes_per_day();
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
