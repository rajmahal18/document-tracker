<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

// Standalone regression test; uses real FPDI with stubbed document metadata/access.
// Run: php tests/document_pdf_preview_test.php
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (($argv[1] ?? '') === 'viewer') {
  $fixture = $argv[2];
  $scenario = $argv[3];
  register_shutdown_function(static function (): void {
    fwrite(STDERR, 'TEST_HTTP_STATUS=' . (http_response_code() ?: 200) . "\n");
  });
  $_GET = ['document_id' => 1];
  $_SESSION = [];
  function require_login(): void {}
  function can_view_document($conn, $id): bool { return $GLOBALS['scenario'] !== 'denied'; }
  function attachment_branch_scope_for_document(...$args): array { return ['scoped' => false]; }
  function workflow_branch_attachment_scope_enabled($conn): bool { return false; }
  function effective_document_identity($conn): array {
    return ['acting_principal_user_id' => $GLOBALS['scenario'] === 'assistant' ? 42 : 0];
  }
  $atts = [];
  $secondFile = match ($scenario) {
    'compatible' => 'compatible.pdf',
    'malformed' => 'malformed.pdf',
    default => 'unsupported.pdf',
  };
  foreach (['compatible.pdf', $secondFile] as $i => $name) {
    $atts[] = ['id' => $i + 1, 'original_name' => $i ? '<uploaded>.pdf' : 'Slip.pdf',
      'stored_path' => 'tests/' . basename($fixture) . '/' . $name,
      'mime' => 'application/pdf', 'note' => '', 'branch_id' => null];
  }
  $conn = new class {
    public function prepare($sql) { return $this; }
    public function bind_param($types, &...$values): void {}
    public function execute(): void {}
    public function get_result() { return $this; }
    public function fetch_assoc(): array { return ['tracking_no' => 'TEST']; }
    public function fetch_all($mode): array { return $GLOBALS['atts']; }
  };
  $source = file_get_contents($root . '/public/view_document.php');
  $source = str_replace('require __DIR__ . "/../includes/bootstrap.php";', '', $source);
  $source = str_replace("require_once __DIR__ . '/../core/division_tracking.php';", '', $source);
  // Avoid probing external executables in the test; GS remains disabled in production.
  $source = str_replace('$gs = find_gs_binary();', '$gs = null;', $source);
  $source = str_replace('__DIR__', var_export($root . '/public', true), $source);
  eval(substr($source, 5));
  exit;
}

function check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}
$fixture = __DIR__ . '/pdf_preview_' . bin2hex(random_bytes(6));
mkdir($fixture);
try {
  $pdf = new FPDF();
  $pdf->AddPage('P', [100, 150]);
  $pdf->AddPage('L', [100, 150]);
  $pdf->Output('F', $fixture . '/compatible.pdf');

  // Minimal PDF 1.5 with a cross-reference stream (unsupported by free FPDI).
  $data = "%PDF-1.5\n";
  $offsets = [0];
  foreach ([
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 150] >>',
  ] as $i => $object) {
    $offsets[] = strlen($data);
    $data .= ($i + 1) . " 0 obj\n$object\nendobj\n";
  }
  $offsets[] = strlen($data);
  $stream = pack('CNn', 0, 0, 65535);
  foreach (array_slice($offsets, 1) as $offset) $stream .= pack('CNn', 1, $offset, 0);
  $data .= "4 0 obj\n<< /Type /XRef /Size 5 /Root 1 0 R /W [1 4 2] /Length " . strlen($stream)
    . " >>\nstream\n$stream\nendstream\nendobj\nstartxref\n" . $offsets[4] . "\n%%EOF\n";
  file_put_contents($fixture . '/unsupported.pdf', $data);
  file_put_contents($fixture . '/malformed.pdf', 'Not a valid PDF');

  require $root . '/core/document_pdf_preview.php';
  try {
    build_document_pdf_preview([$fixture . '/unsupported.pdf']);
    throw new RuntimeException('Expected compressed cross-reference exception');
  } catch (setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException $e) {
    check($e->getCode() === $e::COMPRESSED_XREF, 'Fixture must reproduce reported failure');
  }
  foreach (['compatible', 'unsupported', 'malformed', 'assistant', 'denied'] as $scenario) {
    $process = proc_open([PHP_BINARY, __FILE__, 'viewer', $fixture, $scenario],
      [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $body = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0, "$scenario failed: $errors");
    $expectedStatus = $scenario === 'denied' ? 403 : 200;
    check(str_contains($errors, 'TEST_HTTP_STATUS=' . $expectedStatus), "$scenario returned wrong status: $errors");
    if ($scenario === 'compatible') {
      check(str_starts_with($body, '%PDF-'), 'Compatible viewer must return PDF');
      $reader = new setasign\Fpdi\Fpdi();
      check($reader->setSourceFile(setasign\Fpdi\PdfParser\StreamReader::createByString($body)) === 4, 'All pages must survive merge');
      $size = $reader->getTemplateSize($reader->importPage(2));
      check(abs($size['width'] - 150) < 0.1 && abs($size['height'] - 100) < 0.1, 'Page dimensions must survive');
    } elseif ($scenario === 'denied') {
      check($body === 'Forbidden', 'Unauthorized viewer must remain denied');
    } else {
      check(str_contains($body, 'One or more files could not be combined'), 'Fallback notice missing');
      check(!str_contains($body, '%PDF-'), 'Fallback must not contain partial PDF output');
      check(str_contains($body, '&lt;uploaded&gt;.pdf'), 'Attachment names must be escaped');
      check(strpos($body, 'Slip.pdf') < strpos($body, '&lt;uploaded&gt;'), 'Attachment order changed');
      foreach ([1, 2] as $id) {
        foreach (['view', 'download'] as $action) {
          $url = $action . '_attachment.php?id=' . $id;
          if ($scenario === 'assistant') $url .= '&amp;acting_principal_user_id=42';
          check(str_contains($body, 'href="' . $url . '"'), 'Missing original attachment link/context');
        }
      }
    }
    echo "PASS: $scenario\n";
  }
} finally {
  foreach (glob($fixture . '/*') as $file) unlink($file);
  rmdir($fixture);
}
