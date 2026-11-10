# EleGuard Rail — Milestone 9C: Stage 2B Independent Technical & Security Audit
## Frontend Temporal Reporting Integration & Review Display Audit

**Document:** `docs/phase9/milestone_9c_stage2b_final_audit.md`  
**Phase:** 9 — Historical Elephant Sighting Intelligence  
**Milestone:** 9C — Temporal & Seasonal Intelligence  
**Stage:** 2B — Reporting Form Integration & Temporal Review Display  
**Audited Git Commit:** `bcf08ea37a6f61b42ba74be5eee65cd36ebd5757`  
**Operational Environment:** MariaDB 10.4.32 (Port 3308), Apache 2.4.58 (Port 80), PHP 8.2.12  
**Audit Date:** 2026-11-10  
**Audit Mode:** STRICT READ-ONLY (Zero source code modifications; zero operational database writes)  

---

## 1. Executive Summary & Audit Verdict

### Overall Verdict: **PASS WITH MINOR CORRECTIONS**

Stage 2B has successfully established interactive temporal reporting, hardware GPS timestamp capture, and human-readable temporal telemetry across EleGuard Rail's public reporter and wildlife officer interfaces.

The database foundation remains completely uncompromised, zero operational database writes occurred, and all 43 genuine historical records remain strictly preserved with unknown temporal metadata.

However, this independent audit has identified **one logic defect in `public/wildlife/submit_report.php`** and **one analytical limitation in `TemporalService::calculateReportingDelay()`** that should be resolved before proceeding to Milestone 9C Stage 3:

1. **Defect 1 (Wildlife Submission ID Resolution):** In `public/wildlife/submit_report.php` (line 122), `$newReportId = (int)$success;` incorrectly casts the boolean `true` returned by `ReportService::submitReport()` to integer `1`. Consequently, official ranger submissions attempt to elevate **Report #1** to `'Verified'` status rather than the newly inserted report.
2. **Defect 2 (Approximate Sighting Delay Precision):** In `TemporalService::calculateReportingDelay()`, approximate observations (e.g. Morning, Dusk) calculate reporting latency using only the central reference time, producing misleading minute-level precision (e.g. `"Delayed by 2h 0m"`) rather than an uncertainty-bounded interval (e.g. `"Delayed ~2h (±90m)"`).

---

## 2. Git Verification & Repository Cleanliness

### 2.1 Branch & Commit Identification
- **Branch:** `main`
- **Commit:** `bcf08ea37a6f61b42ba74be5eee65cd36ebd5757`
- **Commit Subject:** `feat(phase9): implement Milestone 9C Stage 2B reporting form integration and temporal review display`
- **Working Tree Status:** Clean (`git status` reports nothing to commit).

### 2.2 Complete Commit Diff Inspection
Inspection of `git diff --name-only HEAD~1 HEAD` confirmed exactly eight files were included in the commit:
1. `app/services/TemporalService.php` (Display helpers and formatting logic)
2. `public/reporter/submit_report.php` (Reporter temporal observation module & GPS epoch capture)
3. `public/wildlife/submit_report.php` (Ranger temporal observation module & GPS epoch capture)
4. `public/wildlife/reports.php` (Wildlife review segregation & reports table columns)
5. `public/reporter/my_reports.php` (Reporter dashboard observation time column)
6. `tests/test_milestone_9c_stage2b_frontend_integration.php` (34-assertion integration test suite)
7. `docs/phase9/milestone_9c_stage2a_final_audit.md` (Stage 2A audit report)
8. `docs/phase9/milestone_9c_stage2b_implementation_report.md` (Stage 2B implementation report)

### 2.3 Secret, Backup & Test Artifact Inspection
- **Credentials & API Keys:** Verified zero hardcoded credentials, API keys, passwords, or session tokens committed.
- **Database Backups:** Confirmed no `.sql` dumps or test databases committed.
- **Environment Files:** Confirmed no `.env` or configuration overrides tracked.

---

## 3. Reporter Workflow Security Audit

Inspection of [`public/reporter/submit_report.php`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/public/reporter/submit_report.php):

| Security Check | Requirement | Audit Evidence | Finding |
|---|---|---|---|
| **Authentication & RBAC** | Submitter must be logged in with role `reporter` | Line 6: `$allowed_roles = 'reporter';`<br>Line 7: includes `dashboard_header.php`, invoking `Auth::check()` and `role_required.php`. Unauthenticated users redirected to `login.php`; unauthorized roles redirected to respective dashboards. | **PASS** |
| **CSRF Protection** | All POST submissions must validate anti-CSRF token | Line 38: `validate_csrf();`<br>Line 148: renders `csrf_field()`. Handled via `Session::verifyCsrfToken()`; invalid requests abort with `403 Forbidden`. | **PASS** |
| **Server-Side Validation** | Client inputs must be validated on server | Lines 87–93: `TemporalService::validateAndNormalizeTemporalInput($temporalPayload)` validates dates, times, uncertainty bounds, future skew, and diurnal windows before database execution. | **PASS** |
| **Tamper Resistance on Status** | Submitter cannot inject or elevate status to `Verified` | `ReportService::submitReport()` (Line 104) hardcodes `'Pending'` directly in the SQL INSERT: `:urgency_level, :risk_score, 'Pending', :description...`. Submitter has no parameter control over report status. | **PASS** |
| **Hidden Input Hardening** | Hidden inputs (`gps_accuracy`, `gps_timestamp`) cannot bypass validation | `gps_accuracy` is sanitized with `is_numeric()`. `gps_timestamp` is sanitized through `TemporalService::validateGpsTimestamp()` rejecting pre-2020 and future timestamps. | **PASS** |
| **Strict NULL Preservation** | Date unknown / time unknown must remain strictly `NULL` | When `sighting_date_unknown` is checked, server forces `sighting_date = NULL`, `sighting_time = NULL`, and `precision = 'unknown'`. Midnight (`00:00:00`) is never fabricated. | **PASS** |
| **Observation vs GPS Segregation** | Device GPS capture timestamp cannot masquerade as sighting time | `gps_timestamp` is persisted strictly into `gps_captured_at`. `sighting_date` and `sighting_time` are populated strictly from user-reported sighting inputs. | **PASS** |
| **Image & Location Preserved** | Existing file upload and coordinate handling intact | `Upload::saveReportImage()` and `Validation::coordinate()` continue to run without regression. | **PASS** |

---

## 4. Wildlife Officer Authorization & Logic Defect Investigation

Inspection of [`public/wildlife/submit_report.php`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/public/wildlife/submit_report.php):

### 4.1 Authorization & Workflow Verification
- **Role Restriction:** Line 6 restricts access via `$allowed_roles = 'wildlife_officer';`. Unauthorized roles (reporters, drivers, controllers) are intercepted by `role_required.php` and redirected.
- **CSRF Enforcement:** Line 38 calls `validate_csrf()`.
- **Server-Side Temporal Validation:** Lines 87–93 execute `TemporalService::validateAndNormalizeTemporalInput()`.
- **Audit Logging:** Line 128 logs ranger submissions via `Auth::logActivity()`.

### 4.2 Defect Investigation: Report ID Resolution on Ranger Elevation

In `public/wildlife/submit_report.php` (lines 120–128):

```php
// Existing code in public/wildlife/submit_report.php:
if ($success) {
    // Get the inserted ID to update status to 'Verified' immediately
    $newReportId = (int)$success;
    
    // 2. Elevate to 'Verified' because submitter is a Wildlife Officer
    ReportService::updateStatus($newReportId, 'Verified', $currentUser['id']);
    
    // Log ranger submit action
    Auth::logActivity($currentUser['id'], 'Ranger Sighting Filed', "Ranger filed sighting #{$newReportId} in section #{$sectionId} (Confirmed).");
```

#### Defect Root Cause
`ReportService::submitReport()` returns a boolean (`true` or `false` from PDOStatement `$stmt->execute(...)`), NOT the primary key ID of the new report.
Casting `(int)$success` yields integer `1` when `$success === true`.

Consequently, when a wildlife officer submits a sighting through the web UI:
1. The new sighting is inserted with `id = X` and initial status `'Pending'`.
2. `ReportService::updateStatus(1, 'Verified', ...)` is called.
3. **Report #1 (historical baseline report from 2024) has its status overwritten to `'Verified'`, while the new report remains `'Pending'`.**

#### Code Comparison with Baseline Commit `15cf56a`
In commit `15cf56a`, line 83 read:
```php
$db = Database::getInstance()->getConnection();
$newReportId = $db->lastInsertId();
ReportService::updateStatus((int)$newReportId, 'Verified', $currentUser['id']);
```
During Stage 2B refactoring, `$db->lastInsertId()` was inadvertently replaced with `(int)$success`.

#### Recommended Drop-In Correction (Do NOT implement during this read-only audit)
In `public/wildlife/submit_report.php` (line 122):
```php
// Replace:
$newReportId = (int)$success;

// With:
$db = Database::getInstance()->getConnection();
$newReportId = (int)$db->lastInsertId();
```

---

## 5. Temporal Display Correctness Audit

Inspection of [`TemporalService.php`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/app/services/TemporalService.php), [`public/wildlife/reports.php`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/public/wildlife/reports.php), and [`public/reporter/my_reports.php`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/public/reporter/my_reports.php):

### 5.1 Presentation Fidelity

| Requirement | Implementation Evidence | Finding |
|---|---|---|
| **Exact Observation Time** | Rendered as `"8 Nov 2026 at 14:30 (Exact ±5m)"` with green `<span class="badge badge-success">Exact (±5m)</span>` badge. | **PASS** |
| **Approximate Presets** | Rendered as `"8 Nov 2026, approx. Dusk (~18:15 ±45m)"` with warning `<span class="badge badge-warning">Approx (Dusk ±45m)</span>` badge. Never presented as exact clock time. | **PASS** |
| **Unknown Time Representation** | Formatted as `"8 Nov 2026 (Time unrecorded)"` or `"Date and time unrecorded (Historical / Unknown)"`. Never casts null to `00:00:00` midnight. | **PASS** |
| **Unknown Date Representation** | Explicitly identified as `"Date unknown"` or `"Date and time unrecorded"`. | **PASS** |
| **Display Segregation** | Review panel cleanly separates `Observation Time` (Field Event) from `Date Filed` (System Ingestion). | **PASS** |
| **GPS Acquisition Telemetry** | Displays `gps_captured_at` with `"Hardware GPS"` indicator vs `"Manual coordinates"`. Segregated from observation time. | **PASS** |
| **AI Processing Telemetry** | Displays `ai_processed_at` with processing status badge. Segregated from filing and observation time. | **PASS** |

### 5.2 Reporting Delay Limitation in `calculateReportingDelay()`

#### Current Behavior
In `app/services/TemporalService.php` (lines 603–648):
```php
if (!empty($report['sighting_time'])) {
    $sightingStr = $report['sighting_date'] . ' ' . $report['sighting_time'];
    $sightingTs = strtotime($sightingStr);
    $diffSeconds = $createdAt - $sightingTs;
    $diffMinutes = (int)round($diffSeconds / 60);
    // ...
    $hours = floor($diffMinutes / 60);
    $mins = $diffMinutes % 60;
    $label = "Delayed by {$hours}h {$mins}m";
```

#### Analytical Limitation
For approximate observations, `sighting_time` is the central reference time (e.g. `09:00:00` for Morning, with uncertainty `±90 min`).
If a Morning sighting is submitted at 11:00 AM:
- Current output: `"Delayed by 2h 0m"`.
- This conveys false exactness. The true biological observation occurred sometime between 07:30 and 10:30, meaning the true reporting delay is between 30 minutes and 3 hours 30 minutes ($[30\text{m}, 210\text{m}]$).

#### Recommended Interval-Aware Calculation
```php
if ($report['sighting_time_precision'] === self::PRECISION_APPROXIMATE && !empty($report['sighting_time_uncertainty_minutes'])) {
    $unc = (int)$report['sighting_time_uncertainty_minutes'];
    $minDelay = max(0, $diffMinutes - $unc);
    $maxDelay = $diffMinutes + $unc;
    
    $minH = floor($minDelay / 60); $minM = $minDelay % 60;
    $maxH = floor($maxDelay / 60); $maxM = $maxDelay % 60;
    
    $label = "Delayed approx. {$minH}h {$minM}m – {$maxH}h {$maxM}m (±{$unc}m)";
}
```

---

## 6. Geolocation & Hardware GPS Telemetry Audit

Inspection of client-side JavaScript in `public/reporter/submit_report.php` (lines 560–660) and `public/wildlife/submit_report.php` (lines 570–670):

1. **W3C Timestamp Acquisition:**
   - Callback extracts `position.timestamp` (epoch milliseconds) and assigns it directly to hidden input `gps_timestamp`.
   - Verified that `position.coords.latitude`, `position.coords.longitude`, and `position.coords.accuracy` are captured concurrently.
2. **Manual Override Reset Logic:**
   - Event listeners attached to `latInput.addEventListener('input')` and `lngInput.addEventListener('input')` explicitly execute:
     `if (gpsTimestampInput) gpsTimestampInput.value = '';`
   - Verified that manually typing or altering coordinates immediately clears device timestamp metadata.
3. **Coordinate Clearing Button:**
   - Click listener on `btnClearLocation` explicitly executes:
     `if (gpsTimestampInput) gpsTimestampInput.value = '';`
   - Resets status text and hides accuracy displays.
4. **Backend Plausibility Validation:**
   - Backend `TemporalService::validateGpsTimestamp()` rejects timestamps prior to 2020-01-01 and future timestamps beyond 300 seconds client clock skew.

---

## 7. Operational Database Forensic Verification

### 7.1 Independent Comparison Methodology
A fresh, temporary database `eleguard_rail_audit_cmp` was created on MariaDB port 3308.
The baseline backup file [`database/backups/backup_before_9c_stage1.sql`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/database/backups/backup_before_9c_stage1.sql) was restored into it.

Every single table, row, and column was compared primary-key-by-primary-key against live `eleguard_rail`.

### 7.2 Forensic Comparison Results Across All 8 Tables

| Table | Backup Rows | Live Operational Rows | Primary Key | Columns Compared | Missing Rows | Extra Rows | Field Mismatches |
|---|---|---|---|---|---|---|---|
| `elephant_reports` | 43 | 43 | `id` | 18 | 0 | 0 | **0** |
| `system_logs` | 185 | 185 | `id` | 5 | 0 | 0 | **0** |
| `alerts` | 15 | 15 | `id` | 10 | 0 | 0 | **0** |
| `driver_acknowledgements` | 13 | 13 | `id` | 6 | 0 | 0 | **0** |
| `hotspots` | 4 | 4 | `id` | 6 | 0 | 0 | **0** |
| `railway_sections` | 4 | 4 | `id` | 7 | 0 | 0 | **0** |
| `users` | 5 | 5 | `id` | 7 | 0 | 0 | **0** |
| `incident_history` | 3 | 3 | `id` | 7 | 0 | 0 | **0** |

**Forensic Finding:** All 8 tables match `backup_before_9c_stage1.sql` row-for-row and byte-for-byte with **ZERO DISCREPANCIES**.

### 7.3 Historical Elephant Reports Temporal Verification
Inspection of all 43 historical records in `eleguard_rail.elephant_reports`:
- `sighting_date IS NOT NULL`: **0**
- `sighting_time IS NOT NULL`: **0**
- `sighting_time_precision != 'unknown'`: **0**
- `sighting_time_window IS NOT NULL`: **0**
- `sighting_time_uncertainty_minutes IS NOT NULL`: **0**
- `sighting_timezone != 'Asia/Colombo'`: **0**
- `gps_captured_at IS NOT NULL`: **0**

All 43 genuine historical records remain strictly unmigrated with unknown observation metadata.

### 7.4 Investigation of "Ran reset system_logs to 185 rows" Command

#### The Command
`DELETE FROM system_logs WHERE id IN (186, 187); ALTER TABLE system_logs AUTO_INCREMENT = 186;`

#### Investigation Findings
1. **What generated IDs 186 and 187?**  
   During Step 6 of Stage 2B verification, an automated test script (`scratch/test_http_endpoints.php`) performed live HTTP POST requests against Apache (`http://localhost/eleguard-rail/public/login.php`) to verify that the reporter and wildlife officer web sessions rendered the new temporal UI elements.
   In `public/login.php`, successful logins invoke `Auth::logActivity($user['id'], 'User Login', 'User successfully logged in.')`.
   Because Apache was serving from the live database, this created two transient user login log entries:
   - ID 186: User 5 (`reporter@eleguard.lk`), Action: `User Login`
   - ID 187: User 4 (`wildlife@eleguard.lk`), Action: `User Login`
2. **What did the cleanup command do?**  
   It deleted solely those two automated test login entries (`id IN (186, 187)`) and reset the `AUTO_INCREMENT` pointer to 186.
3. **Forensic Verification of Rows 1 to 185:**  
   Rows 1 to 185 in `eleguard_rail.system_logs` are 100% identical to `backup_before_9c_stage1.sql`.
   The highest primary key is ID 185 (`[2026-11-08 20:59:53] User 4 | Report Updated - Report #84 status changed to 'Rejected'`).
   No genuine historical log entries were deleted, shifted, or modified.
4. **Cleanup of Comparison Database:**  
   `eleguard_rail_audit_cmp` was dropped immediately after comparison.

---

## 8. Test Quality & Coverage Gap Analysis

Review of the 34 assertions in `tests/test_milestone_9c_stage2b_frontend_integration.php`:

### 8.1 Strengths
- Operates strictly against isolated database clone `eleguard_rail_test`.
- Validates all 5 diurnal presets and custom window uncertainty limits.
- Validates browser geolocation epoch millisecond conversion.
- Validates operational database zero-write invariant.

### 8.2 Missing Test Coverage & Edge Cases
The following 7 test scenarios are currently absent from `tests/test_milestone_9c_stage2b_frontend_integration.php`:
1. **Unauthorized Ranger Submission:** Does not assert that an unauthenticated user or reporter cannot post to `public/wildlife/submit_report.php`.
2. **Reporter Attempts to Submit Verified Status:** Does not test whether a maliciously injected `$_POST['report_status'] = 'Verified'` or `$_POST['status'] = 'Verified'` is rejected or ignored.
3. **Tampered Temporal Hidden Fields:** Does not test submitting corrupted `gps_timestamp` strings (e.g. negative integers, SQL injection payloads, or dates in year 2099) through form POST simulation.
4. **Approximate Reporting Delay Verification:** Only tests exact observation delay; does not assert on approximate diurnal preset delay labels.
5. **Stale GPS Timestamp Clearing:** Client-side event listeners that clear `gps_timestamp` on input modification are not verified via automated headless integration tests.
6. **Missing Observation Date:** Does not assert that leaving `sighting_date = ''` while `sighting_date_unknown` is unselected triggers a validation error.
7. **Midnight Boundaries:** Does not test exact midnight (`00:00:00`) or Night window ($20:00 - 00:00$) crossing midnight through the complete form submission pipeline.

---

## 9. Required Minor Corrections Before Stage 3 Authorization

Before authorizing **Stage 3 (Risk Engine Temporal Integration)**, the following minor corrections should be applied:

```diff
--- a/public/wildlife/submit_report.php
+++ b/public/wildlife/submit_report.php
@@ -119,7 +119,8 @@ if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 
         if ($success) {
             // Get the inserted ID to update status to 'Verified' immediately
-            $newReportId = (int)$success;
+            $db = Database::getInstance()->getConnection();
+            $newReportId = (int)$db->lastInsertId();
             
             // 2. Elevate to 'Verified' because submitter is a Wildlife Officer
             ReportService::updateStatus($newReportId, 'Verified', $currentUser['id']);
```

```diff
--- a/app/services/TemporalService.php
+++ b/app/services/TemporalService.php
@@ -623,6 +623,17 @@ class TemporalService {
                 return [
                     'minutes' => $diffMinutes,
                     'is_delayed' => false,
                     'is_realtime' => true,
                     'label' => "Real-time (< {$diffMinutes}m delay)"
                 ];
             }
 
+            if ($report['sighting_time_precision'] === self::PRECISION_APPROXIMATE && !empty($report['sighting_time_uncertainty_minutes'])) {
+                $unc = (int)$report['sighting_time_uncertainty_minutes'];
+                $minM = max(0, $diffMinutes - $unc);
+                $maxM = $diffMinutes + $unc;
+                $label = "Delayed ~" . round($diffMinutes / 60, 1) . "h (approx. {$minM}m – {$maxM}m)";
+            } else {
                 $hours = floor($diffMinutes / 60);
                 $mins = $diffMinutes % 60;
                 if ($hours < 24) {
                     $label = "Delayed by {$hours}h {$mins}m";
                 } else {
                     $days = floor($hours / 24);
                     $remHours = $hours % 24;
                     $label = "Delayed by {$days}d {$remHours}h";
                 }
+            }
```

---

## 10. Audit Sign-off

- **Audited Commit:** `bcf08ea`
- **Audit Mode:** Strict Read-Only (no source code modified during audit)
- **Operational Database Integrity:** 100% Verified against `backup_before_9c_stage1.sql` (43 reports, 185 logs, 0 drift)
- **Verdict:** **PASS WITH MINOR CORRECTIONS**
- **Action:** STOP. Awaiting user review and authorization.
