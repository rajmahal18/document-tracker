<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_once __DIR__ . "/../core/working_time.php";
require_login();

$pageTitle = "Organizational Chart - Document Tracker";

$hasOfficialTitle = db_column_exists($conn, "users", "official_title");
$hasAuthorityRole = db_column_exists($conn, "users", "authority_role");
$hasLastSeenAt = db_column_exists($conn, "users", "last_seen_at");
$hasUsername = username_column_exists($conn);
$hasPermanent = db_column_exists($conn, "users", "permanent");
$hasChiefAssistant = db_column_exists($conn, "users", "chief_assistant_user_id");
$hasAssistantAssignments = assistant_assignments_table_ready($conn);
$profilePhotoColumn = null;
foreach (["profile_photo_url", "avatar_url", "photo_url"] as $candidatePhotoColumn) {
  if (db_column_exists($conn, "users", $candidatePhotoColumn)) {
    $profilePhotoColumn = $candidatePhotoColumn;
    break;
  }
}

$viewerDivisionId = (int)($_SESSION["division_id"] ?? 0);
$orgEditor = current_org_editor_context();
$canManageOrg = can_edit_any_org_user();
$orgEditorIsAdmin = !empty($orgEditor["is_admin"]) || is_admin_user();
$assignableRoles = org_assignable_roles_for_editor($orgEditor);
$nowTs = time();
$onlineWindow = 120;

$authorityWeight = [
  "director" => 10,
  "division_head" => 20,
  "division_assistant" => 30,
  "section_head" => 40,
  "staff" => 50,
  "admin" => 60,
];

function resolve_authority_role(array $row): string {
  $role = trim((string)($row["authority_role"] ?? ""));
  if ($role !== "") {
    return $role;
  }

  if ((string)($row["role"] ?? "") === "admin") {
    return "admin";
  }

  if ((int)($row["is_chief"] ?? 0) === 1) {
    return "section_head";
  }

  return "staff";
}

function resolve_display_title(array $row, string $authorityRole): string {
  $title = trim((string)($row["official_title"] ?? ""));
  if ($title !== "") {
    return $title;
  }

  return match ($authorityRole) {
    "director" => "Director",
    "division_head" => "Division Chief",
    "division_assistant" => "Assistant Division Chief",
    "section_head" => "Section Chief",
    "admin" => "Administrator",
    default => "Staff",
  };
}

function section_sort_weight(string $name): int {
  $normalized = strtolower(trim($name));
  if ($normalized === 'director office') {
    return 5;
  }
  if (str_contains($normalized, 'office of the division chief')) {
    return 10;
  }
  if (str_contains($normalized, 'office of the director')) {
    return 15;
  }
  return 50;
}

function is_leadership_role(string $authorityRole): bool {
  return in_array($authorityRole, ['director', 'division_head', 'division_assistant', 'section_head'], true);
}

function role_badge_label(string $authorityRole): string {
  return match ($authorityRole) {
    'director' => 'Director',
    'division_head' => 'Division Head',
    'division_assistant' => 'Division Assistant',
    'section_head' => 'Section Head',
    'admin' => 'Admin',
    default => 'Staff',
  };
}

function user_initials(string $name): string {
  $parts = preg_split('/\s+/', trim($name)) ?: [];
  $initials = '';
  foreach ($parts as $part) {
    $clean = trim($part, ".,-");
    if ($clean === '') continue;
    $initials .= mb_strtoupper(mb_substr($clean, 0, 1));
    if (mb_strlen($initials) >= 2) break;
  }
  return $initials !== '' ? $initials : 'U';
}

function org_profile_photo_url(?string $value): string {
  $value = trim((string)$value);
  if ($value === '') {
    return '';
  }
  if (preg_match('/^(https?:)?\/\//i', $value) || str_starts_with($value, 'data:image/')) {
    return $value;
  }
  return asset_url(ltrim($value, '/'));
}

function org_chart_document_stats(mysqli $conn): array {
  $stats = [];
  $ensure = static function (int $userId) use (&$stats): void {
    if ($userId <= 0) return;
    if (!isset($stats[$userId])) {
      $stats[$userId] = [
        'received' => 0,
        'forwarded' => 0,
        'incoming' => 0,
        'pending' => 0,
        'completed' => 0,
        'assistant_actions' => 0,
        'avg_working_minutes' => 0,
      ];
    }
  };

  $readCount = static function (string $sql, string $key) use ($conn, &$stats, $ensure): void {
    $res = $conn->query($sql);
    if (!$res) return;
    while ($row = $res->fetch_assoc()) {
      $userId = (int)($row['user_id'] ?? 0);
      $ensure($userId);
      if ($userId > 0) {
        $stats[$userId][$key] = (int)($row['total'] ?? 0);
      }
    }
    $res->free();
  };

  if (db_table_exists($conn, 'routes')) {
    $hasRoutesCancelled = db_column_exists($conn, 'routes', 'cancelled_at');
    $routeNotCancelled = $hasRoutesCancelled ? 'AND cancelled_at IS NULL' : '';
    $receivedSources = [];
    if (db_column_exists($conn, 'routes', 'received_by_user_id')) {
      $receivedSources[] = "SELECT received_by_user_id AS user_id, document_id FROM routes WHERE received_by_user_id IS NOT NULL AND received_by_user_id > 0 {$routeNotCancelled}";
    }
    if (db_column_exists($conn, 'routes', 'to_user_id') && db_column_exists($conn, 'routes', 'received_at')) {
      $receivedSources[] = "SELECT to_user_id AS user_id, document_id FROM routes WHERE to_user_id IS NOT NULL AND to_user_id > 0 AND received_at IS NOT NULL {$routeNotCancelled}";
    }
    if ($receivedSources !== []) {
      $readCount('SELECT user_id, COUNT(DISTINCT document_id) AS total FROM (' . implode(' UNION ', $receivedSources) . ') x GROUP BY user_id', 'received');
    }

    $forwardedSources = [];
    if (db_column_exists($conn, 'routes', 'sent_by_user_id')) {
      $forwardedSources[] = "SELECT sent_by_user_id AS user_id, document_id FROM routes WHERE sent_by_user_id IS NOT NULL AND sent_by_user_id > 0 {$routeNotCancelled}";
    }
    if (db_column_exists($conn, 'routes', 'from_user_id')) {
      $forwardedSources[] = "SELECT from_user_id AS user_id, document_id FROM routes WHERE from_user_id IS NOT NULL AND from_user_id > 0 {$routeNotCancelled}";
    }
    if ($forwardedSources !== []) {
      $readCount('SELECT user_id, COUNT(DISTINCT document_id) AS total FROM (' . implode(' UNION ', $forwardedSources) . ') x GROUP BY user_id', 'forwarded');
    }

    if (db_column_exists($conn, 'routes', 'to_user_id') && db_column_exists($conn, 'routes', 'received_at')) {
      $pendingSources = ["SELECT to_user_id AS user_id, document_id FROM routes WHERE to_user_id IS NOT NULL AND to_user_id > 0 AND received_at IS NULL {$routeNotCancelled}"];
      if (db_table_exists($conn, 'document_branches') && db_column_exists($conn, 'document_branches', 'current_assignee_user_id')) {
        $branchStatusSql = db_column_exists($conn, 'document_branches', 'branch_status') ? "AND branch_status = 'ACTIVE'" : '';
        $branchReferenceSql = db_column_exists($conn, 'document_branches', 'is_reference') ? 'AND is_reference = 0' : '';
        $pendingSources[] = "SELECT current_assignee_user_id AS user_id, document_id FROM document_branches WHERE current_assignee_user_id IS NOT NULL AND current_assignee_user_id > 0 {$branchStatusSql} {$branchReferenceSql}";
      }
      $readCount('SELECT user_id, COUNT(DISTINCT document_id) AS total FROM (' . implode(' UNION ', $pendingSources) . ') x GROUP BY user_id', 'pending');
    }
  }

  if (db_table_exists($conn, 'document_events') && db_column_exists($conn, 'document_events', 'payload_json')) {
    $workingMinutesByUser = [];
    $docsHandledByUser = [];
    $activeAssignmentsByUserDoc = [];

    $recordWorkingTime = static function(int $userId, int $documentId, int $mins) use (&$workingMinutesByUser, &$docsHandledByUser): void {
      if ($userId <= 0 || $documentId <= 0 || $mins <= 0) return;
      if (!isset($workingMinutesByUser[$userId])) {
        $workingMinutesByUser[$userId] = 0;
        $docsHandledByUser[$userId] = [];
      }
      $workingMinutesByUser[$userId] += $mins;
      $docsHandledByUser[$userId][$documentId] = true;
    };

    $actionDocsByUser = [];

    if (db_table_exists($conn, 'documents') && db_column_exists($conn, 'documents', 'current_status')) {
      $hasBranches = db_table_exists($conn, 'document_branches') && db_column_exists($conn, 'document_branches', 'current_assignee_user_id');
      
      if (db_column_exists($conn, 'documents', 'created_by_user_id')) {
        $resCreator = $conn->query("SELECT id, created_by_user_id FROM documents WHERE created_by_user_id > 0");
        if ($resCreator) {
          while ($row = $resCreator->fetch_assoc()) {
            $actionDocsByUser[(int)$row['created_by_user_id']][(int)$row['id']] = true;
          }
          $resCreator->free();
        }
      }

      if ($hasBranches) {
        $branchReferenceSql = db_column_exists($conn, 'document_branches', 'is_reference') ? 'AND is_reference = 0' : '';
        $resActionBranch = $conn->query("
          SELECT document_id, current_assignee_user_id AS user_id
          FROM document_branches
          WHERE current_assignee_user_id > 0
          {$branchReferenceSql}
        ");
        if ($resActionBranch) {
          while ($row = $resActionBranch->fetch_assoc()) {
            $actionDocsByUser[(int)$row['user_id']][(int)$row['document_id']] = true;
          }
          $resActionBranch->free();
        }
      }

      if (db_table_exists($conn, 'routes')) {
        $routeKindSql = db_column_exists($conn, 'routes', 'route_kind') ? "AND route_kind = 'ACTION'" : '';
        $hasToUser = db_column_exists($conn, 'routes', 'to_user_id');
        $hasReceivedBy = db_column_exists($conn, 'routes', 'received_by_user_id');
        $routeSources = [];
        if ($hasToUser) $routeSources[] = "SELECT document_id, to_user_id AS user_id FROM routes WHERE to_user_id > 0 {$routeKindSql}";
        if ($hasReceivedBy) $routeSources[] = "SELECT document_id, received_by_user_id AS user_id FROM routes WHERE received_by_user_id > 0 {$routeKindSql}";
        if ($routeSources !== []) {
            $resActionRoute = $conn->query(implode(" UNION ", $routeSources));
            if ($resActionRoute) {
              while ($row = $resActionRoute->fetch_assoc()) {
                $actionDocsByUser[(int)$row['user_id']][(int)$row['document_id']] = true;
              }
              $resActionRoute->free();
            }
        }
      }

      if ($hasBranches) {
        $branchStatusSql = db_column_exists($conn, 'document_branches', 'branch_status') ? "AND b.branch_status = 'ACTIVE'" : '';
        $branchReferenceSql = db_column_exists($conn, 'document_branches', 'is_reference') ? "AND b.is_reference = 0" : '';
        $resActiveBranches = $conn->query("
          SELECT b.document_id, b.current_assignee_user_id AS user_id
          FROM document_branches b
          JOIN documents d ON d.id = b.document_id
          WHERE d.current_status = 'ACTIVE'
            AND b.current_assignee_user_id IS NOT NULL
            AND b.current_assignee_user_id > 0
            {$branchStatusSql}
            {$branchReferenceSql}
        ");
        if ($resActiveBranches) {
          while ($row = $resActiveBranches->fetch_assoc()) {
            $uid = (int)($row['user_id'] ?? 0);
            $did = (int)($row['document_id'] ?? 0);
            if ($uid > 0 && $did > 0) {
                if (!isset($activeAssignmentsByUserDoc[$uid])) $activeAssignmentsByUserDoc[$uid] = [];
                $activeAssignmentsByUserDoc[$uid][$did] = true;
            }
          }
          $resActiveBranches->free();
        }
      }

      if (db_table_exists($conn, 'routes') && db_column_exists($conn, 'routes', 'received_by_user_id')) {
        $routeNotCancelled = db_column_exists($conn, 'routes', 'cancelled_at') ? 'AND r.cancelled_at IS NULL' : '';
        $routeKindSql = db_column_exists($conn, 'routes', 'route_kind') ? "AND r.route_kind = 'ACTION'" : '';
        $routeKindSubSql = db_column_exists($conn, 'routes', 'route_kind') ? "AND r2.route_kind = 'ACTION'" : '';
        $noBranchCond = $hasBranches ? "AND NOT EXISTS (SELECT 1 FROM document_branches b2 WHERE b2.document_id = d.id)" : "";
        $resLegacyActive = $conn->query("
          SELECT d.id AS document_id, r.received_by_user_id AS user_id
          FROM documents d
          JOIN routes r ON r.document_id = d.id
          WHERE d.current_status = 'ACTIVE'
            AND r.received_by_user_id IS NOT NULL
            AND r.received_by_user_id > 0
            AND r.received_at IS NOT NULL
            {$routeKindSql}
            {$routeNotCancelled}
            {$noBranchCond}
            AND r.id = (
               SELECT MAX(id) FROM routes r2 WHERE r2.document_id = d.id AND r2.received_at IS NOT NULL {$routeNotCancelled} {$routeKindSubSql}
            )
        ");
        if ($resLegacyActive) {
          while ($row = $resLegacyActive->fetch_assoc()) {
            $uid = (int)($row['user_id'] ?? 0);
            $did = (int)($row['document_id'] ?? 0);
            if ($uid > 0 && $did > 0) {
                if (!isset($activeAssignmentsByUserDoc[$uid])) $activeAssignmentsByUserDoc[$uid] = [];
                $activeAssignmentsByUserDoc[$uid][$did] = true;
            }
          }
          $resLegacyActive->free();
        }
      }
    }

    $resEventActions = $conn->query("
      SELECT
        de.document_id,
        COALESCE(NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(de.payload_json, '$.acting_principal_user_id')) AS UNSIGNED), 0), de.actor_user_id) AS user_id,
        de.created_at,
        de.event_type,
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT(de.payload_json, '$.kind')), '') AS payload_kind,
        CAST(JSON_UNQUOTE(JSON_EXTRACT(de.payload_json, '$.elapsed_working_minutes')) AS SIGNED) AS elapsed_mins,
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT(de.payload_json, '$.route_kind')), '') AS route_kind
      FROM document_events de
      WHERE de.created_at IS NOT NULL
      ORDER BY de.document_id ASC, de.created_at ASC, de.id ASC
    ");

    if ($resEventActions) {
      $currentStintByUserDoc = [];

      while ($row = $resEventActions->fetch_assoc()) {
        $userId = (int)($row['user_id'] ?? 0);
        $documentId = (int)($row['document_id'] ?? 0);
        if ($userId <= 0 || $documentId <= 0) continue;

        $eventType = strtolower(trim((string)($row['event_type'] ?? '')));
        $payloadKind = strtolower(trim((string)($row['payload_kind'] ?? '')));
        $routeKind = strtoupper(trim((string)($row['route_kind'] ?? '')));
        
        $action = $eventType;
        if ($eventType === 'updated' && in_array($payloadKind, ['branch_ended_here', 'document_ended_here', 'attachment_forwarded', 'attachment_forward_task_done'], true)) {
            $action = $payloadKind;
        }
        if ($eventType === 'updated' && $payloadKind === 'attachment_added') {
            $action = $payloadKind;
        }

        $createdAt = (string)($row['created_at'] ?? '');
        $elapsedMins = (int)($row['elapsed_mins'] ?? 0);

        if (!isset($currentStintByUserDoc[$userId])) $currentStintByUserDoc[$userId] = [];

        if ($action === 'received' || $action === 'created') {
            if (isset($currentStintByUserDoc[$userId][$documentId])) {
                $stintMax = $currentStintByUserDoc[$userId][$documentId]['max_mins'];
                $isRef = $currentStintByUserDoc[$userId][$documentId]['is_reference'] ?? false;
                if ($stintMax > 0 && !$isRef) {
                    $recordWorkingTime($userId, $documentId, $stintMax);
                }
            }
            $currentStintByUserDoc[$userId][$documentId] = [
                'start_at' => $createdAt,
                'max_mins' => 0,
                'is_open' => true,
                'is_reference' => ($routeKind === 'REFERENCE')
            ];
        } else {
            if (!isset($currentStintByUserDoc[$userId][$documentId])) {
                $currentStintByUserDoc[$userId][$documentId] = [
                    'start_at' => $createdAt,
                    'max_mins' => 0,
                    'is_open' => true,
                    'is_reference' => false
                ];
            }

            if ($elapsedMins > 0) {
                $currentStintByUserDoc[$userId][$documentId]['max_mins'] = max($currentStintByUserDoc[$userId][$documentId]['max_mins'], $elapsedMins);
            } else {
                if (in_array($action, ['sent', 'forwarded', 'released', 'branch_ended_here', 'document_ended_here', 'attachment_forwarded'], true)) {
                    $startAt = $currentStintByUserDoc[$userId][$documentId]['start_at'];
                    $calcMins = dt_working_minutes_between($startAt, $createdAt, $conn);
                    if ($calcMins <= 0) $calcMins = 1;
                    $currentStintByUserDoc[$userId][$documentId]['max_mins'] = max($currentStintByUserDoc[$userId][$documentId]['max_mins'], $calcMins);
                }
            }

            $isCompletionLike = in_array($action, ['sent', 'forwarded', 'released', 'branch_ended_here', 'document_ended_here', 'attachment_forwarded'], true);
            if ($isCompletionLike) {
                $currentStintByUserDoc[$userId][$documentId]['is_open'] = false;
            }
        }
      }
      $resEventActions->free();

      foreach ($currentStintByUserDoc as $uid => $docs) {
          foreach ($docs as $did => $stint) {
              if (!empty($stint['is_reference'])) {
                  continue;
              }

              // Prevent any minute logging if the user's involvement in this document was strictly for reference
              if (!isset($actionDocsByUser[$uid][$did])) {
                  continue;
              }

              $totalForStint = $stint['max_mins'];

              if ($stint['is_open'] && isset($activeAssignmentsByUserDoc[$uid][$did])) {
                  $liveMins = dt_working_minutes_between($stint['start_at'], null, $conn);
                  if ($liveMins <= 0) $liveMins = 1;
                  $totalForStint = max($totalForStint, $liveMins);
              }

              if ($totalForStint > 0) {
                  $recordWorkingTime($uid, $did, $totalForStint);
              }
          }
      }
    }

    foreach ($workingMinutesByUser as $uid => $sumMins) {
      $uid = (int)$uid;
      $docCount = count($docsHandledByUser[$uid] ?? []);
      if ($uid <= 0 || $docCount <= 0 || $sumMins <= 0) continue;
      $ensure($uid);
      $stats[$uid]['avg_working_minutes'] = (int)round($sumMins / $docCount);
    }

    $readCount("
      SELECT assistant_user_id AS user_id, COUNT(DISTINCT document_id) AS total
      FROM (
        SELECT
          document_id,
          CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.assistant_actual_user_id')) AS UNSIGNED) AS assistant_user_id
        FROM document_events
        WHERE payload_json IS NOT NULL
          AND JSON_EXTRACT(payload_json, '$.assistant_actual_user_id') IS NOT NULL
      ) x
      WHERE assistant_user_id > 0
      GROUP BY assistant_user_id
    ", 'assistant_actions');
  }

  $incomingDocs = [];
  $pendingDocs = [];
  $participatedDocs = [];
  $completedCandidateDocs = [];
  $addDoc = static function (array &$bucket, int $userId, int $documentId) use ($ensure): void {
    if ($userId <= 0 || $documentId <= 0) return;
    $ensure($userId);
    if (!isset($bucket[$userId])) $bucket[$userId] = [];
    $bucket[$userId][$documentId] = true;
  };
  $isCompletedStatus = static function (?string $status): bool {
    return in_array(strtoupper(trim((string)($status ?? 'ACTIVE'))), ['RELEASED', 'ARCHIVED'], true);
  };

  if (db_table_exists($conn, 'documents') && db_column_exists($conn, 'documents', 'created_by_user_id')) {
    $documentStatusExpr = db_column_exists($conn, 'documents', 'current_status') ? 'current_status' : "'ACTIVE'";
    $res = $conn->query("SELECT id AS document_id, created_by_user_id AS user_id, {$documentStatusExpr} AS current_status FROM documents WHERE created_by_user_id IS NOT NULL AND created_by_user_id > 0");
    if ($res) {
      while ($row = $res->fetch_assoc()) {
        $addDoc($participatedDocs, (int)($row['user_id'] ?? 0), (int)($row['document_id'] ?? 0));
        if ($isCompletedStatus((string)($row['current_status'] ?? 'ACTIVE'))) {
          $addDoc($completedCandidateDocs, (int)($row['user_id'] ?? 0), (int)($row['document_id'] ?? 0));
        }
      }
      $res->free();
    }
  }

  if (db_table_exists($conn, 'routes')) {
    $routeCancelledExpr = db_column_exists($conn, 'routes', 'cancelled_at') ? 'cancelled_at' : 'NULL';
    $routeReceivedExpr = db_column_exists($conn, 'routes', 'received_at') ? 'received_at' : 'NULL';
    $routeKindExpr = db_column_exists($conn, 'routes', 'route_kind') ? 'route_kind' : "'ACTION'";
    $routeToExpr = db_column_exists($conn, 'routes', 'to_user_id') ? 'to_user_id' : 'NULL';
    $routeSentByExpr = db_column_exists($conn, 'routes', 'sent_by_user_id') ? 'sent_by_user_id' : 'NULL';
    $routeReceivedByExpr = db_column_exists($conn, 'routes', 'received_by_user_id') ? 'received_by_user_id' : 'NULL';
    $routeStatusJoinSql = db_table_exists($conn, 'documents') ? 'LEFT JOIN documents d_done ON d_done.id = routes.document_id' : '';
    $routeStatusExpr = db_table_exists($conn, 'documents') && db_column_exists($conn, 'documents', 'current_status') ? 'd_done.current_status' : "'ACTIVE'";
    $res = $conn->query("
      SELECT
        routes.document_id,
        {$routeToExpr} AS to_user_id,
        {$routeSentByExpr} AS sent_by_user_id,
        {$routeReceivedByExpr} AS received_by_user_id,
        {$routeReceivedExpr} AS received_at,
        {$routeCancelledExpr} AS cancelled_at,
        {$routeKindExpr} AS route_kind,
        {$routeStatusExpr} AS current_status
      FROM routes
      {$routeStatusJoinSql}
    ");
    if ($res) {
      while ($row = $res->fetch_assoc()) {
        $documentId = (int)($row['document_id'] ?? 0);
        $toUserId = (int)($row['to_user_id'] ?? 0);
        $sentByUserId = (int)($row['sent_by_user_id'] ?? 0);
        $receivedByUserId = (int)($row['received_by_user_id'] ?? 0);
        $isCancelled = trim((string)($row['cancelled_at'] ?? '')) !== '';
        $isReceived = trim((string)($row['received_at'] ?? '')) !== '';
        $isActionRoute = strtoupper(trim((string)($row['route_kind'] ?? 'ACTION'))) === 'ACTION';
        $isCompleted = $isCompletedStatus((string)($row['current_status'] ?? 'ACTIVE'));

        $addDoc($participatedDocs, $toUserId, $documentId);
        $addDoc($participatedDocs, $sentByUserId, $documentId);
        $addDoc($participatedDocs, $receivedByUserId, $documentId);
        if ($isCompleted) {
          $addDoc($completedCandidateDocs, $toUserId, $documentId);
          $addDoc($completedCandidateDocs, $sentByUserId, $documentId);
          $addDoc($completedCandidateDocs, $receivedByUserId, $documentId);
        }
        if (!$isCancelled && !$isReceived && $isActionRoute) {
          $addDoc($incomingDocs, $toUserId, $documentId);
        }
      }
      $res->free();
    }
  }

  if (db_table_exists($conn, 'document_branches') && db_column_exists($conn, 'document_branches', 'current_assignee_user_id')) {
    $branchStatusExpr = db_column_exists($conn, 'document_branches', 'branch_status') ? 'branch_status' : "'ACTIVE'";
    $branchReferenceExpr = db_column_exists($conn, 'document_branches', 'is_reference') ? 'is_reference' : '0';
    $branchStatusJoinSql = db_table_exists($conn, 'documents') ? 'LEFT JOIN documents d_done ON d_done.id = document_branches.document_id' : '';
    $branchDocumentStatusExpr = db_table_exists($conn, 'documents') && db_column_exists($conn, 'documents', 'current_status') ? 'd_done.current_status' : "'ACTIVE'";
    $res = $conn->query("
      SELECT document_branches.document_id, current_assignee_user_id AS user_id, {$branchStatusExpr} AS branch_status, {$branchReferenceExpr} AS is_reference, {$branchDocumentStatusExpr} AS current_status
      FROM document_branches
      {$branchStatusJoinSql}
      WHERE current_assignee_user_id IS NOT NULL AND current_assignee_user_id > 0
    ");
    if ($res) {
      while ($row = $res->fetch_assoc()) {
        $userId = (int)($row['user_id'] ?? 0);
        $documentId = (int)($row['document_id'] ?? 0);
        $status = strtoupper(trim((string)($row['branch_status'] ?? 'ACTIVE')));
        $isReference = (int)($row['is_reference'] ?? 0) === 1;
        $addDoc($participatedDocs, $userId, $documentId);
        if ($isCompletedStatus((string)($row['current_status'] ?? 'ACTIVE'))) {
          $addDoc($completedCandidateDocs, $userId, $documentId);
        }
        if ($status === 'ACTIVE' && !$isReference) {
          $addDoc($pendingDocs, $userId, $documentId);
        }
      }
      $res->free();
    }
  }

  if (db_table_exists($conn, 'document_events') && db_column_exists($conn, 'document_events', 'payload_json')) {
    $eventStatusJoinSql = db_table_exists($conn, 'documents') ? 'LEFT JOIN documents d_done ON d_done.id = x.document_id' : '';
    $eventStatusExpr = db_table_exists($conn, 'documents') && db_column_exists($conn, 'documents', 'current_status') ? 'd_done.current_status' : "'ACTIVE'";
    $res = $conn->query("
      SELECT
        x.document_id,
        x.assistant_user_id,
        {$eventStatusExpr} AS current_status
      FROM (
        SELECT
          document_id,
          CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.assistant_actual_user_id')) AS UNSIGNED) AS assistant_user_id
        FROM document_events
        WHERE payload_json IS NOT NULL
          AND JSON_EXTRACT(payload_json, '$.assistant_actual_user_id') IS NOT NULL
      ) x
      {$eventStatusJoinSql}
      WHERE x.assistant_user_id > 0
    ");
    if ($res) {
      while ($row = $res->fetch_assoc()) {
        $addDoc($participatedDocs, (int)($row['assistant_user_id'] ?? 0), (int)($row['document_id'] ?? 0));
        if ($isCompletedStatus((string)($row['current_status'] ?? 'ACTIVE'))) {
          $addDoc($completedCandidateDocs, (int)($row['assistant_user_id'] ?? 0), (int)($row['document_id'] ?? 0));
        }
      }
      $res->free();
    }
  }

  $allUserIds = array_unique(array_merge(array_keys($participatedDocs), array_keys($completedCandidateDocs), array_keys($incomingDocs), array_keys($pendingDocs), array_keys($stats)));
  foreach ($allUserIds as $userId) {
    $userId = (int)$userId;
    $ensure($userId);
    $incoming = $incomingDocs[$userId] ?? [];
    $pending = $pendingDocs[$userId] ?? [];
    $completed = $completedCandidateDocs[$userId] ?? [];
    foreach (array_keys($incoming + $pending) as $documentId) {
      unset($completed[$documentId]);
    }
    $stats[$userId]['incoming'] = count($incoming);
    $stats[$userId]['pending'] = count($pending);
    $stats[$userId]['completed'] = count($completed);
  }

  if (db_table_exists($conn, 'documents') && db_table_exists($conn, 'routes')) {
    $ctxSql = "
      SELECT
        u.id,
        u.section_id,
        u.is_chief,
        COALESCE(u.authority_role, '') AS authority_role
      FROM users u
      JOIN sections s ON s.id = u.section_id
      JOIN divisions d ON d.id = s.division_id
      WHERE u.is_active = 1
        AND s.is_active = 1
        AND d.is_active = 1
    ";
    $ctxRes = $conn->query($ctxSql);
    if ($ctxRes) {
      $hasBranches = db_table_exists($conn, 'document_branches');
      $branchPredicate = $hasBranches
        ? "EXISTS (SELECT 1 FROM document_branches b_chk WHERE b_chk.document_id = d.id)"
        : "0=1";
      $noBranchPredicate = $hasBranches
        ? "NOT EXISTS (SELECT 1 FROM document_branches b_chk WHERE b_chk.document_id = d.id)"
        : "1=1";
      $hasEvents = db_table_exists($conn, 'document_events') && db_column_exists($conn, 'document_events', 'payload_json');

      while ($ctx = $ctxRes->fetch_assoc()) {
        $userId = (int)($ctx['id'] ?? 0);
        $sectionId = (int)($ctx['section_id'] ?? 0);
        if ($userId <= 0 || $sectionId <= 0) {
          continue;
        }
        $authorityRole = trim((string)($ctx['authority_role'] ?? ''));
        $isChief = ((int)($ctx['is_chief'] ?? 0) === 1) || in_array($authorityRole, ['director', 'division_head', 'section_head'], true);
        $chiefInt = $isChief ? 1 : 0;

        $incomingPredicate = "EXISTS (
          SELECT 1
          FROM routes r_in
          WHERE r_in.document_id = d.id
            AND r_in.received_at IS NULL
            AND r_in.cancelled_at IS NULL
            AND (
              (
                {$branchPredicate}
                AND r_in.route_kind = 'ACTION'
                AND r_in.to_user_id = {$userId}
                AND EXISTS (
                  SELECT 1
                  FROM document_branches b_in
                  WHERE b_in.id = r_in.branch_id
                    AND b_in.current_assignee_user_id = r_in.to_user_id
                )
              )
              OR
              (
                {$noBranchPredicate}
                AND (
                  r_in.to_user_id = {$userId}
                  OR ({$chiefInt} = 1 AND r_in.to_user_id IS NULL AND r_in.to_section_id = {$sectionId})
                )
              )
            )
        )";

        $pendingPredicate = "(
          (
            {$branchPredicate}
            AND EXISTS (
              SELECT 1
              FROM document_branches b_act2
              WHERE b_act2.document_id = d.id
                AND b_act2.branch_status = 'ACTIVE'
                AND b_act2.current_assignee_user_id = {$userId}
                AND b_act2.is_reference = 0
            )
          )
          OR
          (
            {$noBranchPredicate}
            AND d.current_status = 'ACTIVE'
            AND d.current_holder_section_id = {$sectionId}
            AND NOT EXISTS (
              SELECT 1
              FROM routes r_act_legacy
              WHERE r_act_legacy.document_id = d.id
                AND r_act_legacy.route_kind = 'ACTION'
                AND r_act_legacy.received_at IS NULL
                AND r_act_legacy.cancelled_at IS NULL
            )
            AND (
              (
                d.created_by_user_id = {$userId}
                AND NOT EXISTS (
                  SELECT 1
                  FROM routes r_received_any
                  WHERE r_received_any.document_id = d.id
                    AND r_received_any.route_kind = 'ACTION'
                    AND r_received_any.received_at IS NOT NULL
                    AND r_received_any.cancelled_at IS NULL
                )
              )
              OR EXISTS (
                SELECT 1
                FROM routes r_last_received
                WHERE r_last_received.id = (
                  SELECT r_last_pick.id
                  FROM routes r_last_pick
                  WHERE r_last_pick.document_id = d.id
                    AND r_last_pick.route_kind = 'ACTION'
                    AND r_last_pick.received_at IS NOT NULL
                    AND r_last_pick.cancelled_at IS NULL
                  ORDER BY r_last_pick.received_at DESC, r_last_pick.id DESC
                  LIMIT 1
                )
                AND (
                  r_last_received.to_user_id = {$userId}
                  OR (
                    r_last_received.to_user_id IS NULL
                    AND {$chiefInt} = 1
                    AND r_last_received.to_section_id = {$sectionId}
                  )
                )
              )
            )
          )
        )";

        $assistantOwnIsolationPredicate = "1=1";
        if ($hasEvents && assistant_assignments_table_ready($conn)) {
          $assistantOwnIsolationPredicate = "NOT (
            EXISTS (SELECT 1 FROM principal_assistants pa_iso WHERE pa_iso.assistant_user_id = {$userId})
            AND EXISTS (
              SELECT 1
              FROM document_events e_acting
              WHERE e_acting.document_id = d.id
                AND e_acting.actor_user_id = {$userId}
                AND e_acting.payload_json REGEXP '\"acting_principal_user_id\"[[:space:]]*:[[:space:]]*[1-9]'
            )
            AND d.created_by_user_id <> {$userId}
            AND NOT EXISTS (
              SELECT 1
              FROM routes r_direct
              WHERE r_direct.document_id = d.id
                AND r_direct.to_user_id = {$userId}
            )
          )";
        }

        $participationPredicate = "(
          d.created_by_user_id = {$userId}
          OR EXISTS (
            SELECT 1
            FROM routes r_part
            WHERE r_part.document_id = d.id
              AND (
                r_part.to_user_id = {$userId}
                OR r_part.sent_by_user_id = {$userId}
                OR r_part.received_by_user_id = {$userId}
              )
          )" . ($hasEvents ? "
          OR EXISTS (
            SELECT 1
            FROM document_events e_part
            WHERE e_part.document_id = d.id
              AND e_part.event_type IN ('sent', 'forwarded')
              AND e_part.payload_json REGEXP '\"acting_principal_user_id\"[[:space:]]*:[[:space:]]*{$userId}([^0-9]|$)'
          )" : "") . "
        )";

        $completePredicate = "(
          NOT ({$incomingPredicate})
          AND NOT ({$pendingPredicate})
          AND {$participationPredicate}
        )";

        $statRes = $conn->query("
          SELECT
            SUM(d.current_status = 'ACTIVE' AND ({$incomingPredicate})) AS incoming,
            SUM(d.current_status = 'ACTIVE' AND ({$pendingPredicate})) AS pending,
            SUM({$completePredicate}) AS completed
          FROM documents d
          WHERE {$assistantOwnIsolationPredicate}
        ");
        if ($statRes) {
          $row = $statRes->fetch_assoc() ?: [];
          $ensure($userId);
          $stats[$userId]['incoming'] = (int)($row['incoming'] ?? 0);
          $stats[$userId]['pending'] = (int)($row['pending'] ?? 0);
          $stats[$userId]['completed'] = (int)($row['completed'] ?? 0);
          $statRes->free();
        }
      }
      $ctxRes->free();
    }
  }

  return $stats;
}

function org_chart_assistant_principal_rollup(mysqli $conn): array {
  if (!assistant_assignments_table_ready($conn)) {
    return [];
  }

  $res = $conn->query("
    SELECT
      pa.assistant_user_id AS user_id,
      COUNT(DISTINCT pa.principal_user_id) AS total,
      GROUP_CONCAT(pu.full_name ORDER BY pu.full_name SEPARATOR ', ') AS names
    FROM principal_assistants pa
    JOIN users pu ON pu.id = pa.principal_user_id
    WHERE pu.is_active = 1
    GROUP BY pa.assistant_user_id
  ");
  if (!$res) return [];

  $rollup = [];
  while ($row = $res->fetch_assoc()) {
    $userId = (int)($row['user_id'] ?? 0);
    if ($userId > 0) {
      $rollup[$userId] = [
        'assistant_for_count' => (int)($row['total'] ?? 0),
        'assistant_for_names' => trim((string)($row['names'] ?? '')),
      ];
    }
  }
  $res->free();
  return $rollup;
}

function division_kicker(string $divisionName): string {
  $n = strtolower($divisionName);
  if (str_contains($n, 'director office')) return 'Top Office';
  if (str_contains($n, 'planning') && str_contains($n, 'programming')) return 'Planning + Programming';
  if (str_contains($n, 'survey') && str_contains($n, 'design')) return 'Survey + Design';
  if (str_contains($n, 'special project')) return 'Special Projects';
  return 'Division';
}

function search_blob(string ...$values): string {
  $joined = implode(' ', array_map(static fn(string $value): string => strtolower(trim($value)), $values));
  return preg_replace('/\s+/', ' ', $joined) ?? '';
}

function render_org_user_card(array $user, bool $leader = false): string {
  $classes = 'orgUserCard' . ($leader ? ' isLeader' : '');
  $presence = '';
  if (!empty($user['show_presence'])) {
    $presenceClass = !empty($user['is_online']) ? 'presencePill isOnline' : 'presencePill';
    $presenceText = !empty($user['is_online']) ? 'Online now' : 'Offline';
    $presence = '<span class="' . htmlspecialchars($presenceClass) . '"><span class="presenceDot"></span>' . htmlspecialchars($presenceText) . '</span>';
  }

  $search = search_blob(
    (string)($user['full_name'] ?? ''),
    (string)($user['display_title'] ?? ''),
    (string)($user['section_name'] ?? ''),
    (string)($user['authority_role'] ?? ''),
    (string)($user['chief_assistant_names'] ?? $user['chief_assistant_name'] ?? '')
  );

  $canEdit = !empty($user['can_edit']);
  $canAssignAssistant = !empty($user['can_assign_assistant']);
  $editBtn = '';
  if ($canEdit || $canAssignAssistant) {
    $editBtn = '<button type="button" class="orgTinyEditBtn" '
      . 'data-org-edit="1" '
      . 'data-user-id="' . (int)($user['id'] ?? 0) . '" '
      . 'data-full-name="' . htmlspecialchars((string)($user['full_name'] ?? ''), ENT_QUOTES) . '" '
      . 'data-email="' . htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES) . '" '
      . 'data-title="' . htmlspecialchars((string)($user['official_title'] ?? ''), ENT_QUOTES) . '" '
      . 'data-authority-role="' . htmlspecialchars((string)($user['authority_role'] ?? ''), ENT_QUOTES) . '" '
      . 'data-section-name="' . htmlspecialchars((string)($user['section_name'] ?? ''), ENT_QUOTES) . '" '
      . 'data-division-name="' . htmlspecialchars((string)($user['division_name'] ?? ''), ENT_QUOTES) . '" '
      . 'data-permanent="' . (int)($user['permanent'] ?? 0) . '" '
      . 'data-can-edit-basic="' . ($canEdit ? '1' : '0') . '" '
      . 'data-can-assign-assistant="' . ($canAssignAssistant ? '1' : '0') . '" '
      . 'data-chief-assistant-user-id="' . (int)($user['chief_assistant_user_id'] ?? 0) . '" '
      . 'data-chief-assistant-user-ids="' . htmlspecialchars((string)($user['chief_assistant_user_ids'] ?? ''), ENT_QUOTES) . '" '
      . 'data-chief-assistant-name="' . htmlspecialchars((string)($user['chief_assistant_name'] ?? ''), ENT_QUOTES) . '" '
      . 'data-chief-assistant-names="' . htmlspecialchars((string)($user['chief_assistant_names'] ?? ''), ENT_QUOTES) . '" '
      . 'data-assistant-candidates="' . htmlspecialchars((string)($user['assistant_candidates_json'] ?? '[]'), ENT_QUOTES) . '" '
      . 'data-can-edit="1">Edit</button>';
  }

  $assistantMeta = '';
  if (trim((string)($user['chief_assistant_names'] ?? '')) !== '') {
    $assistantMeta = '<p class="orgUserAssistant">Assigned assistants: ' . htmlspecialchars((string)$user['chief_assistant_names']) . '</p>';
  } elseif ($canAssignAssistant && org_user_is_assistant_assignable_principal((string)($user['authority_role'] ?? ''))) {
    $assistantMeta = '<p class="orgUserAssistant isMuted">No assistants assigned yet</p>';
  }

  return '<article class="' . htmlspecialchars($classes) . '" data-search="' . htmlspecialchars($search) . '">' .
    '<div class="orgUserCore">' .
      '<div class="orgUserAvatar role-' . htmlspecialchars((string)$user['authority_role']) . '" aria-hidden="true">' . htmlspecialchars(user_initials((string)$user['full_name'])) . '</div>' .
      '<div class="orgUserContent">' .
        '<div class="orgUserTopline">' .
          '<h5 class="orgUserName">' . htmlspecialchars((string)$user['full_name']) . '</h5>' .
          '<span class="orgRoleBadge role-' . htmlspecialchars((string)$user['authority_role']) . '">' . htmlspecialchars(role_badge_label((string)$user['authority_role'])) . '</span>' .
        '</div>' .
        '<p class="orgUserMeta">' . htmlspecialchars((string)$user['display_title']) . '</p>' .
        '<p class="orgUserSection">' . htmlspecialchars((string)$user['section_name']) . '</p>' .
        $assistantMeta .
      '</div>' .
    '</div>' .
    '<div class="orgUserTools">' . $editBtn . $presence . '</div>' .
  '</article>';
}

$divisions = [];
$divRes = $conn->query("\n  SELECT id, name\n  FROM divisions\n  WHERE is_active = 1\n  ORDER BY id ASC, name ASC\n");
if ($divRes) {
  while ($row = $divRes->fetch_assoc()) {
    $divisionId = (int)($row["id"] ?? 0);
    $divisionName = trim((string)($row["name"] ?? ""));
    $divisions[$divisionId] = [
      "id" => $divisionId,
      "name" => $divisionName,
      "sections" => [],
      "sort_weight" => str_contains(strtolower($divisionName), 'director office') ? 0 : 100,
    ];
  }
}

$secRes = $conn->query("\n  SELECT id, division_id, name\n  FROM sections\n  WHERE is_active = 1\n  ORDER BY division_id ASC, id ASC, name ASC\n");
if ($secRes) {
  while ($row = $secRes->fetch_assoc()) {
    $divisionId = (int)($row["division_id"] ?? 0);
    $sectionId = (int)($row["id"] ?? 0);
    if (!isset($divisions[$divisionId])) {
      continue;
    }
    $sectionName = trim((string)($row["name"] ?? ""));
    $divisions[$divisionId]["sections"][$sectionId] = [
      "id" => $sectionId,
      "name" => $sectionName,
      "users" => [],
      "sort_weight" => section_sort_weight($sectionName),
      "is_chief_office" => str_contains(strtolower($sectionName), 'office of the division chief')
        || str_contains(strtolower($sectionName), 'director office')
        || str_contains(strtolower($sectionName), 'office of the director'),
    ];
  }
}

$documentStatsByUser = org_chart_document_stats($conn);
$assistantPrincipalRollupByUser = org_chart_assistant_principal_rollup($conn);

$userSql = "
  SELECT
    u.id,
    u.full_name,
    " . ($hasUsername ? "u.username" : "NULL") . " AS username,
    u.email,
    u.role,
    u.section_id,
    u.is_chief,
    " . ($hasPermanent ? "u.permanent" : "0") . " AS permanent,
    " . ($hasOfficialTitle ? "u.official_title" : "NULL") . " AS official_title,
    " . ($hasAuthorityRole ? "u.authority_role" : "NULL") . " AS authority_role,
    " . ($hasLastSeenAt ? "u.last_seen_at" : "NULL") . " AS last_seen_at,
    " . ($profilePhotoColumn !== null ? "u.`" . $conn->real_escape_string($profilePhotoColumn) . "`" : "NULL") . " AS profile_photo_url,
    s.name AS section_name,
    s.id AS resolved_section_id,
    d.id AS division_id,
    " . ($hasChiefAssistant ? "u.chief_assistant_user_id" : "NULL") . " AS chief_assistant_user_id,
    " . ($hasChiefAssistant ? "ca.full_name" : "NULL") . " AS chief_assistant_name,
    " . ($hasAssistantAssignments ? "COALESCE(pa_rollup.assistant_ids, '')" : ($hasChiefAssistant ? "COALESCE(CAST(u.chief_assistant_user_id AS CHAR), '')" : "''")) . " AS chief_assistant_user_ids,
    " . ($hasAssistantAssignments ? "COALESCE(pa_rollup.assistant_names, '')" : ($hasChiefAssistant ? "COALESCE(ca.full_name, '')" : "''")) . " AS chief_assistant_names,
    d.name AS division_name
  FROM users u
  JOIN sections s ON s.id = u.section_id
  JOIN divisions d ON d.id = s.division_id
  " . ($hasChiefAssistant ? "LEFT JOIN users ca ON ca.id = u.chief_assistant_user_id" : "") . "
  " . ($hasAssistantAssignments ? "
  LEFT JOIN (
    SELECT
      pa.principal_user_id,
      GROUP_CONCAT(CAST(pa.assistant_user_id AS CHAR) ORDER BY au.full_name SEPARATOR ',') AS assistant_ids,
      GROUP_CONCAT(au.full_name ORDER BY au.full_name SEPARATOR ', ') AS assistant_names
    FROM principal_assistants pa
    JOIN users au ON au.id = pa.assistant_user_id
    WHERE au.is_active = 1
    GROUP BY pa.principal_user_id
  ) pa_rollup ON pa_rollup.principal_user_id = u.id
  " : "") . "
  WHERE u.is_active = 1
    AND s.is_active = 1
    AND d.is_active = 1
  ORDER BY d.id ASC, s.id ASC, u.full_name ASC
";

$userRes = $conn->query($userSql);

if ($userRes) {
  while ($row = $userRes->fetch_assoc()) {
    $divisionId = (int)($row["division_id"] ?? 0);
    $sectionId = (int)($row["resolved_section_id"] ?? 0);
    if (!isset($divisions[$divisionId]["sections"][$sectionId])) {
      continue;
    }

    $authorityRole = resolve_authority_role($row);
    $displayTitle = resolve_display_title($row, $authorityRole);

    $lastSeenAt = trim((string)($row["last_seen_at"] ?? ""));
    $isOnline = false;
    if ($hasLastSeenAt && $viewerDivisionId > 0 && $viewerDivisionId === $divisionId && $lastSeenAt !== "") {
      $lastSeenTs = strtotime($lastSeenAt);
      $isOnline = ($lastSeenTs !== false) && (($nowTs - $lastSeenTs) <= $onlineWindow);
    }

    $userId = (int)($row["id"] ?? 0);
    $target = [
      "id" => $userId,
      "full_name" => (string)($row["full_name"] ?? ""),
      "email" => (string)($row["email"] ?? ""),
      "username" => (string)($row["username"] ?? ""),
      "profile_photo_url" => org_profile_photo_url((string)($row["profile_photo_url"] ?? "")),
      "authority_role" => $authorityRole,
      "authority_weight" => $authorityWeight[$authorityRole] ?? 99,
      "display_title" => $displayTitle,
      "official_title" => trim((string)($row["official_title"] ?? "")),
      "section_name" => (string)($row["section_name"] ?? ""),
      "section_id" => $sectionId,
      "division_id" => $divisionId,
      "division_name" => (string)($row["division_name"] ?? ""),
      "permanent" => (int)($row["permanent"] ?? 0),
      "chief_assistant_user_id" => (int)($row["chief_assistant_user_id"] ?? 0),
      "chief_assistant_name" => trim((string)($row["chief_assistant_name"] ?? "")),
      "chief_assistant_user_ids" => trim((string)($row["chief_assistant_user_ids"] ?? "")),
      "chief_assistant_names" => trim((string)($row["chief_assistant_names"] ?? "")),
      "assistant_for_count" => (int)($assistantPrincipalRollupByUser[$userId]["assistant_for_count"] ?? 0),
      "assistant_for_names" => (string)($assistantPrincipalRollupByUser[$userId]["assistant_for_names"] ?? ""),
      "documents_received_count" => (int)($documentStatsByUser[$userId]["received"] ?? 0),
      "documents_forwarded_count" => (int)($documentStatsByUser[$userId]["forwarded"] ?? 0),
      "documents_incoming_count" => (int)($documentStatsByUser[$userId]["incoming"] ?? 0),
      "documents_pending_count" => (int)($documentStatsByUser[$userId]["pending"] ?? 0),
      "documents_completed_count" => (int)($documentStatsByUser[$userId]["completed"] ?? 0),
      "avg_working_minutes" => (int)($documentStatsByUser[$userId]["avg_working_minutes"] ?? 0),
      "avg_processing_time" => (int)($documentStatsByUser[$userId]["avg_working_minutes"] ?? 0) > 0 ? dt_format_working_elapsed((int)($documentStatsByUser[$userId]["avg_working_minutes"] ?? 0), $conn) : "N/A",
      "is_online" => $isOnline,
      "show_presence" => ($viewerDivisionId > 0 && $viewerDivisionId === $divisionId),
      "is_leader" => is_leadership_role($authorityRole),
    ];
    $target["can_edit"] = $canManageOrg && ($orgEditorIsAdmin || can_edit_org_target($orgEditor, $target));
    $target["can_upload_photo"] = $orgEditorIsAdmin && $target["can_edit"];
    $target["can_assign_assistant"] = ($hasChiefAssistant || $hasAssistantAssignments) && ($orgEditorIsAdmin ? org_user_is_assistant_assignable_principal($authorityRole) : can_assign_assistant_for_target($orgEditor, $target));
    $target["assistant_candidates_json"] = $target["can_assign_assistant"]
      ? json_encode(org_fetch_assistant_candidates($conn, $target), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
      : '[]';
    $divisions[$divisionId]["sections"][$sectionId]["users"][] = $target;
  }
}

foreach ($divisions as &$division) {
  foreach ($division["sections"] as &$section) {
    usort($section["users"], static function (array $a, array $b): int {
      if ($a["authority_weight"] !== $b["authority_weight"]) {
        return $a["authority_weight"] <=> $b["authority_weight"];
      }
      return strcasecmp($a["full_name"], $b["full_name"]);
    });

    $section["leaders"] = array_values(array_filter($section["users"], static fn(array $user): bool => $user["is_leader"]));
    $section["members"] = array_values(array_filter($section["users"], static fn(array $user): bool => !$user["is_leader"]));
    $section["member_count"] = count($section["members"]);
  }
  unset($section);

  uasort($division["sections"], static function (array $a, array $b): int {
    if ($a["sort_weight"] !== $b["sort_weight"]) {
      return $a["sort_weight"] <=> $b["sort_weight"];
    }
    return strcasecmp($a["name"], $b["name"]);
  });

  $division["section_count"] = count($division["sections"]);
  $division["user_count"] = array_reduce(
    $division["sections"],
    static fn(int $carry, array $section): int => $carry + count($section["users"]),
    0
  );

  $division["chief_office"] = null;
  $division["child_sections"] = [];
  foreach ($division["sections"] as $section) {
    if ($division["chief_office"] === null && $section["is_chief_office"]) {
      $division["chief_office"] = $section;
      continue;
    }
    $division["child_sections"][] = $section;
  }

  if ($division["chief_office"] === null && !empty($division["sections"])) {
    $firstSection = reset($division["sections"]);
    $division["chief_office"] = $firstSection;
    $division["child_sections"] = array_values(array_slice($division["sections"], 1));
  }
}
unset($division);

uasort($divisions, static function (array $a, array $b): int {
  if ($a["sort_weight"] !== $b["sort_weight"]) {
    return $a["sort_weight"] <=> $b["sort_weight"];
  }
  return strcasecmp($a["name"], $b["name"]);
});

$rootDivision = null;
$childDivisions = [];
foreach ($divisions as $division) {
  if ($rootDivision === null && $division["sort_weight"] === 0) {
    $rootDivision = $division;
    continue;
  }
  $childDivisions[] = $division;
}
if ($rootDivision === null && !empty($divisions)) {
  $rootDivision = reset($divisions);
  $childDivisions = array_values(array_slice($divisions, 1));
}

$disableLegacyOrgChartStyles = true;
$currentPage = 'org_chart.php';

require __DIR__ . "/../includes/layout.php";

$spotlightDivision = ($viewerDivisionId > 0 && isset($divisions[$viewerDivisionId]))
  ? $divisions[$viewerDivisionId]
  : $rootDivision;

$orgChartStats = [
  'activeDivisions' => max(0, count($divisions) - 1),
  'activeUsers' => array_reduce($divisions, static fn(int $carry, array $division): int => $carry + (int)$division['user_count'], 0),
  'totalSections' => array_reduce($divisions, static fn(int $carry, array $division): int => $carry + (int)$division['section_count'], 0),
];

$orgChartCopy = [
  'eyebrow' => '2026 Org Atlas',
  'title' => 'Technical Services',
  'subtitle' => 'Delivering precision in public works',
];

function org_chart_manifest_path(): string {
  return __DIR__ . '/org-chart-react/manifest.json';
}

function org_chart_manifest_data(): ?array {
  static $manifest = null;
  static $loaded = false;

  if ($loaded) {
    return $manifest;
  }

  $loaded = true;
  $path = org_chart_manifest_path();
  if (!is_file($path)) {
    return $manifest = null;
  }

  $decoded = json_decode((string)file_get_contents($path), true);
  if (!is_array($decoded)) {
    return $manifest = null;
  }

  return $manifest = $decoded;
}

function org_chart_entry_assets(): array {
  $manifest = org_chart_manifest_data();
  if (!is_array($manifest)) {
    return ['css' => [], 'js' => []];
  }

  $entry = $manifest['index.html'] ?? null;
  if (!is_array($entry)) {
    $entry = reset($manifest);
    if (!is_array($entry)) {
      return ['css' => [], 'js' => []];
    }
  }

  $css = [];
  foreach (($entry['css'] ?? []) as $href) {
    if (is_string($href) && $href !== '') {
      $css[] = asset_url('public/org-chart-react/' . ltrim($href, '/'));
    }
  }

  $js = [];
  $entryFile = (string)($entry['file'] ?? '');
  if ($entryFile !== '') {
    $js[] = asset_url('public/org-chart-react/' . ltrim($entryFile, '/'));
  }

  return ['css' => $css, 'js' => $js];
}

$orgChartAssets = org_chart_entry_assets();
$orgChartBuildReady = !empty($orgChartAssets['js']);
?>

<div class="orgChartMountPage">
  <?php if (!$orgChartBuildReady): ?>
    <section style="background:#fff;border:1px solid #dbe4ef;border-radius:20px;padding:20px 22px;box-shadow:0 16px 36px rgba(15,23,42,.06);max-width:980px;">
      <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#1b63df;">React org chart not built yet</p>
      <h2 style="margin:0 0 10px;font-size:24px;line-height:1.2;color:#0f172a;">The PHP integration is ready.</h2>
      <p style="margin:0 0 14px;color:#475569;">This page is now wired for the React + Tailwind org chart, but the frontend bundle still needs to be built once on your machine.</p>
      <ol style="margin:0 0 14px 18px;color:#334155;line-height:1.7;">
        <li>Open your project root in terminal.</li>
        <li>Go to <code>frontend/org-chart-react</code>.</li>
        <li>Run <code>npm install</code>.</li>
        <li>Run <code>npm run build</code>.</li>
        <li>Refresh this page.</li>
      </ol>
      <p style="margin:0;color:#64748b;font-size:13px;">Build output will go directly to <code>public/org-chart-react</code>, and this page will auto-load the hashed assets from <code>manifest.json</code>.</p>
    </section>
  <?php else: ?>
    <script>
      window.__ORG_CHART_BOOTSTRAP__ = <?= json_encode([
        'rootDivision' => $rootDivision,
        'childDivisions' => array_values($childDivisions),
        'spotlightDivision' => $spotlightDivision,
        'divisions' => array_values($divisions),
        'assignableRoles' => $assignableRoles,
        'canManageOrg' => $canManageOrg,
        'viewerDivisionId' => $viewerDivisionId,
        'stats' => $orgChartStats,
        'copy' => $orgChartCopy,
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>

    <?php foreach ($orgChartAssets['css'] as $href): ?>
      <link rel="stylesheet" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>

    <div id="root"></div>

    <?php foreach ($orgChartAssets['js'] as $src): ?>
      <script type="module" src="<?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . "/../includes/footer.php"; ?>
