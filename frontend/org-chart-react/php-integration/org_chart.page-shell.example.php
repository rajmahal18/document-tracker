<?php
/**
 * Example only.
 *
 * 1. Keep your existing require_login(), permissions, and query logic.
 * 2. Keep window.__APP__ from includes/layout.php.
 * 3. Insert the React root where the org chart should render.
 * 4. Load the built CSS and JS from the copied dist folder.
 */
?>
<div id="root"></div>
<link rel="stylesheet" href="<?= asset_url('public/org-chart-react/assets/index.css') ?>">
<script type="module" src="<?= asset_url('public/org-chart-react/assets/index.js') ?>"></script>
