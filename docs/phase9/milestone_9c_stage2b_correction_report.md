# EleGuard Rail — Milestone 9C Stage 2B Corrective Patch Report
**Sri Lanka Wildlife-Railway Collision Early Warning Platform**  
*Document Version:* 1.0.0  
*Execution Date:* 2026-11-10  
*Baseline Commit:* `bcf08ea`  
*Target Branch:* `main`  
*Operational Database:* `eleguard_rail` (MariaDB port 3308)  

---

## Executive Summary

During the independent technical and security audit of **Milestone 9C Stage 2B (Frontend Temporal Reporting Integration & Review Display)**, two critical technical deficiencies were identified:
1. **Defective Wildlife Report ID Elevation (`public/wildlife/submit_report.php`):** The submission handler assumed `ReportService::submitReport()` returned the newly inserted record's primary key ID. In reality, `submitReport()` returned boolean `true`, which PHP type casting converted to `(int)true === 1`. This led to the immediate auto-elevation of historical **Report #1** to `'Verified'` rather than elevating the newly submitted ranger report.
2. **Misleading Exactness in Approximate Reporting Delay (`app/services/TemporalService.php`):** `TemporalService::calculateReportingDelay()` treated approximate reference times as exact timestamps, claiming precise latencies (e.g., `"Delayed by 2h 0m"`) despite observations being reported as diurnal windows with large uncertainty boundaries (e.g., $09:00 \pm 90\text{m}$).

This corrective patch resolves both issues completely, introduces non-breaking backward-compatible architectural APIs, implements interval-aware latency bounds, expands the automated test suite from 22 to 56 test assertions (covering all security, boundary, and edge conditions), verifies 100% operational database invariance, and establishes bit-for-bit SHA-256 parity between the desktop repository and the active XAMPP webroot.

---

## 1. Root-Cause Analysis: Wildlife Report ID Resolution

### 1.1 The Vulnerability
In `public/wildlife/submit_report.php` (lines 105–124):
```php
$success = ReportService::submitReport(
    (int)$sectionId,
    $currentUser['id'],
    (int)$elephantCount,
    $locationDescription,
    $urgencyLevel,
    $description,
    $imagePath,
    $latitude ?: null,
    $longitude ?: null,
    $gpsAccuracy ?: null,
    $tempNorm['data']
);

if ($success) {
    // Get the inserted ID to update status to 'Verified' immediately
    $newReportId = (int)$success;
    
    // Elevate to 'Verified' because submitter is a Wildlife Officer
    ReportService::updateStatus($newReportId, 'Verified', $currentUser['id']);
    ...
}
```

### 1.2 Underlying Mechanism
1. `ReportService::submitReport()` concludes with `return $success;` where `$success = $stmt->execute([...]);`.
2. In PDO, `$stmt->execute()` returns a `bool` (`true` on success, `false` on failure).
3. In PHP, `(int)true` strictly evaluates to integer `1`.
4. Therefore, whenever a wildlife officer filed any sighting report, the code immediately executed:
   ```php
   ReportService::updateStatus(1, 'Verified', $currentUser['id']);
   ```
5. This updated **Report #1** (the earliest historical baseline report), overwriting its status to `'Verified'`, stamping `updated_at`, and creating an erroneous audit log entry in `system_logs`.
6. Meanwhile, the actual newly inserted report remained in `'Pending'` status unverified.

---

## 2. Design and Implementation of the Resolution

### 2.1 Architectural Resolution in `ReportService.php`
To ensure clean segregation between primary key retrieval and legacy boolean expectations, a new dedicated method was introduced in `app/services/ReportService.php`:

```php
/**
 * Submits a new elephant sighting report and returns the inserted report primary key ID.
 * 
 * @return int|false Inserted report ID (> 0) on success, or false on failure/validation error.
 */
public static function submitReportWithId(
    $sectionId, 
    $reportedBy, 
    $elephantCount, 
    $locationDesc, 
    $urgencyLevel, 
    $description, 
    $imagePath = null, 
    $latitude = null, 
    $longitude = null, 
    $accuracy = null,
    $temporalData = null,
    $sightingTime = null,
    $sightingPrecision = null,
    $sightingWindow = null,
    $sightingUncertainty = null,
    $gpsCapturedAt = null
) {
    ...
    $success = $stmt->execute([...]);
    if (!$success) {
        return false;
    }

    $reportId = (int)$db->lastInsertId();
    if ($reportId <= 0) {
        return false;
    }

    // Trigger AI advisory inference asynchronously/synchronously if image is provided
    if (!empty($imagePath)) {
        try {
            AiDetectionService::processReportAi($reportId, $imagePath);
        } catch (Throwable $e) {
            error_log("EleGuard AI Detection Error on Report #{$reportId}: " . $e->getMessage());
        }
    }

    return $reportId;
}
```

### 2.2 Caller Remediation in `public/wildlife/submit_report.php`
`public/wildlife/submit_report.php` was updated to utilize `submitReportWithId()` accompanied by strict type and boundary guards:

```php
// 1. Submit Sighting using submitReportWithId() to reliably retrieve the new report ID
$newReportId = ReportService::submitReportWithId(
    (int)$sectionId,
    $currentUser['id'],
    (int)$elephantCount,
    $locationDescription,
    $urgencyLevel,
    $description,
    $imagePath,
    $latitude ?: null,
    $longitude ?: null,
    $gpsAccuracy ?: null,
    $tempNorm['data']
);

if ($newReportId !== false && $newReportId > 0) {
    // 2. Elevate ONLY the newly inserted report to 'Verified' because submitter is a Wildlife Officer
    ReportService::updateStatus($newReportId, 'Verified', $currentUser['id']);
    
    // Log ranger submit action
    Logger::log(
        $currentUser['id'],
        'Wildlife Sighting Submitted',
        "Wildlife Officer {$currentUser['name']} submitted and verified official sighting Report #{$newReportId}."
    );
    
    Session::flash('success', "Official wildlife sighting report #{$newReportId} successfully submitted and verified.");
    Router::redirect('wildlife/reports.php?id=' . $newReportId);
} else {
    $errors['submit'] = "Failed to submit report. Please review form data and retry.";
}
```

### 2.3 Failed Submission Invariant
If validation fails or database persistence is aborted:
- `submitReportWithId()` returns boolean `false`.
- The condition `($newReportId !== false && $newReportId > 0)` evaluates to `false`.
- `ReportService::updateStatus()` is **never** invoked.
- Report #1 and all historical data remain 100% untouched.

---

## 3. Backward-Compatibility Analysis for `submitReport()`

Existing application components throughout EleGuard Rail (including `public/reporter/submit_report.php`, legacy test suites, and internal batch ingestion utilities) expect `ReportService::submitReport()` to return a boolean (`true` or `false`).

To guarantee 100% backward compatibility:
```php
/**
 * Submits a new elephant sighting report and returns boolean true on success.
 * Preserves strict backward compatibility with all existing callers expecting a boolean.
 * 
 * @return bool True on success, false on failure.
 */
public static function submitReport(
    $sectionId, 
    ...
) {
    $result = self::submitReportWithId(
        $sectionId, 
        ...
    );

    return ($result !== false && $result > 0);
}
```
All existing callers continue to receive boolean `true` on success and boolean `false` on failure with zero breaking changes.

---

## 4. Approximate Reporting Delay & Interval Representation

### 4.1 Problem Identification
`TemporalService::calculateReportingDelay()` previously calculated ingestion delay simply as:
$$\Delta t = t_{\text{created\_at}} - t_{\text{sighting\_time}}$$
When an observer selected an approximate preset (such as Morning $09:00 \pm 90\text{m}$) and submitted at $11:00$, the system stated:
> `"Delayed by 2h 0m"`

This was scientifically flawed because the actual sighting could have occurred at $07:30$ ($3\text{h }30\text{m}$ latency) or $10:30$ ($30\text{m}$ latency). Furthermore, if submitted at $09:30$, the system falsely claimed a $30\text{m}$ delay when in fact the sighting window was still contemporaneous.

### 4.2 Mathematical Model & Implementation
In `app/services/TemporalService.php`:
1. **Helper Method:** Added `formatDurationMinutes($minutes)` to format durations cleanly (e.g., `0m`, `30m`, `1h`, `1h 30m`, `3h 30m`, `1d`, `1d 2h`).
2. **Interval Calculation for Approximate Precision:**
   - Central difference: $\text{diffMinutes} = \text{round}((t_{\text{created}} - t_{\text{ref}}) / 60)$
   - Lower bound: $\text{minMinutes} = \max(0, \text{diffMinutes} - \Delta_{\text{uncertainty}})$
   - Upper bound: $\text{maxMinutes} = \max(0, \text{diffMinutes} + \Delta_{\text{uncertainty}})$
3. **Contemporaneous vs Delayed Categorization:**
   - **Contemporaneous Case:** When $\text{diffMinutes} \le \Delta_{\text{uncertainty}}$ (filing occurs during or immediately after the observation window):
     - `is_delayed = false`
     - `is_realtime = true`
     - `label = "Contemporaneous observation window (filed within ±{$unc}m window)"`
   - **Delayed Case:** When $\text{diffMinutes} > \Delta_{\text{uncertainty}}$ (observation window has completely passed before filing):
     - `is_delayed = true`
     - `is_realtime = false`
     - `label = "Delayed ~{$centralFormatted} (approx. {$minFormatted} – {$maxFormatted}, ±{$unc}m)"`
4. **Structured Return Schema:**
   ```php
   [
       'minutes' => (int),
       'min_minutes' => (int|null),
       'max_minutes' => (int|null),
       'uncertainty_minutes' => (int|null),
       'is_delayed' => (bool),
       'is_realtime' => (bool),
       'label' => (string)
   ]
   ```
Review templates (e.g. `public/wildlife/reports.php`) render these bounds directly without misleading exactness.

---

## 5. Summary of Automated Test Suite Expansion

File: `tests/test_milestone_9c_stage2b_frontend_integration.php`  
Test Count: **56 Passed, 0 Failed** (expanded from 22 in baseline commit).

| Test Group | Assertions | Focus Areas |
|:---|:---:|:---|
| **Group 1: Display Formatting Helpers** | 12 | Exact/approx/preset/unknown formatting, badge rendering, `formatDurationMinutes` unit boundaries, interval-aware delay calculation, contemporaneous observation window detection. |
| **Group 2: Reporter Form Submission Workflow** | 9 | Geolocation GPS epoch ms conversion, presets, custom uncertainty, date-only without midnight coercion, unknown date/time, hardware GPS clearing, reporter status escalation tampering rejection. |
| **Group 3: Wildlife Officer Form Submission & ID Resolution** | 7 | `submitReportWithId()` positive ID return, `submitReport()` boolean wrapper, targeted auto-elevation of new ID, Report #1 status non-modification, failed submission safety guard. |
| **Group 4: Edge Cases & Validation Rejections** | 18 | Future date rejection, future time today rejection, missing time in exact, missing date in exact, missing custom uncertainty, precision/window conflict, invalid hidden fields, tampered GPS timestamps (negative, pre-2020, future, SQL injection), exact midnight boundary (`00:00:00`), night window crossing midnight, stale GPS reset, RBAC role denial, zero writes on validation failure. |
| **Group 5: Review Display & Record Segregation** | 6 | Exact review panel formatting, operational telemetry segregation, historical Report #1 safe rendering, reports table column separation. |
| **Group 6: Operational Database Zero-Write Invariant** | 4 | Invariant assertions on operational tables (`elephant_reports = 43`, `system_logs = 185`, Report #1 untouched). |
| **Total** | **56** | **100% Pass Rate** |

---

## 6. Execution Results of All Milestone Test Suites

All regression and milestone test suites were executed cleanly in isolated environments:

```
1. tests/test_milestone_9c_stage2b_frontend_integration.php: 56 Passed, 0 Failed
2. tests/test_milestone_9c_stage2a_temporal_service.php:     49 Passed, 0 Failed
3. tests/test_milestone_9c_stage1_foundation.php:            29 Passed, 0 Failed
4. tests/test_milestone_9b_analytics.php:                    23 Passed, 0 Failed
---------------------------------------------------------------------------------
Cumulative Milestone 9 Verification:                       157 Passed, 0 Failed
```

---

## 7. Proof of Zero Operational Database Writes

The operational database (`eleguard_rail` on MariaDB port 3308) was queried pre-test and post-test:

```sql
SELECT COUNT(*) AS rep_count FROM eleguard_rail.elephant_reports;
-- Result: 43

SELECT COUNT(*) AS log_count FROM eleguard_rail.system_logs;
-- Result: 185
```

Pre-test counts and post-test counts are strictly identical:
- `elephant_reports`: 43 $\to$ 43 (0 writes)
- `system_logs`: 185 $\to$ 185 (0 writes)
- Zero operational alerts or transient records were created.

---

## 8. Status of Report #1 & Historical Data Integrity

A forensic row-level check was performed on **Report #1** in `eleguard_rail`:

```sql
SELECT id, report_status, sighting_date, sighting_time, sighting_time_precision, created_at, updated_at 
FROM eleguard_rail.elephant_reports WHERE id = 1;
```

**Forensic Output:**
```
                     id: 1
          report_status: Pending
          sighting_date: NULL
          sighting_time: NULL
sighting_time_precision: unknown
             created_at: 2026-11-08 07:02:21
             updated_at: NULL
```

- Status is strictly `'Pending'`.
- `updated_at` is strictly `NULL`.
- Temporal fields remain `NULL` (no midnight or today's date fabrication).
- Historical data preservation is 100% verified.

---

## 9. XAMPP Synchronization Confirmation

All modified files were copied to `C:\xampp\htdocs\eleguard-rail\` and verified via SHA-256 cryptographic hashing:

| File Path | Desktop SHA-256 | XAMPP SHA-256 | Parity |
|:---|:---:|:---:|:---:|
| `app/services/ReportService.php` | `306BD0BBFD0833F6CE095586BAF443784B517EF256691A8E863E93600D43D35E` | `306BD0BBFD0833F6CE095586BAF443784B517EF256691A8E863E93600D43D35E` | **MATCH** |
| `app/services/TemporalService.php` | `77F8CB107BBDDC0442C0B709E5406994C415AB19018D33B88379CC6A4C57A249` | `77F8CB107BBDDC0442C0B709E5406994C415AB19018D33B88379CC6A4C57A249` | **MATCH** |
| `public/wildlife/submit_report.php` | `00DB24294183C4EE0AE880FE8D47F1C6FDF90855855AE73310E4229BDE1EA48F` | `00DB24294183C4EE0AE880FE8D47F1C6FDF90855855AE73310E4229BDE1EA48F` | **MATCH** |
| `tests/test_milestone_9c_stage2b_frontend_integration.php` | `D8DC0EC3A2332DC6ECF950D3A1B6517AD09DE6D15024292E13C58658F2A2B028` | `D8DC0EC3A2332DC6ECF950D3A1B6517AD09DE6D15024292E13C58658F2A2B028` | **MATCH** |

---

## 10. Git Commit & Repository State

- **Branch:** `main`
- **Scope:** Corrective patch for Milestone 9C Stage 2B.
- **Modified Production Files:**
  - `app/services/ReportService.php`
  - `app/services/TemporalService.php`
  - `public/wildlife/submit_report.php`
- **Modified Test Files:**
  - `tests/test_milestone_9c_stage2b_frontend_integration.php`
- **New Audit Documentation:**
  - `docs/phase9/milestone_9c_stage2b_final_audit.md`
  - `docs/phase9/milestone_9c_stage2b_forensic_db_audit.json`
  - `docs/phase9/milestone_9c_stage2b_correction_report.md`

---

## Explicit Milestone Boundary Statement

> [!IMPORTANT]
> **Stage 2B is now complete and fully corrected.**
> Neither `RiskService.php` nor the operational risk engine has been modified.
> No temporal decay factors or seasonal multipliers have been implemented.
> Implementation of **Milestone 9C Stage 3 (Temporal & Diurnal Risk Engine Integration)** has NOT been started and awaits explicit user authorization.
