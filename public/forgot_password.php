<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/mailer.php';

$pageTitle = 'Forgot Password - Document Tracker';
$message = '';
$error = '';
$debugNotice = '';

function page_csrf_ok(): bool {
  $token = $_POST['csrf_token'] ?? '';
  return is_string($token) && $token !== '' && hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!page_csrf_ok()) {
    $error = 'Invalid request token. Please refresh and try again.';
  } else {
    $email = trim((string)($_POST['email'] ?? ''));
    $generic = 'If the email exists in our system, a password reset link has been sent.';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $message = $generic;
    } else {
      $stmt = $conn->prepare('SELECT id, full_name, email, is_active FROM users WHERE email = ? LIMIT 1');
      $stmt->bind_param('s', $email);
      $stmt->execute();
      $user = $stmt->get_result()->fetch_assoc() ?: null;
      $stmt->close();

      if ($user && (int)($user['is_active'] ?? 1) === 1) {
        $userId = (int)$user['id'];
        $nowTs = time();
        $expiresTs = $nowTs + (60 * 60);
        $tokenPlain = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $tokenPlain);
        $expiresAt = date('Y-m-d H:i:s', $expiresTs);
        $requestIp = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        $conn->begin_transaction();
        try {
          $invalidate = $conn->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
          if (!$invalidate) {
            throw new RuntimeException('Failed to prepare token invalidation query.');
          }
          $invalidate->bind_param('i', $userId);
          $invalidate->execute();
          $invalidate->close();

          $ins = $conn->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, requested_ip) VALUES (?, ?, ?, ?)');
          if (!$ins) {
            throw new RuntimeException('Failed to prepare token insert query.');
          }
          $ins->bind_param('isss', $userId, $tokenHash, $expiresAt, $requestIp);
          $ins->execute();
          $ins->close();
          $conn->commit();

          $resetLink = app_url(PUBLIC_PATH . '/reset_password.php?token=' . urlencode($tokenPlain));
          $subject = 'Reset your MPW Document Tracker password';
          $safeName = htmlspecialchars((string)($user['full_name'] ?? 'User'), ENT_QUOTES, 'UTF-8');
          $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
          $htmlBody = <<<HTML
<p>Hello {$safeName},</p>
<p>We received a request to reset your MPW Document Tracker password.</p>
<p><a href="{$safeLink}">Reset your password</a></p>
<p>This link will expire in 1 hour. If you did not request this, you can safely ignore this email.</p>
HTML;
          $textBody = "Hello {$user['full_name']},\n\nReset your password using this link:\n{$resetLink}\n\nThis link expires in 1 hour.";
          $mailResult = app_send_mail((string)$user['email'], (string)($user['full_name'] ?? ''), $subject, $htmlBody, $textBody);
          if (empty($mailResult['ok'])) {
            $mailError = (string)($mailResult['error'] ?? 'Unknown mailer error');
            error_log('Forgot password mail failed: ' . $mailError);
            if (app_is_dev_environment()) {
              $debugNotice = 'Dev notice: email was not sent. ' . $mailError;
            }
          }
        } catch (Throwable $e) {
          $conn->rollback();
          error_log('Forgot password error: ' . $e->getMessage());
          if (app_is_dev_environment()) {
            $debugNotice = 'Dev notice: forgot-password failed: ' . $e->getMessage();
          }
        }
      }
      $message = $generic;
    }
  }
}

require __DIR__ . '/../includes/layout.php';
?>
<div class="grid">
  <section class="card">
    <h2>Forgot Password</h2>
    <p class="mini" style="opacity:.8;">Enter your account email and we’ll send you a reset link.</p>

    <?php if ($error !== ''): ?>
      <div class="notice" style="background:#f8d7da;border:1px solid #f5c2c7;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
      <div class="notice" style="background:#e7f4ff;border:1px solid #b6e0ff;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($debugNotice !== ''): ?>
      <div class="notice" style="background:#fff4e5;border:1px solid #ffd8a8;"><?= htmlspecialchars($debugNotice) ?></div>
    <?php endif; ?>

    <form class="authForm" method="POST" action="<?= PUBLIC_PATH ?>/forgot_password.php" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <div class="authField">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" required maxlength="200" placeholder="Enter your account email">
      </div>
      <button type="submit" class="authBtn">Send reset link</button>
      <p class="authHelp"><a class="authLink" href="<?= PUBLIC_PATH ?>/login.php">Back to login</a></p>
    </form>
  </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
