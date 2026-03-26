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

require __DIR__ . '/../includes/layout.php';
?>

<div class="grid" style="grid-template-columns:minmax(0,1.2fr) minmax(280px,.8fr); gap:18px; align-items:start;">
  <section class="card">
    <h2 style="margin:0 0 6px;">My Account</h2>
    
    <form id="accountForm" class="authForm" onsubmit="submitAccountUpdate(event)">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

      <div class="authField">
        <label for="account_full_name">Account owner / Full name</label>
        <input id="account_full_name" name="full_name" type="text" required maxlength="200" value="<?= htmlspecialchars((string)($user['full_name'] ?? '')) ?>">
        <div class="mini" style="opacity:.75;margin-top:6px;">Changing this updates the account owner while keeping existing tasks and documents attached to the same account.</div>
      </div>

      <div class="authField">
        <label for="account_username">Username</label>
        <input id="account_username" type="text" value="<?= htmlspecialchars((string)($user['username'] ?? $generatedUsername)) ?>" readonly>
        <div class="mini" style="opacity:.75;margin-top:6px;">Auto-generated: given-name initial/s + middle initial + surname. This can be used to log in.</div>
      </div>

      <div class="authField">
        <label for="account_email">Email</label>
        <input id="account_email" name="email" type="email" required maxlength="200" value="<?= htmlspecialchars((string)($user['email'] ?? '')) ?>">
      </div>

      <div id="accountMsg" class="notice" style="display:none;margin-top:12px;"></div>
      <button type="submit" class="authBtn" style="margin-top:12px;">Save Account Details</button>
    </form>
  </section>

  <aside class="aside">
    <div class="asideBox">
      <p class="asideTitle">Current org assignment</p>
      <ul>
        <li><strong>Division:</strong> <?= htmlspecialchars((string)($_SESSION['division_name'] ?? '—')) ?></li>
        <li><strong>Section:</strong> <?= htmlspecialchars((string)($_SESSION['section_name'] ?? '—')) ?></li>
        <li><strong>Title:</strong> <?= htmlspecialchars((string)($_SESSION['official_title'] ?? '—')) ?></li>
        <li><strong>Authority role:</strong> <?= htmlspecialchars((string)($_SESSION['authority_role'] ?? 'staff')) ?></li>
      </ul>
    </div>

    <div class="asideBox" style="margin-top:14px;">
      <p class="asideTitle">Email status</p>
      <p class="mini" style="opacity:.85;">
        <?php if (!empty($user['email_verified_at'])): ?>
          Verified on <?= htmlspecialchars((string)$user['email_verified_at']) ?>
        <?php else: ?>
          Not verified yet.
        <?php endif; ?>
      </p>
    </div>

    <div class="asideBox" style="margin-top:14px;">
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
