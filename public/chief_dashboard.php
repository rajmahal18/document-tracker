<?php
declare(strict_types=1);

$query = $_GET;
$query['view'] = 'chief';
$target = './documents.php';
if ($query !== []) {
  $target .= '?' . http_build_query($query);
}

header('Location: ' . $target, true, 302);
exit;
