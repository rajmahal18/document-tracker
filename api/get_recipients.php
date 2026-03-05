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

$role        = (string)($_SESSION["role"] ?? "division");
$mySectionId = (int)($_SESSION["section_id"] ?? 0);

/**
 * Visibility rule:
 * - admin/records: all
 * - others: holder OR pending recipient (open route) OR participant
 */
function can_view_doc(mysqli $conn, int $docId, string $role, int $mySectionId): bool {
  if (in_array($role, ["admin", "records"], true)) return true;
  if ($mySectionId <= 0) return false;

  // NOTE: we treat "open" as (received_at IS NULL AND cancelled_at IS NULL)
  $stmt = $conn->prepare("
    SELECT 1
    FROM documents d
    WHERE d.id = ?
      AND (
        d.current_holder_section_id = ?
        OR EXISTS (
          SELECT 1 FROM routes r
          WHERE r.document_id = d.id
            AND r.received_at IS NULL
            AND r.cancelled_at IS NULL
            AND r.to_section_id = ?
        )
        OR EXISTS (
          SELECT 1 FROM document_participants p
          WHERE p.document_id = d.id AND p.section_id = ?
        )
      )
    LIMIT 1
  ");
  $stmt->bind_param("iiii", $docId, $mySectionId, $mySectionId, $mySectionId);
  $stmt->execute();
  return (bool)$stmt->get_result()->fetch_assoc();
}

try {
  if (!can_view_doc($conn, $docId, $role, $mySectionId)) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Forbidden"]);
    exit;
  }

  $stmt = $conn->prepare("
    SELECT
      r.id AS route_id,
      r.to_section_id,
      s.name AS to_section_name,
      r.to_user_id,
      u.full_name AS to_user_name,
      r.sent_at
    FROM routes r
    LEFT JOIN sections s ON s.id = r.to_section_id
    LEFT JOIN users u ON u.id = r.to_user_id
    WHERE r.document_id = ?
      AND r.received_at IS NULL
      AND r.cancelled_at IS NULL
    ORDER BY r.sent_at DESC, r.id DESC
  ");
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  echo json_encode([
    "ok" => true,
    "document_id" => $docId,
    "count" => count($rows),
    "recipients" => array_map(static function(array $r): array {
      return [
        "route_id" => (int)($r["route_id"] ?? 0),
        "to_section_id" => (int)($r["to_section_id"] ?? 0),
        "to_section_name" => (string)($r["to_section_name"] ?? ""),
        "to_user_id" => ($r["to_user_id"] !== null ? (int)$r["to_user_id"] : null),
        "to_user_name" => (string)($r["to_user_name"] ?? ""),
        "sent_at" => (string)($r["sent_at"] ?? ""),
      ];
    }, $rows),
  ], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error"]);
  exit;
}
