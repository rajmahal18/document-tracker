<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../core/project_codes.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

require_csrf();

if (!project_codes_tables_ready($conn)) {
  http_response_code(409);
  echo json_encode(['ok' => false, 'error' => 'Project masterlist is not ready yet.']);
  exit;
}

$docId = (int)($_POST['document_id'] ?? 0);
$projectId = (int)($_POST['project_id'] ?? 0);
$projectCodeRaw = trim((string)($_POST['project_code'] ?? ''));
$projectCode = normalize_project_code($projectCodeRaw);

if ($docId <= 0 || ($projectId <= 0 && $projectCode === '')) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Bad request']);
  exit;
}

if (!can_view_document($conn, $docId)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Forbidden']);
  exit;
}

$identity = effective_document_identity($conn);
$userId = (int)($identity['effective_user_id'] ?? 0);
$sectionId = (int)($identity['effective_section_id'] ?? 0);
$isChief = (bool)($identity['effective_is_chief'] ?? false);
$isAdmin = is_admin_user() && !(bool)($identity['assistant_mode'] ?? false);

if (!can_manage_document_projects_for_identity($conn, $docId, $userId, $sectionId, $isChief, $isAdmin)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Only the current holder or admin can edit project codes.']);
  exit;
}

try {
  $conn->begin_transaction();

  if ($projectId <= 0 && $projectCode !== '') {
    $resolved = resolve_project_ids_for_document($conn, [], [$projectCode]);
    $projectId = (int)($resolved[0] ?? 0);
  }
  if ($projectId <= 0) {
    throw new RuntimeException('Unable to resolve project code.');
  }

  $stmtProject = $conn->prepare("
    SELECT id, project_code, title
    FROM projects
    WHERE id = ?
    LIMIT 1
  ");
  $stmtProject->bind_param('i', $projectId);
  $stmtProject->execute();
  $project = $stmtProject->get_result()->fetch_assoc();
  if (!$project) {
    throw new RuntimeException('Project code not found.');
  }

  $stmtIns = $conn->prepare("
    INSERT IGNORE INTO document_projects (document_id, project_id, added_by_user_id)
    VALUES (?, ?, ?)
  ");
  $stmtIns->bind_param('iii', $docId, $projectId, $userId);
  $stmtIns->execute();
  $inserted = $stmtIns->affected_rows > 0;

  if ($inserted) {
    $payload = json_encode([
      'kind' => 'project_added',
      'project_id' => (int)$project['id'],
      'project_code' => (string)$project['project_code'],
      'project_title' => (string)$project['title'],
    ], JSON_UNESCAPED_UNICODE);

    $stmtEvent = $conn->prepare("
      INSERT INTO document_events
        (document_id, event_type, actor_user_id, actor_section_id, payload_json)
      VALUES (?, 'updated', ?, ?, ?)
    ");
    $stmtEvent->bind_param('iiis', $docId, $userId, $sectionId, $payload);
    $stmtEvent->execute();
  }

  $conn->commit();
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to attach project', 'debug' => $e->getMessage()]);
  exit;
}

$projects = fetch_document_projects($conn, $docId, true);
echo json_encode(['ok' => true, 'projects' => $projects], JSON_UNESCAPED_UNICODE);
