<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../core/project_codes.php';
require_once __DIR__ . '/../core/document_split.php';
require_once __DIR__ . '/../core/working_time.php';
require_once __DIR__ . '/../core/workflow.php';
require_once __DIR__ . '/../core/division_tracking.php';
require_once __DIR__ . '/../core/chief_dashboard.php';

header('Content-Type: application/json; charset=utf-8');

require_login();

$documentId = (int)($_GET['document_id'] ?? 0);
if ($documentId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid document.']);
  exit;
}

$identity = effective_document_identity($conn);
$chiefViewRequested = (int)($_GET['chief_view'] ?? 0) === 1;
$chiefViewer = chief_dashboard_viewer_from_identity($identity);
$chiefViewAllowed = $chiefViewRequested && chief_dashboard_document_scope_allows($conn, $chiefViewer, $documentId);
if (!can_view_document_family($conn, $documentId) && !$chiefViewAllowed) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Access denied.']);
  exit;
}

$myUserId = (int)($identity['effective_user_id'] ?? 0);
$actualUserId = (int)($identity['actual_user_id'] ?? 0);
$mySectionId = (int)($identity['effective_section_id'] ?? 0);
$isChief = (bool)($identity['effective_is_chief'] ?? false);
$isAdmin = is_admin_user() && !(bool)($identity['assistant_mode'] ?? false);
$actionRequestViewerUserIds = (!empty($identity['assistant_mode']) && $actualUserId > 0 && $actualUserId !== $myUserId)
  ? [$myUserId, $actualUserId]
  : [$myUserId];
$documentSplitParentReady = document_split_parent_link_ready($conn);
$myDivisionMeta = get_user_division_meta($conn, $mySectionId);
$myDivisionId = (int)($myDivisionMeta['id'] ?? 0);
$myDivisionCode = strtoupper(trim((string)($myDivisionMeta['code'] ?? '')));
$hasOwnDivisionSlip = $myDivisionId > 0 && is_supported_division_tracking_code($myDivisionCode);

$parentSelectSql = $documentSplitParentReady
  ? "COALESCE(d.parent_document_id, 0) AS parent_document_id,
    COALESCE((
      SELECT p_rel.tracking_no
      FROM documents p_rel
      WHERE p_rel.id = d.parent_document_id
      LIMIT 1
    ), '') AS parent_tracking_no,
    COALESCE((
      SELECT COUNT(*)
      FROM documents c_rel
      WHERE c_rel.parent_document_id = d.id
    ), 0) AS child_document_count,
    COALESCE((
      SELECT COUNT(*)
      FROM document_events e_setup
      WHERE e_setup.document_id = d.id
        AND e_setup.event_type = 'child_setup_completed'
    ), 0) AS child_setup_completed_count"
  : "0 AS parent_document_id,
    '' AS parent_tracking_no,
    0 AS child_document_count,
    0 AS child_setup_completed_count";

$stmt = $conn->prepare("
  SELECT
    d.id,
    d.tracking_no,
    d.requester,
    d.document_date,
    d.deadline_at,
    d.subject,
    d.content_type,
    d.comm_type,
    d.current_status,
    d.current_holder_section_id,
    d.origin_section_id,
    d.created_by_user_id,
    d.created_at,
    d.updated_at,
    COALESCE((
      SELECT JSON_UNQUOTE(JSON_EXTRACT(e_end.payload_json, '$.kind'))
      FROM document_events e_end
      WHERE e_end.document_id = d.id
        AND e_end.event_type = 'updated'
        AND JSON_UNQUOTE(JSON_EXTRACT(e_end.payload_json, '$.kind')) IN (
          'branch_ended_here',
          'document_ended_here',
          'branch_end_here_undone',
          'document_end_here_undone'
        )
      ORDER BY e_end.created_at DESC, e_end.id DESC
      LIMIT 1
    ), '') AS last_end_here_kind,
    COALESCE((
      SELECT e_closed.created_at
      FROM document_events e_closed
      WHERE e_closed.document_id = d.id
        AND (
          (
            d.current_status = 'ARCHIVED'
            AND e_closed.event_type = 'archived'
            AND JSON_UNQUOTE(JSON_EXTRACT(e_closed.payload_json, '$.new_status')) = 'ARCHIVED'
          )
          OR (
            d.current_status = 'RELEASED'
            AND (
              (
                e_closed.event_type = 'released'
                AND JSON_UNQUOTE(JSON_EXTRACT(e_closed.payload_json, '$.new_status')) = 'RELEASED'
              )
              OR JSON_UNQUOTE(JSON_EXTRACT(e_closed.payload_json, '$.kind')) IN (
                'branch_ended_here',
                'document_ended_here'
              )
            )
          )
        )
      ORDER BY e_closed.created_at DESC, e_closed.id DESC
      LIMIT 1
    ), d.updated_at) AS lifecycle_closed_at,
    sh.name AS current_holder_name,
    {$parentSelectSql}
  FROM documents d
  LEFT JOIN sections sh ON sh.id = d.current_holder_section_id
  WHERE d.id = ?
  LIMIT 1
");
$stmt->bind_param('i', $documentId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if (!$doc) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Document not found.']);
  exit;
}

$projects = fetch_document_projects($conn, $documentId, true);
$projectCodes = array_values(array_filter(array_map(
  static fn(array $row): string => trim((string)($row['project_code'] ?? '')),
  $projects
), static fn(string $value): bool => $value !== ''));
$projectIds = array_values(array_filter(array_map(
  static fn(array $row): int => (int)($row['id'] ?? 0),
  $projects
), static fn(int $value): bool => $value > 0));

$stuckSince = (string)($doc['updated_at'] ?? '');
$workingMinutesStuck = $stuckSince !== '' && strtoupper((string)($doc['current_status'] ?? 'ACTIVE')) === 'ACTIVE'
  ? dt_working_minutes_between($stuckSince, null, $conn)
  : 0;

$currentHolderName = trim((string)($doc['current_holder_name'] ?? ''));
$currentHolderText = $currentHolderName !== '' ? $currentHolderName : '—';
$currentStatus = strtoupper(trim((string)($doc['current_status'] ?? 'ACTIVE')));
$deadlineBadgeText = '';
$deadlineBadgeClass = 'neutral';
$deadlineRaw = trim((string)($doc['deadline_at'] ?? ''));
$createdRaw = trim((string)($doc['created_at'] ?? ''));
$closedRaw = trim((string)($doc['lifecycle_closed_at'] ?? $doc['updated_at'] ?? ''));
if ($currentStatus === 'RELEASED') {
  $completedDays = ($createdRaw !== '' && $closedRaw !== '')
    ? dt_working_days_between_ceil($createdRaw, $closedRaw, $conn)
    : 0;
  $deadlineBadgeText = $completedDays === 1 ? 'COMPLETED IN 1 DAY' : "COMPLETED IN {$completedDays} DAYS";
  $deadlineBadgeClass = 'safe';
} elseif ($currentStatus === 'ARCHIVED') {
  $deadlineBadgeText = 'ARCHIVED';
  $deadlineBadgeClass = 'neutral';
} elseif ($deadlineRaw !== '') {
  $deadlineBaseTs = strtotime($deadlineRaw);
  $deadlineTs = $deadlineBaseTs !== false ? strtotime(date('Y-m-d', $deadlineBaseTs) . ' 23:59:59') : false;
  if ($deadlineTs !== false) {
    $secondsLeft = $deadlineTs - time();
    if ($secondsLeft < 0) {
      $lateDays = dt_working_days_between_ceil(date('Y-m-d H:i:s', $deadlineTs), null, $conn);
      $deadlineBadgeText = $lateDays === 1 ? 'OVERDUE 1 DAY' : "OVERDUE {$lateDays} DAYS";
      $deadlineBadgeClass = 'danger';
    } elseif ($secondsLeft <= 86400) {
      $deadlineBadgeText = 'DUE TODAY';
      $deadlineBadgeClass = 'today';
    } elseif ($secondsLeft <= 259200) {
      $daysLeft = (int)floor($secondsLeft / 86400);
      $deadlineBadgeText = $daysLeft <= 1 ? '1 DAY LEFT' : "{$daysLeft} DAYS LEFT";
      $deadlineBadgeClass = 'warn';
    } else {
      $daysLeft = (int)floor($secondsLeft / 86400);
      $deadlineBadgeText = "{$daysLeft} DAYS LEFT";
      $deadlineBadgeClass = 'safe';
    }
  }
}
$canSplitProjects = document_split_can_create_children($conn, $documentId, $myUserId, $mySectionId, $isChief, $isAdmin);
$myDivisionTrackingNo = '';
$hasMyDivisionSlip = 0;
$canRegenerateDivisionSlip = 0;
$originDivisionCode = '';
if ($hasOwnDivisionSlip) {
  $trackingRow = get_document_division_tracking($conn, $documentId, $myDivisionId);
  $myDivisionTrackingNo = trim((string)($trackingRow['tracking_no'] ?? ''));
  $hasMyDivisionSlip = $myDivisionTrackingNo !== '' ? 1 : 0;

  $holderDivisionStmt = $conn->prepare("
    SELECT 1
    FROM sections s
    WHERE s.id = ?
      AND s.division_id = ?
    LIMIT 1
  ");
  $currentHolderSectionId = (int)($doc['current_holder_section_id'] ?? 0);
  $holderDivisionStmt->bind_param('ii', $currentHolderSectionId, $myDivisionId);
  $holderDivisionStmt->execute();
  $canRegenerateDivisionSlip = $holderDivisionStmt->get_result()->fetch_row() ? 1 : 0;
}

$originSectionId = (int)($doc['origin_section_id'] ?? 0);
if ($originSectionId > 0) {
  $originDivisionMeta = get_user_division_meta($conn, $originSectionId);
  $originDivisionCode = strtoupper(trim((string)($originDivisionMeta['code'] ?? '')));
}

$openRouteStmt = $conn->prepare("
  SELECT
    r.id,
    r.from_section_id,
    sf.name AS from_section_name,
    r.to_section_id,
    st.name AS to_section_name,
    r.to_user_id,
    u.full_name AS to_user_name
  FROM routes r
  LEFT JOIN sections sf ON sf.id = r.from_section_id
  LEFT JOIN sections st ON st.id = r.to_section_id
  LEFT JOIN users u ON u.id = r.to_user_id
  WHERE r.document_id = ?
    AND r.received_at IS NULL
    AND r.cancelled_at IS NULL
  ORDER BY r.id DESC
  LIMIT 1
");
$openRouteStmt->bind_param('i', $documentId);
$openRouteStmt->execute();
$openRoute = $openRouteStmt->get_result()->fetch_assoc() ?: null;
$inTransit = $openRoute ? 1 : 0;
$openRouteCount = $openRoute ? 1 : 0;

$destinationText = '—';
$lastHolderText = '—';
$myHasOpenInbound = 0;
if ($openRoute) {
  $toUserName = trim((string)($openRoute['to_user_name'] ?? ''));
  $toSectionName = trim((string)($openRoute['to_section_name'] ?? ''));
  $destinationText = $toUserName !== '' ? $toUserName : ($toSectionName !== '' ? $toSectionName : '—');

  $fromSectionName = trim((string)($openRoute['from_section_name'] ?? ''));
  $lastHolderText = $fromSectionName !== '' ? $fromSectionName : '—';

  $toUserId = (int)($openRoute['to_user_id'] ?? 0);
  $toSectionId = (int)($openRoute['to_section_id'] ?? 0);
  if ($toUserId > 0 && $toUserId === $myUserId) {
    $myHasOpenInbound = 1;
  } elseif ($toUserId <= 0 && $isChief && $toSectionId === $mySectionId) {
    $myHasOpenInbound = 1;
  }
}

$hasRealBranches = false;
if (workflow_has_table($conn, 'document_branches')) {
  $branchStmt = $conn->prepare("SELECT 1 FROM document_branches WHERE document_id = ? LIMIT 1");
  $branchStmt->bind_param('i', $documentId);
  $branchStmt->execute();
  $hasRealBranches = $branchStmt->get_result()->fetch_row() ? true : false;
}
$singleActionableBranch = workflow_find_single_actionable_branch($conn, $documentId, $myUserId);
$myHasActionableRole = $singleActionableBranch ? 1 : (workflow_user_can_act_legacy_document($conn, $documentId, $myUserId, $mySectionId, $isChief, false) ? 1 : 0);
$myCanChangeLifecycle = $singleActionableBranch ? 0 : (workflow_user_can_act_legacy_document($conn, $documentId, $myUserId, $mySectionId, $isChief, true) ? 1 : 0);
$flatAttachmentForwardMeta = [
  'attachment_forward_source_branch' => 0,
  'attachment_forward_recipient_branch' => 0,
  'attachment_forward_open_task_count' => 0,
  'attachment_forward_can_attach' => 0,
  'attachment_forward_can_mark_done' => 0,
  'attachment_forward_task_status' => '',
];
$attachmentForwardTaskSummary = [];
$flatActionRequestMeta = [
  'action_request_source_branch' => 0,
  'action_request_recipient_branch' => 0,
  'action_request_open_task_count' => 0,
  'action_request_can_decide' => 0,
  'action_request_task_status' => '',
];
$actionRequestSummary = [];
if ($myUserId > 0) {
  if ($hasRealBranches) {
    if (workflow_attachment_forwarding_enabled($conn)) {
      $attachmentForwardTaskSummary = workflow_get_attachment_forward_task_summary($conn, $documentId, $myUserId);
    }
    if (workflow_action_requests_enabled($conn)) {
      $actionRequestSummary = workflow_get_action_request_summary($conn, $documentId, $actionRequestViewerUserIds);
    }
  } else {
    if (workflow_attachment_forwarding_enabled($conn)) {
      $flatAttachmentForwardMeta = workflow_get_document_attachment_forward_task_meta($conn, $documentId, $myUserId);
      $attachmentForwardTaskSummary = workflow_get_attachment_forward_task_summary($conn, $documentId, $myUserId, 0, 0);
    }
    if (workflow_action_requests_enabled($conn)) {
      $flatActionRequestMeta = workflow_get_document_action_request_meta($conn, $documentId, $actionRequestViewerUserIds);
      $actionRequestSummary = workflow_get_action_request_summary($conn, $documentId, $actionRequestViewerUserIds, 0, 0);
    }
  }
}
$flatAttachmentTaskStatus = strtoupper((string)($flatAttachmentForwardMeta['attachment_forward_task_status'] ?? ''));
$flatAttachmentIsSender = (!$hasRealBranches && (int)($flatAttachmentForwardMeta['attachment_forward_source_branch'] ?? 0) === 1);
$flatAttachmentIsRecipient = (!$hasRealBranches && (int)($flatAttachmentForwardMeta['attachment_forward_recipient_branch'] ?? 0) === 1);
$flatAttachmentSenderWaiting = $flatAttachmentIsSender && (int)($flatAttachmentForwardMeta['attachment_forward_open_task_count'] ?? 0) > 0;
$flatAttachmentRecipientPendingReceive = $flatAttachmentIsRecipient && $flatAttachmentTaskStatus === 'PENDING_RECEIVE';
$flatAttachmentRecipientInProgress = $flatAttachmentIsRecipient && $flatAttachmentTaskStatus === 'IN_PROGRESS';
$flatAttachmentRecipientCompleted = $flatAttachmentIsRecipient && !$flatAttachmentRecipientPendingReceive && !$flatAttachmentRecipientInProgress && (int)($flatAttachmentForwardMeta['attachment_forward_open_task_count'] ?? 0) === 0;
$flatActionRequestTaskStatus = strtoupper((string)($flatActionRequestMeta['action_request_task_status'] ?? ''));
$flatActionRequestIsSender = (!$hasRealBranches && (int)($flatActionRequestMeta['action_request_source_branch'] ?? 0) === 1);
$flatActionRequestIsRecipient = (!$hasRealBranches && (int)($flatActionRequestMeta['action_request_recipient_branch'] ?? 0) === 1);
$flatActionRequestSenderWaiting = $flatActionRequestIsSender && (int)($flatActionRequestMeta['action_request_open_task_count'] ?? 0) > 0;
$flatActionRequestRecipientPendingReceive = $flatActionRequestIsRecipient && $flatActionRequestTaskStatus === 'PENDING_RECEIVE';
$flatActionRequestRecipientInProgress = $flatActionRequestIsRecipient && $flatActionRequestTaskStatus === 'IN_PROGRESS';
$flatActionRequestRecipientCompleted = $flatActionRequestIsRecipient && !$flatActionRequestRecipientPendingReceive && !$flatActionRequestRecipientInProgress && (int)($flatActionRequestMeta['action_request_open_task_count'] ?? 0) === 0;

if (!$hasRealBranches) {
  if ($flatAttachmentRecipientPendingReceive || $flatActionRequestRecipientPendingReceive) {
    $myHasOpenInbound = 1;
    $myHasActionableRole = 0;
    $myCanChangeLifecycle = 0;
  } elseif ($flatAttachmentRecipientInProgress || $flatAttachmentSenderWaiting || $flatActionRequestRecipientInProgress || $flatActionRequestSenderWaiting || $flatAttachmentRecipientCompleted) {
    $myHasOpenInbound = 0;
    $myHasActionableRole = 0;
    $myCanChangeLifecycle = 0;
  }
}

$myHasParticipation = 0;
if ((int)($doc['created_by_user_id'] ?? 0) === $myUserId) {
  $myHasParticipation = 1;
} else {
  $participantStmt = $conn->prepare("
    SELECT 1
    FROM routes r
    WHERE r.document_id = ?
      AND (
        r.to_user_id = ?
        OR r.sent_by_user_id = ?
        OR r.received_by_user_id = ?
      )
    LIMIT 1
  ");
  $participantStmt->bind_param('iiii', $documentId, $myUserId, $myUserId, $myUserId);
  $participantStmt->execute();
  $myHasParticipation = $participantStmt->get_result()->fetch_row() ? 1 : 0;
}

$routesExistStmt = $conn->prepare("SELECT 1 FROM routes WHERE document_id = ? LIMIT 1");
$routesExistStmt->bind_param('i', $documentId);
$routesExistStmt->execute();
$hasAnyRoutes = $routesExistStmt->get_result()->fetch_row() ? 1 : 0;

$canEditDetails = 0;
if (
  $currentStatus === 'ACTIVE'
  && ($isAdmin || (int)($doc['created_by_user_id'] ?? 0) === $myUserId)
  && !$hasAnyRoutes
  && !$hasRealBranches
) {
  $canEditDetails = 1;
}
$needsChildSetup = (
  (int)($doc['parent_document_id'] ?? 0) > 0
  && $canEditDetails === 1
  && (int)($doc['child_setup_completed_count'] ?? 0) === 0
) ? 1 : 0;

$statusChipClass = 'chip incoming';
if ($currentStatus === 'ARCHIVED') {
  $statusChipClass = 'chip archived';
} elseif ($currentStatus === 'RELEASED') {
  $statusChipClass = 'chip released';
} elseif ($inTransit) {
  $statusChipClass = 'chip action';
}

$isVisibleOnly = ($myHasActionableRole || $myHasOpenInbound) ? 0 : 1;
$isForReference = ($myHasActionableRole || $myHasOpenInbound) ? 0 : 1;

echo json_encode([
  'ok' => true,
  'document' => [
    'id' => (int)($doc['id'] ?? 0),
    'tracking_no' => (string)($doc['tracking_no'] ?? ''),
    'division_tracking_no' => '',
    'tracking_display' => (string)($doc['tracking_no'] ?? ''),
    'requester' => (string)($doc['requester'] ?? ''),
    'document_date' => (string)($doc['document_date'] ?? ''),
    'deadline_at' => $doc['deadline_at'] ?? null,
    'subject' => (string)($doc['subject'] ?? ''),
    'content_type' => (string)($doc['content_type'] ?? ''),
    'comm_type' => (string)($doc['comm_type'] ?? ''),
    'project_codes' => $projectCodes,
    'project_ids' => $projectIds,
    'parent_document_id' => (int)($doc['parent_document_id'] ?? 0),
    'parent_tracking_no' => (string)($doc['parent_tracking_no'] ?? ''),
    'child_document_count' => (int)($doc['child_document_count'] ?? 0),
    'needs_child_setup' => $needsChildSetup,
    'can_split_projects' => $canSplitProjects ? 1 : 0,
    'current_status' => $currentStatus,
    'status_label' => $currentStatus,
    'status_chip_class' => $statusChipClass,
    'last_end_here_kind' => (string)($doc['last_end_here_kind'] ?? ''),
    'current_holder_name' => $currentHolderName,
    'current_holder_text' => $currentHolderText,
    'movement_text' => $destinationText,
    'destination_text' => $destinationText,
    'last_holder_text' => $lastHolderText,
    'current_holder_section_name' => $currentHolderName,
    'open_from_section_name' => (string)($openRoute['from_section_name'] ?? ''),
    'open_to_section_id' => (int)($openRoute['to_section_id'] ?? 0),
    'open_to_user_id' => (int)($openRoute['to_user_id'] ?? 0),
    'my_open_route_id' => $myHasOpenInbound ? (int)($openRoute['id'] ?? 0) : 0,
    'my_personal_deadline_at' => null,
    'deadline_badge_text' => $deadlineBadgeText,
    'deadline_badge_class' => $deadlineBadgeClass,
    'can_edit_details' => $canEditDetails,
    'can_regenerate_division_slip' => $canRegenerateDivisionSlip,
    'has_my_division_slip' => $hasMyDivisionSlip,
    'my_division_tracking_no' => $myDivisionTrackingNo,
    'origin_division_code' => $originDivisionCode,
    'activity_label' => 'Days stuck',
    'activity_value' => (string)dt_working_days_from_minutes($workingMinutesStuck, $conn),
    'activity_text' => 'Days stuck: ' . (string)dt_working_days_from_minutes($workingMinutesStuck, $conn),
    'latest_activity_title' => 'Days stuck: ' . (string)dt_working_days_from_minutes($workingMinutesStuck, $conn),
    'latest_activity_detail' => '',
    'latest_activity_at_display' => '',
    'days_stuck' => dt_working_days_from_minutes($workingMinutesStuck, $conn),
    'working_minutes_stuck' => $workingMinutesStuck,
    'open_route_count' => $openRouteCount,
    'has_real_branches' => $hasRealBranches ? 1 : 0,
    'is_initial_routing' => $hasAnyRoutes ? 0 : 1,
    'in_transit' => $inTransit,
    'my_has_open_inbound' => $myHasOpenInbound,
    'my_has_actionable_role' => $myHasActionableRole,
    'my_can_change_lifecycle' => $myCanChangeLifecycle,
    'my_has_participation' => $myHasParticipation,
    'my_is_visible_only' => $isVisibleOnly,
    'my_is_for_reference' => $isForReference,
    'my_is_receive_only' => 0,
    'attachment_forward_open_task_count' => (int)($flatAttachmentForwardMeta['attachment_forward_open_task_count'] ?? 0),
    'attachment_forward_can_attach' => (int)($flatAttachmentForwardMeta['attachment_forward_can_attach'] ?? 0),
    'attachment_forward_can_mark_done' => (int)($flatAttachmentForwardMeta['attachment_forward_can_mark_done'] ?? 0),
    'attachment_forward_recipient_branch' => (int)($flatAttachmentForwardMeta['attachment_forward_recipient_branch'] ?? 0),
    'attachment_forward_source_branch' => (int)($flatAttachmentForwardMeta['attachment_forward_source_branch'] ?? 0),
    'attachment_forward_task_status' => (string)($flatAttachmentForwardMeta['attachment_forward_task_status'] ?? ''),
    'attachment_forward_task_summary' => $attachmentForwardTaskSummary,
    'flat_attachment_sender_waiting' => $flatAttachmentSenderWaiting ? 1 : 0,
    'flat_attachment_recipient_pending_receive' => $flatAttachmentRecipientPendingReceive ? 1 : 0,
    'flat_attachment_recipient_in_progress' => $flatAttachmentRecipientInProgress ? 1 : 0,
    'flat_attachment_recipient_completed' => $flatAttachmentRecipientCompleted ? 1 : 0,
    'action_request_open_task_count' => (int)($flatActionRequestMeta['action_request_open_task_count'] ?? 0),
    'action_request_can_decide' => (int)($flatActionRequestMeta['action_request_can_decide'] ?? 0),
    'action_request_recipient_branch' => (int)($flatActionRequestMeta['action_request_recipient_branch'] ?? 0),
    'action_request_source_branch' => (int)($flatActionRequestMeta['action_request_source_branch'] ?? 0),
    'action_request_task_status' => (string)($flatActionRequestMeta['action_request_task_status'] ?? ''),
    'action_request_summary' => $actionRequestSummary,
    'flat_action_request_sender_waiting' => $flatActionRequestSenderWaiting ? 1 : 0,
    'flat_action_request_recipient_pending_receive' => $flatActionRequestRecipientPendingReceive ? 1 : 0,
    'flat_action_request_recipient_in_progress' => $flatActionRequestRecipientInProgress ? 1 : 0,
    'flat_action_request_recipient_completed' => $flatActionRequestRecipientCompleted ? 1 : 0,
    'viewer_relation_mode' => 'related_followup',
  ],
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
