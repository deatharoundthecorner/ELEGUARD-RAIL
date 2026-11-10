# EleGuard Rail — Milestone 9C Stage 3D
## Final Independent Verification & Security Audit

**Project:** EleGuard Rail (Sri Lanka Wildlife-Railway Collision Early Warning Platform)  
**Evaluated Commit:** `2c2cf8f`  
**Branch:** `main`  
**Date:** 10 November 2026  
**Audit Scope:** Read-Only Technical, Security, Shadow Mode, and Regression Verification  
**Status:** FULLY VERIFIED — ZERO DEFECTS — ZERO REGRESSIONS  

---

### 1. AI Integration Test Failure Root Cause & Execution Audit

#### 1.1 Incident Analysis
In the previous turn, the test command:
```powershell
& "C:\xampp\php\php.exe" "C:\Users\user\Desktop\Railway\eleguard-rail\test_ai_integration.php"
```
exited with code `1`.

#### 1.2 Investigation Findings
1. **Exact Error Message:**
   ```text
   Could not open input file: C:\Users\user\Desktop\Railway\eleguard-rail\test_ai_integration.php
   ```
2. **Execution Depth:**
   The test **did not reach PHP runtime execution of the test suite code**. The PHP CLI interpreter failed during process startup because the requested file path did not exist in the root folder.
3. **Shell / Environment Root Cause:**
   The file is located at `tests/test_ai_integration.php`, not in the project root. The command path omitted the `tests/` subdirectory.
4. **Database Isolation Consideration:**
   Unlike Stage 1, 2, and 3 test suites, `tests/test_ai_integration.php` does not contain built-in database cloning logic. It establishes its connection via `Database::getInstance()`. Running it directly without setting `DB_NAME=eleguard_rail_test` would target the operational database and violate operational database isolation.
5. **Application Regression Assessment:**
   To verify whether any application regression existed, an isolated test runner script was executed with an environment override (`putenv("DB_NAME=eleguard_rail_test")`) against a cloned test database.

#### 1.3 Verified AI Test Results
```text
========================================================
EleGuard Rail - Milestone 8C AI Integration Test Suite
========================================================
-- Test Group 1: Subsystem & Model Readiness (3/3 Passed)
-- Test Group 2: Direct Image Analysis & 3-Tier Outcomes (4/4 Passed)
-- Test Group 3: Database Migration & Schema Verification (8/8 Passed)
-- Test Group 4: End-to-End Report Submission Workflow Integration (5/5 Passed)
-- Test Group 5: Strict Human Governance & Wildlife Officer Authority (5/5 Passed)
========================================================
Test Execution Summary: 25 / 25 PASSED (100%)
========================================================
```
- **Conclusion:** There is **zero application regression** in the AI subsystem, model detection, 3-tier classification, or human governance workflows.

---

### 2. Shadow Mode Safety Audit

A line-by-line inspection of all Stage 3D modified files (`RiskService.php`, `TemporalRiskService.php`, `public/control/reports.php`, `public/wildlife/reports.php`) verified the following invariants:

| Safety Requirement | Audit Findings | Status |
|---|---|:---:|
| **Stored `risk_score` unmutated** | `calculateObservationAwareRisk()` performs purely in-memory arithmetic and returns an array. It executes zero SQL `UPDATE` or `INSERT` statements. `elephant_reports.risk_score` is never modified. | **VERIFIED** |
| **`report_status` unmutated** | Sighting verification status changes occur strictly on explicit POST actions (`action === 'verify'` / `'reject'`). Shadow calculation never alters report status. | **VERIFIED** |
| **Alerts uncreated / unmodified** | `AlertService::createAlert()` is called only when an officer submits the alert broadcast POST form. The alert dropdown defaults strictly to `$selectedReport['risk_score']` (the operational score). Shadow risk is never passed to AlertService. | **VERIFIED** |
| **Driver acknowledgements unmutated** | `AlertService::acknowledgeAlert()` is called only by authenticated drivers in `driver/dashboard.php`. Neither control nor wildlife review screens touch driver acknowledgements. | **VERIFIED** |
| **Page refresh idempotent** | GET requests to `control/reports.php` and `wildlife/reports.php` execute strictly read-only queries (`getById()`, `SELECT COUNT(*)`). Refreshing never inserts audit records. | **VERIFIED** |
| **Operational score preserved** | Card A displays `$selectedReport['risk_score']`, completely unchanged from intake. | **VERIFIED** |
| **Experimental decay disabled** | `['enable_decay' => false]` is explicitly hardcoded in both dashboard review calls and defaults to `false` in `RiskService.php`. | **VERIFIED** |
| **AI confidence excluded** | The temporal risk calculation formula depends strictly on section risk, urgency level, elephant count, active alert concurrency, and diurnal illumination $D(t)$. AI confidence and detection outputs are completely excluded. | **VERIFIED** |

---

### 3. Security and Role Access Audit

1. **Control Officer Access (`public/control/reports.php`):**
   - Enforces `$allowed_roles = 'control_officer';` through `dashboard_header.php` and `app/middleware/role_required.php`.
   - Direct URL access by unauthenticated users redirects to `login.php`.
   - Direct URL access by `driver` or `reporter` triggers an authorization failure and redirects the user to their own role dashboard.
2. **Wildlife Officer Access (`public/wildlife/reports.php` & `view_report.php`):**
   - Enforces `$allowed_roles = 'wildlife_officer';`.
   - `view_report.php` safely validates report ID and redirects to `wildlife/reports.php?id=$id`.
   - Direct URL access by unauthorized roles is blocked.
3. **Driver Isolation (`public/driver/dashboard.php`):**
   - Enforces `$allowed_roles = 'driver';`.
   - Drivers query only broadcasted operational alerts (`AlertService::getAllAlerts('Active')`).
   - Drivers have zero access to sighting review panels, unverified sightings, raw intake scores, or shadow assessments.
4. **Reporter Isolation (`public/reporter/*`):**
   - Enforces `$allowed_roles = 'reporter';`.
   - Reporters can view only their own filed submissions (`public/reporter/my_reports.php` filtered by `reporterId`).
   - Reporters have zero access to control or wildlife review dashboards.
5. **Sensitive Wildlife Coordinates Protection:**
   - Field GPS coordinates are restricted to authorized Control Officers, Wildlife Officers, and System Administrators.
   - Unauthorized roles (Drivers, public Reporters) cannot access coordinates or spatial clustering feeds.

---

### 4. Duplicate Report Handling Assessment

#### 4.1 Treatment in Shadow Calculations
- `RiskService::calculateObservationAwareRisk()` evaluates threat on an **individual report basis**.
- Active alert concurrency points are strictly binary (clamped to `+2 pts` for the presence of active alerts in the section, regardless of how many sightings exist).
- Individual elephant counts are evaluated per report (e.g. 2 spotted elephants = 2 points). Duplicate submissions do not multiplicatively stack elephant counts.
- If `$reportContext['is_potential_duplicate']` is passed, the engine emits a clear data quality notice:
  `"Report is flagged as a potential duplicate sighting; risk evaluation reflects individual report telemetry."`

#### 4.2 Documented Limitation
- **Cross-Report Spatial-Temporal Deduplication:** Automated clustering of multiple overlapping reports within a spatial radius (e.g. 500m) and temporal window (e.g. 30 minutes) into a single unified threat entity is not part of the Stage 3 risk engine.
- Duplicate detection and report merging are currently left to human verification by Wildlife Officers prior to alert conversion. Automated spatial-temporal deduplication remains reserved for Milestone 9C Stage 4 (Analytics).

---

### 5. Regression Test Results (100% Pass)

All 7 test suites were executed against isolated test databases (`eleguard_rail_test`) with zero errors:

| Test Suite | File | Assertions | Result | Notes |
|---|---|:---:|:---:|---|
| **Stage 3D Dashboard Integration** | `tests/test_milestone_9c_stage3d_dashboard_integration.php` | 38 | **38 / 38 PASS** | Shadow UI, form defaults, RBAC, DB invariant |
| **Stage 3C Temporal Risk Engine** | `tests/test_milestone_9c_stage3c_temporal_risk.php` | 32 | **32 / 32 PASS** | Diurnal function $D(t)$, intervals, decay floor |
| **Stage 2B Frontend Integration** | `tests/test_milestone_9c_stage2b_frontend_integration.php` | 62 | **62 / 62 PASS** | Wildlife auto-elevation, reporting delay badges |
| **Stage 2A Temporal Service** | `tests/test_milestone_9c_stage2a_temporal_service.php` | 49 | **49 / 49 PASS** | Date/time validation, epoch GPS conversion |
| **Stage 1 Schema Foundation** | `tests/test_milestone_9c_stage1_foundation.php` | 29 | **29 / 29 PASS** | Migration 03, column attributes, rollback test |
| **Milestone 9B Analytics** | `tests/test_milestone_9b_analytics.php` | 23 | **23 / 23 PASS** | Haversine distance, GPS uncertainty clamping |
| **Milestone 8C AI Integration** | `tests/test_ai_integration.php` | 25 | **25 / 25 PASS** | ONNX detection, 3-tier outcomes, governance |
| **Grand Total** | | **258** | **258 / 258 PASS (100%)** | **Zero Regressions** |

---

### 6. Operational Database Invariant Verification

Before and after the entire independent verification sequence, the production database (`eleguard_rail` on port 3308) was queried directly via MariaDB client:

```sql
SELECT 'elephant_reports' as tbl, count(*) as cnt FROM elephant_reports
UNION ALL SELECT 'system_logs', count(*) FROM system_logs
UNION ALL SELECT 'alerts', count(*) FROM alerts;
```

**Results:**
- `elephant_reports`: **43** (0 writes)
- `system_logs`: **185** (0 writes)
- `alerts`: **15** (0 writes)

#### Historical Baseline Record #1 Audit:
```sql
SELECT id, report_status, sighting_date, sighting_time, sighting_time_precision, sighting_time_window, sighting_time_uncertainty_minutes, sighting_timezone, gps_captured_at, created_at, updated_at
FROM elephant_reports WHERE id = 1;
```
- `id`: `1`
- `report_status`: `'Pending'` (Strictly preserved, never elevated)
- `sighting_date`: `NULL`
- `sighting_time`: `NULL`
- `sighting_time_precision`: `'unknown'`
- `sighting_time_window`: `NULL`
- `sighting_time_uncertainty_minutes`: `NULL`
- `sighting_timezone`: `'Asia/Colombo'`
- `gps_captured_at`: `NULL`
- `created_at`: `'2026-11-08 07:02:21'`
- `updated_at`: `NULL` (Pristine, untouched)

All 43 historical records retain `sighting_date IS NULL` and `sighting_time IS NULL`. Zero database writes occurred.

---

### 7. Git Status & Desktop/XAMPP Parity

- **Git Branch:** `main`
- **Latest Commit:** `2c2cf8f` (`feat(phase9): integrate shadow mode observation-aware temporal risk into control dashboard`)
- **Working Tree:** Clean (zero unstaged or untracked changes).

#### Cryptographic SHA-256 Parity Verification:
| File | Desktop SHA-256 | XAMPP Webroot SHA-256 | Parity |
|---|---|---|:---:|
| `app/services/RiskService.php` | `B4482D5610944F40...` | `B4482D5610944F40...` | **MATCH (100%)** |
| `app/services/TemporalRiskService.php` | `545C8B37E0998B0B...` | `545C8B37E0998B0B...` | **MATCH (100%)** |
| `public/control/reports.php` | `97275EC25E40598A...` | `97275EC25E40598A...` | **MATCH (100%)** |
| `public/wildlife/reports.php` | `68839D40E3035651...` | `68839D40E3035651...` | **MATCH (100%)** |
| `tests/test_milestone_9c_stage3d_dashboard_integration.php` | `692490311C3A88E5...` | `692490311C3A88E5...` | **MATCH (100%)** |
| `docs/phase9/milestone_9c_stage3d_dashboard_integration_report.md` | `A5B4C9BE88686647...` | `A5B4C9BE88686647...` | **MATCH (100%)** |

---

### 8. Audit Conclusion

Milestone 9C Stage 3D has passed all technical, security, shadow isolation, and regression verification checks with zero defects.
Per instructions, no production modifications have been made, and Stage 3E has not been initiated.
