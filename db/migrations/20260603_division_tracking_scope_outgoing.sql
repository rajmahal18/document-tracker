SET @has_sequence_scope := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'division_tracking_sequences'
    AND COLUMN_NAME = 'tracking_scope'
);

SET @add_sequence_scope_sql := IF(
  @has_sequence_scope = 0,
  "ALTER TABLE division_tracking_sequences ADD COLUMN tracking_scope VARCHAR(16) NOT NULL DEFAULT 'INCOMING' AFTER tracking_date",
  'SELECT 1'
);
PREPARE add_sequence_scope_stmt FROM @add_sequence_scope_sql;
EXECUTE add_sequence_scope_stmt;
DEALLOCATE PREPARE add_sequence_scope_stmt;

UPDATE division_tracking_sequences
SET tracking_scope = 'INCOMING'
WHERE tracking_scope IS NULL
   OR TRIM(tracking_scope) = '';

SET @sequence_pk_cols := (
  SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',')
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'division_tracking_sequences'
    AND CONSTRAINT_NAME = 'PRIMARY'
);

SET @fix_sequence_pk_sql := IF(
  COALESCE(@sequence_pk_cols, '') <> 'division_id,tracking_date,tracking_scope',
  'ALTER TABLE division_tracking_sequences DROP PRIMARY KEY, ADD PRIMARY KEY (division_id, tracking_date, tracking_scope)',
  'SELECT 1'
);
PREPARE fix_sequence_pk_stmt FROM @fix_sequence_pk_sql;
EXECUTE fix_sequence_pk_stmt;
DEALLOCATE PREPARE fix_sequence_pk_stmt;

SET @has_document_scope := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'document_division_tracking'
    AND COLUMN_NAME = 'tracking_scope'
);

SET @add_document_scope_sql := IF(
  @has_document_scope = 0,
  "ALTER TABLE document_division_tracking ADD COLUMN tracking_scope VARCHAR(16) NOT NULL DEFAULT 'INCOMING' AFTER division_id",
  'SELECT 1'
);
PREPARE add_document_scope_stmt FROM @add_document_scope_sql;
EXECUTE add_document_scope_stmt;
DEALLOCATE PREPARE add_document_scope_stmt;

UPDATE document_division_tracking
SET tracking_scope = 'INCOMING'
WHERE tracking_scope IS NULL
   OR TRIM(tracking_scope) = '';
