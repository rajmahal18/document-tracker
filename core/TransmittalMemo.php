<?php
declare(strict_types=1);

/**
 * Transmittal Memo PDF generator (A4)
 *
 * Requirements:
 * - Composer libs: setasign/fpdf (vendor/autoload.php present)
 *
 * Notes:
 * - Layout is intentionally "form-like" and printable.
 * - Recipient/action checkboxes are left blank (user ticks after print).
 */
final class TransmittalMemo
{
  /**
  * @param array{date:string, subject:string, qr_url?:string, logo_left_abs?:string, logo_right_abs?:string} $data
   */
  public static function generateA4(array $data, string $absOutPath): void
  {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
      throw new RuntimeException('PDF library not available (missing vendor/autoload.php).');
    }
    require_once $autoload;

    // Accept BOTH namespaced and global FPDF
    if (!class_exists('setasign\\Fpdf\\Fpdf') && !class_exists('FPDF')) {
      throw new RuntimeException('PDF library not available (FPDF not found).');
    }

    $pdfClass = class_exists('setasign\\Fpdf\\Fpdf')
        ? 'setasign\\Fpdf\\Fpdf'
        : 'FPDF';

    $pdf = new $pdfClass('P', 'mm', 'A4');

    $date = trim((string)($data['date'] ?? ''));
    $subject = trim((string)($data['subject'] ?? ''));
    if ($date === '' || $subject === '') {
      throw new RuntimeException('Transmittal memo requires date and subject.');
    }

    
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $pageW = 210.0;
    $pageH = 297.0;
    $m = 12.0; // estimated margin
    $innerX = $m;
    $innerY = $m;
    $innerW = $pageW - ($m * 2);
    $innerH = $pageH - ($m * 2);

    // Outer border
    $pdf->SetDrawColor(45, 55, 72);
    $pdf->SetLineWidth(0.6);
    $pdf->Rect($innerX, $innerY, $innerW, $innerH);

    // Header band
    $bandH = 22;
    $pdf->SetLineWidth(0.4);
    $pdf->Rect($innerX, $innerY, $innerW, $bandH);

    // --- Header logos + QR (MPW left, OCM right, QR below OCM) ---
    $logoLeft  = trim((string)($data['logo_left_abs'] ?? ''));
    $logoRight = trim((string)($data['logo_right_abs'] ?? ''));
    $qrUrl     = trim((string)($data['qr_url'] ?? ''));

    // Sizes (mm) - tweak if needed
    $logoSize = 16.0;     // square logo inside header band
    $pad      = 3.0;

    $logoY = $innerY + $pad; // inside header band

    if ($logoLeft !== '' && is_file($logoLeft)) {
      $pdf->Image($logoLeft, $innerX + $pad, $logoY, $logoSize, $logoSize);
    }

    if ($logoRight !== '' && is_file($logoRight)) {
      $pdf->Image($logoRight, $innerX + $innerW - $pad - $logoSize, $logoY, $logoSize, $logoSize);
    }

    // QR below the right logo
    $tmpQrPath = null;
    $qrSizeMm  = 26.0; // QR size on paper

    if ($qrUrl !== '' && class_exists(\Endroid\QrCode\QrCode::class)) {
      $qr = \Endroid\QrCode\QrCode::create($qrUrl)
        ->setSize(420)
        ->setMargin(10);

      $writer = new \Endroid\QrCode\Writer\PngWriter();
      $result = $writer->write($qr);

      $tmpQrPath = sys_get_temp_dir() . '/qr_' . bin2hex(random_bytes(8)) . '.png';
      $result->saveToFile($tmpQrPath);

      $qrX = $innerX + $innerW - $pad - $qrSizeMm; // right aligned
      $qrY = $innerY + $bandH + 2.5;               // below band
      $pdf->Image($tmpQrPath, $qrX, $qrY, $qrSizeMm, $qrSizeMm);
    }

    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(20, 24, 40);
    $pdf->SetXY($innerX, $innerY + 5);
    $pdf->Cell($innerW, 5, 'Ministry of Public Works', 0, 2, 'C');
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->Cell($innerW, 6, 'TECHNICAL SERVICES', 0, 2, 'C');
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell($innerW, 6, 'TRANSMITTAL MEMO', 0, 0, 'C');

    // Date (top right)
    $pdf->SetFont('Helvetica', 'B', 10);
    $dateX = $innerX + $innerW - 70;

    // if QR was generated, push the date left to avoid overlap
    if (!empty($tmpQrPath)) {
      $dateX = $innerX + $innerW - 70 - ($qrSizeMm + 6.0);
    }

    $pdf->SetXY($dateX, $innerY + $bandH + 8);
    $pdf->Cell(12, 6, 'Date:', 0, 0, 'L');
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell(58, 6, $date, 0, 1, 'L');
    $lineY = $innerY + $bandH + 13;

    $lineStartX = $innerX + 15;
    $lineEndX   = $innerX + $innerW - 15;

    // If QR exists, shorten the line so it doesn't hit the QR
    if (!empty($tmpQrPath)) {
      $qrSafeMargin = 6.0;
      $lineEndX = $qrX - $qrSafeMargin;
    }

    $pdf->Line($lineStartX, $lineY, $lineEndX, $lineY);

    // Recipients
    $y = $innerY + $bandH + 16;
    $x = $innerX + 10;

    $recipients = [
      ['name' => 'MACABAI M. PANGAMADUN', 'title' => 'Chief, Planning and Programming Division'],
      ['name' => 'EMRAIZA D. MANGACOP', 'title' => 'Chief, Survey and Design Division'],
      ['name' => 'FAISAL E. KUSAIN', 'title' => 'Chief, Special Project Division'],
    ];

    foreach ($recipients as $r) {
      $box = 5.5;
      $pdf->SetLineWidth(0.3);
      $pdf->Rect($x, $y + 1.2, $box, $box);
      $pdf->SetXY($x + $box + 4, $y);
      $pdf->SetFont('Helvetica', 'B', 10);
      $pdf->Cell(0, 6, $r['name'], 0, 2, 'L');
      $pdf->SetFont('Helvetica', '', 9);
      $pdf->SetTextColor(71, 84, 103);
      $pdf->Cell(0, 5, $r['title'], 0, 1, 'L');
      $pdf->SetTextColor(20, 24, 40);
      $y += 14;
    }

    // Subject
    $y += 2;
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetXY($innerX + 10, $y);
    $pdf->Cell(18, 6, 'Subject:', 0, 0, 'L');
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetXY($innerX + 28, $y);
    $pdf->MultiCell($innerW - 38, 6, $subject, 0, 'L');

    // Subject lines
    $lineY = $pdf->GetY() + 1;
    for ($i = 0; $i < 2; $i++) {
      $pdf->Line($innerX + 28, $lineY + ($i * 7), $innerX + $innerW - 10, $lineY + ($i * 7));
    }

    // Action section
    $y = $lineY + 10;
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetXY($innerX + 10, $y);
    $pdf->Cell(0, 6, 'Action or Other Instructions:', 0, 1, 'L');
    $y += 6;

    $actions = [
      'For Information',
      'For Appropriate Action',
      'For Evaluation / Consideration',
      'For Reference / Guidance',
      'For Comments / Recommendations',
      'For Compliance',
      'For Review',
      'For Filing',
      'Others',
    ];

    $pdf->SetFont('Helvetica', '', 10);
    $col1X = $innerX + 12;
    $col2X = $innerX + ($innerW / 2) + 4;
    $rowH = 7;

    for ($i = 0; $i < count($actions); $i++) {
      $cx = ($i < 5) ? $col1X : $col2X;
      $cy = $y + (($i % 5) * $rowH);
      $pdf->Rect($cx, $cy + 1.2, 5.5, 5.5);
      $pdf->SetXY($cx + 8, $cy);
      $pdf->Cell(80, 6, $actions[$i], 0, 0, 'L');
    }

    // Blank lines area
    $y = $y + (5 * $rowH) + 10;
    for ($i = 0; $i < 5; $i++) {
      $pdf->Line($innerX + 10, $y + ($i * 8), $innerX + $innerW - 10, $y + ($i * 8));
    }

    // Signature
    $sigY = $innerY + $innerH - 46;
    $sigX = $innerX + $innerW - 95;
    $pdf->Line($sigX, $sigY + 18, $innerX + $innerW - 12, $sigY + 18);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetXY($sigX, $sigY + 20);
    $pdf->Cell($innerX + $innerW - 12 - $sigX, 6, 'SALONGA A. SUMAMPAO', 0, 2, 'C');
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(71, 84, 103);
    $pdf->Cell($innerX + $innerW - 12 - $sigX, 5, 'Director II for Technical Service', 0, 0, 'C');
    $pdf->SetTextColor(20, 24, 40);

    // Write to disk
    $dir = dirname($absOutPath);
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }
    if (!empty($tmpQrPath) && is_file($tmpQrPath)) {
      @unlink($tmpQrPath);
    }
    $pdf->Output('F', $absOutPath);
  }
}
