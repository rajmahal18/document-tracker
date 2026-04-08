<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$pageTitle = 'Admin - Document Tracker';

$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'users')));
if (!in_array($activeTab, ['users', 'documents'], true)) {
  $activeTab = 'users';
}

$sectionsResult = $conn->query(
  "SELECT s.id, s.name AS section_name, d.id AS division_id, d.name AS division_name
   FROM sections s
   JOIN divisions d ON d.id = s.division_id
   WHERE s.is_active = 1 AND d.is_active = 1
   ORDER BY d.name ASC, s.name ASC"
);
$sections = $sectionsResult ? $sectionsResult->fetch_all(MYSQLI_ASSOC) : [];

$usersSql = "
  SELECT
    u.id,
    u.full_name,
    COALESCE(u.username, '') AS username,
    u.email,
    u.role,
    u.is_active,
    u.is_chief,
    COALESCE(u.official_title, '') AS official_title,
    COALESCE(u.authority_role, '') AS authority_role,
    u.must_change_password,
    u.section_id,
    COALESCE(s.name, '') AS section_name,
    COALESCE(d.name, '') AS division_name,
    u.created_at,
    u.last_seen_at
  FROM users u
  LEFT JOIN sections s ON s.id = u.section_id
  LEFT JOIN divisions d ON d.id = s.division_id
  ORDER BY u.is_active DESC, u.role DESC, u.full_name ASC
";
$usersResult = $conn->query($usersSql);
$users = $usersResult ? $usersResult->fetch_all(MYSQLI_ASSOC) : [];

$docSearch = trim((string)($_GET['doc_q'] ?? ''));
$docStatus = trim((string)($_GET['doc_status'] ?? ''));
$allowedStatuses = ['', 'active', 'completed', 'archived', 'returned'];
if (!in_array($docStatus, $allowedStatuses, true)) {
  $docStatus = '';
}

$docWhere = [];
$docParams = [];
$docTypes = '';

if ($docSearch !== '') {
  $docWhere[] = '(d.tracking_no LIKE CONCAT("%", ?, "%") OR d.subject LIKE CONCAT("%", ?, "%") OR d.requester LIKE CONCAT("%", ?, "%"))';
  array_push($docParams, $docSearch, $docSearch, $docSearch);
  $docTypes .= 'sss';
}
if ($docStatus !== '') {
  $docWhere[] = 'd.current_status = ?';
  $docParams[] = $docStatus;
  $docTypes .= 's';
}
$whereSql = $docWhere ? ('WHERE ' . implode(' AND ', $docWhere)) : '';

$documentsSql = "
  SELECT
    d.id,
    d.tracking_no,
    d.subject,
    d.requester,
    d.current_status,
    d.document_date,
    d.created_at,
    d.updated_at,
    COALESCE(creator.full_name, '') AS created_by_name,
    COALESCE(originSec.name, '') AS origin_section_name,
    COALESCE(originDiv.name, '') AS origin_division_name,
    COALESCE(holderSec.name, '') AS holder_section_name,
    COALESCE(holderDiv.name, '') AS holder_division_name,
    (SELECT COUNT(*) FROM document_attachments a WHERE a.document_id = d.id AND COALESCE(a.is_deleted, 0) = 0) AS attachment_count
  FROM documents d
  LEFT JOIN users creator ON creator.id = d.created_by_user_id
  LEFT JOIN sections originSec ON originSec.id = d.origin_section_id
  LEFT JOIN divisions originDiv ON originDiv.id = originSec.division_id
  LEFT JOIN sections holderSec ON holderSec.id = d.current_holder_section_id
  LEFT JOIN divisions holderDiv ON holderDiv.id = holderSec.division_id
  {$whereSql}
  ORDER BY d.created_at DESC
  LIMIT 100
";
$documents = [];
if ($stmt = $conn->prepare($documentsSql)) {
  if ($docParams !== []) {
    $stmt->bind_param($docTypes, ...$docParams);
  }
  $stmt->execute();
  $documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

require __DIR__ . '/../includes/layout.php';
?>
<style>
.adminShell { display:grid; gap:16px; }
.adminHero { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; padding:18px 20px; border:1px solid rgba(15,23,42,.08); border-radius:18px; background:linear-gradient(180deg, rgba(255,255,255,.92), rgba(248,250,252,.96)); box-shadow:0 18px 40px rgba(15,23,42,.05); }
.adminHero h2 { margin:0 0 4px; font-size:1.15rem; }
.adminHero p { margin:0; color:#475569; max-width:720px; }
.adminCounts { display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; }
.adminStat { min-width:128px; border:1px solid rgba(15,23,42,.08); border-radius:14px; padding:10px 12px; background:#fff; }
.adminStat strong { display:block; font-size:1.1rem; }
.adminTabs { display:flex; gap:8px; flex-wrap:wrap; }
.adminTab { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:999px; border:1px solid rgba(15,23,42,.08); background:#fff; color:#0f172a; text-decoration:none; font-weight:600; }
.adminTab.isActive { background:#0f172a; color:#fff; border-color:#0f172a; }
.adminCard { border:1px solid rgba(15,23,42,.08); border-radius:18px; background:#fff; box-shadow:0 14px 34px rgba(15,23,42,.04); }
.adminCardHeader { padding:16px 18px 10px; display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap; }
.adminCardHeader h3 { margin:0; font-size:1rem; }
.adminCardHeader p { margin:4px 0 0; color:#64748b; }
.adminCardBody { padding:0 18px 18px; }
.adminToolbar { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:14px; }
.adminToolbar form { display:flex; gap:10px; flex-wrap:wrap; align-items:center; width:100%; }
.adminToolbar input, .adminToolbar select, .adminModalGrid input, .adminModalGrid select { min-height:40px; border-radius:12px; border:1px solid #dbe2ea; padding:0 12px; }
.adminToolbar .grow { flex:1 1 260px; }
.adminTableWrap { overflow:auto; }
.adminTable { width:100%; min-width:980px; border-collapse:collapse; }
.adminTable th, .adminTable td { padding:12px 10px; border-bottom:1px solid #eef2f7; vertical-align:top; text-align:left; font-size:.94rem; }
.adminTable th { font-size:.76rem; text-transform:uppercase; letter-spacing:.05em; color:#64748b; }
.adminMini { font-size:.82rem; color:#64748b; }
.adminBadge { display:inline-flex; align-items:center; border-radius:999px; padding:4px 9px; font-size:.75rem; font-weight:700; }
.adminBadge.ok { background:#ecfdf3; color:#166534; }
.adminBadge.warn { background:#fff7ed; color:#c2410c; }
.adminBadge.neutral { background:#eef2ff; color:#3730a3; }
.adminRowActions { display:flex; gap:8px; flex-wrap:wrap; }
.adminRowActions button, .adminToolbar button, .adminToolbar a { min-height:38px; border-radius:12px; }
.adminDanger { background:#991b1b !important; border-color:#991b1b !important; color:#fff !important; }
.adminGhost { background:#fff; border:1px solid #dbe2ea; color:#0f172a; }
.adminPrimary { background:#0f172a; border:1px solid #0f172a; color:#fff; }
.adminModalWrap[hidden] { display:none !important; }
.adminModalWrap { position:fixed; inset:0; z-index:11000; }
.adminModalBackdrop { position:absolute; inset:0; background:rgba(15,23,42,.42); }
.adminModalCard { position:relative; z-index:1; width:min(760px, calc(100vw - 28px)); max-height:calc(100vh - 28px); overflow:auto; margin:14px auto; background:#fff; border-radius:22px; box-shadow:0 24px 70px rgba(15,23,42,.32); }
.adminModalHead { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; padding:18px 18px 10px; border-bottom:1px solid #eef2f7; }
.adminModalHead h3 { margin:0; }
.adminModalBody { padding:18px; }
.adminModalGrid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; }
.adminModalGrid .span2 { grid-column:1 / -1; }
.adminModalActions { display:flex; justify-content:flex-end; gap:10px; margin-top:16px; flex-wrap:wrap; }
.adminMessage { display:none; margin-top:12px; padding:11px 13px; border-radius:12px; font-size:.92rem; }
.adminMessage.ok { display:block; background:#ecfdf3; color:#166534; border:1px solid #bbf7d0; }
.adminMessage.error { display:block; background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
@media (max-width: 860px) {
  .adminHero { flex-direction:column; }
  .adminCounts { justify-content:flex-start; }
  .adminModalGrid { grid-template-columns:1fr; }
}
</style>

<?php
$totalUsers = count($users);
$activeUsers = count(array_filter($users, static fn(array $row): bool => (int)($row['is_active'] ?? 0) === 1));
$totalDocs = count($documents);
$activeDocs = count(array_filter($documents, static fn(array $row): bool => strtolower((string)($row['current_status'] ?? '')) === 'active'));
?>

<div class="adminShell">
  <section class="adminHero">
    <div>
      <h2>Admin workspace</h2>
      <p>Manage users and documents from one place. This page is system-level only: full visibility, no redaction, and destructive actions stay isolated from normal workflow screens.</p>
    </div>
    <div class="adminCounts">
      <div class="adminStat"><span class="adminMini">Users</span><strong><?= (int)$totalUsers ?></strong><span class="adminMini"><?= (int)$activeUsers ?> active</span></div>
      <div class="adminStat"><span class="adminMini">Documents</span><strong><?= (int)$totalDocs ?></strong><span class="adminMini"><?= (int)$activeDocs ?> active</span></div>
    </div>
  </section>

  <div class="adminTabs">
    <a class="adminTab <?= $activeTab === 'users' ? 'isActive' : '' ?>" href="<?= PUBLIC_PATH ?>/admin.php?tab=users">Users</a>
    <a class="adminTab <?= $activeTab === 'documents' ? 'isActive' : '' ?>" href="<?= PUBLIC_PATH ?>/admin.php?tab=documents">Documents</a>
    <a class="adminTab" href="<?= PUBLIC_PATH ?>/access_requests.php">Access Requests</a>
  </div>

  <?php if ($activeTab === 'users'): ?>
    <section class="adminCard">
      <div class="adminCardHeader">
        <div>
          <h3>Users</h3>
          <p>Create, edit, activate/deactivate, and reset credentials without leaving the admin workspace.</p>
        </div>
        <button type="button" class="adminPrimary" onclick="openUserModal()">Add user</button>
      </div>
      <div class="adminCardBody">
        <div id="adminUsersMsg" class="adminMessage"></div>
        <div class="adminTableWrap">
          <table class="adminTable">
            <thead>
              <tr>
                <th>Name</th>
                <th>Login</th>
                <th>Office</th>
                <th>Role</th>
                <th>Status</th>
                <th>Last seen</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $user): ?>
                <?php
                  $authorityRole = trim((string)($user['authority_role'] ?? ''));
                  if ($authorityRole === '') {
                    $authorityRole = ((string)($user['role'] ?? 'user') === 'admin') ? 'admin' : (((int)($user['is_chief'] ?? 0) === 1) ? 'section_head' : 'staff');
                  }
                ?>
                <tr id="user-row-<?= (int)$user['id'] ?>"
                    data-user='<?= htmlspecialchars(json_encode([
                      'id' => (int)$user['id'],
                      'full_name' => (string)$user['full_name'],
                      'email' => (string)$user['email'],
                      'role' => (string)($user['role'] ?? 'user'),
                      'is_active' => (int)($user['is_active'] ?? 1),
                      'is_chief' => (int)($user['is_chief'] ?? 0),
                      'official_title' => (string)($user['official_title'] ?? ''),
                      'authority_role' => $authorityRole,
                      'section_id' => isset($user['section_id']) ? (int)$user['section_id'] : 0,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>'>
                  <td>
                    <strong><?= htmlspecialchars((string)$user['full_name']) ?></strong>
                    <?php if ((string)($user['official_title'] ?? '') !== ''): ?>
                      <div class="adminMini"><?= htmlspecialchars((string)$user['official_title']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (trim((string)($user['username'] ?? '')) !== ''): ?>
                      <div><strong>@<?= htmlspecialchars((string)$user['username']) ?></strong></div>
                    <?php endif; ?>
                    <div class="adminMini"><?= htmlspecialchars((string)$user['email']) ?></div>
                  </td>
                  <td>
                    <?php if ((string)($user['division_name'] ?? '') !== '' || (string)($user['section_name'] ?? '') !== ''): ?>
                      <div><?= htmlspecialchars(trim((string)($user['division_name'] ?? ''))) ?></div>
                      <div class="adminMini"><?= htmlspecialchars(trim((string)($user['section_name'] ?? ''))) ?></div>
                    <?php else: ?>
                      <span class="adminMini">No section assigned</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="adminBadge <?= (string)($user['role'] ?? 'user') === 'admin' ? 'neutral' : 'ok' ?>"><?= htmlspecialchars(strtoupper((string)($user['role'] ?? 'user'))) ?></span>
                    <div class="adminMini" style="margin-top:6px;"><?= htmlspecialchars(str_replace('_', ' ', $authorityRole)) ?></div>
                  </td>
                  <td>
                    <span class="adminBadge <?= (int)($user['is_active'] ?? 0) === 1 ? 'ok' : 'warn' ?>"><?= (int)($user['is_active'] ?? 0) === 1 ? 'ACTIVE' : 'INACTIVE' ?></span>
                    <?php if ((int)($user['must_change_password'] ?? 0) === 1): ?>
                      <div class="adminMini" style="margin-top:6px;">Must change password</div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($user['last_seen_at'])): ?>
                      <?= htmlspecialchars((string)$user['last_seen_at']) ?>
                    <?php else: ?>
                      <span class="adminMini">Never</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="adminRowActions">
                      <button type="button" class="adminGhost" onclick="openUserModalFromRow(this)">Edit</button>
                      <button type="button" class="adminGhost" onclick="resetUserPassword(<?= (int)$user['id'] ?>, '<?= htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8') ?>')">Reset password</button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  <?php else: ?>
    <section class="adminCard">
      <div class="adminCardHeader">
        <div>
          <h3>Documents</h3>
          <p>Admin-only document list with direct access to safe delete. Delete stays here so regular workflow screens remain clean.</p>
        </div>
      </div>
      <div class="adminCardBody">
        <div class="adminToolbar">
          <form method="get" action="<?= PUBLIC_PATH ?>/admin.php">
            <input type="hidden" name="tab" value="documents">
            <input class="grow" type="text" name="doc_q" value="<?= htmlspecialchars($docSearch) ?>" placeholder="Search tracking no, subject, requester">
            <select name="doc_status">
              <option value="">All statuses</option>
              <?php foreach (['active' => 'Active', 'completed' => 'Completed', 'archived' => 'Archived', 'returned' => 'Returned'] as $key => $label): ?>
                <option value="<?= htmlspecialchars($key) ?>" <?= $docStatus === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="adminPrimary">Filter</button>
            <a class="adminGhost" href="<?= PUBLIC_PATH ?>/admin.php?tab=documents">Reset</a>
          </form>
        </div>
        <div id="adminDocsMsg" class="adminMessage"></div>
        <div class="adminTableWrap">
          <table class="adminTable">
            <thead>
              <tr>
                <th>Tracking</th>
                <th>Subject</th>
                <th>Origin</th>
                <th>Holder</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($documents === []): ?>
                <tr><td colspan="7"><span class="adminMini">No documents matched the current filter.</span></td></tr>
              <?php endif; ?>
              <?php foreach ($documents as $doc): ?>
                <tr id="doc-row-<?= (int)$doc['id'] ?>">
                  <td>
                    <strong><?= htmlspecialchars((string)($doc['tracking_no'] ?? '—')) ?></strong>
                    <div class="adminMini">#<?= (int)$doc['id'] ?></div>
                  </td>
                  <td>
                    <strong><?= htmlspecialchars((string)($doc['subject'] ?? 'Untitled')) ?></strong>
                    <div class="adminMini">Requester: <?= htmlspecialchars((string)($doc['requester'] ?? '—')) ?></div>
                    <div class="adminMini">Attachments: <?= (int)($doc['attachment_count'] ?? 0) ?></div>
                  </td>
                  <td>
                    <div><?= htmlspecialchars(trim((string)($doc['origin_division_name'] ?? ''))) ?></div>
                    <div class="adminMini"><?= htmlspecialchars(trim((string)($doc['origin_section_name'] ?? ''))) ?></div>
                  </td>
                  <td>
                    <div><?= htmlspecialchars(trim((string)($doc['holder_division_name'] ?? ''))) ?></div>
                    <div class="adminMini"><?= htmlspecialchars(trim((string)($doc['holder_section_name'] ?? ''))) ?></div>
                  </td>
                  <td><span class="adminBadge neutral"><?= htmlspecialchars(strtoupper((string)($doc['current_status'] ?? ''))) ?></span></td>
                  <td>
                    <div><?= htmlspecialchars((string)($doc['created_at'] ?? '')) ?></div>
                    <div class="adminMini">by <?= htmlspecialchars((string)($doc['created_by_name'] ?? 'Unknown')) ?></div>
                  </td>
                  <td>
                    <div class="adminRowActions">
                      <a class="adminGhost" href="<?= PUBLIC_PATH ?>/view_document.php?id=<?= (int)$doc['id'] ?>" target="_blank" rel="noopener">View</a>
                      <button type="button" class="adminDanger" onclick="deleteDocument(<?= (int)$doc['id'] ?>, '<?= htmlspecialchars((string)($doc['tracking_no'] ?: ('#' . (int)$doc['id'])), ENT_QUOTES, 'UTF-8') ?>')">Delete</button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  <?php endif; ?>
</div>

<div class="adminModalWrap" id="userModal" hidden>
  <div class="adminModalBackdrop" onclick="closeUserModal()"></div>
  <div class="adminModalCard">
    <div class="adminModalHead">
      <div>
        <h3 id="userModalTitle">Add user</h3>
        <div class="adminMini">Admins can create system admins here too. Access Requests remains separate for external/new requests.</div>
      </div>
      <button type="button" class="adminGhost" onclick="closeUserModal()">Close</button>
    </div>
    <div class="adminModalBody">
      <form id="userForm">
        <input type="hidden" name="id" id="userFormId" value="0">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="adminModalGrid">
          <div class="span2">
            <label>Full name</label>
            <input type="text" name="full_name" id="userFullName" required maxlength="200">
          </div>
          <div>
            <label>Email</label>
            <input type="email" name="email" id="userEmail" required maxlength="200">
          </div>
          <div>
            <label>Section</label>
            <select name="section_id" id="userSectionId" required>
              <option value="">Select section</option>
              <?php foreach ($sections as $section): ?>
                <option value="<?= (int)$section['id'] ?>"><?= htmlspecialchars((string)$section['division_name']) ?> — <?= htmlspecialchars((string)$section['section_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>System role</label>
            <select name="role" id="userRole" required>
              <option value="user">User</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div>
            <label>Authority role</label>
            <select name="authority_role" id="userAuthorityRole" required>
              <option value="staff">Staff</option>
              <option value="section_head">Section Head</option>
              <option value="division_assistant">Division Assistant</option>
              <option value="division_head">Division Head</option>
              <option value="director">Director</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div>
            <label>Official title</label>
            <input type="text" name="official_title" id="userOfficialTitle" maxlength="100" placeholder="Optional">
          </div>
          <div>
            <label>Status</label>
            <select name="is_active" id="userIsActive" required>
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
          <div>
            <label>Chief flag</label>
            <select name="is_chief" id="userIsChief" required>
              <option value="0">No</option>
              <option value="1">Yes</option>
            </select>
          </div>
        </div>
        <div class="adminModalActions">
          <button type="button" class="adminGhost" onclick="closeUserModal()">Cancel</button>
          <button type="submit" class="adminPrimary">Save user</button>
        </div>
        <div id="userFormMsg" class="adminMessage"></div>
      </form>
    </div>
  </div>
</div>

<div class="adminModalWrap" id="credentialsModal" hidden>
  <div class="adminModalBackdrop" onclick="closeCredentialsModal()"></div>
  <div class="adminModalCard">
    <div class="adminModalHead">
      <div>
        <h3>Temporary credentials</h3>
        <div class="adminMini">Copy these immediately. The system only shows the generated temporary password once.</div>
      </div>
      <button type="button" class="adminGhost" onclick="closeCredentialsModal()">Close</button>
    </div>
    <div class="adminModalBody">
      <div class="adminModalGrid">
        <div>
          <label>Username</label>
          <input id="credUsername" type="text" readonly>
        </div>
        <div>
          <label>Temporary password</label>
          <input id="credPassword" type="text" readonly>
        </div>
      </div>
      <div class="adminModalActions">
        <button type="button" class="adminGhost" onclick="copyAdminValue('credUsername')">Copy username</button>
        <button type="button" class="adminGhost" onclick="copyAdminValue('credPassword')">Copy password</button>
        <button type="button" class="adminPrimary" onclick="closeCredentialsModal()">Done</button>
      </div>
    </div>
  </div>
</div>

<script>
const USER_MODAL = document.getElementById('userModal');
const USER_FORM = document.getElementById('userForm');
const USER_FORM_MSG = document.getElementById('userFormMsg');
const USERS_PAGE_MSG = document.getElementById('adminUsersMsg');
const DOCS_PAGE_MSG = document.getElementById('adminDocsMsg');
const CREDENTIALS_MODAL = document.getElementById('credentialsModal');

function setAdminMessage(el, type, text) {
  if (!el) return;
  el.className = 'adminMessage ' + (type || '');
  el.textContent = text || '';
  if (!text) {
    el.style.display = 'none';
    return;
  }
  el.style.display = 'block';
}

function openUserModal() {
  document.getElementById('userModalTitle').textContent = 'Add user';
  USER_FORM.reset();
  document.getElementById('userFormId').value = '0';
  document.getElementById('userIsActive').value = '1';
  document.getElementById('userRole').value = 'user';
  document.getElementById('userAuthorityRole').value = 'staff';
  document.getElementById('userIsChief').value = '0';
  setAdminMessage(USER_FORM_MSG, '', '');
  USER_MODAL.hidden = false;
}

function openUserModalFromRow(button) {
  const row = button.closest('tr');
  if (!row) return;
  const user = JSON.parse(row.dataset.user || '{}');
  document.getElementById('userModalTitle').textContent = 'Edit user';
  document.getElementById('userFormId').value = String(user.id || 0);
  document.getElementById('userFullName').value = user.full_name || '';
  document.getElementById('userEmail').value = user.email || '';
  document.getElementById('userSectionId').value = String(user.section_id || '');
  document.getElementById('userRole').value = user.role || 'user';
  document.getElementById('userAuthorityRole').value = user.authority_role || 'staff';
  document.getElementById('userOfficialTitle').value = user.official_title || '';
  document.getElementById('userIsActive').value = String(user.is_active ?? 1);
  document.getElementById('userIsChief').value = String(user.is_chief ?? 0);
  setAdminMessage(USER_FORM_MSG, '', '');
  USER_MODAL.hidden = false;
}

function closeUserModal() { USER_MODAL.hidden = true; }
function closeCredentialsModal() { CREDENTIALS_MODAL.hidden = true; }

async function postForm(url, formData) {
  const response = await fetch(url, { method: 'POST', body: formData, credentials: 'same-origin' });
  const payload = await response.json().catch(() => ({ ok: false, error: 'Invalid server response.' }));
  if (!response.ok || !payload.ok) {
    throw new Error(payload.error || 'Request failed.');
  }
  return payload;
}

USER_FORM?.addEventListener('submit', async (event) => {
  event.preventDefault();
  setAdminMessage(USER_FORM_MSG, '', '');
  try {
    const payload = await postForm(window.__APP__.api + '/admin_user_save.php', new FormData(USER_FORM));
    if (payload.temp_password) {
      document.getElementById('credUsername').value = payload.username || payload.email || '';
      document.getElementById('credPassword').value = payload.temp_password || '';
      CREDENTIALS_MODAL.hidden = false;
    }
    setAdminMessage(USERS_PAGE_MSG, 'ok', payload.message || 'User saved successfully. Reloading…');
    closeUserModal();
    window.setTimeout(() => window.location.reload(), 500);
  } catch (error) {
    setAdminMessage(USER_FORM_MSG, 'error', error.message || 'Failed to save user.');
  }
});

async function resetUserPassword(userId, fullName) {
  const confirmed = window.confirm('Reset password for ' + fullName + '? A new temporary password will be generated and the user will be forced to change it on next login.');
  if (!confirmed) return;
  try {
    const form = new FormData();
    form.append('csrf_token', window.__APP__.csrf);
    form.append('user_id', String(userId));
    const payload = await postForm(window.__APP__.api + '/admin_user_reset_password.php', form);
    document.getElementById('credUsername').value = payload.username || payload.email || '';
    document.getElementById('credPassword').value = payload.temp_password || '';
    CREDENTIALS_MODAL.hidden = false;
    setAdminMessage(USERS_PAGE_MSG, 'ok', payload.message || 'Password reset successful.');
  } catch (error) {
    setAdminMessage(USERS_PAGE_MSG, 'error', error.message || 'Failed to reset password.');
  }
}

async function deleteDocument(docId, label) {
  const confirmed = window.confirm('Delete document ' + label + '? This will permanently remove the document, routes, events, attachments, branch data, and related tracking records.');
  if (!confirmed) return;
  try {
    const form = new FormData();
    form.append('csrf_token', window.__APP__.csrf);
    form.append('document_id', String(docId));
    const payload = await postForm(window.__APP__.api + '/admin_document_delete.php', form);
    const row = document.getElementById('doc-row-' + docId);
    if (row) row.remove();
    setAdminMessage(DOCS_PAGE_MSG, 'ok', payload.message || 'Document deleted successfully.');
  } catch (error) {
    setAdminMessage(DOCS_PAGE_MSG, 'error', error.message || 'Failed to delete document.');
  }
}

function copyAdminValue(id) {
  const input = document.getElementById(id);
  if (!input) return;
  input.select();
  input.setSelectionRange(0, 99999);
  document.execCommand('copy');
}
</script>
