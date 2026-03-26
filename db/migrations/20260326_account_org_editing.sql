ALTER TABLE users
  ADD COLUMN IF NOT EXISTS username VARCHAR(80) NULL AFTER full_name,
  ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER email;

-- Optional hardening after backfill:
-- ALTER TABLE users ADD UNIQUE KEY uq_users_username (username);
