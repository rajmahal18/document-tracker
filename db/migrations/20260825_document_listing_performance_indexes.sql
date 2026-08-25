-- Document listing performance indexes.
-- Safe to run repeatedly; each index is added only if missing.
-- Targets repeated correlated lookups in public/documents.php without changing data or behavior.

DELIMITER $$

DROP PROCEDURE IF EXISTS add_document_listing_performance_indexes$$

CREATE PROCEDURE add_document_listing_performance_indexes()
BEGIN
  DECLARE v_count INT DEFAULT 0;

  SELECT COUNT(*) INTO v_count
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'routes'
    AND INDEX_NAME = 'idx_routes_doc_received_order';
  IF v_count = 0 THEN
    ALTER TABLE routes
      ADD KEY idx_routes_doc_received_order (document_id, received_at, id);
  END IF;

  SELECT COUNT(*) INTO v_count
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'routes'
    AND INDEX_NAME = 'idx_routes_doc_kind_cancel_received_order';
  IF v_count = 0 THEN
    ALTER TABLE routes
      ADD KEY idx_routes_doc_kind_cancel_received_order (document_id, route_kind, cancelled_at, received_at, id);
  END IF;

  SELECT COUNT(*) INTO v_count
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'routes'
    AND INDEX_NAME = 'idx_routes_doc_cancel_to_sent_order';
  IF v_count = 0 THEN
    ALTER TABLE routes
      ADD KEY idx_routes_doc_cancel_to_sent_order (document_id, cancelled_at, to_user_id, sent_at, id);
  END IF;

  SELECT COUNT(*) INTO v_count
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'routes'
    AND INDEX_NAME = 'idx_routes_doc_cancel_received_by_order';
  IF v_count = 0 THEN
    ALTER TABLE routes
      ADD KEY idx_routes_doc_cancel_received_by_order (document_id, cancelled_at, received_by_user_id, received_at, id);
  END IF;

  SELECT COUNT(*) INTO v_count
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'routes'
    AND INDEX_NAME = 'idx_routes_doc_cancel_to_section_order';
  IF v_count = 0 THEN
    ALTER TABLE routes
      ADD KEY idx_routes_doc_cancel_to_section_order (document_id, cancelled_at, to_section_id, to_user_id, sent_at, id);
  END IF;

  SELECT COUNT(*) INTO v_count
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'document_events'
    AND INDEX_NAME = 'idx_events_doc_id';
  IF v_count = 0 THEN
    ALTER TABLE document_events
      ADD KEY idx_events_doc_id (document_id, id);
  END IF;
END$$

CALL add_document_listing_performance_indexes()$$

DROP PROCEDURE IF EXISTS add_document_listing_performance_indexes$$

DELIMITER ;
