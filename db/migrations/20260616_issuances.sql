CREATE TABLE IF NOT EXISTS issuances (
  id INT NOT NULL AUTO_INCREMENT,
  memo_no VARCHAR(80) NOT NULL,
  subject TEXT NOT NULL,
  issued_date DATE NOT NULL,
  document_url TEXT NOT NULL,
  document_original_name VARCHAR(255) NULL,
  document_mime VARCHAR(120) NULL,
  document_size_bytes INT UNSIGNED NULL,
  uploaded_at TIMESTAMP NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
