<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

$sections = $conn->query("
  SELECT id, name
  FROM sections
  ORDER BY name ASC
")->fetch_all(MYSQLI_ASSOC);


$pageTitle = "Documents - Document Tracker";
require __DIR__ . "/../includes/layout.php";
?>

<script>
  window.__CSRF__ = "<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>";

  // ✅ JS context for button visibility logic
  window.__CTX__ = {
    mySectionId: <?= (int)($_SESSION["section_id"] ?? 0) ?>,
    myRole: "<?= htmlspecialchars($_SESSION["role"] ?? "division") ?>"
  };
  window.__SECTIONS__ = <?= json_encode($sections, JSON_UNESCAPED_UNICODE) ?>;
</script>


<?php
$search    = trim($_GET["q"] ?? "");
$status    = trim($_GET["status"] ?? "");
$date_from = trim($_GET["from"] ?? "");
$date_to   = trim($_GET["to"] ?? "");

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
          AND r.is_open = 1
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

/**
 * Status filter: based on lifecycle status (ACTIVE/RELEASED/ARCHIVED)
 * Movement filter (IN TRANSIT) is not added here for simplicity.
 */
if ($status !== "") {
  $where[] = "d.current_status = ?";
  $params[] = strtoupper($status);
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

/**
 * Main list query (NEW SCHEMA)
 * Adds:
 * - current holder name
 * - open route (in transit)
 * - last holder (previous sender of last received route)
 */
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

    -- last holder (fallback when not in transit)
    sf_last.name AS last_holder_name,

    TIMESTAMPDIFF(DAY, d.updated_at, NOW()) AS days_stuck
  FROM documents d
  LEFT JOIN sections sh ON sh.id = d.current_holder_section_id

  LEFT JOIN routes r_open
    ON r_open.document_id = d.id AND r_open.is_open = 1
  LEFT JOIN sections sf_open ON sf_open.id = r_open.from_section_id
  LEFT JOIN sections st_open ON st_open.id = r_open.to_section_id

  LEFT JOIN routes r_last
    ON r_last.document_id = d.id AND r_last.is_open = 0
   AND r_last.received_at = (
      SELECT MAX(r2.received_at)
      FROM routes r2
      WHERE r2.document_id = d.id AND r2.is_open = 0 AND r2.received_at IS NOT NULL
   )
  LEFT JOIN sections sf_last ON sf_last.id = r_last.from_section_id
";

if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY d.document_date DESC, d.id DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/**
 * Stats (NEW SCHEMA)
 * - active: ACTIVE
 * - archived: ARCHIVED
 * - released_today: RELEASED today
 * - overdue: ACTIVE but untouched >= 7 days (using updated_at)
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
          AND r.is_open = 1
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

?>


<div style="display:flex;justify-content:space-between;align-items:center;">
  <h1>Document List</h1>
  <a href="<?= PUBLIC_PATH ?>/add_document.php" class="btnPrimary" style="text-decoration:none;">
    + Add Document
  </a>
</div>

<p class="mini">Signed in as <b><?= htmlspecialchars($_SESSION["full_name"] ?? "User") ?></b> (<?= htmlspecialchars($role) ?>)</p>

<div class="stats">
  <div class="statCard">
    <div class="statTop">
      <div class="statTitle">Active</div>
      <div class="chip incoming">Ongoing</div>
    </div>
    <div class="statValue"><?= $stats["active"] ?></div>
  </div>

  <div class="statCard">
    <div class="statTop">
      <div class="statTitle">Overdue</div>
      <div class="chip overdue">Stuck ≥ 7d</div>
    </div>
    <div class="statValue"><?= $stats["overdue"] ?></div>
  </div>

  <div class="statCard">
    <div class="statTop">
      <div class="statTitle">Released Today</div>
      <div class="chip released">Done</div>
    </div>
    <div class="statValue"><?= $stats["released_today"] ?></div>
  </div>

  <div class="statCard">
    <div class="statTop">
      <div class="statTitle">Archived</div>
      <div class="chip archived">Filed</div>
    </div>
    <div class="statValue"><?= $stats["archived"] ?></div>
  </div>
</div>

<form class="toolbar" method="GET" action="<?= PUBLIC_PATH ?>/documents.php">
  <div class="filters">
    <div class="control">
      <label>Status</label>
      <select class="select" name="status">
        <option value="">All</option>
        <option value="ACTIVE" <?= strtoupper($status)==="ACTIVE" ? "selected" : "" ?>>ACTIVE</option>
        <option value="RELEASED" <?= strtoupper($status)==="RELEASED" ? "selected" : "" ?>>RELEASED</option>
        <option value="ARCHIVED" <?= strtoupper($status)==="ARCHIVED" ? "selected" : "" ?>>ARCHIVED</option>
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
  </div>

  <div class="filters">
    <input class="search" type="text" name="q" placeholder="Search tracking no, requester, subject, holder..." value="<?= htmlspecialchars($search) ?>">
    <button type="submit">Apply</button>
  </div>
</form>

<div class="tableWrap">
  <table class="docTable">
    <thead>
      <tr>
        <th>Tracking No.</th>
        <th>Requester</th>
        <th>Document Date</th>
        <th>Subject</th>
        <th>Type</th>
        <th>Movement</th>
        <th>Current Holder</th>
        <th>Last Holder</th>
        <th>Days</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$docs): ?>
        <tr>
          <td colspan="9" class="mini" style="padding:18px;">No documents found.</td>
        </tr>
      <?php endif; ?>

      <?php foreach ($docs as $d): ?>
        <?php
          $days = (int)($d["days_stuck"] ?? 0);
          $warn = ($days >= 3 && $days < 7) ? "warn" : "";
          $danger = ($days >= 7) ? "danger" : "";

          $inTransit = !empty($d["open_to_section_id"]);

          // Movement = destination
          $movementText = $inTransit
            ? (string)($d["open_to_section_name"] ?? "—")
            : "—";

          // Current Holder = IN TRANSIT or section
          $currentHolderText = (string)($d["current_holder_name"] ?? "—");


          // Last Holder logic stays correct
          $lastHolderText = $inTransit
            ? (string)($d["open_from_section_name"] ?? "—")
            : (string)($d["last_holder_name"] ?? "—");

        ?>
        <tr
          class="rowHover"
          data-doc='<?= htmlspecialchars(json_encode([
            "id" => (int)$d["id"],
            "tracking_no" => $d["tracking_no"],
            "requester" => $d["requester"],
            "document_date" => $d["document_date"],
            "subject" => $d["subject"],
            "content_type" => $d["content_type"],
            "comm_type" => $d["comm_type"],

            "in_transit" => !empty($d["open_to_section_id"]) ? 1 : 0,

            "open_to_section_id" => (int)($d["open_to_section_id"] ?? 0),
            "open_from_section_id" => (int)($d["open_from_section_id"] ?? 0),

            "movement_text" => $movementText,              // destination
            "current_holder_text" => $currentHolderText,   // IN TRANSIT or section
            "last_holder_text" => $lastHolderText,


            "current_holder_section_id" => (int)($d["current_holder_section_id"] ?? 0),
            "days_stuck" => $days,
          ]), ENT_QUOTES, "UTF-8") ?>'
        >
          <td><b><?= htmlspecialchars((string)$d["tracking_no"]) ?></b></td>
          <td><?= htmlspecialchars((string)$d["requester"]) ?></td>
          <td class="mini"><?= htmlspecialchars((string)$d["document_date"]) ?></td>
          <td><?= htmlspecialchars((string)$d["subject"]) ?></td>
          <td class="mini"><?= htmlspecialchars((string)$d["content_type"]) ?></td>

          <td>
            <?= htmlspecialchars($movementText) ?>
          </td>

          <td>
            <?php if ($inTransit): ?>
              <span class="chip action">IN TRANSIT</span>
            <?php else: ?>
              <?= htmlspecialchars($currentHolderText) ?>
            <?php endif; ?>
          </td>

          <td><?= htmlspecialchars($lastHolderText) ?></td>


          <td>
            <span class="daysPill <?= $danger ?: $warn ?>"><?= $days ?></span>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
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
      <div class="k">Current Holder</div>
      <div class="v">
        <span id="d_status" class="chip archived">—</span>
      </div>
    </div>

    <div class="kv">
      <div class="k">Destination</div>
      <div class="v" id="d_destination">—</div>
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

    <div style="margin-top:14px;">
      <div class="k" style="margin-bottom:8px;">Timeline</div>
      <div id="d_timeline" class="mini">Select a document…</div>
    </div>
  </div>

  <div class="drawerActions">
    <!-- Render buttons for all roles; JS will decide visibility -->

    <div id="forwardBox" style="display:none; margin: 10px 0 14px;">
      <label style="font-size:12px; font-weight:900;">Forward To</label>
      <select id="f_to_section" class="select" style="min-width:100%;">
        <option value="">-- Select section --</option>
      </select>

      <button id="btnForward" type="button" class="btnPrimary" style="margin-top:10px; display:none;">
        Forward
      </button>
    </div>

    <button id="btnAckReceived" class="btnGhost" type="button" style="display:none;">Received</button>
    <button id="btnRelease" class="btnGhost" type="button" style="display:none;">Release</button>
    <button id="btnArchive" class="btnPrimary" type="button" style="display:none;">Archive</button>
  </div>

</aside>

<?php require __DIR__ . "/../includes/footer.php"; ?>
