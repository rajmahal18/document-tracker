<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();
require_once __DIR__ . "/../core/working_time.php";

header("Content-Type: application/json");

$docId = (int)($_GET["document_id"] ?? 0);
$selectedBranchId = (int)($_GET["branch_id"] ?? 0);
if ($docId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad document_id"]);
  exit;
}

try {

  $toStringList = static function ($value): array {
    if (is_array($value)) {
      return array_values(array_filter(array_map(static fn($v) => trim((string)$v), $value), static fn($v) => $v !== ''));
    }
    $s = trim((string)$value);
    if ($s === '') return [];
    if (($s[0] ?? '') === '[' || ($s[0] ?? '') === '{') {
      $decoded = json_decode($s, true);
      if (is_array($decoded)) {
        return array_values(array_filter(array_map(static fn($v) => trim((string)$v), $decoded), static fn($v) => $v !== ''));
      }
    }
    if (str_contains($s, ',')) {
      return array_values(array_filter(array_map('trim', explode(',', $s)), static fn($v) => $v !== ''));
    }
    return [$s];
  };

  $firstNonEmpty = static function (array $values): string {
    foreach ($values as $value) {
      $s = trim((string)$value);
      if ($s === '') continue;
      if (in_array(strtolower($s), ['-', '—', 'n/a', 'na', 'null', 'undefined'], true)) continue;
      return $s;
    }
    return '';
  };

  $summarizeList = static function (array $items, int $max = 3): string {
    $items = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $items), static fn($v) => $v !== ''));
    if ($items === []) return '';
    if (count($items) <= $max) return implode(', ', $items);
    return implode(', ', array_slice($items, 0, $max)) . ' +' . (count($items) - $max) . ' more';
  };

  $normalizeRemarks = static function ($value): string {
    $s = trim((string)$value);
    if ($s === '') return '';
    if (in_array(strtolower($s), ['none', '-', '—', 'n/a', 'na', 'null', 'undefined'], true)) return '';
    return $s;
  };

  $attachmentTaskSummaryCache = [];
  $buildAttachmentTaskSummary = static function (int $summaryDocId) use ($conn, &$attachmentTaskSummaryCache): ?array {
    if ($summaryDocId <= 0 || !workflow_attachment_forwarding_enabled($conn)) {
      return null;
    }
    if (array_key_exists($summaryDocId, $attachmentTaskSummaryCache)) {
      return $attachmentTaskSummaryCache[$summaryDocId];
    }

    $stmt = $conn->prepare("
      SELECT
        aft.recipient_user_id,
        COALESCE(NULLIF(TRIM(u.full_name), ''), CONCAT('User #', aft.recipient_user_id)) AS recipient_name,
        SUM(CASE WHEN aft.task_status = 'DONE' THEN 1 ELSE 0 END) AS done_count,
        SUM(CASE WHEN aft.task_status = 'IN_PROGRESS' THEN 1 ELSE 0 END) AS in_progress_count,
        SUM(CASE WHEN aft.task_status = 'PENDING_RECEIVE' THEN 1 ELSE 0 END) AS pending_receive_count,
        SUM(CASE WHEN aft.task_status IN ('PENDING_RECEIVE', 'IN_PROGRESS') THEN 1 ELSE 0 END) AS open_count,
        COUNT(*) AS task_count
      FROM attachment_forward_tasks aft
      LEFT JOIN users u ON u.id = aft.recipient_user_id
      WHERE aft.document_id = ?
      GROUP BY aft.recipient_user_id, recipient_name
      ORDER BY recipient_name ASC
    ");
    $stmt->bind_param("i", $summaryDocId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

    $doneUsers = [];
    $inProgressUsers = [];
    $pendingUsers = [];
    $cancelledUsers = [];
    $totalTasks = 0;
    $doneTasks = 0;
    $openTasks = 0;

    foreach ($rows as $row) {
      $name = trim((string)($row['recipient_name'] ?? ''));
      if ($name === '') continue;

      $done = (int)($row['done_count'] ?? 0);
      $inProgress = (int)($row['in_progress_count'] ?? 0);
      $pendingReceive = (int)($row['pending_receive_count'] ?? 0);
      $open = (int)($row['open_count'] ?? 0);
      $tasks = (int)($row['task_count'] ?? 0);

      $totalTasks += $tasks;
      $doneTasks += $done;
      $openTasks += $open;

      if ($inProgress > 0) {
        $inProgressUsers[] = $name;
      } elseif ($pendingReceive > 0) {
        $pendingUsers[] = $name;
      } elseif ($open === 0 && $done > 0) {
        $doneUsers[] = $name;
      } else {
        $cancelledUsers[] = $name;
      }
    }

    $summary = [
      'show_names' => true,
      'done_users' => array_values(array_unique($doneUsers)),
      'in_progress_users' => array_values(array_unique($inProgressUsers)),
      'pending_users' => array_values(array_unique($pendingUsers)),
      'cancelled_users' => array_values(array_unique($cancelledUsers)),
      'done_recipient_count' => count(array_unique($doneUsers)),
      'open_recipient_count' => count(array_unique(array_merge($inProgressUsers, $pendingUsers))),
      'total_recipient_count' => count($rows),
      'done_task_count' => $doneTasks,
      'open_task_count' => $openTasks,
      'total_task_count' => $totalTasks,
    ];

    $attachmentTaskSummaryCache[$summaryDocId] = $summary;
    return $summary;
  };

  if (!can_view_document($conn, $docId)) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Forbidden"]);
    exit;
  }

  $branchMode = workflow_branch_mode_enabled($conn);
  $docHasRealBranches = ($branchMode && workflow_document_has_real_branches($conn, $docId));
  $identity = effective_document_identity($conn);
  $viewerUserId = (int)($identity['effective_user_id'] ?? 0);
  $viewerSectionId = (int)($identity['effective_section_id'] ?? 0);
  $viewerDivisionId = (int)($identity['effective_division_id'] ?? 0);
  $viewerDivisionName = trim((string)($identity['effective_division_name'] ?? ''));
  $viewerRole = strtolower(trim((string)($identity['effective_role'] ?? '')));
  $viewerIsChief = (bool)($identity['effective_is_chief'] ?? false);
  $viewerIsAdmin = ($viewerRole === "admin") && !(bool)($identity['assistant_mode'] ?? false);
  $viewerLiveElapsedEnabled = false;
  $viewerLiveElapsedWorkingMinutes = 0;
  $liveElapsedMinutesByUser = [];

  $stmtDocMeta = $conn->prepare("
    SELECT current_status, created_at
    FROM documents
    WHERE id = ?
    LIMIT 1
  ");
  $stmtDocMeta->bind_param("i", $docId);
  $stmtDocMeta->execute();
  $docMetaRow = $stmtDocMeta->get_result()->fetch_assoc() ?: [];
  $docCurrentStatus = strtoupper(trim((string)($docMetaRow['current_status'] ?? 'ACTIVE')));
  $docCreatedAt = trim((string)($docMetaRow['created_at'] ?? ''));

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

  $branches = $docHasRealBranches ? workflow_get_branch_state($conn, $docId, $viewerUserId) : [];
  $allowedBranchIds = [];

  // Important:
  // Only enforce division-based branch visibility when the viewer actually owns
  // or can act on at least one visible branch. For origin/creator/general viewers,
  // workflow_get_branch_state() intentionally returns the full branch list.
  $viewerHasOwnBranchContext = false;
  if ($docHasRealBranches && is_array($branches)) {
    foreach ($branches as $branchRow) {
      if (
        (int)($branchRow['current_assignee_user_id'] ?? 0) === $viewerUserId
        || (int)($branchRow['my_pending_route_id'] ?? 0) > 0
        || (int)($branchRow['can_forward'] ?? 0) === 1
      ) {
        $viewerHasOwnBranchContext = true;
        break;
      }
    }
  }

  // Restrict visible branch tabs to the viewer's own division, except admins.
  if (
    $docHasRealBranches
    && !$viewerIsAdmin
    && !$viewerIsDocumentOrigin
    && $viewerDivisionId > 0
    && $viewerHasOwnBranchContext
    && is_array($branches)
    && $branches !== []
  ) {
    $rawBranchIds = array_values(array_unique(array_filter(
      array_map(static fn($b) => (int)($b['id'] ?? 0), $branches),
      static fn($id) => $id > 0
    )));

    if ($rawBranchIds !== []) {
      $placeholders = implode(',', array_fill(0, count($rawBranchIds), '?'));
      $types = str_repeat('i', count($rawBranchIds));

      $sqlBranchDivisionMap = "
        SELECT
          b.id,
          COALESCE(cs.division_id, us.division_id, 0) AS division_id
        FROM document_branches b
        LEFT JOIN sections cs ON cs.id = b.current_assignee_section_id
        LEFT JOIN users uu ON uu.id = b.current_assignee_user_id
        LEFT JOIN sections us ON us.id = uu.section_id
        WHERE b.id IN ($placeholders)
      ";

      $stmtBranchDivisionMap = $conn->prepare($sqlBranchDivisionMap);
      $params = $rawBranchIds;
      $bind = [$types];
      foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
      }
      call_user_func_array([$stmtBranchDivisionMap, 'bind_param'], $bind);
      $stmtBranchDivisionMap->execute();
      $branchDivisionRows = $stmtBranchDivisionMap->get_result()->fetch_all(MYSQLI_ASSOC);

      $branchDivisionMap = [];
      foreach ($branchDivisionRows as $branchDivisionRow) {
        $branchDivisionMap[(int)($branchDivisionRow['id'] ?? 0)] = (int)($branchDivisionRow['division_id'] ?? 0);
      }

      $branches = array_values(array_filter($branches, static function ($branchRow) use ($viewerDivisionId, $branchDivisionMap, &$allowedBranchIds) {
        $branchId = (int)($branchRow['id'] ?? 0);
        if ($branchId <= 0) return false;

        $branchDivisionId = (int)($branchDivisionMap[$branchId] ?? 0);
        if ($branchDivisionId !== $viewerDivisionId) {
          return false;
        }

        $allowedBranchIds[] = $branchId;
        return true;
      }));

      $allowedBranchIds = array_values(array_unique(array_filter(array_map('intval', $allowedBranchIds), static fn($id) => $id > 0)));
    }
  } elseif ($docHasRealBranches && is_array($branches)) {
    $allowedBranchIds = array_values(array_unique(array_filter(
      array_map(static fn($b) => (int)($b['id'] ?? 0), $branches),
      static fn($id) => $id > 0
    )));
  }

  // Build full branch tree for strict selected-lane lineage filtering.
  $allBranchesById = [];
  if ($docHasRealBranches) {
    $stmtAllBranches = $conn->prepare("
      SELECT id, parent_branch_id, branch_label, is_reference
      FROM document_branches
      WHERE document_id = ?
      ORDER BY id ASC
    ");
    $stmtAllBranches->bind_param("i", $docId);
    $stmtAllBranches->execute();
    $allBranchRows = $stmtAllBranches->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($allBranchRows as $branchRow) {
      $bid = (int)($branchRow['id'] ?? 0);
      if ($bid <= 0) continue;

      $branchRow['id'] = $bid;
      $branchRow['parent_branch_id'] = (int)($branchRow['parent_branch_id'] ?? 0);
      $branchRow['is_reference'] = ((int)($branchRow['is_reference'] ?? 0) === 1) ? 1 : 0;
      $allBranchesById[$bid] = $branchRow;
    }
  }

  // Hide only synthetic/root/origin branches from UI tabs.
  // Reference branches must stay visible when they are the viewer's actual lane.
  if ($docHasRealBranches && is_array($branches)) {
    $branches = array_values(array_filter($branches, static function ($branchRow) {
      $label = strtolower(trim((string)($branchRow['branch_label'] ?? '')));
      $parentId = (int)($branchRow['parent_branch_id'] ?? 0);

      if ($label === 'origin') return false;
      if ($label === '' && $parentId <= 0) return false;

      return true;
    }));
  }

  // Rebuild allowed IDs after final UI branch filtering.
  if ($docHasRealBranches && is_array($branches)) {
    $allowedBranchIds = array_values(array_unique(array_filter(
      array_map(static fn($b) => (int)($b['id'] ?? 0), $branches),
      static fn($id) => $id > 0
    )));
  }

  // Drop stale/foreign selected branch IDs for non-admins.
  if (
    $docHasRealBranches
    && $selectedBranchId > 0
    && !$viewerIsAdmin
    && !$viewerIsDocumentOrigin
    && $viewerHasOwnBranchContext
    && $allowedBranchIds !== []
    && !in_array($selectedBranchId, $allowedBranchIds, true)
  ) {
    $selectedBranchId = 0;
  }

  if ($docCurrentStatus === 'ACTIVE') {
    $activeUserIds = [];

    if ($docHasRealBranches) {
      $stmtActiveUsers = $conn->prepare("
        SELECT DISTINCT current_assignee_user_id
        FROM document_branches
        WHERE document_id = ?
          AND branch_status = 'ACTIVE'
          AND current_assignee_user_id IS NOT NULL
          AND current_assignee_user_id > 0
      ");
      $stmtActiveUsers->bind_param("i", $docId);
      $stmtActiveUsers->execute();
      $activeUserRows = $stmtActiveUsers->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
      foreach ($activeUserRows as $activeUserRow) {
        $activeUserId = (int)($activeUserRow['current_assignee_user_id'] ?? 0);
        if ($activeUserId > 0) $activeUserIds[] = $activeUserId;
      }
    } else {
      $stmtOpenLegacyRoutes = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM routes
        WHERE document_id = ?
          AND route_kind = 'ACTION'
          AND received_at IS NULL
          AND cancelled_at IS NULL
      ");
      $stmtOpenLegacyRoutes->bind_param("i", $docId);
      $stmtOpenLegacyRoutes->execute();
      $legacyOpenActionRoutes = (int)($stmtOpenLegacyRoutes->get_result()->fetch_assoc()['c'] ?? 0);

      if ($legacyOpenActionRoutes === 0) {
        $stmtLastLegacyReceived = $conn->prepare("
          SELECT received_by_user_id
          FROM routes
          WHERE document_id = ?
            AND route_kind = 'ACTION'
            AND received_by_user_id IS NOT NULL
            AND received_at IS NOT NULL
            AND cancelled_at IS NULL
          ORDER BY received_at DESC, id DESC
          LIMIT 1
        ");
        $stmtLastLegacyReceived->bind_param("i", $docId);
        $stmtLastLegacyReceived->execute();
        $legacyOwnerUserId = (int)($stmtLastLegacyReceived->get_result()->fetch_assoc()['received_by_user_id'] ?? 0);
        if ($legacyOwnerUserId > 0) {
          $activeUserIds[] = $legacyOwnerUserId;
        }
      }

      if (
        $viewerUserId > 0
        && workflow_user_can_act_legacy_document($conn, $docId, $viewerUserId, $viewerSectionId, $viewerIsChief, false)
      ) {
        $activeUserIds[] = $viewerUserId;
      }
    }

    $activeUserIds = array_values(array_unique(array_filter(array_map('intval', $activeUserIds), static fn($id) => $id > 0)));

    if ($activeUserIds !== []) {
      $stmtStartForUser = $conn->prepare("
        SELECT received_at
        FROM routes
        WHERE document_id = ?
          AND received_by_user_id = ?
          AND received_at IS NOT NULL
          AND cancelled_at IS NULL
        ORDER BY received_at DESC
        LIMIT 1
      ");

      foreach ($activeUserIds as $activeUserId) {
        $startRaw = null;
        $stmtStartForUser->bind_param("ii", $docId, $activeUserId);
        $stmtStartForUser->execute();
        $startRow = $stmtStartForUser->get_result()->fetch_assoc();

        if ($startRow && !empty($startRow['received_at'])) {
          $startRaw = (string)$startRow['received_at'];
        } elseif ($docCreatedAt !== '') {
          $startRaw = $docCreatedAt;
        }

        $liveElapsedMinutesByUser[(string)$activeUserId] = $startRaw
          ? dt_working_minutes_between($startRaw, null, $conn)
          : 0;
      }
    }
  }

  if ($viewerUserId > 0) {
    $viewerLiveElapsedWorkingMinutes = (int)($liveElapsedMinutesByUser[(string)$viewerUserId] ?? 0);
    $viewerLiveElapsedEnabled = ($viewerLiveElapsedWorkingMinutes > 0);
  }

  $selectedBranchScopeIds = [];
  if ($docHasRealBranches && $selectedBranchId > 0 && isset($allBranchesById[$selectedBranchId])) {
    $selectedBranchScopeIds = [$selectedBranchId];

    // Keep ancestor events visible so the viewer still sees how the selected lane was created.
    $cursorId = (int)($allBranchesById[$selectedBranchId]['parent_branch_id'] ?? 0);
    $guard = 0;
    while ($cursorId > 0 && isset($allBranchesById[$cursorId]) && $guard < 100) {
      if (in_array($cursorId, $selectedBranchScopeIds, true)) {
        break;
      }

      $selectedBranchScopeIds[] = $cursorId;
      $cursorId = (int)($allBranchesById[$cursorId]['parent_branch_id'] ?? 0);
      $guard++;
    }
  }

  $ackSummaryCache = [];

  $buildAckSummaryForBatch = static function (string $sendBatchId) use ($conn, $docId, &$ackSummaryCache, $allBranchesById): ?array {
    $sendBatchId = trim($sendBatchId);
    if ($sendBatchId === '') {
      return null;
    }

    $cacheKey = 'batch:' . $sendBatchId;
    if (array_key_exists($cacheKey, $ackSummaryCache)) {
      return $ackSummaryCache[$cacheKey];
    }

    $sql = "
      SELECT
        r.id,
        r.branch_id,
        r.to_user_id,
        r.to_section_id,
        r.received_at,
        u.full_name AS recipient_name,
        s.name AS recipient_section_name,
        b.parent_branch_id,
        b.branch_label,
        b.is_reference
      FROM routes r
      LEFT JOIN users u ON u.id = r.to_user_id
      LEFT JOIN sections s ON s.id = r.to_section_id
      LEFT JOIN document_branches b ON b.id = r.branch_id
      WHERE r.document_id = ?
        AND r.send_batch_id = ?
        AND r.cancelled_at IS NULL
      ORDER BY r.id ASC
    ";

    $stmtAck = $conn->prepare($sql);
    $stmtAck->bind_param('is', $docId, $sendBatchId);
    $stmtAck->execute();
    $rowsAck = $stmtAck->get_result()->fetch_all(MYSQLI_ASSOC);

    $summary = [
      'enabled' => true,
      'total' => 0,
      'received_count' => 0,
      'pending_count' => 0,
      'is_reference_summary' => false,
      'show_names' => true,
      'received_users' => [],
      'pending_users' => [],
      'scope_branch_ids' => [],
      'send_batch_id' => $sendBatchId,
    ];

    foreach ($rowsAck as $rowAck) {
      $routeId = (int)($rowAck['id'] ?? 0);
      if ($routeId <= 0) {
        continue;
      }

      $bid = (int)($rowAck['branch_id'] ?? 0);
      if ($bid > 0) {
        $summary['scope_branch_ids'][] = $bid;
      }

      $entry = [
        'route_id' => $routeId,
        'branch_id' => $bid,
        'parent_branch_id' => (int)($rowAck['parent_branch_id'] ?? ($allBranchesById[$bid]['parent_branch_id'] ?? 0)),
        'branch_label' => '',
        'user_id' => (int)($rowAck['to_user_id'] ?? 0),
        'name' => trim((string)($rowAck['recipient_name'] ?? '')),
        'section_name' => trim((string)($rowAck['recipient_section_name'] ?? '')),
        'is_reference' => ((int)($rowAck['is_reference'] ?? ($allBranchesById[$bid]['is_reference'] ?? 0)) === 1) ? 1 : 0,
      ];

      $summary['total']++;
      if (!empty($rowAck['received_at'])) {
        $summary['received_count']++;
        $summary['received_users'][] = $entry;
      } else {
        $summary['pending_count']++;
        $summary['pending_users'][] = $entry;
      }
    }

    $summary['scope_branch_ids'] = array_values(array_unique(array_filter(array_map('intval', $summary['scope_branch_ids']), static fn($id) => $id > 0)));
    $summary['is_reference_summary'] = $summary['total'] > 0
      && count(array_filter(array_merge($summary['received_users'], $summary['pending_users']), static fn($row) => (int)($row['is_reference'] ?? 0) === 1)) === $summary['total'];
    $ackSummaryCache[$cacheKey] = $summary['total'] > 0 ? $summary : null;
    return $ackSummaryCache[$cacheKey];
  };

  $buildAckSummary = static function (array $branchIds) use ($conn, $docId, &$ackSummaryCache, $allBranchesById): ?array {
    $branchIds = array_values(array_unique(array_filter(
      array_map('intval', $branchIds),
      static fn($id) => $id > 0 && isset($allBranchesById[$id])
    )));

    if ($branchIds === []) {
      return null;
    }

    sort($branchIds);
    $cacheKey = implode(',', $branchIds);
    if (array_key_exists($cacheKey, $ackSummaryCache)) {
      return $ackSummaryCache[$cacheKey];
    }

    $placeholders = implode(',', array_fill(0, count($branchIds), '?'));
    $types = 'i' . str_repeat('i', count($branchIds));

    $sql = "
      SELECT
        b.id,
        b.parent_branch_id,
        b.branch_label,
        b.is_reference,
        b.current_assignee_user_id,
        b.current_assignee_section_id,
        u.full_name AS assignee_name,
        s.name AS assignee_section_name,
        (
          SELECT MAX(CASE WHEN r_recv.received_at IS NOT NULL THEN 1 ELSE 0 END)
          FROM routes r_recv
          WHERE r_recv.document_id = ?
            AND r_recv.branch_id = b.id
            AND r_recv.route_kind = 'ACTION'
            AND r_recv.cancelled_at IS NULL
        ) AS has_received
      FROM document_branches b
      LEFT JOIN users u ON u.id = b.current_assignee_user_id
      LEFT JOIN sections s ON s.id = b.current_assignee_section_id
      WHERE b.id IN ($placeholders)
      ORDER BY b.id ASC
    ";

    $stmtAck = $conn->prepare($sql);
    $params = array_merge([$docId], $branchIds);
    $bind = [$types];
    foreach ($params as $k => $v) {
      $bind[] = &$params[$k];
    }
    call_user_func_array([$stmtAck, 'bind_param'], $bind);
    $stmtAck->execute();
    $rowsAck = $stmtAck->get_result()->fetch_all(MYSQLI_ASSOC);

    $summary = [
      "enabled" => true,
      "total" => 0,
      "received_count" => 0,
      "pending_count" => 0,
      "is_reference_summary" => false,
      "show_names" => true,
      "received_users" => [],
      "pending_users" => [],
      "scope_branch_ids" => $branchIds,
    ];

    foreach ($rowsAck as $rowAck) {
      $bid = (int)($rowAck['id'] ?? 0);
      if ($bid <= 0) continue;

      $entry = [
        "branch_id" => $bid,
        "parent_branch_id" => (int)($rowAck['parent_branch_id'] ?? 0),
        "branch_label" => trim((string)($rowAck['branch_label'] ?? ($allBranchesById[$bid]['branch_label'] ?? ''))),
        "user_id" => (int)($rowAck['current_assignee_user_id'] ?? 0),
        "name" => trim((string)($rowAck['assignee_name'] ?? '')),
        "section_name" => trim((string)($rowAck['assignee_section_name'] ?? '')),
        "is_reference" => ((int)($rowAck['is_reference'] ?? 0) === 1) ? 1 : 0,
      ];

      $summary["total"]++;

      $hasReceived = (int)($rowAck['has_received'] ?? 0) === 1;
      if ($hasReceived) {
        $summary["received_count"]++;
        $summary["received_users"][] = $entry;
      } else {
        $summary["pending_count"]++;
        $summary["pending_users"][] = $entry;
      }
    }

    $summary["is_reference_summary"] = $summary["total"] > 0
      && count(array_filter(array_merge($summary["received_users"], $summary["pending_users"]), static fn($row) => (int)($row["is_reference"] ?? 0) === 1)) === $summary["total"];
    $ackSummaryCache[$cacheKey] = $summary["total"] > 0 ? $summary : null;
    return $ackSummaryCache[$cacheKey];
  };

  $actorProfilePhotoColumn = null;
  foreach (["profile_photo_url", "avatar_url", "photo_url"] as $candidatePhotoColumn) {
    if (db_column_exists($conn, "users", $candidatePhotoColumn)) {
      $actorProfilePhotoColumn = $candidatePhotoColumn;
      break;
    }
  }

  $stmt = $conn->prepare("
    SELECT
      e.id AS event_id,
      e.event_type,
      e.created_at,
      e.payload_json,
      e.actor_user_id,
      e.actor_section_id,
      e.from_section_id,
      e.to_section_id,
      s_actor.name AS actor_section_name,
      d_actor.name AS actor_division_name,
      u.full_name AS actor,
      " . ($actorProfilePhotoColumn !== null ? "u.`" . $conn->real_escape_string($actorProfilePhotoColumn) . "`" : "NULL") . " AS actor_profile_photo_raw,
      s_from.name AS from_section,
      d_from.name AS from_division_name,
      s_to.name AS to_section,
      d_to.name AS to_division_name
    FROM document_events e
    LEFT JOIN users u ON u.id = e.actor_user_id
    LEFT JOIN sections s_actor ON s_actor.id = e.actor_section_id
    LEFT JOIN divisions d_actor ON d_actor.id = s_actor.division_id
    LEFT JOIN sections s_from ON s_from.id = e.from_section_id
    LEFT JOIN divisions d_from ON d_from.id = s_from.division_id
    LEFT JOIN sections s_to   ON s_to.id = e.to_section_id
    LEFT JOIN divisions d_to ON d_to.id = s_to.division_id
    WHERE e.document_id = ?
    ORDER BY e.created_at DESC, e.id DESC
    LIMIT 100
  ");
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  $suppressReceivedForBatchIds = [];
  foreach ($rows as $rowForSuppression) {
    $payloadForSuppression = [];
    if (!empty($rowForSuppression["payload_json"])) {
      $decodedSuppression = json_decode((string)$rowForSuppression["payload_json"], true);
      if (is_array($decodedSuppression)) {
        $payloadForSuppression = $decodedSuppression;
      }
    }

    $eventTypeForSuppression = strtolower(trim((string)($rowForSuppression["event_type"] ?? "")));
    if (!in_array($eventTypeForSuppression, ["sent", "forwarded"], true)) {
      continue;
    }

    $sendBatchIdForSuppression = trim((string)($payloadForSuppression['send_batch_id'] ?? ''));
    $recipientCountForSuppression = count(array_values(array_unique(array_filter(
      array_map('intval', (array)($payloadForSuppression['to_user_ids'] ?? [])),
      static fn($id) => $id > 0
    ))));

    if (
      $sendBatchIdForSuppression !== ''
      && $recipientCountForSuppression > 1
      && ((int)($payloadForSuppression['reference_only_without_branching'] ?? 0) === 1)
    ) {
      $suppressReceivedForBatchIds[$sendBatchIdForSuppression] = true;
    }
  }

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
    if ($eventKey === "updated" && (($payload["kind"] ?? "") === "attachment_added")) {
      $eventKey = "attachment_added";
    }
    if ($eventKey === "updated" && in_array(($payload["kind"] ?? ""), [
      "branch_ended_here",
      "branch_end_here_undone",
      "document_ended_here",
      "document_end_here_undone",
    ], true)) {
      $eventKey = (string)$payload["kind"];
    }
    if (in_array(($payload["kind"] ?? ""), ["attachment_forwarded", "attachment_forward_task_done"], true)) {
      $eventKey = (string)$payload["kind"];
    }
    if (in_array(($payload["kind"] ?? ""), ["holder_progress_note_added", "holder_progress_note_updated", "holder_progress_note_cleared", "admin_closed_note_added", "admin_closed_note_updated", "admin_closed_note_cleared"], true)) {
      $eventKey = (string)$payload["kind"];
    }

    $routeKind = strtoupper(trim((string)($payload["route_kind"] ?? "")));
    $isReferenceEvent = (
      $routeKind === 'REFERENCE'
      || (int)($payload['receive_only'] ?? 0) === 1
      || (int)($payload['reference_only_without_branching'] ?? 0) === 1
    );

    $actor = (string)($r["actor"] ?? "—");
    $actorPhotoUrl = app_profile_photo_url((string)($r["actor_profile_photo_raw"] ?? ""));
    $actorInitials = function_exists("app_user_initials")
      ? app_user_initials($actor)
      : strtoupper(substr(trim($actor), 0, 1));
    $actingPrincipalName = trim((string)($payload["acting_principal_name"] ?? ""));
    $actingLabel = trim((string)($payload["acting_label"] ?? ""));
    if ($actingPrincipalName !== "") {
      $actor = ($actingLabel !== "" ? $actingLabel : $actingPrincipalName) . " (via " . $actor . ")";
    }
    $from  = (string)($r["from_section"] ?? "");
    $to    = (string)($r["to_section"] ?? "");
    $branchId = (int)($payload["branch_id"] ?? 0);
    $sourceBranchId = (int)($payload["source_branch_id"] ?? 0);
    $newBranchIds = array_values(array_filter(array_map('intval', (array)($payload['new_branch_ids'] ?? $payload['branch_ids'] ?? []))));
    $branchLabel = (string)($payload["branch_label"] ?? "");

    if ($branchMode && $branchLabel === '') {
      $lookupBranchId = $branchId > 0 ? $branchId : ($newBranchIds[0] ?? $sourceBranchId);
      if ($lookupBranchId > 0) {
        $stmtBranch = $conn->prepare("SELECT branch_label FROM document_branches WHERE id = ? LIMIT 1");
        $stmtBranch->bind_param("i", $lookupBranchId);
        $stmtBranch->execute();
        $branchLabel = (string)($stmtBranch->get_result()->fetch_assoc()["branch_label"] ?? "");
      }
    }

    $payloadToSections = array_merge(
      $toStringList($payload["to_section_name"] ?? []),
      $toStringList($payload["to_section_names"] ?? []),
      $toStringList($payload["target_section_name"] ?? []),
      $toStringList($payload["target_sections"] ?? []),
      $toStringList($payload["recipient_section_names"] ?? [])
    );
    $payloadToUsers = array_merge(
      $toStringList($payload["to_user_name"] ?? []),
      $toStringList($payload["to_user_names"] ?? []),
      $toStringList($payload["recipient_names"] ?? []),
      $toStringList($payload["recipients"] ?? [])
    );
    $payloadFromUser = $firstNonEmpty([
      $payload["from_user_name"] ?? "",
      $payload["source_user_name"] ?? "",
      $payload["sent_by_name"] ?? "",
    ]);

    $payloadToUser = $firstNonEmpty([
      $payload["to_user_name"] ?? "",
      $payload["target_user_name"] ?? "",
      $payload["received_by_name"] ?? "",
      $payload["forwarded_to_name"] ?? "",
      $payloadToUsers[0] ?? "",
    ]);

    if ($eventKey === "received") {
      $payloadToUser = $firstNonEmpty([
        $actor,
        $payload["received_by_name"] ?? "",
        $payloadToUser,
      ]);

      $payloadFromUser = $firstNonEmpty([
        $payload["from_user_name"] ?? "",
        $payload["source_user_name"] ?? "",
        $payload["sent_by_name"] ?? "",
      ]);
    }

    if (in_array($eventKey, ["sent", "forwarded"], true)) {
      $payloadFromUser = $firstNonEmpty([
        $actor,
        $payload["from_user_name"] ?? "",
        $payload["source_user_name"] ?? "",
        $payload["sent_by_name"] ?? "",
      ]);

      $payloadToUser = $firstNonEmpty([
        $payload["to_user_name"] ?? "",
        $payload["target_user_name"] ?? "",
        $payload["forwarded_to_name"] ?? "",
        $payload["received_by_name"] ?? "",
        $payloadToUsers[0] ?? "",
      ]);
    }

    $payloadFrom = $firstNonEmpty([
      $payload["from_section_name"] ?? "",
      $payload["source_section_name"] ?? "",
      $from,
    ]);
    $payloadTo = $firstNonEmpty([
      $payload["to_section_name"] ?? "",
      $payload["target_section_name"] ?? "",
      $to,
      $payloadToSections[0] ?? "",
    ]);
    $payloadRecipientSummary = $summarizeList(array_unique(array_merge($payloadToSections, $payloadToUsers)));
    $branchSplitCount = count($newBranchIds);

    $ackSummary = null;
    $attachmentTaskSummary = null;
    $sendBatchId = trim((string)($payload['send_batch_id'] ?? ''));

    if (
      $eventKey === 'received'
      && $sendBatchId !== ''
      && isset($suppressReceivedForBatchIds[$sendBatchId])
    ) {
      continue;
    }

    $recipientCountForAck = count(array_values(array_unique(array_filter(array_map('intval', (array)($payload['to_user_ids'] ?? [])), static fn($id) => $id > 0))));
    if ($branchMode && in_array($eventKey, ["sent", "forwarded"], true)) {
      if ($sendBatchId !== '' && $recipientCountForAck > 1) {
        $ackSummary = $buildAckSummaryForBatch($sendBatchId);
      } elseif ($branchSplitCount > 1) {
        $ackSummary = $buildAckSummary($newBranchIds);
      }
    }

    $title = "";
    switch ($eventKey) {
      case "created":
        $title = "{$actor} created the document";
        break;

      case "sent":
        $title = "{$actor} sent the document";
        if ($isReferenceEvent) {
          $title = "{$actor} shared this document visibility";
        }
        if ($branchSplitCount > 1) {
          $title .= " to {$branchSplitCount} users";
        }
        break;

      case "forwarded":
        $title = "{$actor} forwarded the document";
        if ($isReferenceEvent) {
          $title = "{$actor} shared this document visibility";
        }
        if ($branchSplitCount > 1) {
          $title .= " to {$branchSplitCount} users";
        }
        break;

      case "attachment_forwarded":
        $recipientRoutes = is_array($payload['recipient_routes'] ?? null) ? (array)$payload['recipient_routes'] : [];
        $attachmentLabels = [];
        $recipientLabels = [];
        foreach ($recipientRoutes as $routeRow) {
          if (!is_array($routeRow)) continue;
          $routeUser = trim((string)($routeRow['to_user_name'] ?? ''));
          $routeSection = trim((string)($routeRow['to_section_name'] ?? ''));
          $recipientLabels[] = $routeUser !== '' ? $routeUser : $routeSection;
          foreach ((array)($routeRow['attachments'] ?? []) as $attachmentName) {
            $attachmentName = trim((string)$attachmentName);
            if ($attachmentName !== '') $attachmentLabels[] = $attachmentName;
          }
        }
        $attachmentLabels = array_values(array_unique($attachmentLabels));
        $recipientLabels = array_values(array_unique(array_filter($recipientLabels, static fn($v) => trim((string)$v) !== '')));
        $title = "{$actor} forwarded attachment" . (count($attachmentLabels) === 1 ? '' : 's');
        if (count($recipientLabels) > 0) {
          $title .= " to " . $summarizeList($recipientLabels);
        }
        if (count($attachmentLabels) > 0) {
          $meta = 'Attachments: ' . $summarizeList($attachmentLabels);
        }
        break;

      case "attachment_forward_task_done":
        $doneCount = (int)($payload['done_count'] ?? 0);
        $title = "{$actor} marked attachment task" . ($doneCount === 1 ? '' : 's') . " done";
        if ($doneCount > 0) {
          $title .= " ({$doneCount})";
        }
        $attachmentTaskSummary = $buildAttachmentTaskSummary($docId);
        break;

      case "received":
        $title = "{$actor} received the document";
        if ($isReferenceEvent) {
          $title = "{$actor} acknowledged the shared visibility";
        }
        break;

      case "branch_ended_here":
      case "document_ended_here":
        $title = "{$actor} ended the document lifecycle";
        break;

      case "branch_end_here_undone":
      case "document_end_here_undone":
        $title = "{$actor} reopened the document lifecycle";
        break;

      case "released":
        $old = strtoupper((string)($payload["old_status"] ?? ""));
        $new = strtoupper((string)($payload["new_status"] ?? ""));
        if ($old === "RELEASED" && $new === "ACTIVE") {
          $eventKey = "release_undone";
          $title = "{$actor} undid the release";
        } else {
          $title = "{$actor} released the document";
        }
        break;

      case "archived":
        $old = strtoupper((string)($payload["old_status"] ?? ""));
        $new = strtoupper((string)($payload["new_status"] ?? ""));
        if ($old === "ARCHIVED" && $new === "RELEASED") {
          $eventKey = "archive_undone";
          $title = "{$actor} undid the archive";
        } else {
          $title = "{$actor} archived the document";
        }
        break;

      case "attachment_added":
        $file = (string)($payload["file"] ?? "file");
        $isAppend = ((int)($payload["is_append"] ?? 0) === 1);
        $what = $isAppend ? "an appended file" : "a file";
        $title = "{$actor} attached {$what}";
        if ($file !== '') {
          $title .= ": {$file}";
        }
        break;

      case "holder_progress_note_added":
        $title = "{$actor} added work-in-progress remarks";
        break;

      case "holder_progress_note_updated":
        $title = "{$actor} updated work-in-progress remarks";
        break;

      case "holder_progress_note_cleared":
        $title = "{$actor} cleared work-in-progress remarks";
        break;

      case "admin_closed_note_added":
        $title = "{$actor} added admin remarks on a closed document";
        break;

      case "admin_closed_note_updated":
        $title = "{$actor} updated admin remarks on a closed document";
        break;

      case "admin_closed_note_cleared":
        $title = "{$actor} cleared admin remarks on a closed document";
        break;

      case "updated":
        $remarksText = $normalizeRemarks($payload["remarks"] ?? "");
        if ($remarksText !== '') {
          $title = "{$actor} updated the document";
        } else {
          continue 2;
        }
        break;

      default:
        continue 2;
    }

    $movementFrom = $payloadFrom;
    $movementTo = $payloadTo;
    if ($movementFrom !== '' && $movementTo !== '' && strcasecmp($movementFrom, $movementTo) === 0) {
      $movementTo = '';
    }
    $movementLabel = '';
    if ($movementFrom !== '' && $movementTo !== '') {
      $movementLabel = "{$movementFrom} → {$movementTo}";
    } elseif ($movementTo !== '') {
      $movementLabel = "To: {$movementTo}";
    } elseif ($movementFrom !== '') {
      $movementLabel = "From: {$movementFrom}";
    }

    $personMovementLabel = '';
    if ($payloadFromUser !== '' && $payloadToUser !== '' && strcasecmp($payloadFromUser, $payloadToUser) !== 0) {
      $personMovementLabel = "{$payloadFromUser} → {$payloadToUser}";
    }

    $metaParts = [];
    if ($movementLabel !== '') $metaParts[] = $movementLabel;
    if ($payloadRecipientSummary !== '' && $payloadRecipientSummary !== $movementTo) {
      $metaParts[] = "Recipients: {$payloadRecipientSummary}";
    }
    if ($branchLabel !== '') {
      $metaParts[] = 'Branch: ' . $branchLabel;
    }
    $meta = implode(' • ', $metaParts);

    $resolvedBranchId = $branchId > 0 ? $branchId : ($newBranchIds[0] ?? ($sourceBranchId > 0 ? $sourceBranchId : null));

    if ($selectedBranchId > 0) {
      $candidateBranchIds = [];

      if ($resolvedBranchId !== null && $resolvedBranchId > 0) {
        $candidateBranchIds[] = (int)$resolvedBranchId;
      }

      if ($sourceBranchId > 0) {
        $candidateBranchIds[] = $sourceBranchId;
      }

      foreach ($newBranchIds as $newBid) {
        $newBid = (int)$newBid;
        if ($newBid > 0) {
          $candidateBranchIds[] = $newBid;
        }
      }

      $candidateBranchIds = array_values(array_unique($candidateBranchIds));

      $belongsToSelected = false;

      if ($candidateBranchIds !== []) {
        foreach ($candidateBranchIds as $candidateBid) {
          if (in_array($candidateBid, $selectedBranchScopeIds, true)) {
            $belongsToSelected = true;
            break;
          }
        }
      } elseif ($resolvedBranchId === null && $sourceBranchId === 0 && count($newBranchIds) === 0) {
        $belongsToSelected = true;
      }

      if (!$belongsToSelected) {
        continue;
      }
    }

    // Optional division-level safety guard when no lane is selected.
    if (
      $docHasRealBranches
      && !$viewerIsAdmin
      && !$viewerIsDocumentOrigin
      && $viewerDivisionId > 0
      && $viewerHasOwnBranchContext
      && $selectedBranchId <= 0
    ) {
      $candidateBranchIdsForDivisionGuard = [];

      if ($resolvedBranchId !== null && $resolvedBranchId > 0) {
        $candidateBranchIdsForDivisionGuard[] = (int)$resolvedBranchId;
      }
      if ($sourceBranchId > 0) {
        $candidateBranchIdsForDivisionGuard[] = $sourceBranchId;
      }
      foreach ($newBranchIds as $newBid) {
        $newBid = (int)$newBid;
        if ($newBid > 0) {
          $candidateBranchIdsForDivisionGuard[] = $newBid;
        }
      }

      $candidateBranchIdsForDivisionGuard = array_values(array_unique($candidateBranchIdsForDivisionGuard));

      if ($candidateBranchIdsForDivisionGuard !== []) {
        $hasAllowedCandidate = false;
        foreach ($candidateBranchIdsForDivisionGuard as $candidateBid) {
          if (in_array($candidateBid, $allowedBranchIds, true)) {
            $hasAllowedCandidate = true;
            break;
          }
        }
        if (!$hasAllowedCandidate) {
          continue;
        }
      }
    }

    $actorDivision = trim((string)($r["actor_division_name"] ?? ""));
    $fromDivision = trim((string)($r["from_division_name"] ?? ""));
    $toDivision = trim((string)($r["to_division_name"] ?? ""));

    $payloadToSectionIds = array_values(array_unique(array_filter(array_map('intval', array_merge(
      (array)($payload['to_section_ids'] ?? []),
      (array)array_keys((array)($payload['recipient_map'] ?? []))
    )), static fn($id) => $id > 0)));

    $payloadToUserIds = array_values(array_unique(array_filter(array_map('intval', (array)($payload['to_user_ids'] ?? [])), static fn($id) => $id > 0)));

    $explicitFromSectionId = (int)($payload['from_section_id'] ?? ($r['from_section_id'] ?? 0));
    $explicitToSectionId = (int)($payload['to_section_id'] ?? ($r['to_section_id'] ?? 0));
    if ($explicitToSectionId > 0) {
      $payloadToSectionIds[] = $explicitToSectionId;
      $payloadToSectionIds = array_values(array_unique($payloadToSectionIds));
    }

    $eventDivision = $firstNonEmpty([$actorDivision, $fromDivision, $toDivision]);
    $sameDivision = (
      $viewerDivisionName !== ''
      && $eventDivision !== ''
      && strcasecmp($viewerDivisionName, $eventDivision) === 0
    );
    $viewerIsPartyToMovement = (
      $viewerDivisionName !== ''
      && (
        ($actorDivision !== '' && strcasecmp($viewerDivisionName, $actorDivision) === 0)
        || ($fromDivision !== '' && strcasecmp($viewerDivisionName, $fromDivision) === 0)
        || ($toDivision !== '' && strcasecmp($viewerDivisionName, $toDivision) === 0)
      )
    );

    $viewerIsExplicitParty = (
      ($viewerSectionId > 0 && ($viewerSectionId === $explicitFromSectionId || in_array($viewerSectionId, $payloadToSectionIds, true)))
      || ($viewerUserId > 0 && in_array($viewerUserId, $payloadToUserIds, true))
    );

    $selectedLaneOwnsEvent = false;
    if ($selectedBranchId > 0) {
      $candidateBranchIdsForRemarks = [];
      if ($resolvedBranchId !== null && $resolvedBranchId > 0) $candidateBranchIdsForRemarks[] = (int)$resolvedBranchId;
      if ($sourceBranchId > 0) $candidateBranchIdsForRemarks[] = $sourceBranchId;
      foreach ($newBranchIds as $newBid) {
        $newBid = (int)$newBid;
        if ($newBid > 0) $candidateBranchIdsForRemarks[] = $newBid;
      }
      $candidateBranchIdsForRemarks = array_values(array_unique($candidateBranchIdsForRemarks));
      foreach ($candidateBranchIdsForRemarks as $candidateBid) {
        if (in_array($candidateBid, $selectedBranchScopeIds, true)) {
          $selectedLaneOwnsEvent = true;
          break;
        }
      }
    }

    $shouldRedact = (!$viewerIsAdmin && !$sameDivision && $eventDivision !== '');
    $remarksValue = $normalizeRemarks($payload["remarks"] ?? "");
    $releasedToValue = $normalizeRemarks($payload["released_to"] ?? "");
    $personalDeadlineValue = trim((string)($payload["personal_deadline_at"] ?? ""));
    $canSeeRemarks = (
      !$shouldRedact
      || $viewerIsAdmin
      || $viewerIsPartyToMovement
      || $viewerIsExplicitParty
      || $selectedLaneOwnsEvent
    );

    $branchSplitRedacted = false;
    if ($shouldRedact) {
      $safeDivision = $eventDivision;
      $actor = $safeDivision;
      $actorPhotoUrl = "";
      $actorInitials = function_exists("app_user_initials")
        ? app_user_initials($safeDivision)
        : strtoupper(substr(trim($safeDivision), 0, 1));
      $branchLabel = '';
      $personMovementLabel = '';
      $payloadRecipientSummary = '';
      $movementLabel = '';
      $movementFrom = '';
      $movementTo = '';
      $from = '';
      $to = '';
      $payloadFromUser = '';
      $payloadToUser = '';
      $meta = '';
      if (count($newBranchIds) > 1) {
        $branchSplitRedacted = true;
        $newBranchIds = [];
      }

      if (is_array($ackSummary)) {
        $ackSummary["show_names"] = false;
        $ackSummary["received_users"] = [];
        $ackSummary["pending_users"] = [];
      }
      if (is_array($attachmentTaskSummary)) {
        $attachmentTaskSummary["show_names"] = false;
        $attachmentTaskSummary["done_users"] = [];
        $attachmentTaskSummary["in_progress_users"] = [];
        $attachmentTaskSummary["pending_users"] = [];
        $attachmentTaskSummary["cancelled_users"] = [];
      }

      switch ($eventKey) {
        case 'created':
          $title = "{$safeDivision} created the document";
          break;
        case 'received':
          $title = "{$safeDivision} received the document";
          if ($isReferenceEvent) {
            $title = "{$safeDivision} acknowledged the shared visibility";
          }
          break;
        case 'sent':
        case 'forwarded':
          $title = "{$safeDivision} forwarded the document";
          if ($isReferenceEvent) {
            $title = "{$safeDivision} shared document visibility";
          }
          break;
        case 'attachment_forwarded':
          $title = "{$safeDivision} forwarded attachment(s)";
          break;
        case 'attachment_forward_task_done':
          $title = "{$safeDivision} completed an attachment task";
          break;
        case 'attachment_added':
        case 'updated':
        case 'under_action':
        case 'status_changed':
          $title = "{$safeDivision} committed an action";
          break;
        case 'released':
          $title = "{$safeDivision} released the document";
          break;
        case 'branch_ended_here':
        case 'document_ended_here':
          $title = "{$safeDivision} ended the document lifecycle";
          break;
        case 'branch_end_here_undone':
        case 'document_end_here_undone':
          $title = "{$safeDivision} reopened the document lifecycle";
          break;
        case 'release_undone':
          $title = "{$safeDivision} undid the release";
          break;
        case 'archived':
          $title = "{$safeDivision} archived the document";
          break;
        case 'archive_undone':
          $title = "{$safeDivision} undid the archive";
          break;
      }
    }

    $elapsedWorkingMinutes = isset($payload["elapsed_working_minutes"]) ? (int)$payload["elapsed_working_minutes"] : -1;
    // If payload does not contain elapsed_working_minutes, calculate for historical purposes.
    // For live tracking, this is done after the loop.
    if ($elapsedWorkingMinutes === -1) {
      $elapsedWorkingMinutes = 0;
      if (in_array($eventKey, ["sent", "forwarded", "branch_ended_here", "document_ended_here", "released", "attachment_forwarded"], true)) {
        $histActorUid = (int)($r["actor_user_id"] ?? 0);
        $histCreatedAt = (string)($r["created_at"] ?? "");
        $fallbackBranchId = $resolvedBranchId > 0 ? $resolvedBranchId : 0;
        $stmtStartFallback = $conn->prepare("SELECT received_at FROM routes WHERE document_id = ? AND received_by_user_id = ? AND (? <= 0 OR branch_id = ?) AND received_at IS NOT NULL AND cancelled_at IS NULL AND received_at <= ? ORDER BY received_at DESC LIMIT 1");
        $stmtStartFallback->bind_param("iiiis", $docId, $histActorUid, $fallbackBranchId, $fallbackBranchId, $histCreatedAt);
        $stmtStartFallback->execute();
        $startFallbackRow = $stmtStartFallback->get_result()->fetch_assoc();
        if ($startFallbackRow && !empty($startFallbackRow['received_at'])) {
          $elapsedWorkingMinutes = dt_working_minutes_between($startFallbackRow['received_at'], $histCreatedAt, $conn);
        } else {
          $stmtDocFallback = $conn->prepare("SELECT created_at FROM documents WHERE id = ? LIMIT 1");
          $stmtDocFallback->bind_param("i", $docId);
          $stmtDocFallback->execute();
          $docStartRawFallback = $stmtDocFallback->get_result()->fetch_assoc()['created_at'] ?? null;
          $elapsedWorkingMinutes = $docStartRawFallback ? dt_working_minutes_between($docStartRawFallback, $histCreatedAt, $conn) : 0;
        }
      }
    }

    // Retroactively patch already saved events so they appear in Action Times
    if (in_array($eventKey, ["sent", "forwarded", "branch_ended_here", "document_ended_here", "released", "attachment_forwarded"], true)) {
      if ($elapsedWorkingMinutes === 0) {
        $elapsedWorkingMinutes = 1;
      }
    }

    $history[] = [
      "event_id" => (int)($r["event_id"] ?? 0),
      "action" => $eventKey,
      "event_type" => $eventType,
      "title" => $title,
      "is_reference_event" => $isReferenceEvent ? 1 : 0,
      "route_kind" => $routeKind,
      "meta" => $meta,
      "branch_id" => $resolvedBranchId,
      "source_branch_id" => $sourceBranchId > 0 ? $sourceBranchId : null,
      "new_branch_ids" => $newBranchIds,
      "branch_label" => $branchLabel,
      "actor_section_id" => (int)($r["actor_section_id"] ?? 0),
      "actor_section" => $shouldRedact ? $eventDivision : (string)($r["actor_section_name"] ?? ""),
      "actor_division" => $actorDivision,
      "viewer_redacted" => $shouldRedact,
      "branch_split_redacted" => $branchSplitRedacted,
      "remarks" => $canSeeRemarks ? $remarksValue : "",
      "released_to" => $canSeeRemarks ? $releasedToValue : "",
      "actor_user_id" => (int)($r["actor_user_id"] ?? 0),
      "elapsed_working_minutes" => $elapsedWorkingMinutes,
      "personal_deadline_at" => $personalDeadlineValue,
      "acted_at" => (string)($r["created_at"] ?? ""),
      "actor" => $actor,
      "actor_photo_url" => $actorPhotoUrl,
      "actor_initials" => $actorInitials,
      "from_section" => $movementFrom !== '' ? $movementFrom : $from,
      "to_section" => $movementTo !== '' ? $movementTo : $to,
      "movement_label" => $movementLabel,
      "recipient_summary" => $payloadRecipientSummary,
      "person_movement" => $personMovementLabel,
      "from_user_name" => $payloadFromUser,
      "to_user_name" => $payloadToUser,
      "ack_summary" => $ackSummary,
      "attachment_task_summary" => $attachmentTaskSummary,
    ];
  }

  $involvedUserIds = [];
  foreach ($history as $h) {
    if (!empty($h['actor_user_id'])) $involvedUserIds[] = (int)$h['actor_user_id'];
  }
  foreach ($activeUserIds ?? [] as $uid) {
    if ($uid > 0) $involvedUserIds[] = (int)$uid;
  }
  if ($viewerUserId > 0) {
    $involvedUserIds[] = $viewerUserId;
  }
  $involvedUserIds = array_values(array_unique($involvedUserIds));

  $userProfiles = [];
  if (!empty($involvedUserIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($involvedUserIds), '?'));
    $types = str_repeat('i', count($involvedUserIds));

    $principalToAssistants = [];
    $assistantToPrincipal = [];

    if (function_exists('assistant_assignments_table_ready') && assistant_assignments_table_ready($conn)) {
      $paSql = "
        SELECT principal_user_id, assistant_user_id
        FROM principal_assistants
        WHERE principal_user_id IN ($inPlaceholders)
           OR assistant_user_id IN ($inPlaceholders)
      ";
      $stmtPa = $conn->prepare($paSql);
      $paParams = array_merge($involvedUserIds, $involvedUserIds);
      $paTypes = $types . $types;
      $stmtPa->bind_param($paTypes, ...$paParams);
      $stmtPa->execute();
      $paRows = $stmtPa->get_result()->fetch_all(MYSQLI_ASSOC);
      foreach ($paRows as $r) {
        $p = (int)$r['principal_user_id'];
        $a = (int)$r['assistant_user_id'];
        $principalToAssistants[$p][] = $a;
        $assistantToPrincipal[$a] = $p;
      }
    }

    if (db_column_exists($conn, 'users', 'chief_assistant_user_id')) {
      $caSql = "
        SELECT id AS principal_user_id, chief_assistant_user_id AS assistant_user_id
        FROM users
        WHERE (id IN ($inPlaceholders) OR chief_assistant_user_id IN ($inPlaceholders))
          AND chief_assistant_user_id IS NOT NULL AND chief_assistant_user_id > 0
      ";
      $stmtCa = $conn->prepare($caSql);
      $caParams = array_merge($involvedUserIds, $involvedUserIds);
      $caTypes = $types . $types;
      $stmtCa->bind_param($caTypes, ...$caParams);
      $stmtCa->execute();
      $caRows = $stmtCa->get_result()->fetch_all(MYSQLI_ASSOC);
      foreach ($caRows as $r) {
        $p = (int)$r['principal_user_id'];
        $a = (int)$r['assistant_user_id'];
        $principalToAssistants[$p][] = $a;
        $assistantToPrincipal[$a] = $p;
      }
    }

    $neededUserIds = $involvedUserIds;
    foreach ($principalToAssistants as $p => $asts) {
      $neededUserIds[] = $p;
      foreach ($asts as $a) $neededUserIds[] = $a;
    }
    foreach ($assistantToPrincipal as $a => $p) {
      $neededUserIds[] = $a;
      $neededUserIds[] = $p;
    }
    $neededUserIds = array_values(array_unique($neededUserIds));

    $userMeta = [];
    if (!empty($neededUserIds)) {
      $nPlaceholders = implode(',', array_fill(0, count($neededUserIds), '?'));
      $nTypes = str_repeat('i', count($neededUserIds));
      $photoCol = $actorProfilePhotoColumn ? "`" . $conn->real_escape_string($actorProfilePhotoColumn) . "`" : "NULL";
      $uSql = "SELECT id, full_name, {$photoCol} AS photo FROM users WHERE id IN ($nPlaceholders)";
      $stmtU = $conn->prepare($uSql);
      $stmtU->bind_param($nTypes, ...$neededUserIds);
      $stmtU->execute();
      $uRows = $stmtU->get_result()->fetch_all(MYSQLI_ASSOC);
      foreach ($uRows as $r) {
        $uid = (int)$r['id'];
        $name = trim((string)$r['full_name']);
        $photo = app_profile_photo_url((string)($r['photo'] ?? ''));
        $initials = function_exists('app_user_initials') ? app_user_initials($name) : strtoupper(substr($name, 0, 1));
        $userMeta[$uid] = [
          'id' => $uid,
          'name' => $name,
          'photo' => $photo,
          'initials' => $initials
        ];
      }
    }

    foreach ($involvedUserIds as $uid) {
      $group = [];
      $pId = $assistantToPrincipal[$uid] ?? $uid;
      if (isset($userMeta[$pId])) {
        $group[] = $userMeta[$pId];
      }
      if (isset($principalToAssistants[$pId])) {
        foreach ($principalToAssistants[$pId] as $aId) {
          if (isset($userMeta[$aId])) {
            $group[] = $userMeta[$aId];
          }
        }
      }
      $uniqueGroup = [];
      $seen = [];
      foreach ($group as $g) {
        if (!isset($seen[$g['id']])) {
          $seen[$g['id']] = true;
          $uniqueGroup[] = $g;
        }
      }
      if (empty($uniqueGroup) && isset($userMeta[$uid])) {
        $uniqueGroup[] = $userMeta[$uid];
      }
      $userProfiles[$uid] = $uniqueGroup;
    }
  }

  echo json_encode([
    "ok" => true,
    "branch_mode" => $docHasRealBranches,
    "viewer_is_document_origin" => $viewerIsDocumentOrigin,
    "viewer_live_elapsed_enabled" => $viewerLiveElapsedEnabled,
    "viewer_live_elapsed_working_minutes" => $viewerLiveElapsedWorkingMinutes,
    "live_elapsed_working_minutes_by_user" => $liveElapsedMinutesByUser,
    "user_profiles" => $userProfiles,
    "branches" => $branches,
    "selected_branch_id" => $selectedBranchId > 0 ? $selectedBranchId : null,
    "history" => $history
  ], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error", "debug" => $e->getMessage()]);
  exit;
}
