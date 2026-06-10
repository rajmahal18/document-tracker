<?php
declare(strict_types=1);

require_once __DIR__ . '/workflow.php';

function document_deadline_reminder_normalize_slot(?string $slot): string
{
    $value = strtolower(trim((string)$slot));
    return $value === 'afternoon' ? 'afternoon' : 'morning';
}

function document_deadline_reminders_table_exists(mysqli $conn): bool
{
    return db_table_exists($conn, 'email_reminder_log');
}

function document_deadline_reminders_target_date(?DateTimeImmutable $now = null): DateTimeImmutable
{
    $tz = new DateTimeZone('Asia/Manila');
    $now = $now ? $now->setTimezone($tz) : new DateTimeImmutable('now', $tz);
    return $now->setTime(0, 0, 0);
}

function document_deadline_reminders_due_today_rows(mysqli $conn, string $slot, ?DateTimeImmutable $now = null): array
{
    $slot = document_deadline_reminder_normalize_slot($slot);
    $targetDate = document_deadline_reminders_target_date($now)->format('Y-m-d');

    if (!db_table_exists($conn, 'documents') || !db_table_exists($conn, 'users') || !db_table_exists($conn, 'routes')) {
        return [];
    }

    $hasEmailVerifiedAt = email_verified_at_column_exists($conn);
    $hasRouteKind = db_column_exists($conn, 'routes', 'route_kind');
    $hasCancelledAt = db_column_exists($conn, 'routes', 'cancelled_at');
    $hasPersonalDeadline = db_column_exists($conn, 'routes', 'personal_deadline_at');
    $hasBranchMode = workflow_has_table($conn, 'document_branches')
        && db_column_exists($conn, 'document_branches', 'current_assignee_user_id')
        && db_column_exists($conn, 'document_branches', 'branch_status');

    $routeKindSql = $hasRouteKind ? " AND r.route_kind = 'ACTION'" : '';
    $routeKindSubSql = $hasRouteKind ? " AND r2.route_kind = 'ACTION'" : '';
    $routeCancelledSql = $hasCancelledAt ? ' AND r.cancelled_at IS NULL' : '';
    $routeCancelledSubSql = $hasCancelledAt ? ' AND r2.cancelled_at IS NULL' : '';
    $routePersonalSelect = $hasPersonalDeadline ? 'r.personal_deadline_at' : 'NULL';
    $routePersonalLatestSelect = $hasPersonalDeadline ? 'lr.personal_deadline_at' : 'NULL';
    $noBranchSql = $hasBranchMode ? ' AND NOT EXISTS (SELECT 1 FROM document_branches b2 WHERE b2.document_id = d.id)' : '';

    $assignmentQueries = [];

    if ($hasBranchMode) {
        $branchRouteJoin = "
      LEFT JOIN routes lr ON lr.id = (
        SELECT MAX(r2.id)
        FROM routes r2
        WHERE r2.document_id = b.document_id
          AND r2.branch_id = b.id
          AND r2.to_user_id = b.current_assignee_user_id
          {$routeKindSubSql}
          {$routeCancelledSubSql}
      )";

        $assignmentQueries[] = "
      SELECT
        d.id AS document_id,
        b.current_assignee_user_id AS user_id,
        b.current_assignee_section_id AS section_id,
        b.id AS branch_id,
        lr.id AS route_id,
        {$routePersonalLatestSelect} AS personal_deadline_at,
        'BRANCH_ACTIVE' AS assignment_kind
      FROM document_branches b
      JOIN documents d ON d.id = b.document_id
      {$branchRouteJoin}
      WHERE d.current_status = 'ACTIVE'
        AND b.branch_status = 'ACTIVE'
        AND b.is_reference = 0
        AND b.current_assignee_user_id IS NOT NULL
        AND b.current_assignee_user_id > 0
    ";
    }

    $assignmentQueries[] = "
      SELECT
        d.id AS document_id,
        r.received_by_user_id AS user_id,
        d.current_holder_section_id AS section_id,
        NULL AS branch_id,
        r.id AS route_id,
        {$routePersonalSelect} AS personal_deadline_at,
        'LEGACY_ACTIVE' AS assignment_kind
      FROM documents d
      JOIN routes r ON r.document_id = d.id
      WHERE d.current_status = 'ACTIVE'
        AND r.received_by_user_id IS NOT NULL
        AND r.received_by_user_id > 0
        AND r.received_at IS NOT NULL
        {$routeKindSql}
        {$routeCancelledSql}
        {$noBranchSql}
        AND r.id = (
          SELECT MAX(r2.id)
          FROM routes r2
          WHERE r2.document_id = d.id
            AND r2.received_by_user_id = r.received_by_user_id
            AND r2.received_at IS NOT NULL
            {$routeKindSubSql}
            {$routeCancelledSubSql}
        )
    ";

    $assignmentQueries[] = "
      SELECT
        d.id AS document_id,
        r.to_user_id AS user_id,
        r.to_section_id AS section_id,
        NULL AS branch_id,
        r.id AS route_id,
        {$routePersonalSelect} AS personal_deadline_at,
        'LEGACY_OPEN' AS assignment_kind
      FROM documents d
      JOIN routes r ON r.document_id = d.id
      WHERE d.current_status = 'ACTIVE'
        AND r.to_user_id IS NOT NULL
        AND r.to_user_id > 0
        AND r.received_at IS NULL
        {$routeKindSql}
        {$routeCancelledSql}
        {$noBranchSql}
        AND r.id = (
          SELECT MAX(r2.id)
          FROM routes r2
          WHERE r2.document_id = d.id
            AND r2.to_user_id = r.to_user_id
            AND r2.received_at IS NULL
            {$routeKindSubSql}
            {$routeCancelledSubSql}
        )
    ";

    $assignmentsSql = implode("\nUNION ALL\n", $assignmentQueries);
    $verifiedSql = $hasEmailVerifiedAt ? ' AND u.email_verified_at IS NOT NULL' : '';

    $sql = "
      SELECT
        a.document_id,
        a.user_id,
        a.section_id,
        a.branch_id,
        a.route_id,
        a.personal_deadline_at,
        a.assignment_kind,
        u.full_name,
        u.email,
        d.tracking_no,
        d.subject,
        d.deadline_at,
        COALESCE(a.personal_deadline_at, d.deadline_at) AS effective_deadline_at,
        COALESCE(s.name, '') AS section_name
      FROM (
        {$assignmentsSql}
      ) a
      JOIN users u ON u.id = a.user_id
      JOIN documents d ON d.id = a.document_id
      LEFT JOIN sections s ON s.id = a.section_id
      LEFT JOIN email_reminder_log erl
        ON erl.user_id = a.user_id
       AND erl.document_id = a.document_id
       AND erl.reminder_date = ?
       AND erl.reminder_slot = ?
      WHERE u.is_active = 1
        {$verifiedSql}
        AND TRIM(COALESCE(u.email, '')) <> ''
        AND COALESCE(a.personal_deadline_at, d.deadline_at) IS NOT NULL
        AND DATE(COALESCE(a.personal_deadline_at, d.deadline_at)) = ?
        AND erl.id IS NULL
      ORDER BY u.full_name ASC, a.user_id ASC, effective_deadline_at ASC, d.tracking_no ASC, d.id ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sss', $targetDate, $slot, $targetDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $deduped = [];
    foreach ($rows as $row) {
        $userId = (int)($row['user_id'] ?? 0);
        $documentId = (int)($row['document_id'] ?? 0);
        if ($userId <= 0 || $documentId <= 0) {
            continue;
        }

        $key = $userId . ':' . $documentId;
        $currentDeadline = trim((string)($row['effective_deadline_at'] ?? ''));
        $existing = $deduped[$key] ?? null;

        if ($existing === null) {
            $deduped[$key] = $row;
            continue;
        }

        $existingDeadline = trim((string)($existing['effective_deadline_at'] ?? ''));
        $replace = false;

        if ($existingDeadline === '' && $currentDeadline !== '') {
            $replace = true;
        } elseif ($existingDeadline !== '' && $currentDeadline !== '' && strcmp($currentDeadline, $existingDeadline) < 0) {
            $replace = true;
        } elseif (trim((string)($existing['personal_deadline_at'] ?? '')) === '' && trim((string)($row['personal_deadline_at'] ?? '')) !== '') {
            $replace = true;
        }

        if ($replace) {
            $deduped[$key] = $row;
        }
    }

    return array_values($deduped);
}

function document_deadline_reminders_mark_sent(mysqli $conn, int $userId, array $documentRows, string $slot, ?DateTimeImmutable $now = null): array
{
    if ($userId <= 0 || $documentRows === []) {
        return ['logged' => 0, 'skipped' => 0];
    }

    $slot = document_deadline_reminder_normalize_slot($slot);
    $targetDate = document_deadline_reminders_target_date($now)->format('Y-m-d');
    $sentAt = ($now ? $now->setTimezone(new DateTimeZone('Asia/Manila')) : new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
        ->format('Y-m-d H:i:s');

    $stmt = $conn->prepare("
      INSERT IGNORE INTO email_reminder_log
        (user_id, document_id, route_id, reminder_date, reminder_slot, effective_deadline_at, sent_at)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare reminder log insert: ' . $conn->error);
    }

    $loggedCount = 0;
    $skippedCount = 0;

    foreach ($documentRows as $row) {
        $documentId = (int)($row['document_id'] ?? 0);
        if ($documentId <= 0) {
            continue;
        }

        $routeId = array_key_exists('route_id', $row) && $row['route_id'] !== null
            ? (int)$row['route_id']
            : null;
        $effectiveDeadlineAt = trim((string)($row['effective_deadline_at'] ?? ''));
        if ($effectiveDeadlineAt === '') {
            $effectiveDeadlineAt = null;
        }

        $stmt->bind_param(
            'iiissss',
            $userId,
            $documentId,
            $routeId,
            $targetDate,
            $slot,
            $effectiveDeadlineAt,
            $sentAt
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('Failed to write reminder log: ' . $stmt->error);
        }

        if ($stmt->affected_rows > 0) {
            $loggedCount++;
        } else {
            $skippedCount++;
        }
    }

    $stmt->close();

    return [
        'logged' => $loggedCount,
        'skipped' => $skippedCount,
    ];
}
