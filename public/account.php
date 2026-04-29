<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_login();

$pageTitle = 'My Account - Document Tracker';
$userId = (int)($_SESSION['user_id'] ?? 0);
$hasUsername = username_column_exists($conn);
$hasEmailVerifiedAt = email_verified_at_column_exists($conn);

$sql = 'SELECT id, full_name, email, ' . ($hasUsername ? 'username' : 'NULL') . ' AS username, ' . ($hasEmailVerifiedAt ? 'email_verified_at' : 'NULL') . ' AS email_verified_at, official_title, authority_role FROM users WHERE id = ? LIMIT 1';
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$generatedUsername = generate_unique_username($conn, (string)($user['full_name'] ?? ($_SESSION['full_name'] ?? '')), $userId);
$profilePhotoUrl = trim((string)($_SESSION['profile_photo_url'] ?? ''));
$profileInitials = function_exists('app_user_initials')
  ? app_user_initials((string)($user['full_name'] ?? ($_SESSION['full_name'] ?? 'U')))
  : strtoupper(substr((string)($user['full_name'] ?? ($_SESSION['full_name'] ?? 'U')), 0, 1));

require __DIR__ . '/../includes/layout.php';
?>

<style>
  .accountPage { display:grid; grid-template-columns:minmax(0,1.25fr) minmax(300px,.75fr); gap:18px; align-items:start; }
  .accountMain { padding:22px; border-radius:20px; }
  .accountHero { display:flex; align-items:center; gap:14px; padding-bottom:14px; margin-bottom:16px; border-bottom:1px solid rgba(37,99,235,.14); }
  .accountHeroName { font-size:24px; line-height:1.1; font-weight:900; margin:0; color:#0f172a; }
  .accountHeroMeta { margin:2px 0 0; color:#5b6b81; font-weight:700; font-size:13px; }
  .accountLabelHint { opacity:.82; margin-top:6px; }
  .accountAside { display:grid; gap:14px; }
  .accountAside .asideBox { border-radius:16px; }
  .accountOrgList { margin:0; padding:0; list-style:none; display:grid; gap:8px; }
  .accountOrgList li { display:flex; gap:8px; align-items:baseline; }
  .accountOrgList strong { min-width:110px; color:#334155; }
  .accountStatusPill { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; font-size:12px; font-weight:800; letter-spacing:.02em; background:#fff7ed; color:#b45309; border:1px solid rgba(245,158,11,.28); }
  .accountStatusPill.isOk { background:#ecfdf3; color:#166534; border-color:rgba(22,163,74,.24); }
  @media (max-width:980px) { .accountPage { grid-template-columns:1fr; } .accountMain { padding:18px; } .accountHeroName { font-size:21px; } }
  body[data-theme="dark"] .accountHero { border-bottom-color:rgba(148,197,255,.22); }
  body[data-theme="dark"] .accountHeroName { color:#e6f0ff; }
  body[data-theme="dark"] .accountHeroMeta { color:#b7c8dc; }
  body[data-theme="dark"] .accountOrgList strong { color:#c7d7eb; }
  body[data-theme="dark"] .accountStatusPill { background:rgba(180,83,9,.2); color:#ffd9a1; border-color:rgba(251,191,36,.32); }
  body[data-theme="dark"] .accountStatusPill.isOk { background:rgba(22,163,74,.2); color:#b9f8d0; border-color:rgba(74,222,128,.3); }
</style>

<div class="accountPage">
  <section class="card accountMain">
    <div class="accountHero">
      <span class="appAvatar appAvatarMd" aria-hidden="true">
        <?php if ($profilePhotoUrl !== ''): ?>
          <img src="<?= htmlspecialchars($profilePhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
        <?php else: ?>
          <span><?= htmlspecialchars($profileInitials) ?></span>
        <?php endif; ?>
      </span>
      <div>
        <h2 class="accountHeroName">My Account</h2>
        <p class="accountHeroMeta">Manage profile identity and sign-in details</p>
      </div>
    </div>

    <form id="accountForm" class="authForm" onsubmit="submitAccountUpdate(event)">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

      <div class="authField">
        <label for="account_full_name">Account owner / Full name</label>
        <input id="account_full_name" name="full_name" type="text" required maxlength="200" value="<?= htmlspecialchars((string)($user['full_name'] ?? '')) ?>">
        <div class="mini accountLabelHint">Changing this updates the account owner while keeping existing tasks and documents attached to the same account.</div>
      </div>

      <div class="authField">
        <label for="account_username">Username</label>
        <input id="account_username" type="text" value="<?= htmlspecialchars((string)($user['username'] ?? $generatedUsername)) ?>" readonly>
        <div class="mini accountLabelHint">Auto-generated: given-name initial/s + middle initial + surname. This can be used to log in.</div>
      </div>

      <div class="authField">
        <label for="account_email">Email</label>
        <input id="account_email" name="email" type="email" required maxlength="200" value="<?= htmlspecialchars((string)($user['email'] ?? '')) ?>">
      </div>

      <div id="accountMsg" class="notice" style="display:none;margin-top:12px;"></div>
      <button type="submit" class="authBtn" style="margin-top:12px;">Save Account Details</button>
    </form>
  </section>

  <aside class="aside accountAside">
    <div class="asideBox">
      <p class="asideTitle">Current org assignment</p>
      <ul class="accountOrgList">
        <li><strong>Division:</strong> <span><?= htmlspecialchars((string)($_SESSION['division_name'] ?? '-')) ?></span></li>
        <li><strong>Section:</strong> <span><?= htmlspecialchars((string)($_SESSION['section_name'] ?? '-')) ?></span></li>
        <li><strong>Title:</strong> <span><?= htmlspecialchars((string)($_SESSION['official_title'] ?? '-')) ?></span></li>
        <li><strong>Authority role:</strong> <span><?= htmlspecialchars((string)($_SESSION['authority_role'] ?? 'staff')) ?></span></li>
      </ul>
    </div>

    <div class="asideBox">
      <p class="asideTitle">Email status</p>
      <?php if (!empty($user['email_verified_at'])): ?>
        <span class="accountStatusPill isOk">Verified on <?= htmlspecialchars((string)$user['email_verified_at']) ?></span>
      <?php else: ?>
        <span class="accountStatusPill">Not verified yet</span>
      <?php endif; ?>
    </div>

    <div class="asideBox">
      <p class="asideTitle">Security</p>
      <a class="authLink" href="<?= PUBLIC_PATH ?>/change_password.php">Change password</a>
    </div>
  </aside>
</div>

<script>
(function(){
  const nameInput = document.getElementById('account_full_name');
  const usernameInput = document.getElementById('account_username');
  if (!nameInput || !usernameInput) return;

  function localGenerate(fullName) {
    const parts = String(fullName || '').trim().split(/\s+/).filter(Boolean).map(p => p.replace(/[^a-z0-9]/gi, ''));
    if (!parts.length) return '';
    const count = parts.length;
    let last = (parts[count - 1] || '').toLowerCase();
    let middle = '';
    let given = [parts[0] || ''];
    if (count >= 3) {
      middle = ((parts[count - 2] || '').charAt(0) || '').toLowerCase();
      given = parts.slice(0, count - 2);
    }
    const givenInitials = given.map(v => (v.charAt(0) || '').toLowerCase()).join('');
    return (givenInitials + middle + last).replace(/[^a-z0-9]/g, '') || 'user';
  }

  nameInput.addEventListener('input', () => {
    usernameInput.value = localGenerate(nameInput.value);
  });
})();

async function submitAccountUpdate(e) {
  e.preventDefault();
  const form = document.getElementById('accountForm');
  const msg = document.getElementById('accountMsg');
  msg.style.display = 'none';
  const fd = new FormData(form);

  try {
    const res = await fetch(window.__APP__.api + '/account_update.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });
    const data = await res.json();
    msg.style.display = 'block';
    if (!data.ok) {
      msg.style.background = '#f8d7da';
      msg.style.border = '1px solid #f5c2c7';
      msg.textContent = data.error || 'Failed to update account.';
      return;
    }
    msg.style.background = '#e7f4ff';
    msg.style.border = '1px solid #b6e0ff';
    msg.textContent = data.message || 'Account details updated.';
    if (data.username) {
      document.getElementById('account_username').value = data.username;
    }
    window.setTimeout(() => window.location.reload(), 500);
  } catch (err) {
    msg.style.display = 'block';
    msg.style.background = '#f8d7da';
    msg.style.border = '1px solid #f5c2c7';
    msg.textContent = 'Network error. Please try again.';
  }
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
