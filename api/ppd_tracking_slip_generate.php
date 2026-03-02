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

$docId = (int)($_POST["document_id"] ?? 0);
if ($docId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad document id"]);
  exit;
}

$userId      = (int)($_SESSION["user_id"] ?? 0);
$role        = (string)($_SESSION["role"] ?? "division");
$mySectionId = (int)($_SESSION["section_id"] ?? 0);

function can_view_doc(mysqli $conn, int $docId, string $role, int $mySectionId): bool {
  if (in_array($role, ["admin", "records"], true)) return true;
  if ($mySectionId <= 0) return false;

  $stmt = $conn->prepare("
    SELECT 1
    FROM documents d
    WHERE d.id = ?
      AND (
        d.current_holder_section_id = ?
        OR EXISTS (
          SELECT 1 FROM routes r
          WHERE r.document_id = d.id AND r.is_open = 1 AND r.to_section_id = ?
        )
        OR EXISTS (
          SELECT 1 FROM document_participants p
          WHERE p.document_id = d.id AND p.section_id = ?
        )
      )
    LIMIT 1
  ");
  $stmt->bind_param("iiii", $docId, $mySectionId, $mySectionId, $mySectionId);
  $stmt->execute();
  return (bool)$stmt->get_result()->fetch_row();
}

function is_ppd_user(mysqli $conn, string $role, int $mySectionId): bool {
  if ($role !== "division") return false;
  if ($mySectionId <= 0) return false;

  $stmt = $conn->prepare("
    SELECT d.name AS division_name
    FROM sections s
    JOIN divisions d ON d.id = s.division_id
    WHERE s.id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $mySectionId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $div = strtolower(trim((string)($row["division_name"] ?? "")));

  // Be forgiving: allow exact name or any string containing "planning" and "programming"
  return (strpos($div, "planning") !== false && strpos($div, "programming") !== false);
}

if (!can_view_doc($conn, $docId, $role, $mySectionId)) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "Access denied"]);
  exit;
}

if (!is_ppd_user($conn, $role, $mySectionId)) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "PPD only"]);
  exit;
}

// Fetch document info
$stmt = $conn->prepare("
  SELECT
    d.id,
    d.tracking_no,
    d.requester,
    d.document_date,
    d.subject,
    d.origin_section_id,
    d.current_holder_section_id
  FROM documents d
  WHERE d.id = ?
  LIMIT 1
");
$stmt->bind_param("i", $docId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if (!$doc) {
  http_response_code(404);
  echo json_encode(["ok" => false, "error" => "Document not found"]);
  exit;
}

$trackingNo = (string)($doc["tracking_no"] ?? "");
$safeTracking = preg_replace('/[^A-Za-z0-9._-]+/', '_', $trackingNo) ?: "document";

$documentDate = (string)($doc["document_date"] ?? "");
$subject = (string)($doc["subject"] ?? "");

// Resolve FROM label (origin division / section)
$fromLabel = "";
$originSectionId = (int)($doc["origin_section_id"] ?? 0);
if ($originSectionId > 0) {
  $stmt = $conn->prepare("
    SELECT s.name AS section_name, d.name AS division_name
    FROM sections s
    JOIN divisions d ON d.id = s.division_id
    WHERE s.id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $originSectionId);
  $stmt->execute();
  $r = $stmt->get_result()->fetch_assoc();
  if ($r) {
    $fromLabel = trim((string)$r["division_name"]) . " / " . trim((string)$r["section_name"]);
  }
}

// "Received by" default to current PPD user full name (printable placeholder)
$receivedBy = trim((string)($_SESSION["full_name"] ?? ""));
if ($receivedBy === "") $receivedBy = "PPD";

$receivedDT = date("m/d/y  g:ia");

// Generate PDF into doc folder
require_once __DIR__ . "/../core/PPDTrackingSlip.php";

$baseDir = realpath(__DIR__ . "/../storage/attachments");
if ($baseDir === false) {
  $baseDir = __DIR__ . "/../storage/attachments";
  if (!is_dir($baseDir)) mkdir($baseDir, 0775, true);
}
$docDir = rtrim((string)$baseDir, "/\\") . "/doc_" . $docId;
if (!is_dir($docDir)) mkdir($docDir, 0775, true);

$storedName = "PPD_TRACKING_SLIP_" . $safeTracking . ".pdf";
$abs = $docDir . "/" . $storedName;
$rel = "storage/attachments/doc_" . $docId . "/" . $storedName;

// If an old AUTO slip exists, soft-delete it (keeps history clean in list)
$stmt = $conn->prepare("
  UPDATE document_attachments
  SET is_deleted = 1
  WHERE document_id = ?
    AND is_deleted = 0
    AND note = 'AUTO:PPD_TRACKING_SLIP'
");
$stmt->bind_param("i", $docId);
$stmt->execute();

PPDTrackingSlip::generateA4([
  "mpw_tracking_no" => "",
  "ppd_tracking_no" => $trackingNo,
  "from_label" => $fromLabel,
  "document_date" => $documentDate,
  "received_by" => $receivedBy,
  "received_datetime" => $receivedDT,
  "subject" => $subject,
], $abs);

$size = (int)@filesize($abs);
if ($size <= 0) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Failed to generate PDF"]);
  exit;
}

$mime = "application/pdf";
$note = "AUTO:PPD_TRACKING_SLIP";
$orig = $storedName;

$stmt = $conn->prepare("
  INSERT INTO document_attachments
    (document_id, original_name, stored_name, stored_path, mime, size_bytes, note, is_append, uploaded_by_user_id, uploaded_by_section_id)
  VALUES
    (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
");
$stmt->bind_param("issssisii", $docId, $orig, $storedName, $rel, $mime, $size, $note, $userId, $mySectionId);
$stmt->execute();
$attId = (int)$conn->insert_id;

$payload = json_encode([
  "kind" => "ppd_tracking_slip_generated",
  "attachment_id" => $attId,
  "file" => $orig,
], JSON_UNESCAPED_UNICODE);

$stmt = $conn->prepare("
  INSERT INTO document_events
    (document_id, event_type, actor_user_id, actor_section_id, payload_json)
  VALUES
    (?, 'updated', ?, ?, ?)
");
$stmt->bind_param("iiis", $docId, $userId, $mySectionId, $payload);
$stmt->execute();

echo json_encode(["ok" => true, "attachment_id" => $attId, "stored_name" => $storedName]);
