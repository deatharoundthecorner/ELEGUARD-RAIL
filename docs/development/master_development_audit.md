# ELEGUARD RAIL — Master Architecture & Technical Repository Audit
**Document Reference:** `ER-AUDIT-MASTER-2026-001`
**Execution Timestamp:** 2026-11-17 19:58:00 IST
**Audit Target:** ELEGUARD RAIL Release Candidate 1.0 / Milestone 9C Hardening Baseline
**Repository Location:** `C:\Users\user\Desktop\Railway\eleguard-rail`
**Reference Git Commit:** `52223b42f941af091e9dc6733934918828589ae2` (`origin/main`)
**Auditor Roles:** Principal Software Architect, Senior PHP Engineer, GIS Engineer, AI/ML Engineer, Database Engineer, Application Security Engineer, QA Lead

---

## 1. Executive Summary & Audit Context

This master technical audit provides a forensic, source-verified evaluation of the **ELEGUARD RAIL** platform across its architecture, runtime configurations, database states, public API contracts, GIS telemetry models, AI inference pipelines, offline PWA storage, application security posture, and automated test coverage.

### Core Audit Findings Overview:
1. **Repository Health & Codebase Maturity:** The repository contains a cohesive, enterprise-grade PHP 8.2 backend, vanilla ES6 modular frontend, and rigorous test automation harness. Zero external PHP vendor frameworks are used; core utilities (routing, authentication, database singleton, validation, image upload) are self-contained and strictly typed.
2. **Database Schema Divergence & Multi-Database Landscape:** Three distinct databases exist in the local environment:
   - `eleguard_rail`: Operational historical baseline (8 tables, 43 reports), intentionally frozen at Migration 03.
   - `eleguard_rail_demo`: Demonstration database (12 tables, 44 reports), containing all sequential migrations (01 through 06) and verified demonstration records.
   - `eleguard_rail_test`: Persistent test database (12 tables, 43 reports), strictly protected from automated test mutation.
   - *Key Defect Identified:* When local runtime environments point `DB_NAME` to `eleguard_rail` instead of `eleguard_rail_demo`, subsystems relying on post-Migration 03 schema (such as `report_verifications`, `report_clarifications`, and `observer_latitude`) trigger database exceptions (e.g. `Table 'eleguard_rail.report_verifications' doesn't exist`).
3. **Apache RESTful URL Routing Gap:** While API v2 endpoint scripts (`submit.php`, `sync_batch.php`, `my.php`, `get.php`) function when called directly via their `.php` extensions, clean RESTful paths (e.g. `/api/v2/reports/sync-batch`, `/api/v2/reports/my`) return HTTP 404 under Apache due to the absence of a `public/.htaccess` rewrite rule or front-controller fallback.
4. **AI Sighting Detection Engine:** Verified operational using local ONNX Runtime 1.31.1 with `yolox_tiny.onnx` (Apache 2.0 license). Detections return structured bounding boxes, confidence scores, and strict human-in-the-loop advisory disclaimers within 25–55 ms.
5. **Security Posture:** Zero critical SQL injection, authentication bypass, or unescaped XSS vulnerabilities were identified. All database queries employ PDO prepared statements with `PDO::ATTR_EMULATE_PREPARES => false`. Image uploads are secured with strict 2MB limits, MIME magic-byte checks, and GD image re-encoding.
6. **Automated Verification:** The unified test runner (`tests/run_all_tests.php`) executes 35 distinct test suites (1,647+ assertions) in 35–37 seconds with 100% pass rate, strictly enforcing disposable ephemeral test databases (`eleguard_test_tmp_*`).

---

## 2. Complete Repository & File Structure Map

```
eleguard-rail/
├── .env                                  # Local environment configuration (relocatable, untracked)
├── .env.example                          # Canonical environment configuration template
├── .gitignore                            # VCS ignore rules protecting secrets, backups, and temporary DBs
├── .htaccess                             # Root Apache hardening denying direct access to backend dirs
├── index.php                             # Root redirector forwarding entry traffic to /public/
├── LICENSE                               # MIT Open-Source License
├── README.md                             # Comprehensive technical documentation & showcase manual
├── ai/                                   # AI Detection Engine & Dataset Assets
│   ├── analytics/                        # Data exploration and quality audit scripts
│   ├── datasets/                         # Authentic elephant, non-elephant, and edge-case images
│   ├── detector/                         # Core Python YOLOX-Tiny inference engine & CLI wrapper
│   │   ├── cli.py                        # CLI interface invoked by PHP AiDetectionService
│   │   └── detector.py                   # ONNX Runtime inference, NMS, and bounding box drawing
│   ├── docs/                             # AI architecture and licensing documentation
│   ├── licenses/                         # Apache 2.0 license text for YOLOX
│   ├── models/                           # Serialized ONNX neural network weights
│   │   ├── yolox_tiny.onnx               # Primary production advisory model (Apache 2.0)
│   │   └── yolov8n.onnx                  # Historical evaluation baseline (AGPL-3.0)
│   └── tests/                            # PyTest unit tests for detector reliability
├── app/                                  # Enterprise PHP 8.2 Application Core
│   ├── config/                           # Environment and runtime configurations
│   │   ├── app.php                       # Application metadata, timezone (Asia/Colombo)
│   │   ├── database.php                  # Database credential parsing & connection parameters
│   │   └── sanity.php                    # Headless CMS integration configuration
│   ├── core/                             # Foundational architectural singletons
│   │   ├── Auth.php                      # RBAC, secure session management, activity logging
│   │   ├── Database.php                  # PDO singleton with test-context safety gates
│   │   ├── Router.php                    # Path normalization and relative URL generation
│   │   └── Session.php                   # Session lifecycle, flash messages, CSRF tokens
│   ├── helpers/                          # Shared presentation and API formatting helpers
│   ├── includes/                         # Security helpers (validation, image upload sanitization)
│   │   ├── upload.php                    # Multi-tier image upload validator with GD re-encoding
│   │   └── validation.php                # Strict input validators and sanitizers
│   ├── layouts/                          # Modular HTML5 presentation templates
│   ├── middleware/                       # HTTP request interception (auth_required, role_required)
│   └── services/                         # Core Domain & Business Logic Services
│       ├── AiDetectionService.php        # Python CLI bridge for YOLOX-Tiny inference
│       ├── AlertService.php              # Active hazard alert queries & driver advisories
│       ├── AnalyticsService.php          # Read-only historical analytics & aggregations
│       ├── DuplicateService.php          # Spatial-temporal sighting deduplication
│       ├── FieldReportingService.php     # Geodesic solver, diurnal continuum, batch sync
│       ├── GisService.php                # Proximity analysis & GeoJSON corridor layer provider
│       ├── ProximityService.php          # Mathematical point-to-segment distance calculations
│       ├── RiskService.php               # Multi-factor dynamic risk scoring algorithm
│       ├── TemporalService.php           # Sighting age decay & temporal classification
│       ├── UserService.php               # User account management & RBAC administration
│       └── VerificationService.php       # Two-tier DWC biological verification FSM
├── database/                             # Database Schemas, Migrations & Seeders
│   ├── eleguard_rail.sql                 # Baseline schema definitions (Tables 01–08)
│   ├── create_sample_users.php           # Bcrypt user seeder script
│   └── migrations/                       # Sequential SQL migrations (01–06) and rollback scripts
├── docs/                                 # Architectural Documentation & Handover Dossiers
│   ├── final_handover/                   # Production handover, defect registers, showcase evidence
│   ├── phase9/                           # Milestone 9C Stage 4E, Stage I, and Stage J reports
│   └── presentation/                     # Verified PDF and PowerPoint stakeholder presentation decks
├── public/                               # Web Server Public Document Root
│   ├── .htaccess                         # [DEFECT: Missing] Apache URL rewrite & front controller
│   ├── index.php                         # Public portal landing page
│   ├── login.php / logout.php            # Secure authentication controllers
│   ├── manifest.webmanifest              # Official PWA web app manifest
│   ├── sw.js                             # Service Worker (Cache-First shell, offline fallback)
│   ├── admin/                            # Administrative dashboard, analytics, user lifecycle
│   ├── api/                              # REST API endpoints (GIS, analytics, API v2 reports)
│   ├── assets/                           # Static CSS, JavaScript, GeoJSON, icons, and vendor files
│   ├── control/                          # Railway Control Desk corridor monitoring interface
│   ├── driver/                           # Locomotive Driver HUD (stationary review interface)
│   ├── field/                            # Field Reporting PWA (offline guided wizard)
│   ├── reporter/                         # Community reporter sighting submission & history
│   └── wildlife/                         # Wildlife Officer verification workspace & clarification desk
└── tests/                                # Automated Testing Framework & Test Suites
    ├── bootstrap.php                     # Test environment guard & disposable DB validator
    ├── run_all_tests.php                 # Master regression test orchestrator (35 suites)
    ├── fixtures/canonical_baseline.sql   # Ephemeral database initialization schema
    └── test_*.php                        # 35 unit, integration, and security test suites
```

---

## 3. Architecture & PHP Subsystem Audit

### 3.1 Foundational Architecture (`app/core/`)
- **`Auth.php`:** Implements role-based access control across five distinct roles (`admin`, `control_officer`, `wildlife_officer`, `driver`, `reporter`). On authentication, `session_regenerate_id(true)` is strictly called, mitigating session fixation. Passwords are authenticated via PHP native `password_verify()` against standard Bcrypt hashes (`PASSWORD_BCRYPT`). Activity is audited to `system_logs`.
- **`Database.php`:** Thread-safe singleton providing two connection contexts:
  1. Default Read/Write PDO connection using application credentials.
  2. Read-Only Analytics PDO connection using `eleguard_readonly` credentials to physically enforce engine-level mutation denial.
  *Safety Gates:* Enforces strict safety gates in CLI test context, instantly throwing fatal exceptions if an automated test targets `eleguard_rail` or `eleguard_rail_test`. Tests must target an approved ephemeral pattern (`eleguard_test_tmp_*`).
- **`Router.php`:** Relocatable URL resolver dynamically calculating subpaths across root, subfolder, and virtual host deployments without hardcoded hostnames.
- **`Session.php`:** Hardens session cookies (`HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS active). Generates cryptographically secure 64-character hex CSRF tokens validated via timing-safe `hash_equals()`.

### 3.2 Service Layer (`app/services/`)
- **`FieldReportingService.php`:** High-complexity domain service (1,323 lines). Implements forward Great-Circle geodesic projection from observer position, Root-Sum-Square (RSS) error propagation, 7-period continuous diurnal continuum classification, topological railway section association, RFC 9457 error formatting, and RFC 4122 v4 UUID transactional idempotency.
- **`VerificationService.php`:** Implements finite-state machine transitions for sighting lifecycle (`Pending` $\to$ `Verified` / `Needs Information` / `Rejected`). Mandates human officer authentication; prevents automated transition promotion.
- **`AlertService.php`:** Manages corridor hazard warnings, broadcast lifecycles, and driver acknowledgement logging.
- **`AiDetectionService.php`:** Subprocess bridge executing Python CLI detector with configurable execution timeouts (5.0s), returning structured detection payloads without blocking report creation on failure.

---

## 4. Database Architecture & Schema Audit

### 4.1 Schema Migration Sequence (01 through 06)

| Migration File | Schema Additions / Transformations | Intended Purpose |
| :--- | :--- | :--- |
| `01_add_gps_coordinates...` | `latitude`, `longitude`, `gps_accuracy_meters`, composite spatial index. | Core georeferencing for sightings. |
| `02_add_ai_detection...` | `ai_status`, `ai_detected_elephant`, `ai_confidence`, `ai_estimated_count`, `ai_model_version`, `ai_detection_notes`, `ai_processed_at`. | AI advisory inference telemetry. |
| `03_add_temporal_intelligence...` | `sighting_date`, `sighting_time`, `sighting_time_precision`, `sighting_time_window`, `sighting_time_uncertainty_minutes`, `sighting_timezone`, `gps_captured_at`. | Sighting age decay and temporal analysis. |
| `04_add_field_reporting_v2...` | `client_report_uuid`, `submission_source`, `observation_category`, `diurnal_period`, `observer_latitude`, `observer_longitude`, `observer_gps_accuracy_meters`, `observation_distance_meters`, `observation_bearing_degrees`, `estimated_animal_latitude`, `estimated_animal_longitude`, `animal_location_uncertainty_meters`, `track_proximity_zone`, and creates `report_verifications` table. | Decoupled field reporting, observer/animal coordinate separation, and verification records. |
| `05_add_verification_and_section...` | Expands `report_status` enum; creates `report_clarifications`, `report_section_assignment_history`, and `railway_milestones` tables. | Structured two-way clarification threads and railway track attribution audit history. |
| `06_add_payload_hash...` | `payload_hash CHAR(64)` on `elephant_reports` with index `idx_reports_payload_hash`. | Deterministic SHA-256 payload fingerprinting for duplicate detection. |

### 4.2 Multi-Database Environment Inventory

```
+------------------------------------+----------+-------------------+---------------------------------------------------+
| Database Name                      | Tables   | Elephant Reports  | Schema State & Purpose                            |
+------------------------------------+----------+-------------------+---------------------------------------------------+
| eleguard_rail                      | 8 tables | 43 records        | Historical baseline; FROZEN at Migration 03.      |
| eleguard_rail_demo                 | 12 tables| 44 records        | Demonstration environment; ALL Migrations 01–06.  |
| eleguard_rail_test                 | 12 tables| 43 records        | Persistent baseline; protected from write tests.  |
| eleguard_test_tmp_* (Ephemeral)    | 12 tables| Variable (43+)    | Disposable schemas created & dropped per test run.|
+------------------------------------+----------+-------------------+---------------------------------------------------+
```

### 4.3 Database Integrity Constraints & Findings
- **DEF-AUD-01 (High): Runtime Target Inconsistency.** The active project `.env` in `C:\Users\user\Desktop\Railway\eleguard-rail` defines `DB_NAME=eleguard_rail`. Because `eleguard_rail` was intentionally frozen at Migration 03 to maintain a historical benchmark, any web interface attempting to read `report_verifications` (such as the Driver Dashboard or Wildlife Verification workspace) fails with a MySQL 1146 table missing error.
  *Remediation Plan:* Set `DB_NAME=eleguard_rail_demo` in `.env` for local demonstration and integration testing, preserving `eleguard_rail` in its unmutated state.

---

## 5. Public API & Communication Protocols Audit

### 5.1 Endpoint Inventory & Standards Compliance

```
+---------------------------------------+--------+-----------------------+--------------------+-------------------------+
| Endpoint Path                         | Method | Security / Auth       | Standard / Format  | Handled Statuses        |
+---------------------------------------+--------+-----------------------+--------------------+-------------------------+
| /public/api/gis_data.php              | GET    | Session (Roles 1-4)   | GeoJSON FeatureColl| 200, 401, 403, 500      |
| /public/api/analytics/summary.php     | GET    | Session (Analytics)   | JSON Envelope      | 200, 401, 403           |
| /public/api/analytics/hotspots.php    | GET    | Session (Analytics)   | JSON Envelope      | 200, 401, 403           |
| /public/api/analytics/sections.php    | GET    | Session (Analytics)   | JSON Envelope      | 200, 401, 403           |
| /public/api/analytics/temporal.php    | GET    | Session (Analytics)   | JSON Envelope      | 200, 401, 403           |
| /public/api/analytics/duplicates.php  | GET    | Session (Analytics)   | JSON Envelope      | 200, 401, 403           |
| /public/api/analytics/quality.php     | GET    | Session (Analytics)   | JSON Envelope      | 200, 401, 403           |
| /public/api/analytics/ai_advisory.php | GET    | Session (Analytics)   | JSON Envelope      | 200, 401, 403           |
| /public/api/v2/reports/submit.php     | POST   | Session + CSRF        | RFC 9457 / JSON    | 201, 200 (Replay), 422  |
| /public/api/v2/reports/sync_batch.php | POST   | Session + CSRF        | HTTP 207 Multi-Stat| 207, 413, 422, 405      |
| /public/api/v2/reports/my.php         | GET    | Session (Reporter)    | JSON Envelope      | 200, 401                |
| /public/api/v2/reports/get.php        | GET    | Session (Role Masked) | JSON Envelope      | 200, 401, 404           |
| /public/api/v2/reports/verify.php     | POST   | Session (Wildlife/Adm)| RFC 9457 / JSON    | 200, 401, 403, 409, 422 |
| /public/api/v2/reports/clarify.php    | POST   | Session + CSRF        | RFC 9457 / JSON    | 200, 401, 403, 422      |
+---------------------------------------+--------+-----------------------+--------------------+-------------------------+
```

### 5.2 Key API Findings
- **DEF-AUD-02 (Medium): Missing Apache Rewrite Rules for RESTful URLs.** When requests target `/api/v2/reports/sync-batch` or `/api/v2/reports/my` without `.php`, Apache returns HTTP 404 because `public/.htaccess` does not exist to route non-file requests to `public/api/v2/reports/index.php`.
- **RFC 9457 Adherence:** Error payloads produced by `send_rfc9457_error()` in `api_helper.php` strictly output `application/problem+json` containing `type`, `title`, `status`, `detail`, `instance`, and `invalid_params`.
- **Payload & Batch Guardrails:** `sync_batch.php` strictly enforces the 50-report count limit and 10MB raw request body ceiling, returning HTTP 422 and HTTP 413 respectively before database execution.

---

## 6. GIS Data, Spatial Engine & Corridor Intelligence Audit

### 6.1 Spatial Architecture & Distance Calculation
- **Railway Geometry:** Vector track data is sourced from OpenStreetMap (`railway=rail`) and stored locally in `public/assets/data/railway_routes.geojson`.
- **Distance to Track Segment:** `ProximityService::pointToSegmentDistance()` projects animal coordinates onto LineString segments using spherical trigonometry and Great-Circle distance formulas ($d = 2R \arcsin \sqrt{\dots}$), calculating perpendicular distance to track centerline.
- **Proximity Buffer Zoning:**
  - **Zone 0 (Track Clearance):** 0 to 15 metres. Immediate danger of locomotive collision.
  - **Zone 1 (Near-Corridor):** 15 to 100 metres. Active alert radius; potential track entry.
  - **Zone 2 (Corridor Buffer):** 100 to 1,000 metres. Situational awareness monitoring.

### 6.2 Forward Geodesic Solver & Uncertainty Math
- **The Standoff Problem:** Wild elephants cannot be safely approached. Observers record observer GNSS coordinates $(\phi_1, \lambda_1)$, optical range $d$ (metres), and compass azimuth $\theta$ (degrees).
- **Forward Great-Circle Calculation:**
  $$\phi_2 = \arcsin(\sin \phi_1 \cos \delta + \cos \phi_1 \sin \delta \cos \theta)$$
  $$\lambda_2 = \lambda_1 + \arctan2(\sin \theta \sin \delta \cos \phi_1, \cos \delta - \sin \phi_1 \sin \phi_2)$$
  where $\delta = d / R_{\text{earth}}$.
- **Root-Sum-Square (RSS) Error Propagation:**
  Uncertainty combines observer GNSS receiver error $\sigma_{\text{gps}}$, assumed $\pm 20\%$ optical range error $\sigma_{\text{dist}} = 0.20 \cdot d$, and compass variance $\sigma_{\text{bearing}} = 10^\circ$:
  $$\sigma_{\text{transverse}} = d \cdot \sin(10^\circ)$$
  $$\sigma_{\text{total}} = \sqrt{\sigma_{\text{gps}}^2 + \sigma_{\text{dist}}^2 + \sigma_{\text{transverse}}^2}$$
- **Junction Topology Disclaimers:** When animal coordinates fall within Gal Oya or Maho railway junction polygons, section attribution is intentionally flagged as `topology_ambiguity` to prevent incorrect automated dispatch.

---

## 7. AI-Assisted Wildlife Verification Audit

### 7.1 Model & Runtime Specifications
- **Model Architecture:** YOLOX-Tiny ONNX (`yolox_tiny.onnx`).
- **Permissive Licensing:** Apache License 2.0 (Verified via `ai/licenses/YOLOX_LICENSE.txt`).
- **Inference Runtime:** ONNX Runtime 1.31.1 executed via Python subprocess (`ai/detector/cli.py`).
- **Input Dimension:** $416 \times 416$ RGB tensor with BGR normalization and letterbox padding.
- **Execution Performance:** Measured inference time: $24.23\text{ ms}$; end-to-end execution: $55.69\text{ ms}$.

### 7.2 Classification Thresholds & Governance
- **Confidence Tiers:**
  - $\ge 0.40$: Confirmed Elephant Candidate (`Potential Elephant Detected`).
  - $0.20 \le \text{conf} < 0.40$: Uncertain Sighting (`Uncertain Sighting - Verification Required`).
  - $< 0.20$: Negative Candidate (`No Elephant Detected`).
- **Safety Boundary Invariant:** AI detection output is strictly advisory. Under no circumstances can AI change report status from `Pending` to `Verified` or issue train alerts. Sole verification authority resides with authorized DWC Wildlife Officers.

---

## 8. Field Reporting PWA & Offline Storage Audit

### 8.1 Cryptographic Storage Architecture (`field_crypto_storage.js`)
- **Cipher:** AES-GCM-256 via native W3C Web Cryptography API (`crypto.subtle`).
- **IV Generation:** Cryptographically secure 96-bit (12-byte) unique IV generated per write via `crypto.getRandomValues()`. IV reuse is strictly prevented.
- **Key Derivation:** PBKDF2-HMAC-SHA256 with 250,000 iterations and 16-byte random salt. Passphrase is held only in volatile memory; never persisted to IndexedDB or localStorage.
- **AAD Cryptographic Binding:** Additional Authenticated Data binds client UUID, schema version, and creation timestamp into the GCM authentication tag, detecting ciphertext tampering.
- **Passive 72-Hour Expiry Guard:** Rather than relying on unreliable background timers, expired drafts are purged deterministically upon PWA initialization and pre-read database operations.

### 8.2 Synchronization Controller (`field_sync_controller.js`)
- **Concurrency Mutex:** Employs W3C Web Locks API (`navigator.locks.request('eleguard_sync_mutex')`) with localStorage lease fallback to prevent multi-tab concurrent synchronization races.
- **HTTP 207 Multi-Status Handling:** Parses itemized server acknowledgements. Only purges local IndexedDB drafts when confirmed `synced` or `replayed`. Partial failures, HTTP 429 rate limits, and network dropouts leave drafts securely in local storage.

---

## 9. Application Security & Defense-in-Depth Assessment

### 9.1 Vulnerability Review Matrix

| Vulnerability Class | Analysis & Implementation Verification | Verdict |
| :--- | :--- | :--- |
| **SQL Injection (SQLi)** | 100% of SQL statements across all services utilize PDO prepared statements with named/positional parameters. Native preparation enforced (`PDO::ATTR_EMULATE_PREPARES => false`). Zero raw string concatenations in WHERE clauses. | **SECURE** |
| **Cross-Site Scripting (XSS)** | UI templates uniformly utilize contextual escaping (`e()` wrapper invoking `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`). User inputs in Leaflet popups are sanitized before DOM insertion. | **SECURE** |
| **Cross-Site Request Forgery (CSRF)** | State-changing POST endpoints validate CSRF tokens via `validate_csrf()` or `require_api_csrf()`. Tokens are session-bound and verified using timing-safe `hash_equals()`. | **SECURE** |
| **Authentication & Session Fixation** | `session_regenerate_id(true)` called upon login. Passwords stored using Bcrypt. Session cookie configured with `HttpOnly` and `SameSite=Lax`. | **SECURE** |
| **File Upload Security** | `Upload::saveReportImage()` enforces 2MB limit, filename randomization, null-byte rejection, MIME type validation (`finfo`), and GD image re-encoding to strip polyglot payloads. Direct access to `uploads/` restricted by `.htaccess`. | **SECURE** |
| **Sensitive Data Exposure** | Database credentials and secrets stored in `.env` outside webroot. Coordinate masking applied for non-privileged roles (latitude/longitude truncated for public and driver interfaces to prevent wildlife poaching). | **SECURE** |
| **Broken Access Control (BAC)** | Endpoints enforce role middleware (`role_required.php`, `require_api_auth()`). Non-authorized roles attempting access receive HTTP 403 or safe redirect to their own dashboard. | **SECURE** |

---

## 10. Automated Testing & Verification Framework Audit

### 10.1 Master Test Harness (`tests/run_all_tests.php`)
- **Structure:** 35 automated test suites spanning unit tests, integration suites, API contract verifications, geodetic solvers, and browser concurrency workers.
- **Ephemeral Database Orchestration:** Dynamically creates unique disposable schemas (`eleguard_test_tmp_[a-f0-9]{12}`) initialized from `tests/fixtures/canonical_baseline.sql`.
- **Database Safety Pre-checks:** Verifies 6 permission isolation rules before launching tests:
  1. `eleguard_readonly` can read `eleguard_rail`.
  2. `eleguard_readonly` is denied INSERT/UPDATE on `eleguard_rail`.
  3. `eleguard_readonly` is blocked from the disposable test database.
  4. `eleguard_test_ro` can read the disposable test database.
  5. `eleguard_test_ro` is denied INSERT/UPDATE on the disposable test database.
  6. `eleguard_test_ro` is blocked from `eleguard_rail`.
- **Execution Evidence:** 35 / 35 test suites passed (1,647 assertions, 35.68 seconds).

---

## 11. Prioritised Defect Register

| Defect ID | Severity | Subsystem | Title / Description | Root Cause | Proposed Remediation |
| :---: | :---: | :--- | :--- | :--- | :--- |
| **DEF-01** | **High (P2)** | Local Environment | **Local `.env` Targets Unmigrated Baseline Schema** | Working tree `.env` targets `DB_NAME=eleguard_rail` (frozen at Migration 03), causing runtime table-not-found errors on Driver Dashboard and Wildlife Verification. | Configure local development `.env` to target `eleguard_rail_demo` for demonstration testing while preserving `eleguard_rail` unmutated. |
| **DEF-02** | **Medium (P3)** | Web Server / API | **Missing Apache URL Rewrite for API v2 Routes** | Apache returns HTTP 404 on clean RESTful paths (e.g. `/api/v2/reports/sync-batch`) due to missing rewrite rules in `public/`. | Introduce a hardened `public/.htaccess` containing clean rewrite rules routing `/api/v2/reports/*` to `index.php`. |
| **DEF-03** | **Medium (P3)** | Field PWA | **Default Sync Endpoint Hardcoded to REST Path** | `field_sync_controller.js` falls back to `../api/v2/reports/sync-batch` if `window.ELEGUARD_API_SYNC_BATCH` is not defined in window context. | Ensure fallback resolution checks both `window.ELEGUARD_API_SYNC_BATCH` and direct `.php` script path before falling back to REST route. |
| **DEF-04** | **Low (P4)** | GIS Visualization | **Leaflet Layer Control Styling Under Dark Theme** | In night-mode driver HUD and dark dashboard layouts, default Leaflet layer controls display bright white backgrounds causing visual glare. | Add high-contrast dark theme overrides in `public/assets/css/map.css`. |
| **DEF-05** | **Low (P4)** | AI Telemetry | **Missing Model Version Fallback Display** | When AI image analysis is skipped (e.g. sighting without image), model version displays as empty string rather than standardized `N/A`. | Update `AiDetectionService.php` to return `N/A` for skipped AI evaluations. |

---

## 12. Master Development Roadmap & Implementation Plan

### STAGE A — Repository & Architecture Audit (COMPLETED)
- [x] Complete directory structure mapping.
- [x] Source inspection of core services, controllers, and models.
- [x] Forensic database comparison (`eleguard_rail` vs `eleguard_rail_demo`).
- [x] Compilation of `docs/development/master_development_audit.md`.

### STAGE B — Database & Local Runtime Remediation
- [ ] Align development environment `.env` to connect to `eleguard_rail_demo`.
- [ ] Verify read-only analytics credentials for `eleguard_readonly`.
- [ ] Create `public/.htaccess` with mod_rewrite rules for API v2 routes and security headers.
- [ ] Verify live HTTP responses across Driver Dashboard, Wildlife Verification, and GIS endpoints.

### STAGE C — Milestone 9C Architecture Hardening
- [ ] Audit and enforce batch limits ($\le 50$ reports, $\le 10$ MB payload) in `FieldReportingService`.
- [ ] Ensure unknown milestone handling preserves raw textual references without database exceptions.
- [ ] Verify cross-user UUID isolation and deterministic payload fingerprint validation (`payload_hash`).

### STAGE D — GIS Intelligence Improvements
- [ ] Separate observer GNSS marker from estimated animal location marker in Leaflet map view.
- [ ] Render Root-Sum-Square (RSS) uncertainty circles on the verification map canvas.
- [ ] Implement spatial filtering by railway section and risk band in `map_component.js`.
- [ ] Add dark-theme CSS rules for Leaflet map controls in locomotive cab HUD.

### STAGE E — AI-Assisted Verification Improvements
- [ ] Audit YOLOX-Tiny inference pipeline and improve graceful timeout handling.
- [ ] Ensure AI detection results consistently present structured bounding boxes, confidence tiers, and human-in-the-loop disclaimers.
- [ ] Organize evaluation dataset test fixtures and benchmark logging.

### STAGE F — Field Reporting PWA Reliability
- [ ] Verify offline draft creation and AES-GCM encrypted persistence in IndexedDB.
- [ ] Test cross-tab Web Locks mutex under simulated concurrency.
- [ ] Ensure network disconnections leave offline drafts safely in local storage.
- [ ] Verify HTTP 207 Multi-Status partial failure handling.

### STAGE G — Application Security Improvements
- [ ] Audit all input sanitation and HTML output escaping.
- [ ] Verify CSRF token coverage on all state-changing forms.
- [ ] Ensure database connection failures log securely without exposing DSN or credentials to clients.

### STAGE H — Integration & Regression Testing
- [ ] Add automated regression test suites covering newly remediated defects.
- [ ] Execute complete master test runner (`php tests/run_all_tests.php`).
- [ ] Verify 35/35+ suites passing with zero database cross-contamination.

### STAGE I — Documentation & Release Governance
- [ ] Update `README.md` and technical specifications.
- [ ] Document architecture decisions in `docs/development/architecture_decisions.md`.
- [ ] Prepare final pre-release checklist and verification evidence.
