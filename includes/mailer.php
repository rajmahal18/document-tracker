<?php
declare(strict_types=1);

function app_send_mail(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): array {
  $autoload = __DIR__ . '/../vendor/autoload.php';
  if (!is_file($autoload)) {
    return ['ok' => false, 'error' => 'Mailer dependency is missing. Run composer install.'];
  }
  require_once $autoload;

  if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    return ['ok' => false, 'error' => 'PHPMailer is not installed.'];
  }

  $host = trim((string)(defined('SMTP_HOST') ? SMTP_HOST : ''));
  $port = (int)(defined('SMTP_PORT') ? SMTP_PORT : 587);
  $username = trim((string)(defined('SMTP_USERNAME') ? SMTP_USERNAME : ''));
  $password = (string)(defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '');
  $encryption = strtolower(trim((string)(defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls')));
  $fromAddress = trim((string)(defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : ''));
  $fromName = trim((string)(defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'MPW Document Tracker'));

  if ($host === '' || $username === '' || $password === '' || $fromAddress === '') {
    return ['ok' => false, 'error' => 'SMTP is not configured.'];
  }

  $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
  try {
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = $port > 0 ? $port : 587;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->Timeout = 20;
    $mail->CharSet = 'UTF-8';

    if ($encryption === 'ssl') {
      $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } else {
      $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->setFrom($fromAddress, $fromName !== '' ? $fromName : 'MPW Document Tracker');
    $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body = $htmlBody;
    $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);
    $mail->send();
    return ['ok' => true];
  } catch (\Throwable $e) {
    return ['ok' => false, 'error' => 'Mailer failed: ' . $e->getMessage()];
  }
}
