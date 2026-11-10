# EleGuard Rail — Milestone 9C Stage 4C.2
## Database Safety, Test Isolation & Analytics SQL Hardening

### Document Metadata
- **Project:** EleGuard Rail (Sri Lanka Wildlife-Railway Collision Early Warning Platform)
- **Milestone:** 9C — Historical Intelligence & Analytics Query Layer
- **Stage:** 4C.2 — Database Safety, Test Isolation & Analytics SQL Hardening
- **Repository Location:** `C:\Users\user\Desktop\Railway\eleguard-rail`
- **XAMPP Mirror:** `C:\xampp\htdocs\eleguard-rail`
- **Baseline Git Commits:**
  - Stage 4A: `f6491ca`
  - Stage 4B: `36d4c95`
  - Stage 4B.1: `ad07d87`
  - Stage 4C: `e23467d`
  - Stage 4C.1: `42df90b`
- **Operational Database:** `eleguard_rail` (MariaDB port 3308)
- **Isolated Test Database:** `eleguard_rail_test`
- **Audit & Implementation Date:** 10 November 2026
- **Status:** Complete, Verified & Tested

---

## 1. Executive Summary & Objective

Milestone 9C Stage 4C.2 delivers a comprehensive security and architectural hardening of EleGuard Rail to eliminate the risk of accidental operational database writes during testing and to close potential evasion vectors in the read-only analytics SQL query engine.

### Core Deliverables
1. **Central Test Suite Bootstrap (`tests/bootstrap.php`):** Implements fail-closed environment validation, automated test database setup, and strict teardown safeguards.
2. **Database Gateway Isolation Guard (`app/core/Database.php`):** Prevents automated test scripts from connecting to `eleguard_rail`, failing closed if `DB_NAME` is missing, empty, or targeting production.
3. **Legacy Test Suite Hardening:** Hardened legacy runners (`test_ai_integration.php`, `test_gps_and_workflows.php`, and Stage 1–3E suites) with bootstrap inclusion and safe teardown guards.
4. **Hardened Analytics SQL Execution Guard (`AnalyticsService::executeReadOnlyQuery`):** Implements a narrow, explicitly defined SQL policy rejecting multi-statements, file export operations (`INTO OUTFILE`/`DUMPFILE`), variable assignments (`@var :=`), locking clauses (`FOR UPDATE`/`LOCK IN SHARE MODE`), and unsafe function calls (`SLEEP`, `BENCHMARK`), while safely normalizing SQL comments.
5. **Security Test Suite (`tests/test_milestone_9c_stage4c2_security.php`):** 37 automated security and regression test cases passing at 100%.

---

## 2. Review of Stage 4C.1 Findings

In Stage 4C.1, an independent audit revealed:
1. `tests/test_ai_integration.php` (created in Milestone 8C) lacked environment variable definitions. When executed without a shell environment variable override, `Database::getInstance()->getConnection()` silently defaulted to `eleguard_rail`, writing two test records (IDs 186 and 187) into `system_logs`.
2. Although the records were subsequently removed and the table was verified to match pre-test snapshots bit-for-bit (0 mismatches across all 185 rows), the audit identified an architectural vulnerability: **tests could silently fall back to operational databases if not guarded by application code.**
3. Test teardown methods in earlier test suites executed raw `DROP DATABASE IF EXISTS \`{$testDbName}\`` without verifying that `$testDbName` was not `eleguard_rail`.

---

## 3. Database Connection Safety & Test Isolation Architecture

### 3.1 Central Test Bootstrap (`tests/bootstrap.php`)
A central bootstrap was introduced to govern all test executions:
- **`ELEGUARD_TEST_ENVIRONMENT` constant:** Explicitly marks the runtime as a testing session.
- **Fail-Closed Target Validation (`validateTestDatabaseTarget`):**
  - Throws `RuntimeException` if `DB_NAME` is missing, null, or empty.
  - Throws fatal `RuntimeException` if `DB_NAME` equals `'eleguard_rail'` (case-insensitive).
  - Throws `RuntimeException` if `DB_NAME` is any database other than `'eleguard_rail_test'`.
- **Automatic Test Database Provisioning (`ensureTestDatabaseExists`):**
  - Automatically verifies that `eleguard_rail_test` exists before tests run.
  - If missing, clones the schema and baseline data from `eleguard_rail` without modifying the operational database.
- **Protected Teardown Guard (`safeTeardownTestDatabase`):**
  - Strictly prevents test teardown routines from targeting `eleguard_rail`.
  - Rejects any database name other than `eleguard_rail_test`.

### 3.2 Database Core Guard (`app/core/Database.php`)
The central singleton gateway `Database::__construct()` was fortified with a defense-in-depth check:
```php
$script = isset($_SERVER['SCRIPT_FILENAME']) ? str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME']) ?: $_SERVER['SCRIPT_FILENAME']) : '';
$isTestContext = defined('ELEGUARD_TEST_ENVIRONMENT') || 
                 (PHP_SAPI === 'cli' && strpos($script, '/tests/') !== false);

if ($isTestContext) {
    $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? (defined('DB_NAME') ? DB_NAME : null));
    if (empty($dbName)) {
        throw new RuntimeException("DATABASE SAFETY VIOLATION: DB_NAME is missing or empty in test execution. Tests must explicitly target 'eleguard_rail_test'.");
    }
    if (strtolower(trim($dbName)) === 'eleguard_rail') {
        throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Automated test attempted to connect to operational database 'eleguard_rail'. Execution halted.");
    }
    if (strtolower(trim($dbName)) !== 'eleguard_rail_test') {
        throw new RuntimeException("DATABASE SAFETY VIOLATION: Database '{$dbName}' is not an approved test database. Tests must target 'eleguard_rail_test'.");
    }
}
```
In addition, `Database::resetInstance()` was added to allow tests to safely reset the singleton instance between isolation assertions.

---

## 4. Analytics SQL Execution Hardening

The query runner in `app/services/AnalyticsService.php` was strengthened to eliminate keyword bypasses, comment obfuscation, and side-effecting operations.

### 4.1 SQL Security Policy Implementation

```
   Raw SQL Input
         │
         ▼
[ Step 1: Input Validation ] ──> Reject non-string or empty queries
         │
         ▼
[ Step 2: Comment Stripping ] ──> Remove /* ... */, -- ..., and # ...
         │
         ▼
[ Step 3: Multi-Statement Ban ] ──> Reject if ';' appears before end-of-string
         │
         ▼
[ Step 4: Statement Form ] ──> First word must strictly be SELECT, SHOW, DESCRIBE, or EXPLAIN
         │
         ▼
[ Step 5: File Export Ban ] ──> Reject INTO OUTFILE, INTO DUMPFILE, LOAD_FILE
         │
         ▼
[ Step 6: Variable Assign Ban ] ──> Reject @var := and SET @var
         │
         ▼
[ Step 7: Row-Locking Ban ] ──> Reject FOR UPDATE, FOR SHARE, LOCK IN SHARE MODE
         │
         ▼
[ Step 8: Unsafe Functions Ban ] ──> Reject SLEEP, BENCHMARK, GET_LOCK, SYS_EXEC, etc.
         │
         ▼
[ Step 9: Mutation Keywords Ban ] ──> Reject INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE, etc.
         │
         ▼
[ Step 10: Prepared Execution ] ──> PDO::prepare with emulated prepares disabled
```

### 4.2 Handling of Common Attack Vectors
1. **Multi-Statement Attacks (`SELECT 1; SELECT 2;`):**
   - Semicolons are prohibited except as an optional single trailing character. Any intermediate semicolon immediately triggers `RuntimeException`.
2. **Comment Obfuscation (`/* comment */ DROP TABLE users;`):**
   - Comments are stripped prior to lexical analysis. Disguised mutations fail the first-word boundary check (`SELECT|SHOW|DESCRIBE|EXPLAIN`) and are rejected.
3. **File System Exfiltration (`SELECT ... INTO OUTFILE '/tmp/dump'`):**
   - Explicitly caught and blocked by `\bINTO\s+(OUTFILE|DUMPFILE)\b`.
4. **Variable State Alteration (`SELECT @var := id FROM users`):**
   - Explicitly blocked by `(@\w+\s*:=|\bSET\s+@)`.
5. **Denial-of-Service & Timing Attacks (`SELECT SLEEP(10)`):**
   - Blocked by `\b(SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK)\s*\(`.

---

## 5. Database Account Isolation & Recommendations

While application-level guards provide immediate defense-in-depth, optimal enterprise isolation is achieved at the RDBMS privilege layer.

### 5.1 Proposed Dedicated Test Account
For automated testing environments, a dedicated MariaDB user account should be provisioned with privileges restricted exclusively to `eleguard_rail_test`:

```sql
-- Step 1: Create dedicated test user
CREATE USER IF NOT EXISTS 'eleguard_test'@'127.0.0.1' IDENTIFIED BY 'TestPassword_2026!';

-- Step 2: Grant full control strictly on test database
GRANT ALL PRIVILEGES ON `eleguard_rail_test`.* TO 'eleguard_test'@'127.0.0.1';

-- Step 3: Explicitly prohibit access to operational database
REVOKE ALL PRIVILEGES ON `eleguard_rail`.* FROM 'eleguard_test'@'127.0.0.1';

FLUSH PRIVILEGES;
```

**Security Implications:**
- Even if application code were compromised or an environment variable misconfigured, the MariaDB storage engine itself rejects any access to `eleguard_rail` with `ERROR 1044 (42000): Access denied`.
- Setup instructions can be added to CI/CD pipelines without impacting production credentials.

### 5.2 Proposed Read-Only Analytics Account
For production analytics execution:
```sql
CREATE USER IF NOT EXISTS 'eleguard_readonly'@'127.0.0.1' IDENTIFIED BY 'ReadOnly_2026!';
GRANT SELECT, SHOW VIEW ON `eleguard_rail`.* TO 'eleguard_readonly'@'127.0.0.1';
FLUSH PRIVILEGES;
```

---

## 6. Regression Testing & Security Verification

A dedicated security test suite ([`tests/test_milestone_9c_stage4c2_security.php`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/tests/test_milestone_9c_stage4c2_security.php)) was executed.

### 6.1 Test Suite Summary (37 / 37 Tests Passed)
```
========================================================================
EleGuard Rail — Milestone 9C Stage 4C.2 Test Suite
Database Safety, Test Isolation & Analytics SQL Hardening
========================================================================

--- 0. PRE-FLIGHT OPERATIONAL SAFETY AUDIT ---
  [PASS] 0.1 Operational DB elephant_reports baseline is 43
  [PASS] 0.2 Operational DB system_logs baseline is 185
  [PASS] 0.3 Operational DB alerts baseline is 15
  [PASS] 0.4 Operational Report #1 sighting time is NULL
  [PASS] 0.5 Operational Report #1 risk score baseline is Medium

--- 1. MISSING DB_NAME VALIDATION ---
  [PASS] 1.1 Missing DB_NAME strictly throws RuntimeException

--- 2. EMPTY DB_NAME VALIDATION ---
  [PASS] 2.1 Empty/whitespace DB_NAME strictly throws RuntimeException

--- 3. OPERATIONAL DB_NAME REJECTION ---
  [PASS] 3.1 Exact operational DB_NAME 'eleguard_rail' strictly blocked
  [PASS] 3.2 Case-insensitive operational DB_NAME 'ELEGUARD_RAIL' strictly blocked

--- 4. APPROVED TEST DB_NAME ACCEPTANCE ---
  [PASS] 4.1 Approved test DB_NAME 'eleguard_rail_test' validated successfully
  [PASS] 4.2 Unapproved database name strictly blocked

--- 5. TEST DATABASE CONNECTION FAILURE HANDLING ---
  [PASS] 5.1 Connection failure on unreachable port raises clean catchable exception

--- 6. MULTIPLE SQL STATEMENTS REJECTION ---
  [PASS] 6.1 Multiple SQL statements separated by semicolon strictly rejected

--- 7. SELECT INTO OUTFILE REJECTION ---
  [PASS] 7.1 SELECT INTO OUTFILE statement strictly rejected

--- 8. SELECT INTO DUMPFILE REJECTION ---
  [PASS] 8.1 SELECT INTO DUMPFILE statement strictly rejected

--- 9. SQL COMMENTS SAFE HANDLING ---
  [PASS] 9.1 Valid query with block comments executes cleanly after normalization
  [PASS] 9.2 Valid query with line comment executes cleanly after normalization
  [PASS] 9.3 Mutation hidden behind comments strictly rejected

--- 10. VARIABLE ASSIGNMENT REJECTION ---
  [PASS] 10.1 In-query variable assignment (@var :=) strictly rejected

--- 11. FOR UPDATE REJECTION ---
  [PASS] 11.1 FOR UPDATE row-locking clause strictly rejected

--- 12. LOCK IN SHARE MODE REJECTION ---
  [PASS] 12.1 LOCK IN SHARE MODE clause strictly rejected

--- 13. UNSAFE FUNCTION CALLS REJECTION ---
  [PASS] 13.1 SLEEP() function call strictly rejected
  [PASS] 13.2 BENCHMARK() function call strictly rejected

--- 14. NORMAL SELECT EXECUTION ---
  [PASS] 14.1 Standard analytical SELECT executes cleanly

--- 15. PARAMETERIZED SELECT EXECUTION ---
  [PASS] 15.1 Parameterized query executes cleanly with accurate parameter binding

--- 16. SHOW AND DESCRIBE EXECUTION ---
  [PASS] 16.1 SHOW TABLES query executes cleanly in read-only layer
  [PASS] 16.2 DESCRIBE table query executes cleanly in read-only layer

--- 17. LEGACY TEST BOOTSTRAP ENFORCEMENT ---
  [PASS] 17.1 Database singleton strictly refuses connection to operational DB in test context

--- 18. TEST CLEANUP TARGETING SAFETY ---
  [PASS] 18.1 safeTeardownTestDatabase strictly refuses to drop operational database
  [PASS] 18.2 safeTeardownTestDatabase strictly refuses to drop arbitrary unapproved database

--- 19. OPERATIONAL DATABASE WRITE PREVENTION ---
  [PASS] 19.1 Operational elephant_reports count completely untouched (43 == 43)
  [PASS] 19.2 Operational system_logs count completely untouched (185 == 185)
  [PASS] 19.3 Operational alerts count completely untouched (15 == 15)
  [PASS] 19.4 Operational Report #1 sighting time remains NULL
  [PASS] 19.5 Operational Report #1 risk score remains Medium

--- 20. ANALYTICS REGRESSION COMPATIBILITY ---
  [PASS] 20.1 getFullAnalyticsSummary completes cleanly through hardened SQL guard
  [PASS] 20.2 getTemporalAnalytics generates 24 hourly bins without error

========================================================================
STAGE 4C.2 SECURITY HARDENING TEST RESULTS
Total Tests:  37
Passed Tests: 37
Failed Tests: 0
========================================================================
```

### 6.2 Full Regression Suite Verification
All regression suites across the platform were executed through the hardened bootstrap:
- `tests/test_milestone_9c_stage4c2_security.php`: **37 / 37 Passed**
- `tests/test_milestone_9c_stage4c_analytics_service.php`: **73 / 73 Passed**
- `tests/test_milestone_9c_stage4b_duplicate_service.php`: **95 / 95 Passed**
- `tests/test_ai_integration.php` (Legacy Hardened): **25 / 25 Passed**
- `tests/test_gps_and_workflows.php` (Legacy Hardened): **19 / 19 Passed**

---

## 7. Operational Database Immutability Evidence

Before and after all test executions, the operational database `eleguard_rail` on port 3308 was verified:

| Table Name | Baseline | Post-Test Count | Status |
|---|---|---|---|
| `elephant_reports` | 43 | 43 | **Untouched** |
| `system_logs` | 185 | 185 | **Untouched** |
| `alerts` | 15 | 15 | **Untouched** |
| `incident_history` | 3 | 3 | **Untouched** |
| `hotspots` | 4 | 4 | **Untouched** |
| `railway_sections` | 4 | 4 | **Untouched** |
| `users` | 5 | 5 | **Untouched** |
| `driver_acknowledgements` | 13 | 13 | **Untouched** |

- **Report #1:** `{"id":1, "report_status":"Pending", "risk_score":"Medium", "sighting_date":null, "sighting_time":null, "updated_at":null}`.
- Zero mutations, zero deletions, and zero alert triggers occurred against `eleguard_rail`.
