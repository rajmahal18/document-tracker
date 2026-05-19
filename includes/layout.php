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
  <link rel="icon" type="image/png" href="<?= asset_url("assets/mpwlogo1.png") ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= asset_url("assets/icons/icon-192.png") ?>">
  <link rel="icon" type="image/png" sizes="512x512" href="<?= asset_url("assets/icons/icon-512.png") ?>">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="<?= asset_url("assets/css/base.css") ?>">
  <link rel="stylesheet" href="<?= asset_url("assets/css/components.css") ?>">
  <link rel="stylesheet" href="<?= asset_url("assets/css/app-shell.css") ?>">
  <?php if (empty($disableLegacyOrgChartStyles)): ?>
  <link rel="stylesheet" href="<?= asset_url("assets/css/org-chart.css") ?>">
  <?php endif; ?>
  <?php if (empty($disableDocumentsStyles)): ?>
  <link rel="stylesheet" href="<?= asset_url("assets/css/documents.css") ?>">
  <?php endif; ?>
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

<body class="<?= htmlspecialchars(trim((string)($bodyClass ?? '')), ENT_QUOTES, "UTF-8") ?>">

<?php
  $currentPage = basename($_SERVER['PHP_SELF']);
  $currentContentScope = strtolower(trim((string)($_GET['content_scope'] ?? '')));
  $sessionDivisionName = trim((string)($_SESSION["division_name"] ?? ""));
  $sessionDivisionCode = strtoupper(trim((string)($_SESSION["division_code"] ?? "")));
  $resolvedDivisionName = $sessionDivisionName;
  $resolvedDivisionCode = $sessionDivisionCode;

  if (isset($_SESSION["user_id"], $_SESSION["section_id"], $conn) && $conn instanceof mysqli) {
    $resolvedSectionId = (int)($_SESSION["section_id"] ?? 0);
    if ($resolvedSectionId > 0) {
      $divisionMetaStmt = $conn->prepare("
        SELECT d.name, d.code
        FROM sections s
        JOIN divisions d ON d.id = s.division_id
        WHERE s.id = ?
        LIMIT 1
      ");
      if ($divisionMetaStmt) {
        $divisionMetaStmt->bind_param("i", $resolvedSectionId);
        $divisionMetaStmt->execute();
        $divisionMeta = $divisionMetaStmt->get_result()->fetch_assoc() ?: null;
        $divisionMetaStmt->close();

        if (is_array($divisionMeta)) {
          $resolvedDivisionName = trim((string)($divisionMeta["name"] ?? $resolvedDivisionName));
          $resolvedDivisionCode = strtoupper(trim((string)($divisionMeta["code"] ?? $resolvedDivisionCode)));
        }
      }
    }
  }

  $isPpdNavigationUser = $resolvedDivisionCode === 'PPD'
    || str_contains(strtolower($resolvedDivisionName), 'planning and programming');
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

    <div class="navGroup" aria-label="Documents group" id="navDocsGroup">
      <button type="button" class="navGroupTitle navGroupToggle" id="navDocsToggle" aria-expanded="false" aria-controls="navDocsItems">
        <span class="navIcon">&#9635;</span>
        <span class="navText">Documents</span>
        <span class="navGroupCaret" aria-hidden="true">&gt;</span>
      </button>
      <div class="navGroupItems" id="navDocsItems" hidden>
        <a href="http://13.214.52.254/indexdocs_cside_funded1.php" class="navSubLink">
          <span class="navIcon">&#8250;</span>
          <span class="navText">Funded</span>
        </a>
        <a href="http://13.214.52.254/viewdocs.php" class="navSubLink">
          <span class="navIcon">&#8250;</span>
          <span class="navText">Legacy Documents</span>
        </a>
        <?php if ($isPpdNavigationUser): ?>
          <a href="<?= PUBLIC_PATH ?>/documents.php?content_scope=planning" class="navSubLink <?= $currentPage === 'documents.php' && $currentContentScope === 'planning' ? 'navActive' : '' ?>">
            <span class="navIcon">&#8250;</span>
            <span class="navText">Planning</span>
          </a>
          <a href="<?= PUBLIC_PATH ?>/documents.php?content_scope=proposals" class="navSubLink <?= $currentPage === 'documents.php' && $currentContentScope === 'proposals' ? 'navActive' : '' ?>">
            <span class="navIcon">&#8250;</span>
            <span class="navText">Proposals</span>
          </a>
        <?php endif; ?>
      </div>
    </div>
    <a href="<?= PUBLIC_PATH ?>/org_chart.php" class="<?= $currentPage === 'org_chart.php' ? 'navActive' : '' ?>">
      <span class="navIcon">▤</span>
      <span class="navText">Organizational Chart</span>
    </a>
    <?php /*
    <a href="<?= PUBLIC_PATH ?>/task_monitoring.php" class="<?= $currentPage === 'task_monitoring.php' ? 'navActive' : '' ?>">
      <span class="navIcon">&#9716;</span>
      <span class="navText">Task Monitoring</span>
    </a>
    */ ?>
    <a href="<?= PUBLIC_PATH ?>/account.php" class="<?= $currentPage === 'account.php' ? 'navActive' : '' ?>">
      <span class="navIcon">☺</span>
      <span class="navText">My Account</span>
    </a>
    <?php if ((string)($_SESSION["role"] ?? "user") === "admin"): ?>
      <a
        href="<?= PUBLIC_PATH ?>/admin.php"
        class="<?= in_array($currentPage, ['admin.php', 'access_requests.php'], true) ? 'navActive' : '' ?>"
      >
        <span class="navIcon">⚙</span>
        <span class="navText">Admin</span>
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

<main class="page <?= htmlspecialchars(trim((string)($pageClass ?? '')), ENT_QUOTES, "UTF-8") ?>">
  <div class="content <?= htmlspecialchars(trim((string)($contentClass ?? '')), ENT_QUOTES, "UTF-8") ?>">

    <?php if (isset($_SESSION["user_id"]) && empty($hideAppUserSummary)): ?>
      <?php
        $fullName = (string)($_SESSION["full_name"] ?? "User");
        $username = trim((string)($_SESSION["username"] ?? ""));
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

      <?php
        $profilePhotoUrl = trim((string)($_SESSION["profile_photo_url"] ?? ""));
        $profileInitials = function_exists('app_user_initials') ? app_user_initials($fullName) : strtoupper(substr($fullName, 0, 1));
      ?>
      <div class="mini appUserSummary" style="margin: 0 0 12px;">
        <span class="appAvatar appAvatarMd" aria-hidden="true">
          <?php if ($profilePhotoUrl !== ""): ?>
            <img src="<?= htmlspecialchars($profilePhotoUrl, ENT_QUOTES, "UTF-8") ?>" alt="">
          <?php else: ?>
            <span><?= htmlspecialchars($profileInitials) ?></span>
          <?php endif; ?>
        </span>
        <span class="appUserSummaryText">
          <span class="appUserSummaryKicker">Signed in as</span>
          <b><?= htmlspecialchars($fullName) ?></b>
          <?php if ($username !== ""): ?>
            <span class="appUserSummaryMuted">@<?= htmlspecialchars($username) ?></span>
          <?php endif; ?>
          <?php if ($officialTitle !== ""): ?>
            <span class="appUserSummaryMuted"><?= htmlspecialchars($officialTitle) ?></span>
          <?php endif; ?>
          <?php if ($orgLine !== ""): ?>
            <span class="appUserSummaryMuted"><?= htmlspecialchars($orgLine) ?></span>
          <?php endif; ?>
        </span>
      </div>
    <?php endif; ?>
