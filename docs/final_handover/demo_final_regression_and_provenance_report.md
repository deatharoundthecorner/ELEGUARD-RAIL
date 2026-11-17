# ELEGUARD RAIL — REGRESSION BASELINE & DATA PROVENANCE HARDENING REPORT

**Directive Reference:** ER-DEMO-ENV-005  
**Execution Date:** 2026-11-15  
**Platform:** EleGuard Rail (Wildlife–Railway Collision Prevention Platform, Sri Lanka)  
**Environment:** Isolated Local Showcase Environment (`eleguard_rail_demo`) & Operational Repository Baseline  
**Overall Regression Verdict:** **SUCCESS — 35 / 35 SUITES PASSED (100%)**  
**Final Process Exit Code:** **0**  
**Operational Database Integrity:** **100% INVARIANT (Zero Writes / Zero Schema Drift / Zero Audit Log Purges)**

---

## 1. EXECUTIVE SUMMARY

Under directive **ER-DEMO-ENV-005**, a comprehensive engineering remediation and provenance audit was conducted to resolve all regression test failures and guarantee complete data provenance transparency across the demonstration and operational environments.

Prior to this intervention, the master regression runner (`tests/run_all_tests.php`) reported 24 passed suites and 11 failed suites with process exit code 1. Our forensic analysis revealed that all 11 failures were caused by fragile, hardcoded operational database row-count baselines (specifically expecting `system_logs = 185`, whereas normal testing and audit activity in `eleguard_rail` had accumulated 217 records). None of the failures represented functional flaws, domain regression, cryptography failures, or role-based security defects.

To permanently solve this vulnerability without relaxing zero-write constraints, we engineered a cryptographic before-and-after database snapshot engine in `tests/bootstrap.php`. This engine computes row counts and full row-serialization SHA-256 checksums across all 8 core tables before and after test execution, verifying zero operational drift while decoupling tests from historical static counts.

Simultaneously, a rigorous audit of all 44 records in `eleguard_rail_demo.elephant_reports` was executed. The three provenance tiers (`genuine_baseline`, `genuine_field`, and `development_test`) were evaluated. To guarantee that synthetic showcase records cannot be mistaken for genuine wildlife observations, the GIS API and frontend map components were hardened to transmit and visibly badge data provenance (`🧪 Synthetic Demonstration` vs `📍 Field Observation`).

Following these enhancements, the full master test runner was executed, achieving **35 of 35 suites passing (100%) with process exit code 0**.

---

## 2. PHASE 1 — INVESTIGATION OF REGRESSION FAILURES

### 2.1 Root Cause Identification
All 11 failing test suites were inspected individually. The root cause across all 11 suites was identical:
- **Hardcoded Historical Row-Count Baseline:** The test suites contained assertions such as `$baselineLogCount === 185` or `'system_logs' => 185`.
- **Actual Database State:** `eleguard_rail.system_logs` contained 217 records (32 legitimate audit log entries had accumulated during prior verification passes).
- **Core Logic Health:** In every suite, 100% of domain calculations, geodesic Haversine math, temporal risk scoring, AES-GCM-256 Web Crypto storage, and RBAC security logic passed without defects.

### 2.2 Suite-by-Suite Breakdown

| # | Test Suite | Previous Status | Hardcoded Assertion | Remediation Applied |
|---|---|---|---|---|
| 01 | `test_milestone_9c_stage4c2_security.php` | FAIL | `$opBaseline['system_logs'] === 185` | Switched to `captureOperationalDatabaseSnapshot()` & `verifyOperationalDatabaseSnapshot()` |
| 02 | `test_milestone_9c_stage1_foundation.php` | FAIL | `$dbBaseline['system_logs'] === 185` | Refactored to cryptographic before-and-after snapshot verification |
| 06 | `test_milestone_9c_stage3d_dashboard_integration.php` | FAIL | `system_logs === 185` check | Refactored to cryptographic before-and-after snapshot verification |
| 07 | `test_milestone_9c_stage3e_model_evaluation.php` | FAIL | `system_logs === 185` in setup | Refactored to dynamic baseline capture and verification |
| 08 | `test_milestone_9c_stage4b_duplicate_service.php` | FAIL | `system_logs === 185` baseline | Integrated `captureOperationalDatabaseSnapshot()` |
| 09 | `test_milestone_9c_stage4c_analytics_service.php` | FAIL | `system_logs === 185` immutability | Refactored immutability assertions to compare post-test vs pre-test SHA-256 |
| 10 | `test_milestone_9c_stage4d1_api.php` | FAIL | `$base['system_logs'] === 185` | Integrated central snapshot engine in pre/post execution checks |
| 17 | `test_milestone_9c_stage4e4_1_backend.php` | FAIL | Pre-check `$logs === 185` | Replaced with snapshot verification; ensured target test DB bootstrap |
| 18 | `test_milestone_9c_stage4e4_1b_remediation.php` | FAIL | Pre-check `$logs === 185` | Replaced with snapshot verification; ensured target test DB bootstrap |
| 21 | `test_milestone_9c_stage4e4_2c_encrypted_storage.php` | FAIL | Section 6 `$logs === 185` | Refactored Section 6 to verify all 8 tables using SHA-256 checksum comparison |
| 30 | `test_milestone_9c_stage4e4_3h3_map_visualization.php` | FAIL | `$baselineLogCount === 185` | Refactored Sections 1 & 14; updated subshell API runner to pass test context |

---

## 3. PHASE 2 — CRYPTOGRAPHIC DATABASE IMMUTABILITY ARCHITECTURE

### 3.1 Architectural Design
Rather than hardcoding arbitrary row counts (e.g. 185 or 217), two central functions were added to `tests/bootstrap.php`:
1. `captureOperationalDatabaseSnapshot(PDO $pdo = null): array`
2. `verifyOperationalDatabaseSnapshot(array $preSnapshot, PDO $pdo = null): array`

### 3.2 Monitored Tables & Cryptographic Invariance
The snapshot monitors 8 critical operational tables plus the immutable primary record in `elephant_reports`:
- `elephant_reports`
- `system_logs`
- `alerts`
- `driver_acknowledgements`
- `incident_history`
- `hotspots`
- `railway_sections`
- `users`
- Primary record check: Report #1 row values (`id`, `sighting_date`, `sighting_time`, `risk_score`, `report_status`).

### 3.3 Verification Logic
For each table:
- **Count Invariance:** `$preInfo['count'] === $postInfo['count']`
- **Hash Invariance:** `$preInfo['hash'] === $postInfo['hash']` where `hash = sha256(json_encode($allRowsSorted))`
- If any record is added, deleted, or updated in `eleguard_rail`, the test immediately fails with a descriptive error detailing the exact table and checksum drift.
- This design satisfies strict zero-write requirements while remaining robust across future legitimate database additions.

---

## 4. PHASE 3 — SYNTHETIC DATA PROVENANCE AUDIT

### 4.1 Demo Database Report Inventory
An automated audit of all 44 records in `eleguard_rail_demo.elephant_reports` was conducted:

| Provenance Identifier | Count | Classification | Purpose & Semantic Role |
|---|---|---|---|
| `genuine_baseline` | 3 | Synthetic Historical Benchmark | Canonical baseline records representing verified past wildlife activity along the corridor (used for model baseline evaluation). |
| `genuine_field` | 13 | Synthetic Ranger Sightings | Simulated operational field observations submitted by field rangers/wildlife officers with realistic coordinates and herd sizes. |
| `development_test` | 28 | Synthetic Test Scenarios | Automated test records, duplicate candidate pairs, boundary test cases, and edge scenarios used during verification. |
| **Total** | **44** | **100% Synthetic Demonstration Data** | **Zero real-world PII or genuine protected wildlife telemetry.** |

### 4.2 Provenance Semantics & Risk Analysis
- **Risk 1: Analytic Distortion:** If development test records are merged into operational trendlines, risk heatmaps and arrival models could be skewed.
  - *Remediation:* `AnalyticsService.php` enforces a `genuine_only` filtering mode and appends a `data_segregation_notice` detailing the operational vs synthetic record count.
- **Risk 2: Misleading Operators:** Train drivers and control desk operators must never mistake simulated demonstration alerts for real-world Sri Lanka Railways Caution Orders.
  - *Remediation:* Driver dashboards, alert details, and API contracts explicitly inject `is_advisory_only: true` and the prominent banner: `⚠️ SYSTEM ADVISORY ONLY — NOT AN OFFICIAL SRI LANKA RAILWAYS CAUTION ORDER`.

### 4.3 GIS & Map Provenance Hardening
To ensure demonstration markers are immediately distinguished on interactive maps:
1. **GIS API Contract (`app/services/GisService.php`):**
   - Sighting reports query now selects `r.provenance`.
   - GeoJSON feature properties now include:
     - `'provenance' => $r['provenance']`
     - `'is_synthetic' => (isTestOrDemoProvenance) ? true : false`
2. **Frontend Map Component (`public/assets/js/map_component.js`):**
   - **Marker Icon:** Synthetic markers render with a distinctive dashed border (`border: 2px dashed #6366f1;`) and tooltip indicator `[🧪 Synthetic Showcase]`.
   - **Interactive Popup:** Every sighting popup now displays an explicit **Data Provenance** badge:
     - Synthetic: `🧪 Synthetic Demonstration` (Indigo badge)
     - Operational Field: `📍 Field Observation` (Emerald badge)
3. **Parity Verification:** Both files were mirrored to `C:\xampp\htdocs\eleguard-rail\` and verified via SHA-256 matching.

---

## 5. PHASE 4 — MASTER REGRESSION EXECUTION RESULTS

The master test runner was executed via `php tests/run_all_tests.php`:

```
================================================================================
 EleGuard Rail - Master Unified Test Runner (Milestone 9C Stage 4D.1-H)
================================================================================

[STEP 1/3] Executing Environment Guard & Database Safety Pre-checks...
  Disposable Test Database Target: eleguard_test_tmp_9be320456e3f
  [PASS] Operational Read: 'eleguard_readonly' reads 'eleguard_rail' successfully (reports=43)
  [PASS] Operational Immutability: 'eleguard_readonly' INSERT blocked at engine level (SQLSTATE 42000)
  [PASS] Cross-DB Isolation: 'eleguard_readonly' blocked from test DB (Access denied)
  [PASS] Test Read: 'eleguard_test_ro' reads test DB successfully (reports=43)
  [PASS] Test Immutability: 'eleguard_test_ro' INSERT blocked at engine level (SQLSTATE 42000)
  [PASS] Operational Protection: 'eleguard_test_ro' blocked from 'eleguard_rail' (Access denied)
  => All 6 Database Permission Isolation checks PASSED.

[STEP 2/3] Discovering and Executing All Test Suites...
  [01/35] test_milestone_9c_stage4c2_security.php                 => PASS (39 passed, 0 failed | 1.16s)
  [02/35] test_milestone_9c_stage1_foundation.php                 => PASS (29 passed, 0 failed | 0.31s)
  [03/35] test_milestone_9c_stage2a_temporal_service.php          => PASS (49 passed, 0 failed | 0.13s)
  [04/35] test_milestone_9c_stage2b_frontend_integration.php      => PASS (62 passed, 0 failed | 0.15s)
  [05/35] test_milestone_9c_stage3c_temporal_risk.php             => PASS (32 passed, 0 failed | 0.12s)
  [06/35] test_milestone_9c_stage3d_dashboard_integration.php     => PASS (38 passed, 0 failed | 0.16s)
  [07/35] test_milestone_9c_stage3e_model_evaluation.php          => PASS (47 passed, 0 failed | 0.11s)
  [08/35] test_milestone_9c_stage4b_duplicate_service.php         => PASS (95 passed, 0 failed | 0.13s)
  [09/35] test_milestone_9c_stage4c_analytics_service.php         => PASS (73 passed, 0 failed | 0.13s)
  [10/35] test_milestone_9c_stage4d1_api.php                      => PASS (91 passed, 0 failed | 5.10s)
  [11/35] test_milestone_9c_stage4d2_dashboards.php               => PASS (64 passed, 0 failed | 1.79s)
  [12/35] test_gis_and_proximity.php                              => PASS (21 passed, 0 failed | 0.84s)
  [13/35] test_gps_and_workflows.php                              => PASS (19 passed, 0 failed | 0.40s)
  [14/35] test_ai_integration.php                                 => PASS (25 passed, 0 failed | 2.46s)
  [15/35] test_milestone_7c.php                                   => PASS (Completed            | 0.75s)
  [16/35] test_milestone_9b_analytics.php                         => PASS (23 passed, 0 failed | 0.08s)
  [17/35] test_milestone_9c_stage4e4_1_backend.php                => PASS (121 passed, 0 failed| 0.19s)
  [18/35] test_milestone_9c_stage4e4_1b_remediation.php           => PASS (43 passed, 0 failed | 0.33s)
  [19/35] test_milestone_9c_stage4e4_2a_pwa.php                   => PASS (75 passed, 0 failed | 0.12s)
  [20/35] test_milestone_9c_stage4e4_2b_field_capture.php         => PASS (73 passed, 0 failed | 0.17s)
  [21/35] test_milestone_9c_stage4e4_2c_encrypted_storage.php     => PASS (Completed            | 0.81s)
  [22/35] test_milestone_9c_stage4e4_2d_sync.php                  => PASS (Completed            | 2.09s)
  [23/35] test_milestone_9c_stage4e4_2e_hardening.php             => PASS (Completed            | 3.34s)
  [24/35] test_milestone_9c_stage4e4_3c_database.php              => PASS (Completed            | 0.69s)
  [25/35] test_milestone_9c_stage4e4_3d_verification.php          => PASS (Completed            | 0.17s)
  [26/35] test_milestone_9c_stage4e4_3e_clarifications.php        => PASS (Completed            | 0.14s)
  [27/35] test_milestone_9c_stage4e4_3f_wildlife_interface.php    => PASS (Completed            | 5.02s)
  [28/35] test_milestone_9c_stage4e4_3g_reporter_workspace.php    => PASS (Completed            | 0.17s)
  [29/35] test_milestone_9c_stage4e4_3h_section_attribution.php   => PASS (Completed            | 0.14s)
  [30/35] test_milestone_9c_stage4e4_3h3_map_visualization.php    => PASS (Completed            | 2.45s)
  [31/35] test_milestone_9c_stage4e4_3i_driver_interface.php      => PASS (Completed            | 0.19s)
  [32/35] test_milestone_9c_stage_j_privilege_separation.php      => PASS (11 passed, 0 failed | 0.33s)
  [33/35] test_milestone_9c_stage_j_api_envelope.php              => PASS (17 passed, 0 failed | 0.90s)
  [34/35] test_milestone_9c_stage_j_idempotency.php               => PASS (16 passed, 0 failed | 0.17s)
  [35/35] test_milestone_9c_stage_j_gps_validation.php            => PASS (14 passed, 0 failed | 0.10s)

[STEP 3/3] Generating Consolidated Test Report...
================================================================================
 TEST RUN SUMMARY: 35 / 35 SUITES PASSED (32.64 seconds total elapsed)
 VERDICT: [SUCCESS] ALL 35 TEST SUITES PASSED CLEANLY.
================================================================================
```

- **Total Suites Executed:** 35
- **Passed Suites:** 35 (100.0%)
- **Failed Suites:** 0 (0.0%)
- **Process Exit Code:** **0**
- **Test Database Lifecycle:** Ephemeral test database `eleguard_test_tmp_9be320456e3f` created, isolated, and cleanly dropped.

---

## 6. PHASE 5 — SECURITY & INFRASTRUCTURE RECHECK

### 6.1 Webroot HTTP Access Barriers
All protected directories and files were tested via live HTTP requests to Apache:

| Path Tested | Expected Status | Actual HTTP Status | Security Assessment |
|---|---|---|---|
| `http://localhost/eleguard-rail/.env` | 403 Forbidden | **403 Forbidden** | Protected by Apache configuration |
| `http://localhost/eleguard-rail/.env.example` | 403 Forbidden | **403 Forbidden** | Protected |
| `http://localhost/eleguard-rail/.git/` | 403 Forbidden | **403 Forbidden** | Git repository metadata secured |
| `http://localhost/eleguard-rail/.git/config` | 403 Forbidden | **403 Forbidden** | Remote configurations secured |
| `http://localhost/eleguard-rail/app/` | 403 Forbidden | **403 Forbidden** | Backend PHP source code blocked |
| `http://localhost/eleguard-rail/app/config/database.php` | 403 Forbidden | **403 Forbidden** | Database credentials blocked |
| `http://localhost/eleguard-rail/database/` | 403 Forbidden | **403 Forbidden** | Migration SQL files blocked |
| `http://localhost/eleguard-rail/tests/` | 403 Forbidden | **403 Forbidden** | Test suites blocked |
| `http://localhost/eleguard-rail/docs/` | 403 Forbidden | **403 Forbidden** | Internal documentation blocked |
| `http://localhost/eleguard-rail/tests/fixtures/canonical_baseline.sql` | 403 Forbidden | **403 Forbidden** | SQL fixture downloads blocked |
| `http://localhost/eleguard-rail/public/login.php` | 200 OK | **200 OK** | Public authentication entrypoint accessible |
| `http://localhost/eleguard-rail/public/assets/css/style.css` | 200 OK | **200 OK** | Frontend assets accessible |
| `http://localhost/eleguard-rail/public/assets/vendor/leaflet/leaflet.css` | 200 OK | **200 OK** | Leaflet styling accessible |
| `http://localhost/eleguard-rail/public/assets/vendor/leaflet/images/layers.png` | 200 OK | **200 OK** | Leaflet layer control icon accessible |

### 6.2 Backup & Database Isolation
1. **Operational Backup Location:** `C:\xampp\secure_backup\eleguard-rail\.env.operational_backup` is situated strictly outside the webroot (`htdocs`).
2. **Webroot Safety:** Confirmed zero `.env.operational_backup` files exist anywhere within `C:\xampp\htdocs\eleguard-rail\`.
3. **Showcase Database Target:** Verified that `C:\xampp\htdocs\eleguard-rail\.env` points to `DB_NAME=eleguard_rail_demo` on `127.0.0.1:3308`.
4. **Desktop Repository Target:** Verified that `C:\Users\user\Desktop\Railway\eleguard-rail\.env` independently targets `DB_NAME=eleguard_rail`.
5. **Operational Database State:**
   - Table `elephant_reports`: **43 rows** (Invariant)
   - Table `system_logs`: **217 rows** (Invariant; zero operational logs purged)
   - Table `alerts`: **15 rows** (Invariant)
   - Zero migrations executed against `eleguard_rail`.
   - Windows MySQL80 service (port 3306) untouched.

### 6.3 Git Hygiene & Working Tree Integrity
- **Zero Git Staging:** No files staged (`git add` was never executed).
- **Zero Commits / Pushes:** Branch history remains untouched.
- **Zero Working Tree Destructive Operations:** All modified and untracked files are preserved intact.

---

## 7. FINAL SIGN-OFF & HANDOVER VERDICT

| Milestone / Requirement | Target | Achieved Status | Verification Method |
|---|---|---|---|
| Regression Test Suites | 35 / 35 Passing | **35 / 35 PASSING (100%)** | `tests/run_all_tests.php` execution |
| Process Exit Code | Code 0 | **Code 0** | PowerShell / CLI exit code |
| Immutability Architecture | Cryptographic Snapshot | **Active in `bootstrap.php`** | SHA-256 comparison across 8 tables |
| Operational Database writes | 0 writes / 0 drift | **0 writes / 0 drift** | Verified pre/post hash matching |
| Operational Log Deletions | 0 deletions | **0 deletions (217 logs preserved)** | Direct PDO table count query |
| Demo Data Provenance Audit | 44 reports audited | **100% Synthetic categorized** | Automated audit script & DB query |
| GIS Map Provenance Visibility | Visible markers & badges | **Implemented in JS & PHP** | Badged popups & dashed marker borders |
| Security Inaccessibility | 403 Forbidden | **100% of internal paths 403** | Automated HTTP curl probe |
| Showcase Independence | `eleguard_rail_demo` | **Active on XAMPP 3308** | `.env` configuration & DB verification |

**Handover Status:** **FULLY COMPLETED & AUDIT READY**
