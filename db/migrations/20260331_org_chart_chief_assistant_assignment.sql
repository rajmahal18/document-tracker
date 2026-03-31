ALTER TABLE users
  ADD COLUMN chief_assistant_user_id INT NULL AFTER authority_role,
  ADD CONSTRAINT fk_users_chief_assistant_user
    FOREIGN KEY (chief_assistant_user_id) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE;

CREATE INDEX idx_users_chief_assistant_user_id ON users (chief_assistant_user_id);
