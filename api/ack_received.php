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

$docId   = (int)($_POST["document_id"] ?? 0);
$remarks = trim((string)($_POST["remarks"] ?? ""));

if ($docId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad request"]);
  exit;
}

$role        = (string)($_SESSION["role"] ?? "user");
$mySectionId = (int)($_SESSION["section_id"] ?? 0);
$userId      = (int)($_SESSION["user_id"] ?? 0);
$isChief     = ((int)($_SESSION["is_chief"] ?? 0) === 1);
$isAdmin     = ($role === "admin"); // records removed

if ($mySectionId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing section assignment (cannot receive)"]);
  exit;
}

try {
  $conn->begin_transaction();

  /**
   * OPEN route definition:
   * - received_at IS NULL
   * - cancelled_at IS NULL
   *
   * Choose which route THIS user is allowed to receive:
   * 1) If there is an open route specifically to me (to_user_id = my user id), I can receive that.
   * 2) Else, if there is an open "section-only" route (to_user_id IS NULL) addressed to my section,
   *    ONLY the chief can receive that.
   *
   * Admin does NOT bypass recipient targeting—admin can still only receive if they are the recipient
   * (or chief of that section). This keeps the "no acting without holding" principle sane.
   */

  // 1) Try: route addressed to THIS USER
  $stmt = $conn->prepare("
    SELECT
      r.id AS route_id,
      r.from_section_id,
      r.to_section_id,
      r.to_user_id,
      r.send_batch_id,
      d.current_holder_section_id,
      d.current_status
    FROM routes r
    JOIN documents d ON d.id = r.document_id
    WHERE r.document_id = ?
      AND r.received_at IS NULL
      AND r.cancelled_at IS NULL
      AND r.to_user_id = ?
    ORDER BY r.id DESC
    LIMIT 1
  ");
  $stmt->bind_param("ii", $docId, $userId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();

  $receiveMode = ""; // "user" | "section"

  if ($row) {
    $receiveMode = "user";
  } else {
    // 2) Else: section-only route, chief-only
    if (!$isChief) {
      $conn->rollback();
      http_response_code(403);
      echo json_encode([
        "ok" => false,
        "error" => "Forbidden: only the Section Chief can receive section-addressed documents."
      ]);
      exit;
    }

    $stmt = $conn->prepare("
      SELECT
        r.id AS route_id,
        r.from_section_id,
        r.to_section_id,
        r.to_user_id,
        r.send_batch_id,
        d.current_holder_section_id,
        d.current_status
      FROM routes r
      JOIN documents d ON d.id = r.document_id
      WHERE r.document_id = ?
        AND r.received_at IS NULL
        AND r.cancelled_at IS NULL
        AND r.to_section_id = ?
        AND r.to_user_id IS NULL
      ORDER BY r.id DESC
      LIMIT 1
    ");
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

  $routeId       = (int)$row["route_id"];
  $fromSectionId = (int)$row["from_section_id"];
  $toSectionId   = (int)$row["to_section_id"];
  $toUserId      = ($row["to_user_id"] !== null ? (int)$row["to_user_id"] : null);
  $sendBatchId   = trim((string)($row["send_batch_id"] ?? ""));
  $currentHolder = (int)$row["current_holder_section_id"];
  $docStatus     = strtoupper((string)($row["current_status"] ?? "ACTIVE"));

  // Hard sanity checks (avoid cross-section receive)
  if ($toSectionId !== $mySectionId) {
    $conn->rollback();
    http_response_code(403);
    echo json_encode([
      "ok" => false,
      "error" => "Forbidden: this route is addressed to a different section."
    ]);
    exit;
  }

  if ($receiveMode === "user") {
    if ($toUserId === null || $toUserId !== $userId) {
      $conn->rollback();
      http_response_code(403);
      echo json_encode([
        "ok" => false,
        "error" => "Forbidden: this route is addressed to a different user."
      ]);
      exit;
    }
  } else {
    // section mode: must be chief + to_user_id null (strict)
    if (!$isChief || $toUserId !== null) {
      $conn->rollback();
      http_response_code(403);
      echo json_encode([
        "ok" => false,
        "error" => "Forbidden: invalid section-receive route."
      ]);
      exit;
    }
  }

  if ($docStatus !== "ACTIVE") {
    $conn->rollback();
    http_response_code(409);
    echo json_encode([
      "ok" => false,
      "error" => "Cannot receive: document is not ACTIVE."
    ]);
    exit;
  }

  // 3) Close the route (mark received) — DO NOT update is_open (generated in your schema)
  $stmt = $conn->prepare("
    UPDATE routes
    SET received_by_user_id = ?,
        received_at = NOW()
    WHERE id = ?
      AND received_at IS NULL
      AND cancelled_at IS NULL
  ");
  $stmt->bind_param("ii", $userId, $routeId);
  $stmt->execute();

  if ($stmt->affected_rows <= 0) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Route already closed."]);
    exit;
  }

  /**
   * 4) Multi-recipient receive semantics:
   *
   * SAME-SECTION fanout:
   * - If multiple users in the SAME destination section were included in one send batch,
   *   the first valid receive should be enough for that section.
   * - So we auto-cancel sibling open routes in the SAME batch + SAME destination section.
   *
   * CROSS-SECTION fanout:
   * - Other sections keep their own pending routes for now.
   * - To avoid the "everyone waits for the last unread person" problem, the FIRST section
   *   to receive from the current holder claims the holder immediately.
   * - Later sibling receives from the same old batch must NOT steal holder back.
   */
  $cancelledSiblingCount = 0;

  if ($sendBatchId !== "") {
    $stmt = $conn->prepare("
      UPDATE routes
      SET cancelled_by_user_id = ?,
          cancelled_at = NOW()
      WHERE document_id = ?
        AND send_batch_id = ?
        AND to_section_id = ?
        AND id <> ?
        AND received_at IS NULL
        AND cancelled_at IS NULL
    ");
    $stmt->bind_param("iisii", $userId, $docId, $sendBatchId, $toSectionId, $routeId);
    $stmt->execute();
    $cancelledSiblingCount = max(0, (int)$stmt->affected_rows);
  }

  $holderUpdated = false;
  if ($currentHolder === $fromSectionId) {
    $stmt = $conn->prepare("
      UPDATE documents
      SET current_holder_section_id = ?
      WHERE id = ?
        AND current_holder_section_id = ?
    ");
    $stmt->bind_param("iii", $toSectionId, $docId, $fromSectionId);
    $stmt->execute();
    $holderUpdated = ($stmt->affected_rows > 0);
  }

  $stmt = $conn->prepare("
    SELECT COUNT(*) AS c
    FROM routes
    WHERE document_id = ?
      AND received_at IS NULL
      AND cancelled_at IS NULL
  ");
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $cRow = $stmt->get_result()->fetch_assoc();
  $openRemaining = (int)($cRow["c"] ?? 0);

  // 5) Ensure receiver section is participant (legacy visibility helper, harmless)
  $stmt = $conn->prepare("
    INSERT IGNORE INTO document_participants
      (document_id, section_id, added_via, added_by_user_id)
    VALUES (?, ?, 'movement', ?)
  ");
  $stmt->bind_param("iii", $docId, $toSectionId, $userId);
  $stmt->execute();

  // 6) Insert event (audit)
  $payload = json_encode([
    "remarks" => $remarks,
    "receive_mode" => $receiveMode,          // "user" | "section"
    "to_user_id" => $toUserId,               // null if section-only
    "open_remaining_after_receive" => $openRemaining,
    "cancelled_same_section_siblings" => $cancelledSiblingCount,
    "holder_updated" => $holderUpdated
  ], JSON_UNESCAPED_UNICODE);

  $stmt = $conn->prepare("
    INSERT INTO document_events
      (document_id, event_type, actor_user_id, actor_section_id, from_section_id, to_section_id, payload_json)
    VALUES (?, 'received', ?, ?, ?, ?, ?)
  ");
  $stmt->bind_param("iiiiis", $docId, $userId, $mySectionId, $fromSectionId, $toSectionId, $payload);
  $stmt->execute();

  $conn->commit();

  echo json_encode([
    "ok" => true,
    "document_id" => $docId,
    "route_id" => $routeId,
    "from_section_id" => $fromSectionId,
    "to_section_id" => $toSectionId,
    "receive_mode" => $receiveMode,
    "open_remaining" => $openRemaining,
    "cancelled_same_section_siblings" => $cancelledSiblingCount,
    "holder_updated" => $holderUpdated
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