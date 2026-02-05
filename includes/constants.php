<?php
declare(strict_types=1);

const DOC_STATUSES = ["incoming","under_action","released","archived"];

function status_label(string $s): array {
  return match($s) {
    "incoming" => ["Incoming", "incoming"],
    "under_action" => ["Under Action", "action"],
    "released" => ["Released", "released"],
    "archived" => ["Archived", "archived"],
    default => ["Unknown", "archived"]
  };
}
