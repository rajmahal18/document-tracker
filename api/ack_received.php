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

$role = $_SESSION["role"] ?? "division";
$mySectionId = (int)($_SESSION["section_id"] ?? 0);
$userId = (int)($_SESSION["user_id"] ?? 0);

// Admin override allowed ONLY if admin has a section assigned (for now).
// This keeps the "no acting without holding" principle sane.
if ($mySectionId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing section assignment (cannot receive)"]);
  exit;
}

try {
  $conn->begin_transaction();

  // 1) Fetch the OPEN route for this document
  $stmt = $conn->prepare("
    SELECT
      r.id AS route_id,
      r.from_section_id,
      r.to_section_id,
      r.is_open,
      d.current_holder_section_id,
      d.current_status
    FROM routes r
    JOIN documents d ON d.id = r.document_id
    WHERE r.document_id = ?
      AND r.is_open = 1
      ORDER BY r.id DESC
    LIMIT 1
  ");
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();

  if (!$row) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "No pending route to receive."]);
    exit;
  }

  $routeId = (int)$row["route_id"];
  $fromSectionId = (int)$row["from_section_id"];
  $toSectionId = (int)$row["to_section_id"];
  $currentHolder = (int)$row["current_holder_section_id"];

  // 2) Permission: ONLY the exact pending recipient section can receive
  if ($toSectionId !== $mySectionId) {
    $conn->rollback();
    http_response_code(403);
    echo json_encode([
      "ok" => false,
      "error" => "Forbidden: this route is addressed to a different section."
    ]);
    exit;
  }

  // Optional sanity check: holder should still be sender while in-transit
  // If it's inconsistent, we still allow receive but it's worth noting later.
  // (We won't block here to avoid deadlocks during migration.)
  // if ($currentHolder !== $fromSectionId) { ... }

  // 3) Close the route (mark received)
  $stmt = $conn->prepare("
    UPDATE routes
    SET is_open = 0,
        received_by_user_id = ?,
        received_at = NOW()
    WHERE id = ? AND is_open = 1
  ");

  $stmt->bind_param("ii", $userId, $routeId);
  $stmt->execute();

  if ($stmt->affected_rows <= 0) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Route already closed."]);
    exit;
  }

  // 4) Update holder -> receiver section (ONLY on receive)
  $stmt = $conn->prepare("
    UPDATE documents
    SET current_holder_section_id = ?
    WHERE id = ?
  ");
  $stmt->bind_param("ii", $toSectionId, $docId);
  $stmt->execute();

  // 5) Ensure receiver is participant (visibility forever)
  $stmt = $conn->prepare("
    INSERT IGNORE INTO document_participants
      (document_id, section_id, added_via, added_by_user_id)
    VALUES (?, ?, 'movement', ?)
  ");
  $stmt->bind_param("iii", $docId, $toSectionId, $userId);
  $stmt->execute();

  // 6) Insert event (audit)
  $payload = json_encode([
    "remarks" => $remarks
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
    "to_section_id" => $toSectionId
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

