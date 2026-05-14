<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "Method not allowed"]);
  exit;
}

require_csrf();

$docId       = (int)($_POST["document_id"] ?? 0);
$routeIdReq  = (int)($_POST["route_id"] ?? 0);
$branchIdReq = (int)($_POST["branch_id"] ?? 0);
$note        = trim((string)($_POST["note"] ?? ""));
$isAppend    = (int)($_POST["is_append"] ?? 0) === 1 ? 1 : 0;

if ($docId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad document_id"]);
  exit;
}

if (!isset($_FILES["file"])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing file"]);
  exit;
}

$identity = effective_document_identity($conn);
$role        = (string)($_SESSION["role"] ?? "user");
$assistantMode = (bool)($identity['assistant_mode'] ?? false);
$actualUserId = (int)($identity['actual_user_id'] ?? 0);
$userId      = (int)($identity['effective_user_id'] ?? 0);
$mySectionId = (int)($identity['effective_section_id'] ?? 0);
$adminModeRequested = (int)($_POST["admin_mode"] ?? 0) === 1;
$isAdminModeUpload = ($adminModeRequested && $role === "admin" && !$assistantMode);



try {
  $conn->begin_transaction();

  // Fetch doc state for permission rules
  $stmt = $conn->prepare("
    SELECT
      d.current_status,
      d.current_holder_section_id,
      EXISTS (SELECT 1 FROM routes r WHERE r.document_id = d.id AND r.received_at IS NULL AND r.cancelled_at IS NULL) AS has_open_route
    FROM documents d
    WHERE d.id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $doc = $stmt->get_result()->fetch_assoc();

  if (!$doc) {
    $conn->rollback();
    http_response_code(404);
    echo json_encode(["ok" => false, "error" => "Document not found"]);
    exit;
  }

  $status = strtoupper((string)($doc["current_status"] ?? ""));
  $holderSectionId = (int)($doc["current_holder_section_id"] ?? 0);
  $hasOpenRoute = ((int)($doc["has_open_route"] ?? 0) === 1);
  $branchMode = workflow_branch_mode_enabled($conn);
  $docHasRealBranches = ($branchMode && workflow_document_has_real_branches($conn, $docId));
  $isPrivileged = in_array($role, ["admin", "records"], true);
  $attachmentBranchId = 0;
  $branchAttachmentScopeEnabled = workflow_branch_attachment_scope_enabled($conn);

  $isAllowedClosedAdminStatus = $isAdminModeUpload && in_array($status, ["RELEASED", "ARCHIVED"], true);

  // Default rule stays ACTIVE-only. Admin mode may append files to closed docs.
  if ($status !== "ACTIVE" && !$isAllowedClosedAdminStatus) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Cannot attach files: document is not open for attachments."]);
    exit;
  }

  if ($docHasRealBranches) {
    if ($routeIdReq > 0) {
      $stmt = $conn->prepare("
        SELECT r.id, r.branch_id, r.to_user_id, r.received_at, r.cancelled_at
        FROM routes r
        WHERE r.id = ?
          AND r.document_id = ?
        LIMIT 1
      ");
      $stmt->bind_param("ii", $routeIdReq, $docId);
      $stmt->execute();
      $routeRow = $stmt->get_result()->fetch_assoc();
      if ($routeRow) {
        $attachmentBranchId = (int)($routeRow["branch_id"] ?? 0);
      }
    }

    if ($branchIdReq > 0) {
      $stmt = $conn->prepare("
        SELECT id
        FROM document_branches
        WHERE id = ? AND document_id = ?
        LIMIT 1
      ");
      $stmt->bind_param("ii", $branchIdReq, $docId);
      $stmt->execute();
      $branchExists = (bool)$stmt->get_result()->fetch_assoc();
      if ($branchExists) {
        $attachmentBranchId = $branchIdReq;
      }
    }

    if (!$isPrivileged) {
      $attachmentForwardBranchMeta = null;
      if ($attachmentBranchId > 0 && workflow_attachment_forwarding_enabled($conn)) {
        $meta = workflow_get_branch_attachment_forward_task_meta($conn, $docId, $attachmentBranchId, $userId);
        if ((int)($meta['attachment_forward_can_attach'] ?? 0) === 1) {
          $attachmentForwardBranchMeta = $meta;
        }
      }

      if ($attachmentForwardBranchMeta !== null) {
        // Allowed: recipient already received the attachment-forward lane and may append files.
      } else {
        $actionableBranch = workflow_find_single_actionable_branch($conn, $docId, $userId, $attachmentBranchId > 0 ? $attachmentBranchId : null);
        if (!$actionableBranch) {
          $stmt = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM document_branches
            WHERE document_id = ?
              AND branch_status = 'ACTIVE'
              AND current_assignee_user_id = ?
              AND is_reference = 0
          ");
          $stmt->bind_param("ii", $docId, $userId);
          $stmt->execute();
          $countAssigned = (int)($stmt->get_result()->fetch_assoc()["c"] ?? 0);

          $conn->rollback();
          http_response_code(409);
          echo json_encode([
            "ok" => false,
            "error" => $countAssigned > 1
              ? "Multiple active branches are assigned to you for this document. Select the correct branch before attaching a file."
              : "You can only attach files in your active received branch.",
          ]);
          exit;
        }

        $attachmentBranchId = (int)($actionableBranch["id"] ?? 0);
      }
    }
  } else {
    if (!$isPrivileged) {
      $flatAttachmentTaskMeta = workflow_attachment_forwarding_enabled($conn)
        ? workflow_get_document_attachment_forward_task_meta($conn, $docId, $userId)
        : null;
      $canAttachViaAttachmentTask = ((int)($flatAttachmentTaskMeta['attachment_forward_can_attach'] ?? 0) === 1);

      if (!$canAttachViaAttachmentTask) {
        if ($mySectionId <= 0 || $holderSectionId <= 0 || $holderSectionId !== $mySectionId) {
          $conn->rollback();
          http_response_code(403);
          echo json_encode(["ok" => false, "error" => "Forbidden: your section does not hold this document."]);
          exit;
        }
        // If somehow still in transit, holder should not be attaching (prevents weirdness)
        if ($hasOpenRoute) {
          $conn->rollback();
          http_response_code(409);
          echo json_encode(["ok" => false, "error" => "Cannot attach while document is in transit."]);
          exit;
        }
      }
    }
  }

  $f = $_FILES["file"];
  if (!is_array($f)) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Missing file"]);
    exit;
  }

  try {
    $validated = attachment_validate_uploaded_file($f);
  } catch (RuntimeException $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => $e->getMessage()]);
    exit;
  }

  $origName = (string)$validated["original_name"];
  $size = (int)$validated["size_bytes"];
  $ext = (string)$validated["extension"];
  $tmp = (string)$validated["tmp_path"];
  $mime = (string)$validated["mime"];

  // Storage: /storage/attachments/doc_{id}/...
  $baseDir = attachments_base_dir();
  $docDir = ensure_storage_dir($baseDir . "/doc_" . $docId);

  $stamp = date("Ymd_His");
  $rand = bin2hex(random_bytes(6));
  $storedName = $stamp . "_u" . $userId . "_" . $rand . "." . $ext;
  $absPath = $docDir . "/" . $storedName;

  if (!move_uploaded_file($tmp, $absPath)) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Failed to store file"]);
    exit;
  }

  // Store relative path (no secrets)
  $relPath = "storage/attachments/doc_" . $docId . "/" . $storedName;

  $uploadedBySectionId = $mySectionId > 0 ? $mySectionId : null;

  if ($branchAttachmentScopeEnabled) {
    $stmt = $conn->prepare("
      INSERT INTO document_attachments
        (document_id, branch_id, original_name, stored_name, stored_path, mime, size_bytes, note, is_append, uploaded_by_user_id, uploaded_by_section_id)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $secId = $uploadedBySectionId;
    $branchIdToStore = $attachmentBranchId > 0 ? $attachmentBranchId : null;
    $stmt->bind_param(
      "iissssisiii",
      $docId,
      $branchIdToStore,
      $origName,
      $storedName,
      $relPath,
      $mime,
      $size,
      $note,
      $isAppend,
      $actualUserId,
      $secId
    );
    $stmt->execute();
  } else {
    $stmt = $conn->prepare("
      INSERT INTO document_attachments
        (document_id, original_name, stored_name, stored_path, mime, size_bytes, note, is_append, uploaded_by_user_id, uploaded_by_section_id)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $secId = $uploadedBySectionId;
    $stmt->bind_param(
      "issssisiii",
      $docId,
      $origName,
      $storedName,
      $relPath,
      $mime,
      $size,
      $note,
      $isAppend,
      $actualUserId,
      $secId
    );
    $stmt->execute();
  }

  $attachId = (int)$conn->insert_id;

  // Bump documents.updated_at (for stuck detection)
  $stmt = $conn->prepare("UPDATE documents SET updated_at = NOW() WHERE id = ?");
  $stmt->bind_param("i", $docId);
  $stmt->execute();

  // Add audit row WITHOUT introducing a new ENUM value.
  // We store event_type = 'updated' and tag payload kind.
  $payload = json_encode([
    "kind" => "attachment_added",
    "attachment_id" => $attachId,
    "file" => $origName,
    "is_append" => $isAppend,
    "note" => $note,
    "branch_id" => $attachmentBranchId > 0 ? $attachmentBranchId : null,
    "route_id" => $routeIdReq > 0 ? $routeIdReq : null,
    "acting_principal_user_id" => ($userId > 0 && $userId !== $actualUserId) ? $userId : null,
    "acting_principal_name" => ($userId > 0 && $userId !== $actualUserId) ? (string)($identity['acting_principal_name'] ?? '') : '',
    "acting_label" => ($userId > 0 && $userId !== $actualUserId) ? (string)($identity['acting_label'] ?? '') : '',
  ], JSON_UNESCAPED_UNICODE);

  $actorSectionId = $mySectionId > 0 ? $mySectionId : null;

  $stmt = $conn->prepare("
    INSERT INTO document_events
      (document_id, event_type, actor_user_id, actor_section_id, payload_json)
    VALUES
      (?, 'updated', ?, ?, ?)
  ");
  $stmt->bind_param("iiis", $docId, $actualUserId, $actorSectionId, $payload);
  $stmt->execute();

  $conn->commit();

  echo json_encode(["ok" => true, "attachment_id" => $attachId]);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error"]);
  exit;
}
