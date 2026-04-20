CREATE TABLE IF NOT EXISTS principal_assistants (
  principal_user_id INT NOT NULL,
  assistant_user_id INT NOT NULL,
  assigned_by_user_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (principal_user_id, assistant_user_id),
  KEY idx_principal_assistants_assistant (assistant_user_id),
  KEY idx_principal_assistants_principal (principal_user_id),
  CONSTRAINT fk_principal_assistants_principal
    FOREIGN KEY (principal_user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_principal_assistants_assistant
    FOREIGN KEY (assistant_user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_principal_assistants_assigned_by
    FOREIGN KEY (assigned_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO principal_assistants (principal_user_id, assistant_user_id)
SELECT id, chief_assistant_user_id
FROM users
WHERE chief_assistant_user_id IS NOT NULL
  AND chief_assistant_user_id > 0;
