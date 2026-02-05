<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

$pageTitle = "Documents - Document Tracker";
require __DIR__ . "/../includes/layout.php";
?>

<script>
  window.__CSRF__ = "<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>";
</script>

<?php
$search = trim($_GET["q"] ?? "");
$status = trim($_GET["status"] ?? "");
$date_from = trim($_GET["from"] ?? "");
$date_to = trim($_GET["to"] ?? "");

$role = $_SESSION["role"] ?? "viewer";
$mySectionId = (int)($_SESSION["section_id"] ?? 0);

$where = [];
$params = [];
$types = "";

/**
 * ✅ SECTION VISIBILITY RULE
 * Records (receiver) and admin see ALL.
 * Others see only documents where their section is involved.
 */
$isPrivileged = in_array($role, ["admin","receiver"], true);

if (!$isPrivileged) {
  if ($mySectionId <= 0) {
    $where[] = "1=0";
  } else {
    $where[] = "EXISTS (
      SELECT 1
      FROM doc_history h
      WHERE h.document_id = documents.id
        AND (h.from_section_id = ? OR h.to_section_id = ?)
    )";
    $params[] = $mySectionId;
    $params[] = $mySectionId;
    $types .= "ii";
  }
}

if ($search !== "") {
  $where[] = "(tracking_no LIKE ? OR requester LIKE ? OR subject LIKE ? OR content_type LIKE ?)";
  $like = "%" . $search . "%";
  array_push($params, $like, $like, $like, $like);
  $types .= "ssss";
}

if ($status !== "") {
  $where[] = "current_status = ?";
  $params[] = $status;
  $types .= "s";
}

if ($date_from !== "") {
  $where[] = "document_date >= ?";
  $params[] = $date_from;
  $types .= "s";
}

if ($date_to !== "") {
  $where[] = "document_date <= ?";
  $params[] = $date_to;
  $types .= "s";
}

$sql = "
  SELECT
    id, tracking_no, requester, document_date, subject, content_type,
    current_status, current_section_id,
    TIMESTAMPDIFF(DAY, status_updated_at, NOW()) AS days_stuck
  FROM documents
";

if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY document_date DESC, id DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/**
 * ✅ Stats should respect the same visibility rule
 */
$statWhere = [];
$statParams = [];
$statTypes = "";

if (!$isPrivileged) {
  if ($mySectionId <= 0) {
    $statWhere[] = "1=0";
  } else {
    $statWhere[] = "EXISTS (
      SELECT 1
      FROM doc_history h
      WHERE h.document_id = documents.id
        AND (h.from_section_id = ? OR h.to_section_id = ?)
    )";
    $statParams[] = $mySectionId;
    $statParams[] = $mySectionId;
    $statTypes .= "ii";
  }
}

$statSql = "
  SELECT
    SUM(current_status='incoming') AS incoming,
    SUM(current_status='under_action') AS under_action,
    SUM(current_status='released' AND DATE(updated_at)=CURDATE()) AS released_today,
    SUM(current_status IN ('incoming','under_action') AND TIMESTAMPDIFF(DAY, status_updated_at, NOW()) >= 7) AS overdue
  FROM documents
";
if ($statWhere) $statSql .= " WHERE " . implode(" AND ", $statWhere);

$statStmt = $conn->prepare($statSql);
if ($statParams) $statStmt->bind_param($statTypes, ...$statParams);
$statStmt->execute();
$statRows = $statStmt->get_result()->fetch_assoc();

$stats = [
  "incoming" => (int)($statRows["incoming"] ?? 0),
  "under_action" => (int)($statRows["under_action"] ?? 0),
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

<p class="mini">Signed in as <b><?= htmlspecialchars($_SESSION["full_name"]) ?></b> (<?= htmlspecialchars($_SESSION["role"]) ?>)</p>

<div class="stats">
  <div class="statCard">
    <div class="statTop">
      <div class="statTitle">Incoming</div>
      <div class="chip incoming">Queue</div>
    </div>
    <div class="statValue"><?= $stats["incoming"] ?></div>
  </div>

  <div class="statCard">
    <div class="statTop">
      <div class="statTitle">Under Action</div>
      <div class="chip action">Working</div>
    </div>
    <div class="statValue"><?= $stats["under_action"] ?></div>
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
</div>

<form class="toolbar" method="GET" action="<?= PUBLIC_PATH ?>/documents.php">
  <div class="filters">
    <div class="control">
      <label>Status</label>
      <select class="select" name="status">
        <option value="">All</option>
        <option value="incoming" <?= $status==="incoming" ? "selected" : "" ?>>Incoming</option>
        <option value="under_action" <?= $status==="under_action" ? "selected" : "" ?>>Under Action</option>
        <option value="released" <?= $status==="released" ? "selected" : "" ?>>Released</option>
        <option value="archived" <?= $status==="archived" ? "selected" : "" ?>>Archived</option>
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
    <input class="search" type="text" name="q" placeholder="Search tracking no, requester, subject..." value="<?= htmlspecialchars($search) ?>">
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
        <th>Status</th>
        <th>Days</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$docs): ?>
        <tr>
          <td colspan="7" class="mini" style="padding:18px;">No documents found.</td>
        </tr>
      <?php endif; ?>

      <?php foreach ($docs as $d): ?>
        <?php
          [$label, $chipClass] = status_label($d["current_status"]);
          $days = (int)($d["days_stuck"] ?? 0);
          $warn = ($days >= 3 && $days < 7) ? "warn" : "";
          $danger = ($days >= 7) ? "danger" : "";
        ?>
        <tr
          class="rowHover"
          data-doc='<?= htmlspecialchars(json_encode([
            "id" => (int)$d["id"],
            "tracking_no" => $d["tracking_no"],
            "requester" => $d["requester"],
            "document_date" => $d["document_date"],
            "subject" => $d["subject"],
            "current_section_id" => (int)$d["current_section_id"],
            "content_type" => $d["content_type"],
            "days_stuck" => $days,
            "status_label" => $label,
            "status_class" => $chipClass
          ]), ENT_QUOTES, "UTF-8") ?>'
        >
          <td><b><?= htmlspecialchars($d["tracking_no"]) ?></b></td>
          <td><?= htmlspecialchars($d["requester"]) ?></td>
          <td class="mini"><?= htmlspecialchars($d["document_date"]) ?></td>
          <td><?= htmlspecialchars($d["subject"]) ?></td>
          <td class="mini"><?= htmlspecialchars($d["content_type"]) ?></td>
          <td><span class="chip <?= $chipClass ?>"><?= htmlspecialchars($label) ?></span></td>
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

    <div class="kv"><div class="k">Status</div><div class="v"><span id="d_status" class="chip archived">—</span></div></div>
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
    <?php if (in_array($role, ["admin","receiver","encoder"], true)): ?>
      <button id="btnAckReceived" class="btnGhost" type="button">Received</button>
    <?php endif; ?>

    <?php if (in_array($role, ["admin","receiver","encoder"], true)): ?>
      <button id="btnUnderAction" class="btnGhost" type="button">Mark Under Action</button>
    <?php endif; ?>

    <?php if (in_array($role, ["admin","releaser"], true)): ?>
      <button id="btnRelease" class="btnGhost" type="button">Release</button>
    <?php endif; ?>

    <?php if ($role === "admin"): ?>
      <button id="btnArchive" class="btnPrimary" type="button">Archive</button>
    <?php endif; ?>
  </div>

</aside>

<?php require __DIR__ . "/../includes/footer.php"; ?>
