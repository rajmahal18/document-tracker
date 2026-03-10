<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_once __DIR__ . "/../core/PPDTrackingSlip.php";
require_login();

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
  $stmt = $conn->prepare("
    SELECT id
    FROM users
    WHERE section_id = ?
      AND is_active = 1
      AND is_chief = 1
    ORDER BY id ASC
    LIMIT 1
  ");
  $stmt->bind_param("i", $sectionId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();

  return (int)($row["id"] ?? 0);
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

$pageTitle = "Add Document";
$error = "";

// ✅ Must be logged in only.
$role = (string)($_SESSION["role"] ?? "user");
$roleNorm = strtolower(trim($role));

// ✅ Must have a section_id for routing
$fromSectionId = (int)($_SESSION["section_id"] ?? 0);

// ✅ Used as fallback display label
$divisionName = trim((string)($_SESSION["division_name"] ?? ""));

// ✅ Robust PPD detection via DB (section -> divisions.name), NOT role-based, NOT hardcoded ID
$isPPD = false;
$myDivisionId = 0;
$myDivisionName = "";

if ($fromSectionId > 0) {
  $stmt = $conn->prepare("
    SELECT d.id AS division_id, d.name AS division_name
    FROM sections s
    JOIN divisions d ON d.id = s.division_id
    WHERE s.id = ? AND s.is_active = 1 AND d.is_active = 1
    LIMIT 1
  ");
  $stmt->bind_param("i", $fromSectionId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();

  $myDivisionId = (int)($row["division_id"] ?? 0);
  $myDivisionName = trim((string)($row["division_name"] ?? ""));

  $dn = strtolower($myDivisionName);
  $isPPD = ($dn !== "" && (
    str_contains($dn, "planning and programming") ||
    str_contains($dn, "ppd")
  ));
}

// ✅ Load sections for dropdown
$sections = $conn->query("
  SELECT s.id, s.name, d.name AS division_name
  FROM sections s
  JOIN divisions d ON d.id = s.division_id
  WHERE s.is_active = 1 AND d.is_active = 1
  ORDER BY d.name ASC, s.name ASC
")->fetch_all(MYSQLI_ASSOC);

// For JS / labels
$sectionLabelMap = [];
foreach ($sections as $s) {
  $sectionLabelMap[(int)$s["id"]] = (string)($s["division_name"] . " — " . $s["name"]);
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
  $tracking_no   = "TRK-" . time();
  $requester     = trim((string)($_POST["requester"] ?? ""));
  $document_date = trim((string)($_POST["document_date"] ?? ""));
  $subject       = trim((string)($_POST["subject"] ?? ""));
  $content_type  = trim((string)($_POST["content_type"] ?? ""));
  $comm_type     = trim((string)($_POST["comm_type"] ?? "internal"));
  $deadlineAtRaw  = trim((string)($_POST["deadline_at"] ?? ""));
  $deadlineAt     = normalize_deadline_input($deadlineAtRaw);
  $selectedSectionId = (int)($_POST["to_section_id"] ?? 0); // picker only

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

  // ✅ One batch id for the initial send
  $sendBatchId = bin2hex(random_bytes(16));

  // Generator choice
  $genChoice = "none";
  if ($isPPD) {
    $genChoice = (string)($_POST["gen_choice"] ?? "none"); // none | transmittal | ppd_slip
    if (!in_array($genChoice, ["none", "transmittal", "ppd_slip"], true)) {
      $genChoice = "none";
    }
  } else {
    $genChoice = (isset($_POST["gen_transmittal"]) && (string)($_POST["gen_transmittal"] ?? "") === "1")
      ? "transmittal"
      : "none";
  }

  $transmittalMode = (string)($_POST["transmittal_mode"] ?? "attach");
  $ppdSlipMode     = (string)($_POST["ppd_slip_mode"] ?? "attach");

  if ($requester === "" || $document_date === "" || $subject === "" || $content_type === "" || $selectedSectionId <= 0) {
    $error = "Please fill in all required fields (including Forward To).";
  } elseif ($fromSectionId <= 0) {
    $error = "Your account has no section assigned. Ask admin to set your section_id.";
  } elseif ($deadlineAtRaw !== "" && $deadlineAt === null) {
    $error = "Deadline must be a valid date and time.";
  } else {
    $userId = (int)($_SESSION["user_id"] ?? 0);

    // Build final recipient map from destination modes.
    $finalRecipientMap = [];

    // If no destination explicitly added, default to currently selected section chief.
    if (count($destinationModeMap) === 0) {
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
        $remarks = "Initial forward on creation";
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
              (document_id, branch_id, from_section_id, to_section_id, from_user_id, to_user_id, route_kind, send_batch_id, received_at, sent_by_user_id, remarks)
            VALUES
              (?, ?, ?, ?, ?, ?, 'ACTION', ?, NULL, ?, ?)
          ");
        } else {
          $stmt = $conn->prepare("
            INSERT INTO routes
              (document_id, from_section_id, to_section_id, to_user_id, send_batch_id, received_at, sent_by_user_id, remarks)
            VALUES
              (?, ?, ?, ?, ?, NULL, ?, ?)
          ");
        }

        foreach ($finalRecipientMap as $destSectionId => $destUserIds) {
          $destSectionId = (int)$destSectionId;

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

              $stmt->bind_param("iiiiiisis", $docId, $branchId, $fromSectionId, $destSectionId, $userId, $rid, $sendBatchId, $userId, $remarks);
            } else {
              workflow_grant_visibility($conn, $docId, $rid, 'PARTICIPANT', null, $userId);
              $stmt->bind_param("iiiisis", $docId, $fromSectionId, $destSectionId, $rid, $sendBatchId, $userId, $remarks);
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

        $payloadSent = json_encode([
          "remarks" => $remarks,
          "recipient_map" => $finalRecipientMap,
          "destination_mode_map" => $destinationModeMap,
          "send_batch_id" => $sendBatchId,
          "branch_mode" => $useBranchModeForThisDocument,
          "branch_ids" => array_values(array_unique(array_filter($createdBranchIds))),
          "route_branch_map" => $routeBranchMap,
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $conn->prepare("
          INSERT INTO document_events
            (document_id, event_type, actor_user_id, actor_section_id, from_section_id, to_section_id, payload_json)
          VALUES (?, 'sent', ?, ?, ?, NULL, ?)
        ");
        $stmt->bind_param("iiiis", $docId, $userId, $fromSectionId, $fromSectionId, $payloadSent);
        $stmt->execute();

        // 6) optional: attach initial file
        if (
          isset($_FILES["attach_file"]) &&
          is_array($_FILES["attach_file"]) &&
          (($_FILES["attach_file"]["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK)
        ) {
          $f = $_FILES["attach_file"];
          $maxBytes = 10 * 1024 * 1024;
          $allowedExt = ["pdf", "jpg", "jpeg", "png"];

          $orig = basename((string)($f["name"] ?? "file"));
          $orig = preg_replace('/[^a-zA-Z0-9._\-\s]/', "_", $orig) ?? $orig;

          $size = (int)($f["size"] ?? 0);
          if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException("Attachment too large (max 10MB)");
          }

          $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
          if ($ext === "" || !in_array($ext, $allowedExt, true)) {
            throw new RuntimeException("Unsupported attachment type (PDF/JPG/PNG only)");
          }

          $tmp = (string)($f["tmp_name"] ?? "");
          if ($tmp === "" || !is_uploaded_file($tmp)) {
            throw new RuntimeException("Invalid upload");
          }

          $finfo = new finfo(FILEINFO_MIME_TYPE);
          $realMime = (string)$finfo->file($tmp);
          $allowedRealMime = ["application/pdf", "image/jpeg", "image/png"];
          if (!in_array($realMime, $allowedRealMime, true)) {
            throw new RuntimeException("Unsupported attachment type (PDF/JPG/PNG only)");
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

          $stamp = date("Ymd_His");
          $rand = bin2hex(random_bytes(6));
          $storedName = $stamp . "_u" . $userId . "_" . $rand . "." . $ext;
          $abs = $docDir . "/" . $storedName;

          if (!move_uploaded_file($tmp, $abs)) {
            throw new RuntimeException("Failed to store attachment");
          }

          $rel = "storage/attachments/doc_" . $docId . "/" . $storedName;
          $note = trim((string)($_POST["attach_note"] ?? ""));
          $mime = $realMime;

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
        }

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

          $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
          $host = (string)($_SERVER["HTTP_HOST"] ?? "localhost");
          $qrUrl = $scheme . "://" . $host . PUBLIC_PATH . "/qr.php?t=" . urlencode($qrToken);

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

        if ($genChoice === "ppd_slip") {
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

          $fromLabel = ($divisionName !== "") ? $divisionName : "PPD";
          if ($rFrom) {
            $fromLabel = trim((string)$rFrom["division_name"]) . " / " . trim((string)$rFrom["section_name"]);
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

          $storedName = "PPD_TRACKING_SLIP_" . $tracking_no . ".pdf";
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

          $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
          $host = (string)($_SERVER["HTTP_HOST"] ?? "localhost");
          $qrUrl = $scheme . "://" . $host . PUBLIC_PATH . "/qr.php?t=" . urlencode($qrToken);

          PPDTrackingSlip::generateA4([
            "ppd_tracking_no"   => $tracking_no,
            "from_label"        => $fromLabel,
            "document_date"     => $document_date,
            "subject"           => $subject,
            "mpw_tracking_no"   => "",
            "received_by"       => "",
            "received_datetime" => "",
            "deadline_date"     => $deadlineAt ? (new DateTime($deadlineAt, new DateTimeZone("Asia/Manila")))->format("m/d/Y") : "",
            "deadline_time"     => $deadlineAt ? (new DateTime($deadlineAt, new DateTimeZone("Asia/Manila")))->format("g:i A") : "",
            "qr_url"            => $qrUrl,
            "logo_left_abs"     => realpath(__DIR__ . "/../assets/mpwlogo1.png") ?: "",
            "logo_right_abs"    => realpath(__DIR__ . "/../assets/ocmlogo.png") ?: "",
          ], $abs);

          $size = (int)@filesize($abs);
          if ($size <= 0) {
            throw new RuntimeException("Failed to generate PPD tracking slip PDF");
          }

          $orig = $storedName;
          $mime = "application/pdf";
          $note = "AUTO:PPD_TRACKING_SLIP";

          $stmt = $conn->prepare("
            INSERT INTO document_attachments
              (document_id, original_name, stored_name, stored_path, mime, size_bytes, note, is_append, uploaded_by_user_id, uploaded_by_section_id)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
          ");
          $stmt->bind_param("issssisii", $docId, $orig, $storedName, $rel, $mime, $size, $note, $userId, $fromSectionId);
          $stmt->execute();
          $ppdAttachId = (int)$conn->insert_id;

          $payloadSlip = json_encode([
            "kind" => "ppd_tracking_slip_generated",
            "attachment_id" => $ppdAttachId,
            "file" => $orig,
          ], JSON_UNESCAPED_UNICODE);

          $stmt = $conn->prepare("
            INSERT INTO document_events
              (document_id, event_type, actor_user_id, actor_section_id, payload_json)
            VALUES
              (?, 'updated', ?, ?, ?)
          ");
          $stmt->bind_param("iiis", $docId, $userId, $fromSectionId, $payloadSlip);
          $stmt->execute();

          if ($ppdSlipMode === "print" && $ppdAttachId > 0) {
            redirect(PUBLIC_PATH . "/transmittal_print.php?id=" . $ppdAttachId);
          }
        }

        redirect(PUBLIC_PATH . "/documents.php");

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

require __DIR__ . "/../includes/layout.php";
?>

<?php if ($error): ?>
  <div class="notice" style="background:#f8d7da;border:1px solid #f5c2c7;">
    <?= htmlspecialchars($error) ?>
  </div>
<?php endif; ?>

<div class="card docFormCard" style="max-width:980px;margin-top:14px;">
  <div class="docFormHead">
    <div>
      <h2 style="margin:6px 0 0;">Add New Document</h2>
    </div>
    <div class="mini" style="text-align:right;">
      Fields with <b>*</b> are required
    </div>
  </div>

  <form method="POST" enctype="multipart/form-data" class="docFormGrid">
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
      <div class="mini" style="margin-top:6px;">Optional. Used for countdown + urgency sorting.</div>
    </div>

    <div class="authField span2">
      <label>Subject <span class="req">*</span></label>
      <input
        type="text"
        name="subject"
        required
        placeholder="Short subject / title"
        value="<?= htmlspecialchars($_POST["subject"] ?? "") ?>"
      >
    </div>

    <div class="authField">
      <label>Content Type <span class="req">*</span></label>
      <input
        type="text"
        name="content_type"
        required
        placeholder="Memorandum, Proposal, Letter..."
        value="<?= htmlspecialchars($_POST["content_type"] ?? "") ?>"
      >
    </div>

    <div class="authField">
      <label>Communication Type <span class="req">*</span></label>
      <select name="comm_type" class="select" required>
        <option value="internal" <?= (($_POST["comm_type"] ?? "internal") === "internal") ? "selected" : "" ?>>Internal</option>
        <option value="external" <?= (($_POST["comm_type"] ?? "") === "external") ? "selected" : "" ?>>External</option>
      </select>
    </div>

    <div class="authField span2">
      <label>Destination Builder <span class="req">*</span></label>

      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <select name="to_section_id" class="select" required id="addToSection" style="flex:1; min-width:280px;">
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

        <button type="button" class="btnGhost" id="btnAddDestination">Add Destination</button>
      </div>

      <div class="mini" style="margin-top:8px;">
        Add one or more destination sections below. If there is only <b>one</b> destination, you may route directly to specific users.
        If there are <b>multiple</b> destinations, all of them automatically use <b>Section Chief</b> for initial routing.
      </div>

      <div id="destinationModeHint" class="mini" style="margin-top:10px; font-weight:800;"></div>

      <div id="destinationsBox" style="margin-top:12px; display:grid; gap:12px;">
        <div class="mini" style="opacity:.8;">No destinations added yet.</div>
      </div>
    </div>

    <?php if ($isPPD): ?>
      <div class="authField span2">
        <div style="font-weight:900;margin-bottom:6px;">Auto-generate (choose one)</div>

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

        <label style="display:flex;align-items:center;gap:8px;font-weight:800;margin-top:14px;">
          <input type="radio" name="gen_choice" value="ppd_slip" <?= ($choice === "ppd_slip") ? "checked" : "" ?>>
          PPD Document Tracking Slip
        </label>

        <div class="mini" style="margin-top:6px;">
          Generates a printable <b>PPD Tracking Slip</b> based on <b>Document Date</b> + <b>Subject</b>, and auto-attaches it.
        </div>

        <div id="ppdSlipOpts" style="margin-top:10px; display:none; gap:10px; flex-wrap:wrap;">
          <label style="display:flex;align-items:center;gap:8px;font-weight:800;">
            <input type="radio" name="ppd_slip_mode" value="print" <?= (($_POST["ppd_slip_mode"] ?? "attach") === "print") ? "checked" : "" ?>>
            Generate, Attach, and Print
          </label>
          <label style="display:flex;align-items:center;gap:8px;font-weight:800;">
            <input type="radio" name="ppd_slip_mode" value="attach" <?= (($_POST["ppd_slip_mode"] ?? "attach") === "attach") ? "checked" : "" ?>>
            Generate and Attach only
          </label>
        </div>
      </div>
    <?php else: ?>
      <div class="authField span2">
        <label>
          <input
            type="checkbox"
            name="gen_transmittal"
            value="1"
            id="genTransmittal"
            <?= (($_POST["gen_transmittal"] ?? "") === "1") ? "checked" : "" ?>
          >
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
      </div>
    <?php endif; ?>

    <div class="docDivider span2"></div>

    <div class="authField span2">
      <label>Attachment (optional)</label>
      <div class="attachRow">
        <input class="fileInput" type="file" name="attach_file" accept=".pdf,.jpg,.jpeg,.png">
        <input
          type="text"
          name="attach_note"
          placeholder="Note (optional)"
          value="<?= htmlspecialchars($_POST["attach_note"] ?? "") ?>"
        >
      </div>
      <div class="mini">Allowed: PDF/JPG/PNG • Max 10MB</div>
    </div>

    <div class="docActions span2">
      <button type="submit" class="btnSecondary">Save Document</button>
      <a href="<?= PUBLIC_PATH ?>/documents.php" class="btnGhost" style="text-decoration:none;">Cancel</a>
    </div>
  </form>
</div>

<script>
(function(){
  const isPPD = <?= $isPPD ? "true" : "false" ?>;
  function show(el, on){ if (!el) return; el.style.display = on ? "flex" : "none"; }

  if (!isPPD) {
    const cb = document.getElementById("genTransmittal");
    const transOpts = document.getElementById("transmittalOpts");
    if (cb && transOpts) {
      function sync(){
        show(transOpts, cb.checked);
        if (cb.checked) {
          const any = transOpts.querySelector('input[type="radio"]:checked');
          if (!any) {
            const def = transOpts.querySelector('input[type="radio"][value="attach"]');
            if (def) def.checked = true;
          }
        }
      }
      cb.addEventListener("change", sync);
      sync();
    }
  } else {
    const transOpts = document.getElementById("transmittalOpts");
    const slipOpts  = document.getElementById("ppdSlipOpts");
    const radios = document.querySelectorAll('input[name="gen_choice"]');

    function syncPPD(){
      let choice = "none";
      radios.forEach(r => { if (r.checked) choice = r.value; });

      show(transOpts, choice === "transmittal");
      show(slipOpts,  choice === "ppd_slip");

      if (choice === "transmittal" && transOpts) {
        const any = transOpts.querySelector('input[type="radio"]:checked');
        if (!any) {
          const def = transOpts.querySelector('input[type="radio"][value="attach"]');
          if (def) def.checked = true;
        }
      }

      if (choice === "ppd_slip" && slipOpts) {
        const any = slipOpts.querySelector('input[type="radio"]:checked');
        if (!any) {
          const def = slipOpts.querySelector('input[type="radio"][value="attach"]');
          if (def) def.checked = true;
        }
      }
    }

    radios.forEach(r => r.addEventListener("change", syncPPD));
    syncPPD();
  }

  const sectionLabels = <?= json_encode($sectionLabelMap, JSON_UNESCAPED_UNICODE) ?>;
  const seedRecipientMap = <?= json_encode($_POST["recipient_map"] ?? [], JSON_UNESCAPED_UNICODE) ?>;
  const seedDestinationMode = <?= json_encode($_POST["destination_mode"] ?? [], JSON_UNESCAPED_UNICODE) ?>;

  const selSection = document.getElementById("addToSection");
  const btnAddDestination = document.getElementById("btnAddDestination");
  const destinationsBox = document.getElementById("destinationsBox");
  const destinationModeHint = document.getElementById("destinationModeHint");
  const form = document.querySelector("form.docFormGrid");

  // sectionId -> { mode: "chief"|"users", users: Map(userId -> userObj), loadedUsers: [] }
  const destinations = new Map();

  function esc(s){
    return String(s ?? "").replace(/[&<>"']/g, c => ({
      "&":"&amp;",
      "<":"&lt;",
      ">":"&gt;",
      '"':"&quot;",
      "'":"&#039;"
    }[c]));
  }

  function getDestinationCount() {
    return destinations.size;
  }

  function isMultiSectionMode() {
    return getDestinationCount() > 1;
  }

  function getSectionLabel(sectionId) {
    return sectionLabels[String(sectionId)] || `Section #${sectionId}`;
  }

  async function fetchUsersBySection(sectionId) {
    const res = await fetch(`<?= API_PATH ?>/users_by_section.php?section_id=${encodeURIComponent(sectionId)}`, {
      headers: { "Accept": "application/json" }
    });
    if (!res.ok) throw new Error("HTTP " + res.status);
    const rows = await res.json();
    return Array.isArray(rows) ? rows : [];
  }

  function getChiefFromLoadedUsers(dest) {
    if (!dest || !Array.isArray(dest.loadedUsers)) return null;
    return dest.loadedUsers.find(u => !!u.is_chief) || null;
  }

  async function ensureUsersLoaded(sectionId) {
    const sid = String(sectionId);
    const dest = destinations.get(sid);
    if (!dest) return [];

    if (Array.isArray(dest.loadedUsers) && dest.loadedUsers.length > 0) {
      return dest.loadedUsers;
    }

    const rows = await fetchUsersBySection(sid);
    dest.loadedUsers = rows;

    // Upgrade placeholder names
    dest.users.forEach((u, uid) => {
      const found = rows.find(r => String(r.id) === String(uid));
      if (found) {
        const rawName = String(found.name || (`#${found.id}`));
        const label = found.is_chief ? `${rawName} (CHIEF)` : rawName;
        dest.users.set(String(found.id), {
          id: Number(found.id),
          name: label,
          isChief: !!found.is_chief
        });
      }
    });

    return rows;
  }

  async function setDestinationToChiefOnly(sectionId) {
    const sid = String(sectionId);
    const dest = destinations.get(sid);
    if (!dest) return;

    const rows = await ensureUsersLoaded(sid);
    const chief = rows.find(u => !!u.is_chief);
    if (!chief) throw new Error(`No Section Chief configured for ${getSectionLabel(sid)}`);

    dest.mode = "chief";
    dest.users.clear();
    dest.users.set(String(chief.id), {
      id: Number(chief.id),
      name: `${chief.name} (CHIEF)`,
      isChief: true
    });
  }

  async function enforceMultiSectionChiefOnly() {
    if (!isMultiSectionMode()) return;
    const ids = Array.from(destinations.keys());
    for (const sid of ids) {
      await setDestinationToChiefOnly(sid);
    }
  }

  function createEmptyDestination() {
    return {
      mode: "chief",
      users: new Map(),
      loadedUsers: []
    };
  }

  function seedFromPost() {
    const modeMap = (seedDestinationMode && typeof seedDestinationMode === "object") ? seedDestinationMode : {};
    const recipientMap = (seedRecipientMap && typeof seedRecipientMap === "object") ? seedRecipientMap : {};

    const allSectionIds = new Set([
      ...Object.keys(modeMap),
      ...Object.keys(recipientMap)
    ]);

    allSectionIds.forEach(sectionId => {
      const sid = String(sectionId);
      const dest = createEmptyDestination();
      dest.mode = (String(modeMap[sid] || "chief") === "users") ? "users" : "chief";

      const seedUsers = Array.isArray(recipientMap[sid]) ? recipientMap[sid] : [];
      seedUsers.forEach(uid => {
        const id = Number(uid);
        if (id > 0) {
          dest.users.set(String(id), {
            id,
            name: `#${id}`,
            isChief: false
          });
        }
      });

      destinations.set(sid, dest);
    });
  }

  function syncHiddenInputs() {
    form.querySelectorAll('[data-destination-hidden="1"]').forEach(el => el.remove());

    destinations.forEach((dest, sid) => {
      const modeInput = document.createElement("input");
      modeInput.type = "hidden";
      modeInput.name = `destination_mode[${sid}]`;
      modeInput.value = dest.mode;
      modeInput.setAttribute("data-destination-hidden", "1");
      form.appendChild(modeInput);

      dest.users.forEach(u => {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = `recipient_map[${sid}][]`;
        input.value = String(u.id);
        input.setAttribute("data-destination-hidden", "1");
        form.appendChild(input);
      });
    });
  }

  function renderModeHint() {
    if (!destinationModeHint) return;

    if (destinations.size === 0) {
      destinationModeHint.textContent = "";
      return;
    }

    if (isMultiSectionMode()) {
      destinationModeHint.textContent = "Multiple destination sections detected: chief-only initial routing is active for all destination cards.";
      return;
    }

    destinationModeHint.textContent = "Single destination mode: you may choose Chief only or Specific users.";
  }

  function renderDestinations() {
    if (!destinationsBox) return;

    if (destinations.size === 0) {
      destinationsBox.innerHTML = `<div class="mini" style="opacity:.8;">No destinations added yet.</div>`;
      renderModeHint();
      syncHiddenInputs();
      return;
    }

    const multi = isMultiSectionMode();

    const html = Array.from(destinations.entries()).map(([sid, dest]) => {
      const sectionLabel = getSectionLabel(sid);
      const modeLabel = multi ? "Chief only" : (dest.mode === "users" ? "Specific users" : "Chief only");

      const currentNames = [];
      dest.users.forEach(u => currentNames.push(u.name || `#${u.id}`));

      const selectedSummary = currentNames.length > 0
        ? currentNames.join(", ")
        : (multi || dest.mode === "chief"
          ? "Will default to Section Chief"
          : "No users selected (will default to Section Chief)");

      const modeControls = multi ? `
        <div class="mini" style="font-weight:800; opacity:.8;">Mode: Chief only (locked because multiple destination sections are selected)</div>
      ` : `
        <div style="display:flex; gap:14px; flex-wrap:wrap; margin-top:8px;">
          <label style="display:flex; align-items:center; gap:8px; font-weight:800;">
            <input type="radio" name="destModeUI_${sid}" value="chief" ${dest.mode === "chief" ? "checked" : ""} data-dest-mode="${sid}">
            Chief only
          </label>
          <label style="display:flex; align-items:center; gap:8px; font-weight:800;">
            <input type="radio" name="destModeUI_${sid}" value="users" ${dest.mode === "users" ? "checked" : ""} data-dest-mode="${sid}">
            Specific users
          </label>
        </div>
      `;

      return `
        <div class="card" style="border:1px solid rgba(0,0,0,.10); border-radius:16px; padding:14px;" data-destination-card="${sid}">
          <div style="display:flex; gap:10px; align-items:flex-start; justify-content:space-between; flex-wrap:wrap;">
            <div>
              <div style="font-weight:900; font-size:16px;">${esc(sectionLabel)}</div>
              <div class="mini" style="margin-top:4px;">Current mode: <b>${esc(modeLabel)}</b></div>
            </div>
            <button type="button" class="btnGhost" data-remove-destination="${sid}">Remove</button>
          </div>

          ${modeControls}

          <div class="mini" style="margin-top:10px;">
            Current initial recipient(s): <b>${esc(selectedSummary)}</b>
          </div>

          <div data-users-panel="${sid}" style="margin-top:12px; ${(!multi && dest.mode === "users") ? "" : "display:none;"}">
            <div class="mini" style="margin-bottom:8px;">
              Select one or more users from this section. If you leave it blank, it will still default to the Section Chief.
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:8px;">
              <button type="button" class="btnGhost" data-select-all-users="${sid}">Select all users</button>
              <button type="button" class="btnGhost" data-clear-users="${sid}">Clear users</button>
            </div>

            <div
              data-users-box="${sid}"
              style="border:1px solid rgba(0,0,0,.10); border-radius:12px; padding:10px; max-height:220px; overflow:auto;"
            >
              <div class="mini" style="opacity:.8;">Loading users…</div>
            </div>
          </div>
        </div>
      `;
    }).join("");

    destinationsBox.innerHTML = html;
    attachDestinationHandlers();
    renderModeHint();
    syncHiddenInputs();

    if (!multi) {
      destinations.forEach((dest, sid) => {
        if (dest.mode === "users") {
          renderUsersPanel(sid);
        }
      });
    }
  }

  async function renderUsersPanel(sectionId) {
    const sid = String(sectionId);
    const dest = destinations.get(sid);
    const panel = destinationsBox.querySelector(`[data-users-box="${sid}"]`);
    if (!dest || !panel) return;

    try {
      const rows = await ensureUsersLoaded(sid);

      if (!Array.isArray(rows) || rows.length === 0) {
        panel.innerHTML = `<div class="mini" style="opacity:.8;">No active users found in that section.</div>`;
        return;
      }

      panel.innerHTML = rows.map(u => {
        const id = Number(u.id);
        const rawName = String(u.name || ("#" + id));
        const isChief = !!u.is_chief;
        const label = isChief ? `${rawName} (CHIEF)` : rawName;
        const checked = dest.users.has(String(id));

        return `
          <label style="display:flex; align-items:center; gap:10px; padding:6px 4px; border-radius:10px;">
            <input
              class="dest-user-cb"
              type="checkbox"
              value="${id}"
              data-dest-user-section="${sid}"
              data-name="${esc(label)}"
              data-is-chief="${isChief ? "1" : "0"}"
              ${checked ? "checked" : ""}
            >
            <span style="font-weight:900;">${esc(label)}</span>
            ${isChief ? `<span class="mini" style="margin-left:6px; padding:2px 8px; border-radius:999px; border:1px solid rgba(0,0,0,.15);">Chief</span>` : ``}
            <span class="mini" style="margin-left:auto; opacity:.7;">#${id}</span>
          </label>
        `;
      }).join("");

      attachUserCheckboxHandlers(sid);

    } catch (e) {
      panel.innerHTML = `<div class="mini" style="opacity:.8;">Failed to load users. Try again.</div>`;
    }
  }

  function attachUserCheckboxHandlers(sectionId) {
    const sid = String(sectionId);
    const dest = destinations.get(sid);
    if (!dest) return;

    destinationsBox.querySelectorAll(`input.dest-user-cb[data-dest-user-section="${sid}"]`).forEach(cb => {
      cb.addEventListener("change", () => {
        const uid = String(cb.value);
        const id = Number(cb.value);
        const name = cb.dataset.name || (`#${id}`);
        const isChief = cb.dataset.isChief === "1";

        if (cb.checked) {
          dest.users.set(uid, { id, name, isChief });
        } else {
          dest.users.delete(uid);
        }

        syncHiddenInputs();
        renderDestinations();
      });
    });
  }

  function attachDestinationHandlers() {
    destinationsBox.querySelectorAll("[data-remove-destination]").forEach(btn => {
      btn.addEventListener("click", async () => {
        const sid = String(btn.getAttribute("data-remove-destination") || "");
        if (!sid) return;

        destinations.delete(sid);

        if (destinations.size === 1) {
          const onlySid = Array.from(destinations.keys())[0];
          const onlyDest = destinations.get(onlySid);
          if (onlyDest && onlyDest.mode === "chief") {
            await setDestinationToChiefOnly(onlySid);
          }
        }

        renderDestinations();
      });
    });

    destinationsBox.querySelectorAll("[data-dest-mode]").forEach(radio => {
      radio.addEventListener("change", async () => {
        const sid = String(radio.getAttribute("data-dest-mode") || "");
        const value = String(radio.value || "chief");
        const dest = destinations.get(sid);
        if (!dest) return;
        if (isMultiSectionMode()) return;

        dest.mode = (value === "users") ? "users" : "chief";

        if (dest.mode === "chief") {
          await setDestinationToChiefOnly(sid);
        } else {
          // keep current selected users; if empty, that's okay (backend will fallback to chief)
          await ensureUsersLoaded(sid);
        }

        renderDestinations();
      });
    });

    destinationsBox.querySelectorAll("[data-select-all-users]").forEach(btn => {
      btn.addEventListener("click", async () => {
        const sid = String(btn.getAttribute("data-select-all-users") || "");
        const dest = destinations.get(sid);
        if (!dest || isMultiSectionMode() || dest.mode !== "users") return;

        const rows = await ensureUsersLoaded(sid);
        dest.users.clear();
        rows.forEach(u => {
          const rawName = String(u.name || (`#${u.id}`));
          const label = u.is_chief ? `${rawName} (CHIEF)` : rawName;
          dest.users.set(String(u.id), {
            id: Number(u.id),
            name: label,
            isChief: !!u.is_chief
          });
        });

        renderDestinations();
      });
    });

    destinationsBox.querySelectorAll("[data-clear-users]").forEach(btn => {
      btn.addEventListener("click", () => {
        const sid = String(btn.getAttribute("data-clear-users") || "");
        const dest = destinations.get(sid);
        if (!dest || isMultiSectionMode() || dest.mode !== "users") return;

        dest.users.clear();
        renderDestinations();
      });
    });
  }

  btnAddDestination?.addEventListener("click", async () => {
    const sid = String(selSection?.value || "");
    if (!sid) {
      alert("Select a section first.");
      return;
    }

    if (destinations.has(sid)) {
      alert("That destination section is already added.");
      return;
    }

    const dest = createEmptyDestination();
    destinations.set(sid, dest);

    if (isMultiSectionMode()) {
      await enforceMultiSectionChiefOnly();
    } else {
      await setDestinationToChiefOnly(sid);
    }

    renderDestinations();
  });

  seedFromPost();

  (async function init(){
    if (destinations.size > 1) {
      await enforceMultiSectionChiefOnly();
    } else if (destinations.size === 1) {
      const sid = Array.from(destinations.keys())[0];
      const dest = destinations.get(sid);
      if (dest && dest.mode === "chief") {
        await setDestinationToChiefOnly(sid);
      }
    }

    renderDestinations();
  })();
})();
</script>

<?php require __DIR__ . "/../includes/footer.php"; ?>