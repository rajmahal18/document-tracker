<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

$pageTitle = "Organizational Chart - Document Tracker";

$hasOfficialTitle = db_column_exists($conn, "users", "official_title");
$hasAuthorityRole = db_column_exists($conn, "users", "authority_role");
$hasLastSeenAt = db_column_exists($conn, "users", "last_seen_at");

$viewerDivisionId = (int)($_SESSION["division_id"] ?? 0);
$nowTs = time();
$onlineWindow = 120;

$authorityWeight = [
  "director" => 10,
  "division_head" => 20,
  "division_assistant" => 30,
  "section_head" => 40,
  "staff" => 50,
  "admin" => 60,
];

function resolve_authority_role(array $row): string {
  $role = trim((string)($row["authority_role"] ?? ""));
  if ($role !== "") {
    return $role;
  }

  if ((string)($row["role"] ?? "") === "admin") {
    return "admin";
  }

  if ((int)($row["is_chief"] ?? 0) === 1) {
    return "section_head";
  }

  return "staff";
}

function resolve_display_title(array $row, string $authorityRole): string {
  $title = trim((string)($row["official_title"] ?? ""));
  if ($title !== "") {
    return $title;
  }

  return match ($authorityRole) {
    "director" => "Director",
    "division_head" => "Division Chief",
    "division_assistant" => "Assistant Division Chief",
    "section_head" => "Section Chief",
    "admin" => "Administrator",
    default => "Staff",
  };
}

function section_sort_weight(string $name): int {
  $normalized = strtolower(trim($name));
  if ($normalized === 'director office') {
    return 5;
  }
  if (str_contains($normalized, 'office of the division chief')) {
    return 10;
  }
  if (str_contains($normalized, 'office of the director')) {
    return 15;
  }
  return 50;
}

function is_leadership_role(string $authorityRole): bool {
  return in_array($authorityRole, ['director', 'division_head', 'division_assistant', 'section_head'], true);
}

function role_badge_label(string $authorityRole): string {
  return match ($authorityRole) {
    'director' => 'Director',
    'division_head' => 'Division Head',
    'division_assistant' => 'Division Assistant',
    'section_head' => 'Section Head',
    'admin' => 'Admin',
    default => 'Staff',
  };
}

function user_initials(string $name): string {
  $parts = preg_split('/\s+/', trim($name)) ?: [];
  $initials = '';
  foreach ($parts as $part) {
    $clean = trim($part, ".,-");
    if ($clean === '') continue;
    $initials .= mb_strtoupper(mb_substr($clean, 0, 1));
    if (mb_strlen($initials) >= 2) break;
  }
  return $initials !== '' ? $initials : 'U';
}

function division_kicker(string $divisionName): string {
  $n = strtolower($divisionName);
  if (str_contains($n, 'director office')) return 'Top Office';
  if (str_contains($n, 'planning') && str_contains($n, 'programming')) return 'Planning + Programming';
  if (str_contains($n, 'survey') && str_contains($n, 'design')) return 'Survey + Design';
  if (str_contains($n, 'special project')) return 'Special Projects';
  return 'Division';
}

function search_blob(string ...$values): string {
  $joined = implode(' ', array_map(static fn(string $value): string => strtolower(trim($value)), $values));
  return preg_replace('/\s+/', ' ', $joined) ?? '';
}

function render_org_user_card(array $user, bool $leader = false): string {
  $classes = 'orgUserCard' . ($leader ? ' isLeader' : '');
  $presence = '';
  if (!empty($user['show_presence'])) {
    $presenceClass = !empty($user['is_online']) ? 'presencePill isOnline' : 'presencePill';
    $presenceText = !empty($user['is_online']) ? 'Online now' : 'Offline';
    $presence = '<span class="' . htmlspecialchars($presenceClass) . '"><span class="presenceDot"></span>' . htmlspecialchars($presenceText) . '</span>';
  }

  $search = search_blob(
    (string)($user['full_name'] ?? ''),
    (string)($user['display_title'] ?? ''),
    (string)($user['section_name'] ?? ''),
    (string)($user['authority_role'] ?? '')
  );

  return '<article class="' . htmlspecialchars($classes) . '" data-search="' . htmlspecialchars($search) . '">' .
    '<div class="orgUserCore">' .
      '<div class="orgUserAvatar role-' . htmlspecialchars((string)$user['authority_role']) . '" aria-hidden="true">' . htmlspecialchars(user_initials((string)$user['full_name'])) . '</div>' .
      '<div class="orgUserContent">' .
        '<div class="orgUserTopline">' .
          '<h5 class="orgUserName">' . htmlspecialchars((string)$user['full_name']) . '</h5>' .
          '<span class="orgRoleBadge role-' . htmlspecialchars((string)$user['authority_role']) . '">' . htmlspecialchars(role_badge_label((string)$user['authority_role'])) . '</span>' .
        '</div>' .
        '<p class="orgUserMeta">' . htmlspecialchars((string)$user['display_title']) . '</p>' .
        '<p class="orgUserSection">' . htmlspecialchars((string)$user['section_name']) . '</p>' .
      '</div>' .
    '</div>' .
    $presence .
  '</article>';
}

$divisions = [];
$divRes = $conn->query("\n  SELECT id, name\n  FROM divisions\n  WHERE is_active = 1\n  ORDER BY id ASC, name ASC\n");
if ($divRes) {
  while ($row = $divRes->fetch_assoc()) {
    $divisionId = (int)($row["id"] ?? 0);
    $divisionName = trim((string)($row["name"] ?? ""));
    $divisions[$divisionId] = [
      "id" => $divisionId,
      "name" => $divisionName,
      "sections" => [],
      "sort_weight" => str_contains(strtolower($divisionName), 'director office') ? 0 : 100,
    ];
  }
}

$secRes = $conn->query("\n  SELECT id, division_id, name\n  FROM sections\n  WHERE is_active = 1\n  ORDER BY division_id ASC, id ASC, name ASC\n");
if ($secRes) {
  while ($row = $secRes->fetch_assoc()) {
    $divisionId = (int)($row["division_id"] ?? 0);
    $sectionId = (int)($row["id"] ?? 0);
    if (!isset($divisions[$divisionId])) {
      continue;
    }
    $sectionName = trim((string)($row["name"] ?? ""));
    $divisions[$divisionId]["sections"][$sectionId] = [
      "id" => $sectionId,
      "name" => $sectionName,
      "users" => [],
      "sort_weight" => section_sort_weight($sectionName),
      "is_chief_office" => str_contains(strtolower($sectionName), 'office of the division chief')
        || str_contains(strtolower($sectionName), 'director office')
        || str_contains(strtolower($sectionName), 'office of the director'),
    ];
  }
}

$userSql = "
  SELECT
    u.id,
    u.full_name,
    u.role,
    u.section_id,
    u.is_chief,
    " . ($hasOfficialTitle ? "u.official_title" : "NULL") . " AS official_title,
    " . ($hasAuthorityRole ? "u.authority_role" : "NULL") . " AS authority_role,
    " . ($hasLastSeenAt ? "u.last_seen_at" : "NULL") . " AS last_seen_at,
    s.name AS section_name,
    s.id AS resolved_section_id,
    d.id AS division_id,
    d.name AS division_name
  FROM users u
  JOIN sections s ON s.id = u.section_id
  JOIN divisions d ON d.id = s.division_id
  WHERE u.is_active = 1
    AND s.is_active = 1
    AND d.is_active = 1
  ORDER BY d.id ASC, s.id ASC, u.full_name ASC
";
$userRes = $conn->query($userSql);

if ($userRes) {
  while ($row = $userRes->fetch_assoc()) {
    $divisionId = (int)($row["division_id"] ?? 0);
    $sectionId = (int)($row["resolved_section_id"] ?? 0);
    if (!isset($divisions[$divisionId]["sections"][$sectionId])) {
      continue;
    }

    $authorityRole = resolve_authority_role($row);
    $displayTitle = resolve_display_title($row, $authorityRole);

    $lastSeenAt = trim((string)($row["last_seen_at"] ?? ""));
    $isOnline = false;
    if ($hasLastSeenAt && $viewerDivisionId > 0 && $viewerDivisionId === $divisionId && $lastSeenAt !== "") {
      $lastSeenTs = strtotime($lastSeenAt);
      $isOnline = ($lastSeenTs !== false) && (($nowTs - $lastSeenTs) <= $onlineWindow);
    }

    $divisions[$divisionId]["sections"][$sectionId]["users"][] = [
      "id" => (int)($row["id"] ?? 0),
      "full_name" => (string)($row["full_name"] ?? ""),
      "authority_role" => $authorityRole,
      "authority_weight" => $authorityWeight[$authorityRole] ?? 99,
      "display_title" => $displayTitle,
      "section_name" => (string)($row["section_name"] ?? ""),
      "is_online" => $isOnline,
      "show_presence" => ($viewerDivisionId > 0 && $viewerDivisionId === $divisionId),
      "is_leader" => is_leadership_role($authorityRole),
    ];
  }
}

foreach ($divisions as &$division) {
  foreach ($division["sections"] as &$section) {
    usort($section["users"], static function (array $a, array $b): int {
      if ($a["authority_weight"] !== $b["authority_weight"]) {
        return $a["authority_weight"] <=> $b["authority_weight"];
      }
      return strcasecmp($a["full_name"], $b["full_name"]);
    });

    $section["leaders"] = array_values(array_filter($section["users"], static fn(array $user): bool => $user["is_leader"]));
    $section["members"] = array_values(array_filter($section["users"], static fn(array $user): bool => !$user["is_leader"]));
    $section["member_count"] = count($section["members"]);
  }
  unset($section);

  uasort($division["sections"], static function (array $a, array $b): int {
    if ($a["sort_weight"] !== $b["sort_weight"]) {
      return $a["sort_weight"] <=> $b["sort_weight"];
    }
    return strcasecmp($a["name"], $b["name"]);
  });

  $division["section_count"] = count($division["sections"]);
  $division["user_count"] = array_reduce(
    $division["sections"],
    static fn(int $carry, array $section): int => $carry + count($section["users"]),
    0
  );

  $division["chief_office"] = null;
  $division["child_sections"] = [];
  foreach ($division["sections"] as $section) {
    if ($division["chief_office"] === null && $section["is_chief_office"]) {
      $division["chief_office"] = $section;
      continue;
    }
    $division["child_sections"][] = $section;
  }

  if ($division["chief_office"] === null && !empty($division["sections"])) {
    $firstSection = reset($division["sections"]);
    $division["chief_office"] = $firstSection;
    $division["child_sections"] = array_values(array_slice($division["sections"], 1));
  }
}
unset($division);

uasort($divisions, static function (array $a, array $b): int {
  if ($a["sort_weight"] !== $b["sort_weight"]) {
    return $a["sort_weight"] <=> $b["sort_weight"];
  }
  return strcasecmp($a["name"], $b["name"]);
});

$rootDivision = null;
$childDivisions = [];
foreach ($divisions as $division) {
  if ($rootDivision === null && $division["sort_weight"] === 0) {
    $rootDivision = $division;
    continue;
  }
  $childDivisions[] = $division;
}
if ($rootDivision === null && !empty($divisions)) {
  $rootDivision = reset($divisions);
  $childDivisions = array_values(array_slice($divisions, 1));
}

require __DIR__ . "/../includes/layout.php";
?>





<div class="orgArt2026" id="orgAtlas2026">
  <section class="orgHeroShell">
    <div class="orgHeroGrid">
      <div>
        <p class="orgHeroEyebrow">2026 Org Atlas</p>
        <h2 class="orgHeroTitle">Technical Services, refined.</h2>
        <p class="orgHeroSub">Hierarchy first. Search fast. Explore the ministry structure without the visual noise.</p>
        <div class="orgHeroRail">
          <span class="orgHeroNote"><span class="orgHeroDot"></span>Director office first</span>
          <span class="orgHeroNote"><span class="orgHeroDot"></span>Fast search</span>
          <span class="orgHeroNote"><span class="orgHeroDot"></span>Members tucked away</span>
        </div>
      </div>

      <aside class="orgControlDeck">
        <div class="orgSearchWrap">
          <label class="orgSearchLabel" for="orgSearchInput">Search</label>
          <div class="orgSearchBox">
            <span class="orgSearchIcon" aria-hidden="true">⌕</span>
            <input id="orgSearchInput" class="orgSearchInput" type="search" placeholder="Search division, section, person, or title..." autocomplete="off">
          </div>
          
        </div>

        <div class="orgActionRow">
          <button type="button" class="orgActionBtn isPrimary" id="orgExpandAllBtn">Expand all members</button>
          <button type="button" class="orgActionBtn" id="orgCollapseAllBtn">Collapse all</button>
          <button type="button" class="orgActionBtn" id="orgResetFilterBtn">Reset view</button>
        </div>

        <div class="orgHeroStats">
          <div class="orgHeroStat">
            <p class="orgHeroStatValue"><?= max(0, count($divisions) - 1) ?></p>
            <p class="orgHeroStatLabel">Active divisions</p>
          </div>
          <div class="orgHeroStat">
            <p class="orgHeroStatValue"><?= array_reduce($divisions, static fn(int $carry, array $division): int => $carry + (int)$division['user_count'], 0) ?></p>
            <p class="orgHeroStatLabel">Active users</p>
          </div>
          <div class="orgHeroStat">
            <p class="orgHeroStatValue"><?= array_reduce($divisions, static fn(int $carry, array $division): int => $carry + (int)$division['section_count'], 0) ?></p>
            <p class="orgHeroStatLabel">Total sections</p>
          </div>
        </div>
      </aside>
    </div>
  </section>

  <?php if ($rootDivision): ?>

    <?php
      $spotlightDivision = $viewerDivisionId > 0 && isset($divisions[$viewerDivisionId]) ? $divisions[$viewerDivisionId] : $rootDivision;
      $spotlightIsViewerDivision = $viewerDivisionId > 0 && isset($divisions[$viewerDivisionId]);
    ?>
    <section class="orgSpotlight">
      <div class="orgSpotlightGrid">
        <div>
          <p class="orgMiniLabel"><?= $spotlightIsViewerDivision ? "Your division" : "Spotlight" ?></p>
          <h3 class="orgSpotlightTitle"><?= htmlspecialchars($spotlightDivision['name']) ?></h3>
</div>
        <div class="orgCounters">
          <div class="orgCounter">
            <p class="orgCounterValue"><?= (int)$spotlightDivision['user_count'] ?></p>
            <p class="orgCounterLabel">People</p>
          </div>
          <div class="orgCounter">
            <p class="orgCounterValue"><?= (int)$spotlightDivision['section_count'] ?></p>
            <p class="orgCounterLabel">Sections</p>
          </div>
          <div class="orgCounter">
            <p class="orgCounterValue"><?= (int)count($spotlightDivision['chief_office']['leaders'] ?? []) ?></p>
            <p class="orgCounterLabel">Leaders visible</p>
          </div>
        </div>
      </div>
    </section>

    <section class="orgStage">
      <div class="orgStageGlow"></div>
      <div class="orgDrillBar" id="orgDrillBar" aria-live="polite">
        <button type="button" class="orgDrillBack" id="orgDrillBackBtn">
          <span class="orgDrillBackArrow">←</span>
          <span class="orgDrillBackLabel">Back to</span>
          <span class="orgDrillBackDivName" id="orgDrillBackDivName">All divisions</span>
        </button>
      </div>
      <div class="orgRootShell orgSearchable" id="org-root-card" data-search="<?= htmlspecialchars(search_blob($rootDivision['name'], $rootDivision['chief_office']['name'] ?? '')) ?>">
        <div class="orgRootTop">
          <div>
            <p class="orgKicker"><?= htmlspecialchars(division_kicker($rootDivision['name'])) ?></p>
            <h2 class="orgRootTitle"><?= htmlspecialchars($rootDivision['name']) ?></h2>
</div>
          <div class="orgCounters">
            <div class="orgCounter">
              <p class="orgCounterValue"><?= (int)$rootDivision['user_count'] ?></p>
              <p class="orgCounterLabel">Users here</p>
            </div>
            <div class="orgCounter">
              <p class="orgCounterValue"><?= count($childDivisions) ?></p>
              <p class="orgCounterLabel">Divisions below</p>
            </div>
          </div>
        </div>

        <?php if ($rootDivision['chief_office']): ?>
          <div class="orgSectionsFlow">
            <article class="orgChiefSection orgSearchable" data-search="<?= htmlspecialchars(search_blob($rootDivision['chief_office']['name'], $rootDivision['name'])) ?>">
              <div class="orgSectionHeader">
                <div>
                  <p class="orgSectionLabel">Top office</p>
                  <h3 class="orgSectionTitle"><?= htmlspecialchars($rootDivision['chief_office']['name']) ?></h3>
</div>
                <span class="orgMiniCount"><?= count($rootDivision['chief_office']['users']) ?></span>
              </div>

              <?php if (!$rootDivision['chief_office']['leaders'] && !$rootDivision['chief_office']['members']): ?>
                <div class="orgUserEmpty">No active users yet.</div>
              <?php else: ?>
                <div class="orgLeaderList">
                  <?php foreach ($rootDivision['chief_office']['leaders'] as $user): ?>
                    <?= render_org_user_card($user, true) ?>
                  <?php endforeach; ?>
                </div>

                <?php if ($rootDivision['chief_office']['member_count'] > 0): ?>
                  <?php $rootDrawerId = 'org-members-root-' . (int)$rootDivision['chief_office']['id']; ?>
                  <div class="orgSectionActions">
                    <span class="orgMemberCount"><?= (int)$rootDivision['chief_office']['member_count'] ?> additional members</span>
                    <button type="button" class="orgMemberToggle" data-target="<?= htmlspecialchars($rootDrawerId) ?>" aria-expanded="false">
                      See all members
                    </button>
                  </div>
                  <div class="orgMemberDrawer" id="<?= htmlspecialchars($rootDrawerId) ?>" hidden>
                    <div class="orgMemberList">
                      <?php foreach ($rootDivision['chief_office']['members'] as $user): ?>
                        <?= render_org_user_card($user) ?>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </article>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($childDivisions): ?>
        <div class="orgTreeConnector"></div>
        <div class="orgDivisionGrid" id="orgDivisionGrid">
          <?php foreach ($childDivisions as $division): ?>
            <?php
              $divisionSearch = search_blob($division['name']);
              foreach ($division['sections'] as $scanSection) {
                $divisionSearch .= ' ' . search_blob($scanSection['name']);
                foreach ($scanSection['users'] as $scanUser) {
                  $divisionSearch .= ' ' . search_blob((string)$scanUser['full_name'], (string)$scanUser['display_title'], (string)$scanUser['authority_role']);
                }
              }
            ?>
            <section class="orgDivisionCard orgSearchable"
              id="org-division-<?= (int)$division['id'] ?>"
              data-search="<?= htmlspecialchars(trim($divisionSearch)) ?>"
              data-division-id="<?= (int)$division['id'] ?>"
              data-division-name="<?= htmlspecialchars($division['name']) ?>">
              <div class="orgDivisionHead">
                <div>
                  <p class="orgKicker"><?= htmlspecialchars(division_kicker($division['name'])) ?></p>
                  <h3 class="orgDivisionName"><?= htmlspecialchars($division['name']) ?></h3>
                  <div class="orgDivisionMetaWrap">
                    <span class="orgDivisionMetaPill"><?= (int)$division['section_count'] ?> sections</span>
                    <span class="orgDivisionMetaPill"><?= (int)$division['user_count'] ?> people</span>
                    <?php if ($viewerDivisionId === (int)$division['id']): ?>
                      <span class="orgDivisionMetaPill">Your lane</span>
                    <?php endif; ?>
                  </div>
                </div>
                <span class="orgMiniCount"><?= (int)$division['user_count'] ?></span>
              </div>

              <button type="button" class="orgDrillHint" data-drill="<?= (int)$division['id'] ?>">
                <span class="orgDrillHintDot"></span>
                Focus this division
              </button>

              <div class="orgDivisionMetrics">
                <div class="orgDivisionMetric">
                  <p class="orgDivisionMetricValue"><?= (int)$division['section_count'] ?></p>
                  <p class="orgDivisionMetricLabel">Sections</p>
                </div>
                <div class="orgDivisionMetric">
                  <p class="orgDivisionMetricValue"><?= (int)$division['user_count'] ?></p>
                  <p class="orgDivisionMetricLabel">People</p>
                </div>
                <div class="orgDivisionMetric">
                  <p class="orgDivisionMetricValue"><?= (int)count($division['chief_office']['leaders'] ?? []) ?></p>
                  <p class="orgDivisionMetricLabel">Visible leaders</p>
                </div>
              </div>

              <div class="orgSectionsFlow">
                <?php if ($division['chief_office']): ?>
                  <article class="orgChiefSection orgSearchable" data-search="<?= htmlspecialchars(search_blob($division['name'], $division['chief_office']['name'])) ?>">
                    <div class="orgSectionHeader">
                      <div>
                        <p class="orgSectionLabel">Chief office</p>
                        <h4 class="orgSectionTitle"><?= htmlspecialchars($division['chief_office']['name']) ?></h4>
</div>
                      <span class="orgMiniCount"><?= count($division['chief_office']['users']) ?></span>
                    </div>

                    <?php if (!$division['chief_office']['leaders'] && !$division['chief_office']['members']): ?>
                      <div class="orgUserEmpty">No active users yet.</div>
                    <?php else: ?>
                      <div class="orgLeaderList">
                        <?php foreach ($division['chief_office']['leaders'] as $user): ?>
                          <?= render_org_user_card($user, true) ?>
                        <?php endforeach; ?>
                      </div>

                      <?php if ($division['chief_office']['member_count'] > 0): ?>
                        <?php $chiefDrawerId = 'org-members-chief-' . (int)$division['chief_office']['id']; ?>
                        <div class="orgSectionActions">
                          <span class="orgMemberCount"><?= (int)$division['chief_office']['member_count'] ?> additional members</span>
                          <button type="button" class="orgMemberToggle" data-target="<?= htmlspecialchars($chiefDrawerId) ?>" aria-expanded="false">
                            See all members
                          </button>
                        </div>
                        <div class="orgMemberDrawer" id="<?= htmlspecialchars($chiefDrawerId) ?>" hidden>
                          <div class="orgMemberList">
                            <?php foreach ($division['chief_office']['members'] as $user): ?>
                              <?= render_org_user_card($user) ?>
                            <?php endforeach; ?>
                          </div>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </article>
                <?php endif; ?>

                <?php foreach ($division['child_sections'] as $section): ?>
                  <article class="orgSectionCard orgSearchable" data-search="<?= htmlspecialchars(search_blob($division['name'], $section['name'])) ?>">
                    <div class="orgSectionHeader">
                      <div>
                        <p class="orgSectionLabel">Section</p>
                        <h4 class="orgSectionTitle"><?= htmlspecialchars($section['name']) ?></h4>
</div>
                      <span class="orgMiniCount"><?= count($section['users']) ?></span>
                    </div>

                    <?php if (!$section['leaders'] && !$section['members']): ?>
                      <div class="orgUserEmpty">No active users yet.</div>
                    <?php else: ?>
                      <?php if ($section['leaders']): ?>
                        <div class="orgLeaderList">
                          <?php foreach ($section['leaders'] as $user): ?>
                            <?= render_org_user_card($user, true) ?>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>

                      <?php if (!$section['leaders'] && $section['members']): ?>
                        <div class="orgUserEmpty">No chief assigned yet.</div>
                      <?php endif; ?>

                      <?php if ($section['member_count'] > 0): ?>
                        <?php $drawerId = 'org-members-' . (int)$section['id']; ?>
                        <div class="orgSectionActions">
                          <span class="orgMemberCount"><?= (int)$section['member_count'] ?> members hidden by default</span>
                          <button type="button" class="orgMemberToggle" data-target="<?= htmlspecialchars($drawerId) ?>" aria-expanded="false">
                            See all members
                          </button>
                        </div>
                        <div class="orgMemberDrawer" id="<?= htmlspecialchars($drawerId) ?>" hidden>
                          <div class="orgMemberList">
                            <?php foreach ($section['members'] as $user): ?>
                              <?= render_org_user_card($user) ?>
                            <?php endforeach; ?>
                          </div>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </article>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endforeach; ?>
        </div>
        <div class="orgEmptyState" id="orgEmptyState">No matching result in this organizational chart.</div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>


<script src="<?= asset_url("assets/js/drill-down.js") ?>"></script>

<?php require __DIR__ . "/../includes/footer.php"; ?>
