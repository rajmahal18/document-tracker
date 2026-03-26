<?php
declare(strict_types=1);

function normalize_whitespace(string $value): string {
  return trim((string)(preg_replace('/\s+/', ' ', $value) ?? $value));
}

function username_column_exists(mysqli $conn): bool {
  return db_column_exists($conn, 'users', 'username');
}

function email_verified_at_column_exists(mysqli $conn): bool {
  return db_column_exists($conn, 'users', 'email_verified_at');
}

function slug_token(string $value): string {
  $value = strtolower(trim($value));
  $value = preg_replace('/[^a-z0-9]+/i', '', $value) ?? '';
  return $value;
}

function generate_username_base_from_name(string $fullName): string {
  $fullName = normalize_whitespace($fullName);
  if ($fullName === '') {
    return 'user';
  }

  $parts = preg_split('/\s+/', $fullName) ?: [];
  $parts = array_values(array_filter(array_map(static fn($v) => trim((string)$v, "., "), $parts), static fn($v) => $v !== ''));
  if ($parts === []) {
    return 'user';
  }

  $count = count($parts);
  $lastName = slug_token($parts[$count - 1]);
  if ($lastName === '') {
    $lastName = 'user';
  }

  if ($count === 1) {
    return $lastName;
  }

  $middleInitial = '';
  $givenParts = [$parts[0]];
  if ($count >= 3) {
    $middleSource = $parts[$count - 2];
    $middleInitial = strtolower(substr(slug_token($middleSource), 0, 1));
    $givenParts = array_slice($parts, 0, $count - 2);
  }

  $givenInitials = '';
  foreach ($givenParts as $part) {
    $clean = slug_token($part);
    if ($clean === '') continue;
    $givenInitials .= strtolower(substr($clean, 0, 1));
  }

  $base = $givenInitials . $middleInitial . $lastName;
  $base = preg_replace('/[^a-z0-9]+/i', '', strtolower($base)) ?? '';
  return $base !== '' ? $base : 'user';
}

function generate_unique_username(mysqli $conn, string $fullName, int $excludeUserId = 0): string {
  $base = generate_username_base_from_name($fullName);
  if (!username_column_exists($conn)) {
    return $base;
  }

  $candidate = $base;
  $counter = 1;
  while (true) {
    if ($excludeUserId > 0) {
      $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
      $stmt->bind_param('si', $candidate, $excludeUserId);
    } else {
      $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
      $stmt->bind_param('s', $candidate);
    }
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$exists) {
      return $candidate;
    }

    $counter++;
    $candidate = $base . $counter;
  }
}

function refresh_session_identity(mysqli $conn, int $userId): void {
  $hasOfficialTitle = db_column_exists($conn, 'users', 'official_title');
  $hasAuthorityRole = db_column_exists($conn, 'users', 'authority_role');
  $hasUsername = username_column_exists($conn);
  $sql = '
      SELECT
        u.id,
        u.full_name,
        ' . ($hasUsername ? 'u.username' : 'NULL') . ' AS username,
        u.email,
        u.must_change_password,
        u.role,
        u.section_id,
        u.is_chief,
        ' . ($hasOfficialTitle ? 'u.official_title' : 'NULL') . ' AS official_title,
        ' . ($hasAuthorityRole ? 'u.authority_role' : 'NULL') . ' AS authority_role,
        s.name AS section_name,
        d.id AS division_id,
        d.name AS division_name
      FROM users u
      LEFT JOIN sections s ON s.id = u.section_id
      LEFT JOIN divisions d ON d.id = s.division_id
      WHERE u.id = ?
      LIMIT 1';
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$user) return;

  $_SESSION['user_id'] = (int)$user['id'];
  $_SESSION['full_name'] = (string)$user['full_name'];
  $_SESSION['username'] = trim((string)($user['username'] ?? ''));
  $_SESSION['email'] = (string)($user['email'] ?? '');
  $_SESSION['role'] = (string)($user['role'] ?? 'user');
  $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0);
  $_SESSION['section_id'] = isset($user['section_id']) ? (int)$user['section_id'] : null;
  $_SESSION['section_name'] = (string)($user['section_name'] ?? '');
  $_SESSION['division_id'] = isset($user['division_id']) ? (int)$user['division_id'] : null;
  $_SESSION['division_name'] = (string)($user['division_name'] ?? '');

  $rawAuthorityRole = trim((string)($user['authority_role'] ?? ''));
  if ($rawAuthorityRole === '') {
    if ((string)($user['role'] ?? 'user') === 'admin') {
      $rawAuthorityRole = 'admin';
    } elseif ((int)($user['is_chief'] ?? 0) === 1) {
      $rawAuthorityRole = 'section_head';
    } else {
      $rawAuthorityRole = 'staff';
    }
  }
  $_SESSION['authority_role'] = $rawAuthorityRole;
  $_SESSION['official_title'] = trim((string)($user['official_title'] ?? ''));
  $_SESSION['is_chief'] = (int)($user['is_chief'] ?? 0);
}
