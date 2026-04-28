<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../core/project_codes.php';
require_login();

header('Content-Type: application/json');

if (!project_codes_tables_ready($conn)) {
  echo json_encode(['ok' => true, 'projects' => []]);
  exit;
}

$query = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 25);
if ($limit <= 0 || $limit > 100) {
  $limit = 25;
}

$selected = $_GET['selected_ids'] ?? [];
if (!is_array($selected)) {
  $selected = $selected === '' ? [] : explode(',', (string)$selected);
}
$selectedIds = array_values(array_unique(array_filter(array_map('intval', $selected), static fn(int $v): bool => $v > 0)));

$where = ["p.is_active = 1"];
$types = '';
$params = [];

if ($query !== '') {
  $where[] = "(p.project_code LIKE ? OR p.title LIKE ?)";
  $like = '%' . $query . '%';
  $types .= 'ss';
  $params[] = $like;
  $params[] = $like;
}

$sql = "
  SELECT p.id, p.project_code, p.title
  FROM projects p
  WHERE " . implode(' AND ', $where) . "
  ORDER BY
    CASE WHEN p.project_code LIKE ? THEN 0 ELSE 1 END,
    p.project_code ASC,
    p.title ASC
  LIMIT {$limit}
";
$prefixLike = $query === '' ? '%' : ($query . '%');
$types .= 's';
$params[] = $prefixLike;

$stmt = $conn->prepare($sql);
if ($types !== '') {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

if ($selectedIds !== []) {
  $existing = [];
  foreach ($rows as $r) {
    $existing[(int)($r['id'] ?? 0)] = true;
  }
  $missing = array_values(array_filter($selectedIds, static fn(int $id): bool => !isset($existing[$id])));
  if ($missing !== []) {
    $ph = implode(',', array_fill(0, count($missing), '?'));
    $tp = str_repeat('i', count($missing));
    $stmtSel = $conn->prepare("
      SELECT id, project_code, title
      FROM projects
      WHERE id IN ($ph)
      ORDER BY project_code ASC, title ASC
    ");
    $stmtSel->bind_param($tp, ...$missing);
    $stmtSel->execute();
    $extra = $stmtSel->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $rows = array_merge($extra, $rows);
  }
}

$projects = array_map(static function (array $row): array {
  $code = trim((string)($row['project_code'] ?? ''));
  $title = trim((string)($row['title'] ?? ''));
  return [
    'id' => (int)($row['id'] ?? 0),
    'project_code' => $code,
    'title' => $title,
    'label' => trim($code . ($title !== '' ? ' - ' . $title : '')),
  ];
}, $rows);

echo json_encode(['ok' => true, 'projects' => $projects], JSON_UNESCAPED_UNICODE);
