<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_once __DIR__ . "/../core/division_tracking.php";
require_once __DIR__ . "/../core/working_time.php";
require_once __DIR__ . "/../core/project_codes.php";
require_login();

/* -------------------------
 * Assistant mode bootstrap
 * ------------------------- */
$assistantPrincipals = [];
$assistantModeEnabled = false;
$activeAssistantPrincipal = null;
$requestedDocumentsTab = strtolower(trim((string)($_GET['view'] ?? 'my')));
if ($requestedDocumentsTab !== 'assistant') {
  $requestedDocumentsTab = 'my';
}
$requestedActingPrincipalUserId = (int)($_GET['acting_principal_user_id'] ?? 0);

$actualUserIdForAssistantLookup = (int)($_SESSION['user_id'] ?? 0);
if ($actualUserIdForAssistantLookup > 0) {
  $assistantPrincipals = assistant_fetch_assigned_principals($conn, $actualUserIdForAssistantLookup);
}

if ($assistantPrincipals !== [] && $requestedDocumentsTab === 'assistant') {
  if ($requestedActingPrincipalUserId > 0) {
    foreach ($assistantPrincipals as $principal) {
      if ((int)($principal['id'] ?? 0) === $requestedActingPrincipalUserId) {
        $activeAssistantPrincipal = $principal;
        break;
      }
    }
  }

  if ($activeAssistantPrincipal === null) {
    $activeAssistantPrincipal = $assistantPrincipals[0];
  }

  if ($activeAssistantPrincipal !== null) {
    $assistantModeEnabled = true;
  }
}

/* ✅ Division-aware sections */

/* ✅ Division-aware sections */
$sections = $conn->query("
  SELECT s.id, s.name, d.name AS division_name
  FROM sections s
  JOIN divisions d ON d.id = s.division_id
  WHERE s.is_active = 1 AND d.is_active = 1
  ORDER BY d.name ASC, s.name ASC
")->fetch_all(MYSQLI_ASSOC);

$branchMode = workflow_branch_mode_enabled($conn);
if (function_exists('workflow_repair_reference_only_routes')) {
  workflow_repair_reference_only_routes($conn);
}
if ($branchMode && function_exists('workflow_repair_reference_only_source_lanes')) {
  workflow_repair_reference_only_source_lanes($conn);
}
$routePersonalDeadlineEnabled = workflow_has_column($conn, 'routes', 'personal_deadline_at');
$documentContextSectionId = $assistantModeEnabled
  ? (int)($activeAssistantPrincipal['section_id'] ?? 0)
  : (int)($_SESSION['section_id'] ?? 0);
$myDivisionMeta = get_user_division_meta($conn, $documentContextSectionId);
$myDivisionId = (int)($myDivisionMeta['id'] ?? 0);
$myDivisionCode = strtoupper(trim((string)($myDivisionMeta['code'] ?? '')));
$hasOwnDivisionSlip = is_supported_division_tracking_code($myDivisionCode);
$ownDivisionSlipLabel = $hasOwnDivisionSlip ? ($myDivisionCode . ' Tracking Slip') : '';

$pageTitle = "Documents - Document Tracker";
require __DIR__ . "/../includes/layout.php";
?>

<script>
  window.__CSRF__ = "<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>";

  window.__CTX__ = {
    actualUserId: <?= (int)($_SESSION["user_id"] ?? 0) ?>,
    myUserId: <?= $assistantModeEnabled ? (int)($activeAssistantPrincipal['id'] ?? 0) : (int)($_SESSION["user_id"] ?? 0) ?>,
    mySectionId: <?= $assistantModeEnabled ? (int)($activeAssistantPrincipal['section_id'] ?? 0) : (int)($_SESSION["section_id"] ?? 0) ?>,
    myRole: "<?= htmlspecialchars($_SESSION["role"] ?? "user") ?>",
    isChief: <?= ($assistantModeEnabled ? in_array((string)($activeAssistantPrincipal['authority_role'] ?? ''), ['director','division_head','section_head'], true) : ((int)($_SESSION["is_chief"] ?? 0) === 1)) ? "true" : "false" ?>,
    myDivisionName: "<?= htmlspecialchars($assistantModeEnabled ? (string)($activeAssistantPrincipal['division_name'] ?? '') : (string)($_SESSION["division_name"] ?? "")) ?>",
    myDivisionCode: "<?= htmlspecialchars($myDivisionCode) ?>",
    hasOwnDivisionSlip: <?= $hasOwnDivisionSlip ? "true" : "false" ?>,
    ownDivisionSlipLabel: "<?= htmlspecialchars($ownDivisionSlipLabel) ?>",
    branchMode: <?= $branchMode ? "true" : "false" ?>,
    assistantMode: <?= $assistantModeEnabled ? 'true' : 'false' ?>,
    actingPrincipalUserId: <?= (int)($activeAssistantPrincipal['id'] ?? 0) ?>,
    actingPrincipalName: "<?= htmlspecialchars((string)($activeAssistantPrincipal['full_name'] ?? '')) ?>"
  };

  window.__SECTIONS__ = <?= json_encode($sections, JSON_UNESCAPED_UNICODE) ?>;
</script>

<?php
$createdDocFlash = $_SESSION["documents_created_flash"] ?? null;
unset($_SESSION["documents_created_flash"]);
$createdDocId = is_array($createdDocFlash) ? (int)($createdDocFlash["doc_id"] ?? 0) : 0;
$createdTrackingNo = is_array($createdDocFlash) ? trim((string)($createdDocFlash["tracking_no"] ?? "")) : "";
$createdFlashMessage = is_array($createdDocFlash) ? trim((string)($createdDocFlash["message"] ?? "")) : "";
?>
<?php if ($createdDocId > 0): ?>
<script>
  try {
    sessionStorage.setItem("dt_restore_drawer", JSON.stringify({
      docId: <?= (int)$createdDocId ?>,
      branchId: 0,
      at: Date.now()
    }));
  } catch (_) {}
</script>
<?php endif; ?>

<?php
// -------------------------
// Filters (GET)
// -------------------------
$search    = trim($_GET["q"] ?? "");
$statusGet = trim($_GET["status"] ?? "");
$date_from = trim($_GET["from"] ?? "");
$date_to   = trim($_GET["to"] ?? "");
$quick = strtolower(trim($_GET["quick"] ?? "")); // active | overdue | released_today | archived
$sort = strtolower(trim($_GET["sort"] ?? "")); // workflow | newest | urgent | overdue_longest | oldest

// Pagination
$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;

$perPage = 15;   // ✅ fixed, unchangeable

$offset = ($page - 1) * $perPage;

$role        = (string)($_SESSION["role"] ?? "user");
$actualUserId = (int)($_SESSION["user_id"] ?? 0);
$actualSectionId = (int)($_SESSION["section_id"] ?? 0);
$actualIsChief = ((int)($_SESSION["is_chief"] ?? 0) === 1);
$myUserId    = $assistantModeEnabled ? (int)($activeAssistantPrincipal['id'] ?? 0) : $actualUserId;
$mySectionId = $assistantModeEnabled ? (int)($activeAssistantPrincipal['section_id'] ?? 0) : $actualSectionId;
$isChief     = $assistantModeEnabled ? in_array((string)($activeAssistantPrincipal['authority_role'] ?? ''), ['director','division_head','section_head'], true) : $actualIsChief;

$assistantOwnPageIsolationSql = "";
if (!$assistantModeEnabled && $actualUserId > 0 && $assistantPrincipals !== []) {
  $actualUid = (int)$actualUserId;
  $assistantOwnPageIsolationSql = "NOT (
    EXISTS (
      SELECT 1
      FROM document_events e_acting
      WHERE e_acting.document_id = d.id
        AND e_acting.actor_user_id = {$actualUid}
        AND e_acting.payload_json REGEXP '\"acting_principal_user_id\"[[:space:]]*:[[:space:]]*[1-9]'
    )
    AND d.created_by_user_id <> {$actualUid}
    AND NOT EXISTS (
      SELECT 1
      FROM routes r_direct
      WHERE r_direct.document_id = d.id
        AND r_direct.to_user_id = {$actualUid}
    )
  )";
}

$where  = [];
$params = [];
$types  = "";
$projectCodesReady = project_codes_tables_ready($conn);

$allowedSorts = ["", "workflow", "newest", "urgent", "overdue_longest", "oldest"];
if (!in_array($sort, $allowedSorts, true)) {
  $sort = "";
}

/**
 * ✅ VISIBILITY RULE
 * Branch mode = creator + explicit user visibility + direct route involvement.
 * Legacy mode = previous section-aware fallback.
 */
$isPrivileged = ($role === "admin");
$isPrivilegedInt = $isPrivileged ? 1 : 0;
if (!$isPrivileged) {
  if ($myUserId <= 0) {
    $where[] = "1=0";
  } else {
    if ($branchMode) {
      $where[] = "(
        d.created_by_user_id = ?
        OR EXISTS (
          SELECT 1
          FROM document_user_visibility duv
          WHERE duv.document_id = d.id
            AND duv.user_id = ?
        )
        OR EXISTS (
          SELECT 1
          FROM routes r
          WHERE r.document_id = d.id
            AND (
              r.to_user_id = ?
              OR r.sent_by_user_id = ?
              OR r.received_by_user_id = ?
            )
        )
      )";

      array_push($params, $myUserId, $myUserId, $myUserId, $myUserId, $myUserId);
      $types .= "iiiii";
    } else {
      $where[] = "(
        d.created_by_user_id = ?

        OR EXISTS (
          SELECT 1
          FROM routes r
          WHERE r.document_id = d.id
            AND (
              r.to_user_id = ?
              OR r.sent_by_user_id = ?
              OR r.received_by_user_id = ?
            )
        )

        OR (
          ? = 1
          AND EXISTS (
            SELECT 1
            FROM routes r
            WHERE r.document_id = d.id
              AND r.received_at IS NULL
              AND r.cancelled_at IS NULL
              AND r.to_section_id = ?
              AND r.to_user_id IS NULL
          )
        )

        OR (
          ? = 1
          AND d.current_holder_section_id = ?
          AND NOT EXISTS (
            SELECT 1
            FROM routes r
            WHERE r.document_id = d.id
              AND r.received_at IS NULL
              AND r.cancelled_at IS NULL
          )
        )
      )";

      array_push(
        $params,
        $myUserId,
        $myUserId,
        $myUserId,
        $myUserId,
        $isChief ? 1 : 0,
        $mySectionId,
        $isChief ? 1 : 0,
        $mySectionId
      );

      $types .= "iiiiiiii";
    }
  }
}
if ($assistantOwnPageIsolationSql !== "") {
  $where[] = $assistantOwnPageIsolationSql;
}

/**
 * Filters
 */
if ($search !== "") {
  $searchPredicate = "(
    d.tracking_no LIKE ?
    OR EXISTS (
      SELECT 1
      FROM document_division_tracking ddt_search
      WHERE ddt_search.document_id = d.id
        AND ddt_search.tracking_no LIKE ?
    )
    OR d.requester LIKE ?
    OR d.subject LIKE ?
    OR d.content_type LIKE ?
    OR sh.name LIKE ?
  )";
  if ($projectCodesReady) {
    $searchPredicate = "(
      {$searchPredicate}
      OR EXISTS (
        SELECT 1
        FROM document_projects dp_search
        JOIN projects p_search ON p_search.id = dp_search.project_id
        WHERE dp_search.document_id = d.id
          AND (
            p_search.project_code LIKE ?
            OR p_search.title LIKE ?
          )
      )
    )";
  }
  $where[] = $searchPredicate;
  $like = "%" . $search . "%";
  array_push($params, $like, $like, $like, $like, $like, $like);
  $types .= "ssssss";
  if ($projectCodesReady) {
    array_push($params, $like, $like);
    $types .= "ss";
  }
}

if ($statusGet !== "" && $quick === "") {
  $where[] = "d.current_status = ?";
  $params[] = strtoupper($statusGet);
  $types .= "s";
}

if ($date_from !== "") {
  $where[] = "d.document_date >= ?";
  $params[] = $date_from;
  $types .= "s";
}

if ($date_to !== "") {
  $where[] = "d.document_date <= ?";
  $params[] = $date_to;
  $types .= "s";
}

// -------------------------
// Main list query (NEW SCHEMA)
// -------------------------
$mySid = (int)$mySectionId;
$myUid = (int)$myUserId;
$myChiefInt = $isChief ? 1 : 0;
$branchModeInt = $branchMode ? 1 : 0;

$hasRealBranchesPredicate = "EXISTS (
  SELECT 1
  FROM document_branches b_chk
  WHERE b_chk.document_id = d.id
)";

$myIsOriginPredicate = "d.created_by_user_id = {$myUid}";

$myHasOpenInboundPredicate = "EXISTS (
  SELECT 1
  FROM routes r_in
  WHERE r_in.document_id = d.id
    AND r_in.received_at IS NULL
    AND r_in.cancelled_at IS NULL
    AND (
      (
        {$hasRealBranchesPredicate}
        AND r_in.route_kind = 'ACTION'
        AND r_in.to_user_id = {$myUid}
        AND EXISTS (
          SELECT 1
          FROM document_branches b_in
          WHERE b_in.id = r_in.branch_id
            AND b_in.current_assignee_user_id = r_in.to_user_id
        )
      )
      OR
      (
        NOT EXISTS (
          SELECT 1
          FROM document_branches b_chk2
          WHERE b_chk2.document_id = d.id
        )
        AND (
          r_in.to_user_id = {$myUid}
          OR ({$myChiefInt} = 1 AND r_in.to_user_id IS NULL AND r_in.to_section_id = {$mySid})
        )
      )
    )
)";

$myHasActionableRolePredicate = "(
  (
    {$hasRealBranchesPredicate}
    AND EXISTS (
      SELECT 1
      FROM document_branches b_act2
      WHERE b_act2.document_id = d.id
        AND b_act2.branch_status = 'ACTIVE'
        AND b_act2.current_assignee_user_id = {$myUid}
        AND b_act2.is_reference = 0
    )
  )
  OR
  (
    NOT EXISTS (
      SELECT 1
      FROM document_branches b_flat
      WHERE b_flat.document_id = d.id
    )
    AND d.current_status = 'ACTIVE'
    AND d.current_holder_section_id = {$mySid}
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
        d.created_by_user_id = {$myUid}
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
          r_last_received.to_user_id = {$myUid}
          OR (
            r_last_received.to_user_id IS NULL
            AND {$myChiefInt} = 1
            AND r_last_received.to_section_id = {$mySid}
          )
        )
      )
    )
  )
)";

$myHasParticipationPredicate = "(
  {$myIsOriginPredicate}
  OR EXISTS (
    SELECT 1
    FROM routes r_part
    WHERE r_part.document_id = d.id
      AND (
        r_part.to_user_id = {$myUid}
        OR r_part.sent_by_user_id = {$myUid}
        OR r_part.received_by_user_id = {$myUid}
      )
  )
  OR EXISTS (
    SELECT 1
    FROM document_events e_part
    WHERE e_part.document_id = d.id
      AND e_part.event_type IN ('sent', 'forwarded')
      AND e_part.payload_json REGEXP '\"acting_principal_user_id\"[[:space:]]*:[[:space:]]*{$myUid}([^0-9]|$)'
  )
)";

$myIsVisibleOnlyPredicate = $branchMode
  ? "EXISTS (
      SELECT 1
      FROM document_user_visibility duv_vis
      WHERE duv_vis.document_id = d.id
        AND duv_vis.user_id = {$myUid}
    )"
  : "0=1";

$myIsForReferencePredicate = "(
  {$hasRealBranchesPredicate}
  AND (
    EXISTS (
      SELECT 1
      FROM document_branches b_ref
      WHERE b_ref.document_id = d.id
        AND b_ref.is_reference = 1
        AND (
          b_ref.current_assignee_user_id = {$myUid}
          OR EXISTS (
            SELECT 1
            FROM routes r_ref
            WHERE r_ref.document_id = d.id
              AND r_ref.branch_id = b_ref.id
              AND (
                r_ref.to_user_id = {$myUid}
                OR r_ref.sent_by_user_id = {$myUid}
                OR r_ref.received_by_user_id = {$myUid}
              )
          )
        )
    )
    OR EXISTS (
      SELECT 1
      FROM routes r_ref_flat
      WHERE r_ref_flat.document_id = d.id
        AND r_ref_flat.route_kind = 'REFERENCE'
        AND (
          r_ref_flat.to_user_id = {$myUid}
          OR r_ref_flat.sent_by_user_id = {$myUid}
          OR r_ref_flat.received_by_user_id = {$myUid}
        )
    )
  )
)";

$myIsReceiveOnlyPredicate = "(
  {$hasRealBranchesPredicate}
  AND EXISTS (
    SELECT 1
    FROM document_branches b_ro
    WHERE b_ro.document_id = d.id
      AND b_ro.branch_status = 'ACTIVE'
      AND b_ro.current_assignee_user_id = {$myUid}
      AND b_ro.is_reference = 1
  )
)";

$myCompletePredicate = "(
  NOT ({$myHasOpenInboundPredicate})
  AND NOT ({$myHasActionableRolePredicate})
  AND (
    {$myHasParticipationPredicate}
    OR {$myIsOriginPredicate}
    OR {$myIsForReferencePredicate}
  )
)";

$personalDeadlineSelectSql = "NULL AS my_personal_deadline_at";
$personalDeadlineJoinSql = "";
$effectiveDeadlineOrderExpr = "TIMESTAMP(DATE(d.deadline_at), '23:59:59')";
$effectiveDeadlineFilterExpr = "TIMESTAMP(DATE(d.deadline_at), '23:59:59')";

if ($routePersonalDeadlineEnabled) {
  $effectiveDeadlineOrderExpr = "TIMESTAMP(DATE(COALESCE(rpd_me.personal_deadline_at, d.deadline_at)), '23:59:59')";
  $personalDeadlineSelectSql = "rpd_me.personal_deadline_at AS my_personal_deadline_at";

  if ($branchMode) {
    $effectiveDeadlineFilterExpr = "TIMESTAMP(DATE(COALESCE((
      SELECT MAX(rpd.personal_deadline_at)
      FROM routes rpd
      LEFT JOIN document_branches bpd ON bpd.id = rpd.branch_id
      LEFT JOIN documents dpd ON dpd.id = rpd.document_id
      WHERE rpd.document_id = d.id
        AND rpd.to_user_id = {$myUid}
        AND rpd.personal_deadline_at IS NOT NULL
        AND (
          rpd.received_at IS NULL
          OR (
            bpd.id IS NOT NULL
            AND bpd.branch_status = 'ACTIVE'
            AND bpd.current_assignee_user_id = {$myUid}
          )
          OR (
            bpd.id IS NULL
            AND dpd.current_holder_section_id = {$mySid}
          )
        )
    ), d.deadline_at)), '23:59:59')";
    $personalDeadlineJoinSql = "
  LEFT JOIN (
    SELECT
      rpd.document_id,
      MAX(rpd.id) AS my_personal_route_id
    FROM routes rpd
    LEFT JOIN document_branches bpd ON bpd.id = rpd.branch_id
    LEFT JOIN documents dpd ON dpd.id = rpd.document_id
    WHERE rpd.to_user_id = {$myUid}
      AND rpd.personal_deadline_at IS NOT NULL
      AND (
        rpd.received_at IS NULL
        OR (
          bpd.id IS NOT NULL
          AND bpd.branch_status = 'ACTIVE'
          AND bpd.current_assignee_user_id = {$myUid}
        )
        OR (
          bpd.id IS NULL
          AND dpd.current_holder_section_id = {$mySid}
        )
      )
    GROUP BY rpd.document_id
  ) rpd_pick ON rpd_pick.document_id = d.id
  LEFT JOIN routes rpd_me ON rpd_me.id = rpd_pick.my_personal_route_id
";
  } else {
    $effectiveDeadlineFilterExpr = "TIMESTAMP(DATE(COALESCE((
      SELECT MAX(rpd.personal_deadline_at)
      FROM routes rpd
      JOIN documents dpd ON dpd.id = rpd.document_id
      WHERE rpd.document_id = d.id
        AND rpd.to_user_id = {$myUid}
        AND rpd.personal_deadline_at IS NOT NULL
        AND (
          rpd.received_at IS NULL
          OR dpd.current_holder_section_id = {$mySid}
        )
    ), d.deadline_at)), '23:59:59')";

    $personalDeadlineJoinSql = "
  LEFT JOIN (
    SELECT
      rpd.document_id,
      MAX(rpd.id) AS my_personal_route_id
    FROM routes rpd
    JOIN documents dpd ON dpd.id = rpd.document_id
    WHERE rpd.to_user_id = {$myUid}
      AND rpd.personal_deadline_at IS NOT NULL
      AND (
        rpd.received_at IS NULL
        OR dpd.current_holder_section_id = {$mySid}
      )
    GROUP BY rpd.document_id
  ) rpd_pick ON rpd_pick.document_id = d.id
  LEFT JOIN routes rpd_me ON rpd_me.id = rpd_pick.my_personal_route_id
";
  }
}

// -------------------------
// Quick filters (from cards / queue pills)
// -------------------------
if ($quick !== "") {
  if ($quick === "incoming") {
    $where[] = "d.current_status = 'ACTIVE' AND ({$myHasOpenInboundPredicate})";
  } elseif ($quick === "pending") {
    $where[] = "d.current_status = 'ACTIVE' AND ({$myHasActionableRolePredicate})";
  } elseif ($quick === "completed") {
    $where[] = "({$myCompletePredicate})";
  } elseif ($quick === "overdue") {
    $where[] = "d.current_status = 'ACTIVE' AND {$effectiveDeadlineFilterExpr} IS NOT NULL AND {$effectiveDeadlineFilterExpr} < NOW()";
  } elseif ($quick === "released") {
    $where[] = "d.current_status = 'RELEASED'";
  } elseif ($quick === "archived") {
    $where[] = "d.current_status = 'ARCHIVED'";
  } elseif ($quick === "active") {
    $where[] = "d.current_status = 'ACTIVE'";
  } elseif ($quick === "released_today") {
    $where[] = "d.current_status = 'RELEASED' AND DATE(d.updated_at) = CURDATE()";
  }
}

// -------------------------
// COUNT query for pagination
// -------------------------
$countSql = "
  SELECT COUNT(DISTINCT d.id) AS total
  FROM documents d
  LEFT JOIN sections sh ON sh.id = d.current_holder_section_id
";
if ($where) $countSql .= " WHERE " . implode(" AND ", $where);

$countStmt = $conn->prepare($countSql);
if ($params) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int)($countStmt->get_result()->fetch_assoc()["total"] ?? 0);

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) {
  $page = $totalPages;
  $offset = ($page - 1) * $perPage;
}

$projectTagSelectSql = $projectCodesReady
  ? "COALESCE((
      SELECT GROUP_CONCAT(DISTINCT p_tag.project_code ORDER BY p_tag.project_code SEPARATOR '||')
      FROM document_projects dp_tag
      JOIN projects p_tag ON p_tag.id = dp_tag.project_id
      WHERE dp_tag.document_id = d.id
    ), '') AS project_codes_concat,
    COALESCE((
      SELECT GROUP_CONCAT(DISTINCT CAST(p_tag.id AS CHAR) ORDER BY p_tag.project_code SEPARATOR ',')
      FROM document_projects dp_tag
      JOIN projects p_tag ON p_tag.id = dp_tag.project_id
      WHERE dp_tag.document_id = d.id
    ), '') AS project_ids_concat,"
  : "'' AS project_codes_concat,
    '' AS project_ids_concat,";

$sql = "
  SELECT
    d.id,
    d.tracking_no,
    COALESCE(ddt_ctx.tracking_no, '') AS division_tracking_no,
    d.requester,
    d.document_date,
    d.deadline_at,
    d.subject,
    d.content_type,
    d.comm_type,
    d.current_status,
    d.updated_at,
    CASE
      WHEN NOT EXISTS (
        SELECT 1
        FROM routes r_any
        WHERE r_any.document_id = d.id
      ) THEN 1
      ELSE 0
    END AS is_initial_routing,
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

    d.current_holder_section_id,
    sh.name AS current_holder_name,

    -- open route (in transit)
    r_open.from_section_id AS open_from_section_id,
    sf_open.name AS open_from_section_name,
    r_open.to_section_id AS open_to_section_id,
    st_open.name AS open_to_section_name,
    r_open.to_user_id AS open_to_user_id,
    u_open.full_name AS open_to_user_name,

    ro.any_open_route_id,
    COALESCE(ro.open_count, 0) AS open_route_count,
    CASE
      WHEN d.current_status = 'ACTIVE'
       AND ({$isPrivilegedInt} = 1 OR d.created_by_user_id = {$myUid})
       AND NOT EXISTS (
         SELECT 1
         FROM routes r_edit
         WHERE r_edit.document_id = d.id
       )
       AND NOT EXISTS (
         SELECT 1
         FROM document_branches b_edit
         WHERE b_edit.document_id = d.id
       )
      THEN 1
      ELSE 0
    END AS can_edit_details,
    CASE
      WHEN {$isPrivilegedInt} = 1
        OR d.created_by_user_id = {$myUid}
        OR d.current_holder_section_id = {$mySid}
        OR EXISTS (
          SELECT 1
          FROM routes r_slip
          WHERE r_slip.document_id = d.id
            AND r_slip.received_at IS NULL
            AND r_slip.cancelled_at IS NULL
            AND (
              r_slip.to_user_id = {$myUid}
              OR r_slip.to_section_id = {$mySid}
            )
        )
        OR EXISTS (
          SELECT 1
          FROM document_branches b_slip
          WHERE b_slip.document_id = d.id
            AND b_slip.branch_status = 'ACTIVE'
            AND (
              b_slip.current_assignee_user_id = {$myUid}
              OR b_slip.current_assignee_section_id = {$mySid}
            )
        )
      THEN 1
      ELSE 0
    END AS can_regenerate_division_slip,
    COALESCE((
      SELECT r_my_open.id
      FROM routes r_my_open
      WHERE r_my_open.document_id = d.id
        AND r_my_open.received_at IS NULL
        AND r_my_open.cancelled_at IS NULL
        AND (
          r_my_open.to_user_id = {$myUid}
          OR (
            r_my_open.to_user_id IS NULL
            AND {$myChiefInt} = 1
            AND r_my_open.to_section_id = {$mySid}
          )
        )
      ORDER BY r_my_open.id DESC
      LIMIT 1
    ), 0) AS my_open_route_id,

    {$personalDeadlineSelectSql},
    {$effectiveDeadlineOrderExpr} AS effective_deadline_at,

    rr_latest.latest_route_remark,
    rr_latest.latest_remark_sent_by_user_id,
    rr_latest.latest_remark_from_user_id,
    rr_latest.latest_remark_to_user_id,
    rr_latest.latest_remark_received_by_user_id,
    rr_latest.latest_remark_to_section_id,
    rr_latest.latest_remark_from_section_id,

    -- last holder (fallback when not in transit)
    sf_last.name AS last_holder_name,

    COALESCE(r_open.sent_at, r_last.received_at, d.created_at) AS stuck_since_at,
    TIMESTAMPDIFF(DAY, COALESCE((
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
    ), d.updated_at), NOW()) AS lifecycle_inactive_days,
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
    COALESCE((
      SELECT COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e_closed.payload_json, '$.kind')), ''), e_closed.event_type)
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
    ), '') AS lifecycle_closed_action,
    {$projectTagSelectSql}

    CASE
      WHEN EXISTS (
        SELECT 1
        FROM document_branches b_chk
        WHERE b_chk.document_id = d.id
      ) THEN 1
      ELSE 0
    END AS has_real_branches,

    CASE
      WHEN d.created_by_user_id = {$myUid} THEN 1
      ELSE 0
    END AS my_is_origin,

    CASE
      WHEN EXISTS (
        SELECT 1
        FROM routes r_in
        WHERE r_in.document_id = d.id
          AND r_in.received_at IS NULL
          AND r_in.cancelled_at IS NULL
          AND (
            (
              EXISTS (
                SELECT 1
                FROM document_branches b_chk
                WHERE b_chk.document_id = d.id
              )
              AND r_in.route_kind = 'ACTION'
              AND r_in.to_user_id = {$myUid}
              AND EXISTS (
                SELECT 1
                FROM document_branches b_in
                WHERE b_in.id = r_in.branch_id
                  AND b_in.current_assignee_user_id = r_in.to_user_id
              )
            )
            OR
            (
              NOT EXISTS (
                SELECT 1
                FROM document_branches b_chk2
                WHERE b_chk2.document_id = d.id
              )
              AND (
                r_in.to_user_id = {$myUid}
                OR ({$myChiefInt} = 1 AND r_in.to_user_id IS NULL AND r_in.to_section_id = {$mySid})
              )
            )
          )
      ) THEN 1
      ELSE 0
    END AS my_has_open_inbound,

    CASE
      WHEN EXISTS (
        SELECT 1
        FROM document_branches b_act
        WHERE b_act.document_id = d.id
      ) AND EXISTS (
        SELECT 1
        FROM document_branches b_act2
        WHERE b_act2.document_id = d.id
          AND b_act2.branch_status = 'ACTIVE'
          AND b_act2.current_assignee_user_id = {$myUid}
          AND b_act2.is_reference = 0
      ) THEN 1

      WHEN NOT EXISTS (
        SELECT 1
        FROM document_branches b_flat
        WHERE b_flat.document_id = d.id
      )
      AND d.current_status = 'ACTIVE'
      AND d.current_holder_section_id = {$mySid}
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
          d.created_by_user_id = {$myUid}
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
            r_last_received.to_user_id = {$myUid}
            OR (
              r_last_received.to_user_id IS NULL
              AND {$myChiefInt} = 1
              AND r_last_received.to_section_id = {$mySid}
            )
          )
        )
      ) THEN 1

      ELSE 0
    END AS my_has_actionable_role,

    CASE
      WHEN NOT EXISTS (
        SELECT 1
        FROM document_branches b_lifecycle
        WHERE b_lifecycle.document_id = d.id
      )
      AND d.current_status IN ('ACTIVE', 'RELEASED')
      AND d.current_holder_section_id = {$mySid}
      AND NOT EXISTS (
        SELECT 1
        FROM routes r_lifecycle_open
        WHERE r_lifecycle_open.document_id = d.id
          AND r_lifecycle_open.route_kind = 'ACTION'
          AND r_lifecycle_open.received_at IS NULL
          AND r_lifecycle_open.cancelled_at IS NULL
      )
      AND (
        (
          d.created_by_user_id = {$myUid}
          AND NOT EXISTS (
            SELECT 1
            FROM routes r_lifecycle_received_any
            WHERE r_lifecycle_received_any.document_id = d.id
              AND r_lifecycle_received_any.route_kind = 'ACTION'
              AND r_lifecycle_received_any.received_at IS NOT NULL
              AND r_lifecycle_received_any.cancelled_at IS NULL
          )
        )
        OR EXISTS (
          SELECT 1
          FROM routes r_lifecycle_last_received
          WHERE r_lifecycle_last_received.id = (
            SELECT r_lifecycle_last_pick.id
            FROM routes r_lifecycle_last_pick
            WHERE r_lifecycle_last_pick.document_id = d.id
              AND r_lifecycle_last_pick.route_kind = 'ACTION'
              AND r_lifecycle_last_pick.received_at IS NOT NULL
              AND r_lifecycle_last_pick.cancelled_at IS NULL
            ORDER BY r_lifecycle_last_pick.received_at DESC, r_lifecycle_last_pick.id DESC
            LIMIT 1
          )
          AND (
            r_lifecycle_last_received.to_user_id = {$myUid}
            OR (
              r_lifecycle_last_received.to_user_id IS NULL
              AND {$myChiefInt} = 1
              AND r_lifecycle_last_received.to_section_id = {$mySid}
            )
          )
        )
      ) THEN 1
      ELSE 0
    END AS my_can_change_lifecycle,

    CASE
      WHEN d.created_by_user_id = {$myUid} THEN 1
      WHEN EXISTS (
        SELECT 1
        FROM routes r_part
        WHERE r_part.document_id = d.id
          AND (
            r_part.to_user_id = {$myUid}
            OR r_part.sent_by_user_id = {$myUid}
            OR r_part.received_by_user_id = {$myUid}
          )
      ) THEN 1
      WHEN EXISTS (
        SELECT 1
        FROM document_events e_part
        WHERE e_part.document_id = d.id
          AND e_part.event_type IN ('sent', 'forwarded')
          AND e_part.payload_json REGEXP '\"acting_principal_user_id\"[[:space:]]*:[[:space:]]*{$myUid}([^0-9]|$)'
      ) THEN 1
      ELSE 0
    END AS my_has_participation,

    CASE
      WHEN {$branchModeInt} = 1 AND EXISTS (
        SELECT 1
        FROM document_user_visibility duv_vis
        WHERE duv_vis.document_id = d.id
          AND duv_vis.user_id = {$myUid}
      ) THEN 1
      ELSE 0
    END AS my_is_visible_only,

    CASE
      WHEN EXISTS (
        SELECT 1
        FROM document_branches b_any_ref
        WHERE b_any_ref.document_id = d.id
      ) AND (
        EXISTS (
          SELECT 1
          FROM document_branches b_ref
          WHERE b_ref.document_id = d.id
            AND b_ref.is_reference = 1
            AND (
              b_ref.current_assignee_user_id = {$myUid}
              OR EXISTS (
                SELECT 1
                FROM routes r_ref
                WHERE r_ref.document_id = d.id
                  AND r_ref.branch_id = b_ref.id
                  AND (
                    r_ref.to_user_id = {$myUid}
                    OR r_ref.sent_by_user_id = {$myUid}
                    OR r_ref.received_by_user_id = {$myUid}
                  )
              )
            )
        )
        OR EXISTS (
          SELECT 1
          FROM routes r_ref_flat
          WHERE r_ref_flat.document_id = d.id
            AND r_ref_flat.route_kind = 'REFERENCE'
            AND (
              r_ref_flat.to_user_id = {$myUid}
              OR r_ref_flat.sent_by_user_id = {$myUid}
              OR r_ref_flat.received_by_user_id = {$myUid}
            )
        )
      ) THEN 1
      ELSE 0
    END AS my_is_for_reference,

    CASE
      WHEN EXISTS (
        SELECT 1
        FROM document_branches b_any_ro
        WHERE b_any_ro.document_id = d.id
      ) AND EXISTS (
        SELECT 1
        FROM document_branches b_ro
        WHERE b_ro.document_id = d.id
          AND b_ro.branch_status = 'ACTIVE'
          AND b_ro.current_assignee_user_id = {$myUid}
          AND b_ro.is_reference = 1
      ) THEN 1
      ELSE 0
    END AS my_is_receive_only
  FROM documents d
  LEFT JOIN sections sh ON sh.id = d.current_holder_section_id
  LEFT JOIN document_division_tracking ddt_ctx
    ON ddt_ctx.document_id = d.id
   AND ddt_ctx.division_id = {$myDivisionId}

  LEFT JOIN (
    SELECT
      r.document_id,
      MIN(r.id) AS any_open_route_id,
      COUNT(*) AS open_count
    FROM routes r
    LEFT JOIN document_branches b_open ON b_open.id = r.branch_id
    WHERE r.received_at IS NULL
      AND r.cancelled_at IS NULL
      AND r.route_kind = 'ACTION'
      AND (
        r.branch_id IS NULL
        OR b_open.current_assignee_user_id = r.to_user_id
      )
    GROUP BY r.document_id
  ) ro ON ro.document_id = d.id

  LEFT JOIN routes r_open
    ON r_open.id = ro.any_open_route_id
  LEFT JOIN sections sf_open ON sf_open.id = r_open.from_section_id
  LEFT JOIN sections st_open ON st_open.id = r_open.to_section_id
  LEFT JOIN users u_open ON u_open.id = r_open.to_user_id

  {$personalDeadlineJoinSql}

  LEFT JOIN (
    SELECT
      r1.id,
      r1.document_id,
      r1.remarks AS latest_route_remark,
      r1.sent_by_user_id AS latest_remark_sent_by_user_id,
      r1.from_user_id AS latest_remark_from_user_id,
      r1.to_user_id AS latest_remark_to_user_id,
      r1.received_by_user_id AS latest_remark_received_by_user_id,
      r1.to_section_id AS latest_remark_to_section_id,
      r1.from_section_id AS latest_remark_from_section_id
    FROM routes r1
  ) rr_latest
    ON rr_latest.document_id = d.id
  AND rr_latest.id = (
      SELECT MAX(r2.id)
      FROM routes r2
      WHERE r2.document_id = d.id
        AND TRIM(COALESCE(r2.remarks, '')) <> ''
        AND LOWER(TRIM(COALESCE(r2.remarks, ''))) <> 'none'
        AND (
          r2.sent_by_user_id = {$myUid}
          OR r2.from_user_id = {$myUid}
          OR r2.to_user_id = {$myUid}
          OR r2.received_by_user_id = {$myUid}
          OR r2.to_section_id = {$mySid}
          OR r2.from_section_id = {$mySid}
        )
    )

  LEFT JOIN routes r_last
    ON r_last.id = (
      SELECT r2.id
      FROM routes r2
      WHERE r2.document_id = d.id
        AND r2.received_at IS NOT NULL
      ORDER BY r2.received_at DESC, r2.id DESC
      LIMIT 1
   )
  LEFT JOIN sections sf_last ON sf_last.id = r_last.from_section_id
";

if ($where) $sql .= " WHERE " . implode(" AND ", $where);

$orderBySql = "
  ORDER BY
    CASE
      WHEN d.current_status = 'ACTIVE'
       AND ({$myHasActionableRolePredicate})
       AND effective_deadline_at IS NOT NULL
       AND effective_deadline_at < NOW()
      THEN 0

      WHEN d.current_status = 'ACTIVE'
       AND ({$myHasOpenInboundPredicate})
       AND effective_deadline_at IS NOT NULL
       AND effective_deadline_at < NOW()
      THEN 1

      WHEN d.current_status = 'ACTIVE'
       AND ({$myHasActionableRolePredicate})
      THEN 2

      WHEN d.current_status = 'ACTIVE'
       AND ({$myHasOpenInboundPredicate})
      THEN 3

      WHEN d.current_status = 'ACTIVE'
       AND effective_deadline_at IS NOT NULL
       AND effective_deadline_at < NOW()
      THEN 4

      WHEN d.current_status = 'ACTIVE'
       AND ({$myCompletePredicate})
      THEN 5

      WHEN d.current_status = 'ACTIVE' THEN 6
      WHEN d.current_status = 'RELEASED' THEN 7
      WHEN d.current_status = 'ARCHIVED' THEN 8
      ELSE 9
    END ASC,

    CASE WHEN effective_deadline_at IS NULL THEN 1 ELSE 0 END ASC,
    effective_deadline_at ASC,
    d.updated_at DESC,
    d.document_date DESC,
    d.id DESC
";

if ($sort === "newest") {
  $orderBySql = "
    ORDER BY d.document_date DESC, d.id DESC
  ";
} elseif ($sort === "oldest") {
  $orderBySql = "
    ORDER BY d.document_date ASC, d.id ASC
  ";
} elseif ($sort === "urgent") {
  $orderBySql = "
    ORDER BY
      CASE WHEN effective_deadline_at IS NULL THEN 1 ELSE 0 END ASC,
      effective_deadline_at ASC,
      CASE WHEN d.current_status = 'ACTIVE' AND ({$myHasActionableRolePredicate}) THEN 0
           WHEN d.current_status = 'ACTIVE' AND ({$myHasOpenInboundPredicate}) THEN 1
           ELSE 2 END ASC,
      d.updated_at DESC,
      d.id DESC
  ";
} elseif ($sort === "overdue_longest") {
  $orderBySql = "
    ORDER BY
      CASE WHEN effective_deadline_at IS NOT NULL AND effective_deadline_at < NOW() THEN 0 ELSE 1 END ASC,
      effective_deadline_at ASC,
      CASE WHEN d.current_status = 'ACTIVE' AND ({$myHasActionableRolePredicate}) THEN 0
           WHEN d.current_status = 'ACTIVE' AND ({$myHasOpenInboundPredicate}) THEN 1
           ELSE 2 END ASC,
      d.updated_at DESC,
      d.id DESC
  ";
}

$sql .= $orderBySql . "
  LIMIT ? OFFSET ?
";

$params2 = $params;
$types2  = $types . "ii";
$params2[] = $perPage;
$params2[] = $offset;

$stmt = $conn->prepare($sql);
if ($params2) $stmt->bind_param($types2, ...$params2);
$stmt->execute();
$docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($docs as &$docRow) {
  $stuckSince = (string)($docRow['stuck_since_at'] ?? $docRow['updated_at'] ?? '');
  $workingMinutesStuck = strtoupper((string)($docRow['current_status'] ?? 'ACTIVE')) === 'ACTIVE'
    ? dt_working_minutes_between($stuckSince, null, $conn)
    : 0;

  $docRow['working_minutes_stuck'] = $workingMinutesStuck;
  $docRow['working_hours_stuck'] = intdiv($workingMinutesStuck, 60);
  $docRow['days_stuck'] = dt_working_days_from_minutes($workingMinutesStuck, $conn);
}
unset($docRow);

$docIdsOnPage = array_values(array_unique(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $docs), static fn($id) => $id > 0)));
if ($docIdsOnPage !== []) {
  $remarkEventSql = "
    SELECT id, document_id, actor_user_id, actor_section_id, from_section_id, to_section_id, payload_json
    FROM document_events
    WHERE document_id IN (" . implode(',', array_fill(0, count($docIdsOnPage), '?')) . ")
    ORDER BY document_id ASC, id DESC
  ";
  $remarkStmt = $conn->prepare($remarkEventSql);
  $remarkTypes = str_repeat('i', count($docIdsOnPage));
  $remarkStmt->bind_param($remarkTypes, ...$docIdsOnPage);
  $remarkStmt->execute();
  $remarkRows = $remarkStmt->get_result()->fetch_all(MYSQLI_ASSOC);

  $latestRemarksByDocId = [];
  foreach ($remarkRows as $remarkRow) {
    $remarkDocId = (int)($remarkRow['document_id'] ?? 0);
    if ($remarkDocId <= 0 || isset($latestRemarksByDocId[$remarkDocId])) {
      continue;
    }

    $payload = [];
    if (!empty($remarkRow['payload_json'])) {
      $decoded = json_decode((string)$remarkRow['payload_json'], true);
      if (is_array($decoded)) {
        $payload = $decoded;
      }
    }

    $remarkText = trim((string)($payload['remarks'] ?? ''));
    if ($remarkText === '' || in_array(strtolower($remarkText), ['none', '-', '—', 'n/a', 'na', 'null', 'undefined'], true)) {
      continue;
    }

    $canSeeRemark = $isPrivileged
      || in_array((string)($payload['kind'] ?? ''), ['holder_progress_note_added', 'holder_progress_note_updated', 'holder_progress_note_cleared'], true)
      || (int)($remarkRow['actor_user_id'] ?? 0) === $myUserId
      || (int)($remarkRow['actor_section_id'] ?? 0) === $mySectionId
      || (int)($remarkRow['from_section_id'] ?? 0) === $mySectionId
      || (int)($remarkRow['to_section_id'] ?? 0) === $mySectionId
      || (int)($payload['to_user_id'] ?? 0) === $myUserId
      || in_array($myUserId, array_map('intval', (array)($payload['to_user_ids'] ?? [])), true)
      || (int)($payload['acting_principal_user_id'] ?? 0) === $myUserId;

    if (!$canSeeRemark) {
      continue;
    }

    $latestRemarksByDocId[$remarkDocId] = $remarkText;
  }

  if ($latestRemarksByDocId !== []) {
    foreach ($docs as &$docRow) {
      $docRowId = (int)($docRow['id'] ?? 0);
      if ($docRowId > 0 && isset($latestRemarksByDocId[$docRowId])) {
        $docRow['latest_route_remark'] = $latestRemarksByDocId[$docRowId];
        $docRow['latest_remark_visible_to_me'] = 1;
      }
    }
    unset($docRow);
  }
}

/**
 * Stats (NEW SCHEMA)
 */
$statWhere  = [];
$statParams = [];
$statTypes  = "";

if (!$isPrivileged) {
  if ($myUserId <= 0) {
    $statWhere[] = "1=0";
  } else {
    if ($branchMode) {
      $statWhere[] = "(
        d.created_by_user_id = ?
        OR EXISTS (
          SELECT 1
          FROM document_user_visibility duv
          WHERE duv.document_id = d.id
            AND duv.user_id = ?
        )
        OR EXISTS (
          SELECT 1
          FROM routes r
          WHERE r.document_id = d.id
            AND (
              r.to_user_id = ?
              OR r.sent_by_user_id = ?
              OR r.received_by_user_id = ?
            )
        )
      )";

      array_push(
        $statParams,
        $myUserId,
        $myUserId,
        $myUserId,
        $myUserId,
        $myUserId
      );

      $statTypes .= "iiiii";
    } else {
      $statWhere[] = "(
        d.created_by_user_id = ?

        OR EXISTS (
          SELECT 1
          FROM routes r
          WHERE r.document_id = d.id
            AND (
              r.to_user_id = ?
              OR r.sent_by_user_id = ?
              OR r.received_by_user_id = ?
            )
        )

        OR (
          ? = 1
          AND EXISTS (
            SELECT 1
            FROM routes r
            WHERE r.document_id = d.id
              AND r.received_at IS NULL
              AND r.cancelled_at IS NULL
              AND r.to_section_id = ?
              AND r.to_user_id IS NULL
          )
        )

        OR (
          ? = 1
          AND d.current_holder_section_id = ?
          AND NOT EXISTS (
            SELECT 1
            FROM routes r
            WHERE r.document_id = d.id
              AND r.received_at IS NULL
              AND r.cancelled_at IS NULL
          )
        )
      )";

      array_push(
        $statParams,
        $myUserId,
        $myUserId,
        $myUserId,
        $myUserId,
        $isChief ? 1 : 0,
        $mySectionId,
        $isChief ? 1 : 0,
        $mySectionId
      );

      $statTypes .= "iiiiiiii";
    }
  }
}
if ($assistantOwnPageIsolationSql !== "") {
  $statWhere[] = $assistantOwnPageIsolationSql;
}

$statSql = "
  SELECT
    SUM(d.current_status = 'ACTIVE' AND ({$myHasOpenInboundPredicate})) AS incoming,
    SUM(d.current_status = 'ACTIVE' AND ({$myHasActionableRolePredicate})) AS pending,
    SUM({$myCompletePredicate}) AS completed,
    SUM(d.current_status = 'ACTIVE' AND {$effectiveDeadlineFilterExpr} IS NOT NULL AND {$effectiveDeadlineFilterExpr} < NOW()) AS overdue,
    SUM(d.current_status = 'RELEASED') AS released,
    SUM(d.current_status = 'ARCHIVED') AS archived,
    SUM(d.current_status = 'ACTIVE') AS active
  FROM documents d
";
if ($statWhere) $statSql .= " WHERE " . implode(" AND ", $statWhere);

$statStmt = $conn->prepare($statSql);
if ($statParams) $statStmt->bind_param($statTypes, ...$statParams);
$statStmt->execute();
$statRows = $statStmt->get_result()->fetch_assoc();

$stats = [
  "incoming" => (int)($statRows["incoming"] ?? 0),
  "pending" => (int)($statRows["pending"] ?? 0),
  "completed" => (int)($statRows["completed"] ?? 0),
  "overdue" => (int)($statRows["overdue"] ?? 0),
  "released" => (int)($statRows["released"] ?? 0),
  "archived" => (int)($statRows["archived"] ?? 0),
  "active" => (int)($statRows["active"] ?? 0),
];

$myAvgMins = 0;
$myAvgText = "";
if ($myUserId > 0 && db_table_exists($conn, 'document_events')) {
  $actionDocsByUser = [];
  $routeActionSql = "SELECT document_id FROM routes WHERE route_kind = 'ACTION' AND (to_user_id = {$myUserId} OR received_by_user_id = {$myUserId})";
  $branchActionSql = $branchMode ? " UNION SELECT document_id FROM document_branches WHERE is_reference = 0 AND current_assignee_user_id = {$myUserId}" : "";
  $creatorActionSql = " UNION SELECT id AS document_id FROM documents WHERE created_by_user_id = {$myUserId}";
  
  $resAction = $conn->query($routeActionSql . $branchActionSql . $creatorActionSql);
  if ($resAction) {
    while($r = $resAction->fetch_assoc()) {
      $actionDocsByUser[(int)$r['document_id']] = true;
    }
    $resAction->free();
  }

  $resEvents = $conn->query("
    SELECT
      document_id,
      created_at,
      event_type,
      COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.kind')), '') AS payload_kind,
      CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.elapsed_working_minutes')) AS SIGNED) AS elapsed_mins,
      COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.route_kind')), '') AS route_kind
    FROM document_events
    WHERE COALESCE(NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.acting_principal_user_id')) AS UNSIGNED), 0), actor_user_id) = {$myUserId}
    ORDER BY document_id ASC, created_at ASC, id ASC
  ");

  if ($resEvents) {
    $stints = [];
    $sumMins = 0;
    $docCount = 0;
    
    while ($row = $resEvents->fetch_assoc()) {
      $did = (int)$row['document_id'];
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

      if ($action === 'received' || $action === 'created') {
          if (isset($stints[$did])) {
              if ($stints[$did]['max_mins'] > 0 && !$stints[$did]['is_reference'] && isset($actionDocsByUser[$did])) {
                  $sumMins += $stints[$did]['max_mins'];
                  $docCount++;
              }
          }
          $stints[$did] = [
              'start_at' => $createdAt,
              'max_mins' => 0,
              'is_open' => true,
              'is_reference' => ($routeKind === 'REFERENCE')
          ];
      } else {
          if (!isset($stints[$did])) {
              $stints[$did] = [
                  'start_at' => $createdAt,
                  'max_mins' => 0,
                  'is_open' => true,
                  'is_reference' => false
              ];
          }
          if ($elapsedMins > 0) {
              $stints[$did]['max_mins'] = max($stints[$did]['max_mins'], $elapsedMins);
          } elseif (in_array($action, ['sent', 'forwarded', 'released', 'branch_ended_here', 'document_ended_here', 'attachment_forwarded'], true)) {
              $startAt = $stints[$did]['start_at'];
              $calcMins = dt_working_minutes_between($startAt, $createdAt, $conn);
              if ($calcMins <= 0) $calcMins = 1;
              $stints[$did]['max_mins'] = max($stints[$did]['max_mins'], $calcMins);
              $stints[$did]['is_open'] = false;
          }
      }
    }
    $resEvents->free();

    foreach ($stints as $did => $stint) {
        if (!$stint['is_reference'] && isset($actionDocsByUser[$did]) && $stint['max_mins'] > 0) {
            $sumMins += $stint['max_mins'];
            $docCount++;
        }
    }

    if ($docCount > 0) {
        $myAvgMins = (int)round($sumMins / $docCount);
        $myAvgText = $myAvgMins . ' mins';
        if ($myAvgMins >= 60) {
            $h = intdiv($myAvgMins, 60);
            $m = $myAvgMins % 60;
            $myAvgText = $h . ' hr' . ($h > 1 ? 's' : '');
            if ($m > 0) $myAvgText .= ' ' . $m . ' min' . ($m > 1 ? 's' : '');
        }
    }
  }
}

// Helper for pagination URLs (preserve current filters)
function pageUrl(int $p): string {
  $q = $_GET;
  $q["page"] = $p;
  return PUBLIC_PATH . "/documents.php?" . http_build_query($q);
}

function quickUrl(string $target): string {
  $q = $_GET;
  $currentQuick = strtolower(trim($q["quick"] ?? ""));
  if ($target === "") unset($q["quick"]);
  elseif ($currentQuick === $target) unset($q["quick"]);
  else $q["quick"] = $target;
  $q["page"] = 1;
  return PUBLIC_PATH . "/documents.php?" . http_build_query($q);
}

function documentsUrl(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $key => $value) {
    if ($value === null || $value === '') unset($q[$key]);
    else $q[$key] = $value;
  }
  return PUBLIC_PATH . '/documents.php?' . http_build_query($q);
}

$workingCalendar = dt_work_calendar($conn);
$calendarDayLabels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$calendarWorkdays = array_values(array_filter(array_map('intval', (array)($workingCalendar['workdays'] ?? [1, 2, 3, 4, 5])), static fn($day) => $day >= 1 && $day <= 7));
$calendarWorkdayText = implode(', ', array_map(static fn($day) => $calendarDayLabels[$day] ?? (string)$day, $calendarWorkdays));
$calendarStartText = substr((string)($workingCalendar['default_start_time'] ?? '08:00:00'), 0, 5);
$calendarEndText = substr((string)($workingCalendar['default_end_time'] ?? '17:00:00'), 0, 5);
$calendarTimezoneText = (string)($workingCalendar['timezone'] ?? 'Asia/Manila');

$calendarToday = new DateTimeImmutable('today', dt_work_timezone($workingCalendar));
$calendarTodayKey = $calendarToday->format('Y-m-d');
$calendarTodayWindow = dt_day_window($calendarToday, $workingCalendar);
$calendarTodayException = (array)($workingCalendar['exceptions'][$calendarTodayKey] ?? []);
$calendarTodayTitle = trim((string)($calendarTodayException['title'] ?? ''));
$calendarTodayIsWorking = $calendarTodayWindow !== null;
if ($calendarTodayWindow !== null) {
  [$calendarTodayStart, $calendarTodayEnd] = $calendarTodayWindow;
  $calendarTodayTimeText = $calendarTodayStart->format('H:i') . '-' . $calendarTodayEnd->format('H:i');
} else {
  $calendarTodayTimeText = 'No work';
}
$calendarTodayDetailText = $calendarTodayTitle !== '' ? $calendarTodayTitle : ($calendarTodayIsWorking ? 'Regular working day' : 'Non-working day');

$oldestDocDateRaw = '';
try {
  $oldestDocRow = $conn->query("
    SELECT MIN(DATE(created_at)) AS oldest_doc_date
    FROM documents
  ")->fetch_assoc();
  $oldestDocDateRaw = trim((string)($oldestDocRow['oldest_doc_date'] ?? ''));
} catch (Throwable) {
  $oldestDocDateRaw = '';
}
$oldestDocDate = $oldestDocDateRaw !== ''
  ? DateTimeImmutable::createFromFormat('!Y-m-d', $oldestDocDateRaw, dt_work_timezone($workingCalendar))
  : false;
if (!$oldestDocDate) {
  $oldestDocDate = $calendarToday;
}
$calendarWeekOneStart = $oldestDocDate->modify('monday this week')->setTime(0, 0, 0);
$calendarCurrentWeekStart = $calendarToday->modify('monday this week')->setTime(0, 0, 0);
$calendarLastRelevantDate = $calendarToday;
foreach (array_keys((array)($workingCalendar['exceptions'] ?? [])) as $exceptionDate) {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$exceptionDate)) {
    continue;
  }
  $exceptionDt = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$exceptionDate, dt_work_timezone($workingCalendar));
  if ($exceptionDt && $exceptionDt > $calendarLastRelevantDate) {
    $calendarLastRelevantDate = $exceptionDt;
  }
}
$calendarLastWeekStart = $calendarLastRelevantDate->modify('monday this week')->setTime(0, 0, 0);
if ($calendarLastWeekStart < $calendarCurrentWeekStart) {
  $calendarLastWeekStart = $calendarCurrentWeekStart;
}

$calendarWeeks = [];
for ($weekStart = $calendarWeekOneStart; $weekStart <= $calendarLastWeekStart; $weekStart = $weekStart->modify('+7 days')) {
  $weekNo = count($calendarWeeks) + 1;
  $weekEnd = $weekStart->modify('+6 days');
  $days = [];

  for ($day = 0; $day < 7; $day++) {
    $date = $weekStart->modify("+{$day} days");
    $key = $date->format('Y-m-d');
    $window = dt_day_window($date, $workingCalendar);
    $exception = (array)($workingCalendar['exceptions'][$key] ?? []);
    $exceptionTitle = trim((string)($exception['title'] ?? ''));
    $exceptionType = trim((string)($exception['type'] ?? ''));

    if ($window !== null) {
      [$dayStart, $dayEnd] = $window;
      $hours = $dayStart->format('H:i') . '-' . $dayEnd->format('H:i');
      $status = $exceptionTitle !== ''
        ? $exceptionTitle
        : ($exceptionType === 'special_working' ? 'Special working day' : ($exceptionType === 'custom_hours' ? 'Custom hours' : 'Regular'));
      $isWorking = true;
    } else {
      $hours = 'No work';
      $status = $exceptionTitle !== ''
        ? $exceptionTitle
        : match ($exceptionType) {
          'special_holiday' => 'Special holiday',
          'regular_holiday' => 'Regular holiday',
          'other_non_working' => 'Other non-working day',
          default => 'Non-working day',
        };
      $isWorking = false;
    }

    $days[] = [
      'date' => $date->format('M d'),
      'day' => $calendarDayLabels[(int)$date->format('N')] ?? $date->format('D'),
      'hours' => $hours,
      'status' => $status,
      'is_working' => $isWorking,
      'is_today' => $key === $calendarTodayKey,
    ];
  }

  $calendarWeeks[] = [
    'week_no' => $weekNo,
    'label' => 'Week ' . $weekNo,
    'range' => $weekStart->format('M d') . '-' . $weekEnd->format('M d, Y'),
    'days' => $days,
  ];
}
$calendarInitialWeekIndex = max(0, min(count($calendarWeeks) - 1, (int)floor(($calendarCurrentWeekStart->getTimestamp() - $calendarWeekOneStart->getTimestamp()) / 604800)));
?>

<?php $hasActiveFilters = ($search !== "" || $statusGet !== "" || $date_from !== "" || $date_to !== "" || $quick !== "" || ($sort !== "" && $sort !== "workflow")); ?>
<style>
.docsViewTabs{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 14px}.docsViewTab{padding:10px 14px;border-radius:12px;border:1px solid rgba(15,23,42,.12);background:#fff;color:#0f172a;text-decoration:none;font-weight:700}.docsViewTab.isActive{background:#0f172a;color:#fff}.docsAssistantBar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:0 0 16px;padding:14px;border:1px solid rgba(15,23,42,.08);border-radius:16px;background:#fff}.docsAssistantIdentity{display:flex;align-items:center;gap:10px;min-width:min(100%,360px)}.docsAssistantField{display:block;min-width:0}.docsAssistantBar label,.docsAssistantFieldLabel{display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px}.docsAssistantBar select{width:min(100%,260px);padding:10px 12px;border-radius:12px;border:1px solid rgba(15,23,42,.12);background:#fff}.docsAssistantHint{font-size:12px;color:#64748b;min-width:220px;flex:1}@media(max-width:640px){.docsAssistantBar{align-items:stretch}.docsAssistantIdentity{width:100%}.docsAssistantField{flex:1}.docsAssistantBar select{width:100%;min-width:0}.docsAssistantHint{min-width:100%}}
</style>
<div class="docsPageShell">
  <div class="docsViewTabs" aria-label="Documents view tabs">
    <a class="docsViewTab <?= !$assistantModeEnabled ? 'isActive' : '' ?>" href="<?= htmlspecialchars(documentsUrl(['view' => 'my', 'acting_principal_user_id' => null, 'page' => 1])) ?>">My documents</a>
    <?php if ($assistantPrincipals !== []): ?>
      <a class="docsViewTab <?= $assistantModeEnabled ? 'isActive' : '' ?>" href="<?= htmlspecialchars(documentsUrl(['view' => 'assistant', 'acting_principal_user_id' => (int)($activeAssistantPrincipal['id'] ?? $assistantPrincipals[0]['id'] ?? 0), 'page' => 1])) ?>">Assistant mode</a>
    <?php endif; ?>
  </div>
  <?php if ($assistantModeEnabled): ?>
    <form class="docsAssistantBar" method="GET" action="<?= PUBLIC_PATH ?>/documents.php">
      <input type="hidden" name="view" value="assistant">
      <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
      <input type="hidden" name="status" value="<?= htmlspecialchars($statusGet) ?>">
      <input type="hidden" name="from" value="<?= htmlspecialchars($date_from) ?>">
      <input type="hidden" name="to" value="<?= htmlspecialchars($date_to) ?>">
      <input type="hidden" name="quick" value="<?= htmlspecialchars($quick) ?>">
      <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
      <?php
        $activePrincipalName = (string)($activeAssistantPrincipal['full_name'] ?? 'Selected chief');
        $activePrincipalPhotoUrl = (string)($activeAssistantPrincipal['profile_photo_url'] ?? '');
        $activePrincipalInitials = function_exists('app_user_initials') ? app_user_initials($activePrincipalName) : strtoupper(substr($activePrincipalName, 0, 1));
      ?>
      <div class="docsAssistantIdentity">
        <span class="appAvatar appAvatarMd" aria-hidden="true">
          <?php if ($activePrincipalPhotoUrl !== ''): ?>
            <img src="<?= htmlspecialchars($activePrincipalPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
          <?php else: ?>
            <span><?= htmlspecialchars($activePrincipalInitials) ?></span>
          <?php endif; ?>
        </span>
        <label class="docsAssistantField">
          <span class="docsAssistantFieldLabel">Acting for</span>
          <select name="acting_principal_user_id" onchange="this.form.submit()"><?php foreach ($assistantPrincipals as $principal): ?><option value="<?= (int)$principal['id'] ?>" <?= (int)$principal['id'] === (int)($activeAssistantPrincipal['id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars((string)$principal['full_name']) ?></option><?php endforeach; ?></select>
        </label>
      </div>
      <div class="docsAssistantHint">Separate assistant queue for <?= htmlspecialchars($activePrincipalName) ?>. Actions remain under your account, but authority checks use this chief context.</div>
    </form>
  <?php endif; ?>
  <nav class="docsMobileTabs" aria-label="Documents sections">
    <a href="#docsOverview" class="docsMobileTab isActive" data-scroll-tab>Overview</a>
    <a href="#docsFilters" class="docsMobileTab" data-scroll-tab>Find</a>
    <a href="#docsList" class="docsMobileTab" data-scroll-tab>List</a>
  </nav>

  <section class="docsHero" id="docsOverview">
    <div class="docsHeroCopy">
      <div class="docsEyebrow"><?= $assistantModeEnabled ? "Assistant queue" : "My work queue" ?></div>
      <h1 class="docsTitle"><?= $assistantModeEnabled ? "Assistant Mode Documents" : "Document List" ?></h1>
    </div>

    <div class="docsHeroActions">
      <?php if ($myAvgText !== ""): ?>
      <div class="docsSummaryPill">
        <span class="docsSummaryValue" style="font-size:16px; margin-bottom:2px;"><?= htmlspecialchars($myAvgText) ?></span>
        <span class="docsSummaryLabel">my avg processing time</span>
      </div>
      <?php endif; ?>

      <div class="docsSummaryPill">
        <span class="docsSummaryValue"><?= (int)$total ?></span>
        <span class="docsSummaryLabel">documents in this view</span>
      </div>

      <div class="docsCalendarPeek" tabindex="0" aria-label="Working calendar summary">
        <div class="docsCalendarPeekMain">
          <span class="docsCalendarDot <?= $calendarTodayIsWorking ? '' : 'isOff' ?>" aria-hidden="true"></span>
          <span class="docsCalendarLabel">Today</span>
          <span class="docsCalendarValue"><?= htmlspecialchars($calendarTodayTimeText) ?></span>
        </div>
        <div class="docsCalendarPanel" role="tooltip">
          <div class="docsCalendarPanelTitle">Working calendar</div>
          <div class="docsCalendarLine">
            <span>Today</span>
            <strong><?= htmlspecialchars($calendarTodayTimeText) ?></strong>
          </div>
          <div class="docsCalendarLine">
            <span>Status</span>
            <strong><?= htmlspecialchars($calendarTodayDetailText) ?></strong>
          </div>
          <div class="docsCalendarLine">
            <span>Timezone</span>
            <strong><?= htmlspecialchars($calendarTimezoneText) ?></strong>
          </div>
          <div class="docsCalendarLine">
            <span>Working days</span>
            <strong><?= htmlspecialchars($calendarWorkdayText !== '' ? $calendarWorkdayText : 'Not set') ?></strong>
          </div>
          <div class="docsCalendarLine">
            <span>Default hours</span>
            <strong><?= htmlspecialchars($calendarStartText . '-' . $calendarEndText) ?></strong>
          </div>
          <div class="docsCalendarExceptions">
            <div class="docsCalendarWeekHead">
              <button type="button" class="docsCalendarWeekBtn" data-calendar-prev aria-label="Previous week">Prev</button>
              <div>
                <div class="docsCalendarPanelTitle small" data-calendar-week-label>Week</div>
                <div class="docsCalendarWeekRange" data-calendar-week-range></div>
              </div>
              <button type="button" class="docsCalendarWeekBtn" data-calendar-next aria-label="Next week">Next</button>
            </div>
            <div class="docsCalendarWeekDays" data-calendar-week-days></div>
          </div>
        </div>
      </div>

      <a href="<?= htmlspecialchars(PUBLIC_PATH . '/add_document.php' . ($assistantModeEnabled && (int)($activeAssistantPrincipal['id'] ?? 0) > 0 ? '?acting_principal_user_id=' . (int)($activeAssistantPrincipal['id'] ?? 0) : '')) ?>" class="btnComp docsAddBtn" style="text-decoration:none;">
        + Add Document
      </a>
    </div>
  </section>

  <script>
    (() => {
      const root = document.currentScript?.previousElementSibling?.querySelector?.(".docsCalendarPeek") || document.querySelector(".docsCalendarPeek");
      if (!root) return;

      const weeks = <?= json_encode($calendarWeeks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      let index = <?= (int)$calendarInitialWeekIndex ?>;
      const label = root.querySelector("[data-calendar-week-label]");
      const range = root.querySelector("[data-calendar-week-range]");
      const daysWrap = root.querySelector("[data-calendar-week-days]");
      const prev = root.querySelector("[data-calendar-prev]");
      const next = root.querySelector("[data-calendar-next]");

      const escCalendar = (value) => (value ?? "").toString()
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");

      function renderCalendarWeek() {
        const week = weeks[index] || weeks[0];
        if (!week) return;

        if (label) label.textContent = week.label || `Week ${index + 1}`;
        if (range) range.textContent = week.range || "";
        if (prev) prev.disabled = index <= 0;
        if (next) next.disabled = index >= weeks.length - 1;

        if (daysWrap) {
          daysWrap.innerHTML = (week.days || []).map((day) => `
            <div class="docsCalendarDay ${day.is_working ? "isWorking" : "isOff"} ${day.is_today ? "isToday" : ""}">
              <span>${escCalendar(day.day)}<small>${escCalendar(day.date)}</small></span>
              <strong>${escCalendar(day.hours)}</strong>
              <em>${escCalendar(day.status)}</em>
            </div>
          `).join("");
        }
      }

      prev?.addEventListener("click", (event) => {
        event.preventDefault();
        if (index > 0) {
          index -= 1;
          renderCalendarWeek();
        }
      });

      next?.addEventListener("click", (event) => {
        event.preventDefault();
        if (index < weeks.length - 1) {
          index += 1;
          renderCalendarWeek();
        }
      });

      renderCalendarWeek();
    })();
  </script>

  <div class="stats docsStatsGrid" id="docsStats">
    <a class="statCard statCardLink docsStatCard toneIncoming <?= $quick === 'incoming' ? 'isActive' : '' ?>"
       href="<?= htmlspecialchars(quickUrl('incoming')) ?>">
      <div class="docsStatHeader">
        <div>
          <div class="statTitle">Incoming</div>
          <div class="docsStatHint">Waiting for your receive</div>
        </div>
        <div class="chip action">Receive</div>
      </div>
      <div class="statValue"><?= $stats["incoming"] ?></div>
    </a>

    <a class="statCard statCardLink docsStatCard tonePending <?= $quick === 'pending' ? 'isActive' : '' ?>"
       href="<?= htmlspecialchars(quickUrl('pending')) ?>">
      <div class="docsStatHeader">
        <div>
          <div class="statTitle">Pending</div>
          <div class="docsStatHint">Already with you for action</div>
        </div>
        <div class="chip overdue">Act now</div>
      </div>
      <div class="statValue"><?= $stats["pending"] ?></div>
    </a>

    <a class="statCard statCardLink docsStatCard toneComplete <?= $quick === 'completed' ? 'isActive' : '' ?>"
       href="<?= htmlspecialchars(quickUrl('completed')) ?>">
      <div class="docsStatHeader">
        <div>
          <div class="statTitle">Completed</div>
          <div class="docsStatHint">Your part is already done</div>
        </div>
        <div class="chip released">Done</div>
      </div>
      <div class="statValue"><?= $stats["completed"] ?></div>
    </a>

    <a class="statCard statCardLink docsStatCard toneOverdue <?= $quick === 'overdue' ? 'isActive' : '' ?>"
       href="<?= htmlspecialchars(quickUrl('overdue')) ?>">
      <div class="docsStatHeader">
        <div>
          <div class="statTitle">Overdue</div>
          <div class="docsStatHint">Effective deadline already passed</div>
        </div>
        <div class="chip overdue">Past due</div>
      </div>
      <div class="statValue"><?= $stats["overdue"] ?></div>
    </a>
  </div>

  <section class="docsControlsCard" id="docsFilters">
    <div class="docsControlsTop">
      <div>
        <div class="docsSectionTitle">Find what you need fast</div>
      </div>

      <?php if ($hasActiveFilters): ?>
        <a class="docsClearFilters" href="<?= htmlspecialchars(documentsUrl(['q'=>null,'status'=>null,'from'=>null,'to'=>null,'quick'=>null,'sort'=>null,'page'=>1])) ?>">Reset filters</a>
      <?php endif; ?>
    </div>

    <div class="docsQuickFilters" aria-label="Quick filters">
      <a class="docsQuickFilter <?= $quick === '' ? 'isActive' : '' ?>" href="<?= htmlspecialchars(quickUrl('')) ?>">All visible <span><?= (int)$stats['active'] + (int)$stats['released'] + (int)$stats['archived'] ?></span></a>
      <a class="docsQuickFilter <?= $quick === 'incoming' ? 'isActive' : '' ?>" href="<?= htmlspecialchars(quickUrl('incoming')) ?>">Incoming <span><?= $stats['incoming'] ?></span></a>
      <a class="docsQuickFilter <?= $quick === 'pending' ? 'isActive' : '' ?>" href="<?= htmlspecialchars(quickUrl('pending')) ?>">Pending <span><?= $stats['pending'] ?></span></a>
      <a class="docsQuickFilter <?= $quick === 'completed' ? 'isActive' : '' ?>" href="<?= htmlspecialchars(quickUrl('completed')) ?>">Completed <span><?= $stats['completed'] ?></span></a>
      <a class="docsQuickFilter <?= $quick === 'overdue' ? 'isActive' : '' ?>" href="<?= htmlspecialchars(quickUrl('overdue')) ?>">Overdue <span><?= $stats['overdue'] ?></span></a>
      <a class="docsQuickFilter <?= $quick === 'released' ? 'isActive' : '' ?>" href="<?= htmlspecialchars(quickUrl('released')) ?>">Closed <span><?= $stats['released'] ?></span></a>
      <a class="docsQuickFilter <?= $quick === 'archived' ? 'isActive' : '' ?>" href="<?= htmlspecialchars(quickUrl('archived')) ?>">Archived <span><?= $stats['archived'] ?></span></a>
    </div>

    <div class="docsControlsGrid">
      <form class="toolbar toolbarSearch docsToolbarSearch" method="GET" action="<?= PUBLIC_PATH ?>/documents.php">
        <input type="hidden" name="view" value="<?= htmlspecialchars($requestedDocumentsTab) ?>">
        <?php if ($assistantModeEnabled): ?><input type="hidden" name="acting_principal_user_id" value="<?= (int)($activeAssistantPrincipal['id'] ?? 0) ?>"><?php endif; ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($statusGet) ?>">
        <input type="hidden" name="from" value="<?= htmlspecialchars($date_from) ?>">
        <input type="hidden" name="to" value="<?= htmlspecialchars($date_to) ?>">
        <input type="hidden" name="quick" value="<?= htmlspecialchars($quick) ?>">

        <div class="control docsSearchControl">
          <label>Search documents</label>
          <input class="search" type="text" name="q"
                 placeholder="DOC no, division tracking no, subject, requester..."
                 value="<?= htmlspecialchars($search) ?>">
        </div>

        <div class="control docsSortControl">
          <label>Sort</label>
          <select class="select" name="sort">
            <option value="workflow" <?= ($sort === "" || $sort === "workflow") ? "selected" : "" ?>>My work priority</option>
            <option value="urgent" <?= $sort === "urgent" ? "selected" : "" ?>>Nearest effective deadline</option>
            <option value="overdue_longest" <?= $sort === "overdue_longest" ? "selected" : "" ?>>Overdue first</option>
            <option value="newest" <?= $sort === "newest" ? "selected" : "" ?>>Newest document date</option>
            <option value="oldest" <?= $sort === "oldest" ? "selected" : "" ?>>Oldest document date</option>
          </select>
        </div>

        <button type="submit" class="btnSecondary docsControlBtn">Search</button>
      </form>

      <form class="toolbar toolbarFilters docsToolbarFilters" method="GET" action="<?= PUBLIC_PATH ?>/documents.php">
        <input type="hidden" name="view" value="<?= htmlspecialchars($requestedDocumentsTab) ?>">
        <?php if ($assistantModeEnabled): ?><input type="hidden" name="acting_principal_user_id" value="<?= (int)($activeAssistantPrincipal['id'] ?? 0) ?>"><?php endif; ?>
        <input type="hidden" name="quick" value="<?= htmlspecialchars($quick) ?>">
        <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">

        <div class="control">
          <label>Status</label>
          <select class="select" name="status">
            <option value="">All</option>
            <option value="ACTIVE" <?= strtoupper($statusGet) === "ACTIVE" ? "selected" : "" ?>>ACTIVE</option>
            <option value="RELEASED" <?= strtoupper($statusGet) === "RELEASED" ? "selected" : "" ?>>CLOSED</option>
            <option value="ARCHIVED" <?= strtoupper($statusGet) === "ARCHIVED" ? "selected" : "" ?>>ARCHIVED</option>
          </select>
        </div>

        <div class="control">
          <label>Date from</label>
          <input class="date" type="date" name="from" value="<?= htmlspecialchars($date_from) ?>">
        </div>

        <div class="control">
          <label>Date to</label>
          <input class="date" type="date" name="to" value="<?= htmlspecialchars($date_to) ?>">
        </div>

        <button type="submit" class="btnSecondary docsControlBtn">Apply</button>
      </form>
    </section>
  </section>

  <div class="tableWrap docsTableWrap" id="docsList">
    <div class="docsTableTopbar">
      <div class="docsResultsMeta">
        Showing <b><?= (int)($total ? $offset + 1 : 0) ?></b>–<b><?= (int)min($offset + $perPage, $total) ?></b> of <b><?= (int)$total ?></b>
      </div>
    </div>

    <table class="docTable docsTableModern">
      <colgroup>
        <col class="docsColStatus">
        <col class="docsColDocument">
        <col class="docsColRoute">
        <col class="docsColState">
        <col class="docsColRemark">
        <col class="docsColDeadline">
        <col class="docsColRequester">
      </colgroup>
      <thead>
        <tr>
          <th>My Status</th>
          <th>Document</th>
          <th>Route</th>
          <th>Doc State</th>
          <th>Latest Remark</th>
          <th>Deadline</th>
          <th>Requester</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$docs): ?>
          <tr>
            <td colspan="7" class="mini docsEmptyState">No documents found.</td>
          </tr>
        <?php endif; ?>

        <?php foreach ($docs as $d): ?>
          <?php
            $days = (int)($d["days_stuck"] ?? 0);
            $workingMinutesStuck = max(0, (int)($d["working_minutes_stuck"] ?? 0));
            $workingHoursStuck = max(0, (int)($d["working_hours_stuck"] ?? intdiv($workingMinutesStuck, 60)));
            $inactiveDays = max(0, (int)($d["lifecycle_inactive_days"] ?? 0));

            $docDeadlineRaw = trim((string)($d["deadline_at"] ?? ""));
            $myPersonalDeadlineRaw = trim((string)($d["my_personal_deadline_at"] ?? ""));
            $hasPersonalDeadline = ($myPersonalDeadlineRaw !== "");
            $effectiveDeadlineRaw = $hasPersonalDeadline ? $myPersonalDeadlineRaw : $docDeadlineRaw;
            $effectiveDeadlineBaseTs = $effectiveDeadlineRaw !== "" ? strtotime($effectiveDeadlineRaw) : false;
            $effectiveDeadlineTs = $effectiveDeadlineBaseTs !== false ? strtotime(date("Y-m-d", $effectiveDeadlineBaseTs) . " 23:59:59") : false;
            $docDeadlineText = $docDeadlineRaw !== "" ? date("M d, Y", strtotime($docDeadlineRaw)) : "—";
            $personalDeadlineText = $myPersonalDeadlineRaw !== "" ? date("M d, Y", strtotime($myPersonalDeadlineRaw)) : "—";
            $deadlineBadgeText = "NO DEADLINE";
            $deadlineBadgeClass = "neutral";
            $deadlineToneClass = "";
            $deadlineMetaLines = [];
            $deadlineSortTs = $effectiveDeadlineTs ? (int)$effectiveDeadlineTs : 0;

            $openCount = (int)($d["open_route_count"] ?? 0);
            $inTransit = ($openCount > 0);
            $currentStatus = strtoupper((string)($d["current_status"] ?? "ACTIVE"));
            $hasRealBranches = ((int)($d["has_real_branches"] ?? 0) === 1);
            $lastEndHereKind = (string)($d["last_end_here_kind"] ?? "");
            $isLifecycleEnded = in_array($lastEndHereKind, ["branch_ended_here", "document_ended_here"], true);
            $isInactiveLifecycle = in_array($currentStatus, ["RELEASED", "ARCHIVED"], true) || $isLifecycleEnded;
            $isDeadlineLifecycleClosed = in_array($currentStatus, ["RELEASED", "ARCHIVED"], true);
            $inactiveDayText = $inactiveDays === 1 ? "1 day" : $inactiveDays . " days";
            $stuckDayText = $days === 1 ? "1 working day" : $days . " working days";
            if ($currentStatus === "ARCHIVED") {
              $activityLabel = "Inactive";
              $activityValue = $inactiveDays === 0 ? "Today" : "For " . $inactiveDayText;
              $activityText = $inactiveDays === 0 ? "Inactive today" : "Inactive for " . $inactiveDayText;
            } elseif ($isInactiveLifecycle) {
              $activityLabel = "Completed";
              $activityValue = $inactiveDays === 0 ? "Today" : $inactiveDayText . " ago";
              $activityText = $inactiveDays === 0 ? "Completed today" : "Completed " . $inactiveDayText . " ago";
            } else {
              $activityLabel = "Days stuck";
              $activityValue = $stuckDayText;
              $activityText = "Days stuck: " . $stuckDayText;
            }

            if ($hasPersonalDeadline) {
              $deadlineMetaLines[] = "Your deadline: " . $personalDeadlineText;
            }
            $deadlineMetaLines[] = "Document: " . $docDeadlineText;

            if ($effectiveDeadlineTs !== false) {
              if ($isDeadlineLifecycleClosed) {
                $closedRaw = trim((string)($d["lifecycle_closed_at"] ?? ""));
                $closedTs = $closedRaw !== "" ? strtotime($closedRaw) : false;
                $deadlineDate = date_create(date("Y-m-d", $effectiveDeadlineTs));
                $closedDate = $closedTs !== false ? date_create(date("Y-m-d", $closedTs)) : null;
                $closedAction = trim((string)($d["lifecycle_closed_action"] ?? ""));
                $outcomePrefix = $currentStatus === "ARCHIVED"
                  ? "ARCHIVED"
                  : (in_array($closedAction, ["branch_ended_here", "document_ended_here"], true) ? "DONE" : "RELEASED");

                if ($deadlineDate && $closedDate) {
                  $deltaDays = (int)$deadlineDate->diff($closedDate)->format("%r%a");
                  if ($deltaDays < 0) {
                    $earlyDays = abs($deltaDays);
                    $deadlineBadgeText = $outcomePrefix . " · " . ($earlyDays === 1 ? "1D EARLY" : "{$earlyDays}D EARLY");
                    $deadlineBadgeClass = "safe";
                  } elseif ($deltaDays === 0) {
                    $deadlineBadgeText = $outcomePrefix . " · ON TIME";
                    $deadlineBadgeClass = "safe";
                  } else {
                    $deadlineBadgeText = $outcomePrefix . " · " . ($deltaDays === 1 ? "1D LATE" : "{$deltaDays}D LATE");
                    $deadlineBadgeClass = "danger";
                  }
                } else {
                  $deadlineBadgeText = $outcomePrefix;
                  $deadlineBadgeClass = "neutral";
                }
              } else {
                $secondsLeft = $effectiveDeadlineTs - time();
                $daysLeft = (int)floor($secondsLeft / 86400);

                if ($secondsLeft < 0) {
                  $lateDays = max(1, (int)ceil(abs($secondsLeft) / 86400));
                  $deadlineBadgeText = $lateDays === 1 ? "OVERDUE 1 DAY" : "OVERDUE {$lateDays} DAYS";
                  $deadlineBadgeClass = "danger";
                  $deadlineToneClass = "rowDeadlineOverdue";
                } elseif ($secondsLeft <= 86400) {
                  $deadlineBadgeText = "DUE TODAY";
                  $deadlineBadgeClass = "today";
                } elseif ($secondsLeft <= 259200) {
                  $deadlineBadgeText = $daysLeft <= 1 ? "1 DAY LEFT" : $daysLeft . " DAYS LEFT";
                  $deadlineBadgeClass = "warn";
                } else {
                  $deadlineBadgeText = $daysLeft . " DAYS LEFT";
                  $deadlineBadgeClass = "safe";
                }
              }
            } elseif ($isDeadlineLifecycleClosed) {
              $deadlineBadgeText = $currentStatus === "ARCHIVED"
                ? "ARCHIVED"
                : (in_array(trim((string)($d["lifecycle_closed_action"] ?? "")), ["branch_ended_here", "document_ended_here"], true) ? "DONE" : "RELEASED");
              $deadlineBadgeClass = "neutral";
            }

            $myHasOpenInbound = ((int)($d["my_has_open_inbound"] ?? 0) === 1);
            $myHasActionableRole = ((int)($d["my_has_actionable_role"] ?? 0) === 1);
            $myCanChangeLifecycle = ((int)($d["my_can_change_lifecycle"] ?? 0) === 1);
            $myHasParticipation = ((int)($d["my_has_participation"] ?? 0) === 1);
            $myIsVisibleOnly = ((int)($d["my_is_visible_only"] ?? 0) === 1);
            $myIsOrigin = ((int)($d["my_is_origin"] ?? 0) === 1);
            $myIsForReference = ((int)($d["my_is_for_reference"] ?? 0) === 1);
            $myIsReceiveOnly = ((int)($d["my_is_receive_only"] ?? 0) === 1);

            if (!$hasRealBranches && workflow_attachment_forwarding_enabled($conn)) {
              $senderStillWaitingOnAttachmentTasks = workflow_user_has_open_attachment_forward_tasks_as_sender(
                $conn,
                (int)($d["id"] ?? 0),
                $myUserId
              );
              if (!$senderStillWaitingOnAttachmentTasks) {
                $legacyCanActAfterAttachmentTasks = workflow_user_can_act_legacy_document(
                  $conn,
                  (int)($d["id"] ?? 0),
                  $myUserId,
                  $mySectionId,
                  $isChief,
                  true
                );
                if ($legacyCanActAfterAttachmentTasks) {
                  $myHasActionableRole = ($currentStatus === "ACTIVE");
                  $myCanChangeLifecycle = in_array($currentStatus, ["ACTIVE", "RELEASED"], true);
                }
              }
            }

            if ($myHasOpenInbound) {
              $myStatusLabel = "INCOMING";
              $myStatusChipClass = "chip action";
              $rowToneClass = "rowToneIncoming";
            } elseif ($myHasActionableRole) {
              $myStatusLabel = "PENDING";
              $myStatusChipClass = "chip overdue";
              $rowToneClass = "rowTonePending";
            } elseif ($myHasParticipation || $myIsOrigin || $myIsForReference) {
              $myStatusLabel = "COMPLETE";
              $myStatusChipClass = "chip incoming";
              $rowToneClass = "rowToneComplete";
            } else {
              $myStatusLabel = "—";
              $myStatusChipClass = "chip archived";
              $rowToneClass = "rowToneNeutral";
            }

            if ($currentStatus === "ARCHIVED") {
              $docStateLabel = "ARCHIVED";
              $docStateToneClass = "isArchived";
              $drawerStatusChipClass = "chip archived";
              $docStateHint = "Filed record";
            } elseif ($currentStatus === "RELEASED") {
              $docStateLabel = $isLifecycleEnded ? "LIFECYCLE ENDED" : "RELEASED";
              $docStateToneClass = "isReleased";
              $drawerStatusChipClass = "chip released";
              $docStateHint = $isLifecycleEnded ? "No further routing" : "Completed routing";
            } elseif ($inTransit) {
              $docStateLabel = "IN TRANSIT";
              $docStateToneClass = "isTransit";
              $drawerStatusChipClass = "chip action";
              $docStateHint = $openCount > 1 ? "Open routes: " . $openCount : "Awaiting acknowledgment";
            } else {
              $docStateLabel = "ACTIVE";
              $docStateToneClass = "isActive";
              $drawerStatusChipClass = "chip incoming";
              $docStateHint = "Within workflow";
            }

            $isJustCreatedDoc = ($createdDocId > 0 && (int)$d["id"] === $createdDocId);

            $statusBadges = [];
            if ($myIsOrigin) {
              $statusBadges[] = ["label" => "ORIGIN", "class" => "docMiniBadge"];
            }
            if ($myIsForReference) {
              $statusBadges[] = ["label" => "FOR REFERENCE", "class" => "docMiniBadge"];
            }
            if ($myIsReceiveOnly) {
              $statusBadges[] = ["label" => "RECEIVE ONLY", "class" => "docMiniBadge warn"];
            }
            if ($myIsVisibleOnly && !$myHasParticipation && !$myIsOrigin && !$myIsForReference) {
              $statusBadges[] = ["label" => "VISIBLE", "class" => "docMiniBadge muted"];
            }
            if ($isJustCreatedDoc) {
              $statusBadges[] = ["label" => "NEW", "class" => "docMiniBadge muted"];
            }

            $movementText = "—";
            if ($inTransit) {
              if ($openCount > 1) {
                $movementText = "Multiple recipients (" . $openCount . ")";
              } else {
                $toUserName = trim((string)($d["open_to_user_name"] ?? ""));
                $toSecName  = (string)($d["open_to_section_name"] ?? "—");
                $movementText = $toUserName !== "" ? $toUserName : $toSecName;
              }
            }

            $currentHolderText = (string)($d["current_holder_name"] ?? "—");

            $lastHolderText = $inTransit
              ? (string)($d["open_from_section_name"] ?? "—")
              : (string)($d["last_holder_name"] ?? "—");

            $routeLine1Label = "Current";
            $routeLine1Value = $currentHolderText;
            $routeLine1Strong = true;

            $routeLine2Label = "To";
            $routeLine2Value = $movementText;

            $routeLine3Label = "Last";
            $routeLine3Value = $lastHolderText;

            if ($hasRealBranches) {
              $routeLine1Label = "My lane";
              $routeLine1Strong = true;

              if ($myHasActionableRole) {
                $routeLine1Value = "With you";
              } elseif ($myHasOpenInbound) {
                $routeLine1Value = "To you";
              } elseif ($myHasParticipation || $myIsOrigin || $myIsForReference) {
                $routeLine1Value = "Past your lane";
              } else {
                $routeLine1Value = "Not in your lane";
              }

              $routeLine2Label = "Context";

              if ($myHasActionableRole) {
                $routeLine2Value = "Awaiting your action";
              } elseif ($myHasOpenInbound) {
                $routeLine2Value = "Waiting for your receive";
              } elseif ($openCount > 0) {
                $routeLine2Value = "Waiting for " . $openCount . " recipient" . ($openCount === 1 ? "" : "s");
              } else {
                $routeLine2Value = "Branch complete";
              }

              $routeLine3Label = "Latest";
              $routeLine3Value = $lastHolderText !== "" ? $lastHolderText : "—";
            }

            $latestRemarkText = trim((string)($d["latest_route_remark"] ?? ""));
            $latestRemarkText = strcasecmp($latestRemarkText, "none") === 0 ? "" : $latestRemarkText;

            $latestRemarkVisibleToMe = false;
            if ($latestRemarkText !== "") {
              $latestRemarkVisibleToMe = (
                !empty($d["latest_remark_visible_to_me"])
                || $isPrivileged
                || (int)($d["latest_remark_sent_by_user_id"] ?? 0) === $myUserId
                || (int)($d["latest_remark_from_user_id"] ?? 0) === $myUserId
                || (int)($d["latest_remark_to_user_id"] ?? 0) === $myUserId
                || (int)($d["latest_remark_received_by_user_id"] ?? 0) === $myUserId
                || (int)($d["latest_remark_to_section_id"] ?? 0) === $mySectionId
                || (int)($d["latest_remark_from_section_id"] ?? 0) === $mySectionId
              );
            }
            $latestRemarkForList = $latestRemarkVisibleToMe ? $latestRemarkText : "";


            $docTypeText = trim((string)($d["content_type"] ?? ""));
            $docCommText = trim((string)($d["comm_type"] ?? ""));
            $projectCodes = array_values(array_filter(array_map('trim', explode('||', (string)($d["project_codes_concat"] ?? ""))), static fn(string $v): bool => $v !== ''));
            $projectIds = array_values(array_filter(array_map('intval', explode(',', (string)($d["project_ids_concat"] ?? ""))), static fn(int $v): bool => $v > 0));
            $docMetaType = $docTypeText !== "" && $docCommText !== ""
              ? $docTypeText . " • " . $docCommText
              : ($docTypeText !== "" ? $docTypeText : ($docCommText !== "" ? $docCommText : "—"));
            $mpwTrackingNo = trim((string)($d["tracking_no"] ?? ""));
            $divisionTrackingNo = trim((string)($d["division_tracking_no"] ?? ""));
            $divisionTrackingDisplay = $divisionTrackingNo !== ""
              ? (preg_replace('/\s+/', '', $divisionTrackingNo) ?? $divisionTrackingNo)
              : "";
            $trackingDisplay = $mpwTrackingNo . ($divisionTrackingDisplay !== "" ? " • " . $divisionTrackingDisplay : "");
            $flatAttachmentForwardMeta = [
              "attachment_forward_source_branch" => 0,
              "attachment_forward_recipient_branch" => 0,
              "attachment_forward_open_task_count" => 0,
              "attachment_forward_can_attach" => 0,
              "attachment_forward_can_mark_done" => 0,
              "attachment_forward_task_status" => "",
            ];
            $attachmentForwardTaskSummary = [];
            if (workflow_attachment_forwarding_enabled($conn) && $myUserId > 0) {
              if ($hasRealBranches) {
                $attachmentForwardTaskSummary = workflow_get_attachment_forward_task_summary($conn, (int)$d["id"], $myUserId);
              } else {
                $flatAttachmentForwardMeta = workflow_get_document_attachment_forward_task_meta($conn, (int)$d["id"], $myUserId);
                $attachmentForwardTaskSummary = workflow_get_attachment_forward_task_summary($conn, (int)$d["id"], $myUserId, 0, 0);
              }
            }

            $flatAttachmentTaskStatus = strtoupper((string)($flatAttachmentForwardMeta["attachment_forward_task_status"] ?? ""));
            $flatAttachmentIsSender = (
              !$hasRealBranches
              && (int)($flatAttachmentForwardMeta["attachment_forward_source_branch"] ?? 0) === 1
            );
            $flatAttachmentIsRecipient = (
              !$hasRealBranches
              && (int)($flatAttachmentForwardMeta["attachment_forward_recipient_branch"] ?? 0) === 1
            );
            $flatAttachmentSenderWaiting = $flatAttachmentIsSender
              && (int)($flatAttachmentForwardMeta["attachment_forward_open_task_count"] ?? 0) > 0;
            $flatAttachmentRecipientPendingReceive = $flatAttachmentIsRecipient
              && $flatAttachmentTaskStatus === "PENDING_RECEIVE";
            $flatAttachmentRecipientInProgress = $flatAttachmentIsRecipient
              && $flatAttachmentTaskStatus === "IN_PROGRESS";
            $flatAttachmentRecipientCompleted = $flatAttachmentIsRecipient
              && !$flatAttachmentRecipientPendingReceive
              && !$flatAttachmentRecipientInProgress
              && (int)($flatAttachmentForwardMeta["attachment_forward_open_task_count"] ?? 0) === 0;

            if (!$hasRealBranches) {
              if ($flatAttachmentRecipientPendingReceive) {
                $myHasOpenInbound = true;
                $myHasActionableRole = false;
                $myCanChangeLifecycle = false;
                $myStatusLabel = "INCOMING";
                $myStatusChipClass = "chip action";
                $rowToneClass = "rowToneIncoming";
              } elseif ($flatAttachmentRecipientInProgress) {
                $myHasOpenInbound = false;
                $myHasActionableRole = false;
                $myCanChangeLifecycle = false;
                $myStatusLabel = "PENDING";
                $myStatusChipClass = "chip overdue";
                $rowToneClass = "rowTonePending";
              } elseif ($flatAttachmentSenderWaiting) {
                $myHasOpenInbound = false;
                $myHasActionableRole = false;
                $myCanChangeLifecycle = false;
                $myStatusLabel = "PENDING";
                $myStatusChipClass = "chip overdue";
                $rowToneClass = "rowTonePending";
              } elseif ($flatAttachmentRecipientCompleted) {
                $myHasOpenInbound = false;
                $myHasActionableRole = false;
                $myCanChangeLifecycle = false;
                $myStatusLabel = "COMPLETE";
                $myStatusChipClass = "chip incoming";
                $rowToneClass = "rowToneComplete";
              }
            }
          ?>
          <tr
            class="rowHover docsRow <?= htmlspecialchars(trim($rowToneClass . " " . $deadlineToneClass . ($isJustCreatedDoc ? " docsRowJustCreated" : ""))) ?>"
            <?= $isJustCreatedDoc ? 'data-created-doc="1"' : '' ?>
            data-doc='<?= htmlspecialchars(
              json_encode([
                "id" => (int)$d["id"],
                "tracking_no" => $mpwTrackingNo,
                "division_tracking_no" => $divisionTrackingDisplay,
                "tracking_display" => $trackingDisplay,
                "requester" => $d["requester"],
                "document_date" => $d["document_date"],
                "deadline_at" => $d["deadline_at"],
                "my_personal_deadline_at" => $d["my_personal_deadline_at"],
                "effective_deadline_at" => $effectiveDeadlineRaw,
                "deadline_badge_text" => $deadlineBadgeText,
                "deadline_badge_class" => $deadlineBadgeClass,
                "subject" => $d["subject"],
                "content_type" => $d["content_type"],
                "comm_type" => $d["comm_type"],
                "project_codes" => $projectCodes,
                "project_ids" => $projectIds,

                "status_label" => $docStateLabel,
                "status_chip_class" => $drawerStatusChipClass,
                "my_status" => $myStatusLabel,
                "my_status_chip_class" => $myStatusChipClass,
                "is_origin" => $myIsOrigin ? 1 : 0,
                "is_for_reference" => $myIsForReference ? 1 : 0,
                "is_receive_only" => $myIsReceiveOnly ? 1 : 0,
                "is_visible_only" => $myIsVisibleOnly ? 1 : 0,
                "has_real_branches" => $hasRealBranches ? 1 : 0,
                "my_has_actionable_role" => $myHasActionableRole ? 1 : 0,
                "my_can_change_lifecycle" => $myCanChangeLifecycle ? 1 : 0,
                "my_has_open_inbound" => $myHasOpenInbound ? 1 : 0,
                "is_initial_routing" => (int)($d["is_initial_routing"] ?? 0),
                "attachment_forward_open_task_count" => (int)($flatAttachmentForwardMeta["attachment_forward_open_task_count"] ?? 0),
                "attachment_forward_can_attach" => (int)($flatAttachmentForwardMeta["attachment_forward_can_attach"] ?? 0),
                "attachment_forward_can_mark_done" => (int)($flatAttachmentForwardMeta["attachment_forward_can_mark_done"] ?? 0),
                "attachment_forward_recipient_branch" => (int)($flatAttachmentForwardMeta["attachment_forward_recipient_branch"] ?? 0),
                "attachment_forward_source_branch" => (int)($flatAttachmentForwardMeta["attachment_forward_source_branch"] ?? 0),
                "attachment_forward_task_status" => (string)($flatAttachmentForwardMeta["attachment_forward_task_status"] ?? ""),
                "attachment_forward_task_summary" => $attachmentForwardTaskSummary,
                "flat_attachment_sender_waiting" => $flatAttachmentSenderWaiting ? 1 : 0,
                "flat_attachment_recipient_pending_receive" => $flatAttachmentRecipientPendingReceive ? 1 : 0,
                "flat_attachment_recipient_in_progress" => $flatAttachmentRecipientInProgress ? 1 : 0,
                "flat_attachment_recipient_completed" => $flatAttachmentRecipientCompleted ? 1 : 0,

                "in_transit" => !empty($d["open_to_section_id"]) ? 1 : 0,
                "open_to_section_id" => (int)($d["open_to_section_id"] ?? 0),
                "open_to_user_id" => (int)($d["open_to_user_id"] ?? 0),
                "open_to_user_name" => (string)($d["open_to_user_name"] ?? ""),
                "open_to_section_name" => (string)($d["open_to_section_name"] ?? ""),
                "open_from_section_name" => (string)($d["open_from_section_name"] ?? ""),
                "open_from_section_id" => (int)($d["open_from_section_id"] ?? 0),
                "open_route_id" => (int)($d["any_open_route_id"] ?? 0),
                "my_open_route_id" => (int)($d["my_open_route_id"] ?? 0),
                "open_route_count" => $openCount,
                "can_edit_details" => (int)($d["can_edit_details"] ?? 0),
                "can_regenerate_division_slip" => (int)($d["can_regenerate_division_slip"] ?? 0),

                "movement_text" => $movementText,
                "current_holder_text" => $currentHolderText,
                "last_holder_text" => $lastHolderText,

                "current_holder_section_id" => (int)($d["current_holder_section_id"] ?? 0),
                "current_status" => (string)($d["current_status"] ?? "ACTIVE"),
                "last_end_here_kind" => (string)($d["last_end_here_kind"] ?? ""),
                "days_stuck" => $days,
                "working_minutes_stuck" => $workingMinutesStuck,
                "working_hours_stuck" => $workingHoursStuck,
                "activity_label" => $activityLabel,
                "activity_value" => $activityValue,
                "activity_text" => $activityText,
                "acting_principal_user_id" => $assistantModeEnabled ? (int)($activeAssistantPrincipal['id'] ?? 0) : 0,
              ], JSON_UNESCAPED_UNICODE),
              ENT_QUOTES,
              "UTF-8"
            ) ?>'
          >
            <td data-label="My status">
              <div class="docStatusCell">
                <span class="<?= htmlspecialchars($myStatusChipClass) ?>">
                  <?= htmlspecialchars($myStatusLabel) ?>
                </span>

                <?php if ($statusBadges): ?>
                  <div class="docStatusBadges">
                    <?php foreach ($statusBadges as $badge): ?>
                      <span class="<?= htmlspecialchars($badge["class"]) ?>"><?= htmlspecialchars($badge["label"]) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </td>

            <td data-label="Document">
              <div class="docInfoCell">
                <div class="docInfoTitle"><?= htmlspecialchars((string)$d["subject"]) ?></div>
                <div class="docInfoMeta">
                  <span class="docInfoTracking"><?= htmlspecialchars($trackingDisplay) ?></span>
                  <span class="docMetaDot">•</span>
                  <span><?= htmlspecialchars($docMetaType) ?></span>
                  <span class="docMetaDot">•</span>
                  <span><?= htmlspecialchars((string)$d["document_date"]) ?></span>
                </div>
              </div>
            </td>

            <td data-label="Route">
              <div class="routeInfoCell">
                <div class="routeLine">
                  <span class="routeLabel"><?= htmlspecialchars($routeLine1Label) ?></span>
                  <span class="routeValue <?= $routeLine1Strong ? 'strong' : '' ?>">
                    <?= htmlspecialchars($routeLine1Value) ?>
                  </span>
                </div>

                <div class="routeLine">
                  <span class="routeLabel"><?= htmlspecialchars($routeLine2Label) ?></span>
                  <span class="routeValue"><?= htmlspecialchars($routeLine2Value) ?></span>
                </div>

                <div class="routeLine">
                  <span class="routeLabel"><?= htmlspecialchars($routeLine3Label) ?></span>
                  <span class="routeValue"><?= htmlspecialchars($routeLine3Value) ?></span>
                </div>
              </div>
            </td>

            <td data-label="Doc state">
              <div class="docStateCell">
                <div class="docStateMain <?= htmlspecialchars($docStateToneClass) ?>">
                  <span class="docStateDot"></span>
                  <span class="docStateText"><?= htmlspecialchars($docStateLabel) ?></span>
                </div>
                <div class="docStateHint"><?= htmlspecialchars($docStateHint) ?></div>
              </div>
            </td>

            <td data-label="Latest remark">
              <div class="latestRemarkCell<?= $latestRemarkForList !== '' ? ' hasRemark' : '' ?>">
                <?php if ($latestRemarkForList !== ''): ?>
                  <div class="latestRemarkText" title="<?= htmlspecialchars($latestRemarkForList) ?>">
                    <?= htmlspecialchars($latestRemarkForList) ?>
                  </div>
                <?php else: ?>
                  <div class="latestRemarkBlank"></div>
                <?php endif; ?>
              </div>
            </td>

            <td data-label="Deadline">
              <div class="deadlineCell <?= htmlspecialchars($deadlineBadgeClass) ?>">
                <div class="deadlineBadge <?= htmlspecialchars($deadlineBadgeClass) ?>"><?= htmlspecialchars($deadlineBadgeText) ?></div>
                <?php foreach ($deadlineMetaLines as $deadlineMetaLine): ?>
                  <div class="deadlineMetaLine"><?= htmlspecialchars($deadlineMetaLine) ?></div>
                <?php endforeach; ?>
              </div>
            </td>

            <td data-label="Requester">
              <div class="requesterCell">
                <div class="requesterName"><?= htmlspecialchars((string)$d["requester"]) ?></div>
                <div class="requesterMeta"><?= htmlspecialchars($activityText) ?></div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($createdDocId > 0): ?>
<script>
  window.addEventListener("load", function () {
    const row = document.querySelector('tr[data-created-doc="1"]');
    if (!row) return;

    row.scrollIntoView({ behavior: "smooth", block: "center" });

    if (window.DTToast?.success) {
      window.DTToast.success(<?= json_encode($createdFlashMessage !== "" ? $createdFlashMessage : "Document added successfully.") ?>);
    }
  });
</script>
<?php endif; ?>

<?php
// Pagination UI
$fromRow = $total ? ($offset + 1) : 0;
$toRow   = min($offset + $perPage, $total);

$start = max(1, $page - 2);
$end   = min($totalPages, $page + 2);
?>

<div class="pager">
  <div class="pagerInfo mini">
    Showing <b><?= (int)$fromRow ?></b>–<b><?= (int)$toRow ?></b> of <b><?= (int)$total ?></b>
  </div>

  <div class="pagerBtns">
    <?php if ($page > 1): ?>
      <a class="pagerBtn" href="<?= htmlspecialchars(pageUrl($page - 1)) ?>">Prev</a>
    <?php else: ?>
      <span class="pagerBtn isDisabled">Prev</span>
    <?php endif; ?>

    <?php if ($start > 1): ?>
      <a class="pagerBtn" href="<?= htmlspecialchars(pageUrl(1)) ?>">1</a>
      <?php if ($start > 2): ?><span class="pagerDots">…</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
      <?php if ($i === $page): ?>
        <span class="pagerBtn isActive"><?= $i ?></span>
      <?php else: ?>
        <a class="pagerBtn" href="<?= htmlspecialchars(pageUrl($i)) ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>

    <?php if ($end < $totalPages): ?>
      <?php if ($end < $totalPages - 1): ?><span class="pagerDots">…</span><?php endif; ?>
      <a class="pagerBtn" href="<?= htmlspecialchars(pageUrl($totalPages)) ?>"><?= $totalPages ?></a>
    <?php endif; ?>

    <?php if ($page < $totalPages): ?>
      <a class="pagerBtn" href="<?= htmlspecialchars(pageUrl($page + 1)) ?>">Next</a>
    <?php else: ?>
      <span class="pagerBtn isDisabled">Next</span>
    <?php endif; ?>
  </div>
</div>

<!-- Drawer + Backdrop -->
<div id="drawerBackdrop" class="drawerBackdrop"></div>

<aside id="drawer" class="drawer" aria-hidden="true">
  <div class="drawerHeader">
    <div class="drawerHeaderMain">
      <div>
        <h3 class="drawerTitle">Document Details</h3>
        <div class="drawerSub">Tracking: <b id="d_tracking"></b></div>
      </div>

      <div class="drawerBranchWrap drawerBranchWrapHeader" id="d_branch_wrap" style="display:none;">
        <div class="drawerBranchHead">
          <span class="drawerBranchTitle">Current lane</span>
          <span class="mini" id="d_branch_hint" style="opacity:.72;">Select the lane you want to view.</span>
        </div>
        <div id="d_branch_bar" class="branchSelectorWrap">
          <select id="d_branch_select" class="branchSelect" aria-label="Select lane"></select>
        </div>
        <div id="d_branch_meta" class="branchMeta mini"></div>
      </div>
    </div>
    <button id="drawerClose" class="drawerClose">✕</button>
  </div>

  <nav class="drawerTabs" aria-label="Document detail sections">
    <button type="button" class="drawerTab isActive" data-drawer-tab="overview" aria-selected="true">
      <span class="drawerTabIcon drawerTabIconOverview" aria-hidden="true"></span>
      <span>Overview</span>
    </button>
    <button type="button" class="drawerTab" data-drawer-tab="files" aria-selected="false">
      <span class="drawerTabIcon drawerTabIconFiles" aria-hidden="true"></span>
      <span>Files</span>
    </button>
    <button type="button" class="drawerTab" data-drawer-tab="timeline" aria-selected="false">
      <span class="drawerTabIcon drawerTabIconTimeline" aria-hidden="true"></span>
      <span>Timeline</span>
    </button>
  </nav>

  <div class="drawerBody">
    <input type="hidden" id="d_id" value="">

    <section class="drawerPanel isActive" id="drawerPanelOverview" data-drawer-panel="overview">
      <div class="drawerPanelIntro">
        <div class="drawerPanelEyebrow">Document snapshot</div>
        <div class="drawerPanelTitle">Key details</div>
      </div>

    <div class="kv">
      <div class="k">Status</div>
      <div class="v">
        <span id="d_status" class="chip incoming">—</span>
      </div>
    </div>

    <div class="kv">
      <div class="k">Current</div>
      <div class="v">
        <span id="d_holder" class="chip incoming">—</span>
      </div>
    </div>

    <div class="kv">
      <div class="k">To</div>
      <div class="v" id="d_destination">
        <span id="d_destination_text">—</span>
      </div>
    </div>

    <div class="kv">
      <div class="k">From</div>
      <div class="v" id="d_last_holder">—</div>
    </div>

    <div class="kv"><div class="k">Requester</div><div class="v" id="d_requester"></div></div>
    <div class="kv"><div class="k">Doc Date</div><div class="v" id="d_date"></div></div>
    <div class="kv"><div class="k">Doc deadline</div><div class="v" id="d_deadline">—</div></div>
    <div class="kv"><div class="k">Your deadline</div><div class="v" id="d_personal_deadline">—</div></div>
    <div class="kv"><div class="k">Urgency</div><div class="v" id="d_deadline_countdown">—</div></div>
    <div class="kv"><div class="k">Subject</div><div class="v" id="d_subject"></div></div>
    <div class="kv"><div class="k">Type</div><div class="v" id="d_type"></div></div>
    <div class="kv">
      <div class="k">Project Codes</div>
      <div class="v">
        <div id="d_projects" class="mini">—</div>
        <div style="margin-top:8px; display:none;" id="d_projects_manage_row">
          <button type="button" class="btnSecondary" id="btnProjectManageToggle">Add Project Code</button>
        </div>
        <div id="d_projects_actions" style="margin-top:8px; display:none; gap:8px; align-items:center;">
          <input id="d_project_code_input" class="search" type="text" placeholder="Type new project code">
          <select id="d_project_select" class="search" style="min-width:240px;">
            <option value="">Or pick existing project code…</option>
          </select>
          <div style="display:flex; gap:8px; width:100%;">
            <button type="button" class="btnSecondary" id="btnProjectAttach">+ Add</button>
            <button type="button" class="btnSecondary" id="btnProjectManageClose">Done</button>
          </div>
        </div>
      </div>
    </div>
    <div class="kv"><div class="k" id="d_activity_label">Days stuck</div><div class="v" id="d_days"></div></div>
    <div class="kv">
      <div class="k">Action Times</div>
      <div class="v" id="d_elapsed_times">
        <span class="mini" style="opacity:0.7;">Loading...</span>
      </div>
    </div>
    <div class="kv" id="rowEditDocumentDetails" style="display:none;">
      <div class="k">Correction</div>
      <div class="v"><button type="button" class="btnSecondary" id="btnEditDocumentDetails">Edit details</button></div>
    </div>
    <div class="kv"><div class="k">Full Document</div><div class="v"><button type="button" class="btnComp" id="btnViewDocument" data-no-loading>View document</button></div></div>
    <div class="drawerRow" id="rowPpdSlip" style="display:none;">
      <div class="k" id="rowPpdSlipLabel"><?= htmlspecialchars($ownDivisionSlipLabel !== "" ? $ownDivisionSlipLabel : "Division Tracking Slip") ?></div>
      <div class="v" style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
        <button type="button" class="btnSecondary" id="btnPpdSlipGenerate">Generate</button>
        <button type="button" class="btnSecondary" id="btnPpdSlipAttach">Attach</button>
        <button type="button" class="btnComp" id="btnPpdSlipPrint" disabled>Print</button>
      </div>
    </div>
    </section>

    <!-- Attachments -->
    <section class="drawerPanel" id="drawerPanelFiles" data-drawer-panel="files" hidden>
      <div class="drawerPanelIntro">
        <div>
          <div class="drawerPanelEyebrow">Files and attachments</div>
          <div class="drawerPanelTitle">Attachments</div>
        </div>
      </div>

      <div class="drawerSectionActions">
        <button type="button" class="btnSecondary" id="btnRegenerateDivisionSlip" style="display:none;">Generate latest slip</button>
        <button type="button" class="btnSecondary" id="btnToggleUpload">Add attachment</button>
      </div>

      <div id="d_attachments" class="attachList mini"></div>

      <form id="attachForm" class="attachForm collapsed" enctype="multipart/form-data">
        <input type="file" id="attachFile" name="file" required accept=".pdf,.jpg,.jpeg,.png" />
        <div style="display:flex; gap:8px; margin-top:8px;">
          <input type="hidden" id="attachType" value="1" />
          <input id="attachNote" type="text" class="search" style="flex:1;" placeholder="Note (optional)" />
        </div>

        <button id="btnAttachUpload" type="button" class="btnPrimary" style="margin-top:10px;">Upload</button>
        <div id="attachMsg" class="mini" style="margin-top:6px;"></div>
      </form>
    </section>

    <section class="drawerPanel" id="drawerPanelTimeline" data-drawer-panel="timeline" hidden>
      <div class="drawerPanelIntro">
        <div>
          <div class="drawerPanelEyebrow">Audit trail</div>
          <div class="drawerPanelTitle">Timeline</div>
        </div>
        <span class="drawerPanelPill">Latest first</span>
      </div>
      <div id="d_timeline" class="mini">Select a document…</div>
    </section>
  </div>

  <div class="drawerActionsWrap">
    <div class="drawerActions">
      <button type="button" class="btnSecondary" id="btnEditPendingRemarks" style="display:none;">Add pending remarks</button>
      <button type="button" class="btnSecondary" id="btnToggleForward">Forward</button>
      <button type="button" class="btnSecondary" id="btnToggleAttachmentForward" style="display:none;">Forward attach</button>

      <button id="btnAckReceived" class="btnGreen" type="button" style="display:none;">Received</button>
      <button id="btnAttachmentTaskDone" class="btnComp" type="button" style="display:none;">Task done</button>
      <button id="btnEndHere" class="btnComp" type="button" style="display:none;">End Now</button>
      <button id="btnUndoEndHere" class="btnSecondary" type="button" style="display:none;">Reopen Lifecycle</button>
      <button id="btnRelease" class="btnGreen" type="button" style="display:none;">Release</button>
      <button id="btnArchive" class="btnComp" type="button" style="display:none;">Archive</button>
    </div>
    <div id="drawerAttachmentForwardHint" class="mini" style="display:none; margin-top:10px; padding:10px 12px; border-radius:12px; background:#eff6ff; border:1px solid rgba(37,99,235,.16); color:#1e3a8a;"></div>
    <div id="drawerAttachmentForwardStatus" class="mini" style="display:none; margin-top:10px; padding:12px; border-radius:12px; background:#f8fafc; border:1px solid rgba(15,23,42,.08); color:#334155;"></div>
    <div id="drawerActionGuide" class="mini"><a href="<?= htmlspecialchars(PUBLIC_PATH . '/which_button_should_i_click.php', ENT_QUOTES, 'UTF-8') ?>">Which button should I click?</a></div>
  </div>
</aside>

<div id="forwardModal" class="modalWrap" aria-hidden="true">
  <div id="forwardModalBackdrop" class="modalBackdrop"></div>
  <div class="modalCard forwardModalCard">
    <div class="modalHeader">
      <div>
        <h3>Forward document</h3>
        <div class="attSub mini">Choose the destination and recipients, then send.</div>
      </div>
      <button id="forwardModalClose" class="modalClose" type="button">✕</button>
    </div>

    <div class="modalBody forwardModalBody">
      <label style="font-size:12px; font-weight:900;">Forward To</label>

      <select id="f_to_section" class="select" style="min-width:100%; margin-top:6px;">
        <option value="">-- Select section --</option>
      </select>

      <label style="font-size:12px; font-weight:900; margin-top:14px; display:block;">Recipients</label>

      <div class="forwardUserTools" style="display:flex; gap:8px; margin:6px 0 8px;">
        <button type="button" class="btnSecondary" id="btnUserSelectAll" style="padding:6px 10px;">Select all</button>
        <button type="button" class="btnSecondary" id="btnUserClear" style="padding:6px 10px;">Clear</button>
      </div>

      <div id="f_user_list" class="userChecklist mini"
          style="border:1px solid rgba(0,0,0,.12); border-radius:12px; padding:10px; max-height:220px; overflow:auto;">
        <div style="opacity:.7;">Select a section to load users…</div>
      </div>

      <div id="forwardRecipientsPreview" class="mini" style="opacity:.75; margin-top:6px;">
        Recipients: —
      </div>

      <div id="forwardModeWrap" style="margin-top:12px; padding:10px; border:1px solid rgba(0,0,0,.10); border-radius:12px; background:#f8fafc; display:none;">
        <label style="display:flex; gap:8px; align-items:flex-start; cursor:pointer;">
          <input id="f_receive_only" type="checkbox" style="margin-top:3px;">
          <span>
            <span style="display:block; font-size:12px; font-weight:900;">Send as reference only</span>
            <span class="mini" id="f_receive_only_hint" style="opacity:.75;">
              Recipient gets a reference copy only. Your current lane stays actionable with you.
            </span>
          </span>
        </label>
      </div>

      <div class="forwardDeadlineGrid" id="forwardDeadlineGrid" style="display:none;">
        <div id="forwardDocumentDeadlineWrap" class="forwardDeadlineWrap" style="display:none;">
          <label for="f_document_deadline">Document deadline</label>
          <input id="f_document_deadline" type="date" class="search">
          <div class="mini">Overall document deadline.</div>
        </div>

        <div id="forwardPersonalDeadlineWrap" class="forwardDeadlineWrap" style="display:none;">
          <label for="f_personal_deadline">Personal deadline</label>
          <input id="f_personal_deadline" type="date" class="search">
          <div class="mini">Recipient-specific deadline.</div>
        </div>
      </div>

      <div class="drawerActionRemarks" style="margin-top:12px;">
        <label for="d_forward_remarks" class="drawerActionRemarksLabel">Forward remarks (optional)</label>
        <textarea id="d_forward_remarks" class="search drawerActionRemarksInput" rows="3" placeholder="Add remarks for the recipient right before sending if needed"></textarea>
      </div>

      <label style="display:flex; gap:8px; align-items:flex-start; cursor:pointer; margin-top:10px;">
        <input id="f_notify_email" type="checkbox" style="margin-top:3px;">
        <span>
          <span style="display:block; font-size:12px; font-weight:900;">Notify this user through email</span>
          <span class="mini" style="opacity:.75;">Sends an email notice to selected recipient(s) after forwarding.</span>
        </span>
      </label>
      <div id="f_notify_email_hint" class="mini" style="margin-top:6px; color:#b45309; display:none;"></div>
    </div>

    <div class="modalFooter">
      <button id="btnForwardCancel" type="button" class="btnSecondary">Cancel</button>
      <button id="btnForward" type="button" class="btnComp">Send forward</button>
    </div>
  </div>
</div>

<div id="attachmentForwardModal" class="modalWrap" aria-hidden="true">
  <div id="attachmentForwardModalBackdrop" class="modalBackdrop"></div>
  <div class="modalCard forwardModalCard" style="max-width:820px;">
    <div class="modalHeader">
      <div>
        <h3>Forward attachments</h3>
        <div class="attSub mini">Keep the document with you while selected attachments are sent out as task lanes.</div>
      </div>
      <button id="attachmentForwardModalClose" class="modalClose" type="button">✕</button>
    </div>

    <div class="modalBody forwardModalBody">
      <div class="mini" style="margin-bottom:10px; opacity:.8;">
        Recipients will still click <strong>Received</strong> first. After receiving, they can only add attachment(s) and mark the task done.
      </div>

      <div id="attachmentForwardRows" style="display:grid; gap:12px;">
        <div class="mini" style="opacity:.7;">Loading attachments…</div>
      </div>

      <div style="margin-top:10px; display:flex; justify-content:flex-start;">
        <button type="button" class="btnSecondary" id="btnAttachmentForwardAddRow">Add another attachment route</button>
      </div>

      <div class="drawerActionRemarks" style="margin-top:12px;">
        <label for="d_attachment_forward_remarks" class="drawerActionRemarksLabel">Forward remarks (optional)</label>
        <textarea id="d_attachment_forward_remarks" class="search drawerActionRemarksInput" rows="3" placeholder="Add one shared note for this attachment-forward batch if needed"></textarea>
      </div>
    </div>

    <div class="modalFooter">
      <button id="btnAttachmentForwardCancel" type="button" class="btnSecondary">Cancel</button>
      <button id="btnAttachmentForwardSend" type="button" class="btnComp">Send attachment tasks</button>
    </div>
  </div>
</div>

<div id="attachmentTaskDoneModal" class="modalWrap" aria-hidden="true">
  <div id="attachmentTaskDoneModalBackdrop" class="modalBackdrop"></div>
  <div class="modalCard forwardModalCard">
    <div class="modalHeader">
      <div>
        <h3>Mark attachment task done</h3>
        <div class="attSub mini">Add a short completion remark before closing your attachment-forward lane.</div>
      </div>
      <button id="attachmentTaskDoneModalClose" class="modalClose" type="button">✕</button>
    </div>

    <div class="modalBody forwardModalBody">
      <div class="drawerActionRemarks">
        <label for="d_attachment_task_done_remarks" class="drawerActionRemarksLabel">Task done remarks (optional)</label>
        <textarea id="d_attachment_task_done_remarks" class="search drawerActionRemarksInput" rows="3" placeholder="Add completion notes if needed"></textarea>
      </div>
      <div id="attachmentTaskDoneModalMsg" class="modalMsg" style="display:none;"></div>
    </div>

    <div class="modalFooter">
      <button id="btnAttachmentTaskDoneCancel" type="button" class="btnSecondary">Cancel</button>
      <button id="btnAttachmentTaskDoneConfirm" type="button" class="btnComp">Confirm task done</button>
    </div>
  </div>
</div>

<div id="releaseModal" class="modalWrap" aria-hidden="true">
  <div id="releaseModalBackdrop" class="modalBackdrop"></div>
  <div class="modalCard forwardModalCard">
    <div class="modalHeader">
      <div>
        <h3>Release document</h3>
        <div class="attSub mini">Record where this document was physically released.</div>
      </div>
      <button id="releaseModalClose" class="modalClose" type="button">x</button>
    </div>

    <div class="modalBody forwardModalBody">
      <label for="d_release_to" style="font-size:12px; font-weight:900;">Released to</label>
      <input id="d_release_to" type="text" class="search" style="width:100%; margin-top:6px;" placeholder="e.g. COA, Records Unit, requester, outside office">
      <div class="mini" style="margin-top:6px; opacity:.75;">Free-form text is allowed for recipients outside the tracker.</div>

      <div class="drawerActionRemarks" style="margin-top:12px;">
        <label for="d_release_remarks" class="drawerActionRemarksLabel">Remarks (optional)</label>
        <textarea id="d_release_remarks" class="search drawerActionRemarksInput" rows="3" placeholder="Add release notes if needed"></textarea>
      </div>

      <div id="releaseModalMsg" class="modalMsg" style="display:none;"></div>
    </div>

    <div class="modalFooter">
      <button id="btnReleaseCancel" type="button" class="btnSecondary">Cancel</button>
      <button id="btnReleaseConfirm" type="button" class="btnGreen">Confirm release</button>
    </div>
  </div>
</div>

<div id="endHereModal" class="modalWrap" aria-hidden="true">
  <div id="endHereModalBackdrop" class="modalBackdrop"></div>
  <div class="modalCard forwardModalCard">
    <div class="modalHeader">
      <div>
        <h3 id="endHereModalTitle">End document lifecycle now?</h3>
        <div class="attSub mini" id="endHereModalSub">This stops routing for the selected lane. Use this only if no further action or forwarding is needed.</div>
      </div>
      <button id="endHereModalClose" class="modalClose" type="button">x</button>
    </div>

    <div class="modalBody forwardModalBody">
      <div class="drawerActionRemarks">
        <label for="d_end_here_remarks" class="drawerActionRemarksLabel">Final note (optional)</label>
        <textarea id="d_end_here_remarks" class="search drawerActionRemarksInput" rows="3" placeholder="Add a short final note if needed"></textarea>
      </div>

      <div id="endHereModalMsg" class="modalMsg" style="display:none;"></div>
    </div>

    <div class="modalFooter">
      <button id="btnEndHereCancel" type="button" class="btnSecondary">Cancel</button>
      <button id="btnEndHereConfirm" type="button" class="btnComp">End lifecycle</button>
    </div>
  </div>
</div>

<div id="pendingRemarksModal" class="modalWrap" aria-hidden="true">
  <div id="pendingRemarksModalBackdrop" class="modalBackdrop"></div>
  <div class="modalCard forwardModalCard">
    <div class="modalHeader">
      <div>
        <div class="drawerPendingRemarksEyebrow" id="d_pending_route_eyebrow">Pending route only</div>
        <h3 id="d_pending_route_title">Pending remarks</h3>
      </div>
      <button id="pendingRemarksModalClose" class="modalClose" type="button">x</button>
    </div>

    <div class="modalBody forwardModalBody">
      <div class="drawerPendingRemarksPreview" id="d_pending_route_preview">No pending remarks yet.</div>
      <div class="drawerPendingRemarksHint" id="d_pending_route_hint">This stays editable until the recipient receives the route.</div>

      <div class="drawerActionRemarks" id="d_pending_route_composer">
        <label for="d_pending_route_remarks" class="drawerActionRemarksLabel">Remarks</label>
        <textarea id="d_pending_route_remarks" class="search drawerActionRemarksInput" rows="3" placeholder="Add a clear note"></textarea>
      </div>
    </div>

    <div class="modalFooter">
      <span class="drawerPendingRemarksBadge" id="d_pending_route_badge">Editable</span>
      <button type="button" class="btnSecondary" id="btnCancelPendingRemarks">Cancel</button>
      <button type="button" class="btnComp" id="btnSavePendingRemarks">Save pending remarks</button>
    </div>
  </div>
</div>

<!-- Attachment Preview Modal -->
<div id="attModal" class="attModal" aria-hidden="true">
  <div id="attModalBackdrop" class="attBackdrop"></div>

  <div class="attDialog" id="attDialog">
    <div class="attTopbar">
      <div>
        <div id="attTitle" class="attTitle">Attachment Preview</div>
        <div id="attSub" class="attSub mini"></div>
      </div>
      <button id="attClose" class="attClose" type="button">✕</button>
    </div>

    <div id="attBody" class="attBody"></div>

    <div class="attFooter">
      <a id="attDownload" class="btnSecondary" href="#" target="_blank" rel="noopener">Download</a>
    </div>
  </div>
</div>

<!-- Recipients Modal (reuses attachment modal styles) -->
<div id="recModal" class="attModal" aria-hidden="true">
  <div id="recModalBackdrop" class="attBackdrop"></div>

  <div class="attDialog">
    <div class="attTopbar">
      <div>
        <div id="recTitle" class="attTitle">Recipients</div>
        <div id="recSub" class="attSub mini"></div>
      </div>
      <button id="recClose" class="attClose" type="button">✕</button>
    </div>

    <div id="recBody" class="attBody" style="padding:16px;"></div>
  </div>
</div>

<script>
  (function() {
    const originalFetch = window.fetch;
    window.fetch = async function(...args) {
      const response = await originalFetch.apply(this, args);
      
      if (typeof args[0] === 'string' && args[0].includes('get_history.php')) {
        response.clone().json().then(data => {
          if (data.ok && data.history) {
            renderElapsedTimes(
              data.history,
              Number(data.viewer_live_elapsed_working_minutes || 0),
              Boolean(data.viewer_live_elapsed_enabled),
              data.live_elapsed_working_minutes_by_user || {},
              data.user_profiles || {}
            );
          }
        }).catch(() => {});
      }
      return response;
    };

    function formatWorkingTime(minutes) {
      if (minutes <= 0) return '0 mins';
      const totalHours = Math.floor(minutes / 60);
      const mins = minutes % 60;
      
      if (totalHours > 0 && mins > 0) return `${totalHours} hr${totalHours > 1 ? 's' : ''} and ${mins} min${mins > 1 ? 's' : ''}`;
      if (totalHours > 0) return `${totalHours} hr${totalHours > 1 ? 's' : ''}`;
      return `${mins} min${mins > 1 ? 's' : ''}`;
    }

    function renderElapsedTimes(history, liveMyElapsedMinutes = 0, liveMyEnabled = false, liveByUser = {}, userProfiles = {}) {
      const container = document.getElementById('d_elapsed_times');
      if (!container) return;

      const eventsAsc = [...(Array.isArray(history) ? history : [])].sort((a, b) => {
        const ta = new Date(a?.acted_at || 0).getTime();
        const tb = new Date(b?.acted_at || 0).getTime();
        if (ta !== tb) return ta - tb;
        return (a?.event_id || 0) - (b?.event_id || 0);
      });

      // elapsed_working_minutes is cumulative for the current handling stint.
      // We keep only the peak cumulative value per stint, then sum stint peaks.
      const runningStintMax = {};
      const totalsByUserId = {};
      const nameByUserId = {};
      const fallbackTotalsByName = {};

      const flushUserStint = (actorUserId) => {
        const userKey = String(actorUserId);
        const stintMax = runningStintMax[userKey] || 0;
        if (stintMax > 0) {
          totalsByUserId[userKey] = (totalsByUserId[userKey] || 0) + stintMax;
        }
        runningStintMax[userKey] = 0;
      };

      const flushNameStint = (actorName) => {
        const nameKey = String(actorName || '').trim();
        if (!nameKey) return;
        const stintMax = runningStintMax[nameKey] || 0;
        if (stintMax > 0) {
          fallbackTotalsByName[nameKey] = (fallbackTotalsByName[nameKey] || 0) + stintMax;
        }
        runningStintMax[nameKey] = 0;
      };

      eventsAsc.forEach(ev => {
        const actorUserId = Number(ev?.actor_user_id || 0);
        const rawActorName = String(ev?.actor || '').replace(/\s*\(via.*?\)\s*/g, '').trim();
        const mins = Number(ev?.elapsed_working_minutes || 0);
        const action = String(ev?.action || '').toLowerCase();

        if (actorUserId > 0 && rawActorName) {
          nameByUserId[String(actorUserId)] = rawActorName;
        }

        // "received" means a new handling stint starts for this user.
        // Close any previous stint before resetting.
        if (action === 'received') {
          if (actorUserId > 0) {
            flushUserStint(actorUserId);
          } else if (rawActorName) {
            flushNameStint(rawActorName);
          }
          return;
        }

        if (mins <= 0) return;

        if (actorUserId > 0) {
          const userKey = String(actorUserId);
          runningStintMax[userKey] = Math.max(runningStintMax[userKey] || 0, mins);
        } else if (rawActorName) {
          runningStintMax[rawActorName] = Math.max(runningStintMax[rawActorName] || 0, mins);
        }
      });

      // Flush open stints at the end of the history.
      Object.keys(runningStintMax).forEach(key => {
        const stintMax = runningStintMax[key] || 0;
        if (stintMax <= 0) return;
        if (/^\d+$/.test(key)) {
          totalsByUserId[key] = (totalsByUserId[key] || 0) + stintMax;
        } else {
          fallbackTotalsByName[key] = (fallbackTotalsByName[key] || 0) + stintMax;
        }
      });

      const liveByUserObj = (liveByUser && typeof liveByUser === 'object') ? liveByUser : {};
      Object.entries(liveByUserObj).forEach(([uid, mins]) => {
        const userKey = String(uid || '').trim();
        const liveMins = Number(mins || 0);
        if (!/^\d+$/.test(userKey) || liveMins <= 0) return;
        totalsByUserId[userKey] = (Number(totalsByUserId[userKey] || 0) + liveMins);
      });

      const myUserId = Number(window.__CTX__?.actualUserId || 0);
      const myTotal = myUserId > 0 ? Number(totalsByUserId[String(myUserId)] || 0) : 0;
      const hasMyLiveFromMap = myUserId > 0 && Number(liveByUserObj[String(myUserId)] || 0) > 0;
      const liveMyTotal = (!hasMyLiveFromMap && liveMyEnabled && liveMyElapsedMinutes > 0) ? Number(liveMyElapsedMinutes) : 0;
      const myTotalWithLive = myTotal + liveMyTotal;
      if (myUserId > 0) {
        delete totalsByUserId[String(myUserId)];
      }

      const others = Object.entries(totalsByUserId)
        .map(([uid, total]) => ({
          uid,
          total: Number(total || 0),
          label: nameByUserId[uid] || `User #${uid}`,
        }))
        .filter(row => row.total > 0)
        .sort((a, b) => b.total - a.total || a.label.localeCompare(b.label));

      const fallbackOthers = Object.entries(fallbackTotalsByName)
        .map(([label, total]) => ({ label, total: Number(total || 0) }))
        .filter(row => row.total > 0)
        .sort((a, b) => b.total - a.total || a.label.localeCompare(b.label));

      const esc = (str) => String(str || '').replace(/[&<>"']/g, match => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
      })[match]);

      function generateAvatarGroup(uid, fallbackName) {
        const profiles = userProfiles[uid];
        let avatarHtml = '';
        if (profiles && profiles.length > 0) {
          avatarHtml = '<div class="elapsedTimesAvatars">';
          profiles.forEach((p, index) => {
            const stacked = index > 0 ? ' is-stacked' : '';
            if (p.photo) {
              avatarHtml += `<div class="elapsedTimesAvatar${stacked}" title="${esc(p.name)}"><img src="${esc(p.photo)}" alt="" class="elapsedTimesAvatarImg"></div>`;
            } else {
              avatarHtml += `<div class="elapsedTimesAvatar elapsedTimesAvatarInitial${stacked}" title="${esc(p.name)}"><span>${esc(p.initials)}</span></div>`;
            }
          });
          avatarHtml += '</div>';
        } else {
          const initials = (fallbackName || 'U').substring(0, 1).toUpperCase();
          avatarHtml = `<div class="elapsedTimesAvatars"><div class="elapsedTimesAvatar elapsedTimesAvatarInitial" title="${esc(fallbackName)}"><span>${initials}</span></div></div>`;
        }
        return avatarHtml;
      }

      const lines = [];
      if (myTotalWithLive > 0) {
        const myLabel = liveMyTotal > 0 ? 'My elapsed time (live)' : 'My elapsed time';
        const myUserId = Number(window.__CTX__?.actualUserId || 0);
        lines.push(`<div class="elapsedTimesRow">${generateAvatarGroup(myUserId, 'Me')}<div class="elapsedTimesMeta"><strong class="elapsedTimesName">${myLabel}</strong><span class="elapsedTimesValue">${formatWorkingTime(myTotalWithLive)}</span></div></div>`);
      }
      others.forEach(row => {
        lines.push(`<div class="elapsedTimesRow">${generateAvatarGroup(row.uid, row.label)}<div class="elapsedTimesMeta"><strong class="elapsedTimesName">${esc(row.label)}</strong><span class="elapsedTimesValue">${formatWorkingTime(row.total)}</span></div></div>`);
      });
      fallbackOthers.forEach(row => {
        lines.push(`<div class="elapsedTimesRow">${generateAvatarGroup(null, row.label)}<div class="elapsedTimesMeta"><strong class="elapsedTimesName">${esc(row.label)}</strong><span class="elapsedTimesValue">${formatWorkingTime(row.total)}</span></div></div>`);
      });

      if (lines.length === 0) {
        container.innerHTML = '<span class="mini" style="opacity:0.7;">No action times recorded yet.</span>';
      } else {
        container.innerHTML = lines.join('');
      }
    }

    document.addEventListener('click', (e) => {
      const row = e.target.closest('tr[data-doc]');
      if (row) {
        const container = document.getElementById('d_elapsed_times');
        if (container) container.innerHTML = '<span class="mini" style="opacity:0.7;">Calculating...</span>';
      }
    });
  })();
</script>

<?php $pageScripts = [asset_url("assets/js/documents-page.js")]; ?>
<?php require __DIR__ . "/../includes/footer.php"; ?>
