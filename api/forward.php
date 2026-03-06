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
$remarks     = trim((string)($_POST["remarks"] ?? ""));

// multiple send
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

// Build recipients list
$recipients = [];
if (count($toUserIds) > 0) {
  $recipients = $toUserIds;
} elseif ($toUserId > 0) {
  $recipients = [$toUserId];
}

// ❌ section-only forwarding removed
if (count($recipients) === 0) {
  http_response_code(400);
  echo json_encode([
    "ok" => false,
    "error" => "Please select at least one recipient user."
  ]);
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

  // 2) Fetch document state
  $stmt = $conn->prepare("
    SELECT
      d.current_status,
      d.current_holder_section_id
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

  $status          = (string)$doc["current_status"];
  $holderSectionId = (int)$doc["current_holder_section_id"];

  if ($status !== "ACTIVE") {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Document is not ACTIVE."]);
    exit;
  }

  // Only holder section may forward
  if ($holderSectionId !== $mySectionId) {
    $conn->rollback();
    http_response_code(403);
    echo json_encode([
      "ok" => false,
      "error" => "Your section does not hold this document."
    ]);
    exit;
  }

  if ($toSectionId === $mySectionId) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode([
      "ok" => false,
      "error" => "You cannot forward a document to your own section."
    ]);
    exit;
  }

  if (in_array($userId, $recipients, true)) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode([
      "ok" => false,
      "error" => "You cannot forward a document to yourself."
    ]);
    exit;
  }

  // Validate recipients belong to destination section
  $placeholders = implode(",", array_fill(0, count($recipients), "?"));
  $types = "i" . str_repeat("i", count($recipients));
  $params = array_merge([$toSectionId], $recipients);

  $sql = "
    SELECT id
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
  while ($r = $res->fetch_assoc()) {
    $found[] = (int)$r["id"];
  }

  sort($found);
  $expected = $recipients;
  sort($expected);

  if ($found !== $expected) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode([
      "ok" => false,
      "error" => "One or more selected users are invalid."
    ]);
    exit;
  }

  // 3) Create routes
  $routeIds = [];

  $stmt = $conn->prepare("
    INSERT INTO routes
      (document_id, from_section_id, to_section_id, to_user_id, send_batch_id, received_at, sent_by_user_id, remarks)
    VALUES
      (?, ?, ?, ?, ?, NULL, ?, ?)
  ");

  foreach ($recipients as $rid) {
    $stmt->bind_param(
      "iiiisis",
      $docId,
      $holderSectionId,
      $toSectionId,
      $rid,
      $sendBatchId,
      $userId,
      $remarks
    );
    $stmt->execute();
    $routeIds[] = (int)$conn->insert_id;
  }

  // 4) Ensure participants
  $stmt = $conn->prepare("
    INSERT IGNORE INTO document_participants
      (document_id, section_id, added_via, added_by_user_id)
    VALUES (?, ?, 'movement', ?)
  ");

  $stmt->bind_param("iii", $docId, $toSectionId, $userId);
  $stmt->execute();

  $stmt->bind_param("iii", $docId, $holderSectionId, $userId);
  $stmt->execute();

  // 5) Insert event
  $payload = json_encode([
    "remarks" => $remarks,
    "to_user_ids" => $recipients,
    "send_batch_id" => $sendBatchId
  ], JSON_UNESCAPED_UNICODE);

  $stmt = $conn->prepare("
    INSERT INTO document_events
      (document_id, event_type, actor_user_id, actor_section_id, from_section_id, to_section_id, payload_json)
    VALUES (?, 'forwarded', ?, ?, ?, ?, ?)
  ");

  $stmt->bind_param(
    "iiiiis",
    $docId,
    $userId,
    $mySectionId,
    $holderSectionId,
    $toSectionId,
    $payload
  );
  $stmt->execute();

  $conn->commit();

  echo json_encode([
    "ok" => true,
    "document_id" => $docId,
    "route_ids" => $routeIds,
    "to_user_ids" => $recipients,
    "send_batch_id" => $sendBatchId
  ]);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error"]);
  exit;
}