<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

header("Content-Type: application/json");

$docId = (int)($_GET["document_id"] ?? 0);
if ($docId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad document_id"]);
  exit;
}

try {
  $stmt = $conn->prepare("
    SELECT
      e.event_type,
      e.created_at,
      e.payload_json,
      u.full_name AS actor,
      s_from.name AS from_section,
      s_to.name AS to_section
    FROM document_events e
    LEFT JOIN users u ON u.id = e.actor_user_id
    LEFT JOIN sections s_from ON s_from.id = e.from_section_id
    LEFT JOIN sections s_to   ON s_to.id = e.to_section_id
    WHERE e.document_id = ?
    ORDER BY e.created_at DESC
    LIMIT 50
  ");
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  // Normalize: expose "remarks" if present in payload_json
  $history = [];
  foreach ($rows as $r) {
    $payload = [];
    if (!empty($r["payload_json"])) {
      $decoded = json_decode((string)$r["payload_json"], true);
      if (is_array($decoded)) $payload = $decoded;
    }

    $history[] = [
      "event_type" => $r["event_type"],
      "remarks" => (string)($payload["remarks"] ?? ""),
      "acted_at" => $r["created_at"],
      "actor" => (string)($r["actor"] ?? "—"),
      "from_section" => (string)($r["from_section"] ?? ""),
      "to_section" => (string)($r["to_section"] ?? ""),
    ];
  }

  echo json_encode(["ok" => true, "history" => $history]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error"]);
  exit;
}
