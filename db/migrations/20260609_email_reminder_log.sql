CREATE TABLE IF NOT EXISTS email_reminder_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  document_id INT NOT NULL,
  route_id INT NULL,
  reminder_date DATE NOT NULL,
  reminder_slot ENUM('morning','afternoon') NOT NULL,
  effective_deadline_at DATETIME NULL,
  sent_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_reminder_once (user_id, document_id, reminder_date, reminder_slot),
  KEY idx_email_reminder_user_slot (user_id, reminder_date, reminder_slot),
  KEY idx_email_reminder_document (document_id),
  KEY idx_email_reminder_route (route_id),
  CONSTRAINT fk_email_reminder_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_email_reminder_document
    FOREIGN KEY (document_id) REFERENCES documents(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_email_reminder_route
    FOREIGN KEY (route_id) REFERENCES routes(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
