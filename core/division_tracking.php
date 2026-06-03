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

function division_tracking_initials_label(string $name): string
{
  $initials = user_initials_from_name($name);
  return $initials !== '' ? $initials : trim($name);
}

function division_tracking_slip_name_label(string $name): string
{
  $parts = preg_split('/\s+/', trim($name)) ?: [];
  $parts = array_values(array_filter(array_map(
    static fn(string $part): string => trim($part, ".,- "),
    $parts
  ), static fn(string $part): bool => $part !== ''));

  if (count($parts) <= 1) {
    return strtoupper(trim($name));
  }

  $suffixes = [];
  while ($parts !== []) {
    $candidate = strtoupper(rtrim((string)end($parts), '.'));
    if (!in_array($candidate, ['JR', 'SR', 'II', 'III', 'IV', 'V', 'VI'], true)) {
      break;
    }
    $suffix = array_pop($parts);
    $suffixes[] = strtoupper($candidate) === 'JR' || strtoupper($candidate) === 'SR'
      ? ucfirst(strtolower($candidate)) . '.'
      : $candidate;
  }

  if ($parts === []) {
    return strtoupper(trim($name));
  }

  $last = array_pop($parts);
  $last = ucwords(strtolower($last));
  $prefix = [];
  foreach ($parts as $part) {
    $prefix[] = strtoupper(substr($part, 0, 1)) . '.';
  }

  $suffixText = $suffixes !== [] ? (' ' . implode(' ', array_reverse($suffixes))) : '';
  $compact = trim(implode(' ', $prefix) . ' ' . $last . $suffixText);
  $initials = user_initials_from_name($name);
  return $initials !== '' ? ($compact . ' (' . $initials . ')') : $compact;
}

function build_division_slip_assigned_to_label(mysqli $conn, int $documentId): string
{
  if ($documentId <= 0) {
    return '';
  }

  $labels = [];

  if (workflow_branch_mode_enabled($conn)) {
    $stmt = $conn->prepare("
      SELECT
        COALESCE(NULLIF(TRIM(u.full_name), ''), '') AS assignee_name,
        COALESCE(NULLIF(TRIM(s.name), ''), '') AS section_name
      FROM document_branches b
      LEFT JOIN users u ON u.id = b.current_assignee_user_id
      LEFT JOIN sections s ON s.id = b.current_assignee_section_id
      WHERE b.document_id = ?
        AND b.branch_status = 'ACTIVE'
        AND b.is_reference = 0
      ORDER BY b.id ASC
      LIMIT 8
    ");
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
      $name = trim((string)($row['assignee_name'] ?? ''));
      $section = trim((string)($row['section_name'] ?? ''));
      $label = $name !== '' ? $name : $section;
      if ($name !== '' && $section !== '') {
        $label = $name . ' (' . $section . ')';
      }
      if ($label !== '') {
        $labels[] = $label;
      }
    }
  }

  if ($labels === []) {
    $stmt = $conn->prepare("
      SELECT
        COALESCE(NULLIF(TRIM(u.full_name), ''), '') AS to_user_name,
        COALESCE(NULLIF(TRIM(s.name), ''), '') AS to_section_name
      FROM routes r
      LEFT JOIN users u ON u.id = r.to_user_id
      LEFT JOIN sections s ON s.id = r.to_section_id
      WHERE r.document_id = ?
        AND r.received_at IS NULL
        AND r.cancelled_at IS NULL
      ORDER BY r.sent_at ASC, r.id ASC
      LIMIT 8
    ");
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [] as $row) {
      $name = trim((string)($row['to_user_name'] ?? ''));
      $section = trim((string)($row['to_section_name'] ?? ''));
      $label = $name !== '' ? $name : $section;
      if ($name !== '' && $section !== '') {
        $label = $name . ' (' . $section . ')';
      }
      if ($label !== '') {
        $labels[] = $label;
      }
    }
  }

  $labels = array_values(array_unique($labels));
  return normalize_pdf_text(implode(', ', $labels));
}

function get_division_slip_head_staff(mysqli $conn, int $divisionId, int $excludeUserId = 0): array
{
  ensure_division_tracking_tables($conn);
  if ($divisionId <= 0) return [];

  $sql = "SELECT
      u.id,
      u.full_name,
      COALESCE(NULLIF(TRIM(u.official_title), ''), '') AS official_title,
      LOWER(TRIM(COALESCE(u.authority_role, ''))) AS authority_role,
      COALESCE(o.sort_order, 999) AS slip_sort_order
    FROM users u
    JOIN sections s ON s.id = u.section_id
    JOIN divisions d ON d.id = s.division_id
    LEFT JOIN division_tracking_slip_user_order o
      ON o.division_id = d.id
      AND o.user_id = u.id
    WHERE d.id = ?
      AND u.is_active = 1
      AND s.is_active = 1
      AND d.is_active = 1
      AND LOWER(TRIM(COALESCE(u.authority_role, ''))) IN ('division_assistant', 'section_head')";
  $types = 'i';
  $params = [$divisionId];
  if ($excludeUserId > 0) {
    $sql .= ' AND u.id <> ?';
    $types .= 'i';
    $params[] = $excludeUserId;
  }
  $sql .= " ORDER BY
    CASE LOWER(TRIM(COALESCE(u.authority_role, '')))
      WHEN 'division_assistant' THEN 0
      WHEN 'section_head' THEN 1
      ELSE 2
    END ASC,
    COALESCE(o.sort_order, 999) ASC,
    CASE WHEN LOWER(TRIM(COALESCE(u.authority_role, ''))) = 'section_head' AND u.is_chief = 1 THEN 0 ELSE 1 END ASC,
    s.name ASC,
    u.full_name ASC";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  return $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
}

function build_division_name_initial_entries(mysqli $conn, int $divisionId, int $excludeUserId = 0, int $limit = 8): array
{
  $generalEntries = ['All Permanent Staff', 'All J.O. Staff', 'All Staff', 'Others: _____________________'];
  $headLimit = min(4, max(0, $limit - count($generalEntries)));
  $rows = get_division_slip_head_staff($conn, $divisionId, $excludeUserId);
  $out = [];
  foreach ($rows as $row) {
    $name = trim((string)($row['full_name'] ?? ''));
    if ($name === '') continue;
    $label = division_tracking_slip_name_label($name);
    $out[] = normalize_pdf_text($label);
    if (count($out) >= $headLimit) break;
  }

  foreach ($generalEntries as $entry) {
    if (count($out) >= $limit) break;
    $out[] = normalize_pdf_text($entry);
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
    tracking_scope VARCHAR(16) NOT NULL DEFAULT 'INCOMING',
    last_number SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (division_id, tracking_date, tracking_scope),
    CONSTRAINT fk_division_tracking_seq_division FOREIGN KEY (division_id) REFERENCES divisions(id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  $conn->query("CREATE TABLE IF NOT EXISTS document_division_tracking (
    id INT NOT NULL AUTO_INCREMENT,
    document_id INT NOT NULL,
    division_id INT NOT NULL,
    tracking_scope VARCHAR(16) NOT NULL DEFAULT 'INCOMING',
    tracking_no VARCHAR(32) NOT NULL,
    duplicate_guard_no VARCHAR(32) DEFAULT NULL,
    tracking_date DATE NOT NULL,
    sequence_no SMALLINT UNSIGNED NOT NULL,
    is_manual TINYINT(1) NOT NULL DEFAULT 0,
    is_duplicate_override TINYINT(1) NOT NULL DEFAULT 0,
    created_by_user_id INT DEFAULT NULL,
    updated_by_user_id INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_doc_division_tracking_doc_division (document_id, division_id),
    UNIQUE KEY uq_doc_division_tracking_scope_guard (division_id, tracking_scope, duplicate_guard_no),
    KEY idx_doc_division_tracking_doc (document_id),
    KEY idx_doc_division_tracking_division (division_id),
    CONSTRAINT fk_doc_division_tracking_doc FOREIGN KEY (document_id) REFERENCES documents(id),
    CONSTRAINT fk_doc_division_tracking_division FOREIGN KEY (division_id) REFERENCES divisions(id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  ensure_division_tracking_scope_columns($conn);

  ensure_document_division_tracking_duplicate_override_support($conn);

  $conn->query("CREATE TABLE IF NOT EXISTS division_tracking_slip_user_order (
    division_id INT NOT NULL,
    user_id INT NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 999,
    updated_by_user_id INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (division_id, user_id),
    KEY idx_division_tracking_slip_order_sort (division_id, sort_order),
    CONSTRAINT fk_division_tracking_slip_order_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE,
    CONSTRAINT fk_division_tracking_slip_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_division_tracking_slip_order_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensure_division_tracking_scope_columns(mysqli $conn): void
{
  $dbResult = $conn->query('SELECT DATABASE()');
  $dbRow = $dbResult ? $dbResult->fetch_row() : null;
  $dbName = trim((string)($dbRow[0] ?? ''));
  if ($dbName === '') {
    return;
  }

  $stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = ?
      AND table_name = 'division_tracking_sequences'
      AND column_name = 'tracking_scope'
  ");
  $stmt->bind_param('s', $dbName);
  $stmt->execute();
  $hasSequenceScope = (int)($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
  if (!$hasSequenceScope) {
    $conn->query("ALTER TABLE division_tracking_sequences ADD COLUMN tracking_scope VARCHAR(16) NOT NULL DEFAULT 'INCOMING' AFTER tracking_date");
  }

  $stmt = $conn->prepare("
    SELECT column_name
    FROM information_schema.key_column_usage
    WHERE table_schema = ?
      AND table_name = 'division_tracking_sequences'
      AND constraint_name = 'PRIMARY'
    ORDER BY ordinal_position ASC
  ");
  $stmt->bind_param('s', $dbName);
  $stmt->execute();
  $pkCols = array_map(
    static fn(array $row): string => (string)($row['column_name'] ?? ''),
    $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: []
  );
  if ($pkCols !== ['division_id', 'tracking_date', 'tracking_scope']) {
    $conn->query('ALTER TABLE division_tracking_sequences DROP PRIMARY KEY, ADD PRIMARY KEY (division_id, tracking_date, tracking_scope)');
  }

  $stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = ?
      AND table_name = 'document_division_tracking'
      AND column_name = 'tracking_scope'
  ");
  $stmt->bind_param('s', $dbName);
  $stmt->execute();
  $hasDocumentScope = (int)($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
  if (!$hasDocumentScope) {
    $conn->query("ALTER TABLE document_division_tracking ADD COLUMN tracking_scope VARCHAR(16) NOT NULL DEFAULT 'INCOMING' AFTER division_id");
  }
}

function ensure_document_division_tracking_duplicate_override_support(mysqli $conn): void
{
  $dbResult = $conn->query('SELECT DATABASE()');
  $dbRow = $dbResult ? $dbResult->fetch_row() : null;
  $dbName = trim((string)($dbRow[0] ?? ''));
  if ($dbName === '') {
    return;
  }

  $stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = ?
      AND table_name = 'document_division_tracking'
      AND column_name = 'is_duplicate_override'
  ");
  $stmt->bind_param('s', $dbName);
  $stmt->execute();
  $hasDuplicateOverride = (int)($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
  if (!$hasDuplicateOverride) {
    $conn->query("ALTER TABLE document_division_tracking ADD COLUMN is_duplicate_override TINYINT(1) NOT NULL DEFAULT 0 AFTER is_manual");
  }

  $stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = ?
      AND table_name = 'document_division_tracking'
      AND column_name = 'duplicate_guard_no'
  ");
  $stmt->bind_param('s', $dbName);
  $stmt->execute();
  $hasDuplicateGuard = (int)($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
  if (!$hasDuplicateGuard) {
    $conn->query("ALTER TABLE document_division_tracking ADD COLUMN duplicate_guard_no VARCHAR(32) DEFAULT NULL AFTER tracking_no");
  }

  $conn->query("
    UPDATE document_division_tracking ddt
    JOIN (
      SELECT
        division_id,
        COALESCE(tracking_scope, 'INCOMING') AS tracking_scope,
        tracking_no,
        MIN(id) AS keeper_id
      FROM document_division_tracking
      GROUP BY division_id, COALESCE(tracking_scope, 'INCOMING'), tracking_no
      HAVING COUNT(*) > 1
    ) dup
      ON dup.division_id = ddt.division_id
     AND dup.tracking_scope = COALESCE(ddt.tracking_scope, 'INCOMING')
     AND dup.tracking_no = ddt.tracking_no
    SET
      ddt.is_duplicate_override = CASE WHEN ddt.id = dup.keeper_id THEN 0 ELSE 1 END,
      ddt.duplicate_guard_no = CASE WHEN ddt.id = dup.keeper_id THEN ddt.tracking_no ELSE NULL END
  ");

  $conn->query("
    UPDATE document_division_tracking
    SET duplicate_guard_no = CASE
      WHEN COALESCE(is_duplicate_override, 0) = 1 THEN NULL
      ELSE tracking_no
    END
    WHERE
      (COALESCE(is_duplicate_override, 0) = 1 AND duplicate_guard_no IS NOT NULL)
      OR
      (COALESCE(is_duplicate_override, 0) <> 1 AND COALESCE(duplicate_guard_no, '') <> tracking_no)
  ");

  $stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = ?
      AND table_name = 'document_division_tracking'
      AND index_name = 'uq_doc_division_tracking_no'
  ");
  $stmt->bind_param('s', $dbName);
  $stmt->execute();
  $exists = (int)($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
  if ($exists) {
    $stmt = $conn->prepare("
      SELECT COUNT(*)
      FROM information_schema.statistics
      WHERE table_schema = ?
        AND table_name = 'document_division_tracking'
        AND index_name = 'idx_doc_division_tracking_division'
    ");
    $stmt->bind_param('s', $dbName);
    $stmt->execute();
    $divisionIdxExists = (int)($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
    if (!$divisionIdxExists) {
      $conn->query('ALTER TABLE document_division_tracking ADD INDEX idx_doc_division_tracking_division (division_id)');
    }

    $conn->query('ALTER TABLE document_division_tracking DROP INDEX uq_doc_division_tracking_no');
  }

  $stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = ?
      AND table_name = 'document_division_tracking'
      AND index_name = 'uq_doc_division_tracking_scope_guard'
  ");
  $stmt->bind_param('s', $dbName);
  $stmt->execute();
  $hasScopedGuard = (int)($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
  if (!$hasScopedGuard) {
    $conn->query('ALTER TABLE document_division_tracking ADD UNIQUE INDEX uq_doc_division_tracking_scope_guard (division_id, tracking_scope, duplicate_guard_no)');
  }
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

function normalize_division_tracking_scope(?string $scope): string
{
  return strtoupper(trim((string)$scope)) === 'OUTGOING' ? 'OUTGOING' : 'INCOMING';
}

function build_division_tracking_prefix(string $divisionCode, ?string $scope = null): string
{
  $divisionCode = strtoupper(trim($divisionCode));
  $scope = normalize_division_tracking_scope($scope);
  return $scope === 'OUTGOING' ? ($divisionCode . 'OUT') : $divisionCode;
}

function build_division_tracking_number_from_parts(string $divisionCode, string $scope, string $datePart, int $sequenceNo): string
{
  return sprintf('%s %s%02d', build_division_tracking_prefix($divisionCode, $scope), $datePart, $sequenceNo);
}

function division_tracking_receipt_label(?string $scope): string
{
  return normalize_division_tracking_scope($scope) === 'OUTGOING' ? 'Created by:' : 'Received by:';
}

function division_tracking_received_datetime_label(?string $scope): string
{
  return normalize_division_tracking_scope($scope) === 'OUTGOING' ? 'Created Date and Time:' : 'Received Date and Time:';
}

function infer_division_tracking_scope(string $divisionCode, string $trackingNo): ?string
{
  $normalized = strtoupper(preg_replace('/\s+/', '', trim($trackingNo)) ?? '');
  if ($normalized === '') {
    return null;
  }

  $outgoingPrefix = build_division_tracking_prefix($divisionCode, 'OUTGOING');
  if (str_starts_with($normalized, $outgoingPrefix)) {
    return 'OUTGOING';
  }

  $incomingPrefix = build_division_tracking_prefix($divisionCode, 'INCOMING');
  if (str_starts_with($normalized, $incomingPrefix)) {
    return 'INCOMING';
  }

  return null;
}

function division_tracking_attachment_note(string $divisionCode): string
{
  return 'AUTO:DIVISION_TRACKING_SLIP:' . strtoupper(trim($divisionCode));
}

function division_tracking_page2_attachment_note(string $divisionCode): string
{
  return 'AUTO:DIVISION_TRACKING_SLIP_PAGE2:' . strtoupper(trim($divisionCode));
}

function division_tracking_attachment_note_matches(?string $note, ?string $divisionCode): bool
{
  $note = strtoupper(trim((string)$note));
  $divisionCode = strtoupper(trim((string)$divisionCode));
  if ($note === '' || $divisionCode === '') {
    return false;
  }

  if ($note === division_tracking_attachment_note($divisionCode)) {
    return true;
  }

  return $divisionCode === 'PPD' && $note === 'AUTO:PPD_TRACKING_SLIP';
}

function division_tracking_page2_attachment_note_matches(?string $note, ?string $divisionCode): bool
{
  $note = strtoupper(trim((string)$note));
  $divisionCode = strtoupper(trim((string)$divisionCode));
  if ($note === '' || $divisionCode === '') {
    return false;
  }

  return $note === division_tracking_page2_attachment_note($divisionCode);
}

function get_document_creator_name(mysqli $conn, int $documentId): string
{
  if ($documentId <= 0) return '';

  $stmt = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(u.full_name), ''), '') AS full_name
    FROM documents d
    LEFT JOIN users u ON u.id = d.created_by_user_id
    WHERE d.id = ?
    LIMIT 1");
  $stmt->bind_param('i', $documentId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();

  return trim((string)($row['full_name'] ?? ''));
}

function build_division_head_signatory_title(?array $divisionHead, string $divisionName): string
{
  $officialTitle = trim((string)($divisionHead['official_title'] ?? ''));
  $authorityRole = strtolower(trim((string)($divisionHead['authority_role'] ?? '')));

  if ($officialTitle !== '') {
    if (preg_match('/division\s+chief/i', $officialTitle)) {
      $officialTitle = preg_replace('/division\s+chief/i', 'Chief', $officialTitle) ?? $officialTitle;
    }
  } elseif ($authorityRole === 'division_head') {
    $officialTitle = 'Chief';
  }

  if ($officialTitle === '') {
    return trim($divisionName);
  }

  return $officialTitle . ($divisionName !== '' ? ', ' . $divisionName : '');
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

function get_next_division_tracking_number(mysqli $conn, int $divisionId, ?DateTimeImmutable $now = null, ?string $scope = null): string
{
  ensure_division_tracking_tables($conn);
  $meta = get_division_meta($conn, $divisionId);
  if (!$meta || !is_supported_division_tracking_code($meta['code'])) {
    throw new RuntimeException('Division tracking is not enabled for this division.');
  }
  $scope = normalize_division_tracking_scope($scope);
  $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
  $trackingDate = $now->format('Y-m-d');

  $conn->begin_transaction();
  try {
    $stmt = $conn->prepare("INSERT INTO division_tracking_sequences (division_id, tracking_date, tracking_scope, last_number)
      VALUES (?, ?, ?, 0)
      ON DUPLICATE KEY UPDATE division_id = division_id");
    $stmt->bind_param('iss', $divisionId, $trackingDate, $scope);
    $stmt->execute();

    $stmt = $conn->prepare("SELECT last_number FROM division_tracking_sequences WHERE division_id = ? AND tracking_date = ? AND tracking_scope = ? FOR UPDATE");
    $stmt->bind_param('iss', $divisionId, $trackingDate, $scope);
    $stmt->execute();
    $stmt->get_result()->fetch_assoc();
    $next = find_next_available_division_tracking_sequence($conn, $divisionId, $trackingDate, $scope);

    $stmt = $conn->prepare("UPDATE division_tracking_sequences SET last_number = ? WHERE division_id = ? AND tracking_date = ? AND tracking_scope = ?");
    $stmt->bind_param('iiss', $next, $divisionId, $trackingDate, $scope);
    $stmt->execute();
    $conn->commit();
  } catch (Throwable $e) {
    $conn->rollback();
    throw $e;
  }

  return build_division_tracking_number_from_parts((string)$meta['code'], $scope, $now->format('mdy'), $next);
}

function find_next_available_division_tracking_sequence(mysqli $conn, int $divisionId, string $trackingDate, ?string $scope = null): int
{
  $scope = normalize_division_tracking_scope($scope);
  $stmt = $conn->prepare("SELECT sequence_no
    FROM document_division_tracking
    WHERE division_id = ?
      AND tracking_date = ?
      AND tracking_scope = ?
    ORDER BY sequence_no ASC");
  $stmt->bind_param('iss', $divisionId, $trackingDate, $scope);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  $used = [];
  foreach ($rows as $row) {
    $seq = (int)($row['sequence_no'] ?? 0);
    if ($seq > 0 && $seq <= 99) {
      $used[$seq] = true;
    }
  }

  for ($seq = 1; $seq <= 99; $seq++) {
    if (empty($used[$seq])) {
      return $seq;
    }
  }

  throw new RuntimeException('All division tracking numbers for this date are already in use.');
}

function preview_next_division_tracking_number(mysqli $conn, int $divisionId, ?DateTimeImmutable $now = null, ?string $scope = null): string
{
  ensure_division_tracking_tables($conn);
  $meta = get_division_meta($conn, $divisionId);
  if (!$meta || !is_supported_division_tracking_code($meta['code'])) {
    return '';
  }
  $scope = normalize_division_tracking_scope($scope);
  $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
  $trackingDate = $now->format('Y-m-d');
  $next = find_next_available_division_tracking_sequence($conn, $divisionId, $trackingDate, $scope);
  return build_division_tracking_number_from_parts((string)$meta['code'], $scope, $now->format('mdy'), $next);
}

function parse_and_validate_division_tracking_no(
  mysqli $conn,
  int $divisionId,
  string $trackingNo,
  ?int $excludeDocumentId = null,
  bool $allowDuplicate = false,
  ?string $scope = null
): array
{
  ensure_division_tracking_tables($conn);
  $meta = get_division_meta($conn, $divisionId);
  if (!$meta || !is_supported_division_tracking_code($meta['code'])) {
    throw new RuntimeException('Division tracking is not enabled for this division.');
  }
  $trackingNo = strtoupper(trim($trackingNo));
  $candidateScopes = [];
  $normalizedScope = $scope !== null ? normalize_division_tracking_scope($scope) : null;
  if ($normalizedScope !== null) {
    $candidateScopes[] = $normalizedScope;
  } else {
    $inferredScope = infer_division_tracking_scope((string)$meta['code'], $trackingNo);
    if ($inferredScope !== null) {
      $candidateScopes[] = $inferredScope;
    }
    $candidateScopes[] = 'INCOMING';
    $candidateScopes[] = 'OUTGOING';
    $candidateScopes = array_values(array_unique($candidateScopes));
  }

  $matchedScope = null;
  $m = null;
  foreach ($candidateScopes as $candidateScope) {
    $prefix = preg_quote(build_division_tracking_prefix((string)$meta['code'], $candidateScope), '/');
    $pattern = '/^' . $prefix . '\s*(\d{6})(\d{2})$/';
    if (preg_match($pattern, $trackingNo, $matches)) {
      $matchedScope = $candidateScope;
      $m = $matches;
      break;
    }
  }

  if ($matchedScope === null || !is_array($m)) {
    throw new RuntimeException('Division tracking number must follow the format ' . $meta['code'] . ' MMDDYYNN or ' . $meta['code'] . 'OUT MMDDYYNN.');
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

  $canonicalTrackingNo = build_division_tracking_number_from_parts((string)$meta['code'], $matchedScope, $datePart, $seq);

  $sql = "SELECT document_id FROM document_division_tracking WHERE division_id = ? AND tracking_no = ?";
  $types = 'is';
  $params = [$divisionId, $canonicalTrackingNo];
  if ($excludeDocumentId !== null && $excludeDocumentId > 0) {
    $sql .= " AND document_id <> ?";
    $types .= 'i';
    $params[] = $excludeDocumentId;
  }
  $sql .= ' LIMIT 1';
  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  if (!$allowDuplicate && $stmt->get_result()->fetch_assoc()) {
    throw new RuntimeException('Division tracking number already exists for this division.');
  }

  return [
    'tracking_no' => $canonicalTrackingNo,
    'tracking_scope' => $matchedScope,
    'tracking_date' => $dt->format('Y-m-d'),
    'sequence_no' => $seq,
  ];
}

function upsert_document_division_tracking(
  mysqli $conn,
  int $documentId,
  int $divisionId,
  string $trackingNo,
  int $actorUserId,
  bool $isManual = false,
  bool $allowDuplicate = false,
  ?string $scope = null
): void
{
  $parsed = parse_and_validate_division_tracking_no($conn, $divisionId, $trackingNo, $documentId, $allowDuplicate, $scope);
  $duplicateOverride = $allowDuplicate ? 1 : 0;
  $duplicateGuardNo = $allowDuplicate ? null : (string)$parsed['tracking_no'];
  $stmt = $conn->prepare("INSERT INTO document_division_tracking
    (document_id, division_id, tracking_scope, tracking_no, duplicate_guard_no, tracking_date, sequence_no, is_manual, is_duplicate_override, created_by_user_id, updated_by_user_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      tracking_scope = VALUES(tracking_scope),
      tracking_no = VALUES(tracking_no),
      duplicate_guard_no = VALUES(duplicate_guard_no),
      tracking_date = VALUES(tracking_date),
      sequence_no = VALUES(sequence_no),
      is_manual = VALUES(is_manual),
      is_duplicate_override = VALUES(is_duplicate_override),
      updated_by_user_id = VALUES(updated_by_user_id)");
  $manual = $isManual ? 1 : 0;
  $stmt->bind_param('iissssiiiii', $documentId, $divisionId, $parsed['tracking_scope'], $parsed['tracking_no'], $duplicateGuardNo, $parsed['tracking_date'], $parsed['sequence_no'], $manual, $duplicateOverride, $actorUserId, $actorUserId);
  $stmt->execute();

  $currentLast = 0;
  $stmt = $conn->prepare('SELECT last_number FROM division_tracking_sequences WHERE division_id = ? AND tracking_date = ? AND tracking_scope = ? LIMIT 1');
  $stmt->bind_param('iss', $divisionId, $parsed['tracking_date'], $parsed['tracking_scope']);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $currentLast = (int)($row['last_number'] ?? 0);
  if ($parsed['sequence_no'] > $currentLast) {
    $stmt = $conn->prepare("INSERT INTO division_tracking_sequences (division_id, tracking_date, tracking_scope, last_number)
      VALUES (?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))");
    $stmt->bind_param('issi', $divisionId, $parsed['tracking_date'], $parsed['tracking_scope'], $parsed['sequence_no']);
    $stmt->execute();
  }
}

function delete_document_division_tracking(mysqli $conn, int $documentId, int $divisionId): void
{
  ensure_division_tracking_tables($conn);
  if ($documentId <= 0 || $divisionId <= 0) {
    return;
  }

  $stmt = $conn->prepare('DELETE FROM document_division_tracking WHERE document_id = ? AND division_id = ? LIMIT 1');
  $stmt->bind_param('ii', $documentId, $divisionId);
  $stmt->execute();
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

function find_document_by_division_tracking_no(mysqli $conn, int $divisionId, string $trackingNo, int $excludeDocumentId = 0): ?array
{
  ensure_division_tracking_tables($conn);
  $trackingNo = strtoupper(trim($trackingNo));
  if ($divisionId <= 0 || $trackingNo === '') {
    return null;
  }

  $meta = get_division_meta($conn, $divisionId);
  if ($meta) {
    try {
      $parsed = parse_and_validate_division_tracking_no($conn, $divisionId, $trackingNo, $excludeDocumentId, true);
      $trackingNo = (string)($parsed['tracking_no'] ?? $trackingNo);
    } catch (Throwable $e) {
      $trackingNo = strtoupper(preg_replace('/\s+/', '', $trackingNo) ?? $trackingNo);
    }
  }

  $sql = "
    SELECT
      d.id AS document_id,
      COALESCE(NULLIF(TRIM(d.tracking_no), ''), '') AS document_tracking_no,
      COALESCE(NULLIF(TRIM(d.subject), ''), '') AS subject
    FROM document_division_tracking ddt
    JOIN documents d ON d.id = ddt.document_id
    WHERE ddt.division_id = ?
      AND REPLACE(UPPER(TRIM(ddt.tracking_no)), ' ', '') = REPLACE(?, ' ', '')
  ";
  if ($excludeDocumentId > 0) {
    $sql .= " AND ddt.document_id <> ?";
  }
  $sql .= " LIMIT 1";

  $stmt = $conn->prepare($sql);
  if ($excludeDocumentId > 0) {
    $stmt->bind_param('isi', $divisionId, $trackingNo, $excludeDocumentId);
  } else {
    $stmt->bind_param('is', $divisionId, $trackingNo);
  }
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  return $row ?: null;
}

function get_latest_division_route_receipt_meta(mysqli $conn, int $documentId, int $divisionId): array
{
  if ($documentId <= 0 || $divisionId <= 0) {
    return ['received_by' => '', 'received_at' => ''];
  }

  $stmt = $conn->prepare("
    SELECT
      COALESCE(NULLIF(TRIM(ur.full_name), ''), COALESCE(NULLIF(TRIM(ut.full_name), ''), '')) AS received_by_name,
      r.received_at
    FROM routes r
    JOIN sections st ON st.id = r.to_section_id
    LEFT JOIN users ur ON ur.id = r.received_by_user_id
    LEFT JOIN users ut ON ut.id = r.to_user_id
    WHERE r.document_id = ?
      AND r.cancelled_at IS NULL
      AND r.received_at IS NOT NULL
      AND st.division_id = ?
    ORDER BY r.received_at DESC, r.id DESC
    LIMIT 1
  ");
  $stmt->bind_param('ii', $documentId, $divisionId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();

  return [
    'received_by' => trim((string)($row['received_by_name'] ?? '')),
    'received_at' => trim((string)($row['received_at'] ?? '')),
  ];
}

function build_division_slip_flow_rows(mysqli $conn, int $documentId, int $divisionId, string $assistantName = '', array $options = []): array
{
  if ($documentId <= 0) return [];

  $stmt = $conn->prepare("
    SELECT
      r.sent_at,
      r.received_at,
      r.remarks,
      r.personal_deadline_at,
      r.route_kind,
      COALESCE(NULLIF(TRIM(us.full_name), ''), '') AS sent_by_name,
      COALESCE(NULLIF(TRIM(ur.full_name), ''), '') AS received_by_name,
      COALESCE(NULLIF(TRIM(ut.full_name), ''), '') AS to_user_name,
      COALESCE(NULLIF(TRIM(sf.name), ''), '') AS from_section_name,
      COALESCE(NULLIF(TRIM(st.name), ''), '') AS to_section_name,
      COALESCE(sf.division_id, 0) AS from_division_id,
      COALESCE(st.division_id, 0) AS to_division_id
    FROM routes r
    LEFT JOIN users us ON us.id = r.sent_by_user_id
    LEFT JOIN users ur ON ur.id = r.received_by_user_id
    LEFT JOIN users ut ON ut.id = r.to_user_id
    LEFT JOIN sections sf ON sf.id = r.from_section_id
    LEFT JOIN sections st ON st.id = r.to_section_id
    WHERE r.document_id = ?
      AND r.cancelled_at IS NULL
    ORDER BY r.sent_at ASC, r.id ASC
    LIMIT 14
  ");
  $stmt->bind_param('i', $documentId);
  $stmt->execute();
  $routes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

  $onlyDivisionOutgoing = array_key_exists('only_division_outgoing', $options)
    ? (bool)$options['only_division_outgoing']
    : false;
  if ($onlyDivisionOutgoing) {
    $routes = array_values(array_filter($routes, static function (array $route) use ($divisionId): bool {
      return (int)($route['from_division_id'] ?? 0) === $divisionId;
    }));
  }

  $tz = new DateTimeZone('Asia/Manila');
  $formatDateTime = static function (?string $raw) use ($tz): string {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    try {
      return (new DateTime($raw, $tz))->format('m/d/y g:ia');
    } catch (Throwable) {
      return '';
    }
  };
  $formatDeadline = static function (?string $raw) use ($tz): string {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    try {
      return (new DateTime($raw, $tz))->format('m/d/y');
    } catch (Throwable) {
      return '';
    }
  };

  $buildForwardSide = static function (array $route, string $fromOverride = '') use ($formatDateTime, $formatDeadline): array {
    $fromUser = trim($fromOverride);
    if ($fromUser === '') {
      $fromUser = trim((string)($route['sent_by_name'] ?? ''));
    }
    $toUser = trim((string)($route['to_user_name'] ?? ''));
    $toSection = trim((string)($route['to_section_name'] ?? ''));
    $remarks = trim((string)($route['remarks'] ?? ''));
    if (strcasecmp($remarks, 'none') === 0) {
      $remarks = '';
    }

    return [
      'from_name' => $fromUser !== '' ? division_tracking_initials_label($fromUser) : '',
      'forwarded_datetime' => $formatDateTime($route['sent_at'] ?? ''),
      'to_name' => $toUser !== '' ? division_tracking_initials_label($toUser) : $toSection,
      'forwarded_text' => $remarks,
      'deadline' => $formatDeadline($route['personal_deadline_at'] ?? ''),
    ];
  };

  $buildReceiveSide = static function (array $route) use ($formatDateTime): array {
    $receivedName = trim((string)($route['received_by_name'] ?? ''));
    if ($receivedName === '') {
      $receivedName = trim((string)($route['to_user_name'] ?? ''));
    }
    return [
      'received_datetime' => $formatDateTime($route['received_at'] ?? ''),
      'received_name' => division_tracking_initials_label($receivedName),
    ];
  };

  $initialReceiveName = trim((string)($options['initial_receive_name'] ?? $assistantName));
  $initialReceiveDatetime = trim((string)($options['initial_receive_datetime'] ?? ''));
  $rowLimit = max(1, (int)($options['row_limit'] ?? 8));
  $rows = [];
  $routeCount = count($routes);
  for ($i = 0; $i < $routeCount; $i++) {
    $left = $i === 0
      ? [
        'received_datetime' => $formatDateTime($initialReceiveDatetime),
        'received_name' => division_tracking_initials_label($initialReceiveName),
      ]
      : $buildReceiveSide($routes[$i - 1]);

    $right = $buildForwardSide($routes[$i]);
    $rows[] = array_merge($left, $right);
  }

  if ($routeCount > 0) {
    $lastRoute = $routes[$routeCount - 1];
    if (trim((string)($lastRoute['received_at'] ?? '')) !== '') {
      $rows[] = array_merge($buildReceiveSide($lastRoute), [
        'from_name' => '',
        'forwarded_datetime' => '',
        'to_name' => '',
        'forwarded_text' => '',
        'deadline' => '',
      ]);
    }
  }

  return array_slice($rows, 0, $rowLimit);
}
