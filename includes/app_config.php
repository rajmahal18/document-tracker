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
