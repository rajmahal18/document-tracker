<?php
declare(strict_types=1);

require_once __DIR__ . '/working_time.php';

function org_chart_fetch_user_activity_context(mysqli $conn, int $userId): ?array
{
  if ($userId <= 0) {
    return null;
  }

  $stmt = $conn->prepare("
    SELECT
      u.id,
      u.section_id,
      u.is_chief,
      COALESCE(u.authority_role, '') AS authority_role
    FROM users u
    JOIN sections s ON s.id = u.section_id
    JOIN divisions d ON d.id = s.division_id
    WHERE u.id = ?
      AND u.is_active = 1
      AND s.is_active = 1
      AND d.is_active = 1
    LIMIT 1
  ");
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: null;

  return $row ?: null;
}

function org_chart_compute_user_average_working_minutes(mysqli $conn, int $userId): int
{
  if (
    $userId <= 0
    || !db_table_exists($conn, 'document_events')
    || !db_column_exists($conn, 'document_events', 'payload_json')
  ) {
    return 0;
  }

  $actionDocs = [];

  if (db_table_exists($conn, 'documents') && db_column_exists($conn, 'documents', 'created_by_user_id')) {
    $stmtCreator = $conn->prepare("
      SELECT id
      FROM documents
      WHERE created_by_user_id = ?
    ");
    $stmtCreator->bind_param('i', $userId);
    $stmtCreator->execute();
    $resCreator = $stmtCreator->get_result();
    while ($row = $resCreator->fetch_assoc()) {
      $actionDocs[(int)($row['id'] ?? 0)] = true;
    }
  }

  if (db_table_exists($conn, 'document_branches') && db_column_exists($conn, 'document_branches', 'current_assignee_user_id')) {
    $branchReferenceSql = db_column_exists($conn, 'document_branches', 'is_reference') ? 'AND is_reference = 0' : '';
    $stmtBranch = $conn->prepare("
      SELECT DISTINCT document_id
      FROM document_branches
      WHERE current_assignee_user_id = ?
        AND current_assignee_user_id > 0
        {$branchReferenceSql}
    ");
    $stmtBranch->bind_param('i', $userId);
    $stmtBranch->execute();
    $resBranch = $stmtBranch->get_result();
    while ($row = $resBranch->fetch_assoc()) {
      $actionDocs[(int)($row['document_id'] ?? 0)] = true;
    }
  }

  if (db_table_exists($conn, 'routes')) {
    $routeKindSql = db_column_exists($conn, 'routes', 'route_kind') ? "AND route_kind = 'ACTION'" : '';
    $routeSources = [];
    $bindTypes = '';
    $bindValues = [];

    if (db_column_exists($conn, 'routes', 'to_user_id')) {
      $routeSources[] = "SELECT document_id FROM routes WHERE to_user_id = ? AND to_user_id > 0 {$routeKindSql}";
      $bindTypes .= 'i';
      $bindValues[] = $userId;
    }
    if (db_column_exists($conn, 'routes', 'received_by_user_id')) {
      $routeSources[] = "SELECT document_id FROM routes WHERE received_by_user_id = ? AND received_by_user_id > 0 {$routeKindSql}";
      $bindTypes .= 'i';
      $bindValues[] = $userId;
    }

    if ($routeSources !== []) {
      $stmtRoutes = $conn->prepare(implode(' UNION ', $routeSources));
      $stmtRoutes->bind_param($bindTypes, ...$bindValues);
      $stmtRoutes->execute();
      $resRoutes = $stmtRoutes->get_result();
      while ($row = $resRoutes->fetch_assoc()) {
        $actionDocs[(int)($row['document_id'] ?? 0)] = true;
      }
    }
  }

  $activeAssignmentsByDoc = [];

  if (
    db_table_exists($conn, 'documents')
    && db_column_exists($conn, 'documents', 'current_status')
    && db_table_exists($conn, 'document_branches')
    && db_column_exists($conn, 'document_branches', 'current_assignee_user_id')
  ) {
    $branchStatusSql = db_column_exists($conn, 'document_branches', 'branch_status') ? "AND b.branch_status = 'ACTIVE'" : '';
    $branchReferenceSql = db_column_exists($conn, 'document_branches', 'is_reference') ? "AND b.is_reference = 0" : '';
    $stmtActiveBranches = $conn->prepare("
      SELECT b.document_id
      FROM document_branches b
      JOIN documents d ON d.id = b.document_id
      WHERE d.current_status = 'ACTIVE'
        AND b.current_assignee_user_id = ?
        AND b.current_assignee_user_id > 0
        {$branchStatusSql}
        {$branchReferenceSql}
    ");
    $stmtActiveBranches->bind_param('i', $userId);
    $stmtActiveBranches->execute();
    $resActiveBranches = $stmtActiveBranches->get_result();
    while ($row = $resActiveBranches->fetch_assoc()) {
      $activeAssignmentsByDoc[(int)($row['document_id'] ?? 0)] = true;
    }
  }

  if (db_table_exists($conn, 'documents') && db_table_exists($conn, 'routes') && db_column_exists($conn, 'routes', 'received_by_user_id')) {
    $routeNotCancelled = db_column_exists($conn, 'routes', 'cancelled_at') ? 'AND r.cancelled_at IS NULL' : '';
    $routeKindSql = db_column_exists($conn, 'routes', 'route_kind') ? "AND r.route_kind = 'ACTION'" : '';
    $routeKindSubSql = db_column_exists($conn, 'routes', 'route_kind') ? "AND r2.route_kind = 'ACTION'" : '';
    $noBranchCond = db_table_exists($conn, 'document_branches') ? "AND NOT EXISTS (SELECT 1 FROM document_branches b2 WHERE b2.document_id = d.id)" : '';
    $stmtLegacyActive = $conn->prepare("
      SELECT d.id AS document_id
      FROM documents d
      JOIN routes r ON r.document_id = d.id
      WHERE d.current_status = 'ACTIVE'
        AND r.received_by_user_id = ?
        AND r.received_by_user_id > 0
        AND r.received_at IS NOT NULL
        {$routeKindSql}
        {$routeNotCancelled}
        {$noBranchCond}
        AND r.id = (
          SELECT MAX(r2.id)
          FROM routes r2
          WHERE r2.document_id = d.id
            AND r2.received_at IS NOT NULL
            " . (db_column_exists($conn, 'routes', 'cancelled_at') ? 'AND r2.cancelled_at IS NULL' : '') . "
            {$routeKindSubSql}
        )
    ");
    $stmtLegacyActive->bind_param('i', $userId);
    $stmtLegacyActive->execute();
    $resLegacyActive = $stmtLegacyActive->get_result();
    while ($row = $resLegacyActive->fetch_assoc()) {
      $activeAssignmentsByDoc[(int)($row['document_id'] ?? 0)] = true;
    }
  }

  $stmtEvents = $conn->prepare("
    SELECT
      de.document_id,
      COALESCE(NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(de.payload_json, '$.acting_principal_user_id')) AS UNSIGNED), 0), de.actor_user_id) AS effective_user_id,
      de.created_at,
      de.event_type,
      COALESCE(JSON_UNQUOTE(JSON_EXTRACT(de.payload_json, '$.kind')), '') AS payload_kind,
      CAST(JSON_UNQUOTE(JSON_EXTRACT(de.payload_json, '$.elapsed_working_minutes')) AS SIGNED) AS elapsed_mins,
      COALESCE(JSON_UNQUOTE(JSON_EXTRACT(de.payload_json, '$.route_kind')), '') AS route_kind
    FROM document_events de
    WHERE de.created_at IS NOT NULL
      AND (
        de.actor_user_id = ?
        OR CAST(JSON_UNQUOTE(JSON_EXTRACT(de.payload_json, '$.acting_principal_user_id')) AS UNSIGNED) = ?
      )
    ORDER BY de.document_id ASC, de.created_at ASC, de.id ASC
  ");
  $stmtEvents->bind_param('ii', $userId, $userId);
  $stmtEvents->execute();
  $resEvents = $stmtEvents->get_result();

  $currentStintByDoc = [];

  while ($row = $resEvents->fetch_assoc()) {
    $effectiveUserId = (int)($row['effective_user_id'] ?? 0);
    $documentId = (int)($row['document_id'] ?? 0);
    if ($effectiveUserId !== $userId || $documentId <= 0) {
      continue;
    }

    $eventType = strtolower(trim((string)($row['event_type'] ?? '')));
    $payloadKind = strtolower(trim((string)($row['payload_kind'] ?? '')));
    $routeKind = strtoupper(trim((string)($row['route_kind'] ?? '')));
    $action = $eventType;

    if ($eventType === 'updated' && in_array($payloadKind, ['branch_ended_here', 'document_ended_here', 'attachment_forwarded', 'attachment_forward_task_done', 'attachment_added'], true)) {
      $action = $payloadKind;
    }

    $createdAt = (string)($row['created_at'] ?? '');
    $elapsedMins = max(0, (int)($row['elapsed_mins'] ?? 0));

    if ($action === 'received' || $action === 'created') {
      if (isset($currentStintByDoc[$documentId])) {
        $stint = $currentStintByDoc[$documentId];
        if (($stint['max_mins'] ?? 0) > 0 && empty($stint['is_reference'])) {
          $currentStintByDoc[$documentId]['committed_mins'] = ($currentStintByDoc[$documentId]['committed_mins'] ?? 0) + (int)$stint['max_mins'];
        }
      }

      $currentStintByDoc[$documentId] = [
        'start_at' => $createdAt,
        'max_mins' => 0,
        'is_open' => true,
        'is_reference' => ($routeKind === 'REFERENCE'),
        'committed_mins' => (int)($currentStintByDoc[$documentId]['committed_mins'] ?? 0),
      ];
      continue;
    }

    if (!isset($currentStintByDoc[$documentId])) {
      $currentStintByDoc[$documentId] = [
        'start_at' => $createdAt,
        'max_mins' => 0,
        'is_open' => true,
        'is_reference' => false,
        'committed_mins' => 0,
      ];
    }

    if ($elapsedMins > 0) {
      $currentStintByDoc[$documentId]['max_mins'] = max((int)$currentStintByDoc[$documentId]['max_mins'], $elapsedMins);
    } elseif (in_array($action, ['sent', 'forwarded', 'released', 'branch_ended_here', 'document_ended_here', 'attachment_forwarded'], true)) {
      $calcMins = dt_working_minutes_between((string)$currentStintByDoc[$documentId]['start_at'], $createdAt, $conn);
      if ($calcMins <= 0) {
        $calcMins = 1;
      }
      $currentStintByDoc[$documentId]['max_mins'] = max((int)$currentStintByDoc[$documentId]['max_mins'], $calcMins);
    }

    if (in_array($action, ['sent', 'forwarded', 'released', 'branch_ended_here', 'document_ended_here', 'attachment_forwarded'], true)) {
      $currentStintByDoc[$documentId]['is_open'] = false;
    }
  }

  $workingMinutes = 0;
  $docsHandled = [];

  foreach ($currentStintByDoc as $documentId => $stint) {
    if (!empty($stint['is_reference']) || !isset($actionDocs[(int)$documentId])) {
      continue;
    }

    $totalForDoc = (int)($stint['committed_mins'] ?? 0) + (int)($stint['max_mins'] ?? 0);

    if (!empty($stint['is_open']) && isset($activeAssignmentsByDoc[(int)$documentId])) {
      $liveMins = dt_working_minutes_between((string)$stint['start_at'], null, $conn);
      if ($liveMins <= 0) {
        $liveMins = 1;
      }
      $totalForDoc = max($totalForDoc, $liveMins);
    }

    if ($totalForDoc > 0) {
      $workingMinutes += $totalForDoc;
      $docsHandled[(int)$documentId] = true;
    }
  }

  $docCount = count($docsHandled);
  if ($docCount <= 0 || $workingMinutes <= 0) {
    return 0;
  }

  return (int)round($workingMinutes / $docCount);
}

function org_chart_fetch_user_activity_stats(mysqli $conn, int $userId): array
{
  $stats = [
    'incoming' => 0,
    'pending' => 0,
    'completed' => 0,
    'avg_working_minutes' => 0,
    'avg_processing_time' => 'N/A',
  ];

  $ctx = org_chart_fetch_user_activity_context($conn, $userId);
  if ($ctx === null) {
    return $stats;
  }

  if (db_table_exists($conn, 'documents') && db_table_exists($conn, 'routes')) {
    $sectionId = (int)($ctx['section_id'] ?? 0);
    $authorityRole = trim((string)($ctx['authority_role'] ?? ''));
    $isChief = ((int)($ctx['is_chief'] ?? 0) === 1) || in_array($authorityRole, ['director', 'division_head', 'section_head'], true);
    $chiefInt = $isChief ? 1 : 0;

    if ($sectionId > 0) {
      $hasBranches = db_table_exists($conn, 'document_branches');
      $branchPredicate = $hasBranches
        ? "EXISTS (SELECT 1 FROM document_branches b_chk WHERE b_chk.document_id = d.id)"
        : "0=1";
      $noBranchPredicate = $hasBranches
        ? "NOT EXISTS (SELECT 1 FROM document_branches b_chk WHERE b_chk.document_id = d.id)"
        : "1=1";
      $hasEvents = db_table_exists($conn, 'document_events') && db_column_exists($conn, 'document_events', 'payload_json');

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
        $stats['incoming'] = (int)($row['incoming'] ?? 0);
        $stats['pending'] = (int)($row['pending'] ?? 0);
        $stats['completed'] = (int)($row['completed'] ?? 0);
        $statRes->free();
      }
    }
  }

  $stats['avg_working_minutes'] = org_chart_compute_user_average_working_minutes($conn, $userId);
  if ($stats['avg_working_minutes'] > 0) {
    $stats['avg_processing_time'] = dt_format_working_elapsed($stats['avg_working_minutes'], $conn);
  }

  return $stats;
}
