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
        $row['open_action_route_count'] = (int)($row['open_action_route_count'] ?? 0);
        $row['my_pending_route_id'] = (int)($row['my_pending_route_id'] ?? 0);

        $row['can_forward'] = (
            strtoupper((string)($row['branch_status'] ?? '')) === 'ACTIVE'
            && (int)$row['is_reference'] === 0
            && (int)$row['current_assignee_user_id'] === $viewerUserId
            && (int)$row['my_pending_route_id'] === 0
            && (int)$row['open_action_route_count'] === 0
        ) ? 1 : 0;
    }
    unset($row);

    if ($viewerUserId <= 0) {
        return $rows;
    }

    $viewerBranches = [];

    foreach ($rows as $row) {
        $bid = (int)($row['id'] ?? 0);
        if ($bid <= 0) continue;

        if (
            (int)($row['current_assignee_user_id'] ?? 0) === $viewerUserId
            || (int)($row['my_pending_route_id'] ?? 0) > 0
            || (int)($row['can_forward'] ?? 0) === 1
        ) {
            $viewerBranches[$bid] = true;
        }
    }

    // No directly-owned/pending/actionable branch: keep the full list
    // for sender/admin/general viewers.
    if ($viewerBranches === []) {
        return $rows;
    }

    $filtered = [];
    foreach ($rows as $row) {
        $bid = (int)($row['id'] ?? 0);
        if ($bid > 0 && isset($viewerBranches[$bid])) {
            $filtered[] = $row;
        }
    }

    return $filtered;
}