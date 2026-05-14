SET @has_uq_doc_division_tracking_no := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'document_division_tracking'
    AND index_name = 'uq_doc_division_tracking_no'
);

SET @has_idx_doc_division_tracking_division := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'document_division_tracking'
    AND index_name = 'idx_doc_division_tracking_division'
);

SET @add_idx_doc_division_tracking_division_sql := IF(
  @has_idx_doc_division_tracking_division = 0,
  'ALTER TABLE document_division_tracking ADD INDEX idx_doc_division_tracking_division (division_id)',
  'SELECT 1'
);

PREPARE add_idx_doc_division_tracking_division_stmt FROM @add_idx_doc_division_tracking_division_sql;
EXECUTE add_idx_doc_division_tracking_division_stmt;
DEALLOCATE PREPARE add_idx_doc_division_tracking_division_stmt;

SET @drop_uq_doc_division_tracking_no_sql := IF(
  @has_uq_doc_division_tracking_no > 0,
  'ALTER TABLE document_division_tracking DROP INDEX uq_doc_division_tracking_no',
  'SELECT 1'
);

PREPARE drop_uq_doc_division_tracking_no_stmt FROM @drop_uq_doc_division_tracking_no_sql;
EXECUTE drop_uq_doc_division_tracking_no_stmt;
DEALLOCATE PREPARE drop_uq_doc_division_tracking_no_stmt;
