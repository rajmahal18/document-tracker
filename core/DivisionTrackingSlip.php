<?php
declare(strict_types=1);

/**
 * Division Document Tracking Slip (A4) with Logos + QR (TransmittalMemo-compatible)
 *
 * Requirements:
 * - Composer libs: setasign/fpdf
 * - QR (optional): endroid/qr-code (same style as TransmittalMemo)
 *
 * Notes:
 * - If QR lib not installed, QR will be skipped (no crash).
 * - Layout is form-like and printable.
 */
final class DivisionTrackingSlip
{
  private static function pdfText(string $text): string
  {
    return function_exists('normalize_pdf_text') ? normalize_pdf_text($text) : $text;
  }

  /**
   * @param array{
   *   mpw_tracking_no?:string,
   *   division_tracking_no:string,
   *   division_name?:string,
   *   division_code?:string,
   *   signatory_name?:string,
   *   signatory_title?:string,
   *   flow_rows?:array<int,array<string,string>>,
   *   from_label?:string,
   *   document_date?:string,
   *   received_by?:string,
   *   received_datetime?:string,
   *   subject:string,
   *   deadline_date?:string,
   *   deadline_time?:string,
   *   qr_url?:string,
   *   logo_left_abs?:string,
   *   logo_right_abs?:string
   * } $data
   */
  public static function generateA4(array $data, string $absOutPath): void
  {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
      throw new RuntimeException('PDF library not available (missing vendor/autoload.php). Run: composer install');
    }
    require_once $autoload;

    // Accept BOTH namespaced and global FPDF
    if (!class_exists('setasign\\Fpdf\\Fpdf') && !class_exists('FPDF')) {
      throw new RuntimeException('PDF library not available (FPDF not found).');
    }
    $pdfClass = class_exists('setasign\\Fpdf\\Fpdf') ? 'setasign\\Fpdf\\Fpdf' : 'FPDF';

    $divisionNo   = trim((string)($data['division_tracking_no'] ?? ''));
    $divisionName = trim((string)($data['division_name'] ?? ''));
    $divisionCode = strtoupper(trim((string)($data['division_code'] ?? '')));
    if ($divisionName === '' && $divisionCode !== '') {
      $divisionName = $divisionCode;
    }
    $subject = trim((string)($data['subject'] ?? ''));

    if ($divisionNo === '' || $subject === '') {
      throw new RuntimeException('DivisionTrackingSlip requires division_tracking_no and subject.');
    }

    $pdf = new $pdfClass('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $fontFamily = 'Times';
    $cambriaFontDir = realpath(__DIR__ . '/../assets/fonts/fpdf');
    if ($cambriaFontDir !== false && is_file($cambriaFontDir . DIRECTORY_SEPARATOR . 'cambriab.php')) {
      $pdf->AddFont('Cambria', '', 'cambriab.php', rtrim($cambriaFontDir, '/\\') . DIRECTORY_SEPARATOR);
      $pdf->AddFont('Cambria', 'B', 'cambriab.php', rtrim($cambriaFontDir, '/\\') . DIRECTORY_SEPARATOR);
      $fontFamily = 'Cambria';
    }

    // ---------- Helpers ----------
    $line = function(float $x1, float $y1, float $x2, float $y2) use ($pdf): void {
      $pdf->Line($x1, $y1, $x2, $y2);
    };
    $rect = function(float $x, float $y, float $w, float $h) use ($pdf): void {
      $pdf->Rect($x, $y, $w, $h);
    };

    // allow float font size
    $setFont = function(string $style = '', float $size = 9.0) use ($pdf, $fontFamily): void {
      $pdf->SetFont($fontFamily, $style, $size);
    };

    $txt = function(float $x, float $y, string $text, string $style = '', float $size = 9.0, string $align = 'L') use ($pdf, $setFont): void {
      $setFont($style, $size);
      $pdf->SetXY($x, $y);
      $pdf->Cell(0, 5, $text, 0, 0, $align);
    };

    $wrap = function(float $x, float $y, float $w, string $text, string $style = '', float $size = 9.0, float $lh = 4.2, string $align = 'L') use ($pdf, $setFont): float {
      $setFont($style, $size);
      $pdf->SetXY($x, $y);
      $pdf->MultiCell($w, $lh, $text, 0, $align);
      return (float)$pdf->GetY();
    };

    // ---------- Page frame ----------
    $pageW = 210.0;
    $pageH = 297.0;

    $margin = 10.0;
    $x0 = $margin;
    $y0 = 10.0;
    $w0 = $pageW - ($margin * 2);
    $h0 = $pageH - ($y0 + 10.0);

    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.7);
    $rect($x0, $y0, $w0, $h0);

    // ---------- Header with logos + QR ----------
    $headerH = 30.0; // more space (less sikip)
    $pdf->SetLineWidth(0.5);
    $rect($x0, $y0, $w0, $headerH);

    $pad = 3.0;
    $logoSize = 16.0;  // match TransmittalMemo look
    $logoY = $y0 + $pad;

    $logoLeft  = trim((string)($data['logo_left_abs'] ?? ''));
    $logoRight = trim((string)($data['logo_right_abs'] ?? ''));
    $qrUrl     = trim((string)($data['qr_url'] ?? ''));

    if ($logoLeft !== '' && is_file($logoLeft)) {
      @$pdf->Image($logoLeft, $x0 + $pad, $logoY, $logoSize, $logoSize);
    }
    if ($logoRight !== '' && is_file($logoRight)) {
      @$pdf->Image($logoRight, $x0 + $w0 - $pad - $logoSize, $logoY, $logoSize, $logoSize);
    }

    // QR beside the right logo (move LEFT so it won't overlap)
    $tmpQrPath = null;
    $qrSizeMm  = 16.0; // slightly smaller, cleaner
    $gap = 2.0;

    if ($qrUrl !== '') {
      $tmpQrPath = self::makeQrPngEndroid($qrUrl);
      if (!empty($tmpQrPath)) {

        // OCM logo is at: x0 + w0 - pad - logoSize
        // Put QR immediately LEFT of OCM logo with small gap
        $qrX = $x0 + $w0 - $pad - $logoSize - $gap - $qrSizeMm;
        $qrY = $logoY; // same top row as logos

        // safety clamp inside header
        if ($qrX < ($x0 + $pad)) $qrX = $x0 + $pad;
        if (($qrY + $qrSizeMm) > ($y0 + $headerH - 1.5)) {
          $qrY = ($y0 + $headerH - 1.5) - $qrSizeMm;
        }

        @$pdf->Image($tmpQrPath, $qrX, $qrY, $qrSizeMm, $qrSizeMm);
      }
    }

    // center header text (leave space for logos)
    $centerX = $x0 + ($pad + $logoSize + 4.0);
    $centerW = $w0 - (2 * ($pad + $logoSize + 4.0));

    $pdf->SetTextColor(20, 24, 40);
    $setFont('B', 9.4);
    $pdf->SetXY($centerX, $y0 + 5.0);
    $pdf->Cell($centerW, 4.8, 'MINISTRY OF PUBLIC WORKS', 0, 1, 'C');

    $setFont('B', 8.7);
    $pdf->SetX($centerX);
    $pdf->Cell($centerW, 4.8, $divisionName !== '' ? strtoupper($divisionName) : 'DIVISION', 0, 1, 'C');

    $setFont('B', 10.4);
    $pdf->SetX($centerX);
    $pdf->Cell($centerW, 6.2, 'DOCUMENT TRACKING SLIP', 0, 1, 'C');

    // ---------- Tracking No row ----------
    $y = $y0 + $headerH;
    $rowH = 12.5;
    $rect($x0, $y, $w0, $rowH);

    $leftW  = $w0 * 0.50;
    $rightW = $w0 - $leftW;
    $line($x0 + $leftW, $y, $x0 + $leftW, $y + $rowH);

    $txt($x0 + 2, $y + 2.2, 'MPW Tracking No.:', '', 8.0);
    $txt($x0 + $leftW + 2, $y + 2.2, ($divisionCode !== '' ? $divisionCode : 'Division') . ' Tracking No.:', '', 8.0);

    $mpwNo = trim((string)($data['mpw_tracking_no'] ?? ''));
    $txt($x0 + 2, $y + 6.8, $mpwNo, 'B', 10.2);
    $txt($x0 + $leftW + 2, $y + 6.8, $divisionNo, 'B', 10.2);

    $y += $rowH;

    // ---------- From + Doc Date + Received by + Received DT ----------
    $rowH2 = 19.0;
    $rect($x0, $y, $w0, $rowH2);
    $line($x0 + $leftW, $y, $x0 + $leftW, $y + $rowH2);

    $txt($x0 + 2, $y + 2.0, 'From (If Applicable):', '', 8.0);
    $from = trim((string)($data['from_label'] ?? ''));
    $wrap($x0 + 2, $y + 6.4, $leftW - 4, $from, 'B', 9.1, 4.2, 'L');

    // right side split into 3 cols
    // give more room to "Received by" so long names won't spill outside the cell
    $c1 = $rightW * 0.26;
    $c2 = $rightW * 0.41;
    $c3 = $rightW - $c1 - $c2;

    $xR = $x0 + $leftW;
    $line($xR + $c1, $y, $xR + $c1, $y + $rowH2);
    $line($xR + $c1 + $c2, $y, $xR + $c1 + $c2, $y + $rowH2);

    $txt($xR + 2, $y + 2.0, 'Document Date:', '', 8.0);
    $txt($xR + $c1 + 2, $y + 2.0, 'Received by:', '', 8.0);
    $wrap($xR + $c1 + $c2 + 2, $y + 1.6, $c3 - 4, "Received Date\nand Time:", '', 7.0, 3.4, 'L');

    $docDate    = trim((string)($data['document_date'] ?? ''));
    $receivedBy = strtoupper(trim((string)($data['received_by'] ?? '')));
    $receivedDT = trim((string)($data['received_datetime'] ?? ''));

    $txt($xR + 2, $y + 7.4, $docDate, 'B', 9.0);
    $wrap($xR + $c1 + 2, $y + 10.6, $c2 - 4, $receivedBy, 'B', 8.0, 3.5, 'L');
    $wrap($xR + $c1 + $c2 + 2, $y + 7.2, $c3 - 4, $receivedDT, 'B', 8.2, 3.8, 'L');

    $y += $rowH2;

    // ---------- Subject ----------
    $rowH3 = 16.0; // more space
    $rect($x0, $y, $w0, $rowH3);

    $txt($x0 + 2, $y + 2.2, 'SUBJECT:', '', 8.0);
    $wrap($x0 + 18, $y + 2.2, $w0 - 20, $subject, 'B', 9.2, 4.4, 'L');

    $y += $rowH3;

    // ---------- Names block ----------
    $pdf->SetTextColor(20, 24, 40);
    $nameEntries = is_array($data['name_entries'] ?? null) ? array_values($data['name_entries']) : [];

    $entries = array_slice($nameEntries, 0, 9);
    while (count($entries) < 9) {
      $entries[] = '';
    }

    $cols = 3;
    $rows = 3;
    $rH = 6.8;
    $namesH = $rows * $rH;
    $colW = $w0 / $cols;

    $rect($x0, $y, $w0, $namesH);

    for ($col = 1; $col < $cols; $col++) {
      $lineX = $x0 + ($col * $colW);
      $line($lineX, $y, $lineX, $y + $namesH);
    }
    for ($rowLine = 1; $rowLine < $rows; $rowLine++) {
      $lineY = $y + ($rowLine * $rH);
      $line($x0, $lineY, $x0 + $w0, $lineY);
    }

    $setFont('B', 6.4);
    for ($idx = 0; $idx < 9; $idx++) {
      $col = intdiv($idx, $rows);
      $row = $idx % $rows;
      $cellX = $x0 + ($col * $colW);
      $cellY = $y + ($row * $rH);

      self::drawCheckbox($pdf, $cellX + 3.0, $cellY + 1.9);

      $label = self::pdfText((string)($entries[$idx] ?? ''));
      if ($label !== '') {
        $pdf->SetXY($cellX + 8.4, $cellY + 1.4);
        $pdf->MultiCell($colW - 10.8, 3.2, $label, 0, 'L');
      }
    }

    $y += $namesH;

    // ---------- Actions checkboxes ----------
    $actionsH = 32.0;
    $rect($x0, $y, $w0, $actionsH);

    $labelW = 20.0;
    $line($x0 + $labelW, $y, $x0 + $labelW, $y + $actionsH);
    $txt($x0 + 2, $y + 2.0, 'ACTIONS:', 'B', 8.0);

    $optW = ($w0 - $labelW) / 3.0;
    $xA = $x0 + $labelW;
    $xB = $xA + $optW;
    $xC = $xB + $optW;

    $line($xB, $y, $xB, $y + $actionsH);
    $line($xC, $y, $xC, $y + $actionsH);

    $rows = 4;
    $cellH = $actionsH / $rows;
    for ($i=1; $i<$rows; $i++) {
      $line($x0 + $labelW, $y + ($i * $cellH), $x0 + $w0, $y + ($i * $cellH));
    }

    $opts = [
      ['For Information/Reference', 'For Review', 'For Dissemination'],
      ['For Appropriate Action', 'For Endorsement', 'For Compliance'],
      ['For Comments/Recommendations', 'For Coordination', 'Prepare Response'],
      ['For Validation/Verification', 'For Filing', 'See Me'],
    ];

    $setFont('', 7.4);

    for ($r = 0; $r < $rows; $r++) {
      $rowTop = $y + ($r * $cellH);

      // vertical tuning: checkbox sits mid, text baseline consistent
      $boxY  = $rowTop + 3.2;
      $textY = $rowTop + 2.6;

      $leftText  = $opts[$r][0] ?? '';
      $midText   = $opts[$r][1] ?? '';
      $rightText = $opts[$r][2] ?? '';

      // left col
      self::drawCheckbox($pdf, $xA + 3.2, $boxY);
      $pdf->SetXY($xA + 9.2, $textY);
      $pdf->Cell($optW - 10, 5, $leftText, 0, 0, 'L');

      // mid col
      self::drawCheckbox($pdf, $xB + 3.2, $boxY);
      $pdf->SetXY($xB + 9.2, $textY);
      $pdf->Cell($optW - 10, 5, $midText, 0, 0, 'L');

      // right col
      if ($rightText !== '') {
        self::drawCheckbox($pdf, $xC + 3.2, $boxY);
        $pdf->SetXY($xC + 9.2, $textY);
        $pdf->Cell($optW - 10, 5, $rightText, 0, 0, 'L');
      }
    }
    $y += $actionsH;

    // ---------- Other actions / Deadline ----------
    $rowH4 = 24.0;
    $rect($x0, $y, $w0, $rowH4);

    $deadlineW = 46.0;
    $line($x0 + ($w0 - $deadlineW), $y, $x0 + ($w0 - $deadlineW), $y + $rowH4);

    $txt($x0 + 2, $y + 2.0, 'OTHER ACTIONS/INSTRUCTIONS:', '', 8.0);

    $txt($x0 + ($w0 - $deadlineW) + 2, $y + 2.0, 'Deadline', '', 8.0);
    $txt($x0 + ($w0 - $deadlineW) + 2, $y + 6.2, '(if applicable)', '', 7.2);

    $deadDate = trim((string)($data['deadline_date'] ?? ''));
    $deadTime = trim((string)($data['deadline_time'] ?? ''));

    // Date
    $txt($x0 + ($w0 - $deadlineW) + 2, $y + 11.5, $deadDate, 'B', 8.6);

    // 🔥 Divider line
    $line(
      $x0 + ($w0 - $deadlineW),
      $y + 13.5,
      $x0 + $w0,
      $y + 13.5
    );

    // Date/Time label
    $txt($x0 + ($w0 - $deadlineW) + 2, $y + 15.2, 'Date/Time:', '', 7.6);

    // Time value
    $txt($x0 + ($w0 - $deadlineW) + 2, $y + 18.8, $deadTime, 'B', 8.6);

    $y += $rowH4;

    // ---------- Signature ----------
    $sigH = 18.0;
    $rect($x0, $y, $w0, $sigH);

    $sigLineY = $y + 8.6;

    $setFont('BU', 8.6);
    $pdf->SetXY($x0, $sigLineY + 1.1);
    $pdf->Cell($w0, 4.5, strtoupper(trim((string)($data['signatory_name'] ?? ''))) ?: ' ', 0, 1, 'C');

    $setFont('', 7.4);
    $pdf->SetX($x0);
    $pdf->Cell($w0, 4.2, trim((string)($data['signatory_title'] ?? '')) ?: ' ', 0, 1, 'C');

    $y += $sigH;

    // ---------- Movement log table ----------
    $tableY = $y + 2.0;

    // Fill remaining safe space up to inside bottom page frame, with small bottom allowance
    $tableBottomMargin = 0.0;
    $tableH = ($y0 + $h0) - $tableY - $tableBottomMargin;

    $rect($x0, $tableY, $w0, $tableH);

    $hdrH = 10.5;
    $line($x0, $tableY + $hdrH, $x0 + $w0, $tableY + $hdrH);

    $rowCount = 8;
    $rowH = ($tableH - $hdrH) / $rowCount;

    $c1 = $w0 * 0.09; // Receive Date/Time
    $c2 = $w0 * 0.10; // From
    $c3 = $w0 * 0.09; // Forward Date/Time
    $c4 = $w0 * 0.10; // To
    $c6 = $w0 * 0.09; // Deadline
    $c5 = $w0 - $c1 - $c2 - $c3 - $c4 - $c6; // Remarks gets the space

    $xC1 = $x0;
    $xC2 = $xC1 + $c1;
    $xC3 = $xC2 + $c2;
    $xC4 = $xC3 + $c3;
    $xC5 = $xC4 + $c4;
    $xC6 = $xC5 + $c5;

    foreach ([$xC2, $xC3, $xC4, $xC5, $xC6] as $xx) {
      $line($xx, $tableY, $xx, $tableY + $tableH);
    }

    for ($i=1; $i<$rowCount; $i++) {
      $yy = $tableY + $hdrH + ($i * $rowH);
      $line($x0, $yy, $x0 + $w0, $yy);
    }

    $setFont('B', 5.8);
    $headY = $tableY + 1.2;
    $pdf->SetXY($xC1 + 0.8, $headY); $pdf->MultiCell($c1 - 1.6, 2.7, 'Receive Date/Time', 0, 'C');
    $pdf->SetXY($xC2 + 0.8, $headY); $pdf->MultiCell($c2 - 1.6, 2.7, 'From', 0, 'C');
    $pdf->SetXY($xC3 + 0.8, $headY); $pdf->MultiCell($c3 - 1.6, 2.7, 'Forward Date/Time', 0, 'C');
    $pdf->SetXY($xC4 + 0.8, $headY); $pdf->MultiCell($c4 - 1.6, 2.7, 'To', 0, 'C');
    $pdf->SetXY($xC5 + 0.8, $headY); $pdf->MultiCell($c5 - 1.6, 2.7, 'Actions/Other Instructions/Remarks', 0, 'C');
    $pdf->SetXY($xC6 + 0.8, $headY); $pdf->MultiCell($c6 - 1.6, 2.7, 'Deadline (if Applicable)', 0, 'C');

    $flowRows = $data['flow_rows'] ?? [];
    if (is_array($flowRows) && $flowRows !== []) {
      $stackDateTime = static function (string $value): string {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(.+)\s+(\S+)$/', $value, $matches)) {
          return $value;
        }
        return trim($matches[1]) . "\n" . trim($matches[2]);
      };

      $setFont('', 6.5);
      foreach (array_slice($flowRows, 0, $rowCount) as $idx => $row) {
        $baseRowTop = $tableY + $hdrH + ($idx * $rowH);
        $rowTop = $baseRowTop + 1.2;
        $nameY = $baseRowTop + $rowH - 4.8;
        $receivedDt = self::pdfText($stackDateTime((string)($row['received_datetime'] ?? '')));
        $fromName = self::pdfText(trim((string)($row['from_name'] ?? $row['received_name'] ?? '')));
        $forwardedDt = self::pdfText($stackDateTime((string)($row['forwarded_datetime'] ?? '')));
        $toName = self::pdfText(trim((string)($row['to_name'] ?? $row['forwarded_name'] ?? '')));
        $forwardedText = self::pdfText(trim((string)($row['forwarded_text'] ?? '')));
        $deadlineText = self::pdfText(trim((string)($row['deadline'] ?? '')));

        $pdf->SetXY($xC1 + 1.0, $rowTop);
        $pdf->MultiCell($c1 - 2.0, 3.2, $receivedDt, 0, 'C');
        $pdf->SetXY($xC2 + 1.0, $nameY);
        $pdf->MultiCell($c2 - 2.0, 3.2, $fromName, 0, 'C');
        $pdf->SetXY($xC3 + 1.0, $rowTop);
        $pdf->MultiCell($c3 - 2.0, 3.2, $forwardedDt, 0, 'C');
        $pdf->SetXY($xC4 + 1.0, $nameY);
        $pdf->MultiCell($c4 - 2.0, 3.2, $toName, 0, 'C');
        $pdf->SetXY($xC5 + 1.0, $rowTop);
        $pdf->MultiCell($c5 - 2.0, 3.2, $forwardedText, 0, 'C');
        $pdf->SetXY($xC6 + 1.0, $rowTop);
        $pdf->MultiCell($c6 - 2.0, 3.2, $deadlineText, 0, 'C');
      }
    }

    // ensure output dir
    $dir = dirname($absOutPath);
    if (!is_dir($dir)) {
      if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Unable to create output directory: " . $dir);
      }
    }

    // cleanup temp QR file (same as TransmittalMemo)
    if (!empty($tmpQrPath) && is_file($tmpQrPath)) {
      @unlink($tmpQrPath);
    }

    $pdf->Output('F', $absOutPath);
  }

  private static function drawCheckbox($pdf, float $x, float $y): void
  {
    $pdf->Rect($x, $y, 3.2, 3.2);
  }

  /**
   * EXACT TransmittalMemo-style QR generation using Endroid (optional).
   * Returns temp png absolute path or null.
   */
  private static function makeQrPngEndroid(string $qrUrl): ?string
  {
    $qrUrl = trim($qrUrl);
    if ($qrUrl === '') return null;

    if (!class_exists(\Endroid\QrCode\QrCode::class)) return null;
    if (!class_exists(\Endroid\QrCode\Writer\PngWriter::class)) return null;

    $qr = \Endroid\QrCode\QrCode::create($qrUrl)
      ->setSize(420)
      ->setMargin(10);

    $writer = new \Endroid\QrCode\Writer\PngWriter();
    $result = $writer->write($qr);

    $tmpQrPath = sys_get_temp_dir() . '/qr_' . bin2hex(random_bytes(8)) . '.png';
    $result->saveToFile($tmpQrPath);

    return (is_file($tmpQrPath) && filesize($tmpQrPath) > 0) ? $tmpQrPath : null;
  }
}
