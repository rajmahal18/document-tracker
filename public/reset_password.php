<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Reset Password - Document Tracker';
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$message = '';
$tokenOk = false;
$tokenRow = null;

function page_csrf_ok(): bool {
  $token = $_POST['csrf_token'] ?? '';
  return is_string($token) && $token !== '' && hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token);
}

if ($token !== '' && ctype_xdigit($token) && strlen($token) >= 32) {
  $tokenHash = hash('sha256', $token);
  $stmt = $conn->prepare(
    "SELECT prt.id, prt.user_id, prt.expires_at, prt.used_at, u.email
     FROM password_reset_tokens prt
     INNER JOIN users u ON u.id = prt.user_id
     WHERE prt.token_hash = ?
     LIMIT 1"
  );
  if ($stmt) {
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $tokenRow = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
  }

  if ($tokenRow) {
    $expired = strtotime((string)$tokenRow['expires_at']) < time();
    $used = !empty($tokenRow['used_at']);
    $tokenOk = (!$expired && !$used);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!page_csrf_ok()) {
    $error = 'Invalid request token. Please refresh and try again.';
  } elseif (!$tokenOk || !$tokenRow) {
    $error = 'This reset link is invalid or expired.';
  } else {
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');
    if (strlen($password) < 8) {
      $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
      $error = 'Passwords do not match.';
    } else {
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $resetId = (int)$tokenRow['id'];
      $userId = (int)$tokenRow['user_id'];
      $conn->begin_transaction();
      try {
        $upd = $conn->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?');
        if (!$upd) {
          throw new RuntimeException('Failed to prepare user password update query.');
        }
        $upd->bind_param('si', $hash, $userId);
        $upd->execute();
        $upd->close();

        $mark = $conn->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?');
        if (!$mark) {
          throw new RuntimeException('Failed to prepare token update query.');
        }
        $mark->bind_param('i', $resetId);
        $mark->execute();
        $mark->close();

        $conn->commit();
        $message = 'Password reset successful. You can now log in.';
        $tokenOk = false;
      } catch (Throwable $e) {
        $conn->rollback();
        $error = 'Failed to reset password. Please try again.';
      }
    }
  }
}

require __DIR__ . '/../includes/layout.php';
?>
<div class="grid">
  <section class="card">
    <h2>Reset Password</h2>
    <?php if ($error !== ''): ?>
      <div class="notice" style="background:#f8d7da;border:1px solid #f5c2c7;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
      <div class="notice" style="background:#e7f4ff;border:1px solid #b6e0ff;"><?= htmlspecialchars($message) ?></div>
      <p class="authHelp"><a class="authLink" href="<?= PUBLIC_PATH ?>/login.php">Proceed to login</a></p>
    <?php elseif (!$tokenOk): ?>
      <div class="notice" style="background:#f8d7da;border:1px solid #f5c2c7;">This reset link is invalid or expired.</div>
      <p class="authHelp"><a class="authLink" href="<?= PUBLIC_PATH ?>/forgot_password.php">Request a new link</a></p>
    <?php else: ?>
      <form class="authForm" method="POST" action="<?= PUBLIC_PATH ?>/reset_password.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <div class="authField">
          <label for="password">New Password</label>
          <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
        </div>
        <div class="authField">
          <label for="password_confirm">Confirm New Password</label>
          <input id="password_confirm" name="password_confirm" type="password" required minlength="8" autocomplete="new-password">
        </div>
        <button type="submit" class="authBtn">Reset password</button>
      </form>
    <?php endif; ?>
  </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
