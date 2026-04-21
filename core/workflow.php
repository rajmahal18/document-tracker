<?php
declare(strict_types=1);

function workflow_has_table(mysqli $conn, string $table): bool
{
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $cache[$key] = $exists;
    return $exists;
}

function workflow_has_column(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $cache[$key] = $exists;
    return $exists;
}

function workflow_branch_mode_enabled(mysqli $conn): bool
{
    return workflow_has_table($conn, 'document_branches')
        && workflow_has_table($conn, 'document_user_visibility')
        && workflow_has_column($conn, 'routes', 'branch_id')
        && workflow_has_column($conn, 'routes', 'from_user_id')
        && workflow_has_column($conn, 'routes', 'route_kind');
}



function workflow_branch_attachment_scope_enabled(mysqli $conn): bool
{
    return workflow_has_column($conn, 'document_attachments', 'branch_id');
}

function workflow_get_user_branch_ids_for_document(mysqli $conn, int $documentId, int $userId): array
{
    if ($documentId <= 0 || $userId <= 0 || !workflow_has_table($conn, 'document_branches')) {
        return [];
    }

    $hasCompletedBy = workflow_has_column($conn, 'document_branches', 'completed_by_user_id');
    $hasFromUserId = workflow_has_column($conn, 'routes', 'from_user_id');

    $branchUserSql = $hasCompletedBy
        ? ' OR b.completed_by_user_id = ?'
        : '';
    $routeUserSql = $hasFromUserId
        ? ' OR r.from_user_id = ?'
        : '';

    $sql = "
        SELECT DISTINCT b.id
        FROM document_branches b
        WHERE b.document_id = ?
          AND (
            b.created_by_user_id = ?
            OR b.current_assignee_user_id = ?" . $branchUserSql . "
            OR EXISTS (
              SELECT 1
              FROM routes r
              WHERE r.document_id = b.document_id
                AND r.branch_id = b.id
                AND (
                  r.to_user_id = ?
                  OR r.sent_by_user_id = ?
                  OR r.received_by_user_id = ?" . $routeUserSql . "
                )
            )
            OR EXISTS (
              SELECT 1
              FROM document_user_visibility duv
              WHERE duv.document_id = b.document_id
                AND duv.branch_id = b.id
                AND duv.user_id = ?
            )
          )
        ORDER BY b.id ASC
    ";

    $stmt = $conn->prepare($sql);

    if ($hasCompletedBy && $hasFromUserId) {
        $stmt->bind_param('iiiiiiiii', $documentId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId);
    } elseif ($hasCompletedBy) {
        $stmt->bind_param('iiiiiiii', $documentId, $userId, $userId, $userId, $userId, $userId, $userId, $userId);
    } elseif ($hasFromUserId) {
        $stmt->bind_param('iiiiiiii', $documentId, $userId, $userId, $userId, $userId, $userId, $userId, $userId);
    } else {
        $stmt->bind_param('iiiiiii', $documentId, $userId, $userId, $userId, $userId, $userId, $userId);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return array_values(array_unique(array_filter(
        array_map(static fn($row) => (int)($row['id'] ?? 0), $rows),
        static fn($id) => $id > 0
    )));
}

function workflow_user_can_access_branch(mysqli $conn, int $documentId, int $branchId, int $userId): bool
{
    if ($documentId <= 0 || $branchId <= 0 || $userId <= 0) {
        return false;
    }

    if (!workflow_branch_mode_enabled($conn) || !workflow_has_table($conn, 'document_branches')) {
        return false;
    }

    $hasFromUserId = workflow_has_column($conn, 'routes', 'from_user_id');
    $routeFromUserClause = $hasFromUserId ? ' OR r.from_user_id = ?' : '';

    $sql = "
        SELECT 1
        FROM document_branches b
        JOIN documents d ON d.id = b.document_id
        WHERE b.id = ?
          AND b.document_id = ?
          AND (
            d.created_by_user_id = ?
            OR b.created_by_user_id = ?
            OR b.current_assignee_user_id = ?
            OR b.completed_by_user_id = ?
            OR EXISTS (
              SELECT 1
              FROM routes r
              WHERE r.document_id = d.id
                AND r.branch_id = b.id
                AND (
                  r.to_user_id = ?
                  OR r.sent_by_user_id = ?
                  OR r.received_by_user_id = ?" . $routeFromUserClause . "
                )
            )
            OR EXISTS (
              SELECT 1
              FROM document_user_visibility duv
              WHERE duv.document_id = d.id
                AND duv.user_id = ?
                AND (duv.branch_id IS NULL OR duv.branch_id = b.id)
            )
          )
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if ($hasFromUserId) {
        $stmt->bind_param('iiiiiiiiiii', $branchId, $documentId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId);
    } else {
        $stmt->bind_param('iiiiiiiiii', $branchId, $documentId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId);
    }

    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

function workflow_document_has_real_branches(mysqli $conn, int $documentId): bool
{
    static $cache = [];

    if ($documentId <= 0 || !workflow_has_table($conn, 'document_branches')) {
        return false;
    }

    if (array_key_exists($documentId, $cache)) {
        return $cache[$documentId];
    }

    $stmt = $conn->prepare("SELECT 1 FROM document_branches WHERE document_id = ? LIMIT 1");
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $cache[$documentId] = $exists;
    return $exists;
}

function workflow_grant_visibility(mysqli $conn, int $documentId, int $userId, string $source, ?int $branchId = null, ?int $grantedByUserId = null): void
{
    if ($documentId <= 0 || $userId <= 0 || !workflow_has_table($conn, 'document_user_visibility')) {
        return;
    }

    $source = strtoupper(trim($source));
    if (!in_array($source, ['CREATOR', 'PARTICIPANT', 'REFERENCE', 'ADMIN'], true)) {
        $source = 'PARTICIPANT';
    }

    $stmt = $conn->prepare("\n        INSERT INTO document_user_visibility (document_id, user_id, source, branch_id, granted_by_user_id)\n        VALUES (?, ?, ?, ?, ?)\n        ON DUPLICATE KEY UPDATE\n          source = VALUES(source),\n          branch_id = COALESCE(VALUES(branch_id), branch_id),\n          granted_by_user_id = COALESCE(VALUES(granted_by_user_id), granted_by_user_id),\n          updated_at = CURRENT_TIMESTAMP\n    ");
    $stmt->bind_param('iisii', $documentId, $userId, $source, $branchId, $grantedByUserId);
    $stmt->execute();
}

function workflow_find_single_actionable_branch(mysqli $conn, int $documentId, int $userId, ?int $branchId = null): ?array
{
    if (!workflow_has_table($conn, 'document_branches')) {
        return null;
    }

    if ($branchId !== null && $branchId > 0) {
        $stmt = $conn->prepare("\n            SELECT b.*\n            FROM document_branches b\n            WHERE b.id = ?\n              AND b.document_id = ?\n              AND b.branch_status = 'ACTIVE'\n              AND b.current_assignee_user_id = ?\n              AND b.is_reference = 0\n            LIMIT 1\n        ");
        $stmt->bind_param('iii', $branchId, $documentId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    $stmt = $conn->prepare("\n        SELECT b.*\n        FROM document_branches b\n        WHERE b.document_id = ?\n          AND b.branch_status = 'ACTIVE'\n          AND b.current_assignee_user_id = ?\n          AND b.is_reference = 0\n        ORDER BY b.id DESC\n        LIMIT 2\n    ");
    $stmt->bind_param('ii', $documentId, $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($rows) !== 1) {
        return null;
    }
    return $rows[0];
}

function workflow_user_can_act_legacy_document(mysqli $conn, int $documentId, int $userId, int $sectionId, bool $isChief, bool $allowReleased = false): bool
{
    if ($documentId <= 0 || $userId <= 0 || $sectionId <= 0) {
        return false;
    }

    $statusSql = $allowReleased
        ? "d.current_status IN ('ACTIVE', 'RELEASED')"
        : "d.current_status = 'ACTIVE'";
    $hasBranchSql = workflow_has_table($conn, 'document_branches')
        ? "EXISTS (
            SELECT 1
            FROM document_branches b
            WHERE b.document_id = d.id
          )"
        : "0";

    $stmt = $conn->prepare("
        SELECT
          d.created_by_user_id,
          d.current_status,
          d.current_holder_section_id,
          {$hasBranchSql} AS has_branch,
          EXISTS (
            SELECT 1
            FROM routes r_open
            WHERE r_open.document_id = d.id
              AND r_open.received_at IS NULL
              AND r_open.cancelled_at IS NULL
              AND NOT EXISTS (
                SELECT 1
                FROM attachment_forward_tasks aft_open
                WHERE aft_open.route_id = r_open.id
              )
          ) AS has_open_route,
          EXISTS (
            SELECT 1
            FROM routes r_any_received
            WHERE r_any_received.document_id = d.id
              AND r_any_received.received_at IS NOT NULL
              AND r_any_received.cancelled_at IS NULL
              AND NOT EXISTS (
                SELECT 1
                FROM attachment_forward_tasks aft_received
                WHERE aft_received.route_id = r_any_received.id
              )
          ) AS has_received_route
        FROM documents d
        WHERE d.id = ?
          AND {$statusSql}
        LIMIT 1
    ");
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();

    if (!$doc) {
        return false;
    }

    if ((int)($doc['has_branch'] ?? 0) === 1) {
        return false;
    }

    if ((int)($doc['has_open_route'] ?? 0) === 1) {
        return false;
    }

    if ((int)($doc['current_holder_section_id'] ?? 0) !== $sectionId) {
        return false;
    }

    if (
        (int)($doc['created_by_user_id'] ?? 0) === $userId
        && (int)($doc['has_received_route'] ?? 0) === 0
    ) {
        return true;
    }

    $stmt = $conn->prepare("
        SELECT r.to_user_id, r.to_section_id
        FROM routes r
        WHERE r.document_id = ?
          AND r.received_at IS NOT NULL
          AND r.cancelled_at IS NULL
          AND NOT EXISTS (
            SELECT 1
            FROM attachment_forward_tasks aft_latest
            WHERE aft_latest.route_id = r.id
          )
        ORDER BY r.received_at DESC, r.id DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $latest = $stmt->get_result()->fetch_assoc();

    if (!$latest) {
        return false;
    }

    $toUserId = (int)($latest['to_user_id'] ?? 0);
    if ($toUserId > 0) {
        return $toUserId === $userId;
    }

    return $isChief && (int)($latest['to_section_id'] ?? 0) === $sectionId;
}

function workflow_create_branch(mysqli $conn, array $data): int
{
    $stmt = $conn->prepare("\n      INSERT INTO document_branches (\n        document_id,\n        parent_branch_id,\n        branch_label,\n        current_assignee_user_id,\n        current_assignee_section_id,\n        branch_status,\n        is_reference,\n        created_by_user_id\n      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)\n    ");

    $documentId = (int)($data['document_id'] ?? 0);
    $parentBranchId = isset($data['parent_branch_id']) ? (int)$data['parent_branch_id'] : null;
    $label = (string)($data['branch_label'] ?? '');
    $assigneeUserId = isset($data['current_assignee_user_id']) ? (int)$data['current_assignee_user_id'] : null;
    $assigneeSectionId = isset($data['current_assignee_section_id']) ? (int)$data['current_assignee_section_id'] : null;
    $status = (string)($data['branch_status'] ?? 'ACTIVE');
    $isReference = !empty($data['is_reference']) ? 1 : 0;
    $createdByUserId = (int)($data['created_by_user_id'] ?? 0);

    $stmt->bind_param('iisiisii', $documentId, $parentBranchId, $label, $assigneeUserId, $assigneeSectionId, $status, $isReference, $createdByUserId);
    $stmt->execute();
    return (int)$conn->insert_id;
}

function workflow_get_branch_state(mysqli $conn, int $documentId, int $viewerUserId = 0): array
{
    if ($documentId <= 0 || !workflow_has_table($conn, 'document_branches')) {
        return [];
    }

    $sql = "
      SELECT
        b.id,
        b.document_id,
        b.parent_branch_id,
        b.branch_label,
        b.branch_status,
        b.is_reference,
        b.current_assignee_user_id,
        b.current_assignee_section_id,
        b.completed_by_user_id,
        b.created_at,
        b.updated_at,
        u.full_name AS current_assignee_name,
        s.name AS current_assignee_section_name,
        COALESCE((
          SELECT COUNT(*)
          FROM routes r_open
          WHERE r_open.branch_id = b.id
            AND r_open.route_kind = 'ACTION'
            AND r_open.received_at IS NULL
            AND r_open.cancelled_at IS NULL
        ), 0) AS open_action_route_count,
        COALESCE((
          SELECT r_me.id
          FROM routes r_me
          WHERE r_me.branch_id = b.id
            AND r_me.route_kind = 'ACTION'
            AND r_me.received_at IS NULL
            AND r_me.cancelled_at IS NULL
            AND r_me.to_user_id = ?
          ORDER BY r_me.id DESC
          LIMIT 1
        ), 0) AS my_pending_route_id
      FROM document_branches b
      LEFT JOIN users u ON u.id = b.current_assignee_user_id
      LEFT JOIN sections s ON s.id = b.current_assignee_section_id
      WHERE b.document_id = ?
      ORDER BY
        CASE
          WHEN b.branch_status = 'ACTIVE' AND b.current_assignee_user_id = ? THEN 0
          WHEN b.branch_status = 'ACTIVE' THEN 1
          ELSE 2
        END,
        b.id ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $viewerUserId, $documentId, $viewerUserId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as &$row) {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['document_id'] = (int)($row['document_id'] ?? 0);
        $row['parent_branch_id'] = (int)($row['parent_branch_id'] ?? 0);
        $row['is_reference'] = ((int)($row['is_reference'] ?? 0) === 1) ? 1 : 0;
        $row['current_assignee_user_id'] = (int)($row['current_assignee_user_id'] ?? 0);
        $row['current_assignee_section_id'] = (int)($row['current_assignee_section_id'] ?? 0);
        $row['completed_by_user_id'] = (int)($row['completed_by_user_id'] ?? 0);
        $row['open_action_route_count'] = (int)($row['open_action_route_count'] ?? 0);
        $row['my_pending_route_id'] = (int)($row['my_pending_route_id'] ?? 0);

        $row['can_forward'] = (
            strtoupper((string)($row['branch_status'] ?? '')) === 'ACTIVE'
            && (int)$row['is_reference'] === 0
            && (int)$row['current_assignee_user_id'] === $viewerUserId
            && (int)$row['my_pending_route_id'] === 0
            && (int)$row['open_action_route_count'] === 0
        ) ? 1 : 0;

        $row['can_undo_end_here'] = (
            strtoupper((string)($row['branch_status'] ?? '')) === 'COMPLETED'
            && (int)$row['is_reference'] === 0
            && (int)$row['completed_by_user_id'] === $viewerUserId
            && (int)$row['open_action_route_count'] === 0
        ) ? 1 : 0;

        $attachmentForwardMeta = workflow_get_branch_attachment_forward_task_meta($conn, $documentId, (int)$row['id'], $viewerUserId);
        foreach ($attachmentForwardMeta as $metaKey => $metaValue) {
            $row[$metaKey] = $metaValue;
        }

        if ((int)($row['attachment_forward_open_task_count'] ?? 0) > 0 && (int)($row['is_reference'] ?? 0) === 0) {
            $row['can_forward'] = 0;
        }
    }
    unset($row);

    if ($viewerUserId <= 0) {
        return $rows;
    }

    // Important: the original sender/creator must keep the full branch list visible
    // even if one branch later comes back to them. Their pending/actionable lane should
    // still be marked on the matching branch row, but the branch selector itself must
    // not collapse to only that returned lane.
    $viewerIsDocumentCreator = false;
    $stmtCreator = $conn->prepare("
        SELECT created_by_user_id
        FROM documents
        WHERE id = ?
        LIMIT 1
    ");
    if ($stmtCreator) {
        $stmtCreator->bind_param('i', $documentId);
        $stmtCreator->execute();
        $creatorRow = $stmtCreator->get_result()->fetch_assoc();
        $viewerIsDocumentCreator = ((int)($creatorRow['created_by_user_id'] ?? 0) === $viewerUserId);
    }

    if ($viewerIsDocumentCreator) {
        return $rows;
    }

    $viewerBranches = [];

    foreach ($rows as $row) {
        $bid = (int)($row['id'] ?? 0);
        if ($bid <= 0) continue;

        if (
            (int)($row['current_assignee_user_id'] ?? 0) === $viewerUserId
            || (int)($row['completed_by_user_id'] ?? 0) === $viewerUserId
            || (int)($row['my_pending_route_id'] ?? 0) > 0
            || (int)($row['can_forward'] ?? 0) === 1
            || (int)($row['can_undo_end_here'] ?? 0) === 1
        ) {
            $viewerBranches[$bid] = true;
        }
    }

    if ($viewerBranches !== []) {
        $filtered = [];
        foreach ($rows as $row) {
            $bid = (int)($row['id'] ?? 0);
            if ($bid > 0 && isset($viewerBranches[$bid])) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    $associatedBranchIds = workflow_get_user_branch_ids_for_document($conn, $documentId, $viewerUserId);
    if ($associatedBranchIds !== []) {
        $associatedLookup = array_fill_keys($associatedBranchIds, true);
        $filtered = [];

        foreach ($rows as $row) {
            $bid = (int)($row['id'] ?? 0);
            if ($bid > 0 && isset($associatedLookup[$bid])) {
                $filtered[] = $row;
            }
        }

        if ($filtered !== []) {
            return $filtered;
        }
    }

    // No branch-specific participation trail: keep the full list
    // for sender/admin/general viewers.
    return $rows;
}


function workflow_attachment_forwarding_enabled(mysqli $conn): bool
{
    return workflow_has_table($conn, 'attachment_forward_tasks');
}

function workflow_branch_has_open_attachment_forward_tasks(mysqli $conn, int $documentId, int $senderBranchId): bool
{
    if ($documentId <= 0 || $senderBranchId <= 0 || !workflow_attachment_forwarding_enabled($conn)) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM attachment_forward_tasks aft
        WHERE aft.document_id = ?
          AND aft.sender_branch_id = ?
          AND aft.task_status IN ('PENDING_RECEIVE', 'IN_PROGRESS')
        LIMIT 1
    ");
    $stmt->bind_param('ii', $documentId, $senderBranchId);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

function workflow_document_has_open_attachment_forward_tasks(mysqli $conn, int $documentId): bool
{
    if ($documentId <= 0 || !workflow_attachment_forwarding_enabled($conn)) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM attachment_forward_tasks aft
        WHERE aft.document_id = ?
          AND aft.task_status IN ('PENDING_RECEIVE', 'IN_PROGRESS')
        LIMIT 1
    ");
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}


function workflow_user_has_open_attachment_forward_tasks_as_sender(mysqli $conn, int $documentId, int $senderUserId): bool
{
    if ($documentId <= 0 || $senderUserId <= 0 || !workflow_attachment_forwarding_enabled($conn)) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM attachment_forward_tasks aft
        WHERE aft.document_id = ?
          AND aft.sender_user_id = ?
          AND aft.task_status IN ('PENDING_RECEIVE', 'IN_PROGRESS')
        LIMIT 1
    ");
    $stmt->bind_param('ii', $documentId, $senderUserId);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

function workflow_branch_open_attachment_forward_task_count(mysqli $conn, int $documentId, int $senderBranchId): int
{
    if ($documentId <= 0 || $senderBranchId <= 0 || !workflow_attachment_forwarding_enabled($conn)) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM attachment_forward_tasks aft
        WHERE aft.document_id = ?
          AND aft.sender_branch_id = ?
          AND aft.task_status IN ('PENDING_RECEIVE', 'IN_PROGRESS')
    ");
    $stmt->bind_param('ii', $documentId, $senderBranchId);
    $stmt->execute();
    return (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
}

function workflow_get_branch_attachment_forward_task_meta(mysqli $conn, int $documentId, int $branchId, int $viewerUserId): array
{
    $meta = [
        'attachment_forward_source_branch' => 0,
        'attachment_forward_recipient_branch' => 0,
        'attachment_forward_open_task_count' => 0,
        'attachment_forward_can_attach' => 0,
        'attachment_forward_can_mark_done' => 0,
        'attachment_forward_task_status' => '',
    ];

    if ($documentId <= 0 || $branchId <= 0 || $viewerUserId <= 0 || !workflow_attachment_forwarding_enabled($conn)) {
        return $meta;
    }

    $stmt = $conn->prepare("
        SELECT
          SUM(CASE WHEN aft.sender_branch_id = ? THEN 1 ELSE 0 END) AS source_any_count,
          SUM(CASE WHEN aft.sender_branch_id = ? AND aft.task_status IN ('PENDING_RECEIVE', 'IN_PROGRESS') THEN 1 ELSE 0 END) AS source_open_count,
          SUM(CASE WHEN aft.recipient_branch_id = ? THEN 1 ELSE 0 END) AS recipient_any_count,
          SUM(CASE WHEN aft.recipient_branch_id = ? AND aft.task_status IN ('PENDING_RECEIVE', 'IN_PROGRESS') THEN 1 ELSE 0 END) AS recipient_open_count,
          SUM(CASE WHEN aft.recipient_branch_id = ? AND aft.recipient_user_id = ? AND aft.task_status = 'IN_PROGRESS' THEN 1 ELSE 0 END) AS recipient_in_progress_count,
          SUM(CASE WHEN aft.recipient_branch_id = ? AND aft.recipient_user_id = ? AND aft.task_status = 'PENDING_RECEIVE' THEN 1 ELSE 0 END) AS recipient_pending_receive_count
        FROM attachment_forward_tasks aft
        WHERE aft.document_id = ?
          AND (
            aft.sender_branch_id = ?
            OR aft.recipient_branch_id = ?
          )
    ");
    $stmt->bind_param(
        'iiiiiiiiiii',
        $branchId,
        $branchId,
        $branchId,
        $branchId,
        $branchId,
        $viewerUserId,
        $branchId,
        $viewerUserId,
        $documentId,
        $branchId,
        $branchId
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];

    $sourceAny = (int)($row['source_any_count'] ?? 0);
    $sourceOpen = (int)($row['source_open_count'] ?? 0);
    $recipientAny = (int)($row['recipient_any_count'] ?? 0);
    $recipientOpen = (int)($row['recipient_open_count'] ?? 0);
    $recipientInProgress = (int)($row['recipient_in_progress_count'] ?? 0);
    $recipientPendingReceive = (int)($row['recipient_pending_receive_count'] ?? 0);

    $meta['attachment_forward_source_branch'] = $sourceAny > 0 ? 1 : 0;
    $meta['attachment_forward_recipient_branch'] = $recipientAny > 0 ? 1 : 0;
    $meta['attachment_forward_open_task_count'] = max($sourceOpen, $recipientOpen);

    if ($recipientInProgress > 0) {
        $meta['attachment_forward_can_attach'] = 1;
        $meta['attachment_forward_can_mark_done'] = 1;
        $meta['attachment_forward_task_status'] = 'IN_PROGRESS';
    } elseif ($recipientPendingReceive > 0) {
        $meta['attachment_forward_task_status'] = 'PENDING_RECEIVE';
    } elseif ($recipientOpen > 0) {
        $meta['attachment_forward_task_status'] = 'OPEN';
    }

    return $meta;
}


function workflow_get_document_attachment_forward_task_meta(mysqli $conn, int $documentId, int $viewerUserId): array
{
    $meta = [
        'attachment_forward_source_branch' => 0,
        'attachment_forward_recipient_branch' => 0,
        'attachment_forward_open_task_count' => 0,
        'attachment_forward_can_attach' => 0,
        'attachment_forward_can_mark_done' => 0,
        'attachment_forward_task_status' => '',
    ];

    if ($documentId <= 0 || $viewerUserId <= 0 || !workflow_attachment_forwarding_enabled($conn)) {
        return $meta;
    }

    $stmt = $conn->prepare("
        SELECT
          SUM(CASE WHEN aft.sender_user_id = ? THEN 1 ELSE 0 END) AS source_any_count,
          SUM(CASE WHEN aft.sender_user_id = ? AND aft.task_status IN ('PENDING_RECEIVE', 'IN_PROGRESS') THEN 1 ELSE 0 END) AS source_open_count,
          SUM(CASE WHEN aft.recipient_user_id = ? THEN 1 ELSE 0 END) AS recipient_any_count,
          SUM(CASE WHEN aft.recipient_user_id = ? AND aft.task_status IN ('PENDING_RECEIVE', 'IN_PROGRESS') THEN 1 ELSE 0 END) AS recipient_open_count,
          SUM(CASE WHEN aft.recipient_user_id = ? AND aft.task_status = 'IN_PROGRESS' THEN 1 ELSE 0 END) AS recipient_in_progress_count,
          SUM(CASE WHEN aft.recipient_user_id = ? AND aft.task_status = 'PENDING_RECEIVE' THEN 1 ELSE 0 END) AS recipient_pending_receive_count
        FROM attachment_forward_tasks aft
        WHERE aft.document_id = ?
          AND COALESCE(aft.sender_branch_id, 0) = 0
          AND COALESCE(aft.recipient_branch_id, 0) = 0
    ");
    $stmt->bind_param('iiiiiii', $viewerUserId, $viewerUserId, $viewerUserId, $viewerUserId, $viewerUserId, $viewerUserId, $documentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];

    $sourceAny = (int)($row['source_any_count'] ?? 0);
    $sourceOpen = (int)($row['source_open_count'] ?? 0);
    $recipientAny = (int)($row['recipient_any_count'] ?? 0);
    $recipientOpen = (int)($row['recipient_open_count'] ?? 0);
    $recipientInProgress = (int)($row['recipient_in_progress_count'] ?? 0);
    $recipientPendingReceive = (int)($row['recipient_pending_receive_count'] ?? 0);

    $meta['attachment_forward_source_branch'] = $sourceAny > 0 ? 1 : 0;
    $meta['attachment_forward_recipient_branch'] = $recipientAny > 0 ? 1 : 0;
    $meta['attachment_forward_open_task_count'] = max($sourceOpen, $recipientOpen);

    if ($recipientInProgress > 0) {
        $meta['attachment_forward_can_attach'] = 1;
        $meta['attachment_forward_can_mark_done'] = 1;
        $meta['attachment_forward_task_status'] = 'IN_PROGRESS';
    } elseif ($recipientPendingReceive > 0) {
        $meta['attachment_forward_task_status'] = 'PENDING_RECEIVE';
    } elseif ($recipientOpen > 0) {
        $meta['attachment_forward_task_status'] = 'OPEN';
    }

    return $meta;
}


function workflow_get_attachment_forward_task_summary(mysqli $conn, int $documentId, int $viewerUserId, ?int $senderBranchId = null, ?int $recipientBranchId = null): array
{
    if ($documentId <= 0 || $viewerUserId <= 0 || !workflow_attachment_forwarding_enabled($conn)) {
        return [];
    }

    $scopeClauses = ["aft.document_id = ?", "(aft.sender_user_id = ? OR aft.recipient_user_id = ?)"];
    $types = 'iii';
    $params = [$documentId, $viewerUserId, $viewerUserId];

    if ($senderBranchId !== null) {
        $scopeClauses[] = 'COALESCE(aft.sender_branch_id, 0) = ?';
        $types .= 'i';
        $params[] = max(0, (int)$senderBranchId);
    }
    if ($recipientBranchId !== null) {
        $scopeClauses[] = 'COALESCE(aft.recipient_branch_id, 0) = ?';
        $types .= 'i';
        $params[] = max(0, (int)$recipientBranchId);
    }

    $sql = "
        SELECT
          aft.id,
          aft.task_status,
          aft.sender_user_id,
          aft.recipient_user_id,
          aft.route_id,
          aft.source_attachment_id,
          aft.forwarded_attachment_id,
          aft.done_remarks,
          aft.created_at,
          aft.received_at,
          aft.done_at,
          su.full_name AS sender_name,
          ru.full_name AS recipient_name,
          rs.name AS recipient_section_name,
          da.original_name AS source_attachment_name,
          fa.original_name AS forwarded_attachment_name
        FROM attachment_forward_tasks aft
        LEFT JOIN users su ON su.id = aft.sender_user_id
        LEFT JOIN users ru ON ru.id = aft.recipient_user_id
        LEFT JOIN sections rs ON rs.id = aft.recipient_section_id
        LEFT JOIN document_attachments da ON da.id = aft.source_attachment_id
        LEFT JOIN document_attachments fa ON fa.id = aft.forwarded_attachment_id
        WHERE " . implode(' AND ', $scopeClauses) . "
        ORDER BY
          CASE aft.task_status
            WHEN 'PENDING_RECEIVE' THEN 0
            WHEN 'IN_PROGRESS' THEN 1
            WHEN 'DONE' THEN 2
            ELSE 3
          END,
          aft.created_at DESC,
          aft.id DESC
        LIMIT 50
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $summary = [];
    foreach ($rows as $row) {
        $summary[] = [
            'id' => (int)($row['id'] ?? 0),
            'task_status' => (string)($row['task_status'] ?? ''),
            'is_sender' => ((int)($row['sender_user_id'] ?? 0) === $viewerUserId) ? 1 : 0,
            'is_recipient' => ((int)($row['recipient_user_id'] ?? 0) === $viewerUserId) ? 1 : 0,
            'route_id' => (int)($row['route_id'] ?? 0),
            'attachment_name' => trim((string)($row['source_attachment_name'] ?? '')) !== ''
                ? (string)$row['source_attachment_name']
                : (string)($row['forwarded_attachment_name'] ?? ''),
            'recipient_name' => (string)($row['recipient_name'] ?? ''),
            'recipient_section_name' => (string)($row['recipient_section_name'] ?? ''),
            'sender_name' => (string)($row['sender_name'] ?? ''),
            'done_remarks' => (string)($row['done_remarks'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'received_at' => (string)($row['received_at'] ?? ''),
            'done_at' => (string)($row['done_at'] ?? ''),
        ];
    }

    return $summary;
}

function workflow_mark_attachment_forward_tasks_received_for_route(mysqli $conn, int $routeId): void
{
    if ($routeId <= 0 || !workflow_attachment_forwarding_enabled($conn)) {
        return;
    }

    $stmt = $conn->prepare("
        UPDATE attachment_forward_tasks
        SET task_status = 'IN_PROGRESS',
            received_at = COALESCE(received_at, NOW()),
            updated_at = NOW()
        WHERE route_id = ?
          AND task_status = 'PENDING_RECEIVE'
    ");
    $stmt->bind_param('i', $routeId);
    $stmt->execute();
}
