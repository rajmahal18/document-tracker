<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../core/changelog.php';
require_login();

$pageTitle = 'Changelogs - Document Tracker';
$changelog = changelog_load();
$allReleases = array_values(array_filter(
  $changelog['releases'] ?? [],
  static fn(array $release): bool => strcasecmp((string)($release['version'] ?? ''), 'Unreleased') !== 0
));

$latestRelease = $allReleases[0] ?? changelog_latest_release();
$selectedVersion = trim((string)($_GET['version'] ?? ($latestRelease['version'] ?? '')));
$selectedRelease = $latestRelease;

foreach ($allReleases as $release) {
  if (strcasecmp((string)($release['version'] ?? ''), $selectedVersion) === 0) {
    $selectedRelease = $release;
    break;
  }
}

function changelog_h(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function changelog_group_intro(string $group): string {
  return match ($group) {
    'Added' => 'New capabilities and user-facing additions in this patch.',
    'Changed' => 'Refinements to existing behavior, flow, or presentation.',
    'Fixed' => 'Behavior issues that were corrected in this patch.',
    'Removed' => 'Items or legacy clutter intentionally removed.',
    'Affected Areas' => 'Parts of the DTS experience directly touched by this patch.',
    'Breaking Changes' => 'Release notes that require extra attention before rollout.',
    default => '',
  };
}

function changelog_group_class(string $group): string {
  return match ($group) {
    'Added' => 'isAdded',
    'Changed' => 'isChanged',
    'Fixed' => 'isFixed',
    'Removed' => 'isRemoved',
    'Affected Areas' => 'isAreas',
    'Breaking Changes' => 'isBreaking',
    default => 'isNeutral',
  };
}

require __DIR__ . '/../includes/layout.php';
?>

<style>
  .changelogPage {
    display: grid;
    gap: 18px;
    max-width: 1120px;
    margin: 0 auto;
  }
  .patchHero {
    position: relative;
    overflow: hidden;
    border-radius: 26px;
    padding: 24px 24px 22px;
    border: 1px solid rgba(11, 58, 102, 0.12);
    background:
      radial-gradient(circle at top right, rgba(232, 119, 34, 0.18), transparent 28%),
      linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
    box-shadow: 0 18px 38px rgba(15, 23, 42, 0.07);
  }
  .patchHeroKicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px;
    border-radius: 999px;
    border: 1px solid rgba(232, 119, 34, 0.24);
    background: rgba(255, 247, 237, 0.94);
    color: #b86500;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
  }
  .patchHeroTitle {
    margin: 14px 0 6px;
    font-size: 34px;
    line-height: 1.02;
    font-weight: 900;
    color: #0f172a;
  }
  .patchHeroSub {
    max-width: 840px;
    color: #5b6b81;
    font-size: 14px;
    font-weight: 700;
    line-height: 1.6;
  }
  .patchMetaRow {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 16px;
  }
  .patchMetaChip {
    display: inline-flex;
    align-items: center;
    min-height: 34px;
    padding: 7px 12px;
    border-radius: 999px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background: rgba(255,255,255,0.84);
    color: #334155;
    font-size: 12px;
    font-weight: 800;
  }
  .patchLayout {
    display: grid;
    grid-template-columns: minmax(0, 260px) minmax(0, 1fr);
    gap: 18px;
    align-items: start;
  }
  .patchSidebar,
  .patchContent {
    border-radius: 22px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.98));
    box-shadow: 0 14px 32px rgba(15, 23, 42, 0.05);
  }
  .patchSidebar {
    padding: 18px;
    position: sticky;
    top: 14px;
    display: grid;
    gap: 18px;
  }
  .patchSidebarLabel {
    font-size: 11px;
    font-weight: 900;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: #1d4ed8;
    margin-bottom: 8px;
  }
  .patchVersionSelect {
    width: 100%;
    min-height: 44px;
    border-radius: 14px;
    border: 1px solid rgba(15, 23, 42, 0.12);
    background: #fff;
    color: #0f172a;
    font-size: 14px;
    font-weight: 800;
    padding: 0 14px;
    outline: none;
  }
  .patchVersionHint {
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.6;
  }
  .patchSectionNav {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    gap: 8px;
  }
  .patchSectionNav a {
    display: block;
    padding: 10px 12px;
    border-radius: 14px;
    color: #0f172a;
    font-weight: 800;
    text-decoration: none;
    background: rgba(248,250,252,0.92);
    border: 1px solid rgba(15, 23, 42, 0.08);
    transition: border-color .15s ease, background .15s ease, transform .15s ease;
  }
  .patchSectionNav a:hover {
    border-color: rgba(232, 119, 34, 0.26);
    background: rgba(255,247,237,0.9);
    transform: translateX(2px);
  }
  .patchContent {
    padding: 24px;
    display: grid;
    gap: 22px;
  }
  .patchOverview {
    display: grid;
    gap: 10px;
    padding-bottom: 18px;
    border-bottom: 1px solid rgba(15, 23, 42, 0.08);
  }
  .patchOverviewEyebrow {
    color: #1d4ed8;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: .14em;
    text-transform: uppercase;
  }
  .patchOverviewTitle {
    margin: 0;
    color: #0f172a;
    font-size: 30px;
    line-height: 1.05;
    font-weight: 900;
  }
  .patchOverviewSummary {
    margin: 0;
    color: #5b6b81;
    font-size: 14px;
    font-weight: 700;
    line-height: 1.7;
  }
  .patchGroups {
    display: grid;
    gap: 24px;
  }
  .patchGroup {
    display: grid;
    gap: 12px;
  }
  .patchGroupHead {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .patchGroupMarker {
    width: 12px;
    height: 12px;
    border-radius: 999px;
    flex: 0 0 auto;
    box-shadow: 0 0 0 5px rgba(148, 163, 184, 0.12);
  }
  .patchGroup.isAdded .patchGroupMarker {
    background: #16a34a;
    box-shadow: 0 0 0 5px rgba(22, 163, 74, 0.14);
  }
  .patchGroup.isChanged .patchGroupMarker {
    background: #2563eb;
    box-shadow: 0 0 0 5px rgba(37, 99, 235, 0.14);
  }
  .patchGroup.isFixed .patchGroupMarker {
    background: #d97706;
    box-shadow: 0 0 0 5px rgba(217, 119, 6, 0.14);
  }
  .patchGroup.isRemoved .patchGroupMarker {
    background: #dc2626;
    box-shadow: 0 0 0 5px rgba(220, 38, 38, 0.14);
  }
  .patchGroup.isAreas .patchGroupMarker {
    background: #7c3aed;
    box-shadow: 0 0 0 5px rgba(124, 58, 237, 0.14);
  }
  .patchGroup.isBreaking .patchGroupMarker {
    background: #b91c1c;
    box-shadow: 0 0 0 5px rgba(185, 28, 28, 0.16);
  }
  .patchGroupTitleWrap {
    min-width: 0;
  }
  .patchGroupTitle {
    margin: 0;
    color: #0f172a;
    font-size: 20px;
    font-weight: 900;
    line-height: 1.2;
  }
  .patchGroupIntro {
    margin: 3px 0 0;
    color: #64748b;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.6;
  }
  .patchList {
    list-style: none;
    padding: 0 0 0 24px;
    margin: 0;
    display: grid;
    gap: 10px;
  }
  .patchList li {
    position: relative;
    color: #334155;
    font-size: 14px;
    line-height: 1.7;
  }
  .patchList li::before {
    content: "";
    position: absolute;
    left: -18px;
    top: 10px;
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: rgba(148, 163, 184, 0.9);
  }
  .patchGroup.isAdded .patchList li::before { background: #16a34a; }
  .patchGroup.isChanged .patchList li::before { background: #2563eb; }
  .patchGroup.isFixed .patchList li::before { background: #d97706; }
  .patchGroup.isRemoved .patchList li::before { background: #dc2626; }
  .patchGroup.isAreas .patchList li::before { background: #7c3aed; }
  .patchGroup.isBreaking .patchList li::before { background: #b91c1c; }
  .patchEmptyState {
    color: #64748b;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.6;
  }
  @media (max-width: 980px) {
    .patchLayout {
      grid-template-columns: 1fr;
    }
    .patchSidebar {
      position: static;
    }
    .patchHeroTitle,
    .patchOverviewTitle {
      font-size: 28px;
    }
  }
  body[data-theme="dark"] .patchHero {
    border-color: rgba(160, 181, 205, 0.18);
    background:
      radial-gradient(circle at top right, rgba(242, 168, 95, 0.16), transparent 28%),
      linear-gradient(180deg, #112131 0%, #0b1826 100%);
    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.24);
  }
  body[data-theme="dark"] .patchSidebar,
  body[data-theme="dark"] .patchContent,
  body[data-theme="dark"] .patchVersionSelect,
  body[data-theme="dark"] .patchSectionNav a {
    border-color: rgba(160, 181, 205, 0.18);
    background: rgba(14, 28, 43, 0.88);
  }
  body[data-theme="dark"] .patchHeroTitle,
  body[data-theme="dark"] .patchOverviewTitle,
  body[data-theme="dark"] .patchGroupTitle,
  body[data-theme="dark"] .patchVersionSelect,
  body[data-theme="dark"] .patchSectionNav a {
    color: #eef5ff;
  }
  body[data-theme="dark"] .patchHeroSub,
  body[data-theme="dark"] .patchOverviewSummary,
  body[data-theme="dark"] .patchGroupIntro,
  body[data-theme="dark"] .patchVersionHint,
  body[data-theme="dark"] .patchList li,
  body[data-theme="dark"] .patchEmptyState {
    color: #a9bbcf;
  }
  body[data-theme="dark"] .patchMetaChip {
    border-color: rgba(160, 181, 205, 0.18);
    background: rgba(14, 28, 43, 0.88);
    color: #d9e4f2;
  }
  body[data-theme="dark"] .patchOverview {
    border-bottom-color: rgba(160, 181, 205, 0.14);
  }
  body[data-theme="dark"] .patchSidebarLabel,
  body[data-theme="dark"] .patchOverviewEyebrow {
    color: #7db7ff;
  }
  body[data-theme="dark"] .patchSectionNav a:hover {
    border-color: rgba(242, 168, 95, 0.32);
    background: rgba(42, 31, 18, 0.9);
  }
</style>

<div class="changelogPage">
  <section class="patchHero">
    <div class="patchHeroKicker">Patch Notes</div>
    <h2 class="patchHeroTitle">
      <?= changelog_h((string)($selectedRelease['version'] ?? 'Changelog')) ?><?= ($selectedRelease['date'] ?? '') !== '' ? ' - ' . changelog_h((string)$selectedRelease['date']) : '' ?>
    </h2>
    <p class="patchHeroSub">
      <?= changelog_h((string)($selectedRelease['summary'] ?? 'Browse released DTS updates from a cleaner versioned view.')) ?>
    </p>
    <div class="patchMetaRow">
      <span class="patchMetaChip">Source: CHANGELOG.md</span>
      <span class="patchMetaChip">Latest release: <?= changelog_h(changelog_release_version_label()) ?></span>
      <span class="patchMetaChip">Visible versions: <?= count($allReleases) ?></span>
    </div>
  </section>

  <div class="patchLayout">
    <aside class="patchSidebar">
      <div>
        <div class="patchSidebarLabel">Versions</div>
        <form method="get" action="<?= PUBLIC_PATH ?>/changelogs.php">
          <select name="version" class="patchVersionSelect" onchange="this.form.submit()">
            <?php foreach ($allReleases as $release): ?>
              <option value="<?= changelog_h((string)$release['version']) ?>" <?= strcasecmp((string)($release['version'] ?? ''), (string)($selectedRelease['version'] ?? '')) === 0 ? 'selected' : '' ?>>
                <?= changelog_h((string)$release['version']) ?><?= ($release['date'] ?? '') !== '' ? ' - ' . changelog_h((string)$release['date']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
        <div class="patchVersionHint" style="margin-top:10px;">
          Only released patches are shown here. Unreleased work stays internal until a version is explicitly promoted.
        </div>
      </div>

      <div>
        <div class="patchSidebarLabel">This Patch</div>
        <ul class="patchSectionNav">
          <?php foreach (($selectedRelease['groups'] ?? []) as $groupName => $items): ?>
            <?php
              $items = is_array($items) ? array_values(array_filter($items, static fn($v): bool => trim((string)$v) !== '')) : [];
              if (!$items) {
                continue;
              }
              $anchor = 'group-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$groupName) ?? (string)$groupName);
            ?>
            <li><a href="#<?= changelog_h($anchor) ?>"><?= changelog_h((string)$groupName) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </aside>

    <section class="patchContent">
      <div class="patchOverview">
        <div class="patchOverviewEyebrow">Selected Patch</div>
        <h3 class="patchOverviewTitle">
          <?= changelog_h((string)($selectedRelease['version'] ?? '')) ?><?= ($selectedRelease['date'] ?? '') !== '' ? ' - ' . changelog_h((string)$selectedRelease['date']) : '' ?>
        </h3>
        <p class="patchOverviewSummary"><?= changelog_h((string)($selectedRelease['summary'] ?? '')) ?></p>
      </div>

      <div class="patchGroups">
        <?php foreach (($selectedRelease['groups'] ?? []) as $groupName => $items): ?>
          <?php
            $items = is_array($items) ? array_values(array_filter($items, static fn($v): bool => trim((string)$v) !== '')) : [];
            if (!$items) {
              continue;
            }
            $groupClass = changelog_group_class((string)$groupName);
            $anchor = 'group-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$groupName) ?? (string)$groupName);
          ?>
          <section class="patchGroup <?= changelog_h($groupClass) ?>" id="<?= changelog_h($anchor) ?>">
            <div class="patchGroupHead">
              <span class="patchGroupMarker" aria-hidden="true"></span>
              <div class="patchGroupTitleWrap">
                <h4 class="patchGroupTitle"><?= changelog_h((string)$groupName) ?></h4>
                <?php $intro = changelog_group_intro((string)$groupName); ?>
                <?php if ($intro !== ''): ?>
                  <p class="patchGroupIntro"><?= changelog_h($intro) ?></p>
                <?php endif; ?>
              </div>
            </div>
            <ul class="patchList">
              <?php foreach ($items as $item): ?>
                <li><?= changelog_h((string)$item) ?></li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endforeach; ?>

        <?php if (!$selectedRelease): ?>
          <div class="patchEmptyState">No released patches are available yet.</div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
