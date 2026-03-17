<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

/* ✅ Division-aware sections */
$sections = $conn->query("
  SELECT s.id, s.name, d.name AS division_name
  FROM sections s
  JOIN divisions d ON d.id = s.division_id
  WHERE s.is_active = 1 AND d.is_active = 1
  ORDER BY d.name ASC, s.name ASC
")->fetch_all(MYSQLI_ASSOC);

$branchMode = workflow_branch_mode_enabled($conn);
$routePersonalDeadlineEnabled = workflow_has_column($conn, 'routes', 'personal_deadline_at');

$pageTitle = "Documents - Document Tracker";
require __DIR__ . "/../includes/layout.php";
?>

<script>
  window.__CSRF__ = "<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>";

  window.__CTX__ = {
    myUserId: <?= (int)($_SESSION["user_id"] ?? 0) ?>,
    mySectionId: <?= (int)($_SESSION["section_id"] ?? 0) ?>,
    myRole: "<?= htmlspecialchars($_SESSION["role"] ?? "user") ?>",
    isChief: <?= ((int)($_SESSION["is_chief"] ?? 0) === 1) ? "true" : "false" ?>,
    myDivisionName: "<?= htmlspecialchars($_SESSION["division_name"] ?? "") ?>",
    isPPD: <?= (stripos((string)($_SESSION["division_name"] ?? ""), "Planning") !== false && stripos((string)($_SESSION["division_name"] ?? ""), "Programming") !== false) ? "true" : "false" ?>,
    branchMode: <?= $branchMode ? "true" : "false" ?>
  };

  window.__SECTIONS__ = <?= json_encode($sections, JSON_UNESCAPED_UNICODE) ?>;
</script>

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
$myUserId    = (int)($_SESSION["user_id"] ?? 0);
$mySectionId = (int)($_SESSION["section_id"] ?? 0);
$isChief     = ((int)($_SESSION["is_chief"] ?? 0) === 1);

$where  = [];
$params = [];
$types  = "";

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

/**
 * Filters
 */
if ($search !== "") {
  $where[] = "(d.tracking_no LIKE ? OR d.requester LIKE ? OR d.subject LIKE ? OR d.content_type LIKE ? OR sh.name LIKE ?)";
  $like = "%" . $search . "%";
  array_push($params, $like, $like, $like, $like, $like);
  $types .= "sssss";
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
// Quick filters (from stat cards)
// -------------------------
if ($quick !== "") {
  if ($quick === "active") {
    $where[] = "d.current_status = 'ACTIVE'";
  } elseif ($quick === "archived") {
    $where[] = "d.current_status = 'ARCHIVED'";
  } elseif ($quick === "released_today") {
    $where[] = "d.current_status = 'RELEASED' AND DATE(d.updated_at) = CURDATE()";
  } elseif ($quick === "overdue") {
    $where[] = "d.current_status = 'ACTIVE' AND d.deadline_at IS NOT NULL AND d.deadline_at < NOW()";
  }
}
// -------------------------
// COUNT query for pagination
// (Use same WHERE + joins needed by filters)
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

// -------------------------
// Main list query (NEW SCHEMA)
// -------------------------
$mySid = (int)$mySectionId;
$myUid = (int)$myUserId;
$myChiefInt = $isChief ? 1 : 0;
$branchModeInt = $branchMode ? 1 : 0;

$personalDeadlineSelectSql = "NULL AS my_personal_deadline_at";
$personalDeadlineJoinSql = "";
$effectiveDeadlineOrderExpr = "d.deadline_at";

if ($routePersonalDeadlineEnabled) {
  $effectiveDeadlineOrderExpr = "COALESCE(rpd_me.personal_deadline_at, d.deadline_at)";
  $personalDeadlineSelectSql = "rpd_me.personal_deadline_at AS my_personal_deadline_at";

  if ($branchMode) {
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

$sql = "
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

    {$personalDeadlineSelectSql},

    rr_latest.latest_route_remark,
    rr_latest.latest_remark_sent_by_user_id,
    rr_latest.latest_remark_from_user_id,
    rr_latest.latest_remark_to_user_id,
    rr_latest.latest_remark_received_by_user_id,

    -- last holder (fallback when not in transit)
    sf_last.name AS last_holder_name,

    TIMESTAMPDIFF(DAY, d.updated_at, NOW()) AS days_stuck,

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
          AND NOT EXISTS (
            SELECT 1
            FROM routes r_act
            WHERE r_act.branch_id = b_act2.id
              AND r_act.route_kind = 'ACTION'
              AND r_act.received_at IS NULL
              AND r_act.cancelled_at IS NULL
          )
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
          AND r_act_legacy.received_at IS NULL
          AND r_act_legacy.cancelled_at IS NULL
      ) THEN 1

      ELSE 0
    END AS my_has_actionable_role,

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
      ) AND EXISTS (
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

  LEFT JOIN (
    SELECT
      r.document_id,
      MIN(r.id) AS any_open_route_id,
      COUNT(*) AS open_count
    FROM routes r
    WHERE r.received_at IS NULL AND r.cancelled_at IS NULL
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
    ON r_last.document_id = d.id
   AND r_last.received_at = (
      SELECT MAX(r2.received_at)
      FROM routes r2
      WHERE r2.document_id = d.id AND r2.received_at IS NOT NULL
   )
  LEFT JOIN sections sf_last ON sf_last.id = r_last.from_section_id
";

if ($where) $sql .= " WHERE " . implode(" AND ", $where);

$orderBySql = "
  ORDER BY
    CASE
      WHEN d.current_status='ACTIVE'
       AND (r_open.id IS NULL)
       AND d.current_holder_section_id = {$mySid}
      THEN 0

      WHEN d.current_status='ACTIVE'
       AND (r_open.id IS NOT NULL)
       AND r_open.to_section_id = {$mySid}
      THEN 1

      WHEN d.current_status='ACTIVE'
       AND (r_open.id IS NOT NULL)
      THEN 2

      WHEN d.current_status='ACTIVE' THEN 3
      WHEN d.current_status='RELEASED' THEN 4
      WHEN d.current_status='ARCHIVED' THEN 5
      ELSE 9
    END ASC,

    TIMESTAMPDIFF(DAY, d.updated_at, NOW()) DESC,
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
      CASE WHEN {$effectiveDeadlineOrderExpr} IS NULL THEN 1 ELSE 0 END ASC,
      {$effectiveDeadlineOrderExpr} ASC,
      d.document_date DESC,
      d.id DESC
  ";
} elseif ($sort === "overdue_longest") {
  $orderBySql = "
    ORDER BY
      CASE WHEN d.deadline_at IS NOT NULL AND d.deadline_at < NOW() THEN 0 ELSE 1 END ASC,
      CASE WHEN {$effectiveDeadlineOrderExpr} IS NULL THEN 1 ELSE 0 END ASC,
      {$effectiveDeadlineOrderExpr} ASC,
      d.document_date DESC,
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

$statSql = "
  SELECT
    SUM(d.current_status='ACTIVE') AS active,
    SUM(d.current_status='ARCHIVED') AS archived,
    SUM(d.current_status='RELEASED' AND DATE(d.updated_at)=CURDATE()) AS released_today,
    SUM(d.current_status='ACTIVE' AND d.deadline_at IS NOT NULL AND d.deadline_at < NOW()) AS overdue
  FROM documents d
";
if ($statWhere) $statSql .= " WHERE " . implode(" AND ", $statWhere);

$statStmt = $conn->prepare($statSql);
if ($statParams) $statStmt->bind_param($statTypes, ...$statParams);
$statStmt->execute();
$statRows = $statStmt->get_result()->fetch_assoc();

$stats = [
  "active" => (int)($statRows["active"] ?? 0),
  "archived" => (int)($statRows["archived"] ?? 0),
  "overdue" => (int)($statRows["overdue"] ?? 0),
  "released_today" => (int)($statRows["released_today"] ?? 0),
];

// Helper for pagination URLs (preserve current filters)
function pageUrl(int $p): string {
  $q = $_GET;
  $q["page"] = $p;
  return PUBLIC_PATH . "/documents.php?" . http_build_query($q);
}

function quickUrl(string $target): string {
  $q = $_GET;

  // ✅ toggle off if the same card is clicked
  if (strtolower(trim($q["quick"] ?? "")) === $target) {
    unset($q["quick"]);
  } else {
    $q["quick"] = $target;
  }

  $q["page"] = 1; // reset pagination when filtering/toggling
  return PUBLIC_PATH . "/documents.php?" . http_build_query($q);
}
?>

<div class="docsPageShell">
  <section class="docsHero">
    <div class="docsHeroCopy">
      <div class="docsEyebrow">Workflow dashboard</div>
      <h1 class="docsTitle">Document List</h1>
      <p class="docsLead">Track movement, spot pending work fast, and open any row for complete routing details.</p>
    </div>

    <div class="docsHeroActions">
      <div class="docsSummaryPill">
        <span class="docsSummaryValue"><?= (int)$total ?></span>
        <span class="docsSummaryLabel">visible documents</span>
      </div>

      <a href="<?= PUBLIC_PATH ?>/add_document.php" class="btnComp docsAddBtn" style="text-decoration:none;">
        + Add Document
      </a>
    </div>
  </section>

  <div class="stats docsStatsGrid">
    <a class="statCard statCardLink docsStatCard <?= $quick === 'active' ? 'isActive' : '' ?>"
       href="<?= htmlspecialchars(quickUrl('active')) ?>">
      <div class="docsStatHeader">
        <div>
          <div class="statTitle">Active</div>
          <div class="docsStatHint">Still moving in workflow</div>
        </div>
        <div class="chip incoming">Ongoing</div>
      </div>
      <div class="statValue"><?= $stats["active"] ?></div>
    </a>

    <a class="statCard statCardLink docsStatCard <?= $quick === 'overdue' ? 'isActive' : '' ?>"
       href="<?= htmlspecialchars(quickUrl('overdue')) ?>">
      <div class="docsStatHeader">
        <div>
          <div class="statTitle">Overdue</div>
          <div class="docsStatHint">Needs attention soonest</div>
        </div>
        <div class="chip overdue">Deadline passed</div>
      </div>
      <div class="statValue"><?= $stats["overdue"] ?></div>
    </a>

    <a class="statCard statCardLink docsStatCard <?= $quick === 'released_today' ? 'isActive' : '' ?>"
       href="<?= htmlspecialchars(quickUrl('released_today')) ?>">
      <div class="docsStatHeader">
        <div>
          <div class="statTitle">Released Today</div>
          <div class="docsStatHint">Closed out today</div>
        </div>
        <div class="chip released">Done</div>
      </div>
      <div class="statValue"><?= $stats["released_today"] ?></div>
    </a>

    <a class="statCard statCardLink docsStatCard <?= $quick === 'archived' ? 'isActive' : '' ?>"
       href="<?= htmlspecialchars(quickUrl('archived')) ?>">
      <div class="docsStatHeader">
        <div>
          <div class="statTitle">Archived</div>
          <div class="docsStatHint">Filed records</div>
        </div>
        <div class="chip archived">Filed</div>
      </div>
      <div class="statValue"><?= $stats["archived"] ?></div>
    </a>
  </div>

  <section class="docsControlsCard">
    <div class="docsControlsTop">
      <div>
        <div class="docsSectionTitle">Find documents quickly</div>
        <div class="docsSectionSub">Search, filter, and sort without leaving the workflow view.</div>
      </div>

      <?php if ($search !== "" || $statusGet !== "" || $date_from !== "" || $date_to !== "" || $quick !== "" || ($sort !== "" && $sort !== "workflow")): ?>
        <a class="docsClearFilters" href="<?= PUBLIC_PATH ?>/documents.php">Reset filters</a>
      <?php endif; ?>
    </div>

    <div class="docsControlsGrid">
      <form class="toolbar toolbarSearch docsToolbarSearch" method="GET" action="<?= PUBLIC_PATH ?>/documents.php">
        <input type="hidden" name="status" value="<?= htmlspecialchars($statusGet) ?>">
        <input type="hidden" name="from" value="<?= htmlspecialchars($date_from) ?>">
        <input type="hidden" name="to" value="<?= htmlspecialchars($date_to) ?>">
        <input type="hidden" name="quick" value="<?= htmlspecialchars($quick) ?>">

        <div class="control docsSearchControl">
          <label>Search documents</label>
          <input class="search" type="text" name="q"
                 placeholder="Tracking no, subject, requester, holder..."
                 value="<?= htmlspecialchars($search) ?>">
        </div>

        <div class="control docsSortControl">
          <label>Sort</label>
          <select class="select" name="sort">
            <option value="workflow" <?= ($sort === "" || $sort === "workflow") ? "selected" : "" ?>>Workflow priority</option>
            <option value="newest" <?= $sort === "newest" ? "selected" : "" ?>>Newest first</option>
            <option value="urgent" <?= $sort === "urgent" ? "selected" : "" ?>>Most urgent first</option>
            <option value="overdue_longest" <?= $sort === "overdue_longest" ? "selected" : "" ?>>Longest overdue first</option>
            <option value="oldest" <?= $sort === "oldest" ? "selected" : "" ?>>Oldest first</option>
          </select>
        </div>

        <button type="submit" class="btnSecondary docsControlBtn">Search</button>
      </form>

      <form class="toolbar toolbarFilters docsToolbarFilters" method="GET" action="<?= PUBLIC_PATH ?>/documents.php">
        <input type="hidden" name="quick" value="<?= htmlspecialchars($quick) ?>">
        <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">

        <div class="control">
          <label>Status</label>
          <select class="select" name="status">
            <option value="">All</option>
            <option value="ACTIVE" <?= strtoupper($statusGet) === "ACTIVE" ? "selected" : "" ?>>ACTIVE</option>
            <option value="RELEASED" <?= strtoupper($statusGet) === "RELEASED" ? "selected" : "" ?>>RELEASED</option>
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
    </div>
  </section>

  <div class="tableWrap docsTableWrap">
    <div class="docsTableTopbar">
      <div>
        <div class="docsSectionTitle">Documents in view</div>
        <div class="docsSectionSub">Click any row to open the drawer and see full routing history.</div>
      </div>
      <div class="docsResultsMeta">
        Showing <b><?= (int)($total ? $offset + 1 : 0) ?></b>–<b><?= (int)min($offset + $perPage, $total) ?></b> of <b><?= (int)$total ?></b>
      </div>
    </div>

    <table class="docTable docsTableModern">
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

            $docDeadlineRaw = trim((string)($d["deadline_at"] ?? ""));
            $myPersonalDeadlineRaw = trim((string)($d["my_personal_deadline_at"] ?? ""));
            $hasPersonalDeadline = ($myPersonalDeadlineRaw !== "");
            $effectiveDeadlineRaw = $hasPersonalDeadline ? $myPersonalDeadlineRaw : $docDeadlineRaw;
            $effectiveDeadlineTs = $effectiveDeadlineRaw !== "" ? strtotime($effectiveDeadlineRaw) : false;
            $docDeadlineText = $docDeadlineRaw !== "" ? date("M d, Y g:i A", strtotime($docDeadlineRaw)) : "—";
            $personalDeadlineText = $myPersonalDeadlineRaw !== "" ? date("M d, Y g:i A", strtotime($myPersonalDeadlineRaw)) : "—";
            $deadlineBadgeText = "NO DEADLINE";
            $deadlineBadgeClass = "neutral";
            $deadlineToneClass = "";
            $deadlineMetaLines = [];
            $deadlineSortTs = $effectiveDeadlineTs ? (int)$effectiveDeadlineTs : 0;

            if ($hasPersonalDeadline) {
              $deadlineMetaLines[] = "Your deadline: " . $personalDeadlineText;
            }
            $deadlineMetaLines[] = "Document: " . $docDeadlineText;

            if ($effectiveDeadlineTs !== false) {
              $secondsLeft = $effectiveDeadlineTs - time();
              $daysLeft = (int)floor($secondsLeft / 86400);
              $hoursLeft = (int)floor(abs($secondsLeft) / 3600);

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

            $openCount = (int)($d["open_route_count"] ?? 0);
            $inTransit = ($openCount > 0);
            $currentStatus = strtoupper((string)($d["current_status"] ?? "ACTIVE"));
            $hasRealBranches = ((int)($d["has_real_branches"] ?? 0) === 1);

            $myHasOpenInbound = ((int)($d["my_has_open_inbound"] ?? 0) === 1);
            $myHasActionableRole = ((int)($d["my_has_actionable_role"] ?? 0) === 1);
            $myHasParticipation = ((int)($d["my_has_participation"] ?? 0) === 1);
            $myIsVisibleOnly = ((int)($d["my_is_visible_only"] ?? 0) === 1);
            $myIsOrigin = ((int)($d["my_is_origin"] ?? 0) === 1);
            $myIsForReference = ((int)($d["my_is_for_reference"] ?? 0) === 1);
            $myIsReceiveOnly = ((int)($d["my_is_receive_only"] ?? 0) === 1);

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
              $docStateLabel = "RELEASED";
              $docStateToneClass = "isReleased";
              $drawerStatusChipClass = "chip released";
              $docStateHint = "Completed routing";
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
                (int)($d["latest_remark_sent_by_user_id"] ?? 0) === $myUserId
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
            $docMetaType = $docTypeText !== "" && $docCommText !== ""
              ? $docTypeText . " • " . $docCommText
              : ($docTypeText !== "" ? $docTypeText : ($docCommText !== "" ? $docCommText : "—"));
          ?>
          <tr
            class="rowHover docsRow <?= htmlspecialchars(trim($rowToneClass . " " . $deadlineToneClass)) ?>"
            data-doc='<?= htmlspecialchars(
              json_encode([
                "id" => (int)$d["id"],
                "tracking_no" => $d["tracking_no"],
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

                "status_label" => $docStateLabel,
                "status_chip_class" => $drawerStatusChipClass,
                "my_status" => $myStatusLabel,
                "my_status_chip_class" => $myStatusChipClass,
                "is_origin" => $myIsOrigin ? 1 : 0,
                "is_for_reference" => $myIsForReference ? 1 : 0,
                "is_receive_only" => $myIsReceiveOnly ? 1 : 0,
                "is_visible_only" => $myIsVisibleOnly ? 1 : 0,
                "has_real_branches" => $hasRealBranches ? 1 : 0,

                "in_transit" => !empty($d["open_to_section_id"]) ? 1 : 0,
                "open_to_section_id" => (int)($d["open_to_section_id"] ?? 0),
                "open_to_user_id" => (int)($d["open_to_user_id"] ?? 0),
                "open_to_user_name" => (string)($d["open_to_user_name"] ?? ""),
                "open_to_section_name" => (string)($d["open_to_section_name"] ?? ""),
                "open_from_section_name" => (string)($d["open_from_section_name"] ?? ""),
                "open_from_section_id" => (int)($d["open_from_section_id"] ?? 0),
                "open_route_id" => (int)($d["any_open_route_id"] ?? 0),
                "open_route_count" => $openCount,

                "movement_text" => $movementText,
                "current_holder_text" => $currentHolderText,
                "last_holder_text" => $lastHolderText,

                "current_holder_section_id" => (int)($d["current_holder_section_id"] ?? 0),
                "current_status" => (string)($d["current_status"] ?? "ACTIVE"),
                "days_stuck" => $days,
              ], JSON_UNESCAPED_UNICODE),
              ENT_QUOTES,
              "UTF-8"
            ) ?>'
          >
            <td>
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

            <td>
              <div class="docInfoCell">
                <div class="docInfoTitle"><?= htmlspecialchars((string)$d["subject"]) ?></div>
                <div class="docInfoMeta">
                  <span class="docInfoTracking"><?= htmlspecialchars((string)$d["tracking_no"]) ?></span>
                  <span class="docMetaDot">•</span>
                  <span><?= htmlspecialchars($docMetaType) ?></span>
                  <span class="docMetaDot">•</span>
                  <span><?= htmlspecialchars((string)$d["document_date"]) ?></span>
                </div>
              </div>
            </td>

            <td>
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

            <td>
              <div class="docStateCell">
                <div class="docStateMain <?= htmlspecialchars($docStateToneClass) ?>">
                  <span class="docStateDot"></span>
                  <span class="docStateText"><?= htmlspecialchars($docStateLabel) ?></span>
                </div>
                <div class="docStateHint"><?= htmlspecialchars($docStateHint) ?></div>
              </div>
            </td>

            <td>
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

            <td>
              <div class="deadlineCell <?= htmlspecialchars($deadlineBadgeClass) ?>">
                <div class="deadlineBadge <?= htmlspecialchars($deadlineBadgeClass) ?>"><?= htmlspecialchars($deadlineBadgeText) ?></div>
                <?php foreach ($deadlineMetaLines as $deadlineMetaLine): ?>
                  <div class="deadlineMetaLine"><?= htmlspecialchars($deadlineMetaLine) ?></div>
                <?php endforeach; ?>
              </div>
            </td>

            <td>
              <div class="requesterCell">
                <div class="requesterName"><?= htmlspecialchars((string)$d["requester"]) ?></div>
                <div class="requesterMeta">Days stuck: <?= (int)$days ?></div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

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
    <div>
      <h3 class="drawerTitle">Document Details</h3>
      <div class="drawerSub">Tracking: <b id="d_tracking"></b></div>
    </div>
    <button id="drawerClose" class="drawerClose">✕</button>
  </div>

  <div class="drawerBody">
    <input type="hidden" id="d_id" value="">

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
    <div class="kv"><div class="k">Days stuck</div><div class="v" id="d_days"></div></div>
    <div class="kv"><div class="k">Full Document</div><div class="v"><button type="button" class="btnComp" id="btnViewDocument">View document</button></div></div>
    <div class="drawerRow" id="rowPpdSlip" style="display:none;">
      <div class="k">PPD Tracking Slip</div>
      <div class="v" style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
        <button type="button" class="btnSecondary" id="btnPpdSlipGenerate">Generate</button>
        <button type="button" class="btnSecondary" id="btnPpdSlipAttach">Attach</button>
        <button type="button" class="btnComp" id="btnPpdSlipPrint" disabled>Print</button>
      </div>
    </div>

    <!-- Attachments -->
    <div style="margin-top:14px;">
      <div class="k" style="margin-bottom:8px; display:flex; align-items:center; gap:8px; justify-content:space-between;">
        <div style="display:flex; align-items:center; gap:8px;">
          <span>Attachments</span>
        </div>
      </div>

      <div class="drawerSectionActions">
        <button type="button" class="btnSecondary" id="btnToggleAttachments">View all</button>
        <button type="button" class="btnSecondary" id="btnToggleUpload">Add attachment</button>
      </div>

      <div id="d_attachments" class="attachList mini collapsed"></div>

      <form id="attachForm" class="attachForm collapsed" enctype="multipart/form-data">
        <input type="file" id="attachFile" name="file" required accept=".pdf,.jpg,.jpeg,.png" />
        <div style="display:flex; gap:8px; margin-top:8px;">
          <input type="hidden" id="attachType" value="1" />
          <input id="attachNote" type="text" class="search" style="flex:1;" placeholder="Note (optional)" />
        </div>

        <button id="btnAttachUpload" type="button" class="btnPrimary" style="margin-top:10px;">Upload</button>
        <div id="attachMsg" class="mini" style="margin-top:6px;"></div>
      </form>
    </div>

    <div class="drawerBranchWrap" id="d_branch_wrap" style="display:none;">
      <div class="k" style="margin-bottom:8px; display:flex; align-items:center; gap:8px;">
        <span>Branches</span>
        <span class="mini" id="d_branch_hint" style="opacity:.7;">Select a branch to act on.</span>
      </div>
      <div id="d_branch_bar" class="branchBar"></div>
      <div id="d_branch_meta" class="branchMeta mini"></div>
    </div>

    <div style="margin-top:14px;">
      <div class="k" style="margin-bottom:8px; display:flex; align-items:center; gap:8px;">
        <span>Timeline</span>
        <span class="mini" style="opacity:.7;">(latest on top)</span>
      </div>
      <div id="d_timeline" class="mini">Select a document…</div>
    </div>
  </div>

  <div class="drawerActionsWrap">
    <div class="drawerActionRemarks">
      <label for="d_action_remarks" class="drawerActionRemarksLabel">Remarks (optional)</label>
      <textarea id="d_action_remarks" class="search drawerActionRemarksInput" rows="2" placeholder="Add remarks for this action only if needed"></textarea>
    </div>

    <div class="drawerActions">
      <button type="button" class="btnSecondary" id="btnToggleForward">Forward</button>

      <button id="btnAckReceived" class="btnGreen" type="button" style="display:none;">Received</button>
      <button id="btnRelease" class="btnGreen" type="button" style="display:none;">Release</button>
      <button id="btnArchive" class="btnComp" type="button" style="display:none;">Archive</button>
    </div>
  </div>

  <div id="forwardBox" class="collapsed" style="margin-top:10px;">
    <label style="font-size:12px; font-weight:900;">Forward To</label>

    <select id="f_to_section" class="select" style="min-width:100%;">
      <option value="">-- Select section --</option>
    </select>

    <label style="font-size:12px; font-weight:900; margin-top:10px; display:block;">Recipients</label>

    <div class="forwardUserTools" style="display:flex; gap:8px; margin:6px 0 8px;">
      <button type="button" class="btnSecondary" id="btnUserSelectAll" style="padding:6px 10px;">Select all</button>
      <button type="button" class="btnSecondary" id="btnUserClear" style="padding:6px 10px;">Clear</button>
    </div>

    <div id="f_user_list" class="userChecklist mini"
        style="border:1px solid rgba(0,0,0,.12); border-radius:12px; padding:10px; max-height:170px; overflow:auto;">
      <div style="opacity:.7;">Select a section to load users…</div>
    </div>

    <div id="forwardRecipientsPreview" class="mini" style="opacity:.75; margin-top:6px;">
      Recipients: —
    </div>

    <div id="forwardModeWrap" style="margin-top:10px; padding:10px; border:1px solid rgba(0,0,0,.10); border-radius:12px; background:#f8fafc;">
      <label style="display:flex; gap:8px; align-items:flex-start; cursor:pointer;">
        <input id="f_receive_only" type="checkbox" style="margin-top:3px;">
        <span>
          <span style="display:block; font-size:12px; font-weight:900;">Forward as receive-only</span>
          <span class="mini" id="f_receive_only_hint" style="opacity:.75;">
            Recipient can acknowledge receive, but cannot forward or act further.
          </span>
        </span>
      </label>
    </div>

    <div id="forwardPersonalDeadlineWrap" class="forwardDeadlineWrap" style="display:none; margin-top:10px;">
      <label for="f_personal_deadline" style="font-size:12px; font-weight:900; display:block; margin-bottom:6px;">Personal deadline</label>
      <input id="f_personal_deadline" type="datetime-local" class="search" style="width:100%;">
      <div class="mini" style="margin-top:6px; opacity:.75;">Only section chiefs can set a personal deadline for the selected recipient(s).</div>
    </div>

    <button id="btnForward" type="button" class="btnSecondary" style="margin-top:10px; margin-bottom:10px; margin-left:10px; display:none;">
      Forward
    </button>
  </div>
</aside>

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

<?php require __DIR__ . "/../includes/footer.php"; ?>