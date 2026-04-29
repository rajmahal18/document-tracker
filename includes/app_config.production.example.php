<?php
declare(strict_types=1);

return [
    'APP_ENV' => 'production',
    'APP_BASE_PATH_OVERRIDE' => '',
    'APP_URL_ORIGIN' => '', // optional: e.g. 'https://tracker.example.gov.ph'
    'DB_HOST' => '127.0.0.1',
    'DB_NAME' => 'doc_tracker',
    'DB_USER' => 'tracker_user',
    'DB_PASS' => 'Secret',
    'SMTP_HOST' => 'smtp.gmail.com',
    'SMTP_PORT' => '587',
    'SMTP_USERNAME' => 'your-gmail@gmail.com',
    'SMTP_PASSWORD' => 'app-password-here',
    'SMTP_ENCRYPTION' => 'tls',
    'MAIL_FROM_ADDRESS' => 'your-gmail@gmail.com',
    'MAIL_FROM_NAME' => 'MPW Document Tracker',
];
