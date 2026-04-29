<?php
declare(strict_types=1);

function email_verification_tokens_table_exists(mysqli $conn): bool {
  return db_table_exists($conn, 'email_verification_tokens');
}

function email_verification_issue_token(mysqli $conn, int $userId, ?string $ip = null, int $ttlHours = 24): array {
  if ($userId <= 0) {
    return ['ok' => false, 'error' => 'Invalid user.'];
  }
  if (!email_verification_tokens_table_exists($conn)) {
    return ['ok' => false, 'error' => 'Email verification table is missing. Please run migrations.'];
  }

  $rawToken = bin2hex(random_bytes(32));
  $tokenHash = hash('sha256', $rawToken);
  $ip = trim((string)$ip);
  $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
    ->modify('+' . max(1, $ttlHours) . ' hours')
    ->format('Y-m-d H:i:s');

  $conn->begin_transaction();
  try {
    $stmtPurge = $conn->prepare('
      UPDATE email_verification_tokens
      SET used_at = NOW()
      WHERE user_id = ?
        AND used_at IS NULL
    ');
    $stmtPurge->bind_param('i', $userId);
    $stmtPurge->execute();
    $stmtPurge->close();

    $stmt = $conn->prepare('
      INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, requested_ip)
      VALUES (?, ?, ?, ?)
    ');
    $stmt->bind_param('isss', $userId, $tokenHash, $expiresAt, $ip);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
  } catch (Throwable $e) {
    $conn->rollback();
    return ['ok' => false, 'error' => 'Failed to issue verification token.'];
  }

  return [
    'ok' => true,
    'token' => $rawToken,
    'expires_at' => $expiresAt,
  ];
}

function email_verification_consume_token(mysqli $conn, string $rawToken): array {
  $rawToken = trim($rawToken);
  if ($rawToken === '' || strlen($rawToken) < 32) {
    return ['ok' => false, 'error' => 'Invalid verification token.'];
  }
  if (!email_verification_tokens_table_exists($conn)) {
    return ['ok' => false, 'error' => 'Email verification table is missing. Please run migrations.'];
  }
  if (!email_verified_at_column_exists($conn)) {
    return ['ok' => false, 'error' => 'users.email_verified_at column is missing.'];
  }

  $tokenHash = hash('sha256', $rawToken);
  $conn->begin_transaction();
  try {
    $stmt = $conn->prepare('
      SELECT id, user_id, expires_at, used_at
      FROM email_verification_tokens
      WHERE token_hash = ?
      LIMIT 1
    ');
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $tokenRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tokenRow) {
      $conn->rollback();
      return ['ok' => false, 'error' => 'Verification link is invalid.'];
    }

    if (!empty($tokenRow['used_at'])) {
      $conn->rollback();
      return ['ok' => false, 'error' => 'Verification link was already used.'];
    }

    $expiresAt = strtotime((string)$tokenRow['expires_at']);
    if ($expiresAt === false || $expiresAt < time()) {
      $stmtExpire = $conn->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE id = ? LIMIT 1');
      $tokenId = (int)$tokenRow['id'];
      $stmtExpire->bind_param('i', $tokenId);
      $stmtExpire->execute();
      $stmtExpire->close();
      $conn->commit();
      return ['ok' => false, 'error' => 'Verification link has expired. Please request a new one.'];
    }

    $userId = (int)$tokenRow['user_id'];

    $stmtVerify = $conn->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ? LIMIT 1');
    $stmtVerify->bind_param('i', $userId);
    $stmtVerify->execute();
    $stmtVerify->close();

    $stmtUse = $conn->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE id = ? LIMIT 1');
    $tokenId = (int)$tokenRow['id'];
    $stmtUse->bind_param('i', $tokenId);
    $stmtUse->execute();
    $stmtUse->close();

    $conn->commit();
    return ['ok' => true, 'user_id' => $userId];
  } catch (Throwable $e) {
    $conn->rollback();
    return ['ok' => false, 'error' => 'Failed to verify email.'];
  }
}
