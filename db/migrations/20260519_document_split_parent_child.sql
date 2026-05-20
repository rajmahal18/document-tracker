CREATE TABLE IF NOT EXISTS document_tracking_sequences (
  tracking_year SMALLINT UNSIGNED NOT NULL,
  last_number INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tracking_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_parent_document_id := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'documents'
    AND COLUMN_NAME = 'parent_document_id'
);

SET @add_parent_column_sql := IF(
  @has_parent_document_id = 0,
  'ALTER TABLE documents ADD COLUMN parent_document_id INT NULL AFTER current_holder_section_id',
  'SELECT 1'
);
PREPARE add_parent_column_stmt FROM @add_parent_column_sql;
EXECUTE add_parent_column_stmt;
DEALLOCATE PREPARE add_parent_column_stmt;

SET @has_parent_document_idx := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'documents'
    AND INDEX_NAME = 'idx_documents_parent_document_id'
);

SET @add_parent_index_sql := IF(
  @has_parent_document_idx = 0,
  'ALTER TABLE documents ADD KEY idx_documents_parent_document_id (parent_document_id)',
  'SELECT 1'
);
PREPARE add_parent_index_stmt FROM @add_parent_index_sql;
EXECUTE add_parent_index_stmt;
DEALLOCATE PREPARE add_parent_index_stmt;
