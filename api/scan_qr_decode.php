<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!hash_equals(csrf_token(), (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if (empty($_FILES['qr_image']) || !is_array($_FILES['qr_image'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No image uploaded']);
    exit;
}

$file = $_FILES['qr_image'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Image upload failed']);
    exit;
}

$tmpPath = (string)($file['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid upload source']);
    exit;
}

$pythonCandidates = ['python3', 'python'];
$pythonBin = null;
foreach ($pythonCandidates as $candidate) {
    $probe = @shell_exec($candidate . ' --version 2>&1');
    if (is_string($probe) && trim($probe) !== '') {
        $pythonBin = $candidate;
        break;
    }
}

if ($pythonBin === null) {
    http_response_code(501);
    echo json_encode(['ok' => false, 'error' => 'Server-side QR decode is unavailable because Python is not installed']);
    exit;
}

$script = realpath(__DIR__ . '/../core/qr_decode_image.py');
if (!$script || !is_file($script)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'QR decoder script is missing']);
    exit;
}

$cmd = escapeshellcmd($pythonBin) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($tmpPath) . ' 2>&1';
$output = @shell_exec($cmd);
if (!is_string($output) || trim($output) === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server-side decoder returned no response']);
    exit;
}

$data = json_decode($output, true);
if (!is_array($data)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Decoder response was invalid', 'raw' => trim($output)]);
    exit;
}

if (!($data['ok'] ?? false)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => (string)($data['error'] ?? 'Decode failed')]);
    exit;
}

echo json_encode([
    'ok' => true,
    'text' => (string)($data['text'] ?? ''),
    'passes' => array_values(array_filter((array)($data['passes'] ?? []), 'is_array')),
]);
