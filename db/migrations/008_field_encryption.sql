-- Migration 008: Field Encryption - Modify columns to store CipherSweet encrypted payloads
ALTER TABLE nu_users
  MODIFY COLUMN usr_2fa_secret LONGTEXT NULL,
  MODIFY COLUMN usr_2fa_backup_codes LONGTEXT NULL;
