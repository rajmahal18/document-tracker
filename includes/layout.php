<?php
$pageTitle = $pageTitle ?? "Document Tracker";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="stylesheet" href="/document-tracker/assets/css/style.css">
</head>
<body>

<header class="topbar">
  <div class="brand">
    <div class="logo">
      <img src="/document-tracker/mpwlogo1.png" alt="MPW Logo" />
    </div>

    <div class="brandText">
      <h1>Ministry of Public Works</h1>
      <span>Bangsamoro Autonomous Region in Muslim Mindanao</span>
    </div>
  </div>

  <nav class="nav">
    <a href="/document-tracker/index.php">Home</a>
    <a href="#" onclick="event.preventDefault()">Online Services</a>
    <a href="#" onclick="event.preventDefault()">About Us</a>

    <?php if (isset($_SESSION["user_id"])): ?>
        <a href="/document-tracker/logout.php" style="margin-left:18px;color:#ffdddd;">
        Logout
        </a>
    <?php endif; ?>
    </nav>

</header>

<main class="page">
  <div class="content">
