<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_once __DIR__ . "/../core/division_tracking.php";
require_once __DIR__ . "/../core/DivisionTrackingSlip.php";
require_once __DIR__ . "/../core/project_codes.php";
require_login();




function get_saved_temp_attachment(): ?array
{
  $entry = $_SESSION["add_document_temp_attachment"] ?? null;
  return is_array($entry) ? $entry : null;
}

function clear_saved_temp_attachment(): void
{
  $entry = get_saved_temp_attachment();
  if (is_array($entry)) {
    $abs = (string)($entry["temp_path"] ?? "");
    if ($abs !== '' && is_file($abs)) {
      @unlink($abs);
    }
  }

  unset($_SESSION["add_document_temp_attachment"]);
}

function stash_uploaded_attachment(array $file, int $userId): array
{
  $validated = attachment_validate_uploaded_file($file);
  $orig = (string)$validated["original_name"];
  $size = (int)$validated["size_bytes"];
  $ext = (string)$validated["extension"];
  $tmp = (string)$validated["tmp_path"];
  $realMime = (string)$validated["mime"];

  $tmpDir = temp_attachment_dir();
  $storedName = date("Ymd_His") . "_u" . $userId . "_" . bin2hex(random_bytes(6)) . "." . $ext;
  $abs = rtrim($tmpDir, "/\\") . "/" . $storedName;

  if (!move_uploaded_file($tmp, $abs)) {
    throw new RuntimeException("Failed to preserve attachment for retry");
  }

  clear_saved_temp_attachment();

  $entry = [
    "token" => bin2hex(random_bytes(16)),
    "original_name" => $orig,
    "stored_name" => $storedName,
    "temp_path" => $abs,
    "mime" => $realMime,
    "size_bytes" => $size,
    "note" => trim((string)($_POST["attach_note"] ?? "")),
    "uploaded_by_user_id" => $userId,
    "created_at" => date("c"),
  ];

  $_SESSION["add_document_temp_attachment"] = $entry;
  return $entry;
}

function move_temp_attachment_to_document(mysqli $conn, int $docId, int $userId, int $fromSectionId): void
{
  $entry = get_saved_temp_attachment();
  if (!is_array($entry)) {
    return;
  }

  $tempPath = (string)($entry["temp_path"] ?? "");
  if ($tempPath === '' || !is_file($tempPath)) {
    clear_saved_temp_attachment();
    throw new RuntimeException("Saved attachment could not be found. Please attach the file again.");
  }

  $orig = (string)($entry["original_name"] ?? "attachment");
  $mime = (string)($entry["mime"] ?? "application/octet-stream");
  $size = (int)($entry["size_bytes"] ?? 0);
  $note = trim((string)($entry["note"] ?? ""));
  $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

  $docDir = rtrim(attachments_base_dir(), "/\\") . "/doc_" . $docId;
  ensure_storage_dir($docDir);

  $storedName = date("Ymd_His") . "_u" . $userId . "_" . bin2hex(random_bytes(6)) . ($ext !== '' ? "." . $ext : '');
  $abs = $docDir . "/" . $storedName;
  if (!rename($tempPath, $abs)) {
    if (!copy($tempPath, $abs) || !unlink($tempPath)) {
      throw new RuntimeException("Failed to store attachment");
    }
  }

  $rel = "storage/attachments/doc_" . $docId . "/" . $storedName;

  $stmt = $conn->prepare("
    INSERT INTO document_attachments
      (document_id, original_name, stored_name, stored_path, mime, size_bytes, note, is_append, uploaded_by_user_id, uploaded_by_section_id)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
  ");
  $stmt->bind_param("issssisii", $docId, $orig, $storedName, $rel, $mime, $size, $note, $userId, $fromSectionId);
  $stmt->execute();

  $attachId = (int)$conn->insert_id;

  $payloadAttach = json_encode([
    "kind" => "attachment_added",
    "attachment_id" => $attachId,
    "file" => $orig,
    "is_append" => 0,
    "note" => $note,
  ], JSON_UNESCAPED_UNICODE);

  $stmt = $conn->prepare("
    INSERT INTO document_events
      (document_id, event_type, actor_user_id, actor_section_id, payload_json)
    VALUES
      (?, 'updated', ?, ?, ?)
  ");
  $stmt->bind_param("iiis", $docId, $userId, $fromSectionId, $payloadAttach);
  $stmt->execute();

  unset($_SESSION["add_document_temp_attachment"]);
}

function build_division_chief_targets(mysqli $conn, array $sections, int $myDivisionId): array
{
  $chiefCounts = [];
  $chiefRes = $conn->query("
    SELECT section_id, COUNT(*) AS chief_count
    FROM users
    WHERE is_active = 1
      AND is_chief = 1
    GROUP BY section_id
  ");
  if ($chiefRes) {
    while ($chiefRow = $chiefRes->fetch_assoc()) {
      $chiefCounts[(int)($chiefRow['section_id'] ?? 0)] = (int)($chiefRow['chief_count'] ?? 0);
    }
  }

  $grouped = [];
  foreach ($sections as $section) {
    $divisionId = (int)($section['division_id'] ?? 0);
    if ($divisionId <= 0 || $divisionId === $myDivisionId) {
      continue;
    }

    if (!isset($grouped[$divisionId])) {
      $grouped[$divisionId] = [
        'division_id' => $divisionId,
        'division_name' => (string)($section['division_name'] ?? ''),
        'candidates' => [],
      ];
    }

    $grouped[$divisionId]['candidates'][] = $section;
  }

  $targets = [];
  foreach ($grouped as $division) {
    $candidates = $division['candidates'];
    usort($candidates, static function (array $a, array $b): int {
      $an = strtolower(trim((string)($a['name'] ?? '')));
      $bn = strtolower(trim((string)($b['name'] ?? '')));

      $weight = static function (string $name): int {
        if ($name === 'director office') return 5;
        if (str_contains($name, 'office of the division chief')) return 10;
        if (str_contains($name, 'office of the director')) return 12;
        if (str_contains($name, 'division chief')) return 15;
        if (str_contains($name, 'director')) return 18;
        if (str_contains($name, 'assistant division chief')) return 20;
        return 50;
      };

      $aChiefCount = (int)($chiefCounts[(int)($a['id'] ?? 0)] ?? 0);
      $bChiefCount = (int)($chiefCounts[(int)($b['id'] ?? 0)] ?? 0);

      return [
        $weight($an),
        $aChiefCount > 0 ? 0 : 1,
        -$aChiefCount,
        $an,
      ] <=> [
        $weight($bn),
        $bChiefCount > 0 ? 0 : 1,
        -$bChiefCount,
        $bn,
      ];
    });

    $picked = $candidates[0] ?? null;
    if (!$picked) {
      continue;
    }

    $targets[] = [
      'division_id' => (int)$division['division_id'],
      'division_name' => (string)$division['division_name'],
      'section_id' => (int)($picked['id'] ?? 0),
      'section_name' => (string)($picked['name'] ?? ''),
      'label' => trim((string)$division['division_name']) . ' — ' . trim((string)($picked['name'] ?? '')),
    ];
  }

  usort($targets, static fn(array $a, array $b): int => strcasecmp((string)$a['division_name'], (string)$b['division_name']));
  return $targets;
}

function bind_params_dynamic(mysqli_stmt $stmt, string $types, array $params): void
{
  $refs = [];
  $refs[] = $types;

  foreach ($params as $k => $v) {
    $refs[] = &$params[$k];
  }

  $stmt->bind_param(...$refs);
}

function find_section_chief_id(mysqli $conn, int $sectionId): int
{
  $hasOfficialTitle = db_column_exists($conn, "users", "official_title");
  $hasAuthorityRole = db_column_exists($conn, "users", "authority_role");

  $officialTitleSql = $hasOfficialTitle ? "u.official_title" : "NULL";
  $authorityRoleSql = $hasAuthorityRole ? "u.authority_role" : "NULL";

  $stmt = $conn->prepare("
    SELECT
      u.id,
      " . $officialTitleSql . " AS official_title,
      " . $authorityRoleSql . " AS authority_role,
      u.full_name
    FROM users u
    WHERE u.section_id = ?
      AND u.is_active = 1
      AND u.is_chief = 1
    ORDER BY u.id ASC
  ");
  $stmt->bind_param("i", $sectionId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  if (!$rows) {
    return 0;
  }

  usort($rows, static function (array $a, array $b): int {
    $roleWeight = static function (string $role): int {
      return match (strtolower(trim($role))) {
        'director' => 5,
        'division_head' => 10,
        'section_head' => 15,
        'division_assistant' => 20,
        default => 50,
      };
    };

    $titleWeight = static function (string $title): int {
      $title = strtolower(trim($title));
      if ($title === '') return 50;
      if (str_contains($title, 'director')) return 5;
      if (str_contains($title, 'division chief')) return 10;
      if (str_contains($title, 'section chief')) return 15;
      if (str_contains($title, 'chief')) return 18;
      if (str_contains($title, 'head')) return 20;
      return 50;
    };

    return [
      $roleWeight((string)($a['authority_role'] ?? '')),
      $titleWeight((string)($a['official_title'] ?? '')),
      strtolower(trim((string)($a['full_name'] ?? ''))),
      (int)($a['id'] ?? 0),
    ] <=> [
      $roleWeight((string)($b['authority_role'] ?? '')),
      $titleWeight((string)($b['official_title'] ?? '')),
      strtolower(trim((string)($b['full_name'] ?? ''))),
      (int)($b['id'] ?? 0),
    ];
  });

  return (int)($rows[0]['id'] ?? 0);
}

function normalize_recipient_map_to_chiefs(mysqli $conn, array $recipientMap): array
{
  if (count($recipientMap) <= 1) {
    return $recipientMap;
  }

  $normalized = [];
  foreach (array_keys($recipientMap) as $sectionId) {
    $sectionId = (int)$sectionId;
    $chiefId = find_section_chief_id($conn, $sectionId);
    if ($chiefId <= 0) {
      throw new RuntimeException("No Section Chief configured for one of the selected sections.");
    }
    $normalized[$sectionId] = [$chiefId];
  }

  return $normalized;
}

function ensure_document_tracking_sequences_table(mysqli $conn): void
{
  $conn->query("
    CREATE TABLE IF NOT EXISTS document_tracking_sequences (
      tracking_year SMALLINT UNSIGNED NOT NULL,
      last_number INT UNSIGNED NOT NULL DEFAULT 0,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (tracking_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}

function generate_document_tracking_no(mysqli $conn, ?DateTimeImmutable $now = null): string
{
  ensure_document_tracking_sequences_table($conn);

  $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
  $year = (int)$now->format('Y');

  $stmt = $conn->prepare("
    INSERT INTO document_tracking_sequences (tracking_year, last_number)
    VALUES (?, 0)
    ON DUPLICATE KEY UPDATE tracking_year = tracking_year
  ");
  $stmt->bind_param('i', $year);
  $stmt->execute();

  $stmt = $conn->prepare("
    SELECT last_number
    FROM document_tracking_sequences
    WHERE tracking_year = ?
    FOR UPDATE
  ");
  $stmt->bind_param('i', $year);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();

  if (!$row) {
    throw new RuntimeException('Failed to initialize tracking sequence.');
  }

  $nextNumber = ((int)($row['last_number'] ?? 0)) + 1;

  $stmt = $conn->prepare("
    UPDATE document_tracking_sequences
    SET last_number = ?
    WHERE tracking_year = ?
  ");
  $stmt->bind_param('ii', $nextNumber, $year);
  $stmt->execute();

  return sprintf('DOC-%04d-%05d', $year, $nextNumber);
}

$editDocumentId = (int)($_POST["edit_id"] ?? $_GET["edit_id"] ?? 0);
$editDocument = null;
$editMode = false;
$editAccessError = "";
$pageTitle = $editDocumentId > 0 ? "Edit Document" : "Add Document";
$error = "";

// ✅ Must be logged in only.
$identity = effective_document_identity($conn);
$role = (string)($identity["effective_role"] ?? ($_SESSION["role"] ?? "user"));
$roleNorm = strtolower(trim($role));
$actualUserId = (int)($identity["actual_user_id"] ?? ($_SESSION["user_id"] ?? 0));
$userId = (int)($identity["effective_user_id"] ?? ($_SESSION["user_id"] ?? 0));
$fromSectionId = (int)($identity["effective_section_id"] ?? ($_SESSION["section_id"] ?? 0));
$isChief = (bool)($identity["effective_is_chief"] ?? (((int)($_SESSION["is_chief"] ?? 0) === 1)));
$divisionName = trim((string)($identity["effective_division_name"] ?? ($_SESSION["division_name"] ?? "")));
$assistantModeEnabled = (bool)($identity["assistant_mode"] ?? false);
$actingPrincipalUserId = (int)($identity["acting_principal_user_id"] ?? 0);
$actingPrincipalName = trim((string)($identity["acting_principal_name"] ?? ''));
$actualUserFullName = trim((string)($identity["actual_full_name"] ?? ($_SESSION["full_name"] ?? '')));

// ✅ Resolve current user division and supported own-division slip metadata
$myDivisionId = 0;
$myDivisionName = "";
$myDivisionCode = "";
$hasOwnDivisionSlip = false;
$ownDivisionSlipLabel = "";
$ownDivisionTrackingPreview = "";

$myDivisionMeta = get_user_division_meta($conn, $fromSectionId);
if (is_array($myDivisionMeta)) {
  $myDivisionId = (int)($myDivisionMeta['id'] ?? 0);
  $myDivisionName = trim((string)($myDivisionMeta['name'] ?? ''));
  $myDivisionCode = strtoupper(trim((string)($myDivisionMeta['code'] ?? '')));
  $hasOwnDivisionSlip = is_supported_division_tracking_code($myDivisionCode);
  if ($hasOwnDivisionSlip) {
    $ownDivisionSlipLabel = $myDivisionCode . ' Document Tracking Slip';
    $ownDivisionTrackingPreview = preview_next_division_tracking_number($conn, $myDivisionId, new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')));
  }
}

// ✅ Load sections for dropdown
$sections = $conn->query("
  SELECT s.id, s.name, s.division_id, d.name AS division_name
  FROM sections s
  JOIN divisions d ON d.id = s.division_id
  WHERE s.is_active = 1 AND d.is_active = 1
  ORDER BY d.name ASC, s.name ASC
")->fetch_all(MYSQLI_ASSOC);

// For JS / labels
$divisionChiefTargets = build_division_chief_targets($conn, $sections, $myDivisionId);
$sectionLabelMap = [];
$sectionMetaMap = [];
$destinationBuilderDivisions = [];
$destinationUsersBySection = [];

$userRes = $conn->query("
  SELECT id, full_name, is_chief, section_id
  FROM users
  WHERE is_active = 1
  ORDER BY is_chief DESC, full_name ASC
");
if ($userRes) {
  while ($userRow = $userRes->fetch_assoc()) {
    $sid = (int)($userRow['section_id'] ?? 0);
    if ($sid <= 0) continue;
    if (!isset($destinationUsersBySection[$sid])) {
      $destinationUsersBySection[$sid] = [];
    }
    $destinationUsersBySection[$sid][] = [
      'id' => (int)($userRow['id'] ?? 0),
      'name' => (string)($userRow['full_name'] ?? ''),
      'is_chief' => ((int)($userRow['is_chief'] ?? 0) === 1),
    ];
  }
}

foreach ($sections as $s) {
  $sid = (int)$s["id"];
  $divisionId = (int)($s['division_id'] ?? 0);
  $division = (string)($s["division_name"] ?? "");
  $section = (string)($s["name"] ?? "");
  $label = $division . " — " . $section;
  $sectionLabelMap[$sid] = $label;
  $sectionMetaMap[$sid] = [
    "division_name" => $division,
    "section_name" => $section,
    "label" => $label,
  ];

  if (!isset($destinationBuilderDivisions[$divisionId])) {
    $destinationBuilderDivisions[$divisionId] = [
      'division_id' => $divisionId,
      'division_name' => $division,
      'sections' => [],
    ];
  }

  $destinationBuilderDivisions[$divisionId]['sections'][] = [
    'id' => $sid,
    'name' => $section,
    'label' => $label,
    'users' => $destinationUsersBySection[$sid] ?? [],
  ];
}

$destinationBuilderDivisions = array_values($destinationBuilderDivisions);
usort($destinationBuilderDivisions, static fn(array $a, array $b): int => strcasecmp((string)($a['division_name'] ?? ''), (string)($b['division_name'] ?? '')));
foreach ($destinationBuilderDivisions as &$divisionBlock) {
  usort($divisionBlock['sections'], static fn(array $a, array $b): int => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
}
unset($divisionBlock);

// Default date
$phNow = new DateTime("now", new DateTimeZone("Asia/Manila"));
$defaultDocDate = $phNow->format("Y-m-d");

function normalize_deadline_input(?string $raw): ?string
{
  $raw = trim((string)$raw);
  if ($raw === "") {
    return null;
  }

  $tz = new DateTimeZone("Asia/Manila");
  $dt = DateTime::createFromFormat("!Y-m-d", $raw, $tz);
  if ($dt) {
    $errors = DateTime::getLastErrors();
    if ($errors === false || ((int)$errors["warning_count"] === 0 && (int)$errors["error_count"] === 0)) {
      return $dt->setTime(23, 59, 59)->format("Y-m-d H:i:s");
    }
  }

  $dt = DateTime::createFromFormat("Y-m-d\TH:i", $raw, $tz);
  if (!$dt) {
    return null;
  }
  $errors = DateTime::getLastErrors();
  if ($errors !== false && ((int)$errors["warning_count"] > 0 || (int)$errors["error_count"] > 0)) {
    return null;
  }

  return $dt->setTime(23, 59, 59)->format("Y-m-d H:i:s");
}

function format_optional_slip_received_datetime(?string $raw): ?string
{
  $raw = trim((string)$raw);
  if ($raw === "") {
    return "";
  }

  $tz = new DateTimeZone("Asia/Manila");
  $dt = DateTime::createFromFormat("Y-m-d\TH:i", $raw, $tz);
  if (!$dt) {
    return null;
  }

  $errors = DateTime::getLastErrors();
  if ($errors !== false && ((int)$errors["warning_count"] > 0 || (int)$errors["error_count"] > 0)) {
    return null;
  }

  return $dt->format("m/d/y g:ia");
}

if ($editDocumentId > 0) {
  $stmt = $conn->prepare("
    SELECT
      d.id,
      d.tracking_no,
      d.requester,
      d.document_date,
      d.deadline_at,
      d.subject,
      d.content_type,
      d.comm_type,
      d.current_status,
      d.origin_section_id,
      d.current_holder_section_id,
      d.created_by_user_id,
      COALESCE(ddt.tracking_no, '') AS division_tracking_no,
      (
        SELECT COUNT(*)
        FROM routes r
        WHERE r.document_id = d.id
      ) AS route_count,
      (
        SELECT COUNT(*)
        FROM document_branches b
        WHERE b.document_id = d.id
      ) AS branch_count
    FROM documents d
    LEFT JOIN document_division_tracking ddt
      ON ddt.document_id = d.id
     AND ddt.division_id = ?
    WHERE d.id = ?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $myDivisionId, $editDocumentId);
  $stmt->execute();
  $editDocument = $stmt->get_result()->fetch_assoc() ?: null;

  if (!$editDocument) {
    $editAccessError = "Document not found.";
  } else {
    $createdBy = (int)($editDocument["created_by_user_id"] ?? 0);
    $routeCount = (int)($editDocument["route_count"] ?? 0);
    $branchCount = (int)($editDocument["branch_count"] ?? 0);
    $status = strtoupper((string)($editDocument["current_status"] ?? ""));
    $canEditOwner = ($roleNorm === "admin" || $createdBy === $userId);

    if (!$canEditOwner) {
      $editAccessError = "Only the creator or an admin can edit this document.";
    } elseif ($status !== "ACTIVE") {
      $editAccessError = "Only active documents can be edited.";
    } elseif ($routeCount > 0 || $branchCount > 0) {
      $editAccessError = "This document has already been routed, so its details are locked.";
    } else {
      $editMode = true;
    }
  }

  if ($editAccessError !== "" && $error === "") {
    $error = $editAccessError;
  }
}

if ($editMode && $_SERVER["REQUEST_METHOD"] !== "POST") {
  $_POST["requester"] = (string)($editDocument["requester"] ?? "");
  $_POST["document_date"] = (string)($editDocument["document_date"] ?? $defaultDocDate);
  $_POST["deadline_at"] = trim((string)($editDocument["deadline_at"] ?? "")) !== ""
    ? date("Y-m-d", strtotime((string)$editDocument["deadline_at"]))
    : "";
  $_POST["subject"] = (string)($editDocument["subject"] ?? "");
  $_POST["content_type"] = (string)($editDocument["content_type"] ?? "");
  $_POST["comm_type"] = (string)($editDocument["comm_type"] ?? "internal");
  $_POST["division_tracking_no"] = (string)($editDocument["division_tracking_no"] ?? "");
  if (project_codes_tables_ready($conn)) {
    $editProjects = fetch_document_projects($conn, $editDocumentId, true);
    $_POST["project_ids"] = array_map(static fn(array $row): int => (int)($row["id"] ?? 0), $editProjects);
    $_POST["project_codes_input"] = implode("\n", array_values(array_filter(array_map(
      static fn(array $row): string => trim((string)($row["project_code"] ?? "")),
      $editProjects
    ))));
  }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $error === "") {
  if (($_POST["remove_saved_attachment"] ?? "") === "1") {
    clear_saved_temp_attachment();
  }

  $requester     = trim((string)($_POST["requester"] ?? ""));
  $document_date = trim((string)($_POST["document_date"] ?? ""));
  $subject       = trim((string)($_POST["subject"] ?? ""));
  $content_type  = trim((string)($_POST["content_type"] ?? ""));
  $content_type_other = trim((string)($_POST["content_type_other"] ?? ""));
  if (strcasecmp($content_type, "Others") === 0) {
    $content_type = $content_type_other;
  }
  $comm_type     = trim((string)($_POST["comm_type"] ?? "internal"));
  $deadlineAtRaw = trim((string)($_POST["deadline_at"] ?? ""));
  $deadlineAt    = normalize_deadline_input($deadlineAtRaw);
  $remarks       = trim((string)($_POST["remarks"] ?? ""));
  $projectIdsRaw = $_POST["project_ids"] ?? [];
  if (!is_array($projectIdsRaw)) {
    $projectIdsRaw = $projectIdsRaw === '' ? [] : explode(',', (string)$projectIdsRaw);
  }
  $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIdsRaw), static fn(int $v): bool => $v > 0)));
  $projectCodesInputRaw = trim((string)($_POST["project_codes_input"] ?? ""));
  $projectCodes = parse_project_codes_input($projectCodesInputRaw);
  if ($projectCodesInputRaw !== '' && $projectCodes === []) {
    $error = "Please enter a valid project code.";
  }
  if (strcasecmp($remarks, "none") === 0) {
    $remarks = "";
  }
  $creationMode = strtolower(trim((string)($_POST["creation_mode_choice"] ?? $_POST["creation_mode"] ?? "route_now")));
  if (!in_array($creationMode, ["review", "route_now"], true)) {
    $creationMode = "route_now";
  }
  if ($editMode) {
    $creationMode = "review";
  }
  $routeOnCreate = ($creationMode === "route_now");
  $selectedSectionId = (int)($_POST["to_section_id"] ?? 0); // picker only

  $fileErrorCode = (int)($_FILES["attach_file"]["error"] ?? UPLOAD_ERR_NO_FILE);
  if (!$editMode && $fileErrorCode === UPLOAD_ERR_OK) {
    try {
      stash_uploaded_attachment($_FILES["attach_file"], $userId);
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  } elseif (!$editMode && $fileErrorCode !== UPLOAD_ERR_NO_FILE) {
    $error = attachment_upload_error_message($fileErrorCode);
  } elseif (!$editMode && get_saved_temp_attachment() !== null) {
    $saved = get_saved_temp_attachment();
    if (is_array($saved)) {
      $saved["note"] = trim((string)($_POST["attach_note"] ?? ""));
      $_SESSION["add_document_temp_attachment"] = $saved;
    }
  }

  // ✅ Destination mode map
  $destinationModeRaw = $_POST["destination_mode"] ?? [];
  if (!is_array($destinationModeRaw)) {
    $destinationModeRaw = [];
  }

  $destinationModeMap = []; // [sectionId => "chief" | "users"]
  foreach ($destinationModeRaw as $sectionIdRaw => $modeRaw) {
    $sectionId = (int)$sectionIdRaw;
    $mode = strtolower(trim((string)$modeRaw));
    if ($sectionId <= 0) continue;
    $destinationModeMap[$sectionId] = ($mode === "users") ? "users" : "chief";
  }

  // ✅ Multi-section recipients
  $recipientMapRaw = $_POST["recipient_map"] ?? [];
  if (!is_array($recipientMapRaw)) {
    $recipientMapRaw = [];
  }

  $recipientMap = []; // [sectionId => [userId, userId]]
  foreach ($recipientMapRaw as $sectionIdRaw => $userIdsRaw) {
    $sectionId = (int)$sectionIdRaw;
    if ($sectionId <= 0 || !is_array($userIdsRaw)) {
      continue;
    }

    $cleanUserIds = array_values(array_unique(array_filter(array_map(
      static fn($v) => (int)$v,
      $userIdsRaw
    ), static fn($n) => $n > 0)));

    if (count($cleanUserIds) > 0) {
      $recipientMap[$sectionId] = $cleanUserIds;
    }
  }

  $personalDeadlineMapRaw = $_POST["personal_deadline_map"] ?? [];
  if (!is_array($personalDeadlineMapRaw)) {
    $personalDeadlineMapRaw = [];
  }

  $hasSeededDestinations = ($destinationModeMap !== [] || $recipientMap !== []);
  $builderContractEnabled = ((string)($_POST["destination_builder_contract"] ?? "") === "1");

  $personalDeadlineMap = [];
  foreach ($personalDeadlineMapRaw as $sectionIdRaw => $deadlineRaw) {
    $sectionId = (int)$sectionIdRaw;
    if ($sectionId <= 0) continue;

    $deadlineRaw = trim((string)$deadlineRaw);
    if ($deadlineRaw === '') continue;

    $normalizedPersonalDeadline = normalize_deadline_input($deadlineRaw);
    if ($normalizedPersonalDeadline === null) {
      $error = "One of the personal deadlines is invalid.";
      break;
    }

    $personalDeadlineMap[$sectionId] = $normalizedPersonalDeadline;
  }

  if (!$isChief && $personalDeadlineMap !== []) {
    $error = "Only section chiefs can set personal deadlines.";
  }

  // ✅ One batch id for the initial send
  $sendBatchId = bin2hex(random_bytes(16));

  // Generator choice
  $genChoice = (string)($_POST['gen_choice'] ?? 'none');
  $allowedGenChoices = ['none', 'transmittal'];
  if ($hasOwnDivisionSlip) {
    $allowedGenChoices[] = 'division_slip';
  }
  if (!in_array($genChoice, $allowedGenChoices, true)) {
    $genChoice = 'none';
  }

  $transmittalMode = (string)($_POST['transmittal_mode'] ?? 'attach');
  $divisionSlipMode = (string)($_POST['division_slip_mode'] ?? 'attach');
  $divisionTrackingInput = trim((string)($_POST['division_tracking_no'] ?? ''));
  $forceDuplicateDivisionTracking = ((string)($_POST['force_duplicate_division_tracking'] ?? '') === '1');
  $divisionSlipReceivedRaw = trim((string)($_POST['division_slip_received_datetime'] ?? ''));
  $divisionSlipReceivedDatetime = format_optional_slip_received_datetime($divisionSlipReceivedRaw);
  if ($divisionTrackingInput === '' && $hasOwnDivisionSlip) {
    $divisionTrackingInput = $ownDivisionTrackingPreview;
  }

  if (strcasecmp((string)($_POST["content_type"] ?? ""), "Others") === 0 && $content_type_other === "") {
    $error = "Please specify the document type when Others is selected.";
  } elseif ($requester === "" || $document_date === "" || $subject === "" || $content_type === "") {
    $error = "Please fill in all required fields.";
  } elseif ($routeOnCreate && $builderContractEnabled && !$hasSeededDestinations) {
    $error = "Please add at least one destination to the list.";
  } elseif ($routeOnCreate && !$builderContractEnabled && !$hasSeededDestinations && $selectedSectionId <= 0) {
    $error = "Please add at least one destination.";
  } elseif ($fromSectionId <= 0) {
    $error = "Your account has no section assigned. Ask admin to set your section_id.";
  } elseif ($deadlineAtRaw !== "" && $deadlineAt === null) {
    $error = "Deadline must be a valid date.";
  } elseif ($genChoice === "division_slip" && $divisionSlipReceivedDatetime === null) {
    $error = "Division tracking slip received date and time is invalid.";
  } elseif (!$routeOnCreate && $genChoice === "transmittal") {
    $error = "Transmittal Memo needs a destination. Choose Save and route now, or generate a division tracking slip instead.";
  } else {
    if ($editMode) {
      $txStarted = false;
      $txCommitted = false;

      try {
        $conn->begin_transaction();
        $txStarted = true;

        $stmtLock = $conn->prepare("
          SELECT
            d.created_by_user_id,
            d.current_status,
            (
              SELECT COUNT(*)
              FROM routes r
              WHERE r.document_id = d.id
            ) AS route_count,
            (
              SELECT COUNT(*)
              FROM document_branches b
              WHERE b.document_id = d.id
            ) AS branch_count
          FROM documents d
          WHERE d.id = ?
          FOR UPDATE
        ");
        $stmtLock->bind_param("i", $editDocumentId);
        $stmtLock->execute();
        $lockRow = $stmtLock->get_result()->fetch_assoc();
        if (!$lockRow) {
          throw new RuntimeException("Document not found.");
        }

        $createdBy = (int)($lockRow["created_by_user_id"] ?? 0);
        $canEditOwner = ($roleNorm === "admin" || $createdBy === $userId);
        if (!$canEditOwner) {
          throw new RuntimeException("Only the creator or an admin can edit this document.");
        }
        if (strtoupper((string)($lockRow["current_status"] ?? "")) !== "ACTIVE") {
          throw new RuntimeException("Only active documents can be edited.");
        }
        if ((int)($lockRow["route_count"] ?? 0) > 0 || (int)($lockRow["branch_count"] ?? 0) > 0) {
          throw new RuntimeException("This document has already been routed, so its details are locked.");
        }

        $stmt = $conn->prepare("
          UPDATE documents
          SET requester = ?,
              document_date = ?,
              deadline_at = ?,
              subject = ?,
              content_type = ?,
              comm_type = ?,
              updated_at = NOW()
          WHERE id = ?
          LIMIT 1
        ");
        $stmt->bind_param(
          "ssssssi",
          $requester,
          $document_date,
          $deadlineAt,
          $subject,
          $content_type,
          $comm_type,
          $editDocumentId
        );
        $stmt->execute();

        if ($hasOwnDivisionSlip && $myDivisionId > 0 && $divisionTrackingInput !== "") {
          upsert_document_division_tracking(
            $conn,
            $editDocumentId,
            $myDivisionId,
            $divisionTrackingInput,
            $userId,
            strtoupper(trim($divisionTrackingInput)) !== strtoupper(trim((string)($editDocument["division_tracking_no"] ?? ""))),
            $forceDuplicateDivisionTracking
          );
        }
        $resolvedProjectIds = resolve_project_ids_for_document($conn, $projectIds, $projectCodes);
        sync_document_projects($conn, $editDocumentId, $resolvedProjectIds, $userId);

        $payloadUpdated = json_encode([
          "kind" => "document_details_updated",
          "tracking_no" => (string)($editDocument["tracking_no"] ?? ""),
          "subject" => $subject,
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $conn->prepare("
          INSERT INTO document_events
            (document_id, event_type, actor_user_id, actor_section_id, payload_json)
          VALUES (?, 'updated', ?, ?, ?)
        ");
        $stmt->bind_param("iiis", $editDocumentId, $userId, $fromSectionId, $payloadUpdated);
        $stmt->execute();

        $conn->commit();
        $txCommitted = true;

        $_SESSION["documents_created_flash"] = [
          "doc_id" => $editDocumentId,
          "tracking_no" => (string)($editDocument["tracking_no"] ?? ""),
          "message" => "Document details updated.",
          "created_at" => time(),
        ];

        $documentsRedirect = PUBLIC_PATH . "/documents.php?sort=newest&page=1";
        if ($assistantModeEnabled && $actingPrincipalUserId > 0) {
          $documentsRedirect .= "&view=assistant&acting_principal_user_id=" . $actingPrincipalUserId;
        }
        redirect($documentsRedirect);
      } catch (Throwable $e) {
        try {
          if ($txStarted && !$txCommitted && isset($conn) && $conn instanceof mysqli && @$conn->ping()) {
            $conn->rollback();
          }
        } catch (Throwable $rollbackError) {
          // Ignore rollback failure
        }

        $error = "Failed to update document: " . $e->getMessage();
      }
    } else {
    // Build final recipient map from destination modes.
    $finalRecipientMap = [];

    if ($routeOnCreate) {
      // Legacy fallback only when JS builder contract is not active.
      if (!$builderContractEnabled && count($destinationModeMap) === 0) {
        $chiefId = find_section_chief_id($conn, $selectedSectionId);
        if ($chiefId <= 0) {
          $error = "No Section Chief configured for the selected section.";
        } else {
          $finalRecipientMap[$selectedSectionId] = [$chiefId];
          $destinationModeMap[$selectedSectionId] = "chief";
        }
      }

      if ($error === "") {
        foreach ($destinationModeMap as $sectionId => $mode) {
          $sectionId = (int)$sectionId;
          if ($sectionId <= 0) continue;

          if ($mode === "users") {
            $selectedUsers = $recipientMap[$sectionId] ?? [];
            if (count($selectedUsers) > 0) {
              $finalRecipientMap[$sectionId] = array_values($selectedUsers);
            } else {
              $chiefId = find_section_chief_id($conn, $sectionId);
              if ($chiefId <= 0) {
                $error = "No Section Chief configured for one of the selected sections.";
                break;
              }
              $finalRecipientMap[$sectionId] = [$chiefId];
            }
          } else {
            $chiefId = find_section_chief_id($conn, $sectionId);
            if ($chiefId <= 0) {
              $error = "No Section Chief configured for one of the selected sections.";
              break;
            }
            $finalRecipientMap[$sectionId] = [$chiefId];
          }
        }
      }
    }

    if ($routeOnCreate && $error === "" && count($finalRecipientMap) === 0) {
      $error = "Please add at least one destination.";
    }

    if ($error === "") {
      $txStarted = false;
      $txCommitted = false;

      try {
        $conn->begin_transaction();
        $txStarted = true;

        $tracking_no = generate_document_tracking_no($conn);

        // 1) documents
        $stmt = $conn->prepare("
          INSERT INTO documents (
            tracking_no, requester, document_date, deadline_at, subject, content_type, comm_type,
            current_status,
            origin_section_id, current_holder_section_id,
            created_by_user_id
          )
          VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVE', ?, ?, ?)
        ");
        $stmt->bind_param(
          "sssssssiii",
          $tracking_no,
          $requester,
          $document_date,
          $deadlineAt,
          $subject,
          $content_type,
          $comm_type,
          $fromSectionId,
          $fromSectionId,
          $userId
        );
        $stmt->execute();
        $docId = (int)$conn->insert_id;

        if ($hasOwnDivisionSlip && $myDivisionId > 0) {
          upsert_document_division_tracking(
            $conn,
            $docId,
            $myDivisionId,
            $divisionTrackingInput,
            $userId,
            strtoupper(trim($divisionTrackingInput)) !== strtoupper(trim($ownDivisionTrackingPreview)),
            $forceDuplicateDivisionTracking
          );
        }
        $resolvedProjectIds = resolve_project_ids_for_document($conn, $projectIds, $projectCodes);
        sync_document_projects($conn, $docId, $resolvedProjectIds, $userId);

        $branchMode = workflow_branch_mode_enabled($conn);

        $totalActionableRecipients = 0;
        foreach ($finalRecipientMap as $tmpUserIds) {
          $totalActionableRecipients += count($tmpUserIds);
        }

        $useBranchModeForThisDocument = ($branchMode && $totalActionableRecipients > 1);

        if ($branchMode) {
          workflow_grant_visibility($conn, $docId, $userId, 'CREATOR', null, $userId);
        }

        // 2) participants: origin
        $stmt = $conn->prepare("
          INSERT IGNORE INTO document_participants
            (document_id, section_id, added_via, added_by_user_id)
          VALUES (?, ?, 'origin', ?)
        ");
        $stmt->bind_param("iii", $docId, $fromSectionId, $userId);
        $stmt->execute();

        // 3) participants: every recipient section can SEE, but only when
        // creation immediately routes the document.
        if ($routeOnCreate) {
          $stmt = $conn->prepare("
            INSERT IGNORE INTO document_participants
              (document_id, section_id, added_via, added_by_user_id)
            VALUES (?, ?, 'movement', ?)
          ");
          foreach (array_keys($finalRecipientMap) as $destSectionId) {
            $destSectionId = (int)$destSectionId;
            $stmt->bind_param("iii", $docId, $destSectionId, $userId);
            $stmt->execute();
          }
        }

        // 3.5) Validate selected users per destination section
        $validatedRecipients = [];
        if ($routeOnCreate) {
          foreach ($finalRecipientMap as $destSectionId => $destUserIds) {
            $destSectionId = (int)$destSectionId;
            if (count($destUserIds) === 0) {
              throw new RuntimeException("Recipient list cannot be empty for a selected section.");
            }

            $placeholders = implode(",", array_fill(0, count($destUserIds), "?"));
            $types = "i" . str_repeat("i", count($destUserIds));
            $params = array_merge([$destSectionId], array_values($destUserIds));

            $sql = "
              SELECT id, full_name
              FROM users
              WHERE section_id = ?
                AND is_active = 1
                AND id IN ($placeholders)
            ";

            $stmt = $conn->prepare($sql);
            bind_params_dynamic($stmt, $types, $params);
            $stmt->execute();
            $res = $stmt->get_result();

            $found = [];
            $validatedRecipients[$destSectionId] = [];
            while ($r = $res->fetch_assoc()) {
              $rid = (int)$r["id"];
              $found[] = $rid;
              $validatedRecipients[$destSectionId][$rid] = [
                "id" => $rid,
                "full_name" => (string)($r["full_name"] ?? "User #" . $rid),
              ];
            }

            sort($found);
            $expected = array_values($destUserIds);
            sort($expected);

            if ($found !== $expected) {
              throw new RuntimeException("One or more selected users are invalid/inactive for their section.");
            }
          }
        }

        // 4) routes: multi-section per-user routing
        $routeBranchMap = [];
        $createdBranchIds = [];

        $rootBranchId = 0;
        if ($routeOnCreate && $useBranchModeForThisDocument) {
          $rootBranchId = workflow_create_branch($conn, [
            'document_id' => $docId,
            'parent_branch_id' => null,
            'branch_label' => 'Origin',
            'current_assignee_user_id' => null,
            'current_assignee_section_id' => null,
            'branch_status' => 'COMPLETED',
            'is_reference' => 0,
            'created_by_user_id' => $userId,
          ]);
        }

        if ($routeOnCreate && $useBranchModeForThisDocument) {
          $stmt = $conn->prepare("
            INSERT INTO routes
              (document_id, branch_id, from_section_id, to_section_id, from_user_id, to_user_id, route_kind, send_batch_id, received_at, sent_by_user_id, remarks, personal_deadline_at)
            VALUES
              (?, ?, ?, ?, ?, ?, 'ACTION', ?, NULL, ?, ?, ?)
          ");
        } else {
          $stmt = $conn->prepare("
            INSERT INTO routes
              (document_id, from_section_id, to_section_id, to_user_id, send_batch_id, received_at, sent_by_user_id, remarks, personal_deadline_at)
            VALUES
              (?, ?, ?, ?, ?, NULL, ?, ?, ?)
          ");
        }

        if ($routeOnCreate) {
          foreach ($finalRecipientMap as $destSectionId => $destUserIds) {
            $destSectionId = (int)$destSectionId;
            $destPersonalDeadlineAt = $personalDeadlineMap[$destSectionId] ?? null;

            foreach ($destUserIds as $rid) {
              $rid = (int)$rid;

              if ($useBranchModeForThisDocument) {
                $labelUser = (string)($validatedRecipients[$destSectionId][$rid]['full_name'] ?? ('User #' . $rid));
                $branchId = workflow_create_branch($conn, [
                  'document_id' => $docId,
                  'parent_branch_id' => $rootBranchId > 0 ? $rootBranchId : null,
                  'branch_label' => $labelUser,
                  'current_assignee_user_id' => $rid,
                  'current_assignee_section_id' => $destSectionId,
                  'branch_status' => 'ACTIVE',
                  'is_reference' => 0,
                  'created_by_user_id' => $userId,
                ]);

                $createdBranchIds[] = $branchId;
                $routeBranchMap[] = [
                  'branch_id' => $branchId,
                  'to_user_id' => $rid,
                  'to_section_id' => $destSectionId,
                ];

                workflow_grant_visibility($conn, $docId, $rid, 'PARTICIPANT', $branchId, $userId);

                $stmt->bind_param("iiiiiisiss", $docId, $branchId, $fromSectionId, $destSectionId, $userId, $rid, $sendBatchId, $userId, $remarks, $destPersonalDeadlineAt);
              } else {
                workflow_grant_visibility($conn, $docId, $rid, 'PARTICIPANT', null, $userId);
                $stmt->bind_param("iiiisiss", $docId, $fromSectionId, $destSectionId, $rid, $sendBatchId, $userId, $remarks, $destPersonalDeadlineAt);
              }

              $stmt->execute();
            }
          }
        }

        // 5) events: created + sent
        $payloadCreated = json_encode([
          "tracking_no" => $tracking_no,
          "subject" => $subject,
          "creation_mode" => $routeOnCreate ? "route_now" : "principal_review",
          "remarks" => $remarks,
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $conn->prepare("
          INSERT INTO document_events
            (document_id, event_type, actor_user_id, actor_section_id, payload_json)
          VALUES (?, 'created', ?, ?, ?)
        ");
        $stmt->bind_param("iiis", $docId, $userId, $fromSectionId, $payloadCreated);
        $stmt->execute();

        if ($routeOnCreate) {
          $toSectionIds = array_values(array_unique(array_filter(array_map('intval', array_keys($finalRecipientMap)))));
          $toSectionNames = [];
          if ($toSectionIds !== []) {
            $sectionPlaceholders = implode(',', array_fill(0, count($toSectionIds), '?'));
            $sectionTypes = str_repeat('i', count($toSectionIds));
            $stmtToSections = $conn->prepare("SELECT id, name FROM sections WHERE id IN ($sectionPlaceholders)");
            $stmtToSections->bind_param($sectionTypes, ...$toSectionIds);
            $stmtToSections->execute();
            $toSectionRows = $stmtToSections->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($toSectionRows as $toSectionRow) {
              $toSectionNames[] = (string)($toSectionRow['name'] ?? '');
            }
          }

          $payloadSent = json_encode([
            "remarks" => $remarks,
            "recipient_map" => $finalRecipientMap,
            "destination_mode_map" => $destinationModeMap,
            "send_batch_id" => $sendBatchId,
            "branch_mode" => $useBranchModeForThisDocument,
            "branch_ids" => array_values(array_unique(array_filter($createdBranchIds))),
            "route_branch_map" => $routeBranchMap,
            "personal_deadline_map" => $personalDeadlineMap,
            "from_section_id" => $fromSectionId,
            "from_section_name" => $fromSectionName,
            "to_section_ids" => $toSectionIds,
            "to_section_names" => $toSectionNames,
          ], JSON_UNESCAPED_UNICODE);

          $stmt = $conn->prepare("
            INSERT INTO document_events
              (document_id, event_type, actor_user_id, actor_section_id, from_section_id, to_section_id, payload_json)
            VALUES (?, 'sent', ?, ?, ?, NULL, ?)
          ");
          $stmt->bind_param("iiiis", $docId, $userId, $fromSectionId, $fromSectionId, $payloadSent);
          $stmt->execute();
        }

        // 6) optional: attach initial file
        move_temp_attachment_to_document($conn, $docId, $userId, $fromSectionId);

        $conn->commit();
        $txCommitted = true;

        // ========= AFTER COMMIT: generate chosen PDF and attach =========
        $transAttachId = 0;
        $ppdAttachId = 0;

        if ($genChoice === "transmittal") {
          require_once __DIR__ . "/../core/TransmittalMemo.php";

          $baseDir = realpath(__DIR__ . "/../storage/attachments");
          if ($baseDir === false) {
            $baseDir = __DIR__ . "/../storage/attachments";
            if (!is_dir($baseDir)) {
              mkdir($baseDir, 0775, true);
            }
          }
          $docDir = rtrim((string)$baseDir, "/\\") . "/doc_" . $docId;
          if (!is_dir($docDir)) {
            mkdir($docDir, 0775, true);
          }

          $storedName = "TRANSMITTAL_MEMO_" . $tracking_no . ".pdf";
          $abs = $docDir . "/" . $storedName;
          $rel = "storage/attachments/doc_" . $docId . "/" . $storedName;

          $qrToken = null;
          $stmt = $conn->prepare("
            SELECT token
            FROM document_qr_tokens
            WHERE document_id = ?
              AND revoked_at IS NULL
            ORDER BY id DESC
            LIMIT 1
          ");
          $stmt->bind_param("i", $docId);
          $stmt->execute();
          $rowTok = $stmt->get_result()->fetch_assoc();

          if ($rowTok && !empty($rowTok["token"])) {
            $qrToken = (string)$rowTok["token"];
          } else {
            $qrToken = bin2hex(random_bytes(16));
            $stmt = $conn->prepare("
              INSERT INTO document_qr_tokens (document_id, token)
              VALUES (?, ?)
            ");
            $stmt->bind_param("is", $docId, $qrToken);
            $stmt->execute();
          }

          $qrUrl = app_url(PUBLIC_PATH . "/qr.php?t=" . urlencode($qrToken));

          $stmt = $conn->prepare("
            SELECT
              s.id,
              s.name AS section_name,
              d.name AS division_name
            FROM sections s
            JOIN divisions d ON d.id = s.division_id
            WHERE s.id IN (?, ?)
          ");
          $stmt->bind_param("ii", $fromSectionId, $selectedSectionId);
          $stmt->execute();
          $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

          $fromLabel = "";
          $toLabel = "";
          foreach ($rows as $r) {
            $sid = (int)$r["id"];
            $label = trim((string)$r["division_name"]) . " / " . trim((string)$r["section_name"]);
            if ($sid === $fromSectionId) $fromLabel = $label;
            if ($sid === $selectedSectionId) $toLabel = $label;
          }

          TransmittalMemo::generateA4([
            "date" => $document_date,
            "subject" => $subject,
            "qr_url" => $qrUrl,
            "logo_left_abs"  => realpath(__DIR__ . "/../assets/mpwlogo1.png") ?: "",
            "logo_right_abs" => realpath(__DIR__ . "/../assets/ocmlogo.png") ?: "",
            "from_label" => $fromLabel,
            "to_label"   => $toLabel,
            "recipients" => resolve_transmittal_recipients($conn),
            "mpw_tracking_no" => $tracking_no,
          ], $abs);

          $size = (int)@filesize($abs);
          if ($size <= 0) {
            throw new RuntimeException("Failed to generate transmittal memo PDF");
          }
          if ($size > attachment_max_bytes()) {
            @unlink($abs);
            throw new RuntimeException("Generated transmittal memo is too large (max " . attachment_max_mb_label() . ")");
          }
          if ($size > attachment_max_bytes()) {
            @unlink($abs);
            throw new RuntimeException("Generated transmittal memo is too large (max " . attachment_max_mb_label() . ")");
          }

          $orig = $storedName;
          $mime = "application/pdf";
          $note = "AUTO:TRANSMITTAL_MEMO";

          $stmt = $conn->prepare("
            INSERT INTO document_attachments
              (document_id, original_name, stored_name, stored_path, mime, size_bytes, note, is_append, uploaded_by_user_id, uploaded_by_section_id)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
          ");
          $stmt->bind_param("issssisii", $docId, $orig, $storedName, $rel, $mime, $size, $note, $userId, $fromSectionId);
          $stmt->execute();
          $transAttachId = (int)$conn->insert_id;

          $payloadTrans = json_encode([
            "kind" => "transmittal_memo_generated",
            "attachment_id" => $transAttachId,
            "file" => $orig,
          ], JSON_UNESCAPED_UNICODE);

          $stmt = $conn->prepare("
            INSERT INTO document_events
              (document_id, event_type, actor_user_id, actor_section_id, payload_json)
            VALUES
              (?, 'updated', ?, ?, ?)
          ");
          $stmt->bind_param("iiis", $docId, $userId, $fromSectionId, $payloadTrans);
          $stmt->execute();

          if ($transmittalMode === "print" && $transAttachId > 0) {
            redirect(PUBLIC_PATH . "/transmittal_print.php?id=" . $transAttachId);
          }
        }

        if ($genChoice === "division_slip" && $hasOwnDivisionSlip && $myDivisionId > 0) {
          $stmt = $conn->prepare("
            SELECT s.name AS section_name, d.name AS division_name
            FROM sections s
            JOIN divisions d ON d.id = s.division_id
            WHERE s.id = ?
            LIMIT 1
          ");
          $stmt->bind_param("i", $fromSectionId);
          $stmt->execute();
          $rFrom = $stmt->get_result()->fetch_assoc();

          $fromLabel = trim((string)$requester);

          if ($fromLabel === '') {
            $fromLabel = ($divisionName !== "") ? $divisionName : $myDivisionCode;
            if ($rFrom) {
              $divisionNameFrom = trim((string)($rFrom["division_name"] ?? ''));
              $sectionNameFrom = trim((string)($rFrom["section_name"] ?? ''));

              if ($divisionNameFrom !== '' && $sectionNameFrom !== '') {
                $fromLabel = $divisionNameFrom . " / " . $sectionNameFrom;
              } elseif ($divisionNameFrom !== '') {
                $fromLabel = $divisionNameFrom;
              } elseif ($sectionNameFrom !== '') {
                $fromLabel = $sectionNameFrom;
              }
            }
          }

          $baseDir = realpath(__DIR__ . "/../storage/attachments");
          if ($baseDir === false) {
            $baseDir = __DIR__ . "/../storage/attachments";
            if (!is_dir($baseDir)) {
              mkdir($baseDir, 0775, true);
            }
          }
          $docDir = rtrim((string)$baseDir, "/\\") . "/doc_" . $docId;
          if (!is_dir($docDir)) {
            mkdir($docDir, 0775, true);
          }

          $safeDivision = preg_replace('/[^A-Za-z0-9._-]+/', '_', $myDivisionCode) ?: 'DIVISION';
          $storedName = $safeDivision . "_TRACKING_SLIP_" . $tracking_no . ".pdf";
          $abs = $docDir . "/" . $storedName;
          $rel = "storage/attachments/doc_" . $docId . "/" . $storedName;

          $qrToken = null;
          $stmt = $conn->prepare("
            SELECT token
            FROM document_qr_tokens
            WHERE document_id = ?
              AND revoked_at IS NULL
            ORDER BY id DESC
            LIMIT 1
          ");
          $stmt->bind_param("i", $docId);
          $stmt->execute();
          $rowTok = $stmt->get_result()->fetch_assoc();

          if ($rowTok && !empty($rowTok["token"])) {
            $qrToken = (string)$rowTok["token"];
          } else {
            $qrToken = bin2hex(random_bytes(16));
            $stmt = $conn->prepare("
              INSERT INTO document_qr_tokens (document_id, token)
              VALUES (?, ?)
            ");
            $stmt->bind_param("is", $docId, $qrToken);
            $stmt->execute();
          }

          $qrUrl = app_url(PUBLIC_PATH . "/qr.php?t=" . urlencode($qrToken));
          $divisionTrackingRow = get_document_division_tracking($conn, $docId, $myDivisionId);
          $divisionSlipNo = trim((string)($divisionTrackingRow['tracking_no'] ?? $divisionTrackingInput));
          $divisionHead = resolve_division_head($conn, $myDivisionId);
          $flowRows = build_division_slip_flow_rows($conn, $docId, $myDivisionId, $actualUserFullName);
          $nameEntries = build_division_name_initial_entries($conn, $myDivisionId, (int)($divisionHead['id'] ?? 0));
          $assignedTo = build_division_slip_assigned_to_label($conn, $docId);

          DivisionTrackingSlip::generateA4([
            "division_tracking_no" => $divisionSlipNo,
            "division_name"        => $myDivisionName,
            "division_code"        => $myDivisionCode,
            "from_label"           => $fromLabel,
            "document_type"        => $content_type,
            "document_date"        => $document_date,
            "subject"              => $subject,
            "mpw_tracking_no"      => $tracking_no,
            "received_by"          => $actualUserFullName !== "" ? $actualUserFullName : trim((string)($_SESSION["full_name"] ?? "")),
            "received_datetime"    => (string)$divisionSlipReceivedDatetime,
            "assigned_to"          => $assignedTo,
            "deadline_date"        => $deadlineAt ? (new DateTime($deadlineAt, new DateTimeZone("Asia/Manila")))->format("m/d/Y") : "",
            "deadline_time"        => $deadlineAt ? (new DateTime($deadlineAt, new DateTimeZone("Asia/Manila")))->format("g:i A") : "",
            "qr_url"               => $qrUrl,
            "logo_left_abs"        => realpath(__DIR__ . "/../assets/mpwlogo1.png") ?: "",
            "logo_right_abs"       => realpath(__DIR__ . "/../assets/ocmlogo.png") ?: "",
            "signatory_name"       => (string)($divisionHead['full_name'] ?? ''),
            "signatory_title"      => 'Chief' . ($myDivisionName !== '' ? ', ' . $myDivisionName : ''),
            "flow_rows"            => $flowRows,
            "name_entries"         => $nameEntries,
          ], $abs);

          $size = (int)@filesize($abs);
          if ($size <= 0) {
            throw new RuntimeException("Failed to generate division tracking slip PDF");
          }
          if ($size > attachment_max_bytes()) {
            @unlink($abs);
            throw new RuntimeException("Generated division tracking slip is too large (max " . attachment_max_mb_label() . ")");
          }
          if ($size > attachment_max_bytes()) {
            @unlink($abs);
            throw new RuntimeException("Generated division tracking slip is too large (max " . attachment_max_mb_label() . ")");
          }

          $orig = $storedName;
          $mime = "application/pdf";
          $note = "AUTO:DIVISION_TRACKING_SLIP:" . $myDivisionCode;

          $stmt = $conn->prepare("
            INSERT INTO document_attachments
              (document_id, original_name, stored_name, stored_path, mime, size_bytes, note, is_append, uploaded_by_user_id, uploaded_by_section_id)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
          ");
          $stmt->bind_param("issssisii", $docId, $orig, $storedName, $rel, $mime, $size, $note, $userId, $fromSectionId);
          $stmt->execute();
          $divisionAttachId = (int)$conn->insert_id;

          $payloadSlip = json_encode([
            "kind" => "division_tracking_slip_generated",
            "attachment_id" => $divisionAttachId,
            "file" => $orig,
            "division_code" => $myDivisionCode,
            "received_by_name" => $actualUserFullName !== "" ? $actualUserFullName : trim((string)($_SESSION["full_name"] ?? "")),
            "received_datetime" => (string)$divisionSlipReceivedDatetime,
            "assistant_actual_user_id" => $assistantModeEnabled ? $actualUserId : null,
            "acting_principal_user_id" => ($assistantModeEnabled && $actingPrincipalUserId > 0) ? $actingPrincipalUserId : null,
            "acting_principal_name" => ($assistantModeEnabled && $actingPrincipalName !== "") ? $actingPrincipalName : "",
          ], JSON_UNESCAPED_UNICODE);

          $stmt = $conn->prepare("
            INSERT INTO document_events
              (document_id, event_type, actor_user_id, actor_section_id, payload_json)
            VALUES
              (?, 'updated', ?, ?, ?)
          ");
          $stmt->bind_param("iiis", $docId, $userId, $fromSectionId, $payloadSlip);
          $stmt->execute();

          if ($divisionSlipMode === "print" && $divisionAttachId > 0) {
            $printRedirect = PUBLIC_PATH . "/division_tracking_slip_print.php?id=" . $divisionAttachId;
            if ($assistantModeEnabled && $actingPrincipalUserId > 0) {
              $printRedirect .= "&acting_principal_user_id=" . $actingPrincipalUserId;
            }
            redirect($printRedirect);
          }
        }

        $_SESSION["documents_created_flash"] = [
          "doc_id" => $docId,
          "tracking_no" => $tracking_no,
          "created_at" => time(),
        ];

        $documentsRedirect = PUBLIC_PATH . "/documents.php?sort=newest&page=1";
        if ($assistantModeEnabled && $actingPrincipalUserId > 0) {
          $documentsRedirect .= "&view=assistant&acting_principal_user_id=" . $actingPrincipalUserId;
        }

        redirect($documentsRedirect);

      } catch (Throwable $e) {
        try {
          if ($txStarted && !$txCommitted && isset($conn) && $conn instanceof mysqli && @$conn->ping()) {
            $conn->rollback();
          }
        } catch (Throwable $rollbackError) {
          // Ignore rollback failure
        }

        $error = "Failed to add document: " . $e->getMessage();
      }
    }
    }
  }
}

$savedTempAttachment = get_saved_temp_attachment();
$postedProjectIdsRaw = $_POST["project_ids"] ?? [];
if (!is_array($postedProjectIdsRaw)) {
  $postedProjectIdsRaw = $postedProjectIdsRaw === '' ? [] : explode(',', (string)$postedProjectIdsRaw);
}
$postedProjectIds = array_values(array_unique(array_filter(array_map('intval', $postedProjectIdsRaw), static fn(int $v): bool => $v > 0)));
$postedProjectCodesInput = trim((string)($_POST["project_codes_input"] ?? ""));

$contentTypeOptions = [
  "Memorandum",
  "Endorsement",
  "Letter",
  "Memo Order",
  "Ministry Order",
  "Communication (External)",
  "Communication (Internal)",
  "Communication (External) DEO",
  "Program of Works and Plans",
  "Proposal",
  "Back to Office Report",
  "Others",
];

$postedContentType = trim((string)($_POST["content_type"] ?? ""));
$postedContentTypeOther = trim((string)($_POST["content_type_other"] ?? ""));
$contentTypeSelectedValue = $postedContentType;
if ($postedContentType !== '' && !in_array($postedContentType, $contentTypeOptions, true)) {
  $contentTypeSelectedValue = 'Others';
  if ($postedContentTypeOther === '') {
    $postedContentTypeOther = $postedContentType;
  }
}

$pageStyles = [asset_url("assets/css/add-document.css")];
$pageScripts = [asset_url("assets/js/add-document.js")];

require __DIR__ . "/../includes/layout.php";
?>

<?php if ($error): ?>
  <div class="notice" style="background:#f8d7da;border:1px solid #f5c2c7;">
    <?= htmlspecialchars($error) ?>
  </div>
<?php endif; ?>

<?php if ($editDocumentId > 0 && !$editMode): ?>
  <div class="card docFormCard addDocumentPage" style="max-width:760px;margin-top:14px;">
    <div class="docFormHead addDocHeader">
      <div>
        <div class="addDocEyebrow">Document Correction</div>
        <h2 style="margin:6px 0 0;">Details Locked</h2>
        <div class="mini addDocLead">This document can no longer be edited from intake.</div>
      </div>
    </div>
    <div class="docActions addDocActions">
      <a href="<?= PUBLIC_PATH ?>/documents.php" class="btnGhost" style="text-decoration:none;">Back to Documents</a>
    </div>
  </div>
<?php else: ?>
<div class="card docFormCard addDocumentPage" style="max-width:1040px;margin-top:14px;">
  <div class="docFormHead addDocHeader">
    <div>
      <div class="addDocEyebrow"><?= $editMode ? "Document Correction" : "Document Intake" ?></div>
      <h2 style="margin:6px 0 0;"><?= $editMode ? "Edit Document Details" : "Add New Document" ?></h2>
      <div class="mini addDocLead">
        <?= $editMode
          ? "Correct the document details before it is routed. Tracking number stays unchanged."
          : "Fill in the basic details first, then choose destinations and optional auto-generated files." ?>
      </div>
    </div>
    <div class="addDocRequiredNote">
      Fields with <b>*</b> are required
    </div>
  </div>

  <form method="POST" enctype="multipart/form-data" class="docFormGrid addDocForm" data-remove-saved-attachment-url="<?= API_PATH ?>/remove_saved_attachment.php">
    <?php if ($editMode): ?>
      <input type="hidden" name="edit_id" value="<?= (int)$editDocumentId ?>">
    <?php endif; ?>
    <?php if ($assistantModeEnabled && $actingPrincipalUserId > 0): ?>
      <input type="hidden" name="acting_principal_user_id" value="<?= (int)$actingPrincipalUserId ?>">
    <?php endif; ?>
    <input type="hidden" name="remove_saved_attachment" value="0" id="removeSavedAttachmentInput">
    <input type="hidden" name="destination_builder_contract" value="0" id="destinationBuilderContractInput">
    <?php $postedCreationMode = $editMode ? "review" : (string)($_POST["creation_mode_choice"] ?? $_POST["creation_mode"] ?? "review"); ?>
    <input type="hidden" name="creation_mode" value="<?= htmlspecialchars($postedCreationMode) ?>" id="creationModeInput">

    <section class="addDocSection addDocSection-basic span2">
      <div class="addDocSectionHead">
        <div>
          <h3>Basic Information</h3>
          <p>Core document details used across routing, tracking, and generated files.</p>
        </div>
      </div>

      <div class="addDocSectionGrid addDocBasicGrid">
        <div class="authField">
          <label>Requester <span class="req">*</span></label>
          <input
            type="text"
            name="requester"
            required
            placeholder="Name of requester"
            value="<?= htmlspecialchars($_POST["requester"] ?? "") ?>"
          >
        </div>

        <div class="authField">
          <label>Document Date <span class="req">*</span></label>
          <input
            type="date"
            name="document_date"
            required
            value="<?= htmlspecialchars($_POST["document_date"] ?? $defaultDocDate) ?>"
          >
        </div>

        <div class="authField">
          <label>Deadline</label>
          <input
            type="date"
            name="deadline_at"
            value="<?= htmlspecialchars($_POST["deadline_at"] ?? "") ?>"
          >
          <div class="mini">Optional. Deadlines stay active until 11:59 PM of the selected date.</div>
        </div>

        <div class="authField addDocFieldWide">
          <label>Subject <span class="req">*</span></label>
          <input
            type="text"
            name="subject"
            required
            placeholder="Short subject / title"
            value="<?= htmlspecialchars($_POST["subject"] ?? "") ?>"
          >
        </div>

        <div class="authField authFieldStacked" id="contentTypeField">
          <label>Document Type <span class="req">*</span></label>
          <select name="content_type" id="contentTypeSelect" class="select" required>
            <option value="">-- Please Select Document Type --</option>
            <?php foreach ($contentTypeOptions as $typeOption): ?>
              <option value="<?= htmlspecialchars($typeOption) ?>" <?= ($contentTypeSelectedValue === $typeOption) ? "selected" : "" ?>><?= htmlspecialchars($typeOption) ?></option>
            <?php endforeach; ?>
          </select>

          <div class="addDocConditionalField" id="contentTypeOtherWrap">
            <label for="contentTypeOtherInput">Please Specify <span class="req">*</span></label>
            <input
              type="text"
              name="content_type_other"
              id="contentTypeOtherInput"
              placeholder="Enter document type"
              value="<?= htmlspecialchars($postedContentTypeOther) ?>"
            >
            <div class="mini">Use this only when the document type is not in the list above.</div>
          </div>
        </div>

        <div class="authField">
          <label>Communication Type <span class="req">*</span></label>
          <select name="comm_type" class="select" required>
            <option value="internal" <?= (($_POST["comm_type"] ?? "internal") === "internal") ? "selected" : "" ?>>Internal</option>
            <option value="external" <?= (($_POST["comm_type"] ?? "") === "external") ? "selected" : "" ?>>External</option>
          </select>
        </div>

        <div class="authField addDocFieldWide">
          <label>Project Codes <span class="mini" style="font-weight:700;">(optional)</span></label>
          <textarea
            name="project_codes_input"
            class="search"
            rows="4"
            placeholder="Enter one or more project codes (separate by comma or new line)"
            style="height:auto; min-height:110px; padding:10px 12px;"
          ><?= htmlspecialchars($postedProjectCodesInput) ?></textarea>
          <?php foreach ($postedProjectIds as $postedProjectId): ?>
            <input type="hidden" name="project_ids[]" value="<?= (int)$postedProjectId ?>">
          <?php endforeach; ?>
          <div class="mini">You can enter brand-new project codes here. Existing matches are reused automatically.</div>
        </div>

        <?php if ($editMode && $hasOwnDivisionSlip): ?>
          <div class="authField">
            <label>Own Division Tracking Number</label>
            <input
              type="text"
              id="editDivisionTrackingNo"
              name="division_tracking_no"
              value="<?= htmlspecialchars($_POST["division_tracking_no"] ?? "") ?>"
              placeholder="<?= htmlspecialchars($ownDivisionTrackingPreview) ?>"
            >
            <div class="mini">Format: <?= htmlspecialchars($myDivisionCode) ?> MMDDYYNN.</div>
            <div id="editDivisionTrackingDuplicateHint" class="mini" style="margin-top:6px; color:#b45309; display:none;"></div>
            <label style="display:flex;align-items:center;gap:8px;margin-top:8px;font-weight:700;">
              <input type="checkbox" name="force_duplicate_division_tracking" value="1" <?= (($_POST["force_duplicate_division_tracking"] ?? "") === "1") ? "checked" : "" ?>>
              Force allow duplicate tracking number
            </label>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <?php if (!$editMode): ?>
    <section class="addDocSection span2">
      <div class="addDocSectionHead">
        <div>
          <h3>Creation Flow</h3>
          <p>Choose whether this document should wait for principal instructions or be routed immediately.</p>
        </div>
      </div>

      <div class="authField span2">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
          <label style="display:flex;gap:10px;align-items:flex-start;padding:14px;border:1px solid rgba(15,23,42,.14);border-radius:16px;background:rgba(255,255,255,.72);">
            <input type="radio" name="creation_mode_choice" value="review" <?= ($postedCreationMode === "review") ? "checked" : "" ?>>
            <span>
              <b>Save for principal review</b>
              <span class="mini" style="display:block;margin-top:4px;">Create the document as ACTIVE, keep it in this office, and do not route yet.</span>
            </span>
          </label>
          <label style="display:flex;gap:10px;align-items:flex-start;padding:14px;border:1px solid rgba(15,23,42,.14);border-radius:16px;background:rgba(255,255,255,.72);">
            <input type="radio" name="creation_mode_choice" value="route_now" <?= ($postedCreationMode !== "review") ? "checked" : "" ?>>
            <span>
              <b>Save and route now</b>
              <span class="mini" style="display:block;margin-top:4px;">Use the selected destination list and send the document immediately.</span>
            </span>
          </label>
        </div>
      </div>
    </section>

    <section class="addDocSection span2" id="destinationBuilderSection">
      <div class="addDocSectionHead">
        <div>
          <h3>Destination Builder</h3>
          <p>Select the people directly. Recipients are grouped by section automatically.</p>
        </div>
      </div>

      <div class="destBuilder destBuilderV2">
        <div class="destToolbar destToolbarTop destToolbarTopV2">
          <div class="destToolbarIntro">
            <div class="destToolbarTitle">Choose recipients</div>
            <div class="mini">Open a division, check the people, then review the selected list on the right.</div>
          </div>
          <div class="destToolbarButtons">
            <button type="button" class="destActionBtn" id="btnAddAllDivisionChiefs">Send to all division chiefs</button>
            <button type="button" class="destActionBtn" id="btnClearDestinations">Clear all</button>
          </div>
        </div>

        <div id="destinationNotice" class="destStatus" aria-live="polite"></div>

        <div class="destBuilderLayout">
          <div class="destDirectoryPane">
            <div id="destinationAccordion" class="destDivisionList"></div>
          </div>

          <div class="destSelectionPane">
            <div id="destinationSummaryBox" class="destSummaryBox"></div>
            <div id="destinationsBox" class="destinationsGrid">
              <div class="destSummaryEmpty">No recipients selected yet.</div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="addDocSection span2">
      <div class="addDocSectionHead">
        <div>
          <h3>Auto-generate</h3>
          <p>Optional PDFs you can generate right away while saving the document.</p>
        </div>
      </div>

      <div class="authField addDocAutoField">
        <div class="addDocInlineLabel">Auto-generate (choose one)</div>

        <?php $choice = (string)($_POST["gen_choice"] ?? "none"); ?>

        <label style="display:flex;align-items:center;gap:8px;font-weight:800;">
          <input type="radio" name="gen_choice" value="none" <?= ($choice === "none") ? "checked" : "" ?>>
          None
        </label>

        <label style="display:flex;align-items:center;gap:8px;font-weight:800;margin-top:8px;">
          <input type="radio" name="gen_choice" value="transmittal" <?= ($choice === "transmittal") ? "checked" : "" ?>>
          Transmittal Memo
        </label>

        <div class="mini" style="margin-top:6px;">
          Generates a printable PDF memo based on <b>Document Date</b> + <b>Subject</b>, and auto-attaches it.
        </div>

        <div id="transmittalOpts" style="margin-top:10px; display:none; gap:10px; flex-wrap:wrap;">
          <label style="display:flex;align-items:center;gap:8px;font-weight:800;">
            <input type="radio" name="transmittal_mode" value="print" <?= (($_POST["transmittal_mode"] ?? "attach") === "print") ? "checked" : "" ?>>
            Generate, Attach, and Print
          </label>
          <label style="display:flex;align-items:center;gap:8px;font-weight:800;">
            <input type="radio" name="transmittal_mode" value="attach" <?= (($_POST["transmittal_mode"] ?? "attach") === "attach") ? "checked" : "" ?>>
            Generate and Attach only
          </label>
        </div>

        <?php if ($hasOwnDivisionSlip): ?>
          <label style="display:flex;align-items:center;gap:8px;font-weight:800;margin-top:14px;">
            <input type="radio" name="gen_choice" value="division_slip" <?= ($choice === "division_slip") ? "checked" : "" ?>>
            <?= htmlspecialchars($ownDivisionSlipLabel) ?>
          </label>

          <div class="mini" style="margin-top:6px;">
            Auto-generates your own division tracking slip. Tracking number is editable before save.
          </div>

          <div id="divisionSlipOpts" style="margin-top:10px; display:none; gap:10px; flex-wrap:wrap;">
            <label style="display:flex;align-items:center;gap:8px;font-weight:800;">
              <input type="radio" name="division_slip_mode" value="print" <?= (($_POST["division_slip_mode"] ?? "attach") === "print") ? "checked" : "" ?>>
              Generate, Attach, and Print
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-weight:800;">
              <input type="radio" name="division_slip_mode" value="attach" <?= (($_POST["division_slip_mode"] ?? "attach") === "attach") ? "checked" : "" ?>>
              Generate and Attach only
            </label>
          </div>

          <div style="margin-top:12px;">
            <label style="font-weight:800;display:block;margin-bottom:6px;">Own Division Tracking Number</label>
            <input type="text" id="createDivisionTrackingNo" name="division_tracking_no" value="<?= htmlspecialchars($_POST["division_tracking_no"] ?? $ownDivisionTrackingPreview) ?>" placeholder="<?= htmlspecialchars($ownDivisionTrackingPreview) ?>">
            <div class="mini" style="margin-top:6px;">Format: <?= htmlspecialchars($myDivisionCode) ?> MMDDYYNN. Auto-filled but editable.</div>
            <div id="createDivisionTrackingDuplicateHint" class="mini" style="margin-top:6px; color:#b45309; display:none;"></div>
            <label style="display:flex;align-items:center;gap:8px;margin-top:8px;font-weight:700;">
              <input type="checkbox" name="force_duplicate_division_tracking" value="1" <?= (($_POST["force_duplicate_division_tracking"] ?? "") === "1") ? "checked" : "" ?>>
              Force allow duplicate tracking number
            </label>
          </div>

          <div id="divisionSlipReceivedWrap" style="margin-top:12px; display:none; gap:6px;">
            <label style="font-weight:800;">Received date and time <span class="mini" style="font-weight:700;">(optional)</span></label>
            <input
              type="datetime-local"
              name="division_slip_received_datetime"
              value="<?= htmlspecialchars($_POST["division_slip_received_datetime"] ?? "") ?>"
            >
            <div class="mini">If filled, this is printed in the received date/time box of the generated division tracking slip. Leave blank if not needed.</div>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="addDocSection span2">
      <div class="addDocSectionHead">
        <div>
          <h3>Notes and Attachment</h3>
          <p>Add extra remarks only when needed, and attach the reference file if available.</p>
        </div>
      </div>

    <div class="authField span2">
      <label>Remarks <span class="mini" style="font-weight:700;">(optional)</span></label>
      <input
        type="text"
        name="remarks"
        placeholder="Add remarks only if needed"
        value="<?= htmlspecialchars($_POST["remarks"] ?? "") ?>"
      >
      <div class="mini" style="margin-top:6px;">
        Leave blank if there is no special instruction.
      </div>
    </div>

    <div class="authField span2">
      <label>Attachment (optional)</label>
      <?php if ($savedTempAttachment): ?>
        <div id="savedAttachmentCard" style="margin-bottom:10px; padding:12px 14px; border:1px solid rgba(25, 135, 84, .25); border-radius:14px; background:rgba(25, 135, 84, .06);">
          <div style="font-weight:900;">Saved for retry: <?= htmlspecialchars((string)($savedTempAttachment["original_name"] ?? "Attachment")) ?></div>
          <div class="mini" style="margin-top:4px;">
            This file will still be attached even if you leave the file picker empty. Choose a new file to replace it.
          </div>
          <button type="button" class="btnGhost" id="btnRemoveSavedAttachment" style="margin-top:10px;">Remove saved file</button>
        </div>
      <?php endif; ?>
      <div class="attachRow">
        <input class="fileInput" type="file" name="attach_file" accept=".pdf,.jpg,.jpeg,.png">
        <input
          type="text"
          name="attach_note"
          placeholder="File description (optional)"
          value="<?= htmlspecialchars($_POST["attach_note"] ?? ($savedTempAttachment["note"] ?? "")) ?>"
        >
      </div>
      <div class="mini">Allowed: PDF/JPG/PNG • Max <?= htmlspecialchars(attachment_max_mb_label()) ?></div>
      <div class="mini" style="margin-top:6px;">If saving fails, the uploaded file is now preserved for retry on this page.</div>
    </div>

    <?php endif; ?>

    <div class="docActions span2 addDocActions">
      <?php if ($editMode): ?>
        <button type="submit" class="btnComp">Save Changes</button>
      <?php else: ?>
      <button type="submit" class="btnComp" data-creation-mode-submit="route_now" id="btnSubmitRouteNow">Save and Route Now</button>
      <button type="submit" class="btnSecondary" data-creation-mode-submit="review" id="btnSubmitReview">Save for Principal Review</button>
      <?php endif; ?>
      <a href="<?= PUBLIC_PATH ?>/documents.php" class="btnGhost" style="text-decoration:none;">Cancel</a>
    </div>
    <?php if (!$editMode): ?>
    </section>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>

<script>
  window.addDocumentConfig = <?= json_encode([
    "editMode" => $editMode,
    "hasOwnDivisionSlip" => $hasOwnDivisionSlip,
    "apiPath" => API_PATH,
    "divisionTrackingLookupUrl" => API_PATH . "/division_tracking_duplicate_lookup.php",
    "excludeDocumentId" => $editMode ? (int)$editDocumentId : 0,
    "sectionLabels" => $sectionLabelMap,
    "sectionMeta" => $sectionMetaMap,
    "divisionChiefTargets" => $divisionChiefTargets,
    "divisionDirectory" => $destinationBuilderDivisions,
    "seedRecipientMap" => $_POST["recipient_map"] ?? [],
    "seedDestinationMode" => $_POST["destination_mode"] ?? [],
    "seedPersonalDeadlineMap" => $_POST["personal_deadline_map"] ?? [],
    "canSetPersonalDeadline" => $isChief,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<?php require __DIR__ . "/../includes/footer.php"; ?>
