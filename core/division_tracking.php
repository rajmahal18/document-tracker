<?php
declare(strict_types=1);

function ensure_division_code_column(mysqli $conn): void
{
  $res = $conn->query("SHOW COLUMNS FROM divisions LIKE 'code'");
  $exists = $res && $res->num_rows > 0;
  if (!$exists) {
    $conn->query("ALTER TABLE divisions ADD COLUMN code VARCHAR(16) NULL AFTER name");
  }

  $stmt = $conn->prepare("UPDATE divisions SET code = ? WHERE id = ? AND (code IS NULL OR code = '')");
  $map = [
    1 => 'TS',
    2 => 'PPD',
    3 => 'SDD',
    4 => 'SPD',
  ];
  foreach ($map as $id => $code) {
    $stmt->bind_param('si', $code, $id);
    $stmt->execute();
  }

  $stmt = $conn->prepare("UPDATE divisions SET code = ? WHERE LOWER(name) LIKE ? AND (code IS NULL OR code = '')");
  $fallbacks = [
    'PPD' => '%planning and programming%',
    'SDD' => '%survey and design%',
    'SPD' => '%special project%',
    'TS' => '%technical services%',
  ];
  foreach ($fallbacks as $code => $like) {
    $stmt->bind_param('ss', $code, $like);
    $stmt->execute();
  }
}


function ensure_users_permanent_column(mysqli $conn): void
{
  $res = $conn->query("SHOW COLUMNS FROM users LIKE 'permanent'");
  $exists = $res && $res->num_rows > 0;
  if (!$exists) {
    $conn->query("ALTER TABLE users ADD COLUMN permanent TINYINT(1) NOT NULL DEFAULT 0 AFTER is_chief");
  }
}

function normalize_pdf_text(string $text): string
{
  $text = str_replace(["\r\n", "\r"], "\n", $text);
  $map = [
    "\xE2\x86\x92" => ' to ',
    "→" => ' to ',
    "—" => ' - ',
    "–" => '-',
    "•" => '-',
    "…" => '...',
    "“" => '"',
    "”" => '"',
    "’" => "'",
    "‘" => "'",
    "\t" => ' ',
  ];
  $text = strtr($text, $map);
  $text = preg_replace('/[^\P{C}\n]+/u', '', $text) ?? $text;
  if (function_exists('iconv')) {
    $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
    if ($converted !== false) {
      return $converted;
    }
  }
  return preg_replace('/[^\x20-\x7E\n]/', '', $text) ?? '';
}

function user_initials_from_name(string $name): string
{
  $parts = preg_split('/\s+/', trim($name)) ?: [];
  $initials = '';
  foreach ($parts as $part) {
    $part = trim($part, ".,- ");
    if ($part === '') continue;
    $initials .= strtoupper(substr($part, 0, 1));
    if (strlen($initials) >= 4) break;
  }
  return $initials;
}

function get_division_permanent_staff(mysqli $conn, int $divisionId, int $excludeUserId = 0): array
{
  ensure_division_tracking_tables($conn);
  ensure_users_permanent_column($conn);
  if ($divisionId <= 0) return [];

  $sql = "SELECT
      u.id,
      u.full_name,
      COALESCE(NULLIF(TRIM(u.official_title), ''), '') AS official_title,
      LOWER(TRIM(COALESCE(u.authority_role, ''))) AS authority_role
    FROM users u
    JOIN sections s ON s.id = u.section_id
    JOIN divisions d ON d.id = s.division_id
    WHERE d.id = ?
      AND u.is_active = 1
      AND s.is_active = 1
      AND d.is_active = 1
      AND u.permanent = 1";
  $types = 'i';
  $params = [$divisionId];
  if ($excludeUserId > 0) {
    $sql .= ' AND u.id <> ?';
    $types .= 'i';
    $params[] = $excludeUserId;
  }
  $sql .= " AND LOWER(TRIM(COALESCE(u.authority_role, ''))) <> 'division_head'
    ORDER BY
    CASE LOWER(TRIM(COALESCE(u.authority_role, '')))
      WHEN 'division_assistant' THEN 0
      WHEN 'section_head' THEN 1
      ELSE 2
    END ASC,
    CASE WHEN LOWER(TRIM(COALESCE(u.authority_role, ''))) = 'section_head' AND u.is_chief = 1 THEN 0 ELSE 1 END ASC,
    s.name ASC,
    u.full_name ASC";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  return $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
}

function build_division_name_initial_entries(mysqli $conn, int $divisionId, int $excludeUserId = 0, int $limit = 9): array
{
  $rows = get_division_permanent_staff($conn, $divisionId, $excludeUserId);
  $out = [];
  foreach ($rows as $row) {
    $name = strtoupper(trim((string)($row['full_name'] ?? '')));
    if ($name === '') continue;
    $initials = user_initials_from_name($name);
    $label = $initials !== '' ? ($name . ' (' . $initials . ')') : $name;
    $out[] = normalize_pdf_text($label);
    if (count($out) >= $limit) break;
  }
  return $out;
}

function ensure_division_tracking_tables(mysqli $conn): void
{
  ensure_division_code_column($conn);
  ensure_users_permanent_column($conn);

  $conn->query("CREATE TABLE IF NOT EXISTS division_tracking_sequences (
    division_id INT NOT NULL,
    tracking_date DATE NOT NULL,
    last_number SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (division_id, tracking_date),
    CONSTRAINT fk_division_tracking_seq_division FOREIGN KEY (division_id) REFERENCES divisions(id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  $conn->query("CREATE TABLE IF NOT EXISTS document_division_tracking (
    id INT NOT NULL AUTO_INCREMENT,
    document_id INT NOT NULL,
    division_id INT NOT NULL,
    tracking_no VARCHAR(32) NOT NULL,
    tracking_date DATE NOT NULL,
    sequence_no SMALLINT UNSIGNED NOT NULL,
    is_manual TINYINT(1) NOT NULL DEFAULT 0,
    created_by_user_id INT DEFAULT NULL,
    updated_by_user_id INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_doc_division_tracking_doc_division (document_id, division_id),
    UNIQUE KEY uq_doc_division_tracking_no (division_id, tracking_no),
    KEY idx_doc_division_tracking_doc (document_id),
    CONSTRAINT fk_doc_division_tracking_doc FOREIGN KEY (document_id) REFERENCES documents(id),
    CONSTRAINT fk_doc_division_tracking_division FOREIGN KEY (division_id) REFERENCES divisions(id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function get_division_meta(mysqli $conn, int $divisionId): ?array
{
  ensure_division_tracking_tables($conn);
  if ($divisionId <= 0) return null;
  $stmt = $conn->prepare("SELECT id, name, COALESCE(code, '') AS code FROM divisions WHERE id = ? AND is_active = 1 LIMIT 1");
  $stmt->bind_param('i', $divisionId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  if (!$row) return null;
  return [
    'id' => (int)$row['id'],
    'name' => (string)$row['name'],
    'code' => trim((string)$row['code']),
  ];
}

function get_user_division_meta(mysqli $conn, int $sectionId): ?array
{
  ensure_division_tracking_tables($conn);
  if ($sectionId <= 0) return null;
  $stmt = $conn->prepare("SELECT d.id, d.name, COALESCE(d.code, '') AS code
    FROM sections s
    JOIN divisions d ON d.id = s.division_id
    WHERE s.id = ? AND s.is_active = 1 AND d.is_active = 1
    LIMIT 1");
  $stmt->bind_param('i', $sectionId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  if (!$row) return null;
  return [
    'id' => (int)$row['id'],
    'name' => (string)$row['name'],
    'code' => trim((string)$row['code']),
  ];
}

function is_supported_division_tracking_code(?string $code): bool
{
  return in_array(strtoupper(trim((string)$code)), ['PPD', 'SDD', 'SPD'], true);
}

function resolve_division_head(mysqli $conn, int $divisionId): ?array
{
  if ($divisionId <= 0) return null;
  $stmt = $conn->prepare("SELECT
      u.id,
      u.full_name,
      COALESCE(NULLIF(TRIM(u.official_title), ''), '') AS official_title,
      COALESCE(NULLIF(TRIM(u.authority_role), ''), 'staff') AS authority_role,
      u.is_chief,
      s.name AS section_name,
      d.name AS division_name,
      COALESCE(d.code, '') AS division_code
    FROM users u
    JOIN sections s ON s.id = u.section_id
    JOIN divisions d ON d.id = s.division_id
    WHERE d.id = ?
      AND u.is_active = 1
      AND s.is_active = 1
      AND d.is_active = 1
    ORDER BY
      CASE LOWER(COALESCE(u.authority_role, ''))
        WHEN 'division_head' THEN 0
        WHEN 'division_assistant' THEN 1
        WHEN 'section_head' THEN 2
        ELSE 3
      END ASC,
      CASE WHEN u.is_chief = 1 THEN 0 ELSE 1 END ASC,
      CASE WHEN LOWER(s.name) LIKE '%division chief%' THEN 0 ELSE 1 END ASC,
      u.full_name ASC
    LIMIT 1");
  $stmt->bind_param('i', $divisionId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  if (!$row) return null;
  return $row;
}

function resolve_transmittal_recipients(mysqli $conn): array
{
  ensure_division_tracking_tables($conn);
  $rows = $conn->query("SELECT id, name, COALESCE(code, '') AS code FROM divisions WHERE is_active = 1 ORDER BY id ASC");
  $out = [];
  while ($row = $rows->fetch_assoc()) {
    $code = strtoupper(trim((string)($row['code'] ?? '')));
    if (!in_array($code, ['PPD', 'SDD', 'SPD'], true)) continue;
    $head = resolve_division_head($conn, (int)$row['id']);
    if (!$head) continue;
    $title = trim((string)($head['official_title'] ?? ''));
    if ($title === '') {
      $title = 'Chief';
    }
    $out[] = [
      'name' => strtoupper(trim((string)$head['full_name'])),
      'title' => $title . ', ' . (string)$row['name'],
      'division_code' => $code,
    ];
  }
  return $out;
}

function get_next_division_tracking_number(mysqli $conn, int $divisionId, ?DateTimeImmutable $now = null): string
{
  ensure_division_tracking_tables($conn);
  $meta = get_division_meta($conn, $divisionId);
  if (!$meta || !is_supported_division_tracking_code($meta['code'])) {
    throw new RuntimeException('Division tracking is not enabled for this division.');
  }
  $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
  $trackingDate = $now->format('Y-m-d');

  $conn->begin_transaction();
  try {
    $stmt = $conn->prepare("INSERT INTO division_tracking_sequences (division_id, tracking_date, last_number)
      VALUES (?, ?, 0)
      ON DUPLICATE KEY UPDATE division_id = division_id");
    $stmt->bind_param('is', $divisionId, $trackingDate);
    $stmt->execute();

    $stmt = $conn->prepare("SELECT last_number FROM division_tracking_sequences WHERE division_id = ? AND tracking_date = ? FOR UPDATE");
    $stmt->bind_param('is', $divisionId, $trackingDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $last = (int)($row['last_number'] ?? 0);
    $next = $last + 1;

    $stmt = $conn->prepare("UPDATE division_tracking_sequences SET last_number = ? WHERE division_id = ? AND tracking_date = ?");
    $stmt->bind_param('iis', $next, $divisionId, $trackingDate);
    $stmt->execute();
    $conn->commit();
  } catch (Throwable $e) {
    $conn->rollback();
    throw $e;
  }

  return sprintf('%s %s%02d', $meta['code'], $now->format('mdy'), $next);
}

function preview_next_division_tracking_number(mysqli $conn, int $divisionId, ?DateTimeImmutable $now = null): string
{
  ensure_division_tracking_tables($conn);
  $meta = get_division_meta($conn, $divisionId);
  if (!$meta || !is_supported_division_tracking_code($meta['code'])) {
    return '';
  }
  $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
  $trackingDate = $now->format('Y-m-d');
  $stmt = $conn->prepare("SELECT last_number FROM division_tracking_sequences WHERE division_id = ? AND tracking_date = ? LIMIT 1");
  $stmt->bind_param('is', $divisionId, $trackingDate);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $next = ((int)($row['last_number'] ?? 0)) + 1;
  return sprintf('%s %s%02d', $meta['code'], $now->format('mdy'), $next);
}

function parse_and_validate_division_tracking_no(mysqli $conn, int $divisionId, string $trackingNo, ?int $excludeDocumentId = null): array
{
  ensure_division_tracking_tables($conn);
  $meta = get_division_meta($conn, $divisionId);
  if (!$meta || !is_supported_division_tracking_code($meta['code'])) {
    throw new RuntimeException('Division tracking is not enabled for this division.');
  }
  $trackingNo = strtoupper(trim($trackingNo));
  $pattern = '/^' . preg_quote($meta['code'], '/') . '\s+(\d{6})(\d{2})$/';
  if (!preg_match($pattern, $trackingNo, $m)) {
    throw new RuntimeException('Division tracking number must follow the format ' . $meta['code'] . ' MMDDYYNN.');
  }
  $datePart = $m[1];
  $seq = (int)$m[2];
  $dt = DateTimeImmutable::createFromFormat('mdy', $datePart, new DateTimeZone('Asia/Manila'));
  if (!$dt || $dt->format('mdy') !== $datePart) {
    throw new RuntimeException('Division tracking number has an invalid date segment.');
  }
  if ($seq <= 0 || $seq > 99) {
    throw new RuntimeException('Division tracking number sequence must be between 01 and 99.');
  }

  $sql = "SELECT document_id FROM document_division_tracking WHERE division_id = ? AND tracking_no = ?";
  $types = 'is';
  $params = [$divisionId, $trackingNo];
  if ($excludeDocumentId !== null && $excludeDocumentId > 0) {
    $sql .= " AND document_id <> ?";
    $types .= 'i';
    $params[] = $excludeDocumentId;
  }
  $sql .= ' LIMIT 1';
  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  if ($stmt->get_result()->fetch_assoc()) {
    throw new RuntimeException('Division tracking number already exists for this division.');
  }

  return [
    'tracking_no' => $trackingNo,
    'tracking_date' => $dt->format('Y-m-d'),
    'sequence_no' => $seq,
  ];
}

function upsert_document_division_tracking(mysqli $conn, int $documentId, int $divisionId, string $trackingNo, int $actorUserId, bool $isManual = false): void
{
  $parsed = parse_and_validate_division_tracking_no($conn, $divisionId, $trackingNo, $documentId);
  $stmt = $conn->prepare("INSERT INTO document_division_tracking
    (document_id, division_id, tracking_no, tracking_date, sequence_no, is_manual, created_by_user_id, updated_by_user_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      tracking_no = VALUES(tracking_no),
      tracking_date = VALUES(tracking_date),
      sequence_no = VALUES(sequence_no),
      is_manual = VALUES(is_manual),
      updated_by_user_id = VALUES(updated_by_user_id)");
  $manual = $isManual ? 1 : 0;
  $stmt->bind_param('iissiiii', $documentId, $divisionId, $parsed['tracking_no'], $parsed['tracking_date'], $parsed['sequence_no'], $manual, $actorUserId, $actorUserId);
  $stmt->execute();

  $currentLast = 0;
  $stmt = $conn->prepare('SELECT last_number FROM division_tracking_sequences WHERE division_id = ? AND tracking_date = ? LIMIT 1');
  $stmt->bind_param('is', $divisionId, $parsed['tracking_date']);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $currentLast = (int)($row['last_number'] ?? 0);
  if ($parsed['sequence_no'] > $currentLast) {
    $stmt = $conn->prepare("INSERT INTO division_tracking_sequences (division_id, tracking_date, last_number)
      VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))");
    $stmt->bind_param('isi', $divisionId, $parsed['tracking_date'], $parsed['sequence_no']);
    $stmt->execute();
  }
}

function get_document_division_tracking(mysqli $conn, int $documentId, int $divisionId): ?array
{
  ensure_division_tracking_tables($conn);
  $stmt = $conn->prepare("SELECT ddt.*, d.name AS division_name, COALESCE(d.code, '') AS division_code
    FROM document_division_tracking ddt
    JOIN divisions d ON d.id = ddt.division_id
    WHERE ddt.document_id = ? AND ddt.division_id = ?
    LIMIT 1");
  $stmt->bind_param('ii', $documentId, $divisionId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  return $row ?: null;
}

function build_division_slip_flow_rows(mysqli $conn, int $documentId, int $divisionId): array
{
  // Movement auto-generation intentionally disabled for now.
  // Leave the slip movement table blank until a paper-accurate flow rule is finalized.
  return [];
}
