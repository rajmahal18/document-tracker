<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../core/project_codes.php';
require_once __DIR__ . '/../core/document_split.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

require_login();
require_csrf();

$identity = effective_document_identity($conn);
$userId = (int)($identity['effective_user_id'] ?? 0);
$sectionId = (int)($identity['effective_section_id'] ?? 0);
$isChief = (bool)($identity['effective_is_chief'] ?? false);
$isAdmin = is_admin_user() && !(bool)($identity['assistant_mode'] ?? false);

$documentId = (int)($_POST['document_id'] ?? 0);
$projectIdsRaw = $_POST['project_ids'] ?? [];
$projectIds = is_array($projectIdsRaw)
  ? array_map('intval', $projectIdsRaw)
  : array_map('intval', preg_split('/\s*,\s*/', (string)$projectIdsRaw) ?: []);
$splitMode = strtolower(trim((string)($_POST['split_mode'] ?? '')));
$projectGroups = null;

if ($splitMode === 'grouped') {
  $groupedIds = array_values(array_unique(array_filter($projectIds, static fn(int $value): bool => $value > 0)));
  $projectGroups = $groupedIds !== [] ? [$groupedIds] : [];
} elseif ($splitMode === 'custom') {
  $groupKeysRaw = $_POST['project_group_keys'] ?? [];
  $groupKeys = is_array($groupKeysRaw) ? $groupKeysRaw : [];
  $buckets = [];

  foreach ($projectIds as $projectId) {
    $projectId = (int)$projectId;
    if ($projectId <= 0) {
      continue;
    }

    $rawKey = trim((string)($groupKeys[(string)$projectId] ?? $groupKeys[$projectId] ?? '1'));
    $key = preg_replace('/[^a-zA-Z0-9_-]+/', '', $rawKey) ?: '1';
    $buckets[$key][] = $projectId;
  }

  uksort($buckets, 'strnatcasecmp');
  $projectGroups = array_values($buckets);
} elseif ($splitMode === 'separate') {
  $projectGroups = null;
}

if ($documentId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid document.']);
  exit;
}

if (!can_view_document($conn, $documentId)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Access denied.']);
  exit;
}

if (!document_split_can_create_children($conn, $documentId, $userId, $sectionId, $isChief, $isAdmin)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'You are not allowed to split projects from this document.']);
  exit;
}

try {
  $conn->begin_transaction();
  $created = document_split_create_children($conn, $documentId, $projectIds, $userId, $sectionId, $projectGroups);
  $conn->commit();

  foreach ($created as &$childRow) {
    $childId = (int)($childRow['id'] ?? 0);
    if ($childId <= 0) {
      continue;
    }
    $qs = ['edit_id=' . rawurlencode((string)$childId), 'mode=child_setup'];
    if (!empty($identity['assistant_mode']) && (int)($identity['acting_principal_user_id'] ?? 0) > 0) {
      $qs[] = 'acting_principal_user_id=' . rawurlencode((string)((int)$identity['acting_principal_user_id']));
    }
    $childRow['setup_url'] = PUBLIC_PATH . '/add_document.php?' . implode('&', $qs);
  }
  unset($childRow);

  echo json_encode([
    'ok' => true,
    'message' => count($created) === 1 ? 'Child document created.' : 'Child documents created.',
    'created' => $created,
  ]);
} catch (Throwable $e) {
  try {
    if (@$conn->ping()) {
      $conn->rollback();
    }
  } catch (Throwable $rollbackError) {
  }

  http_response_code(422);
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
  ]);
}
