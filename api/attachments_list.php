<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_once __DIR__ . "/../core/division_tracking.php";
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

  $mySectionId = (int)($_SESSION['section_id'] ?? 0);
  $myDivision = $mySectionId > 0 ? get_user_division_meta($conn, $mySectionId) : null;
  $ownDivisionCode = strtoupper(trim((string)($myDivision['code'] ?? '')));
  if ($ownDivisionCode === '') {
    $sessionDivisionName = strtoupper(trim((string)($_SESSION['division_name'] ?? '')));
    if (str_contains($sessionDivisionName, 'PLANNING') || str_contains($sessionDivisionName, 'PROGRAMMING')) {
      $ownDivisionCode = 'PPD';
    } elseif (str_contains($sessionDivisionName, 'SURVEY') || str_contains($sessionDivisionName, 'DESIGN')) {
      $ownDivisionCode = 'SDD';
    } elseif (str_contains($sessionDivisionName, 'SPECIAL')) {
      $ownDivisionCode = 'SPD';
    }
  }

  $branchMode = workflow_branch_mode_enabled($conn);
  $docHasRealBranches = ($branchMode && workflow_document_has_real_branches($conn, $docId));
  $viewerUserId = (int)($_SESSION['user_id'] ?? 0);

  $viewerIsDocumentOrigin = false;

  if ($docHasRealBranches && $viewerUserId > 0) {
    $stmtOrigin = $conn->prepare("
      SELECT actor_user_id
      FROM document_events
      WHERE document_id = ?
        AND event_type IN ('created', 'sent')
      ORDER BY created_at ASC, id ASC
      LIMIT 1
    ");
    $stmtOrigin->bind_param("i", $docId);
    $stmtOrigin->execute();
    $originRow = $stmtOrigin->get_result()->fetch_assoc();

    $originActorUserId = (int)($originRow['actor_user_id'] ?? 0);
    $viewerIsDocumentOrigin = ($originActorUserId > 0 && $originActorUserId === $viewerUserId);
  }

  $scope = attachment_branch_scope_for_document($conn, $docId, $requestedBranchId);
  $selectedBranchId = (int)($scope['selected_branch_id'] ?? 0);
  $isScoped = (($scope['scoped'] ?? false) === true);

  // Sender/origin POV should not be branch-scoped.
  if ($viewerIsDocumentOrigin) {
    $selectedBranchId = 0;
    $isScoped = false;
  }

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
        WHEN a.note LIKE 'AUTO:DIVISION_TRACKING_SLIP:%' THEN 1
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

  if ($rows) {
    foreach ($rows as $__i => &$__row) {
      $__row['__order_idx'] = $__i;
    }
    unset($__row);

    usort($rows, static function (array $a, array $b) use ($ownDivisionCode): int {
      $extractDivisionCode = static function (string $note): string {
        if ($note === 'AUTO:PPD_TRACKING_SLIP') {
          return 'PPD';
        }
        if (str_starts_with($note, 'AUTO:DIVISION_TRACKING_SLIP:')) {
          return strtoupper(trim(substr($note, strlen('AUTO:DIVISION_TRACKING_SLIP:'))));
        }
        return '';
      };

      $priority = static function (array $row) use ($ownDivisionCode, $extractDivisionCode): int {
        $note = (string)($row['note'] ?? '');
        if ($note === 'AUTO:TRANSMITTAL_MEMO') {
          return 0;
        }

        $rowDivisionCode = $extractDivisionCode($note);
        if ($rowDivisionCode !== '') {
          if ($ownDivisionCode !== '' && $rowDivisionCode === $ownDivisionCode) {
            return 1;
          }
          return 2;
        }

        return 3;
      };

      $pa = $priority($a);
      $pb = $priority($b);
      if ($pa !== $pb) return $pa <=> $pb;

      $na = (string)($a['note'] ?? '');
      $nb = (string)($b['note'] ?? '');
      $cda = $extractDivisionCode($na);
      $cdb = $extractDivisionCode($nb);
      if ($cda !== '' || $cdb !== '') {
        if ($cda !== $cdb) {
          return strcmp($cda, $cdb);
        }
      }

      return ((int)($a['__order_idx'] ?? 0)) <=> ((int)($b['__order_idx'] ?? 0));
    });

    foreach ($rows as &$__row) {
      unset($__row['__order_idx']);
    }
    unset($__row);
  }

  echo json_encode([
    "ok" => true,
    "attachments" => $rows,
    "selected_branch_id" => $selectedBranchId > 0 ? $selectedBranchId : null,
    "branch_scoped" => $isScoped,
    "viewer_is_document_origin" => $viewerIsDocumentOrigin,
  ]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error"]);
  exit;
}