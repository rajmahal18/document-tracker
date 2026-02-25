<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

$pageTitle = "Change Password - Document Tracker";

require __DIR__ . "/../includes/layout.php";
?>

<section class="card" style="max-width:560px;margin:0 auto;">
  <h2 style="margin:0 0 6px;">Change Password</h2>

  <?php if ((int)($_SESSION["must_change_password"] ?? 0) === 1): ?>
    <div class="notice" style="margin:12px 0;">
      For security, you must change your temporary password before continuing.
    </div>
  <?php else: ?>
    <div class="mini" style="margin:12px 0;opacity:.8;">
      Update your password.
    </div>
  <?php endif; ?>

  <form id="pwForm" class="authForm" onsubmit="submitPw(event)">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <div class="authField">
      <label for="current_password">Current Password</label>
      <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
    </div>

    <div class="authField">
      <label for="new_password">New Password</label>
      <input id="new_password" name="new_password" type="password" autocomplete="new-password" required minlength="8">
      <div class="mini" style="opacity:.75;margin-top:6px;">Minimum 8 characters.</div>
    </div>

    <div class="authField">
      <label for="confirm_password">Confirm New Password</label>
      <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required minlength="8">
    </div>

    <div id="pwMsg" class="notice" style="display:none;margin-top:12px;"></div>

    <button type="submit" class="authBtn" style="margin-top:12px;">Save Password</button>
  </form>
</section>

<script>
async function submitPw(e) {
  e.preventDefault();

  const form = document.getElementById('pwForm');
  const msg = document.getElementById('pwMsg');

  msg.style.display = 'none';
  msg.style.background = '#e7f4ff';
  msg.style.border = '1px solid #b6e0ff';

  const fd = new FormData(form);

  try {
    const res = await fetch(window.__APP__.api + '/change_password.php', {
      method: 'POST',
      body: fd,
    });

    const data = await res.json();

    if (!data.ok) {
      msg.style.display = 'block';
      msg.style.background = '#f8d7da';
      msg.style.border = '1px solid #f5c2c7';
      msg.textContent = data.error || 'Failed to change password.';
      return;
    }

    msg.style.display = 'block';
    msg.textContent = 'Password updated. Redirecting...';

    setTimeout(() => {
      window.location.href = window.__APP__.public + '/documents.php';
    }, 600);

  } catch (err) {
    msg.style.display = 'block';
    msg.style.background = '#f8d7da';
    msg.style.border = '1px solid #f5c2c7';
    msg.textContent = 'Network error. Please try again.';
  }
}
</script>

<?php require __DIR__ . "/../includes/footer.php"; ?>
