-- PERSONAL DEADLINE SUPPORT FOR ROUTES
-- Run after the branch rearchitecture migration.

ALTER TABLE routes
  ADD COLUMN IF NOT EXISTS personal_deadline_at DATETIME NULL AFTER remarks,
  ADD KEY idx_routes_personal_deadline_at (personal_deadline_at);
