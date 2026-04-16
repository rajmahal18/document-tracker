<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

$docId = (int)($_GET["document_id"] ?? 0);
$requestedBranchId = (int)($_GET["branch_id"] ?? 0);
if ($docId <= 0) {
  http_response_code(400);
  echo "Bad request";
  exit;
}

// ✅ NEW: centralized visibility rule (chief-only section inbox supported)
if (!can_view_document($conn, $docId)) {
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

require_once __DIR__ . '/../core/division_tracking.php';
$mySectionId = (int)($_SESSION['section_id'] ?? 0);
$myDivision = $mySectionId > 0 ? get_user_division_meta($conn, $mySectionId) : null;
$ownDivisionCode = strtoupper(trim((string)($myDivision['code'] ?? '')));
if ($ownDivisionCode === '') {
  $sessionDivisionName = strtoupper(trim((string)($_SESSION['division_name'] ?? '')));
  if (str_contains($sessionDivisionName, 'PLANNING') || str_contains($sessionDivisionName, 'PROGRAMMING')) {
    $ownDivisionCode = 'PPD';
  } elseif (str_contains($sessionDivisionName, 'SURVEY') || str_contains($sessionDivisionName, 'DESIGN')) {
    $ownDivisionCode = 'SDD';
  } elseif (str_contains($sessionDivisionName, 'SPECIAL')) {
    $ownDivisionCode = 'SPD';
  }
}

/**
 * Attachments order:
 * - Force Transmittal Memo first (note = AUTO:TRANSMITTAL_MEMO)
 * - then PPD slip
 * - then main/append by time
 */
$scope = attachment_branch_scope_for_document($conn, $docId, $requestedBranchId);
$selectedBranchId = (int)($scope['selected_branch_id'] ?? 0);
$isScoped = (($scope['scoped'] ?? false) === true);
$branchFieldSql = workflow_branch_attachment_scope_enabled($conn)
  ? 'branch_id'
  : 'NULL AS branch_id';

$whereSql = "document_id = ? AND is_deleted = 0 AND note NOT LIKE 'AUTO:DIVISION_TRACKING_SLIP:%:SUPERSEDED'";
$bindTypes = 'i';
$bindValues = [$docId];
if ($isScoped) {
  if ($selectedBranchId > 0) {
    $whereSql .= ' AND (branch_id IS NULL OR branch_id = 0 OR branch_id = ?)';
    $bindTypes .= 'i';
    $bindValues[] = $selectedBranchId;
  } else {
    $whereSql .= ' AND (branch_id IS NULL OR branch_id = 0)';
  }
}

$sql = "
  SELECT
    id,
    original_name,
    stored_path,
    mime,
    is_append,
    uploaded_at,
    note,
    {$branchFieldSql}
  FROM document_attachments
  WHERE {$whereSql}
  ORDER BY
    CASE
      WHEN note = 'AUTO:TRANSMITTAL_MEMO' THEN 0
      WHEN note = 'AUTO:PPD_TRACKING_SLIP' OR note LIKE 'AUTO:DIVISION_TRACKING_SLIP:%' THEN 1
      ELSE 2
    END ASC,
    CASE WHEN branch_id IS NULL OR branch_id = 0 THEN 0 ELSE 1 END ASC,
    is_append ASC,
    uploaded_at ASC,
    id ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($bindTypes, ...$bindValues);
$stmt->execute();

$atts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
if ($atts) {
  foreach ($atts as $__i => &$__row) { $__row['__order_idx'] = $__i; } unset($__row);
  usort($atts, static function (array $a, array $b) use ($ownDivisionCode): int {
    $extractDivisionCode = static function (string $note): string {
      if ($note === 'AUTO:PPD_TRACKING_SLIP') {
        return 'PPD';
      }
      if (str_starts_with($note, 'AUTO:DIVISION_TRACKING_SLIP:')) {
        $suffix = strtoupper(trim(substr($note, strlen('AUTO:DIVISION_TRACKING_SLIP:'))));
        return trim(explode(':', $suffix, 2)[0] ?? '');
      }
      return '';
    };
    $priority = static function (array $row) use ($ownDivisionCode, $extractDivisionCode): int {
      $note = (string)($row['note'] ?? '');
      if ($note === 'AUTO:TRANSMITTAL_MEMO') return 0;
      $rowDivisionCode = $extractDivisionCode($note);
      if ($rowDivisionCode !== '') {
        if ($ownDivisionCode !== '' && $rowDivisionCode === $ownDivisionCode) return 1;
        return 2;
      }
      return 3;
    };
    $pa = $priority($a); $pb = $priority($b);
    if ($pa !== $pb) return $pa <=> $pb;
    return ((int)($a['__order_idx'] ?? 0)) <=> ((int)($b['__order_idx'] ?? 0));
  });
  foreach ($atts as &$__row) { unset($__row['__order_idx']); } unset($__row);
}

// Debug: show raw attachment rows
if (isset($_GET['debugatts']) && $_GET['debugatts'] === '1') {
  header('Content-Type: text/plain; charset=utf-8');
  foreach ($atts as $a) {
    echo "{$a['id']} | {$a['original_name']} | rel={$a['stored_path']} | mime={$a['mime']} | note=" . ($a['note'] ?? '') . "\n";
  }
  exit;
}

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

/**
 * Convert an image file into a single-page A4 PDF (centered, scaled to fit).
 * Returns absolute path to the temp PDF.
 */
function image_to_a4_pdf(string $imageAbs, string $mime, string $tracking): string {
  $autoload = __DIR__ . "/../vendor/autoload.php";
  if (!is_file($autoload)) {
    http_response_code(501);
    echo "Image-to-PDF preview requires Composer vendor libs (setasign/fpdf).";
    exit;
  }
  require_once $autoload;

  // setasign/fpdf v1.8 exposes global \FPDF (non-namespaced).
  // Some setups may expose setasign\Fpdf\Fpdf. Support both.
  $fpdfClass = null;

  if (class_exists('FPDF')) {
    $fpdfClass = 'FPDF';
  } elseif (class_exists('setasign\\Fpdf\\Fpdf')) {
    $fpdfClass = 'setasign\\Fpdf\\Fpdf';
  }

  if ($fpdfClass === null) {
    http_response_code(501);
    echo "Image-to-PDF preview requires setasign/fpdf (FPDF class not found).";
    exit;
  }

  $ext = strtolower(pathinfo($imageAbs, PATHINFO_EXTENSION));

  // FPDF supports JPG/JPEG/PNG/GIF (depends), but NOT WebP directly.
  // If WebP, convert to JPG using GD if available.
  $imgForFpdf = $imageAbs;
  $tmpJpg = null;

  if ($ext === "webp") {
    if (function_exists('imagecreatefromwebp')) {
      $im = @imagecreatefromwebp($imageAbs);
      if ($im !== false) {
        $tmpJpg = tempnam(sys_get_temp_dir(), "dtwebp_") . ".jpg";
        @imagejpeg($im, $tmpJpg, 92);
        @imagedestroy($im);
        $imgForFpdf = $tmpJpg;
        $ext = "jpg";
      }
    }
    if ($tmpJpg === null) {
      http_response_code(415);
      echo "WEBP preview is not supported on this server yet. Please upload JPG/PNG instead, or enable GD webp support.";
      exit;
    }
  }

  // Basic sanity: image dimensions
  $info = @getimagesize($imgForFpdf);
  if ($info === false) {
    http_response_code(415);
    echo "Unsupported or corrupted image file.";
    exit;
  }
  [$pxW, $pxH] = $info;

  // Create A4 PDF
  $pdf = new $fpdfClass("P", "mm", "A4");
  $pdf->SetAutoPageBreak(false);
  $pdf->AddPage();

  // A4 size in mm: 210 x 297
  $pageW = 210.0;
  $pageH = 297.0;
  $margin = 10.0; // mm
  $maxW = $pageW - ($margin * 2);
  $maxH = $pageH - ($margin * 2);

  // Compute image aspect and fit
  $imgRatio = ($pxH > 0) ? ($pxW / $pxH) : 1.0;
  $boxRatio = $maxW / $maxH;

  if ($imgRatio >= $boxRatio) {
    // limited by width
    $w = $maxW;
    $h = $w / $imgRatio;
  } else {
    // limited by height
    $h = $maxH;
    $w = $h * $imgRatio;
  }

  $x = ($pageW - $w) / 2.0;
  $y = ($pageH - $h) / 2.0;

  // Place image
  $pdf->Image($imgForFpdf, $x, $y, $w, $h);

  $tmpPdf = tempnam(sys_get_temp_dir(), "dtimg_") . ".pdf";
  $pdf->Output("F", $tmpPdf);

  // Cleanup temp jpg if created
  if ($tmpJpg !== null && is_file($tmpJpg)) {
    @unlink($tmpJpg);
  }

  return $tmpPdf;
}

function find_gs_binary(): ?string {
  $candidates = ["gs", "gswin64c", "gswin32c"];
  foreach ($candidates as $bin) {
    $out = @shell_exec(escapeshellcmd($bin) . " -version 2>&1");
    if (is_string($out) && trim($out) !== "") return $bin;
  }
  return null;
}

$tmpGenerated = [];
$mergeFiles = [];

foreach ($atts as $a) {
  $mime = strtolower(trim((string)($a["mime"] ?? "")));
  $rel  = (string)($a["stored_path"] ?? "");
  $abs  = realpath(__DIR__ . "/../" . $rel);

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

  $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
  $isPdf = ($mime === "application/pdf") || ($ext === "pdf");
  $isImage = str_starts_with($mime, "image/") || in_array($ext, ["jpg","jpeg","png","gif","webp"], true);

  if ($isPdf) {
    $mergeFiles[] = $abs;
  } elseif ($isImage) {
    // Convert image to A4 PDF page, so output is uniform even with photos.
    $tmpPdf = image_to_a4_pdf($abs, $mime, $tracking);
    $tmpGenerated[] = $tmpPdf;
    $mergeFiles[] = $tmpPdf;
  } else {
    http_response_code(415);
    echo "This 'View document' button supports PDF and common images (JPG/PNG/etc). Found an unsupported file: "
      . (string)($a["original_name"] ?? "(unknown)");
    exit;
  }

  // Debug: show each added file
  if (isset($_GET['debugpush']) && $_GET['debugpush'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "ADDED: " . basename(end($mergeFiles)) . PHP_EOL;
    exit;
  }
}

register_shutdown_function(function () use ($tmpGenerated) {
  foreach ($tmpGenerated as $p) {
    if (is_string($p) && $p !== "" && is_file($p)) {
      @unlink($p);
    }
  }
});

// Debug: list final merge order
if (isset($_GET['debugmerge']) && $_GET['debugmerge'] === '1') {
  header('Content-Type: text/plain; charset=utf-8');
  foreach ($mergeFiles as $i => $p) {
    echo ($i + 1) . ") " . basename($p) . "\n";
  }
  exit;
}

if (count($mergeFiles) === 0) {
  http_response_code(404);
  echo "No previewable attachments found.";
  exit;
}

$filename = $tracking . "_merged.pdf";

// =========================
// Merge strategy:
// 1) Try Ghostscript (fast, no PHP libs)
// 2) Fallback to FPDI (available in your vendor)
// =========================
$gs = find_gs_binary();
$gs = null; // keep your existing behavior (avoid GS ordering/caching weirdness)
if ($gs !== null && function_exists('proc_open')) {
  $cmd = array_merge(
    [$gs, "-q", "-dNOPAUSE", "-dBATCH", "-sDEVICE=pdfwrite", "-sOutputFile=-"],
    $mergeFiles
  );

  $descriptors = [
    1 => ["pipe", "w"], // stdout
    2 => ["pipe", "w"], // stderr
  ];

  $proc = @proc_open($cmd, $descriptors, $pipes);
  if (is_resource($proc)) {
    header("X-Content-Type-Options: nosniff");
    header("Content-Type: application/pdf");
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("X-Merge-Engine: ghostscript");
    header("X-Merge-Order: " . implode(" | ", array_map("basename", $mergeFiles)));

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

    foreach ($mergeFiles as $file) {
      $pageCount = $pdf->setSourceFile($file);
      for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
        $tpl  = $pdf->importPage($pageNo);
        $size = $pdf->getTemplateSize($tpl);

        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($tpl);
      }
    }

    header("X-Content-Type-Options: nosniff");
    header("Content-Type: application/pdf");
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("X-Merge-Engine: fpdi");
    header("X-Merge-Order: " . implode(" | ", array_map("basename", $mergeFiles)));

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
