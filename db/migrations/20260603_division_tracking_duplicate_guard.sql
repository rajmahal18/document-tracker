SET @has_duplicate_override_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'document_division_tracking'
    AND COLUMN_NAME = 'is_duplicate_override'
);

SET @add_duplicate_override_col_sql := IF(
  @has_duplicate_override_col = 0,
  "ALTER TABLE document_division_tracking ADD COLUMN is_duplicate_override TINYINT(1) NOT NULL DEFAULT 0 AFTER is_manual",
  'SELECT 1'
);
PREPARE add_duplicate_override_col_stmt FROM @add_duplicate_override_col_sql;
EXECUTE add_duplicate_override_col_stmt;
DEALLOCATE PREPARE add_duplicate_override_col_stmt;

SET @has_duplicate_guard_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'document_division_tracking'
    AND COLUMN_NAME = 'duplicate_guard_no'
);

SET @add_duplicate_guard_col_sql := IF(
  @has_duplicate_guard_col = 0,
  "ALTER TABLE document_division_tracking ADD COLUMN duplicate_guard_no VARCHAR(32) DEFAULT NULL AFTER tracking_no",
  'SELECT 1'
);
PREPARE add_duplicate_guard_col_stmt FROM @add_duplicate_guard_col_sql;
EXECUTE add_duplicate_guard_col_stmt;
DEALLOCATE PREPARE add_duplicate_guard_col_stmt;

UPDATE document_division_tracking
SET duplicate_guard_no = CASE
  WHEN COALESCE(is_duplicate_override, 0) = 1 THEN NULL
  ELSE tracking_no
END
WHERE
  (COALESCE(is_duplicate_override, 0) = 1 AND duplicate_guard_no IS NOT NULL)
  OR
  (COALESCE(is_duplicate_override, 0) <> 1 AND COALESCE(duplicate_guard_no, '') <> tracking_no);

UPDATE document_division_tracking ddt
JOIN (
  SELECT
    division_id,
    COALESCE(tracking_scope, 'INCOMING') AS tracking_scope,
    tracking_no,
    MIN(id) AS keeper_id
  FROM document_division_tracking
  GROUP BY division_id, COALESCE(tracking_scope, 'INCOMING'), tracking_no
  HAVING COUNT(*) > 1
) dup
  ON dup.division_id = ddt.division_id
 AND dup.tracking_scope = COALESCE(ddt.tracking_scope, 'INCOMING')
 AND dup.tracking_no = ddt.tracking_no
SET
  ddt.is_duplicate_override = CASE WHEN ddt.id = dup.keeper_id THEN 0 ELSE 1 END,
  ddt.duplicate_guard_no = CASE WHEN ddt.id = dup.keeper_id THEN ddt.tracking_no ELSE NULL END;

UPDATE document_division_tracking
SET duplicate_guard_no = CASE
  WHEN COALESCE(is_duplicate_override, 0) = 1 THEN NULL
  ELSE tracking_no
END
WHERE
  (COALESCE(is_duplicate_override, 0) = 1 AND duplicate_guard_no IS NOT NULL)
  OR
  (COALESCE(is_duplicate_override, 0) <> 1 AND COALESCE(duplicate_guard_no, '') <> tracking_no);

SET @has_old_tracking_unique := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'document_division_tracking'
    AND INDEX_NAME = 'uq_doc_division_tracking_no'
);

SET @drop_old_tracking_unique_sql := IF(
  @has_old_tracking_unique > 0,
  'ALTER TABLE document_division_tracking DROP INDEX uq_doc_division_tracking_no',
  'SELECT 1'
);
PREPARE drop_old_tracking_unique_stmt FROM @drop_old_tracking_unique_sql;
EXECUTE drop_old_tracking_unique_stmt;
DEALLOCATE PREPARE drop_old_tracking_unique_stmt;

SET @has_scope_guard_unique := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'document_division_tracking'
    AND INDEX_NAME = 'uq_doc_division_tracking_scope_guard'
);

SET @add_scope_guard_unique_sql := IF(
  @has_scope_guard_unique = 0,
  'ALTER TABLE document_division_tracking ADD UNIQUE INDEX uq_doc_division_tracking_scope_guard (division_id, tracking_scope, duplicate_guard_no)',
  'SELECT 1'
);
PREPARE add_scope_guard_unique_stmt FROM @add_scope_guard_unique_sql;
EXECUTE add_scope_guard_unique_stmt;
DEALLOCATE PREPARE add_scope_guard_unique_stmt;
