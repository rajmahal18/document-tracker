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
$remarks     = trim((string)($_POST["remarks"] ?? ""));

if ($docId <= 0 || $toSectionId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad request"]);
  exit;
}

$role        = $_SESSION["role"] ?? "division";
$mySectionId = (int)($_SESSION["section_id"] ?? 0);
$userId      = (int)($_SESSION["user_id"] ?? 0);

if ($mySectionId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing section assignment"]);
  exit;
}

try {
  $conn->begin_transaction();

  // 1) Ensure destination section exists
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

  // 2) Fetch doc: status, holder, and whether it has an open route (IN TRANSIT)
  $stmt = $conn->prepare("
    SELECT
      d.current_status,
      d.current_holder_section_id,
      EXISTS (
        SELECT 1 FROM routes r
        WHERE r.document_id = d.id AND r.is_open = 1
      ) AS has_open_route
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

  $status         = (string)($doc["current_status"] ?? "");
  $holderSectionId = (int)($doc["current_holder_section_id"] ?? 0);
  $hasOpenRoute   = ((int)($doc["has_open_route"] ?? 0) === 1);

  // 3) Prevent forward while IN TRANSIT (already has open route)
  if ($hasOpenRoute) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Cannot forward: document is currently in transit."]);
    exit;
  }

  // 4) Optional but sensible: only forward ACTIVE documents
  // (prevents forwarding released/archived docs)
  if ($status !== "ACTIVE") {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Cannot forward: document is not ACTIVE."]);
    exit;
  }

  // 5) Permission: only current holder can forward
  // (admin override optional; you can loosen later, but this matches your model)
  if ($holderSectionId <= 0 || $holderSectionId !== $mySectionId) {
    $conn->rollback();
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Forbidden: your section does not hold this document."]);
    exit;
  }

  // Prevent forwarding to self (useless loop)
  if ($toSectionId === $holderSectionId) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Destination must be a different section."]);
    exit;
  }

  // 6) Create new route (open route = sent but not received)
  // NOTE: In your system, is_open behaves like "received_at IS NULL" (likely generated),
  // so we do NOT set is_open here; we just insert the route like add_document.php does.
  $stmt = $conn->prepare("
    INSERT INTO routes
        (document_id, from_section_id, to_section_id, is_open, received_at, sent_by_user_id, remarks)
        VALUES (?, ?, ?, 1, NULL, ?, ?)
  ");
  $stmt->bind_param("iiiis", $docId, $holderSectionId, $toSectionId, $userId, $remarks);
  $stmt->execute();

  $routeId = (int)$conn->insert_id;

  // 7) Ensure destination is a participant (visibility forever)
  $stmt = $conn->prepare("
    INSERT IGNORE INTO document_participants
      (document_id, section_id, added_via, added_by_user_id)
    VALUES (?, ?, 'movement', ?)
  ");
  $stmt->bind_param("iii", $docId, $toSectionId, $userId);
  $stmt->execute();

  // Ensure sender is also participant (visibility forever)
    $stmt = $conn->prepare("
    INSERT IGNORE INTO document_participants
        (document_id, section_id, added_via, added_by_user_id)
    VALUES (?, ?, 'movement', ?)
    ");
    $stmt->bind_param("iii", $docId, $holderSectionId, $userId);
    $stmt->execute();


  // 8) Insert event (audit)
  $payload = json_encode([
    "remarks" => $remarks
  ], JSON_UNESCAPED_UNICODE);

  $stmt = $conn->prepare("
    INSERT INTO document_events
      (document_id, event_type, actor_user_id, actor_section_id, from_section_id, to_section_id, payload_json)
    VALUES (?, 'forwarded', ?, ?, ?, ?, ?)
  ");
  $stmt->bind_param("iiiiis", $docId, $userId, $mySectionId, $holderSectionId, $toSectionId, $payload);
  $stmt->execute();

  $conn->commit();

  echo json_encode([
    "ok" => true,
    "document_id" => $docId,
    "route_id" => $routeId,
    "from_section_id" => $holderSectionId,
    "to_section_id" => $toSectionId
  ]);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error"]);
  exit;
}
