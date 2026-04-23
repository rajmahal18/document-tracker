<?php
/**
 * Example only.
 *
 * Put this near the bottom of public/org_chart.php, before loading the built JS bundle.
 * The goal is to convert your already-shaped PHP arrays into a clean bootstrap object.
 */
?>
<script>
window.__ORG_CHART_BOOTSTRAP__ = <?= json_encode([
  'rootDivision' => $rootDivision,
  'childDivisions' => $childDivisions,
  'spotlightDivision' => $spotlightDivision ?? $rootDivision,
  'divisions' => array_values($divisions),
  'assignableRoles' => $assignableRoles,
  'canManageOrg' => $canManageOrg,
  'viewerDivisionId' => $viewerDivisionId,
  'stats' => [
    'activeDivisions' => max(0, count($divisions) - 1),
    'activeUsers' => array_reduce($divisions, static fn(int $carry, array $division): int => $carry + (int)$division['user_count'], 0),
    'totalSections' => array_reduce($divisions, static fn(int $carry, array $division): int => $carry + (int)$division['section_count'], 0),
  ],
  'copy' => [
    'eyebrow' => '2026 Org Atlas',
    'title' => 'Technical Services, refined.',
    'subtitle' => 'Delivering precision in public works',
  ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
