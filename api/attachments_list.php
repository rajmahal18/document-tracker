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

  $stmt = $conn->prepare("
    SELECT 1
    FROM documents d
    WHERE d.id = ?
      AND (
        d.current_holder_section_id = ?
        OR EXISTS (
          SELECT 1 FROM routes r
          WHERE r.document_id = d.id AND r.is_open = 1 AND r.to_section_id = ?
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
      a.id,
      a.document_id,
      a.original_name,
      a.mime,
      a.size_bytes,
      a.note,
      a.is_append,
      a.uploaded_at,
      u.full_name AS uploaded_by,
      s.name AS uploaded_by_section
    FROM document_attachments a
    LEFT JOIN users u ON u.id = a.uploaded_by_user_id
    LEFT JOIN sections s ON s.id = a.uploaded_by_section_id
    WHERE a.document_id = ?
      AND a.is_deleted = 0
    ORDER BY
      CASE
        WHEN a.note = 'AUTO:TRANSMITTAL_MEMO' THEN 0
        WHEN a.note = 'AUTO:PPD_TRACKING_SLIP' THEN 1
        ELSE 2
      END ASC,
      a.uploaded_at DESC,
      a.id DESC
  ");
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  echo json_encode(["ok" => true, "attachments" => $rows]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error"]);
  exit;
}
