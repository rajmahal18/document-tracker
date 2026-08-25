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

const ATTACHMENT_MAX_BYTES = 100 * 1024 * 1024;
const ATTACHMENT_MAX_MB = 100;
const ATTACHMENT_ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
const ATTACHMENT_ALLOWED_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];
const DTS_REMARKS_MAX_CHARS = 5000;
