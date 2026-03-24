<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? "Document Tracker";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <meta name="theme-color" content="#0b3a66">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="MPW Tracker">
  <meta name="mobile-web-app-capable" content="yes">
  <title><?= htmlspecialchars($pageTitle) ?></title>

  <link rel="manifest" href="<?= asset_url("public/manifest.webmanifest") ?>">
  <link rel="apple-touch-icon" href="<?= asset_url("assets/icons/icon-192.png") ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= asset_url("assets/icons/icon-192.png") ?>">
  <link rel="icon" type="image/png" sizes="512x512" href="<?= asset_url("assets/icons/icon-512.png") ?>">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="<?= asset_url("assets/css/base.css") ?>">
  <link rel="stylesheet" href="<?= asset_url("assets/css/components.css") ?>">
  <link rel="stylesheet" href="<?= asset_url("assets/css/app-shell.css") ?>">
  <link rel="stylesheet" href="<?= asset_url("assets/css/org-chart.css") ?>">
  <link rel="stylesheet" href="<?= asset_url("assets/css/documents.css") ?>">
  <?php if (!empty($pageStyles) && is_array($pageStyles)): ?>
    <?php foreach ($pageStyles as $href): ?>
      <link rel="stylesheet" href="<?= htmlspecialchars((string)$href, ENT_QUOTES, "UTF-8") ?>">
    <?php endforeach; ?>
  <?php endif; ?>

<script>
  window.__APP__ = {
    base: "<?= BASE_PATH ?>",
    api: "<?= API_PATH ?>",
    public: "<?= PUBLIC_PATH ?>",
    assets: "<?= ASSETS_PATH ?>",
    csrf: "<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>",
    currentPage: "<?= htmlspecialchars($currentPage ?? basename($_SERVER['PHP_SELF'])) ?>",
    isDevelopment: <?= app_is_dev_environment() ? "true" : "false" ?>
  };
</script>
</head>

<body>

<?php
  $currentPage = basename($_SERVER['PHP_SELF']);
?>
<header class="topbar appTopbar">
  <div class="appTopbarMain">
    <?php if (isset($_SESSION["user_id"])): ?>
      <div class="topbarTools topbarToolsLeft">
        <button type="button" class="navToggle" id="navToggle" aria-label="Open navigation" aria-expanded="false" aria-controls="mainNav">
          <span class="navToggleLine"></span>
          <span class="navToggleLine"></span>
          <span class="navToggleLine"></span>
        </button>
      </div>
    <?php endif; ?>

    <a href="<?= PUBLIC_PATH ?>/documents.php" class="brand appBrandShell appBrandHeaderLink">
      <span class="logo appBrandLogoLink">
        <img src="<?= ASSETS_PATH ?>/mpwlogo1.png" alt="MPW Logo" />
      </span>

      <span class="brandText appBrandTextLink">
        <h1>Ministry of Public Works</h1>
        <span class="brandSubtitle">Bangsamoro Autonomous Region in Muslim Mindanao</span>
      </span>
    </a>
  </div>
</header>

<?php if (isset($_SESSION["user_id"])): ?>
  <div class="appNavBackdrop" id="appNavBackdrop" hidden></div>
  <nav class="nav appNav appSideNav" id="mainNav" aria-hidden="true">
    <div class="appDrawerHeader">
      <div class="appDrawerBrand">
        <img src="<?= ASSETS_PATH ?>/mpwlogo1.png" alt="MPW Logo" />
        <div>
          <strong>MPW Document Tracker</strong>
        </div>
      </div>
      <button type="button" class="appDrawerClose" id="navCloseBtn" aria-label="Close navigation">✕</button>
    </div>

    <div class="appDrawerSectionLabel">Navigation</div>
    <div class="appDrawerNavLinks">
    <a 
      href="<?= PUBLIC_PATH ?>/documents.php"
      class="<?= in_array($currentPage, ['documents.php', 'index.php'], true) ? 'navActive' : '' ?>"
    >
      <span class="navIcon">⌂</span>
      <span class="navText">Home</span>
    </a>

    <a href="<?= PUBLIC_PATH ?>/scan.php" class="<?= $currentPage === 'scan.php' ? 'navActive' : '' ?>">
      <span class="navIcon">⌁</span>
      <span class="navText">Scan QR</span>
    </a>

    <a href="#" class="navPlaceholder" onclick="event.preventDefault()">
      <span class="navIcon">◎</span>
      <span class="navText">Issuances</span>
    </a>
    <a href="<?= PUBLIC_PATH ?>/org_chart.php" class="<?= $currentPage === 'org_chart.php' ? 'navActive' : '' ?>">
      <span class="navIcon">▤</span>
      <span class="navText">Organizational Chart</span>
    </a>
    <a href="#" class="navPlaceholder" onclick="event.preventDefault()">
      <span class="navIcon">☷</span>
      <span class="navText">Ministry Orders</span>
    </a>

    <?php if ((string)($_SESSION["role"] ?? "user") === "admin"): ?>
      <a
        href="<?= PUBLIC_PATH ?>/access_requests.php"
        class="<?= $currentPage === 'access_requests.php' ? 'navActive' : '' ?>"
      >
        <span class="navIcon">⚑</span>
        <span class="navText">Access Requests</span>
      </a>
    <?php endif; ?>

    <a href="<?= PUBLIC_PATH ?>/logout.php" class="navLogout">
      <span class="navIcon">↗</span>
      <span class="navText">Logout</span>
    </a>
    </div>

    <div class="appDrawerSectionLabel">Appearance</div>
    <div class="themePicker themePickerDrawer" aria-label="Appearance mode">
      <button type="button" class="themeOrb isActive" data-theme-value="light" aria-label="Use light mode" title="Light mode"></button>
      <button type="button" class="themeOrb themeOrbDark" data-theme-value="dark" aria-label="Use dark mode" title="Dark mode"></button>
    </div>

    <button type="button" id="installAppBtn" class="appInstallDrawerBtn" hidden>Install app</button>
  </nav>
<?php endif; ?>

<?php if (isset($_SESSION["user_id"]) && $currentPage !== "scan.php"): ?>
  <a href="<?= PUBLIC_PATH ?>/scan.php" class="mobileScanFab" aria-label="Scan document QR">Scan</a>
<?php endif; ?>

<main class="page">
  <div class="content">

    <?php if (isset($_SESSION["user_id"])): ?>
      <?php
        $fullName = (string)($_SESSION["full_name"] ?? "User");
        $officialTitle = trim((string)($_SESSION["official_title"] ?? ""));
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
        <?php if ($officialTitle !== ""): ?>
          <br>
          <span style="opacity:.75;"><?= htmlspecialchars($officialTitle) ?></span>
        <?php endif; ?>
        <?php if ($orgLine !== ""): ?>
          <br>
          <span style="opacity:.75;">
            <?= htmlspecialchars($orgLine) ?>
          </span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
