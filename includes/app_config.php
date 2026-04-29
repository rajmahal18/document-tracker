<?php
declare(strict_types=1);

$appEnv = strtolower(trim((string)(getenv('APP_ENV') ?: 'local')));
if ($appEnv === '') {
    $appEnv = 'local';
}

$config = [
    'APP_ENV' => $appEnv,
    'APP_BASE_PATH_OVERRIDE' => getenv('APP_BASE_PATH_OVERRIDE') !== false ? (string)getenv('APP_BASE_PATH_OVERRIDE') : '',
    'APP_URL_ORIGIN' => getenv('APP_URL_ORIGIN') !== false ? trim((string)getenv('APP_URL_ORIGIN')) : '',
    'DB_HOST' => getenv('DB_HOST') !== false ? (string)getenv('DB_HOST') : '127.0.0.1',
    'DB_NAME' => getenv('DB_NAME') !== false ? (string)getenv('DB_NAME') : 'doc_tracker',
    'DB_USER' => getenv('DB_USER') !== false ? (string)getenv('DB_USER') : 'root',
    'DB_PASS' => getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : '',
    'SMTP_HOST' => getenv('SMTP_HOST') !== false ? (string)getenv('SMTP_HOST') : '',
    'SMTP_PORT' => getenv('SMTP_PORT') !== false ? (string)getenv('SMTP_PORT') : '587',
    'SMTP_USERNAME' => getenv('SMTP_USERNAME') !== false ? (string)getenv('SMTP_USERNAME') : 'rajpaute@gmail.com',
    'SMTP_PASSWORD' => getenv('SMTP_PASSWORD') !== false ? (string)getenv('SMTP_PASSWORD') : 'qzrcikjrdubbchdc',
    'SMTP_ENCRYPTION' => getenv('SMTP_ENCRYPTION') !== false ? (string)getenv('SMTP_ENCRYPTION') : 'tls',
    'MAIL_FROM_ADDRESS' => getenv('MAIL_FROM_ADDRESS') !== false ? (string)getenv('MAIL_FROM_ADDRESS') : 'rajpaute@gmail.com',
    'MAIL_FROM_NAME' => getenv('MAIL_FROM_NAME') !== false ? (string)getenv('MAIL_FROM_NAME') : 'MPW Document Tracker',
];

$appConfigFile = __DIR__ . '/app_config.' . $appEnv . '.php';
if (is_file($appConfigFile)) {
    $fileConfig = require $appConfigFile;
    if (is_array($fileConfig)) {
        $config = array_replace($config, $fileConfig);
    }
}

foreach ($config as $key => $value) {
    if (!defined($key)) {
        define($key, is_string($value) ? $value : (string)$value);
    }
}

date_default_timezone_set('Asia/Manila');
