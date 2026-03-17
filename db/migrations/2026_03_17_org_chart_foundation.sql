-- ORG CHART FOUNDATION
-- Adds optional user metadata for organizational-chart rendering and future authority rules.

START TRANSACTION;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS official_title VARCHAR(100) NULL AFTER full_name,
  ADD COLUMN IF NOT EXISTS authority_role VARCHAR(50) NOT NULL DEFAULT 'staff' AFTER official_title,
  ADD COLUMN IF NOT EXISTS last_seen_at DATETIME NULL AFTER authority_role;

UPDATE users
SET authority_role = CASE
  WHEN role = 'admin' THEN 'admin'
  WHEN is_chief = 1 THEN 'section_head'
  ELSE 'staff'
END
WHERE authority_role IS NULL
   OR authority_role = '';

COMMIT;
