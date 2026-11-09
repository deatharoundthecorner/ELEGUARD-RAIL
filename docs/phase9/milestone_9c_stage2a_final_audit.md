# EleGuard Rail — Milestone 9C Stage 2A: Technical Audit Report

**Project:** EleGuard Rail  
**Milestone:** 9C — Temporal & Seasonal Intelligence  
**Stage:** 2A — Temporal Service Layer and Validation Foundation ONLY  
**Audit Scope:** Strict Read-Only Independent Technical Audit  
**Operational Database:** `eleguard_rail` on MariaDB `127.0.0.1:3308`  
**Git Baseline Commit:** `15cf56a` (`feat(phase9): implement Milestone 9C Stage 2A temporal service layer and validation foundation`)  
**Audit Date:** 2026-11-09  

---

## AUDIT VERDICT

### **VERDICT: PASS — Stage 2A technically accepted.**

**Summary of Conclusions:**
1. **Implementation Conformance:** `TemporalService.php` and `ReportService.php` strictly satisfy all specifications defined in the Milestone 9C technical design and refinement documents.
2. **Backward Compatibility:** All existing 10-parameter legacy callers (`public/reporter/submit_report.php`, `public/wildlife/submit_report.php`, regression test suites) remain 100% functional without code changes. Omitted temporal fields cleanly persist as `NULL` / `'unknown'`.
3. **Data Integrity & Zero Ingestion Date Fabrication:** Observation timestamps are never manufactured from `created_at`, unknown times are never coerced to midnight (`00:00:00`), and invalid temporal data is rejected prior to database persistence.
4. **GPS Timestamp Precision:** W3C Geolocation epoch timestamps (both milliseconds and seconds) are validated and converted accurately to `Asia/Colombo` MySQL `DATETIME`. Device hardware acquisition time is strictly segregated from biological sighting time.
5. **Operational Database Uncompromised:** Deep row-level, field-level, and primary-key comparison against `backup_before_9c_stage1.sql` confirmed that all 43 genuine historical reports and all 185 system logs remain completely unchanged.
6. **Repository Cleanliness:** Commit `15cf56a` is clean, contains zero secrets or database backups, and preserves complete deployment parity with XAMPP.

---

## 1. Inspection of Actual Implementation Code

The committed codebase was inspected directly from the working tree at commit `15cf56a`:

### 1.1 `app/services/TemporalService.php` (477 lines)
- **Timezone Anchor:** Constant `TIMEZONE = 'Asia/Colombo'` enforced across `DateTimeZone` instances.
- **Diurnal Presets (`PRESETS`):**
  - `dawn`: `05:30:00` reference, $\pm 60$ min uncertainty (interval 04:30 – 06:30)
  - `morning`: `09:00:00` reference, $\pm 90$ min uncertainty (interval 07:30 – 10:30)
  - `afternoon`: `14:30:00` reference, $\pm 90$ min uncertainty (interval 13:00 – 16:00)
  - `dusk`: `18:15:00` reference, $\pm 45$ min uncertainty (interval 17:30 – 19:00)
  - `night`: `22:00:00` reference, $\pm 120$ min uncertainty (interval 20:00 – 00:00)
- **Core Methods:**
  - `validateDate($dateStr)`: Regex format enforcement (`YYYY-MM-DD`), calendar validation via `checkdate()`, and future-date rejection against `Asia/Colombo` current date.
  - `validateTime($timeStr)`: Normalizes `HH:MM` and `HH:MM:SS` into standard 24-hour format (`HH:MM:SS`), bounding hours [00–23], minutes [00–59], and seconds [00–59].
  - `validateDateTimeNotFuture($dateStr, $timeStr)`: Validates composite date-time in `Asia/Colombo` with a 60-second transmission grace window.
  - `parseGpsTimestamp($rawTimestamp)`: Detects milliseconds ($\ge 10^{11}$) vs seconds, enforces bounds ($1577836800 \le t \le \text{now} + 300\text{s}$), and maps to `Asia/Colombo` `YYYY-MM-DD HH:MM:SS`.
  - `validateAndNormalizeTemporalInput(array $input)`: Central validation engine returning structured `['valid' => bool, 'errors' => array, 'data' => array]`.

### 1.2 `app/services/ReportService.php` (221 lines)
- **Signature Expansion:** Expanded to accept `$temporalData` (associative array or scalar date) followed by positional temporal arguments (`$sightingTime`, `$sightingPrecision`, `$sightingWindow`, `$sightingUncertainty`, `$gpsCapturedAt`).
- **Input Normalization:** Automatically routes associative array inputs (`is_array($temporalData)`) or maps positional parameters into `$temporalInput`.
- **Validation Guard:** Calls `TemporalService::validateAndNormalizeTemporalInput()`. If invalid, immediately returns `false` before executing the SQL `INSERT`, preventing partial or corrupted records.
- **SQL Execution:** Binds all 7 temporal columns to `elephant_reports`. Defaults cleanly to `NULL` / `'unknown'` for legacy callers.
- **Operational Risk Scoring:** Invariant preserved. Continues passing `$reportTime = date('Y-m-d H:i:s')` to `RiskService::calculateRiskScore()`.
- **AI Processing:** Invariant preserved. Asynchronous/synchronous invocation of `AiDetectionService::processReportAi()` remains identical.

### 1.3 `tests/test_milestone_9c_stage2a_temporal_service.php` (623 lines)
- Implements 10 test groups and 49 discrete assertions.
- Sets up an isolated test database (`eleguard_rail_test`) by pre-configuring `putenv("DB_NAME=eleguard_rail_test")` before loading application config.
- Automatically cleans up and drops `eleguard_rail_test` on completion.

---

## 2. ReportService Compatibility Audit

Comparison between commit `a5dbb4a` and commit `15cf56a`:

```
diff --git a/app/services/ReportService.php b/app/services/ReportService.php
-    public static function submitReport($sectionId, $reportedBy, $elephantCount, $locationDesc, $urgencyLevel, $description, $imagePath = null, $latitude = null, $longitude = null, $accuracy = null)
+    public static function submitReport(
+        $sectionId, $reportedBy, $elephantCount, $locationDesc, $urgencyLevel, $description,
+        $imagePath = null, $latitude = null, $longitude = null, $accuracy = null,
+        $temporalData = null, $sightingTime = null, $sightingPrecision = null,
+        $sightingWindow = null, $sightingUncertainty = null, $gpsCapturedAt = null
+    )
```

### 2.1 Verification Checklist
1. **Existing Callers Compatibility:** All existing calls in `public/reporter/submit_report.php`, `public/wildlife/submit_report.php`, `tests/test_ai_integration.php`, and `tests/test_gps_and_workflows.php` supply between 6 and 10 arguments. Since parameters 11 through 16 default to `null`, existing callers invoke the legacy branch without warnings or behavioral changes.
2. **Positional Parameters Meaning:** Parameters 1 through 10 maintain their exact positions, types, sanitization (`$latParam`, `$lngParam`, `$accParam`), and meaning.
3. **Associative Interface Correctness:** Passing an associative array to the 11th parameter (`$temporalData = ['sighting_date' => ..., 'sighting_time' => ...]`) is detected via `is_array($temporalData)` and validated properly.
4. **Legacy Submissions:** Omitted temporal parameters produce:
   - `sighting_date = NULL`
   - `sighting_time = NULL`
   - `sighting_time_precision = 'unknown'`
   - `sighting_time_window = NULL`
   - `sighting_time_uncertainty_minutes = NULL`
   - `sighting_timezone = 'Asia/Colombo'`
   - `gps_captured_at = NULL`
5. **Partial Persistence Prevention:** If temporal validation fails, `submitReport()` logs the error and terminates at line 82 (`return false;`). The database `INSERT` at line 109 is never reached.
6. **Report Status & AI Inference:** `report_status` is initialized to `'Pending'`. If `$imagePath` is provided, `AiDetectionService::processReportAi()` executes exactly as before. AI failures do not roll back report persistence.
7. **Transaction & Rollback Safety:** The operation is an atomic single-statement `INSERT`. If the database throws a PDOException, no orphan rows are created.

### 2.2 Method Signature Ambiguity Finding
- **Observation:** In the expanded signature, parameter 11 is named `$temporalData`. When called positionally, this parameter acts as `$sightingDate`.
- **Assessment:** While `is_array($temporalData)` resolves this dynamically without runtime errors, callers in Stage 2B should strictly standardize on passing the associative array interface (`$temporalData = [...]`) rather than supplying up to 16 positional arguments.

---

## 3. TemporalService Audit

### 3.1 Date Validation (`TemporalService::validateDate`)
- **Format Enforcement:** Correctly enforces `YYYY-MM-DD` via regex.
- **Calendar Boundary Checks:** Uses `checkdate((int)$m, (int)$d, (int)$y)` which correctly rejects invalid dates such as `2026-02-30`, `2026-04-31`, and `2026-13-01`.
- **Future Date Rejection:** Compares against `TemporalService::getNow()->format('Y-m-d')` in `Asia/Colombo`. Any date after today is rejected.

### 3.2 Time Validation (`TemporalService::validateTime`)
- Normalizes `H:i` (e.g. `'8:30'`) to `08:30:00`.
- Normalizes `00:00` and `00:00:00` cleanly to midnight `00:00:00`.
- Rejects hours $\ge 24$ (e.g. `'24:00'`) and minutes $\ge 60$ (e.g. `'14:60'`).

### 3.3 Approximate Presets & Bounds
- Reference times and uncertainties match the approved 9C design matrix exactly:
  - Dawn: `05:30:00`, $\pm 60$ min
  - Morning: `09:00:00`, $\pm 90$ min
  - Afternoon: `14:30:00`, $\pm 90$ min
  - Dusk: `18:15:00`, $\pm 45$ min
  - Night: `22:00:00`, $\pm 120$ min
- **Intra-Day Future Preset Protection:** If a user submits a report for today with a preset whose reference time has not yet occurred (e.g. submitting a `dusk` sighting at 10:00 AM today), `validateDateTimeNotFuture()` rejects the submission.

### 3.4 Custom Approximate Window
- Custom window requires user-defined reference time and uncertainty.
- Validates that uncertainty falls within $[15, 180]$ minutes. Rejects values $< 15$ min (e.g. 10 min) or $> 180$ min (e.g. 240 min).

### 3.5 Unknown-Time NULL Handling vs Known Date
- **Known Date + Unknown Time:** Sighting date is preserved (`sighting_date = '2026-11-01'`), while `sighting_time` is set strictly to `NULL` (never coerced to `00:00:00`).
- **Completely Unknown:** Both `sighting_date` and `sighting_time` remain `NULL`.
- **Conflict Prevention:** If a submission declares `precision = 'unknown'` but supplies a clock time, window, or uncertainty, `TemporalService` rejects the conflicting payload.

---

## 4. GPS Timestamp Audit

Independent verification of `TemporalService::parseGpsTimestamp()` using known fixed timestamps:

| Test Case | Input Value | Input Interpretation | Expected Output (Asia/Colombo, UTC+05:30) | Audit Result |
| :--- | :--- | :--- | :--- | :---: |
| **Fixed Epoch Seconds** | `1700000000` | `2023-11-14 22:13:20 UTC` | `2023-11-15 03:43:20` | **MATCH** |
| **Fixed Epoch Milliseconds** | `1700000000000` | W3C DOMTimeStamp | `2023-11-15 03:43:20` | **MATCH** |
| **Boundary Epoch Seconds** | `1609459200` | `2021-01-01 00:00:00 UTC` | `2021-01-01 05:30:00` | **MATCH** |
| **Boundary Epoch Milliseconds**| `1609459200000` | W3C DOMTimeStamp | `2021-01-01 05:30:00` | **MATCH** |
| **Implausible Early Timestamp**| `1577836799` | 1 second prior to 2020-01-01 | Rejected (`implausibly early`) | **MATCH** |
| **Far Future Timestamp** | `2000000000` | Year 2033 | Rejected (`in the future`) | **MATCH** |
| **Null / Empty Input** | `null` / `''` | Optional GPS capture omitted | `NULL` | **MATCH** |
| **Corrupted String** | `'not-a-timestamp'` | Malformed string | Rejected (`Malformed GPS timestamp`) | **MATCH** |

### Findings:
- Double conversion is prevented by checking `is_numeric()` and dividing by 1000 only when value $\ge 10^{11}$.
- Storage convention is consistent: MySQL `DATETIME` (`YYYY-MM-DD HH:MM:SS`) in `Asia/Colombo`.
- `gps_captured_at` is completely segregated from `sighting_date` and `sighting_time` in both service logic and SQL parameters.

---

## 5. Operational Database Integrity Audit

An exhaustive, read-only audit of the operational database `eleguard_rail` was performed against the pre-migration backup (`backup_before_9c_stage1.sql`):

### 5.1 Verification Methodology
1. `backup_before_9c_stage1.sql` was restored into a temporary comparison database (`eleguard_rail_audit_temp_comp`).
2. Every column across all pre-existing tables was compared row-by-row and primary-key-by-primary-key against live `eleguard_rail`.
3. The temporary database was dropped immediately after verification. Zero writes were performed on `eleguard_rail`.

### 5.2 Table Comparison Results

| Table Name | Pre-Migration Backup Rows | Live Operational Rows | Missing PKs | Extra PKs | Field Diffs | Timestamp Diffs | Audit Result |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `alerts` | 15 | 15 | 0 | 0 | 0 | 0 | **100% MATCH** |
| `driver_acknowledgements` | 13 | 13 | 0 | 0 | 0 | 0 | **100% MATCH** |
| `elephant_reports` | 43 | 43 | 0 | 0 | 0 | 0 | **100% MATCH** |
| `hotspots` | 4 | 4 | 0 | 0 | 0 | 0 | **100% MATCH** |
| `incident_history` | 3 | 3 | 0 | 0 | 0 | 0 | **100% MATCH** |
| `railway_sections` | 4 | 4 | 0 | 0 | 0 | 0 | **100% MATCH** |
| `system_logs` | 185 | 185 | 0 | 0 | 0 | 0 | **100% MATCH** |
| `users` | 5 | 5 | 0 | 0 | 0 | 0 | **100% MATCH** |

### 5.3 Operational Temporal Invariant Verification
Querying all 43 records in operational `eleguard_rail.elephant_reports`:
- `sighting_date IS NULL`: **43 / 43 (100%)**
- `sighting_time IS NULL`: **43 / 43 (100%)**
- `sighting_time_precision = 'unknown'`: **43 / 43 (100%)**
- `sighting_time_window IS NULL`: **43 / 43 (100%)**
- `sighting_time_uncertainty_minutes IS NULL`: **43 / 43 (100%)**
- `gps_captured_at IS NULL`: **43 / 43 (100%)**
- Zero synthetic test records or audit logs remain in `eleguard_rail`.

---

## 6. Test Quality Review

Analysis of [`tests/test_milestone_9c_stage2a_temporal_service.php`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/tests/test_milestone_9c_stage2a_temporal_service.php):

### 6.1 Genuine Behavioral Assertions
- Tests verify end-to-end database persistence by querying persisted records (`SELECT * FROM elephant_reports WHERE location_description = ...`) and asserting individual field values (`sighting_date`, `sighting_time`, `sighting_time_precision`, `uncertainty`, `gps_captured_at`).
- Asserts that legacy submissions insert `NULL` rather than accidental defaults (e.g. midnight).
- Verifies that validation failure prevents insertion into the database (`assert(!$futureRow)`).

### 6.2 Test Architecture Evaluation
- **Isolation Mechanism:** Uses `putenv("DB_NAME=eleguard_rail_test")` prior to loading config. This guarantees the PDO singleton connects exclusively to the isolated test database.
- **Teardown & Cleanup:** Test clone is dropped cleanly in teardown.

### 6.3 Edge Cases Identified for Future Test Hardening
1. **Leap Year Boundary:** `checkdate()` handles leap years internally, but adding explicit assertions for `2024-02-29` (valid) and `2025-02-29` (invalid) would enhance documentation.
2. **Circular Midnight Spanning:** In Stage 3 analytics, the `night` preset ($22:00 \pm 120$ min, spanning 20:00 to 00:00) crosses midnight into the subsequent calendar day. This will require circular modulo interval queries in analytical SQL.
3. **Exact Custom Boundary:** Custom uncertainty boundaries are tested at 10m (rejected) and 45m (accepted); explicit assertions at exact boundaries 15m and 180m should be included during Stage 2B testing.

---

## 7. Git & Deployment Verification

- **Current Branch:** `main`
- **HEAD Commit:** `15cf56a5a2dc0098b61a10258ea2cc78c70b9d75`
- **Working Tree:** Clean (zero unstaged modifications)
- **Commit History:** Linear and intact (`15cf56a` $\rightarrow$ `a5dbb4a` $\rightarrow$ `66c0b27`)
- **Sensitive Data & Backups:** Confirmed untracked. No `.env` files or database backups are committed.
- **Desktop/XAMPP Parity:** Cryptographic SHA-256 comparison verified 100% bit-for-bit match on all Stage 2A files:
  - `app/services/TemporalService.php`: `EA11E1A11D14AB4132BADC887A3696195C36AD570B2223F42505381BA62A8F73`
  - `app/services/ReportService.php`: `A7E9D1F8E4DDA1FABDEA4F5998CBA99F2F9CA06B6C8833BC0D93EC9E8DE87451`
  - `tests/test_milestone_9c_stage2a_temporal_service.php`: `063E53C997A2C06AEED44E83902D28793709EDEF9898C4065F6DF7B2261A8F02`
  - `tests/test_milestone_9c_stage1_foundation.php`: `210EBCBC4A69FE40E2DD27AE7F7B1734DBFE0EE3FC3FD7B0B34DD32335A6248E`

---

## 8. Final Recommendation & Next Steps

Milestone 9C Stage 2A is **technically sound, fully backwards compatible, thoroughly tested, and preserves 100% operational data integrity**.

Stage 2A is complete. **Do not begin Stage 2B until explicit authorization is granted.**
