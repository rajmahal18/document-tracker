SET @event_type_definition := (
  SELECT COLUMN_TYPE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'document_events'
    AND COLUMN_NAME = 'event_type'
  LIMIT 1
);

SET @needs_child_setup_completed := IF(
  @event_type_definition IS NULL,
  0,
  IF(LOCATE("'child_setup_completed'", @event_type_definition) > 0, 0, 1)
);

SET @alter_event_type_sql := IF(
  @needs_child_setup_completed = 1,
  "ALTER TABLE document_events MODIFY COLUMN event_type ENUM('created','sent','received','forwarded','updated','released','archived','cancelled','child_setup_completed') NOT NULL",
  'SELECT 1'
);

PREPARE alter_event_type_stmt FROM @alter_event_type_sql;
EXECUTE alter_event_type_stmt;
DEALLOCATE PREPARE alter_event_type_stmt;
