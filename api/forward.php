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

// multiple send (optional)
$toUserIds = $_POST["to_user_ids"] ?? [];
if (!is_array($toUserIds)) $toUserIds = [];
$toUserIds = array_values(array_unique(array_filter(array_map(
  static fn($v) => (int)$v,
  $toUserIds
), static fn($n) => $n > 0)));

$sendBatchId = bin2hex(random_bytes(16)); // 32-char hex

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

// Decide recipients list:
// - If to_user_ids[] present => multiple send
// - Else if to_user_id present => single send
// - Else => section-only forward (legacy)
$recipients = [];
if (count($toUserIds) > 0) {
  $recipients = $toUserIds;
} elseif ($toUserId > 0) {
  $recipients = [$toUserId];
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
  // Use received_at/cancelled_at instead of is_open to be bulletproof.
  $stmt = $conn->prepare("
    SELECT
      d.current_status,
      d.current_holder_section_id,
      EXISTS (
        SELECT 1 FROM routes r
        WHERE r.document_id = d.id
          AND r.received_at IS NULL
          AND r.cancelled_at IS NULL
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

  $status          = (string)($doc["current_status"] ?? "");
  $holderSectionId = (int)($doc["current_holder_section_id"] ?? 0);
  $hasOpenRoute    = ((int)($doc["has_open_route"] ?? 0) === 1);

  // 3) Prevent forward while IN TRANSIT
  if ($hasOpenRoute) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Cannot forward: document is currently in transit."]);
    exit;
  }

  // 4) Only forward ACTIVE docs
  if ($status !== "ACTIVE") {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Cannot forward: document is not ACTIVE."]);
    exit;
  }

  // 5) Permission: only current holder can forward
  if ($holderSectionId <= 0 || $holderSectionId !== $mySectionId) {
    $conn->rollback();
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Forbidden: your section does not hold this document."]);
    exit;
  }

  // ✅ NEW RULE:
  // Same-section is allowed ONLY if you selected at least 1 recipient user.
  // If no user recipients (legacy section-only), same-section is blocked.
  if ($toSectionId === $holderSectionId && count($recipients) === 0) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Destination must be a different section."]);
    exit;
  }

  // 6) Validate recipients (if any): must belong to destination section and be active
  if (count($recipients) > 0) {
    $placeholders = implode(",", array_fill(0, count($recipients), "?"));
    $types = "i" . str_repeat("i", count($recipients)); // section_id + each user_id
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
    while ($row = $res->fetch_assoc()) $found[] = (int)$row["id"];

    sort($found);
    $expected = $recipients;
    sort($expected);

    if ($found !== $expected) {
      $conn->rollback();
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "One or more selected users are invalid/inactive for the selected section."]);
      exit;
    }
  }

  // 7) Create route(s)
  // IMPORTANT: do NOT insert is_open (generated/derived). Open route = received_at NULL & cancelled_at NULL.
  $routeIds = [];

  if (count($recipients) === 0) {
    // legacy section-only forward
    $stmt = $conn->prepare("
      INSERT INTO routes
        (document_id, from_section_id, to_section_id, to_user_id, send_batch_id, received_at, sent_by_user_id, remarks)
      VALUES
        (?, ?, ?, NULL, ?, NULL, ?, ?)
    ");
    $stmt->bind_param("iiisis", $docId, $holderSectionId, $toSectionId, $sendBatchId, $userId, $remarks);
    $stmt->execute();
    $routeIds[] = (int)$conn->insert_id;

  } else {
    // single/multiple send (one route per user)
    $stmt = $conn->prepare("
      INSERT INTO routes
        (document_id, from_section_id, to_section_id, to_user_id, send_batch_id, received_at, sent_by_user_id, remarks)
      VALUES
        (?, ?, ?, ?, ?, NULL, ?, ?)
    ");

    foreach ($recipients as $rid) {
      $stmt->bind_param("iiiisis", $docId, $holderSectionId, $toSectionId, $rid, $sendBatchId, $userId, $remarks);
      $stmt->execute();
      $routeIds[] = (int)$conn->insert_id;
    }
  }

  // 8) Ensure destination section is a participant (visibility forever)
  $stmt = $conn->prepare("
    INSERT IGNORE INTO document_participants
      (document_id, section_id, added_via, added_by_user_id)
    VALUES (?, ?, 'movement', ?)
  ");
  $stmt->bind_param("iii", $docId, $toSectionId, $userId);
  $stmt->execute();

  // Ensure sender section is also participant
  $stmt = $conn->prepare("
    INSERT IGNORE INTO document_participants
      (document_id, section_id, added_via, added_by_user_id)
    VALUES (?, ?, 'movement', ?)
  ");
  $stmt->bind_param("iii", $docId, $holderSectionId, $userId);
  $stmt->execute();

  // 9) Insert event (audit)
  $payload = json_encode([
    "remarks" => $remarks,
    "to_user_ids" => (count($recipients) > 0 ? $recipients : null),
    "send_batch_id" => $sendBatchId,
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
    "route_ids" => $routeIds,
    "from_section_id" => $holderSectionId,
    "to_section_id" => $toSectionId,
    "to_user_ids" => (count($recipients) > 0 ? $recipients : null),
    "send_batch_id" => $sendBatchId,
  ]);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error"]);
  exit;
}