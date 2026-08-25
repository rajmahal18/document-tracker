<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../core/task_monitoring.php';
require_once __DIR__ . '/../core/project_codes.php';
require_login();

$pageTitle = 'Task Monitoring System';
$currentPage = 'task_monitoring.php';
$disableLegacyOrgChartStyles = true;
$disableDocumentsStyles = true;
$hideAppUserSummary = true;
$hideFooter = true;
$bodyClass = 'tms-body-shell';
$pageClass = 'tms-page-shell';
$contentClass = 'tms-content-shell';

$userId = (int)($_SESSION['user_id'] ?? 0);
$canManageAll = tms_user_can_manage_all($conn, $userId);

$tablesReady = tms_tables_ready($conn);

$typeCode = trim((string)($_GET['type'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$viewMode = strtolower(trim((string)($_GET['view_mode'] ?? ($canManageAll ? 'all' : 'my'))));
if (!in_array($viewMode, ['my', 'section', 'division', 'all'], true)) {
  $viewMode = $canManageAll ? 'all' : 'my';
}
if (!$canManageAll && $viewMode === 'all') {
  $viewMode = 'my';
}
$search = trim((string)($_GET['q'] ?? ''));

$taskTypes = $tablesReady ? tms_task_types($conn, true) : [];
$workflowTemplates = $tablesReady ? tms_workflow_templates_with_details($conn, true) : [];
$rolePresets = $tablesReady ? tms_role_presets($conn) : [];
$divisions = $tablesReady ? tms_divisions($conn) : [];
$sections = $tablesReady ? tms_sections($conn) : [];
$tasks = $tablesReady ? tms_fetch_tasks($conn, [
  'type_code' => $typeCode,
  'status' => $status,
  'view_mode' => $viewMode,
  'q' => $search,
], $userId) : [];
$users = $tablesReady ? tms_fetch_users_for_assignment($conn) : [];
$projects = project_codes_tables_ready($conn)
  ? ($conn->query("
      SELECT id, project_code, title
      FROM projects
      WHERE is_active = 1
      ORDER BY project_code ASC, title ASC
    ")->fetch_all(MYSQLI_ASSOC) ?: [])
  : [];

$statusOptions = [];
foreach ($tasks as $task) {
  $statusLabel = trim((string)($task['lifecycle_status'] ?? ''));
  if ($statusLabel !== '') {
    $statusOptions[$statusLabel] = $statusLabel;
  }
}
ksort($statusOptions);

$viewer = [
  'id' => $userId,
  'full_name' => (string)($_SESSION['full_name'] ?? 'User'),
  'username' => (string)($_SESSION['username'] ?? ''),
  'official_title' => (string)($_SESSION['official_title'] ?? ''),
  'division_id' => (int)($_SESSION['division_id'] ?? 0),
  'section_id' => (int)($_SESSION['section_id'] ?? 0),
  'division_name' => (string)($_SESSION['division_name'] ?? ''),
  'section_name' => (string)($_SESSION['section_name'] ?? ''),
];

$bootstrap = [
  'viewer' => $viewer,
  'canManageAll' => $canManageAll,
  'viewMode' => $viewMode,
  'filters' => [
    'type' => $typeCode,
    'status' => $status,
    'q' => $search,
  ],
  'taskTypes' => array_values($taskTypes),
  'workflowTemplates' => array_values($workflowTemplates),
  'rolePresets' => array_values($rolePresets),
  'divisions' => array_values($divisions),
  'sections' => array_values($sections),
  'tasks' => array_values($tasks),
  'users' => array_values($users),
  'projects' => array_values($projects),
  'statusOptions' => array_values($statusOptions),
  'tablesReady' => $tablesReady,
];

function tms_react_manifest_path(): string
{
  return __DIR__ . '/task-monitoring-react/manifest.json';
}

function tms_react_manifest_data(): ?array
{
  static $manifest = null;
  static $loaded = false;

  if ($loaded) {
    return $manifest;
  }

  $loaded = true;
  $path = tms_react_manifest_path();
  if (!is_file($path)) {
    return $manifest = null;
  }

  $decoded = json_decode((string)file_get_contents($path), true);
  if (!is_array($decoded)) {
    return $manifest = null;
  }

  return $manifest = $decoded;
}

function tms_react_entry_assets(): array
{
  $manifest = tms_react_manifest_data();
  if (!is_array($manifest)) {
    return ['css' => [], 'js' => []];
  }

  $entry = $manifest['index.html'] ?? null;
  if (!is_array($entry)) {
    $entry = reset($manifest);
    if (!is_array($entry)) {
      return ['css' => [], 'js' => []];
    }
  }

  $css = [];
  foreach (($entry['css'] ?? []) as $href) {
    if (is_string($href) && $href !== '') {
      $css[] = asset_url('public/task-monitoring-react/' . ltrim($href, '/'));
    }
  }

  $js = [];
  $entryFile = (string)($entry['file'] ?? '');
  if ($entryFile !== '') {
    $js[] = asset_url('public/task-monitoring-react/' . ltrim($entryFile, '/'));
  }

  return ['css' => $css, 'js' => $js];
}

$tmsReactAssets = tms_react_entry_assets();
$tmsBuildReady = !empty($tmsReactAssets['js']);

require __DIR__ . '/../includes/layout.php';
?>

<div class="tmsReactPage">
  <?php if (!$tablesReady): ?>
    <section style="background:#fff;border:1px solid #dbe4ef;border-radius:20px;padding:20px 22px;box-shadow:0 16px 36px rgba(15,23,42,.06);max-width:980px;">
      <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#1b63df;">Task Monitoring tables are not ready</p>
      <h2 style="margin:0 0 10px;font-size:24px;line-height:1.2;color:#0f172a;">Run the TMS migration first.</h2>
      <p style="margin:0;color:#475569;">Apply <code>db/migrations/20260518_task_monitoring_foundation.sql</code> and reload this page.</p>
    </section>
  <?php elseif (!$tmsBuildReady): ?>
    <section style="background:#fff;border:1px solid #dbe4ef;border-radius:20px;padding:20px 22px;box-shadow:0 16px 36px rgba(15,23,42,.06);max-width:980px;">
      <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#1b63df;">React TMS frontend not built yet</p>
      <h2 style="margin:0 0 10px;font-size:24px;line-height:1.2;color:#0f172a;">The PHP shell is ready.</h2>
      <p style="margin:0 0 14px;color:#475569;">Build the isolated React frontend once, then this page will load the hashed bundle automatically.</p>
      <ol style="margin:0 0 14px 18px;color:#334155;line-height:1.7;">
        <li>Open terminal at <code>frontend/task-monitoring-react</code>.</li>
        <li>Run <code>npm install</code>.</li>
        <li>Run <code>npm run build</code>.</li>
        <li>Refresh this page.</li>
      </ol>
      <p style="margin:0;color:#64748b;font-size:13px;">Build output goes to <code>public/task-monitoring-react</code>.</p>
    </section>
  <?php else: ?>
    <script>
      window.__TMS_BOOTSTRAP__ = <?= json_encode($bootstrap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>

    <?php foreach ($tmsReactAssets['css'] as $href): ?>
      <link rel="stylesheet" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>

    <div id="tms-root"></div>

    <?php foreach ($tmsReactAssets['js'] as $src): ?>
      <script type="module" src="<?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>"></script>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
