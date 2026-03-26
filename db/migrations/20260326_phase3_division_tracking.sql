ALTER TABLE divisions ADD COLUMN code VARCHAR(16) NULL AFTER name;
UPDATE divisions SET code = 'TS' WHERE id = 1 AND (code IS NULL OR code = '');
UPDATE divisions SET code = 'PPD' WHERE id = 2 AND (code IS NULL OR code = '');
UPDATE divisions SET code = 'SDD' WHERE id = 3 AND (code IS NULL OR code = '');
UPDATE divisions SET code = 'SPD' WHERE id = 4 AND (code IS NULL OR code = '');

CREATE TABLE IF NOT EXISTS division_tracking_sequences (
  division_id INT NOT NULL,
  tracking_date DATE NOT NULL,
  last_number SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (division_id, tracking_date),
  CONSTRAINT fk_division_tracking_seq_division FOREIGN KEY (division_id) REFERENCES divisions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_division_tracking (
  id INT NOT NULL AUTO_INCREMENT,
  document_id INT NOT NULL,
  division_id INT NOT NULL,
  tracking_no VARCHAR(32) NOT NULL,
  tracking_date DATE NOT NULL,
  sequence_no SMALLINT UNSIGNED NOT NULL,
  is_manual TINYINT(1) NOT NULL DEFAULT 0,
  created_by_user_id INT DEFAULT NULL,
  updated_by_user_id INT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_doc_division_tracking_doc_division (document_id, division_id),
  UNIQUE KEY uq_doc_division_tracking_no (division_id, tracking_no),
  KEY idx_doc_division_tracking_doc (document_id),
  CONSTRAINT fk_doc_division_tracking_doc FOREIGN KEY (document_id) REFERENCES documents(id),
  CONSTRAINT fk_doc_division_tracking_division FOREIGN KEY (division_id) REFERENCES divisions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


ALTER TABLE users
  ADD COLUMN IF NOT EXISTS permanent TINYINT(1) NOT NULL DEFAULT 0 AFTER is_chief;
