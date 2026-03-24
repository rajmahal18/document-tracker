START TRANSACTION;

ALTER TABLE document_attachments
  ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED NULL AFTER document_id,
  ADD KEY idx_document_attachments_branch (branch_id);

ALTER TABLE document_attachments
  ADD CONSTRAINT fk_document_attachments_branch
    FOREIGN KEY (branch_id) REFERENCES document_branches(id)
    ON DELETE SET NULL;

COMMIT;
