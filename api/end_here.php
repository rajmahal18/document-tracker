<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();
require_once __DIR__ . "/../core/working_time.php";

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "Method not allowed"]);
  exit;
}

require_csrf();

$docId = (int)($_POST["document_id"] ?? 0);
$branchIdReq = (int)($_POST["branch_id"] ?? 0);
$mode = strtolower(trim((string)($_POST["mode"] ?? "end")));
$remarks = trim((string)($_POST["remarks"] ?? ""));

if ($docId <= 0 || !in_array($mode, ["end", "undo"], true)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad request"]);
  exit;
}

$identity = effective_document_identity($conn);
$actualUserId = (int)($identity["actual_user_id"] ?? 0);
$principalUserId = (int)($identity["effective_user_id"] ?? 0);
$mySectionId = (int)($identity["effective_section_id"] ?? 0);
$isChief = (bool)($identity["effective_is_chief"] ?? false);

if ($actualUserId <= 0 || $principalUserId <= 0 || $mySectionId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing session assignment"]);
  exit;
}

$eventRemarks = "";
if ($remarks !== "" && strcasecmp($remarks, "none") !== 0) {
  $eventRemarks = $remarks;
}

function get_elapsed_working_minutes($conn, $docId, $userId) {
  $stmt = $conn->prepare("SELECT received_at FROM routes WHERE document_id = ? AND received_by_user_id = ? AND received_at IS NOT NULL AND cancelled_at IS NULL ORDER BY received_at DESC LIMIT 1");
  $stmt->bind_param("ii", $docId, $userId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  if ($row && !empty($row['received_at'])) {
    return dt_working_minutes_between($row['received_at'], null, $conn);
  }
  $stmt = $conn->prepare("SELECT created_at FROM documents WHERE id = ? LIMIT 1");
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $startRaw = $stmt->get_result()->fetch_assoc()['created_at'] ?? null;
  return $startRaw ? dt_working_minutes_between($startRaw, null, $conn) : 0;
}

try {
  $conn->begin_transaction();

  $stmt = $conn->prepare("
    SELECT id, current_status, current_holder_section_id
    FROM documents
    WHERE id = ?
    LIMIT 1
    FOR UPDATE
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

  $oldStatus = strtoupper((string)($doc["current_status"] ?? "ACTIVE"));
  $branchMode = workflow_branch_mode_enabled($conn);
  $docHasRealBranches = ($branchMode && workflow_document_has_real_branches($conn, $docId));

  if (workflow_document_has_open_attachment_forward_tasks($conn, $docId)) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Cannot end this workflow while there are pending attachment-forward tasks."]);
    exit;
  }

  if ($docHasRealBranches) {
    if ($branchIdReq <= 0) {
      $conn->rollback();
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "Please select the lane to end."]);
      exit;
    }

    if ($mode === "end") {
      if ($oldStatus !== "ACTIVE") {
        $conn->rollback();
        http_response_code(409);
        echo json_encode(["ok" => false, "error" => "Only ACTIVE documents can be ended here."]);
        exit;
      }

      $branch = workflow_find_single_actionable_branch($conn, $docId, $principalUserId, $branchIdReq);
      if (!$branch) {
        $conn->rollback();
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "You can only end a lane that is currently with you."]);
        exit;
      }

      $branchId = (int)$branch["id"];

      $stmt = $conn->prepare("
        UPDATE document_branches
        SET branch_status = 'COMPLETED',
            current_assignee_user_id = NULL,
            current_assignee_section_id = NULL,
            completed_by_user_id = ?,
            updated_at = NOW()
        WHERE id = ?
          AND document_id = ?
          AND branch_status = 'ACTIVE'
          AND current_assignee_user_id = ?
          AND is_reference = 0
      ");
      $stmt->bind_param("iiii", $principalUserId, $branchId, $docId, $principalUserId);
      $stmt->execute();

      if ($stmt->affected_rows <= 0) {
        $conn->rollback();
        http_response_code(409);
        echo json_encode(["ok" => false, "error" => "This lane is no longer actionable."]);
        exit;
      }

      $stmt = $conn->prepare("
        UPDATE documents
        SET current_holder_section_id = ?,
            updated_at = NOW()
        WHERE id = ?
      ");
      $stmt->bind_param("ii", $mySectionId, $docId);
      $stmt->execute();

      $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM document_branches
        WHERE document_id = ?
          AND branch_status = 'ACTIVE'
          AND is_reference = 0
      ");
      $stmt->bind_param("i", $docId);
      $stmt->execute();
      $activeActionBranches = (int)($stmt->get_result()->fetch_assoc()["c"] ?? 0);

      $newStatus = $oldStatus;
      $documentCompleted = ($activeActionBranches === 0);
      if ($documentCompleted) {
        $newStatus = "RELEASED";
        $stmt = $conn->prepare("
          UPDATE documents
          SET current_status = 'RELEASED',
              updated_at = NOW()
          WHERE id = ?
        ");
        $stmt->bind_param("i", $docId);
        $stmt->execute();
      }

      $payload = json_encode([
        "kind" => "branch_ended_here",
        "remarks" => $eventRemarks,
        "branch_id" => $branchId,
        "old_status" => $oldStatus,
        "new_status" => $newStatus,
        "document_completed" => $documentCompleted,
        "active_action_branches_remaining" => $activeActionBranches,
        "elapsed_working_minutes" => get_elapsed_working_minutes($conn, $docId, $actualUserId),
        "acting_principal_user_id" => ($principalUserId !== $actualUserId) ? $principalUserId : null,
        "acting_principal_name" => ($principalUserId !== $actualUserId) ? (string)($identity["acting_principal_name"] ?? "") : "",
        "acting_label" => ($principalUserId !== $actualUserId) ? (string)($identity["acting_label"] ?? "") : "",
      ], JSON_UNESCAPED_UNICODE);

      $stmt = $conn->prepare("
        INSERT INTO document_events
          (document_id, event_type, actor_user_id, actor_section_id, payload_json)
        VALUES (?, 'updated', ?, ?, ?)
      ");
      $stmt->bind_param("iiis", $docId, $actualUserId, $mySectionId, $payload);
      $stmt->execute();

      $conn->commit();
      echo json_encode([
        "ok" => true,
        "document_id" => $docId,
        "branch_id" => $branchId,
        "mode" => "end",
        "document_completed" => $documentCompleted,
        "new_status" => $newStatus,
      ]);
      exit;
    }

    $stmt = $conn->prepare("
      SELECT id, branch_status, completed_by_user_id
      FROM document_branches
      WHERE id = ?
        AND document_id = ?
        AND branch_status = 'COMPLETED'
        AND is_reference = 0
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->bind_param("ii", $branchIdReq, $docId);
    $stmt->execute();
    $branch = $stmt->get_result()->fetch_assoc();

    if (!$branch || (int)($branch["completed_by_user_id"] ?? 0) !== $principalUserId) {
      $conn->rollback();
      http_response_code(403);
      echo json_encode(["ok" => false, "error" => "You can only reopen an End Now action that you made."]);
      exit;
    }

    if ($oldStatus === "ARCHIVED") {
      $conn->rollback();
      http_response_code(409);
      echo json_encode(["ok" => false, "error" => "Archived documents cannot be reopened here."]);
      exit;
    }

    $branchId = (int)$branch["id"];
    $stmt = $conn->prepare("
      SELECT COUNT(*) AS c
      FROM routes
      WHERE branch_id = ?
        AND route_kind = 'ACTION'
        AND received_at IS NULL
        AND cancelled_at IS NULL
    ");
    $stmt->bind_param("i", $branchId);
    $stmt->execute();
    $openRoutes = (int)($stmt->get_result()->fetch_assoc()["c"] ?? 0);
    if ($openRoutes > 0) {
      $conn->rollback();
      http_response_code(409);
      echo json_encode(["ok" => false, "error" => "This lane has a pending route and cannot be reopened here."]);
      exit;
    }

    $stmt = $conn->prepare("
      UPDATE document_branches
      SET branch_status = 'ACTIVE',
          current_assignee_user_id = ?,
          current_assignee_section_id = ?,
          completed_by_user_id = NULL,
          updated_at = NOW()
      WHERE id = ?
        AND document_id = ?
        AND branch_status = 'COMPLETED'
    ");
    $stmt->bind_param("iiii", $principalUserId, $mySectionId, $branchId, $docId);
    $stmt->execute();

    $stmt = $conn->prepare("
      UPDATE documents
      SET current_status = 'ACTIVE',
          current_holder_section_id = ?,
          updated_at = NOW()
      WHERE id = ?
    ");
    $stmt->bind_param("ii", $mySectionId, $docId);
    $stmt->execute();

    $payload = json_encode([
      "kind" => "branch_end_here_undone",
      "remarks" => $eventRemarks,
      "branch_id" => $branchId,
      "old_status" => $oldStatus,
      "new_status" => "ACTIVE",
      "acting_principal_user_id" => ($principalUserId !== $actualUserId) ? $principalUserId : null,
      "acting_principal_name" => ($principalUserId !== $actualUserId) ? (string)($identity["acting_principal_name"] ?? "") : "",
      "acting_label" => ($principalUserId !== $actualUserId) ? (string)($identity["acting_label"] ?? "") : "",
    ], JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare("
      INSERT INTO document_events
        (document_id, event_type, actor_user_id, actor_section_id, payload_json)
      VALUES (?, 'updated', ?, ?, ?)
    ");
    $stmt->bind_param("iiis", $docId, $actualUserId, $mySectionId, $payload);
    $stmt->execute();

    $conn->commit();
    echo json_encode([
      "ok" => true,
      "document_id" => $docId,
      "branch_id" => $branchId,
      "mode" => "undo",
      "new_status" => "ACTIVE",
    ]);
    exit;
  }

  if ($mode === "end") {
    if (!workflow_user_can_act_legacy_document($conn, $docId, $principalUserId, $mySectionId, $isChief, false)) {
      $conn->rollback();
      http_response_code(403);
      echo json_encode(["ok" => false, "error" => "You can only end a document that is currently with you."]);
      exit;
    }

    $stmt = $conn->prepare("
      UPDATE documents
      SET current_status = 'RELEASED',
          current_holder_section_id = ?,
          updated_at = NOW()
      WHERE id = ?
        AND current_status = 'ACTIVE'
    ");
    $stmt->bind_param("ii", $mySectionId, $docId);
    $stmt->execute();

    $payload = json_encode([
      "kind" => "document_ended_here",
      "remarks" => $eventRemarks,
      "old_status" => $oldStatus,
      "new_status" => "RELEASED",
      "document_completed" => true,
      "elapsed_working_minutes" => get_elapsed_working_minutes($conn, $docId, $actualUserId),
      "acting_principal_user_id" => ($principalUserId !== $actualUserId) ? $principalUserId : null,
      "acting_principal_name" => ($principalUserId !== $actualUserId) ? (string)($identity["acting_principal_name"] ?? "") : "",
      "acting_label" => ($principalUserId !== $actualUserId) ? (string)($identity["acting_label"] ?? "") : "",
    ], JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare("
      INSERT INTO document_events
        (document_id, event_type, actor_user_id, actor_section_id, payload_json)
      VALUES (?, 'updated', ?, ?, ?)
    ");
    $stmt->bind_param("iiis", $docId, $actualUserId, $mySectionId, $payload);
    $stmt->execute();

    $conn->commit();
    echo json_encode(["ok" => true, "document_id" => $docId, "mode" => "end", "new_status" => "RELEASED"]);
    exit;
  }

  if ($oldStatus !== "RELEASED" || !workflow_user_can_act_legacy_document($conn, $docId, $principalUserId, $mySectionId, $isChief, true)) {
    $conn->rollback();
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "You can only reopen End Now while the document is still ended by your final action."]);
    exit;
  }

  $stmt = $conn->prepare("
    SELECT payload_json
    FROM document_events
    WHERE document_id = ?
      AND event_type = 'updated'
      AND payload_json LIKE '%document_%here%'
    ORDER BY created_at DESC, id DESC
    LIMIT 1
  ");
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $lastPayloadRaw = (string)($stmt->get_result()->fetch_assoc()["payload_json"] ?? "");
  $lastPayload = $lastPayloadRaw !== "" ? json_decode($lastPayloadRaw, true) : null;
  $lastKind = is_array($lastPayload) ? (string)($lastPayload["kind"] ?? "") : "";

  if ($lastKind !== "document_ended_here") {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "The latest lifecycle action was not End Now."]);
    exit;
  }

  $stmt = $conn->prepare("
    UPDATE documents
    SET current_status = 'ACTIVE',
        current_holder_section_id = ?,
        updated_at = NOW()
    WHERE id = ?
  ");
  $stmt->bind_param("ii", $mySectionId, $docId);
  $stmt->execute();

  $payload = json_encode([
    "kind" => "document_end_here_undone",
    "remarks" => $eventRemarks,
    "old_status" => $oldStatus,
    "new_status" => "ACTIVE",
    "acting_principal_user_id" => ($principalUserId !== $actualUserId) ? $principalUserId : null,
    "acting_principal_name" => ($principalUserId !== $actualUserId) ? (string)($identity["acting_principal_name"] ?? "") : "",
    "acting_label" => ($principalUserId !== $actualUserId) ? (string)($identity["acting_label"] ?? "") : "",
  ], JSON_UNESCAPED_UNICODE);

  $stmt = $conn->prepare("
    INSERT INTO document_events
      (document_id, event_type, actor_user_id, actor_section_id, payload_json)
    VALUES (?, 'updated', ?, ?, ?)
  ");
  $stmt->bind_param("iiis", $docId, $actualUserId, $mySectionId, $payload);
  $stmt->execute();

  $conn->commit();
  echo json_encode(["ok" => true, "document_id" => $docId, "mode" => "undo", "new_status" => "ACTIVE"]);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error", "debug" => $e->getMessage()]);
  exit;
}
