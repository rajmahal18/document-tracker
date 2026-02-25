<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_admin();

$pageTitle = "Access Requests - Document Tracker";

// Pending requests
$stmt = $conn->prepare("SELECT id, full_name, office_section, email, reason, status, created_at FROM access_requests WHERE status='PENDING' ORDER BY created_at DESC");
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Sections for dropdown (admin must choose)
$sec = $conn->query("SELECT s.id, s.name, d.name AS division_name
                     FROM sections s
                     JOIN divisions d ON d.id = s.division_id
                     WHERE s.is_active = 1
                     ORDER BY d.name ASC, s.name ASC");
$sections = $sec ? $sec->fetch_all(MYSQLI_ASSOC) : [];

require __DIR__ . "/../includes/layout.php";
?>

<script>
  window.__CSRF__ = "<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>";
</script>

<div class="card" style="margin-bottom:12px;">
  <h2 style="margin:0 0 6px;">Access Requests</h2>
  <div class="mini">
    Showing <b><?= count($requests) ?></b> pending request(s).
  </div>
</div>

<div class="tableWrap">
  <table class="docTable" style="min-width: 980px;">
    <thead>
      <tr>
        <th style="width:70px;">ID</th>
        <th style="width:240px;">Full Name</th>
        <th style="width:240px;">Office / Section (Requested)</th>
        <th style="width:220px;">Email</th>
        <th>Reason</th>
        <th style="width:150px;">Requested</th>
        <th style="width:240px;">Action</th>
      </tr>
    </thead>

    <tbody id="reqBody">
      <?php if (!$requests): ?>
        <tr>
          <td colspan="7" style="text-align:center;padding:18px;">
            No pending requests 🎉
          </td>
        </tr>
      <?php endif; ?>

      <?php foreach ($requests as $r): ?>
        <tr data-id="<?= (int)$r["id"] ?>">
          <td style="text-align:center;"><b><?= (int)$r["id"] ?></b></td>
          <td><?= htmlspecialchars((string)$r["full_name"]) ?></td>
          <td><?= htmlspecialchars((string)$r["office_section"]) ?></td>
          <td><?= htmlspecialchars((string)$r["email"]) ?></td>
          <td><?= nl2br(htmlspecialchars((string)$r["reason"])) ?></td>
          <td style="text-align:center;"><?= htmlspecialchars((string)$r["created_at"]) ?></td>
          <td style="text-align:center;">
            <button
                type="button"
                class="btn js-approve"
                data-id="<?= (int)$r['id'] ?>"
                data-name="<?= htmlspecialchars((string)$r['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                data-email="<?= htmlspecialchars((string)$r['email'], ENT_QUOTES, 'UTF-8') ?>"
            >
            Approve
            </button>

            <button
                type="button"
                class="btn js-reject"
                data-id="<?= (int)$r['id'] ?>"
                style="margin-left:8px;background:#b42318;border-color:#b42318;"
            >
            Reject
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Approve Modal -->
<div id="approveModal" class="modalWrap" aria-hidden="true">
  <div class="modalBackdrop" onclick="closeApproveModal()"></div>

  <div class="modalCard" role="dialog" aria-modal="true" aria-labelledby="approveModalTitle">
    <div class="modalHeader">
      <h3 id="approveModalTitle">Approve Request</h3>
      <button type="button" class="modalClose" onclick="closeApproveModal()" aria-label="Close">✕</button>
    </div>

    <div class="modalBody">
      <div class="notice" style="margin-bottom:12px;">
        Approving will create a new user account and generate a temporary password.
      </div>

      <div class="mini" style="margin-bottom:10px;">
        <b id="approveName"></b> — <span id="approveEmail"></span>
      </div>

      <form id="approveForm" onsubmit="submitApprove(event)">
        <input type="hidden" name="id" id="approveReqId" value="">
        <input type="hidden" name="action" value="APPROVE">

        <div class="authField">
          <label>Assign Official Section/Division (required)</label>
          <select name="section_id" id="approveSection" required>
            <option value="">-- Select Section --</option>
            <?php foreach ($sections as $s): ?>
              <option value="<?= (int)$s["id"] ?>">
                <?= htmlspecialchars((string)$s["division_name"]) ?> — <?= htmlspecialchars((string)$s["name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="authField">
          <label>Admin Notes (optional)</label>
          <textarea name="admin_notes" class="modalTextarea" placeholder="Notes for audit trail..." style="min-height:90px;"></textarea>
        </div>

        <div id="approveMsg" class="modalMsg error" style="display:none;margin-top:10px;"></div>

        <div class="modalFooter">
          <button type="button" class="btnSecondary" onclick="closeApproveModal()">Cancel</button>
          <button type="submit" class="btn">Approve</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Result Modal (Access Approved) -->
<div id="resultModal" class="modalWrap" aria-hidden="true">
  <div class="modalBackdrop" onclick="closeResultModal()"></div>

  <div class="modalCard" role="dialog" aria-modal="true" aria-labelledby="resultModalTitle">
    <div class="modalHeader">
      <h3 id="resultModalTitle">Access Approved</h3>
      <button type="button" class="modalClose" onclick="closeResultModal()" aria-label="Close">✕</button>
    </div>

    <div class="modalBody">
      <div class="notice" style="margin-bottom:12px;">
        Copy the credentials and send the email draft manually (SMTP not configured yet).
      </div>

      <div class="authField">
        <label>Username</label>
        <input id="resUsername" type="text" readonly>
      </div>

      <div class="authField">
        <label>Temporary Password</label>
        <input id="resTemp" type="text" readonly>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0 16px;">
        <button type="button" class="btn" onclick="copyText('resUsername')">Copy Username</button>
        <button type="button" class="btn" onclick="copyText('resTemp')">Copy Temp Password</button>
      </div>

      <hr style="border:0;border-top:1px solid #eef0f2;margin:16px 0;" />

      <div class="authField">
        <label>Email Subject</label>
        <input id="resSubject" type="text" readonly>
      </div>

      <div class="authField">
        <label>Email Body</label>
        <textarea id="resBody" class="modalTextarea" readonly style="min-height:170px;"></textarea>
      </div>

      <button type="button" class="btn" onclick="copyEmailDraft()">Copy Email Draft</button>
    </div>

    <div class="modalFooter">
      <button type="button" class="btnSecondary" onclick="closeResultModal()">Done</button>
    </div>
  </div>
</div>

<!-- Sticky "Last Approved" bar -->
<div id="lastApprovedBar" style="
  display:none;
  position:fixed;
  left:16px;
  bottom:16px;
  z-index:9999;
  background:#0b2a4a;
  color:#fff;
  padding:10px 12px;
  border-radius:12px;
  box-shadow:0 10px 30px rgba(0,0,0,.2);
  max-width:420px;
">
  <div style="font-weight:700; margin-bottom:4px;">Last Approved</div>
  <div class="mini" id="lastApprovedEmail" style="opacity:.9;"></div>

  <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
    <button type="button" class="btn" onclick="openLastApproved()">View Credentials</button>
    <button type="button" class="btn" style="background:#334155;border-color:#334155;" onclick="clearLastApproved()">Clear</button>
  </div>
</div>

<script>
let currentApproveId = null;

function saveLastApproved(payload) {
  const safe = {
    username: payload.username || '',
    temp_password: payload.temp_password || '',
    email_subject: payload.email_subject || '',
    email_body: payload.email_body || '',
    saved_at: new Date().toISOString(),
  };
  sessionStorage.setItem('last_approved', JSON.stringify(safe));
  renderLastApprovedBar();
}

function renderLastApprovedBar() {
  const raw = sessionStorage.getItem('last_approved');
  const bar = document.getElementById('lastApprovedBar');
  const emailEl = document.getElementById('lastApprovedEmail');
  if (!bar || !emailEl) return;

  if (!raw) {
    bar.style.display = 'none';
    return;
  }
  try {
    const obj = JSON.parse(raw);
    emailEl.textContent = obj.username || '';
    bar.style.display = 'block';
  } catch (e) {
    bar.style.display = 'none';
  }
}

function openLastApproved() {
  const raw = sessionStorage.getItem('last_approved');
  if (!raw) return;
  try {
    const obj = JSON.parse(raw);
    openResultModal(obj);
  } catch (e) {}
}

function clearLastApproved() {
  sessionStorage.removeItem('last_approved');
  renderLastApprovedBar();
}

document.addEventListener('DOMContentLoaded', renderLastApprovedBar);

function openApproveModal(id, name, email) {
  currentApproveId = id;
  document.getElementById('approveReqId').value = String(id);
  document.getElementById('approveName').textContent = name;
  document.getElementById('approveEmail').textContent = email;
  document.getElementById('approveSection').value = '';

  const msg = document.getElementById('approveMsg');
  msg.style.display = 'none';

  const wrap = document.getElementById('approveModal');
  wrap.setAttribute('aria-hidden', 'false');
  wrap.classList.add('open');
}

function closeApproveModal() {
  const wrap = document.getElementById('approveModal');
  wrap.setAttribute('aria-hidden', 'true');
  wrap.classList.remove('open');
}

function openResultModal(data) {
  document.getElementById('resUsername').value = data.username || '';
  document.getElementById('resTemp').value = data.temp_password || '';
  document.getElementById('resSubject').value = data.email_subject || '';
  document.getElementById('resBody').value = data.email_body || '';

  const wrap = document.getElementById('resultModal');
  wrap.setAttribute('aria-hidden', 'false');
  wrap.classList.add('open');
}

function closeResultModal() {
  const wrap = document.getElementById('resultModal');
  wrap.setAttribute('aria-hidden', 'true');
  wrap.classList.remove('open');
}

async function submitApprove(e) {
  e.preventDefault();

  const form = document.getElementById('approveForm');
  const msg = document.getElementById('approveMsg');

  msg.style.display = 'none';
  msg.textContent = '';

  const fd = new FormData(form);
  fd.set('csrf_token', window.__CSRF__);

  // Useful for email draft
  fd.set('login_url', window.location.origin + window.__APP__.public + '/login.php');

  const API = (window.__APP__ && window.__APP__.api) ? window.__APP__.api : '/document-tracker/api';

  try {
    const res = await fetch(`${API}/process_access_request.php`, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });

    // ✅ Safe JSON parse (won’t crash if server returns HTML)
    const data = await res.json().catch(() => ({}));

    if (!res.ok || !data.ok) {
      msg.style.display = 'block';
      msg.textContent = data.error || `Approve failed (HTTP ${res.status}). Check Apache/PHP error logs.`;
      return;
    }

    // Remove row
    const tr = document.querySelector(`tr[data-id="${currentApproveId}"]`);
    if (tr) tr.remove();

    closeApproveModal();
    openResultModal(data);
    saveLastApproved(data);

    // Empty state
    const body = document.getElementById('reqBody');
    if (body && body.querySelectorAll('tr').length === 0) {
      const empty = document.createElement('tr');
      empty.innerHTML =
        '<td colspan="7" style="text-align:center;padding:18px;">' +
        'No pending requests' +
        '</td>';
      body.appendChild(empty);
    }

  } catch (err) {
    msg.style.display = 'block';
    msg.textContent = 'Network / server error. (Check console + Network tab).';
  }
}

async function rejectReq(id) {
  const note = prompt("Reason / Admin note (optional):") ?? "";

  const fd = new FormData();
  fd.set('csrf_token', window.__CSRF__);
  fd.set('id', String(id));
  fd.set('action', 'REJECT');
  fd.set('admin_notes', note);

  try {
    const res = await fetch(window.__APP__.api + '/process_access_request.php', {
      method: 'POST',
      body: fd,
    });

    const data = await res.json();
    if (!data.ok) {
      alert(data.error || 'Failed to reject.');
      return;
    }

    const tr = document.querySelector(`tr[data-id="${id}"]`);
    if (tr) tr.remove();

    const body = document.getElementById('reqBody');
    if (body && body.children.length === 0) {
      const empty = document.createElement('tr');
      empty.innerHTML = `
        <td colspan="7" style="text-align:center;padding:18px;">
          No pending requests 🎉
        </td>
      `;
      body.appendChild(empty);
    }

    alert('Rejected.');

  } catch (err) {
    alert('Network error.');
  }
}

function copyText(inputId) {
  const el = document.getElementById(inputId);
  if (!el) return;

  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(el.value || '').then(() => {
      alert('Copied.');
    }).catch(() => {
      // fallback
      el.select();
      el.setSelectionRange(0, 99999);
      document.execCommand('copy');
      alert('Copied.');
    });
  } else {
    el.select();
    el.setSelectionRange(0, 99999);
    document.execCommand('copy');
    alert('Copied.');
  }
}

function copyEmailDraft() {
  const subject = document.getElementById('resSubject').value;
  const body = document.getElementById('resBody').value;
  const text = 'Subject: ' + subject + "\n\n" + body;

  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text)
      .then(() => alert('Email draft copied.'))
      .catch(() => fallbackCopy(text));
  } else {
    fallbackCopy(text);
  }
}

function fallbackCopy(text) {
  const ta = document.createElement('textarea');
  ta.value = text;
  document.body.appendChild(ta);
  ta.select();
  document.execCommand('copy');
  ta.remove();
  alert('Email draft copied.');
}

document.addEventListener('click', (e) => {
  const approveBtn = e.target.closest('.js-approve');
  if (approveBtn) {
    const id = Number(approveBtn.dataset.id || 0);
    const name = approveBtn.dataset.name || '';
    const email = approveBtn.dataset.email || '';
    openApproveModal(id, name, email);
    return;
  }

  const rejectBtn = e.target.closest('.js-reject');
  if (rejectBtn) {
    const id = Number(rejectBtn.dataset.id || 0);
    rejectReq(id);
    return;
  }
});
</script>

<?php require __DIR__ . "/../includes/footer.php"; ?>