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

$pageTitle = "Documents - Document Tracker";
require __DIR__ . "/../includes/layout.php";
?>

<script>
  window.__CSRF__ = "<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>";

  window.__CTX__ = {
    mySectionId: <?= (int)($_SESSION["section_id"] ?? 0) ?>,
    myRole: "<?= htmlspecialchars($_SESSION["role"] ?? "division") ?>",
    myDivisionName: "<?= htmlspecialchars($_SESSION["division_name"] ?? "") ?>",
    isPPD: <?= (stripos((string)($_SESSION["division_name"] ?? ""), "Planning") !== false && stripos((string)($_SESSION["division_name"] ?? ""), "Programming") !== false) ? "true" : "false" ?>
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

// Pagination
$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;

$perPage = 15;   // ✅ fixed, unchangeable

$offset = ($page - 1) * $perPage;

$role        = $_SESSION["role"] ?? "division";
$mySectionId = (int)($_SESSION["section_id"] ?? 0);

$where  = [];
$params = [];
$types  = "";

/**
 * ✅ VISIBILITY RULE (NEW SCHEMA)
 * Records + admin see ALL.
 * Others see docs if:
 *  1) current holder
 *  2) pending recipient (open route)
 *  3) participant
 */
$isPrivileged = in_array($role, ["admin", "records"], true);

if (!$isPrivileged) {
  if ($mySectionId <= 0) {
    $where[] = "1=0";
  } else {
    $where[] = "(
      d.current_holder_section_id = ?
      OR EXISTS (
        SELECT 1
        FROM routes r
        WHERE r.document_id = d.id
          AND r.received_at IS NULL
          AND r.cancelled_at IS NULL
          AND r.to_section_id = ?
      )
      OR EXISTS (
        SELECT 1
        FROM document_participants p
        WHERE p.document_id = d.id
          AND p.section_id = ?
      )
    )";
    array_push($params, $mySectionId, $mySectionId, $mySectionId);
    $types .= "iii";
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
    $where[] = "d.current_status = 'ACTIVE' AND TIMESTAMPDIFF(DAY, d.updated_at, NOW()) >= 7";
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

$sql = "
  SELECT
    d.id,
    d.tracking_no,
    d.requester,
    d.document_date,
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

    COALESCE(ro.open_count, 0) AS open_route_count,

    -- last holder (fallback when not in transit)
    sf_last.name AS last_holder_name,

    TIMESTAMPDIFF(DAY, d.updated_at, NOW()) AS days_stuck
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

$sql .= "
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
  if ($mySectionId <= 0) {
    $statWhere[] = "1=0";
  } else {
    $statWhere[] = "(
      d.current_holder_section_id = ?
      OR EXISTS (
        SELECT 1
        FROM routes r
        WHERE r.document_id = d.id
          AND r.received_at IS NULL
          AND r.cancelled_at IS NULL
          AND r.to_section_id = ?
      )
      OR EXISTS (
        SELECT 1
        FROM document_participants p
        WHERE p.document_id = d.id
          AND p.section_id = ?
      )
    )";
    array_push($statParams, $mySectionId, $mySectionId, $mySectionId);
    $statTypes .= "iii";
  }
}

$statSql = "
  SELECT
    SUM(d.current_status='ACTIVE') AS active,
    SUM(d.current_status='ARCHIVED') AS archived,
    SUM(d.current_status='RELEASED' AND DATE(d.updated_at)=CURDATE()) AS released_today,
    SUM(d.current_status='ACTIVE' AND TIMESTAMPDIFF(DAY, d.updated_at, NOW()) >= 7) AS overdue
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

<div style="display:flex;justify-content:space-between;align-items:center;">
  <h1>Document List</h1>
  <a href="<?= PUBLIC_PATH ?>/add_document.php" class="btnComp" style="text-decoration:none;">
    + Add Document
  </a>
</div>

<div class="stats">
  <a class="statCard statCardLink <?= $quick === 'active' ? 'isActive' : '' ?>"
     href="<?= htmlspecialchars(quickUrl('active')) ?>">
    <div class="statTop">
      <div class="statTitle">Active</div>
      <div class="chip incoming">Ongoing</div>
    </div>
    <div class="statValue"><?= $stats["active"] ?></div>
  </a>

  <a class="statCard statCardLink <?= $quick === 'overdue' ? 'isActive' : '' ?>"
     href="<?= htmlspecialchars(quickUrl('overdue')) ?>">
    <div class="statTop">
      <div class="statTitle">Overdue</div>
      <div class="chip overdue">Stuck ≥ 7d</div>
    </div>
    <div class="statValue"><?= $stats["overdue"] ?></div>
  </a>

  <a class="statCard statCardLink <?= $quick === 'released_today' ? 'isActive' : '' ?>"
     href="<?= htmlspecialchars(quickUrl('released_today')) ?>">
    <div class="statTop">
      <div class="statTitle">Released Today</div>
      <div class="chip released">Done</div>
    </div>
    <div class="statValue"><?= $stats["released_today"] ?></div>
  </a>

  <a class="statCard statCardLink <?= $quick === 'archived' ? 'isActive' : '' ?>"
     href="<?= htmlspecialchars(quickUrl('archived')) ?>">
    <div class="statTop">
      <div class="statTitle">Archived</div>
      <div class="chip archived">Filed</div>
    </div>
    <div class="statValue"><?= $stats["archived"] ?></div>
  </a>
</div>

<!-- ✅ Split toolbar into 2 forms (predictable + modern) -->
<div class="toolbarWrap">
  <!-- Filters -->
  <form class="toolbar toolbarFilters" method="GET" action="<?= PUBLIC_PATH ?>/documents.php">
     <!-- ✅ preserve quick card filter -->
    <input type="hidden" name="quick" value="<?= htmlspecialchars($quick) ?>">

    <!-- (optional but recommended) preserve search too, if filters form doesn't include it -->
    <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">

    <div class="control">
      <label>Status</label>
      <select class="select" name="status">
        <option value="">All</option>
        <option value="ACTIVE" <?= strtoupper($statusGet)==="ACTIVE" ? "selected" : "" ?>>ACTIVE</option>
        <option value="RELEASED" <?= strtoupper($statusGet)==="RELEASED" ? "selected" : "" ?>>RELEASED</option>
        <option value="ARCHIVED" <?= strtoupper($statusGet)==="ARCHIVED" ? "selected" : "" ?>>ARCHIVED</option>
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

    <button type="submit" class="btnSecondary">Apply</button>
  </form>

  <!-- Search -->
  <form class="toolbar toolbarSearch" method="GET" action="<?= PUBLIC_PATH ?>/documents.php">
    <input type="hidden" name="status" value="<?= htmlspecialchars($statusGet) ?>">
    <input type="hidden" name="from" value="<?= htmlspecialchars($date_from) ?>">
    <input type="hidden" name="to" value="<?= htmlspecialchars($date_to) ?>">

    <div class="control">
      <label>Search Documents</label>
      <input class="search" type="text" name="q" style="margin-bottom:0px"
             placeholder="Search tracking no, requester, subject, holder..."
             value="<?= htmlspecialchars($search) ?>">
    </div>

    <button type="submit" class="btnSecondary">Search</button>
  </form>
</div>

<div class="tableWrap">
  <table class="docTable">
    <thead>
      <tr>
        <th>Status</th>
        <th>Current Holder</th>
        <th>Tracking No.</th>
        <th>Subject</th>
        <th>Destination</th>
        <th>Days</th>
        <th>Requester</th>
        <th>Document Date</th>
        <th>Type</th>
        <th>Last Holder</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$docs): ?>
        <tr>
          <td colspan="10" class="mini" style="padding:18px;">No documents found.</td>
        </tr>
      <?php endif; ?>

      <?php foreach ($docs as $d): ?>
        <?php
          $days = (int)($d["days_stuck"] ?? 0);
          $warn = ($days >= 3 && $days < 7) ? "warn" : "";
          $danger = ($days >= 7) ? "danger" : "";

          $inTransit = !empty($d["open_to_section_id"]);
          $currentStatus = strtoupper((string)($d["current_status"] ?? "ACTIVE"));

          $statusLabel = "—";
          $statusChipClass = "chip incoming";

          if ($currentStatus === "ARCHIVED") {
            $statusLabel = "ARCHIVED";
            $statusChipClass = "chip archived";
          } elseif ($currentStatus === "RELEASED") {
            $statusLabel = "RELEASED";
            $statusChipClass = "chip released";
          } else {
            if ($inTransit) {
              $isIncomingToMe = ((int)($d["open_to_section_id"] ?? 0) === $mySectionId);
              $statusLabel = $isIncomingToMe ? "IN TRANSIT (TO YOU)" : "IN TRANSIT";
              $statusChipClass = "chip action";
            } else {
              $isMine = ((int)($d["current_holder_section_id"] ?? 0) === $mySectionId);
              $statusLabel = $isMine ? "NEEDS ACTION" : "ACTIVE";
              $statusChipClass = $isMine ? "chip overdue" : "chip incoming";
            }
          }

          $openCount = (int)($d["open_route_count"] ?? 0);

          $movementText = $inTransit
            ? (($openCount > 1)
                ? ("Multiple recipients (" . $openCount . ")")
                : (string)($d["open_to_section_name"] ?? "—"))
            : "—";

          $currentHolderText = $inTransit ? "—" : (string)($d["current_holder_name"] ?? "—");

          $lastHolderText = $inTransit
            ? (string)($d["open_from_section_name"] ?? "—")
            : (string)($d["last_holder_name"] ?? "—");
        ?>
        <tr
          class="rowHover"
          data-doc='<?= htmlspecialchars(
            json_encode([
              "id" => (int)$d["id"],
              "tracking_no" => $d["tracking_no"],
              "requester" => $d["requester"],
              "document_date" => $d["document_date"],
              "subject" => $d["subject"],
              "content_type" => $d["content_type"],
              "comm_type" => $d["comm_type"],

              "status_label" => $statusLabel,
              "status_chip_class" => $statusChipClass,

              "in_transit" => !empty($d["open_to_section_id"]) ? 1 : 0,
              "open_to_section_id" => (int)($d["open_to_section_id"] ?? 0),
              "open_from_section_id" => (int)($d["open_from_section_id"] ?? 0),
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
            <span class="<?= htmlspecialchars($statusChipClass) ?>">
              <?= htmlspecialchars($statusLabel) ?>
            </span>
          </td>

          <td><b><?= htmlspecialchars($currentHolderText) ?></b></td>
          <td class="mini"><?= htmlspecialchars((string)$d["tracking_no"]) ?></td>
          <td class="mini"><?= htmlspecialchars((string)$d["subject"]) ?></td>
          <td class="mini"><?= htmlspecialchars($movementText) ?></td>

          <td>
            <span class="daysPill <?= $danger ?: $warn ?>"><?= $days ?></span>
          </td>

          <td class="mini"><?= htmlspecialchars((string)$d["requester"]) ?></td>
          <td class="mini"><?= htmlspecialchars((string)$d["document_date"]) ?></td>
          <td class="mini"><?= htmlspecialchars((string)$d["content_type"]) ?></td>
          <td class="mini"><?= htmlspecialchars($lastHolderText) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
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

    <div style="margin: 10px 0 14px;">
      <label style="font-size:12px;font-weight:900;">Remarks (optional)</label>
      <input id="d_remarks" type="text" class="search" style="min-width:100%;" placeholder="Add a quick note for history...">
    </div>

    <div class="kv">
      <div class="k">Status</div>
      <div class="v">
        <span id="d_status" class="chip incoming">—</span>
      </div>
    </div>

    <div class="kv">
      <div class="k">Current Holder</div>
      <div class="v">
        <span id="d_holder" class="chip incoming">—</span>
      </div>
    </div>

    <div class="kv">
      <div class="k">Destination</div>
      <div class="v" id="d_destination">
        <span id="d_destination_text">—</span>
        <button type="button" class="btnSecondary" id="btnViewRecipients" style="display:none; padding:6px 10px; margin-left:8px;">View</button>
      </div>
    </div>

    <div class="kv">
      <div class="k">Last Holder</div>
      <div class="v" id="d_last_holder">—</div>
    </div>

    <div class="kv"><div class="k">Requester</div><div class="v" id="d_requester"></div></div>
    <div class="kv"><div class="k">Doc Date</div><div class="v" id="d_date"></div></div>
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

    <div style="margin-top:14px;">
      <div class="k" style="margin-bottom:8px; display:flex; align-items:center; gap:8px;">
        <span>Timeline</span>
        <span class="mini" style="opacity:.7;">(latest on top)</span>
      </div>
      <div id="d_timeline" class="mini">Select a document…</div>
    </div>
  </div>

  <div class="drawerActions">
    <button type="button" class="btnSecondary" id="btnToggleForward">Forward</button>

    <button id="btnAckReceived" class="btnGreen" type="button" style="display:none;">Received</button>
    <button id="btnRelease" class="btnGreen" type="button" style="display:none;">Release</button>
    <button id="btnArchive" class="btnComp" type="button" style="display:none;">Archive</button>
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

    <div id="forwardRecipientsPreview" class="mini" style="opacity:.9; margin-top:6px;"></div>

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