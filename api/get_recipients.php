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
  if (!can_view_document($conn, $docId)) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Forbidden"]);
    exit;
  }

  $branchMode = workflow_branch_mode_enabled($conn);
  $docHasRealBranches = ($branchMode && workflow_document_has_real_branches($conn, $docId));
  if ($docHasRealBranches) {
    $stmt = $conn->prepare("\n      SELECT\n        r.id AS route_id,\n        r.branch_id,\n        b.branch_label,\n        r.to_section_id,\n        s.name AS to_section_name,\n        r.to_user_id,\n        u.full_name AS to_user_name,\n        r.sent_at\n      FROM routes r\n      LEFT JOIN document_branches b ON b.id = r.branch_id\n      LEFT JOIN sections s ON s.id = r.to_section_id\n      LEFT JOIN users u ON u.id = r.to_user_id\n      WHERE r.document_id = ?\n        AND r.received_at IS NULL\n        AND r.cancelled_at IS NULL\n        AND r.route_kind = 'ACTION'\n      ORDER BY r.sent_at DESC, r.id DESC\n    ");
  } else {
    $stmt = $conn->prepare("\n      SELECT\n        r.id AS route_id,\n        NULL AS branch_id,\n        NULL AS branch_label,\n        r.to_section_id,\n        s.name AS to_section_name,\n        r.to_user_id,\n        u.full_name AS to_user_name,\n        r.sent_at\n      FROM routes r\n      LEFT JOIN sections s ON s.id = r.to_section_id\n      LEFT JOIN users u ON u.id = r.to_user_id\n      WHERE r.document_id = ?\n        AND r.received_at IS NULL\n        AND r.cancelled_at IS NULL\n      ORDER BY r.sent_at DESC, r.id DESC\n    ");
  }
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  echo json_encode([
    "ok" => true,
    "document_id" => $docId,
    "branch_mode" => $docHasRealBranches,
    "count" => count($rows),
    "recipients" => array_map(static function (array $r): array {
      return [
        "route_id" => (int)($r["route_id"] ?? 0),
        "branch_id" => ($r["branch_id"] !== null ? (int)$r["branch_id"] : null),
        "branch_label" => (string)($r["branch_label"] ?? ""),
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
  echo json_encode(["ok" => false, "error" => "Server error", "debug" => $e->getMessage()]);
  exit;
}
