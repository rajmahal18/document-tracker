SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM document_attachments;
DELETE FROM document_events;
DELETE FROM document_participants;
DELETE FROM document_user_visibility;
DELETE FROM document_qr_tokens;
DELETE FROM routes;
DELETE FROM document_branches;
DELETE FROM document_division_tracking;
DELETE FROM documents;
DELETE FROM document_tracking_sequences;
DELETE FROM division_tracking_sequences;

ALTER TABLE document_attachments AUTO_INCREMENT = 1;
ALTER TABLE document_events AUTO_INCREMENT = 1;
ALTER TABLE document_participants AUTO_INCREMENT = 1;
ALTER TABLE document_user_visibility AUTO_INCREMENT = 1;
ALTER TABLE document_qr_tokens AUTO_INCREMENT = 1;
ALTER TABLE routes AUTO_INCREMENT = 1;
ALTER TABLE document_branches AUTO_INCREMENT = 1;
ALTER TABLE document_division_tracking AUTO_INCREMENT = 1;
ALTER TABLE documents AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;