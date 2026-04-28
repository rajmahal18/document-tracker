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
if ($docId <= 0 || $projectId <= 0) {
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
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Project code not found.']);
  exit;
}

try {
  $conn->begin_transaction();

  $stmtDel = $conn->prepare("
    DELETE FROM document_projects
    WHERE document_id = ?
      AND project_id = ?
    LIMIT 1
  ");
  $stmtDel->bind_param('ii', $docId, $projectId);
  $stmtDel->execute();
  $deleted = $stmtDel->affected_rows > 0;

  if ($deleted) {
    $payload = json_encode([
      'kind' => 'project_removed',
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
  echo json_encode(['ok' => false, 'error' => 'Failed to detach project', 'debug' => $e->getMessage()]);
  exit;
}

$projects = fetch_document_projects($conn, $docId, true);
echo json_encode(['ok' => true, 'projects' => $projects], JSON_UNESCAPED_UNICODE);
