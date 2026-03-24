<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

$attachId = (int)($_GET["id"] ?? 0);
if ($attachId <= 0) {
  http_response_code(400);
  echo "Bad id";
  exit;
}

try {
  $stmt = $conn->prepare("
    SELECT
      a.document_id,
      a.original_name,
      a.stored_path,
      a.mime,
      a.size_bytes
    FROM document_attachments a
    WHERE a.id = ? AND a.is_deleted = 0
    LIMIT 1
  ");
  $stmt->bind_param("i", $attachId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();

  if (!$row) {
    http_response_code(404);
    echo "Not found";
    exit;
  }

  $docId = (int)($row["document_id"] ?? 0);

  if ($docId <= 0 || !can_view_attachment($conn, $attachId)) {
    http_response_code(403);
    echo "Forbidden";
    exit;
  }

  $orig = (string)($row["original_name"] ?? "file");
  $mime = (string)($row["mime"] ?? "application/octet-stream");
  $rel  = (string)($row["stored_path"] ?? "");

  // Resolve absolute path safely under project root
  $root = realpath(__DIR__ . "/..") ?: (__DIR__ . "/..");
  $abs = realpath($root . "/" . $rel);

  if ($abs === false || !is_file($abs)) {
    http_response_code(404);
    echo "File missing";
    exit;
  }

  // Prevent path traversal: ensure resolved path is within storage/attachments
  $allowedBase = realpath($root . "/storage/attachments");
  if ($allowedBase !== false) {
    $absNorm = str_replace("\\", "/", $abs);
    $baseNorm = str_replace("\\", "/", $allowedBase);
    if (strpos($absNorm, $baseNorm) !== 0) {
      http_response_code(403);
      echo "Forbidden";
      exit;
    }
  }

  header("X-Content-Type-Options: nosniff");
  header("Content-Type: " . $mime);
  header('Content-Disposition: attachment; filename="' . str_replace('"', "'", basename($orig)) . '"');
  header("Content-Length: " . (string)filesize($abs));

  readfile($abs);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo "Server error";
  exit;
}