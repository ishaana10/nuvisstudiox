-- ============================================================
-- Migration 009: Dashboard widgets v2 (MySQL 5.7+ / MariaDB 10.3+)
-- Keyed seeds + unique key (stops duplicate role widgets when install.sql
-- is re-run), locked/refresh columns, per-user prefs and settings tables.
-- NOTE: the app applies the same upgrade automatically via
-- core/WidgetSchema.php (NuWidgetSchema::ensure); this file is for manual runs.
-- ADD COLUMN IF NOT EXISTS is not available on MySQL 5.7: run each ALTER only
-- if SHOW COLUMNS FROM nu_dashboard_widgets LIKE '<column>' returns nothing.
-- ============================================================

ALTER TABLE `nu_dashboard_widgets` ADD COLUMN `widget_key` VARCHAR(64) NULL;
ALTER TABLE `nu_dashboard_widgets` ADD COLUMN `widget_locked` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `nu_dashboard_widgets` ADD COLUMN `widget_refresh` SMALLINT UNSIGNED NOT NULL DEFAULT 0;

-- Remove exact duplicate role widgets (keep the lowest widget_id of each group)
DELETE FROM `nu_dashboard_widgets`
 WHERE widget_user_id IS NULL
   AND widget_id NOT IN (
       SELECT keep_id FROM (
           SELECT MIN(widget_id) AS keep_id FROM `nu_dashboard_widgets`
            WHERE widget_user_id IS NULL
            GROUP BY widget_role, widget_type, widget_title, CAST(widget_config AS CHAR), widget_active
       ) AS k
   );

-- Give the seeded widgets their stable keys
UPDATE `nu_dashboard_widgets` SET widget_key = CASE
    WHEN widget_role = 'user'    AND widget_title = 'Available Forms'    THEN 'user.available_forms'
    WHEN widget_role = 'user'    AND widget_title = 'Available Reports'  THEN 'user.available_reports'
    WHEN widget_role = 'user'    AND widget_title = 'Quick Links'        THEN 'user.quick_links'
    WHEN widget_role = 'user'    AND widget_title = 'My Recent Activity' THEN 'user.my_recent_activity'
    WHEN widget_role = 'manager' AND widget_title = 'Total Users'        THEN 'manager.total_users'
    WHEN widget_role = 'manager' AND widget_title = 'Active Forms'       THEN 'manager.active_forms'
    WHEN widget_role = 'manager' AND widget_title = 'Activity This Week' THEN 'manager.activity_this_week'
    WHEN widget_role = 'manager' AND widget_title = 'Actions Breakdown'  THEN 'manager.actions_breakdown'
    ELSE widget_key END
 WHERE widget_user_id IS NULL AND widget_key IS NULL;

ALTER TABLE `nu_dashboard_widgets` ADD UNIQUE KEY `uq_role_widget` (`widget_role`, `widget_key`);

CREATE TABLE IF NOT EXISTS `nu_dashboard_user_prefs` (
    `pref_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` VARCHAR(64) NOT NULL,
    `widget_id` INT UNSIGNED NOT NULL,
    `hidden` TINYINT(1) NOT NULL DEFAULT 0,
    `position` SMALLINT NULL,
    `width` TINYINT UNSIGNED NULL,
    `height` TINYINT UNSIGNED NULL,
    `accent` VARCHAR(20) NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`pref_id`),
    UNIQUE KEY `uq_user_widget` (`user_id`, `widget_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nu_dashboard_user_settings` (
    `user_id` VARCHAR(64) NOT NULL,
    `settings` TEXT NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- UUID installs (userIdType = 'uuid'): user ids are strings. Convert tables created by earlier builds.
-- (Also done automatically by NuWidgetSchema::ensure(); safe to re-run.)
ALTER TABLE `nu_dashboard_widgets`        MODIFY `widget_user_id` VARCHAR(64) NULL DEFAULT NULL;
ALTER TABLE `nu_dashboard_user_prefs`     MODIFY `user_id` VARCHAR(64) NOT NULL;
ALTER TABLE `nu_dashboard_user_settings`  MODIFY `user_id` VARCHAR(64) NOT NULL;
