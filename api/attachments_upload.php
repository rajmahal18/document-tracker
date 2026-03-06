<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "Method not allowed"]);
  exit;
}

require_csrf();

$docId    = (int)($_POST["document_id"] ?? 0);
$note     = trim((string)($_POST["note"] ?? ""));
$isAppend = (int)($_POST["is_append"] ?? 0) === 1 ? 1 : 0;

if ($docId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad document_id"]);
  exit;
}

if (!isset($_FILES["file"])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing file"]);
  exit;
}

$role        = (string)($_SESSION["role"] ?? "user");
$mySectionId = (int)($_SESSION["section_id"] ?? 0);
$userId      = (int)($_SESSION["user_id"] ?? 0);

// Basic file constraints (keep sane for government LAN setups)
$MAX_BYTES = 10 * 1024 * 1024; // 10MB

// ✅ InfinityFree-safe merge requirement: PDF + Images only
$ALLOWED_EXT = ["pdf", "jpg", "jpeg", "png"];
$ALLOWED_MIME = [
  "application/pdf",
  "image/jpeg",
  "image/png",
];

// Explicit hard-block list (even if extension allowlist changes later)
$BLOCKED_EXT = ["doc", "docx", "xls", "xlsx"];

function ext_of(string $name): string {
  $pos = strrpos($name, ".");
  if ($pos === false) return "";
  return strtolower(substr($name, $pos + 1));
}

function safe_basename(string $name): string {
  // Strip any path; then keep only safe-ish chars.
  $base = basename($name);
  $base = preg_replace('/[^a-zA-Z0-9._\-\s]/', "_", $base) ?? $base;
  return trim($base) !== "" ? $base : "file";
}

// Best-effort: check uploaded tmp file mime (more reliable than $_FILES["type"])
function sniff_mime(string $tmpPath): string {
  if (!is_file($tmpPath)) return "application/octet-stream";
  if (!function_exists("finfo_open")) return "application/octet-stream";
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  if ($finfo === false) return "application/octet-stream";
  $mime = finfo_file($finfo, $tmpPath);
  finfo_close($finfo);
  return is_string($mime) && $mime !== "" ? $mime : "application/octet-stream";
}

try {
  $conn->begin_transaction();

  // Fetch doc state for permission rules
  $stmt = $conn->prepare("
    SELECT
      d.current_status,
      d.current_holder_section_id,
      EXISTS (SELECT 1 FROM routes r WHERE r.document_id = d.id AND r.received_at IS NULL AND r.cancelled_at IS NULL) AS has_open_route
    FROM documents d
    WHERE d.id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $doc = $stmt->get_result()->fetch_assoc();

  if (!$doc) {
    $conn->rollback();
    http_response_code(404);
    echo json_encode(["ok" => false, "error" => "Document not found"]);
    exit;
  }

  $status = (string)($doc["current_status"] ?? "");
  $holderSectionId = (int)($doc["current_holder_section_id"] ?? 0);
  $hasOpenRoute = ((int)($doc["has_open_route"] ?? 0) === 1);

  // Only ACTIVE docs can accept attachments (keeps audit sane)
  if ($status !== "ACTIVE") {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Cannot attach files: document is not ACTIVE."]);
    exit;
  }

  // Permission: admin/records OR current holder (division)
  $isPrivileged = in_array($role, ["admin", "records"], true);

  if (!$isPrivileged) {
    if ($mySectionId <= 0 || $holderSectionId <= 0 || $holderSectionId !== $mySectionId) {
      $conn->rollback();
      http_response_code(403);
      echo json_encode(["ok" => false, "error" => "Forbidden: your section does not hold this document."]);
      exit;
    }
    // If somehow still in transit, holder should not be attaching (prevents weirdness)
    if ($hasOpenRoute) {
      $conn->rollback();
      http_response_code(409);
      echo json_encode(["ok" => false, "error" => "Cannot attach while document is in transit."]);
      exit;
    }
  }

  $f = $_FILES["file"];
  if (!is_array($f) || ($f["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Upload failed"]);
    exit;
  }

  $origName = safe_basename((string)($f["name"] ?? "file"));
  $size = (int)($f["size"] ?? 0);
  if ($size <= 0 || $size > $MAX_BYTES) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "File too large (max 10MB)"]);
    exit;
  }

  $ext = ext_of($origName);
  if ($ext === "") {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Unsupported file type. Allowed: PDF, JPG, PNG."]);
    exit;
  }

  // Explicit block first (clearer error)
  if (in_array($ext, $BLOCKED_EXT, true)) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode([
      "ok" => false,
      "error" => "Office files (DOCX/XLSX) are not supported. Please export to PDF then upload."
    ]);
    exit;
  }

  if (!in_array($ext, $ALLOWED_EXT, true)) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Unsupported file type. Allowed: PDF, JPG, PNG."]);
    exit;
  }

  $tmp = (string)($f["tmp_name"] ?? "");
  if ($tmp === "" || !is_uploaded_file($tmp)) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Invalid upload"]);
    exit;
  }

  // MIME check: prefer sniffing temp file, fallback to browser-provided MIME
  $mime = sniff_mime($tmp);
  if ($mime === "application/octet-stream") {
    $mime = (string)($f["type"] ?? "application/octet-stream");
  }

  // MIME allowlist (best-effort; do not reject if still octet-stream)
  if ($mime !== "application/octet-stream" && !in_array($mime, $ALLOWED_MIME, true)) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Unsupported file type. Allowed: PDF, JPG, PNG."]);
    exit;
  }

  // Storage: /storage/attachments/doc_{id}/...
  $baseDir = realpath(__DIR__ . "/../storage/attachments");
  if ($baseDir === false) {
    $baseDir = __DIR__ . "/../storage/attachments";
    if (!is_dir($baseDir)) {
      mkdir($baseDir, 0775, true);
    }
    $baseDir = realpath($baseDir) ?: $baseDir;
  }

  $docDir = $baseDir . "/doc_" . $docId;
  if (!is_dir($docDir)) {
    mkdir($docDir, 0775, true);
  }

  $stamp = date("Ymd_His");
  $rand = bin2hex(random_bytes(6));
  $storedName = $stamp . "_u" . $userId . "_" . $rand . "." . $ext;
  $absPath = $docDir . "/" . $storedName;

  if (!move_uploaded_file($tmp, $absPath)) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Failed to store file"]);
    exit;
  }

  // Store relative path (no secrets)
  $relPath = "storage/attachments/doc_" . $docId . "/" . $storedName;

  $uploadedBySectionId = $mySectionId > 0 ? $mySectionId : null;

  $stmt = $conn->prepare("
    INSERT INTO document_attachments
      (document_id, original_name, stored_name, stored_path, mime, size_bytes, note, is_append, uploaded_by_user_id, uploaded_by_section_id)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");

  // NOTE: keep your existing bind types. If your mysqli complains about null section_id,
  // you can switch to bind_param with "issssisiii" and pass 0 instead of null.
  $secId = $uploadedBySectionId; // can be null (mysqli accepts null for "i" in many setups)
  $stmt->bind_param(
    "issssisiii",
    $docId,
    $origName,
    $storedName,
    $relPath,
    $mime,
    $size,
    $note,
    $isAppend,
    $userId,
    $secId
  );
  $stmt->execute();

  $attachId = (int)$conn->insert_id;

  // Bump documents.updated_at (for stuck detection)
  $stmt = $conn->prepare("UPDATE documents SET updated_at = NOW() WHERE id = ?");
  $stmt->bind_param("i", $docId);
  $stmt->execute();

  // Add audit row WITHOUT introducing a new ENUM value.
  // We store event_type = 'updated' and tag payload kind.
  $payload = json_encode([
    "kind" => "attachment_added",
    "attachment_id" => $attachId,
    "file" => $origName,
    "is_append" => $isAppend,
    "note" => $note,
  ], JSON_UNESCAPED_UNICODE);

  $actorSectionId = $mySectionId > 0 ? $mySectionId : null;

  $stmt = $conn->prepare("
    INSERT INTO document_events
      (document_id, event_type, actor_user_id, actor_section_id, payload_json)
    VALUES
      (?, 'updated', ?, ?, ?)
  ");
  $stmt->bind_param("iiis", $docId, $userId, $actorSectionId, $payload);
  $stmt->execute();

  $conn->commit();

  echo json_encode(["ok" => true, "attachment_id" => $attachId]);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error"]);
  exit;
}
