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

$docId    = (int)($_POST["document_id"] ?? 0);
$routeId  = (int)($_POST["route_id"] ?? 0);
$remarks  = trim((string)($_POST["remarks"] ?? ""));

if ($docId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad request"]);
  exit;
}

$role        = (string)($_SESSION["role"] ?? "user");
$mySectionId = (int)($_SESSION["section_id"] ?? 0);
$userId      = (int)($_SESSION["user_id"] ?? 0);
$isChief     = ((int)($_SESSION["is_chief"] ?? 0) === 1);
$isAdmin     = ($role === "admin");

if ($mySectionId <= 0 || $userId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing section assignment (cannot receive)"]);
  exit;
}

try {
  $conn->begin_transaction();

  $branchMode = workflow_branch_mode_enabled($conn);
  $docHasRealBranches = ($branchMode && workflow_document_has_real_branches($conn, $docId));
  $row = null;
  $receiveMode = "user";

  if ($docHasRealBranches) {
    if ($routeId > 0) {
      $stmt = $conn->prepare("\n        SELECT\n          r.id AS route_id,\n          r.branch_id,\n          r.from_section_id,\n          r.to_section_id,\n          r.to_user_id,\n          r.send_batch_id,\n          d.current_status\n        FROM routes r\n        JOIN documents d ON d.id = r.document_id\n        WHERE r.id = ?\n          AND r.document_id = ?\n          AND r.received_at IS NULL\n          AND r.cancelled_at IS NULL\n          AND r.route_kind = 'ACTION'\n          AND r.to_user_id = ?\n        LIMIT 1\n      ");
      $stmt->bind_param("iii", $routeId, $docId, $userId);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
    } else {
      $stmt = $conn->prepare("\n        SELECT\n          r.id AS route_id,\n          r.branch_id,\n          r.from_section_id,\n          r.to_section_id,\n          r.to_user_id,\n          r.send_batch_id,\n          d.current_status\n        FROM routes r\n        JOIN documents d ON d.id = r.document_id\n        WHERE r.document_id = ?\n          AND r.received_at IS NULL\n          AND r.cancelled_at IS NULL\n          AND r.route_kind = 'ACTION'\n          AND r.to_user_id = ?\n        ORDER BY r.id DESC\n        LIMIT 2\n      ");
      $stmt->bind_param("ii", $docId, $userId);
      $stmt->execute();
      $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
      if (count($rows) > 1) {
        $conn->rollback();
        http_response_code(409);
        echo json_encode(["ok" => false, "error" => "Multiple pending routes are addressed to you for this document. Pass route_id to receive the correct branch."]);
        exit;
      }
      $row = $rows[0] ?? null;
    }

    if (!$row) {
      $conn->rollback();
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "No pending route for you to receive."]);
      exit;
    }
  } else {
    $stmt = $conn->prepare("\n      SELECT\n        r.id AS route_id,\n        r.from_section_id,\n        r.to_section_id,\n        r.to_user_id,\n        r.send_batch_id,\n        d.current_holder_section_id,\n        d.current_status\n      FROM routes r\n      JOIN documents d ON d.id = r.document_id\n      WHERE r.document_id = ?\n        AND r.received_at IS NULL\n        AND r.cancelled_at IS NULL\n        AND r.to_user_id = ?\n      ORDER BY r.id DESC\n      LIMIT 1\n    ");
    $stmt->bind_param("ii", $docId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
      if (!$isChief) {
        $conn->rollback();
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "Forbidden: only the Section Chief can receive section-addressed documents."]);
        exit;
      }

      $stmt = $conn->prepare("\n        SELECT\n          r.id AS route_id,\n          r.from_section_id,\n          r.to_section_id,\n          r.to_user_id,\n          r.send_batch_id,\n          d.current_holder_section_id,\n          d.current_status\n        FROM routes r\n        JOIN documents d ON d.id = r.document_id\n        WHERE r.document_id = ?\n          AND r.received_at IS NULL\n          AND r.cancelled_at IS NULL\n          AND r.to_section_id = ?\n          AND r.to_user_id IS NULL\n        ORDER BY r.id DESC\n        LIMIT 1\n      ");
      $stmt->bind_param("ii", $docId, $mySectionId);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
      if ($row) {
        $receiveMode = "section";
      }
    }

    if (!$row) {
      $conn->rollback();
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "No pending route for you to receive."]);
      exit;
    }
  }

  $routeId       = (int)$row["route_id"];
  $branchId      = (int)($row["branch_id"] ?? 0);
  $fromSectionId = (int)$row["from_section_id"];
  $toSectionId   = (int)$row["to_section_id"];
  $toUserId      = ($row["to_user_id"] !== null ? (int)$row["to_user_id"] : null);
  $docStatus     = strtoupper((string)($row["current_status"] ?? "ACTIVE"));

  if ($toSectionId !== $mySectionId) {
    $conn->rollback();
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Forbidden: this route is addressed to a different section."]);
    exit;
  }

  if ($docHasRealBranches && ($toUserId === null || $toUserId !== $userId)) {
    $conn->rollback();
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Forbidden: this route is addressed to a different user."]);
    exit;
  }

  if ($docStatus !== "ACTIVE") {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Cannot receive: document is not ACTIVE."]);
    exit;
  }

  $stmt = $conn->prepare("\n    UPDATE routes\n    SET received_by_user_id = ?,\n        received_at = NOW()\n    WHERE id = ?\n      AND received_at IS NULL\n      AND cancelled_at IS NULL\n  ");
  $stmt->bind_param("ii", $userId, $routeId);
  $stmt->execute();

  if ($stmt->affected_rows <= 0) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Route already closed."]);
    exit;
  }

  $stmt = $conn->prepare("\n    UPDATE documents\n    SET current_holder_section_id = ?,\n        updated_at = NOW()\n    WHERE id = ?\n  ");
  $stmt->bind_param("ii", $toSectionId, $docId);
  $stmt->execute();

  if ($docHasRealBranches && $branchId > 0) {
    workflow_grant_visibility($conn, $docId, $userId, 'PARTICIPANT', $branchId, $userId);
  } else {
    $stmt = $conn->prepare("\n      INSERT IGNORE INTO document_participants\n        (document_id, section_id, added_via, added_by_user_id)\n      VALUES (?, ?, 'movement', ?)\n    ");
    $stmt->bind_param("iii", $docId, $toSectionId, $userId);
    $stmt->execute();
  }

  $stmt = $conn->prepare("\n    SELECT COUNT(*) AS c\n    FROM routes\n    WHERE document_id = ?\n      AND received_at IS NULL\n      AND cancelled_at IS NULL\n  ");
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $openRemaining = (int)($stmt->get_result()->fetch_assoc()["c"] ?? 0);

  $eventRemarks = '';
  if ($remarks !== '' && strcasecmp($remarks, 'none') !== 0) {
    $eventRemarks = $remarks;
  }

  $payload = json_encode([
    "remarks" => $eventRemarks,
    "receive_mode" => $docHasRealBranches ? 'user' : $receiveMode,
    "from_section_id" => $fromSectionId,
    "to_section_id" => $toSectionId,
    "to_user_id" => $toUserId,
    "branch_id" => $branchId > 0 ? $branchId : null,
    "open_remaining_after_receive" => $openRemaining,
  ], JSON_UNESCAPED_UNICODE);

  $stmt = $conn->prepare("\n    INSERT INTO document_events\n      (document_id, event_type, actor_user_id, actor_section_id, from_section_id, to_section_id, payload_json)\n    VALUES (?, 'received', ?, ?, ?, ?, ?)\n  ");
  $stmt->bind_param("iiiiis", $docId, $userId, $mySectionId, $fromSectionId, $toSectionId, $payload);
  $stmt->execute();

  $conn->commit();

  echo json_encode([
    "ok" => true,
    "document_id" => $docId,
    "route_id" => $routeId,
    "branch_id" => $branchId,
    "from_section_id" => $fromSectionId,
    "to_section_id" => $toSectionId,
    "receive_mode" => $docHasRealBranches ? 'user' : $receiveMode,
    "open_remaining" => $openRemaining,
    "holder_updated" => true,
  ]);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "error" => "Server error",
    "debug" => $e->getMessage()
  ]);
  exit;
}
