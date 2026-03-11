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
$toSectionId = (int)($_POST["to_section_id"] ?? 0);
$toUserId    = (int)($_POST["to_user_id"] ?? 0);
$branchIdReq = (int)($_POST["branch_id"] ?? 0);
$receiveOnly = ((int)($_POST["receive_only"] ?? 0) === 1);
$remarks     = trim((string)($_POST["remarks"] ?? "none"));
if ($remarks === "") {
  $remarks = "none";
}

$toUserIds = $_POST["to_user_ids"] ?? [];
if (!is_array($toUserIds)) $toUserIds = [];
$toUserIds = array_values(array_unique(array_filter(array_map(
  static fn($v) => (int)$v,
  $toUserIds
), static fn($n) => $n > 0)));

$sendBatchId = bin2hex(random_bytes(16));

if ($docId <= 0 || $toSectionId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad request"]);
  exit;
}

$mySectionId = (int)($_SESSION["section_id"] ?? 0);
$userId      = (int)($_SESSION["user_id"] ?? 0);
if ($mySectionId <= 0 || $userId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing session assignment"]);
  exit;
}

$recipients = [];
if (count($toUserIds) > 0) {
  $recipients = $toUserIds;
} elseif ($toUserId > 0) {
  $recipients = [$toUserId];
}

if (count($recipients) === 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Please select at least one recipient user."]);
  exit;
}

try {
  $conn->begin_transaction();

  $stmt = $conn->prepare("SELECT id FROM sections WHERE id = ? LIMIT 1");
  $stmt->bind_param("i", $toSectionId);
  $stmt->execute();
  $sec = $stmt->get_result()->fetch_assoc();
  if (!$sec) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Invalid destination section"]);
    exit;
  }

  $placeholders = implode(",", array_fill(0, count($recipients), "?"));
  $types = "i" . str_repeat("i", count($recipients));
  $params = array_merge([$toSectionId], $recipients);

  $sql = "
    SELECT id, full_name
    FROM users
    WHERE section_id = ?
      AND is_active = 1
      AND id IN ($placeholders)
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();

  $found = [];
  $recipientInfo = [];
  while ($r = $res->fetch_assoc()) {
    $rid = (int)$r["id"];
    $found[] = $rid;
    $recipientInfo[$rid] = (string)($r["full_name"] ?? ("User #" . $rid));
  }

  $recipientNames = [];
foreach ($recipients as $rid) {
  $rid = (int)$rid;
  if (isset($recipientInfo[$rid])) {
    $recipientNames[] = (string)$recipientInfo[$rid];
  }
}

$fromUserName = (string)($_SESSION["full_name"] ?? $_SESSION["name"] ?? "");
if ($fromUserName === "") {
  $stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
  $stmt->bind_param("i", $userId);
  $stmt->execute();
  $fromUserName = (string)($stmt->get_result()->fetch_assoc()["full_name"] ?? ("User #{$userId}"));
}

$toSectionName = "";
$stmt = $conn->prepare("SELECT name FROM sections WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $toSectionId);
$stmt->execute();
$toSectionName = (string)($stmt->get_result()->fetch_assoc()["name"] ?? "");

$fromSectionName = "";
$stmt = $conn->prepare("SELECT name FROM sections WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $mySectionId);
$stmt->execute();
$fromSectionName = (string)($stmt->get_result()->fetch_assoc()["name"] ?? "");

  sort($found);
  $expected = $recipients;
  sort($expected);

  if ($found !== $expected) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "One or more selected users are invalid."]);
    exit;
  }

  if (in_array($userId, $recipients, true)) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "You cannot forward a document to yourself."]);
    exit;
  }

  // Prevent duplicate active inbound responsibility for the same document.
  // Back-and-forth is still allowed later because only OPEN routes are blocked here.
  $dupPlaceholders = implode(",", array_fill(0, count($recipients), "?"));
  $dupTypes = "i" . str_repeat("i", count($recipients));
  $dupParams = array_merge([$docId], $recipients);

  $dupSql = "
    SELECT DISTINCT
      r.to_user_id,
      u.full_name
    FROM routes r
    JOIN users u ON u.id = r.to_user_id
    WHERE r.document_id = ?
      AND r.received_at IS NULL
      AND r.cancelled_at IS NULL
      AND r.to_user_id IN ($dupPlaceholders)
  ";

  $stmtDup = $conn->prepare($dupSql);
  $stmtDup->bind_param($dupTypes, ...$dupParams);
  $stmtDup->execute();
  $dupRows = $stmtDup->get_result()->fetch_all(MYSQLI_ASSOC);

  if (!empty($dupRows)) {
    $dupNames = array_values(array_filter(array_map(
      static fn($row) => trim((string)($row["full_name"] ?? "")),
      $dupRows
    )));
    $dupNames = array_values(array_unique($dupNames));

    $conn->rollback();
    http_response_code(409);
    echo json_encode([
      "ok" => false,
      "error" => count($dupNames) > 0
        ? "Forward blocked. Already has an active incoming copy: " . implode(", ", $dupNames) . "."
        : "Forward blocked. One or more selected users already have an active incoming copy of this document.",
    ]);
    exit;
  }

  $branchMode = workflow_branch_mode_enabled($conn);

  $stmt = $conn->prepare("SELECT id, current_status, current_holder_section_id FROM documents WHERE id = ? LIMIT 1");
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $doc = $stmt->get_result()->fetch_assoc();
  if (!$doc) {
    $conn->rollback();
    http_response_code(404);
    echo json_encode(["ok" => false, "error" => "Document not found"]);
    exit;
  }

  $status = strtoupper((string)($doc["current_status"] ?? "ACTIVE"));
  $holderSectionId = (int)($doc["current_holder_section_id"] ?? 0);

  if ($status !== "ACTIVE") {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Document is not ACTIVE."]);
    exit;
  }

  $sourceBranch = null;
  if ($branchMode) {
    $sourceBranch = workflow_find_single_actionable_branch($conn, $docId, $userId, $branchIdReq > 0 ? $branchIdReq : null);
    if (!$sourceBranch) {
      $stmt = $conn->prepare("\n        SELECT COUNT(*) AS c\n        FROM document_branches\n        WHERE document_id = ?\n          AND branch_status = 'ACTIVE'\n          AND current_assignee_user_id = ?\n          AND is_reference = 0\n      ");
      $stmt->bind_param("ii", $docId, $userId);
      $stmt->execute();
      $countAssigned = (int)($stmt->get_result()->fetch_assoc()["c"] ?? 0);

      $conn->rollback();
      http_response_code(409);
      echo json_encode([
        "ok" => false,
        "error" => $countAssigned > 1
          ? "Multiple active branches are assigned to you for this document. Pass branch_id so the action is unambiguous."
          : "You are not the current actionable user for this document.",
      ]);
      exit;
    }
  } else {
    if ($holderSectionId !== $mySectionId) {
      $conn->rollback();
      http_response_code(403);
      echo json_encode(["ok" => false, "error" => "Your section does not hold this document."]);
      exit;
    }
    if ($toSectionId === $mySectionId) {
      $conn->rollback();
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "You cannot forward a document to your own section."]);
      exit;
    }
  }

  $routeIds = [];
  $newBranchIds = [];

  if ($branchMode) {
    $sourceBranchId = (int)$sourceBranch["id"];

    $stmtRoute = $conn->prepare("\n      INSERT INTO routes\n        (document_id, branch_id, from_section_id, to_section_id, from_user_id, to_user_id, route_kind, send_batch_id, received_at, sent_by_user_id, remarks)\n      VALUES\n        (?, ?, ?, ?, ?, ?, 'ACTION', ?, NULL, ?, ?)\n    ");

    $forceReceiveOnly = $receiveOnly || count($recipients) > 1;

    if (!$forceReceiveOnly && count($recipients) === 1) {
      // Normal single forward keeps the same actionable lane.
      $rid = (int)$recipients[0];

      $stmt = $conn->prepare("\n        UPDATE document_branches\n        SET current_assignee_user_id = ?,\n            current_assignee_section_id = ?,\n            updated_at = NOW()\n        WHERE id = ?\n      ");
      $stmt->bind_param("iii", $rid, $toSectionId, $sourceBranchId);
      $stmt->execute();

      workflow_grant_visibility($conn, $docId, $rid, 'PARTICIPANT', $sourceBranchId, $userId);

      $stmtRoute->bind_param("iiiiiisis", $docId, $sourceBranchId, $mySectionId, $toSectionId, $userId, $rid, $sendBatchId, $userId, $remarks);
      $stmtRoute->execute();
      $routeIds[] = (int)$conn->insert_id;
      $newBranchIds[] = $sourceBranchId;

    } else {
      // Receive-only forward: original branch is completed, recipient branches are reference-only.
      $stmt = $conn->prepare("\n        UPDATE document_branches\n        SET branch_status = 'COMPLETED',\n            current_assignee_user_id = NULL,\n            current_assignee_section_id = NULL,\n            updated_at = NOW()\n        WHERE id = ?\n      ");
      $stmt->bind_param("i", $sourceBranchId);
      $stmt->execute();

      foreach ($recipients as $rid) {
        $rid = (int)$rid;
        $branchLabel = (string)($recipientInfo[$rid] ?? ("User #" . $rid));

        $childBranchId = workflow_create_branch($conn, [
          'document_id' => $docId,
          'parent_branch_id' => $sourceBranchId,
          'branch_label' => $branchLabel,
          'current_assignee_user_id' => $rid,
          'current_assignee_section_id' => $toSectionId,
          'branch_status' => 'ACTIVE',
          'is_reference' => 1,
          'created_by_user_id' => $userId,
        ]);

        workflow_grant_visibility($conn, $docId, $rid, 'PARTICIPANT', $childBranchId, $userId);

        $stmtRoute->bind_param("iiiiiisis", $docId, $childBranchId, $mySectionId, $toSectionId, $userId, $rid, $sendBatchId, $userId, $remarks);
        $stmtRoute->execute();
        $routeIds[] = (int)$conn->insert_id;
        $newBranchIds[] = $childBranchId;
      }
    }
  } else {
    $stmt = $conn->prepare("\n      INSERT INTO routes\n        (document_id, from_section_id, to_section_id, to_user_id, send_batch_id, received_at, sent_by_user_id, remarks)\n      VALUES\n        (?, ?, ?, ?, ?, NULL, ?, ?)\n    ");

    foreach ($recipients as $rid) {
      $rid = (int)$rid;
      $stmt->bind_param("iiiisis", $docId, $holderSectionId, $toSectionId, $rid, $sendBatchId, $userId, $remarks);
      $stmt->execute();
      $routeIds[] = (int)$conn->insert_id;
    }

    $stmt = $conn->prepare("\n      INSERT IGNORE INTO document_participants\n        (document_id, section_id, added_via, added_by_user_id)\n      VALUES (?, ?, 'movement', ?)\n    ");
    $stmt->bind_param("iii", $docId, $toSectionId, $userId);
    $stmt->execute();

    $stmt->bind_param("iii", $docId, $holderSectionId, $userId);
    $stmt->execute();
  }

  $payload = json_encode([
    "remarks" => $remarks,
    "send_batch_id" => $sendBatchId,
    "branch_mode" => $branchMode,
    "receive_only" => $branchMode ? ($receiveOnly || count($recipients) > 1) : false,

    "from_section_name" => $fromSectionName,
    "to_section_name" => $toSectionName,

    "from_user_name" => $fromUserName,

    "to_user_ids" => $recipients,
    "to_user_name" => count($recipientNames) === 1 ? $recipientNames[0] : "",
    "to_user_names" => $recipientNames,
    "recipient_names" => $recipientNames,

    "source_branch_id" => $branchMode ? (int)($sourceBranch["id"] ?? 0) : null,
    "new_branch_ids" => array_values(array_unique(array_filter($newBranchIds))),
  ], JSON_UNESCAPED_UNICODE);

  $stmt = $conn->prepare("\n    INSERT INTO document_events\n      (document_id, event_type, actor_user_id, actor_section_id, from_section_id, to_section_id, payload_json)\n    VALUES (?, 'forwarded', ?, ?, ?, ?, ?)\n  ");

  $fromSectionForEvent = $branchMode ? $mySectionId : $holderSectionId;
  $stmt->bind_param("iiiiis", $docId, $userId, $mySectionId, $fromSectionForEvent, $toSectionId, $payload);
  $stmt->execute();

  $conn->commit();

  echo json_encode([
    "ok" => true,
    "document_id" => $docId,
    "route_ids" => $routeIds,
    "to_user_ids" => $recipients,
    "send_batch_id" => $sendBatchId,
    "branch_mode" => $branchMode,
    "branch_ids" => array_values(array_unique(array_filter($newBranchIds))),
  ]);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error", "debug" => $e->getMessage()]);
  exit;
}
