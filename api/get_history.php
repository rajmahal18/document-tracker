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

    $eventType = (string)($r["event_type"] ?? "updated");
    $eventKey  = strtolower(trim($eventType));
    if ($eventKey === "") $eventKey = "updated";

    $actor = (string)($r["actor"] ?? "—");
    $from  = (string)($r["from_section"] ?? "");
    $to    = (string)($r["to_section"] ?? "");

    // 🔥 Derive FORWARDED from movement when event_type is generic
    if ($eventKey === "updated" && $from !== "" && $to !== "") {
      $eventKey = "forwarded";
    }


    $actor = (string)($r["actor"] ?? "—");
    $from  = (string)($r["from_section"] ?? "");
    $to    = (string)($r["to_section"] ?? "");

    $title = "";
    $meta  = "";

    // Human-readable sentence per event
    switch ($eventKey) {
      case "created":
        $title = "{$actor} created the document";
        break;

      case "sent":
        // initial forward on creation usually lands here
        $title = "{$actor} sent the document";
        break;

      case "forwarded":
        $title = "{$actor} forwarded the document";
        break;

      case "received":
        $title = "{$actor} received the document";
        break;

      case "released":
        $title = "{$actor} released the document";
        break;

      case "archived":
        $title = "{$actor} archived the document";
        break;

      case "cancelled":
        $title = "{$actor} cancelled the route";
        break;

      case "status_changed":
        $title = "{$actor} changed the status";
        break;

      default:
        $title = "{$actor} updated the document";
        break;
    }

    // Movement meta line (only if may from/to)
    if ($from !== "" || $to !== "") {
      if ($from !== "" && $to !== "") {
        $meta = "{$from} → {$to}";
      } elseif ($to !== "") {
        $meta = "To: {$to}";
      } else {
        $meta = "From: {$from}";
      }
    }


    $history[] = [
      "action" => $eventKey,
      "event_type" => $eventType,

      // ✅ new fields for UI text
      "title" => $title,
      "meta" => $meta,

      "remarks" => (string)($payload["remarks"] ?? ""),
      "acted_at" => $r["created_at"],
      "actor" => $actor,
      "from_section" => $from,
      "to_section" => $to,
    ];

  }

  echo json_encode(["ok" => true, "history" => $history]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error"]);
  exit;
}
