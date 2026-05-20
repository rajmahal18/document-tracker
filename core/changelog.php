<?php
declare(strict_types=1);

function changelog_source_path(): string {
  return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'CHANGELOG.md';
}

function changelog_default_structure(): array {
  return [
    'title' => 'Changelog',
    'intro' => [],
    'releases' => [],
  ];
}

function changelog_load(): array {
  static $cache = null;
  if (is_array($cache)) {
    return $cache;
  }

  $path = changelog_source_path();
  if (!is_file($path)) {
    return $cache = changelog_default_structure();
  }

  $lines = file($path, FILE_IGNORE_NEW_LINES);
  if (!is_array($lines)) {
    return $cache = changelog_default_structure();
  }

  $data = changelog_default_structure();
  $currentRelease = null;
  $currentGroup = null;

  foreach ($lines as $line) {
    $rawLine = rtrim((string)$line, "\r\n");
    $trimmed = trim($rawLine);

    if ($trimmed === '') {
      continue;
    }

    if (preg_match('/^#\s+(.+)$/', $trimmed, $m)) {
      $data['title'] = trim($m[1]);
      continue;
    }

    if (preg_match('/^##\s+\[(.+?)\](?:\s*-\s*(.+))?$/', $trimmed, $m)) {
      $version = trim($m[1]);
      $date = trim((string)($m[2] ?? ''));
      $release = [
        'version' => $version,
        'date' => $date,
        'summary' => '',
        'anchor' => 'patch-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $version) ?? $version),
        'groups' => [
          'Added' => [],
          'Changed' => [],
          'Fixed' => [],
          'Removed' => [],
          'Affected Areas' => [],
          'Breaking Changes' => [],
        ],
      ];
      $data['releases'][] = $release;
      $currentRelease = count($data['releases']) - 1;
      $currentGroup = null;
      continue;
    }

    if (preg_match('/^Summary:\s*(.+)$/i', $trimmed, $m)) {
      if ($currentRelease === null) {
        $data['intro'][] = trim($m[1]);
      } else {
        $data['releases'][$currentRelease]['summary'] = trim($m[1]);
      }
      continue;
    }

    if (preg_match('/^###\s+(.+)$/', $trimmed, $m)) {
      $group = trim($m[1]);
      if ($currentRelease !== null && array_key_exists($group, $data['releases'][$currentRelease]['groups'])) {
        $currentGroup = $group;
      } else {
        $currentGroup = null;
      }
      continue;
    }

    if (preg_match('/^-\s+(.+)$/', $trimmed, $m)) {
      $item = trim($m[1]);
      if ($currentRelease !== null && $currentGroup !== null) {
        $data['releases'][$currentRelease]['groups'][$currentGroup][] = $item;
      } elseif ($currentRelease === null) {
        $data['intro'][] = $item;
      }
      continue;
    }
  }

  return $cache = $data;
}

function changelog_latest_release(): ?array {
  $data = changelog_load();
  foreach ($data['releases'] as $release) {
    if (strcasecmp((string)$release['version'], 'Unreleased') !== 0) {
      return $release;
    }
  }
  return null;
}

function changelog_release_version_label(): string {
  $release = changelog_latest_release();
  if (!$release) {
    return 'Unreleased';
  }
  return (string)($release['version'] ?: 'Unreleased');
}

