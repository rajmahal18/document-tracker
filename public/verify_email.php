<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Verify Email - Document Tracker';
$token = trim((string)($_GET['token'] ?? ''));
$result = ['ok' => false, 'error' => 'Invalid verification link.'];

if ($token !== '') {
  $result = email_verification_consume_token($conn, $token);
  if (!empty($result['ok']) && is_logged_in()) {
    $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
    $verifiedUserId = (int)($result['user_id'] ?? 0);
    if ($sessionUserId > 0 && $verifiedUserId === $sessionUserId) {
      refresh_session_identity($conn, $sessionUserId);
    }
  }
}

require __DIR__ . '/../includes/layout.php';
?>

<div class="grid" style="grid-template-columns:1fr;">
  <section class="card" style="max-width:760px;">
    <h2>Email Verification</h2>

    <?php if (!empty($result['ok'])): ?>
      <div class="notice" style="background:#ecfdf3;border:1px solid #86efac;">
        Your email has been verified successfully.
      </div>
      <p class="mini" style="margin-top:10px;">
        You can now continue using your account normally.
      </p>
      <p style="margin-top:14px;">
        <a class="btnSecondary" href="<?= PUBLIC_PATH ?>/documents.php">Go to Documents</a>
      </p>
    <?php else: ?>
      <div class="notice" style="background:#fff1f2;border:1px solid #fecdd3;">
        <?= htmlspecialchars((string)($result['error'] ?? 'Verification failed.')) ?>
      </div>
      <p class="mini" style="margin-top:10px;">
        Request a new verification email from My Account.
      </p>
      <p style="margin-top:14px;">
        <a class="btnSecondary" href="<?= is_logged_in() ? (PUBLIC_PATH . '/account.php') : (PUBLIC_PATH . '/login.php') ?>">
          <?= is_logged_in() ? 'Go to My Account' : 'Back to Login' ?>
        </a>
      </p>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
