<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_login();

$pageTitle = 'Issuances - Document Tracker';
$pageStyles = [asset_url('assets/css/issuances.css')];
$currentPage = 'issuances.php';

function issuances_table_ready(mysqli $conn): bool {
  static $ready = null;
  if ($ready !== null) {
    return $ready;
  }

  $ok = $conn->query("
    CREATE TABLE IF NOT EXISTS issuances (
      id INT NOT NULL AUTO_INCREMENT,
      memo_no VARCHAR(80) NOT NULL,
      subject TEXT NOT NULL,
      issued_date DATE NOT NULL,
      document_url TEXT NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_by_user_id INT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_issuances_issued_date (issued_date),
      KEY idx_issuances_memo_no (memo_no),
      KEY idx_issuances_active_date (is_active, issued_date),
      CONSTRAINT fk_issuances_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $ready = $ok || db_table_exists($conn, 'issuances');
  return $ready;
}

function issuances_h(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function issuance_valid_document_url(string $value): bool {
  $value = trim($value);
  if ($value === '') {
    return false;
  }

  if (preg_match('#^https?://#i', $value) === 1) {
    return filter_var($value, FILTER_VALIDATE_URL) !== false;
  }

  if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $value) === 1 || str_starts_with($value, '//')) {
    return false;
  }

  return true;
}

function issuance_valid_date(string $value): bool {
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
    return false;
  }

  [$year, $month, $day] = array_map('intval', explode('-', $value));
  return checkdate($month, $day, $year);
}

function issuance_storage_dir(string $issuedDate): string {
  $year = substr($issuedDate, 0, 4);
  if (preg_match('/^\d{4}$/', $year) !== 1) {
    $year = date('Y');
  }

  $base = realpath(__DIR__ . '/../storage/issuances');
  if ($base === false) {
    $base = __DIR__ . '/../storage/issuances';
  }

  return ensure_storage_dir(rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $year);
}

function issuance_store_uploaded_file(array $file, string $issuedDate, int $userId): array {
  $upload = attachment_validate_uploaded_file($file);
  $dir = issuance_storage_dir($issuedDate);
  $extension = strtolower((string)($upload['extension'] ?? ''));
  $stamp = date('Ymd_His');
  $random = bin2hex(random_bytes(6));
  $safeUserId = max(0, $userId);
  $storedName = "{$stamp}_u{$safeUserId}_{$random}.{$extension}";
  $targetPath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $storedName;

  if (!move_uploaded_file((string)$upload['tmp_path'], $targetPath)) {
    throw new RuntimeException('Failed to store issuance file.');
  }

  $relativePath = str_replace('\\', '/', substr($targetPath, strlen(realpath(__DIR__ . '/..')) + 1));
  return [
    'path' => $relativePath,
    'original_name' => (string)($upload['original_name'] ?? ''),
    'mime' => (string)($upload['mime'] ?? ''),
    'size_bytes' => (int)($upload['size_bytes'] ?? 0),
  ];
}

function issuance_document_href(string $value): string {
  $value = trim($value);
  if (preg_match('#^https?://#i', $value) === 1) {
    return $value;
  }

  if (str_starts_with($value, '/')) {
    return app_safe_next_path($value, PUBLIC_PATH . '/issuances.php');
  }

  return asset_url($value);
}

function issuance_format_date(string $date): string {
  $ts = strtotime($date);
  if ($ts === false) {
    return $date;
  }
  return date('d M Y', $ts);
}

$isAdmin = is_admin_user();
$flash = $_SESSION['issuances_flash'] ?? null;
unset($_SESSION['issuances_flash']);
$errors = [];

if (!issuances_table_ready($conn)) {
  $errors[] = 'Issuances storage is not available yet.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && issuances_table_ready($conn)) {
  require_csrf();
  $action = trim((string)($_POST['action'] ?? ''));

  if ($action === 'create') {
    $memoNo = trim((string)($_POST['memo_no'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $issuedDate = trim((string)($_POST['issued_date'] ?? ''));
    $storedUpload = null;
    $documentUrl = '';

    if ($memoNo === '') {
      $errors[] = 'Memo number is required.';
    }
    if ($subject === '') {
      $errors[] = 'Subject is required.';
    }
    if (!issuance_valid_date($issuedDate)) {
      $errors[] = 'A valid issued date is required.';
    }
    $file = $_FILES['issuance_file'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
      $errors[] = 'Upload the issuance document.';
    }

    if (!$errors) {
      $creatorId = (int)($_SESSION['user_id'] ?? 0);
      try {
        $storedUpload = issuance_store_uploaded_file($file, $issuedDate, $creatorId);
        $documentUrl = (string)($storedUpload['path'] ?? '');
      } catch (Throwable $e) {
        $errors[] = $e->getMessage();
      }
    }

    if (!$errors) {
      $hasUploadMeta = db_column_exists($conn, 'issuances', 'document_original_name')
        && db_column_exists($conn, 'issuances', 'document_mime')
        && db_column_exists($conn, 'issuances', 'document_size_bytes')
        && db_column_exists($conn, 'issuances', 'uploaded_at');

      $sql = $hasUploadMeta
        ? "INSERT INTO issuances (memo_no, subject, issued_date, document_url, document_original_name, document_mime, document_size_bytes, uploaded_at, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
        : "INSERT INTO issuances (memo_no, subject, issued_date, document_url, created_by_user_id) VALUES (?, ?, ?, ?, ?)";
      $stmt = $conn->prepare($sql);
      if ($stmt) {
        if ($hasUploadMeta) {
          $originalName = (string)($storedUpload['original_name'] ?? '');
          $mime = (string)($storedUpload['mime'] ?? '');
          $sizeBytes = (int)($storedUpload['size_bytes'] ?? 0);
          $stmt->bind_param('ssssssii', $memoNo, $subject, $issuedDate, $documentUrl, $originalName, $mime, $sizeBytes, $creatorId);
        } else {
          $stmt->bind_param('ssssi', $memoNo, $subject, $issuedDate, $documentUrl, $creatorId);
        }
        if ($stmt->execute()) {
          $_SESSION['issuances_flash'] = ['type' => 'success', 'message' => 'Issuance added.'];
          redirect(PUBLIC_PATH . '/issuances.php?year=' . rawurlencode(date('Y', strtotime($issuedDate))));
        }
        $errors[] = 'Unable to save issuance.';
        $stmt->close();
      } else {
        $errors[] = 'Unable to prepare issuance save.';
      }
    }
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $stmt = $conn->prepare("UPDATE issuances SET is_active = 0 WHERE id = ? LIMIT 1");
      if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['issuances_flash'] = ['type' => 'success', 'message' => 'Issuance removed from the list.'];
        redirect(PUBLIC_PATH . '/issuances.php');
      }
    }
    $errors[] = 'Unable to remove issuance.';
  }
}

$years = [];
$selectedYear = '';
$q = trim((string)($_GET['q'] ?? ''));
$issuances = [];
$totalCount = 0;

if (issuances_table_ready($conn)) {
  $yearsRes = $conn->query("
    SELECT YEAR(issued_date) AS issuance_year, COUNT(*) AS total
    FROM issuances
    WHERE is_active = 1
    GROUP BY YEAR(issued_date)
    ORDER BY issuance_year DESC
  ");
  if ($yearsRes) {
    while ($row = $yearsRes->fetch_assoc()) {
      $year = (string)((int)($row['issuance_year'] ?? 0));
      if ($year !== '0') {
        $years[$year] = (int)($row['total'] ?? 0);
      }
    }
    $yearsRes->free();
  }

  $requestedYear = preg_replace('/[^0-9]/', '', (string)($_GET['year'] ?? '')) ?? '';
  $selectedYear = (string)(isset($years[$requestedYear]) ? $requestedYear : (array_key_first($years) ?? ''));

  $countRes = $conn->query("SELECT COUNT(*) AS total FROM issuances WHERE is_active = 1");
  if ($countRes) {
    $totalCount = (int)(($countRes->fetch_assoc()['total'] ?? 0));
    $countRes->free();
  }

  if ($selectedYear !== '') {
    $where = ['is_active = 1', 'YEAR(issued_date) = ?'];
    $params = [(int)$selectedYear];
    $types = 'i';

    if ($q !== '') {
      $where[] = '(memo_no LIKE ? OR subject LIKE ?)';
      $like = '%' . $q . '%';
      $params[] = $like;
      $params[] = $like;
      $types .= 'ss';
    }

    $sql = "
      SELECT id, memo_no, subject, issued_date, document_url
      FROM issuances
      WHERE " . implode(' AND ', $where) . "
      ORDER BY issued_date ASC, id ASC
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
      $bind = [&$types];
      foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
      }
      call_user_func_array([$stmt, 'bind_param'], $bind);
      $stmt->execute();
      $issuances = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
      $stmt->close();
    }
  }
}

require __DIR__ . '/../includes/layout.php';
?>

<div class="issuancesPage">
  <section class="issuancesHero">
    <div>
      <div class="issuancesKicker">Public Reference</div>
      <h2 class="issuancesTitle">Issuances</h2>
      <p class="issuancesLead">
        Browse ministry issuances by year. Each row opens the source document in a new browser tab.
      </p>
    </div>
    <div class="issuancesStats" aria-label="Issuance summary">
      <div class="issuancesStat">
        <span>Total</span>
        <strong><?= (int)$totalCount ?></strong>
      </div>
      <div class="issuancesStat">
        <span>Years</span>
        <strong><?= count($years) ?></strong>
      </div>
    </div>
  </section>

  <?php if (is_array($flash) && trim((string)($flash['message'] ?? '')) !== ''): ?>
    <div class="notice" style="margin:0;<?= ($flash['type'] ?? '') === 'success' ? 'background:#ecfdf3;border:1px solid #abefc6;color:#067647;' : 'background:#fff4e5;border:1px solid #ffd8a8;color:#92400e;' ?>">
      <?= issuances_h((string)$flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="notice" style="margin:0;background:#fef3f2;border:1px solid #fecdca;color:#b42318;">
      <?= issuances_h(implode(' ', $errors)) ?>
    </div>
  <?php endif; ?>

  <div class="issuancesTools">
    <form class="issuancesSearch" method="get" action="<?= PUBLIC_PATH ?>/issuances.php">
      <?php if ($selectedYear !== ''): ?>
        <input type="hidden" name="year" value="<?= issuances_h($selectedYear) ?>">
      <?php endif; ?>
      <input class="search" type="search" name="q" value="<?= issuances_h($q) ?>" placeholder="Search memo number or subject">
      <button type="submit" class="btnPrimary">Search</button>
    </form>
    <?php if ($q !== ''): ?>
      <a class="btnSecondary" href="<?= PUBLIC_PATH ?>/issuances.php<?= $selectedYear !== '' ? '?year=' . rawurlencode($selectedYear) : '' ?>">Clear</a>
    <?php endif; ?>
  </div>

  <?php if ($years): ?>
    <nav class="issuancesYearTabs" aria-label="Issuance years">
      <?php foreach ($years as $year => $count): ?>
        <?php
          $href = PUBLIC_PATH . '/issuances.php?year=' . rawurlencode((string)$year);
          if ($q !== '') {
            $href .= '&q=' . rawurlencode($q);
          }
        ?>
        <a class="issuancesYearTab <?= $selectedYear === (string)$year ? 'isActive' : '' ?>" href="<?= issuances_h($href) ?>">
          <span><?= issuances_h((string)$year) ?></span>
          <span><?= (int)$count ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <section class="issuancesTableShell">
    <div class="issuancesTableTop">
      <div>
        <span class="issuancesTableLabel">Listing</span>
        <div class="issuancesTableTitle"><?= $selectedYear !== '' ? issuances_h($selectedYear) . ' Issuances' : 'No Issuances Yet' ?></div>
      </div>
      <div class="issuancesTableMeta"><?= count($issuances) ?> shown<?= $q !== '' ? ' for search' : '' ?></div>
    </div>

    <?php if (!$years): ?>
      <div class="issuancesEmpty">No issuances have been added yet.</div>
    <?php elseif (!$issuances): ?>
      <div class="issuancesEmpty">No issuances match the current year and search.</div>
    <?php else: ?>
      <div class="issuancesTableWrap">
        <table class="issuancesTable">
          <thead>
            <tr>
              <th>Memo #</th>
              <th>Subject</th>
              <th>Date Issued</th>
              <?php if ($isAdmin): ?><th>Admin</th><?php endif; ?>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($issuances as $issuance): ?>
              <?php $href = issuance_document_href((string)($issuance['document_url'] ?? '')); ?>
              <tr data-issuance-href="<?= issuances_h($href) ?>" onclick="if (!event.target.closest('button,form,a')) window.open(this.dataset.issuanceHref, '_blank', 'noopener');">
                <td><a class="issuanceRowLink" href="<?= issuances_h($href) ?>" target="_blank" rel="noopener"><span class="issuanceMemo"><?= issuances_h((string)$issuance['memo_no']) ?></span></a></td>
                <td><a class="issuanceRowLink" href="<?= issuances_h($href) ?>" target="_blank" rel="noopener"><span class="issuanceSubject"><?= issuances_h((string)$issuance['subject']) ?></span></a></td>
                <td><span class="issuanceDate"><?= issuances_h(issuance_format_date((string)$issuance['issued_date'])) ?></span></td>
                <?php if ($isAdmin): ?>
                  <td>
                    <form class="issuanceDeleteForm" method="post" action="<?= PUBLIC_PATH ?>/issuances.php" onsubmit="return confirm('Remove this issuance from the list?');">
                      <input type="hidden" name="csrf_token" value="<?= issuances_h(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$issuance['id'] ?>">
                      <button type="submit" class="issuanceDeleteBtn">Remove</button>
                    </form>
                  </td>
                <?php endif; ?>
                <td class="issuanceOpen"><span aria-hidden="true">&nearr;</span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($isAdmin): ?>
    <details class="issuanceAdminPanel">
      <summary>Add issuance</summary>
      <form class="issuanceForm" method="post" action="<?= PUBLIC_PATH ?>/issuances.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= issuances_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <div class="issuanceFormGrid">
          <label>
            <span>Memo #</span>
            <input class="search" type="text" name="memo_no" maxlength="80" required placeholder="e.g. MO-002">
          </label>
          <label>
            <span>Date Issued</span>
            <input class="date" type="date" name="issued_date" required>
          </label>
        </div>
        <div class="issuanceFormGridFull">
          <label>
            <span>Document File</span>
            <input class="fileInput" type="file" name="issuance_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
          </label>
          <label>
            <span>Subject</span>
            <textarea class="modalTextarea" name="subject" rows="3" required placeholder="Issuance subject"></textarea>
          </label>
        </div>
        <div class="issuanceFormHint">PDF/JPG/PNG only. The file path is stored in the issuances database record, and the year tab is generated from Date Issued.</div>
        <div class="issuanceFormActions">
          <button type="submit" class="btnComp">Save issuance</button>
        </div>
      </form>
    </details>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
