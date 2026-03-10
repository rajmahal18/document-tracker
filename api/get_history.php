<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

header("Content-Type: application/json");

$docId = (int)($_GET["document_id"] ?? 0);
$selectedBranchId = (int)($_GET["branch_id"] ?? 0);
if ($docId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad document_id"]);
  exit;
}

try {

  $toStringList = static function ($value): array {
    if (is_array($value)) {
      return array_values(array_filter(array_map(static fn($v) => trim((string)$v), $value), static fn($v) => $v !== ''));
    }
    $s = trim((string)$value);
    if ($s === '') return [];
    if (($s[0] ?? '') === '[' || ($s[0] ?? '') === '{') {
      $decoded = json_decode($s, true);
      if (is_array($decoded)) {
        return array_values(array_filter(array_map(static fn($v) => trim((string)$v), $decoded), static fn($v) => $v !== ''));
      }
    }
    if (str_contains($s, ',')) {
      return array_values(array_filter(array_map('trim', explode(',', $s)), static fn($v) => $v !== ''));
    }
    return [$s];
  };

  $firstNonEmpty = static function (array $values): string {
    foreach ($values as $value) {
      $s = trim((string)$value);
      if ($s === '') continue;
      if (in_array(strtolower($s), ['-', '—', 'n/a', 'na', 'null', 'undefined'], true)) continue;
      return $s;
    }
    return '';
  };

  $summarizeList = static function (array $items, int $max = 3): string {
    $items = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $items), static fn($v) => $v !== ''));
    if ($items === []) return '';
    if (count($items) <= $max) return implode(', ', $items);
    return implode(', ', array_slice($items, 0, $max)) . ' +' . (count($items) - $max) . ' more';
  };

  if (!can_view_document($conn, $docId)) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Forbidden"]);
    exit;
  }

  $branchMode = workflow_branch_mode_enabled($conn);
  $viewerUserId = (int)($_SESSION["user_id"] ?? 0);
  $viewerDivisionName = trim((string)($_SESSION["division_name"] ?? ""));
  $viewerRole = strtolower(trim((string)($_SESSION["role"] ?? "")));
  $viewerIsAdmin = ($viewerRole === "admin");

  $branches = $branchMode ? workflow_get_branch_state($conn, $docId, $viewerUserId) : [];

  // Build full branch tree for strict selected-lane lineage filtering.
  $allBranchesById = [];
  if ($branchMode) {
    $stmtAllBranches = $conn->prepare("
      SELECT id, parent_branch_id, branch_label, is_reference
      FROM document_branches
      WHERE document_id = ?
      ORDER BY id ASC
    ");
    $stmtAllBranches->bind_param("i", $docId);
    $stmtAllBranches->execute();
    $allBranchRows = $stmtAllBranches->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($allBranchRows as $branchRow) {
      $bid = (int)($branchRow['id'] ?? 0);
      if ($bid <= 0) continue;

      $branchRow['id'] = $bid;
      $branchRow['parent_branch_id'] = (int)($branchRow['parent_branch_id'] ?? 0);
      $branchRow['is_reference'] = ((int)($branchRow['is_reference'] ?? 0) === 1) ? 1 : 0;
      $allBranchesById[$bid] = $branchRow;
    }
  }

  // Hide only synthetic/root/origin branches from UI tabs.
  // Reference branches must stay visible when they are the viewer's actual lane.
  if ($branchMode && is_array($branches)) {
    $branches = array_values(array_filter($branches, static function ($branchRow) {
      $label = strtolower(trim((string)($branchRow['branch_label'] ?? '')));
      $parentId = (int)($branchRow['parent_branch_id'] ?? 0);

      if ($label === 'origin') return false;
      if ($label === '' && $parentId <= 0) return false;

      return true;
    }));
  }

  $selectedBranchScopeIds = [];
  if ($branchMode && $selectedBranchId > 0 && isset($allBranchesById[$selectedBranchId])) {
    $cursorId = $selectedBranchId;
    $guard = 0;

    while ($cursorId > 0 && isset($allBranchesById[$cursorId]) && $guard < 100) {
      if (in_array($cursorId, $selectedBranchScopeIds, true)) {
        break;
      }

      $selectedBranchScopeIds[] = $cursorId;
      $cursorId = (int)($allBranchesById[$cursorId]['parent_branch_id'] ?? 0);
      $guard++;
    }
  }
  $stmt = $conn->prepare("\n    SELECT\n      e.id AS event_id,\n      e.event_type,\n      e.created_at,\n      e.payload_json,\n      e.actor_section_id,\n      s_actor.name AS actor_section_name,\n      d_actor.name AS actor_division_name,\n      u.full_name AS actor,\n      s_from.name AS from_section,\n      d_from.name AS from_division_name,\n      s_to.name AS to_section,\n      d_to.name AS to_division_name\n    FROM document_events e\n    LEFT JOIN users u ON u.id = e.actor_user_id\n    LEFT JOIN sections s_actor ON s_actor.id = e.actor_section_id\n    LEFT JOIN divisions d_actor ON d_actor.id = s_actor.division_id\n    LEFT JOIN sections s_from ON s_from.id = e.from_section_id\n    LEFT JOIN divisions d_from ON d_from.id = s_from.division_id\n    LEFT JOIN sections s_to   ON s_to.id = e.to_section_id\n    LEFT JOIN divisions d_to ON d_to.id = s_to.division_id\n    WHERE e.document_id = ?\n    ORDER BY e.created_at DESC, e.id DESC\n    LIMIT 100\n  ");
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  $history = [];
  foreach ($rows as $r) {
    $payload = [];
    if (!empty($r["payload_json"])) {
      $decoded = json_decode((string)$r["payload_json"], true);
      if (is_array($decoded)) $payload = $decoded;
    }

    $eventType = (string)($r["event_type"] ?? "updated");
    $eventKey  = strtolower(trim($eventType));
    if ($eventKey === "") $eventKey = "updated";
    if ($eventKey === "updated" && (($payload["kind"] ?? "") === "attachment_added")) {
      $eventKey = "attachment_added";
    }

    $actor = (string)($r["actor"] ?? "—");
    $from  = (string)($r["from_section"] ?? "");
    $to    = (string)($r["to_section"] ?? "");
    $branchId = (int)($payload["branch_id"] ?? 0);
    $sourceBranchId = (int)($payload["source_branch_id"] ?? 0);
    $newBranchIds = array_values(array_filter(array_map('intval', (array)($payload['new_branch_ids'] ?? $payload['branch_ids'] ?? []))));
    $branchLabel = (string)($payload["branch_label"] ?? "");

    if ($branchMode && $branchLabel === '') {
      $lookupBranchId = $branchId > 0 ? $branchId : ($newBranchIds[0] ?? $sourceBranchId);
      if ($lookupBranchId > 0) {
        $stmtBranch = $conn->prepare("SELECT branch_label FROM document_branches WHERE id = ? LIMIT 1");
        $stmtBranch->bind_param("i", $lookupBranchId);
        $stmtBranch->execute();
        $branchLabel = (string)($stmtBranch->get_result()->fetch_assoc()["branch_label"] ?? "");
      }
    }

    $payloadToSections = array_merge(
      $toStringList($payload["to_section_name"] ?? []),
      $toStringList($payload["to_section_names"] ?? []),
      $toStringList($payload["target_section_name"] ?? []),
      $toStringList($payload["target_sections"] ?? []),
      $toStringList($payload["recipient_section_names"] ?? [])
    );
    $payloadToUsers = array_merge(
      $toStringList($payload["to_user_name"] ?? []),
      $toStringList($payload["to_user_names"] ?? []),
      $toStringList($payload["recipient_names"] ?? []),
      $toStringList($payload["recipients"] ?? [])
    );
    $payloadFromUser = $firstNonEmpty([
      $payload["from_user_name"] ?? "",
      $payload["source_user_name"] ?? "",
      $payload["sent_by_name"] ?? "",
    ]);

    $payloadToUser = $firstNonEmpty([
      $payload["to_user_name"] ?? "",
      $payload["target_user_name"] ?? "",
      $payload["received_by_name"] ?? "",
      $payload["forwarded_to_name"] ?? "",
      $payloadToUsers[0] ?? "",
    ]);

    // Event-aware normalization
    if ($eventKey === "received") {
      $payloadToUser = $firstNonEmpty([
        $actor, // receiver is usually the actor
        $payload["received_by_name"] ?? "",
        $payloadToUser,
      ]);

      $payloadFromUser = $firstNonEmpty([
        $payload["from_user_name"] ?? "",
        $payload["source_user_name"] ?? "",
        $payload["sent_by_name"] ?? "",
      ]);
    }

    if (in_array($eventKey, ["sent", "forwarded"], true)) {
      $payloadFromUser = $firstNonEmpty([
        $actor, // sender/forwarder is usually the actor
        $payload["from_user_name"] ?? "",
        $payload["source_user_name"] ?? "",
        $payload["sent_by_name"] ?? "",
      ]);

      $payloadToUser = $firstNonEmpty([
        $payload["to_user_name"] ?? "",
        $payload["target_user_name"] ?? "",
        $payload["forwarded_to_name"] ?? "",
        $payload["received_by_name"] ?? "",
        $payloadToUsers[0] ?? "",
      ]);
    }


    $payloadFrom = $firstNonEmpty([
      $payload["from_section_name"] ?? "",
      $payload["source_section_name"] ?? "",
      $from,
    ]);
    $payloadTo = $firstNonEmpty([
      $payload["to_section_name"] ?? "",
      $payload["target_section_name"] ?? "",
      $to,
      $payloadToSections[0] ?? "",
    ]);
    $payloadRecipientSummary = $summarizeList(array_unique(array_merge($payloadToSections, $payloadToUsers)));
    $branchSplitCount = count($newBranchIds);

    $title = "";
    switch ($eventKey) {
      case "created":
        $title = "{$actor} created the document";
        break;

      case "sent":
        $title = "{$actor} sent the document";
        if ($branchSplitCount > 1) {
          $title .= " to {$branchSplitCount} recipients";
        }
        break;

      case "forwarded":
        $title = "{$actor} forwarded the document";
        if ($branchSplitCount > 1) {
          $title .= " to {$branchSplitCount} recipients";
        }
        break;

      case "received":
        $title = "{$actor} received the document";
        break;

      case "released":
        $old = strtoupper((string)($payload["old_status"] ?? ""));
        $new = strtoupper((string)($payload["new_status"] ?? ""));
        if ($old === "RELEASED" && $new === "ACTIVE") {
          $eventKey = "release_undone";
          $title = "{$actor} undid the release";
        } else {
          $title = "{$actor} released the document";
        }
        break;

      case "archived":
        $old = strtoupper((string)($payload["old_status"] ?? ""));
        $new = strtoupper((string)($payload["new_status"] ?? ""));
        if ($old === "ARCHIVED" && $new === "RELEASED") {
          $eventKey = "archive_undone";
          $title = "{$actor} undid the archive";
        } else {
          $title = "{$actor} archived the document";
        }
        break;

      case "attachment_added":
        $file = (string)($payload["file"] ?? "file");
        $isAppend = ((int)($payload["is_append"] ?? 0) === 1);
        $what = $isAppend ? "an appended file" : "a file";
        $title = "{$actor} attached {$what}";
        if ($file !== '') {
          $title .= ": {$file}";
        }
        break;

      case "updated":
        $remarksText = trim((string)($payload["remarks"] ?? ""));
        if ($remarksText !== '') {
          $title = "{$actor} updated the document";
        } else {
          continue 2;
        }
        break;

      default:
        continue 2;
    }

    $movementFrom = $payloadFrom;
    $movementTo = $payloadTo;
    if ($movementFrom !== '' && $movementTo !== '' && strcasecmp($movementFrom, $movementTo) === 0) {
      $movementTo = '';
    }
    $movementLabel = '';
    if ($movementFrom !== '' && $movementTo !== '') {
      $movementLabel = "{$movementFrom} → {$movementTo}";
    } elseif ($movementTo !== '') {
      $movementLabel = "To: {$movementTo}";
    } elseif ($movementFrom !== '') {
      $movementLabel = "From: {$movementFrom}";
    }

    $personMovementLabel = '';
    if ($payloadFromUser !== '' && $payloadToUser !== '' && strcasecmp($payloadFromUser, $payloadToUser) !== 0) {
      $personMovementLabel = "{$payloadFromUser} → {$payloadToUser}";
    }

    $metaParts = [];
    if ($movementLabel !== '') $metaParts[] = $movementLabel;
    if ($payloadRecipientSummary !== '' && $payloadRecipientSummary !== $movementTo) {
      $metaParts[] = "Recipients: {$payloadRecipientSummary}";
    }
    if ($branchLabel !== '') {
      $metaParts[] = 'Branch: ' . $branchLabel;
    }
    $meta = implode(' • ', $metaParts);

    $resolvedBranchId = $branchId > 0 ? $branchId : ($newBranchIds[0] ?? ($sourceBranchId > 0 ? $sourceBranchId : null));

    if ($selectedBranchId > 0) {
      $candidateBranchIds = [];

      if ($resolvedBranchId !== null && $resolvedBranchId > 0) {
        $candidateBranchIds[] = (int)$resolvedBranchId;
      }

      if ($sourceBranchId > 0) {
        $candidateBranchIds[] = $sourceBranchId;
      }

      foreach ($newBranchIds as $newBid) {
        $newBid = (int)$newBid;
        if ($newBid > 0) {
          $candidateBranchIds[] = $newBid;
        }
      }

      $candidateBranchIds = array_values(array_unique($candidateBranchIds));

      $belongsToSelected = false;

      if ($candidateBranchIds !== []) {
        foreach ($candidateBranchIds as $candidateBid) {
          if (in_array($candidateBid, $selectedBranchScopeIds, true)) {
            $belongsToSelected = true;
            break;
          }
        }
      } elseif ($resolvedBranchId === null && $sourceBranchId === 0 && count($newBranchIds) === 0) {
        // For single-recipient / non-branch docs, keep the event visible.
        $belongsToSelected = true;
      }

      if (!$belongsToSelected) {
        continue;
      }
    }

    $actorDivision = trim((string)($r["actor_division_name"] ?? ""));
    $fromDivision = trim((string)($r["from_division_name"] ?? ""));
    $toDivision = trim((string)($r["to_division_name"] ?? ""));
    $eventDivision = $firstNonEmpty([$actorDivision, $fromDivision, $toDivision]);
    $sameDivision = (
      $viewerDivisionName !== ''
      && $eventDivision !== ''
      && strcasecmp($viewerDivisionName, $eventDivision) === 0
    );
    $shouldRedact = (!$viewerIsAdmin && !$sameDivision && $eventDivision !== '');

    $branchSplitRedacted = false;
    if ($shouldRedact) {
      $safeDivision = $eventDivision;
      $actor = $safeDivision;
      $branchLabel = '';
      $personMovementLabel = '';
      $payloadRecipientSummary = '';
      $movementLabel = '';
      $movementFrom = '';
      $movementTo = '';
      $from = '';
      $to = '';
      $payloadFromUser = '';
      $payloadToUser = '';
      $meta = '';
      if (count($newBranchIds) > 1) {
        $branchSplitRedacted = true;
        $newBranchIds = [];
      }

      switch ($eventKey) {
        case 'created':
          $title = "{$safeDivision} created the document";
          break;
        case 'received':
          $title = "{$safeDivision} received the document";
          break;
        case 'sent':
        case 'forwarded':
          $title = "{$safeDivision} forwarded the document";
          break;
        case 'attachment_added':
        case 'updated':
        case 'under_action':
        case 'status_changed':
          $title = "{$safeDivision} committed an action";
          break;
        case 'released':
          $title = "{$safeDivision} released the document";
          break;
        case 'release_undone':
          $title = "{$safeDivision} undid the release";
          break;
        case 'archived':
          $title = "{$safeDivision} archived the document";
          break;
        case 'archive_undone':
          $title = "{$safeDivision} undid the archive";
          break;
      }
    }

    $history[] = [
      "event_id" => (int)($r["event_id"] ?? 0),
      "action" => $eventKey,
      "event_type" => $eventType,
      "title" => $title,
      "meta" => $meta,
      "branch_id" => $resolvedBranchId,
      "source_branch_id" => $sourceBranchId > 0 ? $sourceBranchId : null,
      "new_branch_ids" => $newBranchIds,
      "branch_label" => $branchLabel,
      "actor_section_id" => (int)($r["actor_section_id"] ?? 0),
      "actor_section" => $shouldRedact ? $eventDivision : (string)($r["actor_section_name"] ?? ""),
      "actor_division" => $actorDivision,
      "viewer_redacted" => $shouldRedact,
      "branch_split_redacted" => $branchSplitRedacted,
      "remarks" => $shouldRedact ? "" : (string)($payload["remarks"] ?? ""),
      "acted_at" => (string)($r["created_at"] ?? ""),
      "actor" => $actor,
      "from_section" => $movementFrom !== '' ? $movementFrom : $from,
      "to_section" => $movementTo !== '' ? $movementTo : $to,
      "movement_label" => $movementLabel,
      "recipient_summary" => $payloadRecipientSummary,
      "person_movement" => $personMovementLabel,
      "from_user_name" => $payloadFromUser,
      "to_user_name" => $payloadToUser,
    ];
  }

  echo json_encode([
    "ok" => true,
    "branch_mode" => $branchMode,
    "branches" => $branches,
    "selected_branch_id" => $selectedBranchId > 0 ? $selectedBranchId : null,
    "history" => $history
  ], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error", "debug" => $e->getMessage()]);
  exit;
}
