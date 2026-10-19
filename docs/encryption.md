# Nuvis Encryption Architecture: TDE & Application-Level Field Encryption

Nuvis implements a multi-layered security architecture protecting data at rest across both database engines (Transparent Data Encryption) and at the application layer (Field-Level Authenticated Encryption).

---

## 1. Transparent Data Encryption (TDE)

TDE encrypts database files, redo logs, undo logs, binary logs, and temporary tablespaces at the I/O level without requiring code changes.

### MariaDB TDE Setup (Production)

1. **Create and Protect Key Files (Server Root)**:
   ```bash
   mkdir -p /etc/mysql/encryption
   cd /etc/mysql/encryption
   openssl rand -hex 32 > keyfile
   openssl rand -hex 128 > keyfile.key
   openssl enc -aes-256-cbc -md sha1 -k $(cat keyfile.key) -in keyfile -out keyfile.enc
   rm keyfile
   chown mysql:mysql keyfile.enc keyfile.key
   chmod 600 keyfile.enc keyfile.key
   ```

2. **Configure MariaDB (`/etc/my.cnf.d/encryption.cnf`)**:
   ```ini
   [mariadb]
   plugin-load-add = file_key_management.so
   file_key_management_filename = /etc/mysql/encryption/keyfile.enc
   file_key_management_filekey  = FILE:/etc/mysql/encryption/keyfile.key
   file_key_management_encryption_algorithm = AES_CBC

   innodb_encrypt_tables = FORCE
   innodb_encrypt_log = ON
   innodb_encryption_threads = 4
   innodb_default_encryption_key_id = 1
   encrypt_binlog = ON
   encrypt_tmp_disk_tables = ON
   encrypt_tmp_files = ON
   aria_encrypt_tables = ON
   ```

3. **Encrypt Existing Tables**:
   ```sql
   ALTER TABLE nu_users ENCRYPTION='Y';
   ```

---

## 2. Application-Level Field Encryption (`NuEncryption`)

Application-level field encryption utilizes **ParagonIE/CipherSweet** with **AES-256-GCM / FIPS-Crypto** authenticated encryption. Ciphertext is prefixed with `enc:` and stored securely.

### Configuration (`config.local.php` / `config.php`)

```php
$nuConfig['encryption'] = [
    'key' => getenv('NU_ENCRYPTION_KEY') ?: 'base64:' . base64_encode(random_bytes(32)),
    'enabled_fields' => [
        'nu_users' => ['usr_2fa_secret', 'usr_phone'],
    ],
];
```

### Usage API (`core/Encryption.php`)

- **Encrypt Field**:
  ```php
  $ciphertext = NuEncryption::encryptField('nu_users', 'usr_2fa_secret', $plaintext);
  ```

- **Decrypt Field**:
  ```php
  $plaintext = NuEncryption::decryptField('nu_users', 'usr_2fa_secret', $ciphertext);
  ```

- **Searchable Blind Index**:
  ```php
  $index = NuEncryption::getBlindIndex('nu_users', 'usr_email', 'admin@example.com');
  ```

---

## 3. Key Rotation Strategy (90–180 Days)

1. Generate a new master key: `NU_ENCRYPTION_KEY_NEW`.
2. Execute batch re-encryption script over encrypted columns:
   - Decrypt using active/legacy key.
   - Encrypt using new key engine.
3. Update production environment key configuration to `NU_ENCRYPTION_KEY_NEW`.
