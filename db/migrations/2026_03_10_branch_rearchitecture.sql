-- DOCUMENT TRACKER BRANCH REARCHITECTURE
-- Run this against the existing doc_tracker database before using branch-mode features.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS document_branches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id INT NOT NULL,
  parent_branch_id INT UNSIGNED NULL,
  branch_label VARCHAR(150) NULL,
  current_assignee_user_id INT NULL,
  current_assignee_section_id INT NULL,
  branch_status ENUM('ACTIVE','COMPLETED','CANCELLED') NOT NULL DEFAULT 'ACTIVE',
  is_reference TINYINT(1) NOT NULL DEFAULT 0,
  created_by_user_id INT NOT NULL,
  completed_by_user_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_branches_document (document_id),
  KEY idx_branches_assignee (current_assignee_user_id),
  KEY idx_branches_status (branch_status),
  KEY idx_branches_parent (parent_branch_id),
  CONSTRAINT fk_branches_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_branches_parent FOREIGN KEY (parent_branch_id) REFERENCES document_branches(id) ON DELETE SET NULL,
  CONSTRAINT fk_branches_assignee_user FOREIGN KEY (current_assignee_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_branches_assignee_section FOREIGN KEY (current_assignee_section_id) REFERENCES sections(id) ON DELETE SET NULL,
  CONSTRAINT fk_branches_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT fk_branches_completed_by FOREIGN KEY (completed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_user_visibility (
  document_id INT NOT NULL,
  user_id INT NOT NULL,
  source ENUM('CREATOR','PARTICIPANT','REFERENCE','ADMIN') NOT NULL DEFAULT 'PARTICIPANT',
  branch_id INT UNSIGNED NULL,
  granted_by_user_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (document_id, user_id),
  KEY idx_duv_user (user_id),
  KEY idx_duv_branch (branch_id),
  CONSTRAINT fk_duv_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_duv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_duv_branch FOREIGN KEY (branch_id) REFERENCES document_branches(id) ON DELETE SET NULL,
  CONSTRAINT fk_duv_granted_by FOREIGN KEY (granted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE routes
  ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED NULL AFTER document_id,
  ADD COLUMN IF NOT EXISTS from_user_id INT NULL AFTER to_section_id,
  ADD COLUMN IF NOT EXISTS route_kind ENUM('ACTION','REFERENCE') NOT NULL DEFAULT 'ACTION' AFTER to_user_id,
  ADD KEY idx_routes_branch (branch_id),
  ADD KEY idx_routes_kind (route_kind);

ALTER TABLE routes
  ADD CONSTRAINT fk_routes_branch FOREIGN KEY (branch_id) REFERENCES document_branches(id) ON DELETE SET NULL;

-- Seed explicit creator visibility
INSERT IGNORE INTO document_user_visibility (document_id, user_id, source, granted_by_user_id)
SELECT d.id, d.created_by_user_id, 'CREATOR', d.created_by_user_id
FROM documents d
WHERE d.created_by_user_id IS NOT NULL;

-- Seed participant visibility from prior route involvement
INSERT IGNORE INTO document_user_visibility (document_id, user_id, source, granted_by_user_id)
SELECT DISTINCT r.document_id, r.to_user_id, 'PARTICIPANT', r.sent_by_user_id
FROM routes r
WHERE r.to_user_id IS NOT NULL;

INSERT IGNORE INTO document_user_visibility (document_id, user_id, source, granted_by_user_id)
SELECT DISTINCT r.document_id, r.sent_by_user_id, 'PARTICIPANT', r.sent_by_user_id
FROM routes r
WHERE r.sent_by_user_id IS NOT NULL;

INSERT IGNORE INTO document_user_visibility (document_id, user_id, source, granted_by_user_id)
SELECT DISTINCT r.document_id, r.received_by_user_id, 'PARTICIPANT', r.sent_by_user_id
FROM routes r
WHERE r.received_by_user_id IS NOT NULL;

COMMIT;
