# ELEGUARD RAIL — Manual System Testing Results Report

**Document Reference:** `ER-MANUAL-TEST-RESULTS-001`  
**Execution Timestamp:** 2026-11-15 13:58:00 IST  
**Target System:** ELEGUARD RAIL Release Candidate 1.0  
**Repository Location:** `C:\Users\user\Desktop\Railway\eleguard-rail`  
**Web Server Root:** `C:\xampp\htdocs\eleguard-rail`  
**Operational Target:** Public GitHub Publication & LinkedIn Engineering Showcase  

---

## 1. Executive Summary

This report documents the execution of the **28-test manual system verification programme** defined in `ER-MANUAL-TEST-PLAN-001`. The test programme was executed under rigorous isolation safeguards:
- **Phase 0 Pre-Execution Safety:** Operational database baseline was cryptographically recorded via SHA-256 table hashes before test execution. Ephemeral test database creation was verified via fail-closed validation (`tests/bootstrap.php`).
- **Strict Isolation of Operational Database:** All state-changing tests (record insertion, update, deletion, verification, alert creation, and driver receipts) were executed strictly within ephemeral test databases (`eleguard_test_tmp_*`).
- **Post-Test Operational Invariance:** All 8 operational tables in `eleguard_rail` retained 100% SHA-256 content and row count invariance (`elephant_reports`: 43, `system_logs`: 185, `alerts`: 15, `driver_acknowledgements`: 13, `incident_history`: 3, `hotspots`: 4, `railway_sections`: 4, `users`: 5).

### Summary of Manual Testing Results
| Total Test Cases | Passed | Defects Discovered & Logged | Blocked / Untested | Operational Data Mutations |
| :---: | :---: | :---: | :---: | :---: |
| **28** | **26** | **2** | **0** | **EXACTLY ZERO (0)** |

---

## 2. Detailed Results by Functional Domain

---

### Section A: Authentication and Session Management

| Test ID | Test Item & Scope | Safety Class | Data Mutation | Status | Expected vs. Actual Result & Evidence |
| :--- | :--- | :---: | :---: | :---: | :--- |
| **TC-AUTH-01** | Public Landing Page & Login Navigation | `[READ-ONLY]` | `NO` | **PASS** | **Expected:** Landing page and login return HTTP 200 with institutional styling.<br>**Actual:** Landing returned HTTP 200; `/public/login.php` returned HTTP 200 with `<title>Login \| EleGuard Rail</title>`.<br>*Evidence:* Direct HTTP GET response inspection. |
| **TC-AUTH-02** | Universal Login Form CSRF Protection | `[READ-ONLY]` | `NO` | **PASS** | **Expected:** Hidden input `csrf_token` contains a 64-char hexadecimal string.<br>**Actual:** Verified valid 64-char hex token in `<input type="hidden" name="csrf_token">`; session cookie assigned `HttpOnly; SameSite=Lax`.<br>*Evidence:* DOM inspection of `/public/login.php`. |
| **TC-AUTH-03** | Multi-Role Authentication & Redirection | `[DISPOSABLE DB REQUIRED]` | `YES` | **PASS** | **Expected:** Standard role passwords verify cleanly via bcrypt against canonical schema.<br>**Actual:** Verified all 5 roles (`admin`, `control_officer`, `wildlife_officer`, `driver`, `reporter`) against bcrypt hashes in ephemeral DB.<br>*Evidence:* Password verification assertions in `eleguard_test_tmp_*`. |
| **TC-AUTH-04** | Session Termination & Invalidation (Logout) | `[DISPOSABLE DB REQUIRED]` | `YES` | **PASS** | **Expected:** Logout issues HTTP 302 redirecting to `login.php` and terminates session.<br>**Actual:** HTTP 302 Found returned with `Location: /eleguard-rail/public/login.php`; session invalidated.<br>*Evidence:* Response header trace from `/public/logout.php`. |

---

### Section B: Admin Dashboard and User Management

| Test ID | Test Item & Scope | Safety Class | Data Mutation | Status | Expected vs. Actual Result & Evidence |
| :--- | :--- | :---: | :---: | :---: | :--- |
| **TC-ADM-01** | Admin Operational Dashboard Metric Cards | `[READ-ONLY]` | `NO` | **PASS** | **Expected:** Admin dashboard returns HTTP 200 and renders KPI overview.<br>**Actual:** HTTP 200 OK returned; verified "Admin Command Center", "Total Users", and "System Health" in rendered DOM.<br>*Evidence:* Authenticated HTTP GET with admin session. |
| **TC-ADM-02** | User Lifecycle Management (Create, Update, Deactivate) | `[DISPOSABLE DB REQUIRED]` | `YES` | **PASS** | **Expected:** User is created with bcrypt hash, updated, and deactivated cleanly.<br>**Actual:** Created user with bcrypt hash, updated name to "Senior Ranger", deactivated status to `inactive`.<br>*Evidence:* SQL lifecycle inspection in ephemeral DB. |

---

### Section C: Control Officer Dashboard and GIS

| Test ID | Test Item & Scope | Safety Class | Data Mutation | Status | Expected vs. Actual Result & Evidence |
| :--- | :--- | :---: | :---: | :---: | :--- |
| **TC-CTL-01** | Control Desk KPI Overview & Layout | `[READ-ONLY]` | `NO` | **PASS** | **Expected:** Dashboard displays Active Alerts, Critical Hazards, Verified Sightings.<br>**Actual:** HTTP 200 OK returned; verified `.stat-card` KPI elements in rendered DOM.<br>*Evidence:* Authenticated HTTP GET with control officer session. |
| **TC-CTL-02** | Interactive GIS Corridor Map Rendering | `[EXTERNAL SERVICE REQUIRED]` | `NO` | **PASS** | **Expected:** OSM tile server returns 200 OK; corridor GeoJSON delivers full polyline geometry.<br>**Actual:** `https://tile.openstreetmap.org/7/96/60.png` returned HTTP 200; `railway_routes.geojson` returned HTTP 200 (73,838 bytes). 3 proximity zones verified.<br>*Evidence:* Live tile header trace & GeoJSON payload audit. |
| **TC-CTL-03** | Railway Corridor Alert Broadcast | `[DISPOSABLE DB REQUIRED]` | `YES` | **PASS** | **Expected:** Alert is broadcasted and saved as Active with Critical severity.<br>**Actual:** Alert ID created (Section 1, Level: Critical, Status: Active, Created By: 2).<br>*Evidence:* SQL query of `alerts` table in ephemeral DB. |

---

### Section D: Wildlife Officer Reporting and Verification

| Test ID | Test Item & Scope | Safety Class | Data Mutation | Status | Expected vs. Actual Result & Evidence |
| :--- | :--- | :---: | :---: | :---: | :--- |
| **TC-WLD-01** | Verification Workspace Status Tabs & Filtering | `[READ-ONLY]` | `NO` | **PASS** | **Expected:** Wildlife verification workspace loads with status tabs.<br>**Actual:** HTTP 200 OK returned; verified `<title>Verify Reports \| EleGuard Rail Dashboard</title>`.<br>*Evidence:* Authenticated HTTP GET with wildlife officer session. |
| **TC-WLD-02** | Detailed Sighting Inspection & Demographics | `[READ-ONLY]` | `NO` | **PASS** | **Expected:** Sightings present geographic coordinates, herd count, and urgency level.<br>**Actual:** Verified baseline report: Count=3, Lat=8.0350, Lon=80.7520, Status=Verified, Urgency=High.<br>*Evidence:* Read-only SQL inspection of sighting record. |
| **TC-WLD-03** | Sighting Verification Decision Execution | `[DISPOSABLE DB REQUIRED]` | `YES` | **PASS** | **Expected:** Report status transitions from Pending to Verified.<br>**Actual:** Pending report transitioned to `Verified` via transactional update in test DB.<br>*Evidence:* SQL before/after query in ephemeral DB. |

---

### Section E: Field Reporter PWA

| Test ID | Test Item & Scope | Safety Class | Data Mutation | Status | Expected vs. Actual Result & Evidence |
| :--- | :--- | :---: | :---: | :---: | :--- |
| **TC-PWA-01** | Service Worker Registration & Manifest Delivery | `[READ-ONLY]` | `NO` | **DEFECT LOGGED** *(DEF-002)* | **Expected:** Manifest delivers valid JSON config with name "EleGuard Rail - Field Reporter".<br>**Actual:** HTTP 200 OK; JSON manifest parsed with name `"EleGuard Rail Field Telemetry"`. Service worker `sw.js` (5,611 B) served cleanly.<br>*Evidence:* HTTP GET of `/public/manifest.webmanifest`. *(Logged as minor documentation naming defect DEF-002)*. |
| **TC-PWA-02** | Guided 6-Step Field Capture Wizard | `[READ-ONLY]` | `NO` | **PASS** | **Expected:** PWA delivers guided observation capture interface.<br>**Actual:** HTTP 200 OK; step indicators and telemetry forms verified in rendered HTML.<br>*Evidence:* Authenticated HTTP GET with reporter session. |

---

### Section F: GPS and Geospatial Calculations

| Test ID | Test Item & Scope | Safety Class | Data Mutation | Status | Expected vs. Actual Result & Evidence |
| :--- | :--- | :---: | :---: | :---: | :--- |
| **TC-GPS-01** | Forward Geodesic Projection Accuracy | `[DISPOSABLE DB REQUIRED]` | `YES` | **PASS** | **Expected:** Forward spherical projection and inverse distance match within 1 cm.<br>**Actual:** Observer at (8.0345, 80.2850), standoff 80.0 m at 45.0°; inverse distance verified at 80.000 m (error < 0.001 m).<br>*Evidence:* Great-Circle forward trigonometric assertion log. |
| **TC-GPS-02** | Root-Sum-Square (RSS) Spatial Uncertainty Propagation | `[READ-ONLY]` | `NO` | **PASS** | **Expected:** Spatial uncertainty matches RSS formula (~22.49 m) and suppresses unknown circles on missing accuracy.<br>**Actual:** Computed RSS variance = 22.49 m. Null-accuracy fail-closed behavior verified.<br>*Evidence:* Mathematical calculation log. |

---

### Section G: Photograph Upload and Validation

| Test ID | Test Item & Scope | Safety Class | Data Mutation | Status | Expected vs. Actual Result & Evidence |
| :--- | :--- | :---: | :---: | :---: | :--- |
| **TC-UPL-01** | File Extension & MIME-Type Validation | `[DISPOSABLE DB REQUIRED]` | `YES` | **PASS** | **Expected:** PHP Fileinfo reliably verifies true MIME type as image/jpeg.<br>**Actual:** Server-side `finfo_file` verified test asset as `image/jpeg`.<br>*Evidence:* PHP Fileinfo execution output. |

---

### Section H: Offline Storage and Synchronization

| Test ID | Test Item & Scope | Safety Class | Data Mutation | Status | Expected vs. Actual Result & Evidence |
| :--- | :--- | :---: | :---: | :---: | :--- |
| **TC-OFF-01** | Offline Fallback Shell Display | `[READ-ONLY]` | `NO` | **PASS** | **Expected:** Dedicated offline fallback shell served with HTTP 200 OK.<br>**Actual:** HTTP 200 OK returned; verified "Offline Mode \| EleGuard Rail Field Telemetry" badge and retry controls.<br>*Evidence:* HTTP GET of `/public/offline.html`. |
| **TC-OFF-02** | Client-Side Encrypted Storage (AES-GCM in IndexedDB) | `[READ-ONLY]` | `NO` | **PASS** | **Expected:** Client storage engine implements AES-GCM encryption via Web Crypto API.<br>**Actual:** Audited `field_crypto_storage.js`; verified AES-GCM cipher suite and `crypto.subtle` encryption primitives.<br>*Evidence:* Source code audit of `public/assets/js/field_crypto_storage.js`. |
| **TC-OFF-03** | Background Synchronization with Idempotency Key | `[DISPOSABLE DB REQUIRED]` | `YES` | **PASS** | **Expected:** Client UUID idempotency key prevents duplicate sighting insertions.<br>**Actual:** Sighting inserted with UUID; duplicate insertion query matched existing ID and prevented duplicate row.<br>*Evidence:* Transactional idempotency verification on test DB. |

---

### Section I: Driver Advisory HUD

| Test ID | Test Item & Scope | Safety Class | Data Mutation | Status | Expected vs. Actual Result & Evidence |
| :--- | :--- | :---: | :---: | :---: | :--- |
| **TC-DRV-01** | Locomotive Cab Advisory HUD & Stationary Safety Banner | `[READ-ONLY]` | `NO` | **DEFECT LOGGED** *(DEF-001)* | **Expected:** Driver interface displays mandatory stationary safety regulation banner.<br>**Actual:** When accessed on the operational database, `AlertService::getDriverAdvisories()` throws a `PDOException: Table 'eleguard_rail.report_verifications' doesn't exist` because Migration 04 was not run on `eleguard_rail`. On the disposable test database (where Migration 04 is applied), it returns 14 advisories and renders the banner cleanly.<br>*Evidence:* Operational database query trace vs. test database output. *(Logged as high-severity operational defect DEF-001)*. |
| **TC-DRV-02** | Stationary Driver Acknowledgement Receipt | `[DISPOSABLE DB REQUIRED]` | `YES` | **PASS** | **Expected:** Acknowledgement recorded with stationary review status and driver note.<br>**Actual:** Receipt created in `driver_acknowledgements` with `action_status='acknowledged'`.<br>*Evidence:* Database verification in ephemeral DB. |

---

### Section J: Role-Based Access Control (RBAC)

| Test ID | Test Item & Scope | Safety Class | Data Mutation | Status | Expected vs. Actual Result & Evidence |
| :--- | :--- | :---: | :---: | :---: | :--- |
| **TC-RBAC-01** | Unauthenticated Endpoint Redirection | `[READ-ONLY]` | `NO` | **PASS** | **Expected:** All 4 protected dashboards return HTTP 302 redirecting to `/public/login.php`.<br>**Actual:** All 4 role dashboards (`admin`, `control`, `wildlife`, `driver`) issued clean 302 Found with `Location: /eleguard-rail/public/login.php`.<br>*Evidence:* HTTP GET header audits across all roles. |
| **TC-RBAC-02** | Cross-Role Privilege Escalation Denial | `[READ-ONLY]` | `NO` | **PASS** | **Expected:** Cross-role violation redirects user to their own authorized dashboard.<br>**Actual:** Driver session attempting to access `/admin/dashboard.php` returned HTTP 302 with `Location: /eleguard-rail/public/driver/dashboard.php`.<br>*Evidence:* Authenticated HTTP header trace for cross-role violation. |

---

### Section K: CSRF and Application Security

| Test ID | Test Item & Scope | Safety Class | Data Mutation | Status | Expected vs. Actual Result & Evidence |
| :--- | :--- | :---: | :---: | :---: | :--- |
| **TC-SEC-01** | Cross-Site Request Forgery (CSRF) Token Rejection | `[DISPOSABLE DB REQUIRED]` | `NO` | **PASS** | **Expected:** State-changing POST without CSRF token is rejected.<br>**Actual:** POST to `login.php` without CSRF token returned rejection error; zero queries executed.<br>*Evidence:* Tampered POST request without valid `csrf_token`. |
| **TC-SEC-02** | SQL Injection Resistance (PDO Prepared Statements) | `[READ-ONLY]` | `NO` | **PASS** | **Expected:** Injected SQL payload is safely cast or parameterized without exposing database errors.<br>**Actual:** Injected `1' OR '1'='1` into `section_id`; input safely rejected/cast by `is_numeric()`; zero SQL exceptions disclosed.<br>*Evidence:* SQL injection probe on query parameters. |

---

### Section L: Database Integrity and Audit Logging

| Test ID | Test Item & Scope | Safety Class | Data Mutation | Status | Expected vs. Actual Result & Evidence |
| :--- | :--- | :---: | :---: | :---: | :--- |
| **TC-AUD-01** | Immutable Audit Trail Logging | `[DISPOSABLE DB REQUIRED]` | `YES` | **PASS** | **Expected:** Audit log is created with user ID, action type, description, and timestamp.<br>**Actual:** Verified chronological audit entry created in `system_logs` table.<br>*Evidence:* SQL query dump of `system_logs` in ephemeral DB. |
| **TC-AUD-02** | Operational Database Baseline Invariance | `[READ-ONLY]` | `NO` | **PASS** | **Expected:** All 8 operational tables retain 100% SHA-256 content and row count invariance.<br>**Actual:** All 8 tables (`elephant_reports`: 43, `system_logs`: 185, `alerts`: 15, `driver_acknowledgements`: 13, `incident_history`: 3, `hotspots`: 4, `railway_sections`: 4, `users`: 5) matched exact pre-test SHA-256 hashes.<br>*Evidence:* Pre-test vs. post-test cryptographic hash comparison across all 8 tables. |

---

## 3. Discovered Defects Summary

| Defect ID | Associated Test | Severity | Description | Operational Impact | Recommended Remediation |
| :---: | :---: | :---: | :--- | :--- | :--- |
| **DEF-001** | `TC-DRV-01` | **High (P2)** | Operational database `eleguard_rail` was frozen at Migration 03 and lacks Migration 04 (`04_add_field_reporting_v2_to_elephant_reports.sql`). `AlertService::getDriverAdvisories()` fails on `report_verifications` table. | Driver advisory overview throws PDOException on operational database. Operates cleanly on test databases where Migration 04 is applied. | Execute Migration 04 on `eleguard_rail` when deploying live or pilot instances. |
| **DEF-002** | `TC-PWA-01` | **Low (P4)** | `public/manifest.webmanifest` specifies `name: "EleGuard Rail Field Telemetry"` whereas some documentation refers to `"EleGuard Rail - Field Reporter"`. | Purely a cosmetic string discrepancy in PWA title. | Standardize documentation references to `"EleGuard Rail Field Telemetry"`. |
