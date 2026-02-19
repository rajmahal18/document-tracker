<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

$docId = (int)($_GET["document_id"] ?? 0);
if ($docId <= 0) {
  http_response_code(400);
  echo "Bad request";
  exit;
}

$role        = (string)($_SESSION["role"] ?? "division");
$mySectionId = (int)($_SESSION["section_id"] ?? 0);

/**
 * Visibility rule:
 * - admin/records: all
 * - others: holder OR pending recipient (open route) OR participant
 */
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

if (!can_view_doc($conn, $docId, $role, $mySectionId)) {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

// Get doc tracking no for a nicer filename
$tracking = "document";
$stmt = $conn->prepare("SELECT tracking_no FROM documents WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $docId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if ($row && !empty($row["tracking_no"])) {
  $tracking = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$row["tracking_no"]) ?: "document";
}

// Fetch attachments (main first, then append) in chronological order
$stmt = $conn->prepare("
  SELECT
    id,
    original_name,
    stored_path,
    mime,
    is_append,
    uploaded_at
  FROM document_attachments
  WHERE document_id = ?
    AND is_deleted = 0
  ORDER BY is_append ASC, uploaded_at ASC, id ASC
");
$stmt->bind_param("i", $docId);
$stmt->execute();
$atts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (!$atts || count($atts) === 0) {
  http_response_code(404);
  echo "No attachments to view.";
  exit;
}

$baseDir = realpath(__DIR__ . "/../");
if ($baseDir === false) {
  http_response_code(500);
  echo "Server error.";
  exit;
}

$pdfFiles = [];
foreach ($atts as $a) {
  $mime = strtolower(trim((string)($a["mime"] ?? "")));
  $rel = (string)($a["stored_path"] ?? "");
  $abs = realpath(__DIR__ . "/../" . $rel);

  if ($abs === false || !is_file($abs)) {
    http_response_code(404);
    echo "Missing file: " . (string)($a["original_name"] ?? "(unknown)");
    exit;
  }

  // Safety: ensure it's under project base
  if (strpos($abs, $baseDir) !== 0) {
    http_response_code(400);
    echo "Invalid file path.";
    exit;
  }

  $isPdf = ($mime === "application/pdf") || (strtolower(pathinfo($abs, PATHINFO_EXTENSION)) === "pdf");
  if (!$isPdf) {
    http_response_code(415);
    echo "This 'View document' button currently supports PDF-only attachments. Found a non-PDF file: "
      . (string)($a["original_name"] ?? "(unknown)");
    exit;
  }

  $pdfFiles[] = $abs;
}

if (count($pdfFiles) === 0) {
  http_response_code(404);
  echo "No PDF attachments found.";
  exit;
}

// =========================
// Merge strategy:
// 1) Try Ghostscript (fast, no PHP libs)
// 2) Fallback to FPDI if vendor/autoload.php exists
// =========================

function find_gs_binary(): ?string {
  $candidates = ["gs", "gswin64c", "gswin32c"];
  foreach ($candidates as $bin) {
    $out = @shell_exec(escapeshellcmd($bin) . " -version 2>&1");
    if (is_string($out) && trim($out) !== "") return $bin;
  }
  return null;
}

$filename = $tracking . "_merged.pdf";

$gs = find_gs_binary();
if ($gs !== null && function_exists('proc_open')) {
  $args = implode(' ', array_map('escapeshellarg', $pdfFiles));
  $cmd = escapeshellcmd($gs) . " -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -sOutputFile=- " . $args;

  $descriptors = [
    1 => ["pipe", "w"], // stdout
    2 => ["pipe", "w"], // stderr
  ];

  $proc = @proc_open($cmd, $descriptors, $pipes);
  if (is_resource($proc)) {
    header("X-Content-Type-Options: nosniff");
    header("Content-Type: application/pdf");
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
    header("Cache-Control: private, max-age=3600");

    // Stream output
    while (!feof($pipes[1])) {
      $chunk = fread($pipes[1], 8192);
      if ($chunk === false) break;
      echo $chunk;
      @flush();
    }
    fclose($pipes[1]);

    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $code = proc_close($proc);
    if ($code !== 0) {
      // If merge failed mid-stream, we can't change headers; just add a minimal hint.
      // (In practice, this should be rare.)
      error_log("Ghostscript merge failed: code=$code err=$err");
    }
    exit;
  }
}

// FPDI fallback (requires vendor libs)
$autoload = __DIR__ . "/../vendor/autoload.php";
if (is_file($autoload)) {
  require_once $autoload;

  if (class_exists('setasign\\Fpdi\\Fpdi')) {
    $pdf = new setasign\Fpdi\Fpdi();
    $pdf->SetAutoPageBreak(false);

    foreach ($pdfFiles as $file) {
      $pageCount = $pdf->setSourceFile($file);
      for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
        $tpl = $pdf->importPage($pageNo);
        $size = $pdf->getTemplateSize($tpl);
        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($tpl);
      }
    }

    header("X-Content-Type-Options: nosniff");
    header("Content-Type: application/pdf");
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
    header("Cache-Control: private, max-age=3600");

    // Output inline
    $pdf->Output('I', $filename);
    exit;
  }
}

http_response_code(501);
echo "Merged PDF preview is not available on this server yet.\n\n";
echo "To enable it, install one of these on your server:\n";
echo "1) Ghostscript (preferred)\n";
echo "2) Composer libs: setasign/fpdi + setasign/fpdf\n";
exit;
