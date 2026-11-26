-- ============================================================
-- Migration 011: tenant boundary on users, roles and projects
-- Every existing row belongs to the 'default' tenant. To host a second customer, give its users,
-- roles and projects the same tenant_code (lowercase letters, digits, _ and -).
-- The app also adds these columns itself on first use; running this by hand is optional.
-- ============================================================
ALTER TABLE `nu_users`    ADD COLUMN `tenant_code` VARCHAR(64) NOT NULL DEFAULT 'default';
ALTER TABLE `nu_roles`    ADD COLUMN `tenant_code` VARCHAR(64) NOT NULL DEFAULT 'default';
ALTER TABLE `nu_projects` ADD COLUMN `tenant_code` VARCHAR(64) NOT NULL DEFAULT 'default';
