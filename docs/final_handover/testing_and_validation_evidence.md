# ELEGUARD RAIL — TESTING AND VALIDATION EVIDENCE
## Consolidated Automated Testing, Concurrency Verification & Immutability Audit

**Document Reference:** `docs/final_handover/testing_and_validation_evidence.md`  
**Change Reference:** `ER-FINAL-HANDOVER-001`  
**Milestone:** 9C (Stage J Finalization)  
**Date:** November 13, 2026  
**Final Test Status:** ALL 35 TEST SUITES PASSED (Exit Code 0)  

---

## 1. TESTING STRATEGY & GOVERNANCE PRINCIPLES

The testing infrastructure for ELEGUARD RAIL was architected to satisfy strict defense-in-depth, data integrity, and reproducibility standards:

1. **Zero Mutation of Operational Databases:**
   - Under no circumstances does the automated test suite write to, alter, or delete records from the primary operational database (`eleguard_rail`) or the persistent baseline test database (`eleguard_rail_test`).
2. **Automated Disposable Database Lifecycle:**
   - Prior to executing test suites, the test runner provisions an isolated, ephemeral test database (`eleguard_test_tmp_<hex>`).
   - The test runner executes all DDL and DML operations within this disposable container and drops the schema upon completion.
3. **Database Account Scope Enforcement:**
   - Automated tests execute under restricted credentials (`eleguard_test_rw`) whose MariaDB grant scope is strictly bounded to `eleguard_test_tmp_%`.*. Attempts to access `eleguard_rail` trigger SQLSTATE access denial at the database engine level.
4. **Distinction Between Automated & Field Validation:**
   - All tests documented herein represent automated software integration, unit, and simulated concurrency tests running against local MariaDB and PHP runtimes. They do **not** represent live in-service railway field trials.

---

## 2. CONSOLIDATED MASTER TEST SUITE EXECUTION TELEMETRY

Command: `C:\xampp\php\php.exe tests/run_all_tests.php`  
Result: **35 / 35 SUITES PASSED** | Total Elapsed Time: **34.84s** | Process Exit Code: **0**

| # | Test Suite Filename | Milestone / Stage Scope | Assertions / Status | Duration |
|---|---|---|---|---|
| **01** | `test_milestone_9c_stage4c2_security.php` | Stage 4C.2: DB Safety & SQL Injection Hardening | 39 passed, 0 failed | 1.16s |
| **02** | `test_milestone_9c_stage1_foundation.php` | Stage 1: Foundation Schema & Baseline Data | 29 passed, 0 failed | 0.34s |
| **03** | `test_milestone_9c_stage2a_temporal_service.php` | Stage 2A: Temporal Analysis Service Unit Tests | 49 passed, 0 failed | 0.16s |
| **04** | `test_milestone_9c_stage2b_frontend_integration.php` | Stage 2B: Frontend View Integration Tests | 62 passed, 0 failed | 0.20s |
| **05** | `test_milestone_9c_stage3c_temporal_risk.php` | Stage 3C: Temporal Risk Engine Implementation | 32 passed, 0 failed | 0.13s |
| **06** | `test_milestone_9c_stage3d_dashboard_integration.php` | Stage 3D: Risk Dashboard Integration | 38 passed, 0 failed | 0.18s |
| **07** | `test_milestone_9c_stage3e_model_evaluation.php` | Stage 3E: Model Evaluation & Benchmark Suite | 47 passed, 0 failed | 0.17s |
| **08** | `test_milestone_9c_stage4b_duplicate_service.php` | Stage 4B: Spatial-Temporal Duplicate Service | 95 passed, 0 failed | 0.12s |
| **09** | `test_milestone_9c_stage4c_analytics_service.php` | Stage 4C: Read-Only Historical Analytics Layer | 73 passed, 0 failed | 0.15s |
| **10** | `test_milestone_9c_stage4d1_api.php` | Stage 4D.1: Analytics REST API & RBAC Suite | 91 passed, 0 failed | 5.04s |
| **11** | `test_milestone_9c_stage4d2_dashboards.php` | Stage 4D.2: Role-Based Analytics Dashboards | 64 passed, 0 failed | 1.71s |
| **12** | `test_gis_and_proximity.php` | Phase 5: Spatial Proximity & Geodesic Engine | 21 passed, 0 failed | 0.91s |
| **13** | `test_gps_and_workflows.php` | Phase 6: GPS Ingestion & Lifecycle Workflows | 19 passed, 0 failed | 0.43s |
| **14** | `test_ai_integration.php` | Phase 7: AI Sighting Detection & Governance | 25 passed, 0 failed | 2.61s |
| **15** | `test_milestone_7c.php` | Phase 7C: AI Pipeline & Advisory Verification | Completed | 0.74s |
| **16** | `test_milestone_9b_analytics.php` | Milestone 9B: Historical Hotspot Analytics | 23 passed, 0 failed | 0.08s |
| **17** | `test_milestone_9c_stage4e4_1_backend.php` | Stage 4E.4.1: Field Reporting Backend Foundation | 121 passed, 0 failed | 0.17s |
| **18** | `test_milestone_9c_stage4e4_1b_remediation.php` | Stage 4E.4.1-B: Backend Multi-Process Concurrency | 43 passed, 0 failed | 0.29s |
| **19** | `test_milestone_9c_stage4e4_2a_pwa.php` | Stage 4E.4.2-A: PWA Foundation & Telemetry | 75 passed, 0 failed | 0.12s |
| **20** | `test_milestone_9c_stage4e4_2b_field_capture.php` | Stage 4E.4.2-B: Guided Field Observation Capture | 73 passed, 0 failed | 0.14s |
| **21** | `test_milestone_9c_stage4e4_2c_encrypted_storage.php` | Stage 4E.4.2-C: Encrypted Offline Storage | Completed | 0.77s |
| **22** | `test_milestone_9c_stage4e4_2d_sync.php` | Stage 4E.4.2-D: Controlled Offline Synchronization | Completed | 2.10s |
| **23** | `test_milestone_9c_stage4e4_2e_hardening.php` | Stage 4E.4.2-E: Offline Sync Hardening | Completed | 3.59s |
| **24** | `test_milestone_9c_stage4e4_3c_database.php` | Stage 4E.4.3-C: Verification Database Foundation | Completed | 1.90s |
| **25** | `test_milestone_9c_stage4e4_3d_verification.php` | Stage 4E.4.3-D: Verification State Engine | Completed | 0.70s |
| **26** | `test_milestone_9c_stage4e4_3e_clarifications.php` | Stage 4E.4.3-E: Clarification APIs & Communication | Completed | 0.25s |
| **27** | `test_milestone_9c_stage4e4_3f_wildlife_interface.php` | Stage 4E.4.3-F: Wildlife Officer Verification UI | Completed | 4.19s |
| **28** | `test_milestone_9c_stage4e4_3g_reporter_workspace.php` | Stage 4E.4.3-G: Reporter Workspace Interface | Completed | 0.21s |
| **29** | `test_milestone_9c_stage4e4_3h_section_attribution.php` | Stage 4E.4.3-H: Control Officer Section Attribution | Completed | 0.21s |
| **30** | `test_milestone_9c_stage4e4_3h3_map_visualization.php` | Stage 4E.4.3-H3: Interactive Railway Map GIS | Completed | 2.50s |
| **31** | `test_milestone_9c_stage4e4_3i_driver_interface.php` | Stage 4E.4.3-I: Locomotive Driver Advisory HUD | Completed | 0.25s |
| **32** | `test_milestone_9c_stage_j_privilege_separation.php` | Stage J: Privilege Separation & Access Control | 11 passed, 0 failed | 0.34s |
| **33** | `test_milestone_9c_stage_j_api_envelope.php` | Stage J.4: API Response Envelope Standardization | 17 passed, 0 failed | 0.91s |
| **34** | `test_milestone_9c_stage_j_idempotency.php` | Stage J.5: Offline Sync UUID Idempotency | 16 passed, 0 failed | 0.22s |
| **35** | `test_milestone_9c_stage_j_gps_validation.php` | Stage J.6: Geodetic GPS & Uncertainty Validation | 14 passed, 0 failed | 0.13s |

---

## 3. KEY SUBSYSTEM VALIDATION HIGHLIGHTS

### 3.1 Multi-Process Race Condition Verification (`test_milestone_9c_stage4e4_1b_remediation.php`)
- **Harness:** Spawns 3 independent concurrent child PHP processes via `proc_open` executing `tests/workers/concurrent_submit_worker.php`.
- **Barrier Sync:** Workers spin-wait on a filesystem lock barrier (`eleguard_barrier_<id>.lock`) until released simultaneously.
- **Verification Invariant:** Under strict race conditions submitting identical client UUIDs, **exactly one** worker obtains database insertion lock (HTTP 201 Created), while remaining workers roll back and gracefully return HTTP 200 OK (`is_replay: true`), referencing the exact same primary key ID. Zero duplicate records are created.

### 3.2 SQL Injection Attack Matrix (`test_milestone_9c_stage4c2_security.php`)
- Tests 39 distinct attack vectors including quote escapes, stacked queries (`'; DROP TABLE`), union injections, and boolean blind injections across all report, verification, and analytics endpoints.
- 100% of malicious payloads were intercepted or safely bound via PDO parameters. **Zero injections succeeded.**

### 3.3 Offline Idempotency & Replay Protection (`test_milestone_9c_stage_j_idempotency.php`)
- Proved cross-user collision isolation: When User B submits a UUID previously created by User A, the server returns HTTP 409 Conflict with zero metadata disclosure.
- Proved material modification detection: Altering elephant counts or coordinates on an existing UUID triggers HTTP 409 Conflict (`payload-mismatch`).
- Proved mathematical batch reconciliation: `total_received === synced_count + replayed_count + rejected_count`.

### 3.4 Geodetic GPS Uncertainty & Safe Fallbacks (`test_milestone_9c_stage_j_gps_validation.php`)
- Validated Great-Circle forward geodetic equations against equatorial ($0^\circ$), high-latitude ($60^\circ\text{ N}$), and southern hemisphere ($-35^\circ\text{ S}$) coordinates.
- Proved elimination of fabricated accuracy defaults: Missing, zero, or negative GPS accuracy leaves spatial uncertainty strictly `NULL`.

### 3.5 Browser UI Visual Verification Evidence
Live browser interaction audits captured high-resolution visual evidence (`docs/phase9/browser_evidence/`):
- `01_driver_dashboard.png`: Driver cab advisory feed rendering.
- `02_driver_alert_details.png`: Enriched advisory details and speed disclaimer.
- `03_ack_submitted.png`: Successful receipt submission with stationary status.
- `04_dup_ack_handled.png`: Duplicate acknowledgement handling.
- `05_driver_history.png`: Historical acknowledgement audit log.
- `06_control_dashboard.png` & `07_control_acknowledgements.png`: Control desk dispatcher attribution and monitoring.
- `08_admin_acknowledgements.png`: Administrative oversight views.
- `09_rbac_denial.png`: RBAC enforcement blocking unauthorized role access.
- `11_driver_alerts_stationary.png`: Visual verification of mandatory stationary-use safety banners.

---

## 4. DATABASE IMMUTABILITY EVIDENCE

Before and after running all test suites, the operational database `eleguard_rail` and persistent test database `eleguard_rail_test` were audited:

| Database Table | Expected Baseline Count | Observed Count (Post-Run) | Audit Result |
|---|---|---|---|
| `elephant_reports` | 43 | 43 | **100% IMMUTABLE** |
| `alerts` | 15 | 15 | **100% IMMUTABLE** |
| `driver_acknowledgements` | 13 | 13 | **100% IMMUTABLE** |
| `hotspots` | 4 | 4 | **100% IMMUTABLE** |
| `railway_sections` | 4 | 4 | **100% IMMUTABLE** |
| `system_logs` | 185 | 185 | **100% IMMUTABLE** |
| `users` | 5 | 5 | **100% IMMUTABLE** |
| `incident_history` | 3 | 3 | **100% IMMUTABLE** |

- Temporary test databases remaining: **0** (Verified via `SHOW DATABASES LIKE 'eleguard_test_tmp_%'`).
- Zero production migrations applied; zero schema mutations on operational databases.

---

## 5. EVIDENCE SCOPE, OPERATIONAL REALITY & DEPLOYMENT STATUS

### 5.1 Verification Scope & Boundaries
- **Automated Verification:** 35 / 35 test suites passed cleanly under local PHP/MariaDB execution.
- **Live In-Service Railway Performance:** **Not independently verified / Not conducted.** No tests were executed on active rolling stock, moving locomotives, or in live railway corridors in Sri Lanka.
- **Locomotive Telemetry / Speed Lockout:** **Not implemented / Unavailable.** The platform does not interface with locomotive speed sensors or onboard train control electronics. Stationary acknowledgement is an operational safety policy enforced procedurally and through visual UI banners.
- **Safety Engineering Certification:** **None.** No independent safety certification (e.g. CENELEC EN 50126/50128/50129 or IEC 61508) has been conducted or awarded.

### 5.2 Formal Deployment Status
- **Milestone 9C (Stages J.1–J.6):** Technically complete and verified on local environments.
- **Production Deployment:** **NOT AUTHORIZED.**
- **Regulatory Status:** Formal approvals from the Department of Wildlife Conservation (DWC) and Sri Lanka Railways (SLR) remain pending.
