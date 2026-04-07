<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_once __DIR__ . "/../core/division_tracking.php";
require_once __DIR__ . "/../core/DivisionTrackingSlip.php";
require_login();


function map_upload_error_message(int $code): string
{
  return match ($code) {
    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "Attachment too large (max 30MB).",
    UPLOAD_ERR_PARTIAL => "Attachment upload was interrupted. Please try again.",
    UPLOAD_ERR_NO_TMP_DIR => "Upload failed because the server temp folder is missing.",
    UPLOAD_ERR_CANT_WRITE => "Upload failed because the server could not write the file.",
    UPLOAD_ERR_EXTENSION => "Upload blocked by server extension.",
    default => "Failed to upload attachment.",
  };
}

function ensure_storage_dir(string $path): string
{
  if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
    throw new RuntimeException("Failed to prepare storage directory.");
  }

  return $path;
}

function attachments_base_dir(): string
{
  $baseDir = realpath(__DIR__ . "/../storage/attachments");
  if ($baseDir === false) {
    $baseDir = __DIR__ . "/../storage/attachments";
  }

  return ensure_storage_dir($baseDir);
}

function temp_attachment_dir(): string
{
  return ensure_storage_dir(rtrim(attachments_base_dir(), "/\\") . "/_tmp");
}

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
  $maxBytes = 30 * 1024 * 1024;
  $allowedExt = ["pdf", "jpg", "jpeg", "png"];
  $allowedRealMime = ["application/pdf", "image/jpeg", "image/png"];

  $orig = basename((string)($file["name"] ?? "file"));
  $orig = preg_replace('/[^a-zA-Z0-9._\-\s]/', "_", $orig) ?? $orig;

  $size = (int)($file["size"] ?? 0);

  if ($size <= 0) {
    throw new RuntimeException("Upload failed on server. Please re-save the PDF and try again.");
  }

  if ($size > $maxBytes) {
    throw new RuntimeException("Attachment too large (max 30MB)");
  }

  $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
  if ($ext === '' || !in_array($ext, $allowedExt, true)) {
    throw new RuntimeException("Unsupported attachment type (PDF/JPG/PNG only)");
  }

  $tmp = (string)($file["tmp_name"] ?? "");
  if ($tmp === '' || !is_uploaded_file($tmp)) {
    throw new RuntimeException("Invalid upload");
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $realMime = (string)$finfo->file($tmp);
  if (!in_array($realMime, $allowedRealMime, true)) {
    throw new RuntimeException("Unsupported attachment type (PDF/JPG/PNG only)");
  }

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

$pageTitle = "Add Document";
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
foreach ($sections as $s) {
  $sid = (int)$s["id"];
  $division = (string)($s["division_name"] ?? "");
  $section = (string)($s["name"] ?? "");
  $sectionLabelMap[$sid] = $division . " — " . $section;
  $sectionMetaMap[$sid] = [
    "division_name" => $division,
    "section_name" => $section,
    "label" => $division . " — " . $section,
  ];
}

// Default date
$phNow = new DateTime("now", new DateTimeZone("Asia/Manila"));
$defaultDocDate = $phNow->format("Y-m-d");

function normalize_deadline_input(?string $raw): ?string
{
  $raw = trim((string)$raw);
  if ($raw === "") {
    return null;
  }

  $dt = DateTime::createFromFormat("Y-m-d\TH:i", $raw, new DateTimeZone("Asia/Manila"));
  if (!$dt) {
    return null;
  }

  return $dt->format("Y-m-d H:i:s");
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
  if (strcasecmp($remarks, "none") === 0) {
    $remarks = "";
  }
  $selectedSectionId = (int)($_POST["to_section_id"] ?? 0); // picker only

  $fileErrorCode = (int)($_FILES["attach_file"]["error"] ?? UPLOAD_ERR_NO_FILE);
  if ($fileErrorCode === UPLOAD_ERR_OK) {
    try {
      stash_uploaded_attachment($_FILES["attach_file"], $userId);
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  } elseif ($fileErrorCode !== UPLOAD_ERR_NO_FILE) {
    $error = map_upload_error_message($fileErrorCode);
  } elseif (get_saved_temp_attachment() !== null) {
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
  if ($divisionTrackingInput === '' && $hasOwnDivisionSlip) {
    $divisionTrackingInput = $ownDivisionTrackingPreview;
  }

  if (strcasecmp((string)($_POST["content_type"] ?? ""), "Others") === 0 && $content_type_other === "") {
    $error = "Please specify the content type when Others is selected.";
  } elseif ($requester === "" || $document_date === "" || $subject === "" || $content_type === "") {
    $error = "Please fill in all required fields.";
  } elseif ($builderContractEnabled && !$hasSeededDestinations) {
    $error = "Please add at least one destination to the list.";
  } elseif (!$builderContractEnabled && !$hasSeededDestinations && $selectedSectionId <= 0) {
    $error = "Please add at least one destination.";
  } elseif ($fromSectionId <= 0) {
    $error = "Your account has no section assigned. Ask admin to set your section_id.";
  } elseif ($deadlineAtRaw !== "" && $deadlineAt === null) {
    $error = "Deadline must be a valid date and time.";
  } else {
    // Build final recipient map from destination modes.
    $finalRecipientMap = [];

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

    // Safety: multi-section must always be chief-only.
    if ($error === "" && count($finalRecipientMap) > 1) {
      $finalRecipientMap = normalize_recipient_map_to_chiefs($conn, $finalRecipientMap);
    }

    if ($error === "" && count($finalRecipientMap) === 0) {
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
            strtoupper(trim($divisionTrackingInput)) !== strtoupper(trim($ownDivisionTrackingPreview))
          );
        }

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

        // 3) participants: every recipient section can SEE
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

        // 3.5) Validate selected users per destination section
        $validatedRecipients = [];
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

        // 4) routes: multi-section per-user routing
        $routeBranchMap = [];
        $createdBranchIds = [];

        $rootBranchId = 0;
        if ($useBranchModeForThisDocument) {
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

        if ($useBranchModeForThisDocument) {
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

        // 5) events: created + sent
        $payloadCreated = json_encode([
          "tracking_no" => $tracking_no,
          "subject" => $subject,
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $conn->prepare("
          INSERT INTO document_events
            (document_id, event_type, actor_user_id, actor_section_id, payload_json)
          VALUES (?, 'created', ?, ?, ?)
        ");
        $stmt->bind_param("iiis", $docId, $userId, $fromSectionId, $payloadCreated);
        $stmt->execute();

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
          $flowRows = build_division_slip_flow_rows($conn, $docId, $myDivisionId);
          $nameEntries = build_division_name_initial_entries($conn, $myDivisionId, (int)($divisionHead['id'] ?? 0));

          DivisionTrackingSlip::generateA4([
            "division_tracking_no" => $divisionSlipNo,
            "division_name"        => $myDivisionName,
            "division_code"        => $myDivisionCode,
            "from_label"           => $fromLabel,
            "document_date"        => $document_date,
            "subject"              => $subject,
            "mpw_tracking_no"      => $tracking_no,
            "received_by"          => $actualUserFullName !== "" ? $actualUserFullName : trim((string)($_SESSION["full_name"] ?? "")),
            "received_datetime"    => "",
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
            redirect(PUBLIC_PATH . "/division_tracking_slip_print.php?id=" . $divisionAttachId);
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

$savedTempAttachment = get_saved_temp_attachment();

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

<div class="card docFormCard addDocumentPage" style="max-width:1040px;margin-top:14px;">
  <div class="docFormHead addDocHeader">
    <div>
      <div class="addDocEyebrow">Document Intake</div>
      <h2 style="margin:6px 0 0;">Add New Document</h2>
      <div class="mini addDocLead">Fill in the basic details first, then choose destinations and optional auto-generated files.</div>
    </div>
    <div class="addDocRequiredNote">
      Fields with <b>*</b> are required
    </div>
  </div>

  <form method="POST" enctype="multipart/form-data" class="docFormGrid addDocForm" data-remove-saved-attachment-url="<?= API_PATH ?>/remove_saved_attachment.php">
    <?php if ($assistantModeEnabled && $actingPrincipalUserId > 0): ?>
      <input type="hidden" name="acting_principal_user_id" value="<?= (int)$actingPrincipalUserId ?>">
    <?php endif; ?>
    <input type="hidden" name="remove_saved_attachment" value="0" id="removeSavedAttachmentInput">
    <input type="hidden" name="destination_builder_contract" value="0" id="destinationBuilderContractInput">

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
            type="datetime-local"
            name="deadline_at"
            value="<?= htmlspecialchars($_POST["deadline_at"] ?? "") ?>"
          >
          <div class="mini">Optional. Used for countdown + urgency sorting.</div>
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
          <label>Content Type <span class="req">*</span></label>
          <select name="content_type" id="contentTypeSelect" class="select" required>
            <option value="">-- Please Select Type --</option>
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
              placeholder="Enter content type"
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
      </div>
    </section>

    <section class="addDocSection span2">
      <div class="addDocSectionHead">
        <div>
          <h3>Destination Builder</h3>
          <p>Pick a section, check the recipients, then add it to the list.</p>
        </div>
      </div>

      <div class="destBuilder">
        <div class="destToolbar destToolbarTop">
          <select name="to_section_id" class="select destSelect" id="addToSection">
            <option value="">-- Select Section --</option>
            <?php foreach ($sections as $s): ?>
              <option
                value="<?= (int)$s["id"] ?>"
                <?= ((string)$s["id"] === (string)($_POST["to_section_id"] ?? "")) ? "selected" : "" ?>
              >
                <?= htmlspecialchars($s["division_name"] . " — " . $s["name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <button type="button" class="destActionBtn" id="btnAddAllDivisionChiefs">Send to all division chiefs</button>
        </div>

        <div id="sectionPreviewBox" class="destSectionPreview">
          <div class="destSummaryEmpty">Pick a section to preview users.</div>
        </div>

        <div class="destToolbarActions">
          <button type="button" class="destActionBtn is-primary" id="btnAddDestination" style="display:none;">Add destination</button>
          <button type="button" class="destActionBtn" id="btnCancelAllDivisionChiefs" style="display:none;">Cancel</button>
        </div>

        <div id="destinationNotice" class="destStatus" aria-live="polite"></div>
        <div id="destinationSummaryBox" class="destSummaryBox"></div>
        <div id="destinationModeHint" class="destModeBar" style="display:none;"></div>

        <div id="destinationsBox" class="destinationsGrid">
          <div class="destSummaryEmpty">No destinations added yet.</div>
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
            <input type="text" name="division_tracking_no" value="<?= htmlspecialchars($_POST["division_tracking_no"] ?? $ownDivisionTrackingPreview) ?>" placeholder="<?= htmlspecialchars($ownDivisionTrackingPreview) ?>">
            <div class="mini" style="margin-top:6px;">Format: <?= htmlspecialchars($myDivisionCode) ?> MMDDYYNN. Auto-filled but editable.</div>
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
      <div class="mini">Allowed: PDF/JPG/PNG • Max 30MB</div>
      <div class="mini" style="margin-top:6px;">If saving fails, the uploaded file is now preserved for retry on this page.</div>
    </div>

    <div class="docActions span2 addDocActions">
      <button type="submit" class="btnSecondary">Save Document</button>
      <a href="<?= PUBLIC_PATH ?>/documents.php" class="btnGhost" style="text-decoration:none;">Cancel</a>
    </div>
    </section>
  </form>
</div>

<script>
  window.addDocumentConfig = <?= json_encode([
    "hasOwnDivisionSlip" => $hasOwnDivisionSlip,
    "apiPath" => API_PATH,
    "sectionLabels" => $sectionLabelMap,
    "sectionMeta" => $sectionMetaMap,
    "divisionChiefTargets" => $divisionChiefTargets,
    "seedRecipientMap" => $_POST["recipient_map"] ?? [],
    "seedDestinationMode" => $_POST["destination_mode"] ?? [],
    "seedPersonalDeadlineMap" => $_POST["personal_deadline_map"] ?? [],
    "canSetPersonalDeadline" => $isChief,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<?php require __DIR__ . "/../includes/footer.php"; ?>