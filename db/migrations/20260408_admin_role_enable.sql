-- Promote an existing user to system admin.
-- Replace the email below before running.

UPDATE users
SET role = 'admin',
    authority_role = 'admin',
    is_chief = 0,
    is_active = 1
WHERE email = 'your.email@mpw.local'
LIMIT 1;
