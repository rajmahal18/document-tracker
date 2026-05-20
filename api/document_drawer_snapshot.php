<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../core/project_codes.php';
require_once __DIR__ . '/../core/document_split.php';
require_once __DIR__ . '/../core/working_time.php';
require_once __DIR__ . '/../core/workflow.php';

header('Content-Type: application/json; charset=utf-8');

require_login();

$documentId = (int)($_GET['document_id'] ?? 0);
if ($documentId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid document.']);
  exit;
}

if (!can_view_document_family($conn, $documentId)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Access denied.']);
  exit;
}

$identity = effective_document_identity($conn);
$myUserId = (int)($identity['effective_user_id'] ?? 0);
$mySectionId = (int)($identity['effective_section_id'] ?? 0);
$isChief = (bool)($identity['effective_is_chief'] ?? false);
$isAdmin = is_admin_user() && !(bool)($identity['assistant_mode'] ?? false);
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
    d.updated_at,
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
    'current_holder_name' => $currentHolderName,
    'current_holder_text' => $currentHolderText,
    'destination_text' => $destinationText,
    'last_holder_text' => $lastHolderText,
    'current_holder_section_name' => $currentHolderName,
    'can_edit_details' => $canEditDetails,
    'can_regenerate_division_slip' => $canRegenerateDivisionSlip,
    'has_my_division_slip' => $hasMyDivisionSlip,
    'my_division_tracking_no' => $myDivisionTrackingNo,
    'origin_division_code' => $originDivisionCode,
    'activity_label' => 'Days stuck',
    'activity_value' => (string)dt_working_days_from_minutes($workingMinutesStuck, $conn),
    'days_stuck' => dt_working_days_from_minutes($workingMinutesStuck, $conn),
    'working_minutes_stuck' => $workingMinutesStuck,
    'open_route_count' => $openRouteCount,
    'in_transit' => $inTransit,
    'my_has_open_inbound' => $myHasOpenInbound,
    'my_has_actionable_role' => $myHasActionableRole,
    'my_can_change_lifecycle' => $myCanChangeLifecycle,
    'my_has_participation' => $myHasParticipation,
    'my_is_visible_only' => $isVisibleOnly,
    'my_is_for_reference' => $isForReference,
    'my_is_receive_only' => 0,
    'viewer_relation_mode' => 'related_followup',
  ],
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
