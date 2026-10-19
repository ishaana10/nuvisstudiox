# Nuvis Testing Strategy & CI Security Gates

Nuvis employs a comprehensive automated testing suite and CI security pipeline aligned with SOC 2 (Type II) and ISO 27001 control requirements.

---

## 1. Test Architecture & Structure

Tests are organized into dedicated test suites under `tests/`:

```
tests/
├── bootstrap.php
├── Unit/             # Unit tests (Auth, Encryption, TOTP, Multi-Project)
├── Integration/      # Database & CRUD flows (AuditLogging, MultiProject)
├── Security/         # Security tests (AuthBypass, SqlInjection, Csrf, SessionSecurity)
├── Api/              # REST API gateway endpoints
└── fixtures/         # Mock datasets and schema setups
```

---

## 2. Security Test Suites

The security test suite (`tests/Security/`) validates critical controls:

- **`AuthBypassTest`**: Ensures unauthenticated users cannot access protected modules.
- **`SqlInjectionTest`**: Verifies parameterized queries and SQL placeholder escaping (`resolveHashes`).
- **`CsrfTest`**: Validates 64-character token generation and validation.
- **`SessionSecurityTest`**: Asserts strict session flags (`HttpOnly`, `Secure`, `SameSite=Strict`, `nuvisstudioxssid`).

---

## 3. Running Tests Locally

- **Execute All TestSuites**:
  ```bash
  composer test
  # or: vendor/bin/phpunit
  ```

- **Run Specific Testsuite**:
  ```bash
  vendor/bin/phpunit --testsuite Security
  ```

- **Run Complete Check Suite**:
  ```bash
  composer check
  ```

---

## 4. CI Security Gates (GitHub Actions)

On every push and pull request, GitHub Actions executes:

1. **PHP Matrix Testing**: Validates PHP 8.1, 8.2, and 8.3 compatibility.
2. **PHPUnit Execution**: Runs all test suites (`Unit`, `Security`, `Integration`, `Api`).
3. **Dependency Vulnerability Scan**: Executes `composer audit --no-dev`.
4. **Trivy Static Security Scan**: Scans codebase for high/critical security findings.
