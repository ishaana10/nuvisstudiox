-- ============================================================
-- Migration 010: the default project is members-only
-- Adds every existing user to the default project as a member so nobody loses access
-- when projectDefaultOpenAccess stops being the default. Safe to run more than once.
-- Remove users you do not want in the default project afterwards (Projects > Members).
-- ============================================================
INSERT INTO nu_project_members (pm_project_id, pm_user_id, pm_role)
SELECT p.project_id, u.usr_id, 'member'
FROM nu_projects p
JOIN nu_users u ON 1 = 1
WHERE p.project_is_default = 1
  AND NOT EXISTS (
      SELECT 1 FROM nu_project_members m
      WHERE m.pm_project_id = p.project_id AND m.pm_user_id = u.usr_id
  );
