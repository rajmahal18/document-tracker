<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

header("Content-Type: application/json");

$docId = (int)($_GET["document_id"] ?? 0);
$requestedBranchId = (int)($_GET["branch_id"] ?? 0);
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

  $scope = attachment_branch_scope_for_document($conn, $docId, $requestedBranchId);
  $selectedBranchId = (int)($scope['selected_branch_id'] ?? 0);
  $isScoped = (($scope['scoped'] ?? false) === true);

  $hasBranchColumn = workflow_branch_attachment_scope_enabled($conn);
  $branchFieldSql = $hasBranchColumn ? 'a.branch_id' : 'NULL AS branch_id';
  $branchLabelSql = $hasBranchColumn ? "COALESCE(b.branch_label, '') AS branch_label" : "'' AS branch_label";
  $branchJoinSql = $hasBranchColumn ? 'LEFT JOIN document_branches b ON b.id = a.branch_id' : '';
  $branchOrderSql = $hasBranchColumn ? 'CASE WHEN a.branch_id IS NULL OR a.branch_id = 0 THEN 0 ELSE 1 END ASC,' : '';

  $whereSql = 'a.document_id = ? AND a.is_deleted = 0';
  $bindTypes = 'i';
  $bindValues = [$docId];

  if ($isScoped) {
    if ($selectedBranchId > 0) {
      $whereSql .= ' AND (a.branch_id IS NULL OR a.branch_id = 0 OR a.branch_id = ?)';
      $bindTypes .= 'i';
      $bindValues[] = $selectedBranchId;
    } else {
      $whereSql .= ' AND (a.branch_id IS NULL OR a.branch_id = 0)';
    }
  }

  $sql = "
    SELECT
      a.id,
      a.document_id,
      a.original_name,
      a.mime,
      a.size_bytes,
      a.note,
      a.is_append,
      a.uploaded_at,
      {$branchFieldSql},
      {$branchLabelSql},
      u.full_name AS uploaded_by,
      s.name AS uploaded_by_section
    FROM document_attachments a
    LEFT JOIN users u ON u.id = a.uploaded_by_user_id
    LEFT JOIN sections s ON s.id = a.uploaded_by_section_id
    {$branchJoinSql}
    WHERE {$whereSql}
    ORDER BY
      CASE
        WHEN a.note = 'AUTO:TRANSMITTAL_MEMO' THEN 0
        WHEN a.note = 'AUTO:PPD_TRACKING_SLIP' THEN 1
        ELSE 2
      END ASC,
      {$branchOrderSql}
      a.uploaded_at DESC,
      a.id DESC
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($bindTypes, ...$bindValues);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  echo json_encode([
    "ok" => true,
    "attachments" => $rows,
    "selected_branch_id" => $selectedBranchId > 0 ? $selectedBranchId : null,
    "branch_scoped" => $isScoped,
  ]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error"]);
  exit;
}
