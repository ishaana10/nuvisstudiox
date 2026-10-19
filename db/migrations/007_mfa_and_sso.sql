-- Migration 007: MFA Enforcement & SSO Support

-- Add MFA columns to nu_users
ALTER TABLE nu_users
  ADD COLUMN IF NOT EXISTS usr_2fa_secret      VARCHAR(64)  NULL,
  ADD COLUMN IF NOT EXISTS usr_2fa_enabled     TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS usr_2fa_backup_codes TEXT        NULL,
  ADD COLUMN IF NOT EXISTS usr_mfa_required    TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS usr_mfa_enrolled_at DATETIME     NULL;

-- Global MFA setting
INSERT INTO nu_system_settings (setting_key, setting_value)
VALUES ('mfa_enforcement', 'optional')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- SSO Providers Table
CREATE TABLE IF NOT EXISTS nu_sso_providers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    type            ENUM('saml','oidc') NOT NULL,
    enabled         TINYINT(1) NOT NULL DEFAULT 0,
    is_default      TINYINT(1) NOT NULL DEFAULT 0,
    config_json     LONGTEXT NOT NULL,
    attribute_map   LONGTEXT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SSO Sessions Table
CREATE TABLE IF NOT EXISTS nu_sso_sessions (
    id              VARCHAR(64) PRIMARY KEY,
    user_id         VARCHAR(36) NOT NULL,
    provider_id     INT NOT NULL,
    idp_session_id  VARCHAR(255) NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
