<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? "Document Tracker";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>

  <link rel="stylesheet" href="<?= ASSETS_PATH ?>/css/style.css?v=10">

<script>
  window.__APP__ = {
    base: "<?= BASE_PATH ?>",
    api: "<?= API_PATH ?>",
    public: "<?= PUBLIC_PATH ?>",
    assets: "<?= ASSETS_PATH ?>"
  };
</script>
</head>

<body>

<header class="topbar">
  <div class="brand">
    <div class="logo">
      <img src="<?= ASSETS_PATH ?>/mpwlogo1.png" alt="MPW Logo" />
    </div>

    <div class="brandText">
      <h1>Ministry of Public Works</h1>
      <span>Bangsamoro Autonomous Region in Muslim Mindanao</span>
    </div>
  </div>

  <nav class="nav">
    <a href="<?= PUBLIC_PATH ?>/index.php">Home</a>
    <a href="#" onclick="event.preventDefault()">Online Services</a>
    <a href="#" onclick="event.preventDefault()">About Us</a>

    <?php if (isset($_SESSION["user_id"])): ?>
      <a href="<?= PUBLIC_PATH ?>/logout.php" style="margin-left:18px;color:#ffdddd;">
        Logout
      </a>
    <?php endif; ?>
  </nav>
</header>

<main class="page">
  <div class="content">

    <?php if (isset($_SESSION["user_id"])): ?>
      <?php
        $fullName = (string)($_SESSION["full_name"] ?? "User");
        $division = trim((string)($_SESSION["division_name"] ?? ""));
        $section  = trim((string)($_SESSION["section_name"] ?? ""));
        $role     = trim((string)($_SESSION["role"] ?? "user"));

        // Build "Division — Section" line
        $orgLine = "";
        if ($division !== "" && $section !== "") $orgLine = $division . " — " . $section;
        elseif ($division !== "") $orgLine = $division;
        elseif ($section !== "") $orgLine = $section;
      ?>

      <div class="mini" style="margin: 0 0 12px; opacity: .85;">
        Signed in as <b><?= htmlspecialchars($fullName) ?></b>
        <?php if ($orgLine !== ""): ?>
          <br>
          <span style="opacity:.75;">
            <?= htmlspecialchars($orgLine) ?>
          </span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
