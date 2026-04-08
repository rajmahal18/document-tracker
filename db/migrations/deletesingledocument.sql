SET FOREIGN_KEY_CHECKS = 0;

SET @doc_id = ?; 

DELETE FROM document_attachments WHERE document_id = @doc_id;
DELETE FROM document_events WHERE document_id = @doc_id;
DELETE FROM document_participants WHERE document_id = @doc_id;
DELETE FROM document_user_visibility WHERE document_id = @doc_id;
DELETE FROM document_qr_tokens WHERE document_id = @doc_id;
DELETE FROM routes WHERE document_id = @doc_id;
DELETE FROM document_division_tracking WHERE document_id = @doc_id;
DELETE FROM document_branches WHERE document_id = @doc_id;
DELETE FROM documents WHERE id = @doc_id LIMIT 1;

SET FOREIGN_KEY_CHECKS = 1;