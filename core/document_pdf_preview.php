<?php
declare(strict_types=1);

/** Build the entire PDF before sending headers: FPDI may also fail during output. */
function build_document_pdf_preview(array $files): string {
  $pdf = new setasign\Fpdi\Fpdi();
  $pdf->SetAutoPageBreak(false);
  try {
    foreach ($files as $file) {
      $pageCount = $pdf->setSourceFile($file);
      for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
        $tpl = $pdf->importPage($pageNo);
        $size = $pdf->getTemplateSize($tpl);
        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($tpl);
      }
    }
    return $pdf->Output('S');
  } finally {
    $pdf->cleanUp();
  }
}
