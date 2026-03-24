<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo "Bad request";
  exit;
}

// Fetch attachment + document id
$stmt = $conn->prepare("
  SELECT
    a.id,
    a.document_id,
    a.original_name,
    a.stored_path,
    a.mime,
    a.is_deleted
  FROM document_attachments a
  WHERE a.id = ?
  LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$a = $stmt->get_result()->fetch_assoc();

if (!$a || (int)$a["is_deleted"] === 1) {
  http_response_code(404);
  echo "Not found";
  exit;
}

$docId = (int)$a["document_id"];

/**
 * ✅ NEW centralized permission rule
 */
if (!can_view_attachment($conn, $id)) {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

// Resolve file path
$rel = (string)$a["stored_path"];
$abs = realpath(__DIR__ . "/../" . $rel);

if ($abs === false || !is_file($abs)) {
  http_response_code(404);
  echo "File missing";
  exit;
}

$mime = trim((string)($a["mime"] ?? "")) ?: "application/octet-stream";
$filename = (string)$a["original_name"];

header("X-Content-Type-Options: nosniff");
header("Content-Type: " . $mime);

// inline = preview
header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');

header("Cache-Control: private, max-age=3600");

readfile($abs);
exit;