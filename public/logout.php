<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php"; // session + constants

$_SESSION = [];
session_destroy();

redirect(PUBLIC_PATH . "/login.php");
