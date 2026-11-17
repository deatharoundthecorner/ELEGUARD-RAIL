# ELEGUARD RAIL — FINAL PROJECT HANDOVER REPORT
## Wildlife–Railway Collision Prevention & Ecological Early Warning Platform

**Document Reference:** `docs/final_handover/final_project_handover_report.md`  
**Change Reference:** `ER-FINAL-HANDOVER-001`  
**Milestone:** 9C (Stage J Technical Closure)  
**Date:** November 13, 2026  
**System Version:** Release Candidate 1.0 (Local Verified Build)  
**Governance Scope:** Handover to Department of Wildlife Conservation (DWC) and Sri Lanka Railways (SLR)  

---

## 1. EXECUTIVE SUMMARY

The **ELEGUARD RAIL** platform is a mission-critical, spatial-temporal early warning and telemetry coordination system developed to mitigate wildlife–train collisions along the railway corridors of Sri Lanka (notably the Northern and Batticaloa lines passing through the dry-zone elephant habitats of Galgamuwa, Habarana, and Maho).

This report constitutes the comprehensive project handover document following the successful technical closure of **Stage J (Phases J.1 through J.6)** under Milestone 9C. Over the course of the project:
- A decoupled, three-tier georeferencing engine and mathematical spatial uncertainty model were engineered to ingest field observations without spatial distortion.
- An end-to-end Progressive Web Application (PWA) with AES-GCM 256-bit client-side encrypted storage was deployed to enable offline wildlife telemetry capture in deep forest corridors devoid of cellular reception.
- Strict multi-tiered Role-Based Access Control (RBAC) was established across five operational personas (System Administrator, Wildlife Officer, Railway Control Officer, Locomotive Driver, and Field Reporter).
- Enterprise-grade security hardening was completed across upload magic-byte inspection, parameterized SQL query enforcement (100% of 28 dynamic queries hardened), database engine privilege separation, RFC 9457 API response standardization, and RFC 4122 v4 UUID idempotency with SHA-256 canonical payload fingerprinting.
- The platform's complete master regression suite of **35 automated test suites** passed with zero defects (100% pass rate) on disposable test databases, maintaining absolute immutability over operational database schemas.

---

## 2. PROBLEM STATEMENT

In Sri Lanka, railway tracks bisect ancient migratory corridors and fragmented seasonal home ranges of the Asian Elephant (*Elephas maximus maximus*). Between 2010 and 2025, train strikes accounted for scores of elephant mortalities and catastrophic derailments, posing grave threats to biodiversity conservation, human passenger safety, and railway rolling stock.

Prior to ELEGUARD RAIL, collision prevention suffered from systemic operational vulnerabilities:
1. **Reporting Latency & Communication Silos:** Sighting notifications communicated via ad-hoc VHF radio or phone calls were unstructured, lacked precise coordinates, and suffered transmission delays between DWC rangers and SLR train controllers.
2. **Connectivity Voids:** Wildlife encounters occur predominantly in remote sanctuary borders where mobile cellular coverage is intermittent or non-existent, preventing real-time web portal submission.
3. **Spatial Bias & Distortion:** Observers typically stand dozens or hundreds of metres away from wild herds. Treating the observer's GPS smartphone position as the animal's physical coordinate created substantial spatial distortion when calculating proximity to railway tracks.
4. **Information Disclosure & Poaching Threats:** Indiscriminate broadcast of exact coordinates poses severe poaching and harassment risks to endangered herds.
5. **Driver Workload & Operational Conflict:** In-cab warning systems that flood locomotive crews with false alarms or demand interactive touch confirmation while operating high-speed trains violate fundamental railway safety rules.

---

## 3. PROJECT OBJECTIVES

The primary engineering objectives accomplished in ELEGUARD RAIL:
1. **Decoupled Field Telemetry Acquisition:** Provide field rangers with an intuitive offline-first mobile PWA to capture animal sightings, standoff distance, compass bearing, and diurnal time precision.
2. **Mathematical Target & Uncertainty Resolution:** Solve the forward Great-Circle geodesic problem to estimate true animal coordinates while calculating propagated Root-Sum-Square (RSS) spatial uncertainty.
3. **Structured Multi-Tier Verification Workflow:** Route unverified field observations through a formal Wildlife Officer verification ledger before promoting data to active operational alerts.
4. **Locomotive Driver Safety Advisories:** Deliver passive, stationary-acknowledgement-only cautions displaying prescribed cautionary speeds (e.g., 20–40 km/h) based on verified track proximity zones.
5. **Zero Trust Security & Idempotency:** Secure all endpoints against SQL injection, session hijacking, upload spoofing, cross-user UUID collision replay leakage, and accidental database mutation.

---

## 4. PROPOSED SOLUTION & ARCHITECTURE HIGHLIGHTS

ELEGUARD RAIL addresses these challenges through a unified, modular architecture:

```
[ Field Rangers / Observers ] ──(Offline PWA / AES-GCM Storage)──> [ Client Sync Controller ]
                                                                             │ (HTTPS JSON / UUID)
                                                                             ▼
[ Public / Dispatch Ingestion ] ─────────────────────────────────> [ FieldReportingService ]
                                                                             │
                                     ┌───────────────────────────────────────┴───────────────────────────────────────┐
                                     ▼                                                                               ▼
                         [ Geodesic Forward Engine ]                                                     [ Section Proximity Engine ]
                         (Great-Circle + RSS Uncertainty)                                                (OSM Vector + Geofence Analysis)
                                     │                                                                               │
                                     └───────────────────────────────────────┬───────────────────────────────────────┘
                                                                             ▼
                                                                [ MariaDB Relational Core ]
                                                                (Multi-User Engine Privileges)
                                                                             │
                      ┌──────────────────────────────────────────────────────┼──────────────────────────────────────────────────────┐
                      ▼                                                      ▼                                                      ▼
        [ Wildlife Verification Desk ]                         [ Control Attribution Console ]                        [ Locomotive Driver HUD ]
        (DWC Officer Formal Audit)                             (Railway Dispatcher Sectioning)                        (Passive Caution & Audio)
```

---

## 5. TECHNOLOGY STACK

- **Backend Runtime:** PHP 8.2.12 (Strict typing, PDO MySQL driver, OpenSSL, `intl` Normalizer).
- **Database Engine:** MariaDB 10.4.32 (InnoDB transactional engine, B-Tree composite indexing, UTF-8 MB4 Unicode collation).
- **Frontend Layer:** Vanilla ES6+ JavaScript (zero external runtime frameworks for longevity and security), HTML5, CSS3, Service Worker API.
- **Client Storage & Cryptography:** IndexedDB with Web Cryptography API (`crypto.subtle`) utilizing AES-GCM with 256-bit keys derived via PBKDF2 (SHA-256, 100,000 iterations).
- **Cartographic & GIS Engine:** Leaflet.js 1.9.4 with OpenStreetMap tile integration, custom GeoJSON rendering, circular uncertainty buffers, and topological junction polygons.
- **Test Infrastructure:** Custom CLI Master Test Runner with multi-process child worker orchestration (`proc_open`), automated disposable database lifecycle (`eleguard_test_tmp_*`), and restricted test credentials (`eleguard_test_rw`).

---

## 6. MAJOR APPLICATION MODULES & CAPABILITIES

### 6.1 Field PWA & Offline Ingestion Tier
- **Route:** `public/field/`
- Implements RFC 4122 v4 UUID client-side generation upon draft creation.
- Progressive offline storage encrypts draft sighting payloads in IndexedDB before committing to browser storage.
- Auto-sync background manager detects connectivity restoration and triggers batched multi-status submissions (`POST /api/v2/reports/sync_batch.php`).
- Conservative draft deletion: Local records are purged **only** upon verified HTTP 201 (`synced`) or HTTP 200 (`replayed`) server acknowledgment.

### 6.2 Wildlife Officer Verification Desk
- **Route:** `public/wildlife/`
- Central dashboard for authenticated DWC wildlife officers.
- Enforces state machine transitions on reports: `Pending` $\to$ `Verified`, `Needs Information`, `Rejected`, or `Converted to Alert`.
- Implements two-way structured clarification messaging between wildlife officers and field reporters.
- Logs every verification action in `report_verifications` with mandatory investigator attribution and rationale.

### 6.3 Railway Control & Section Attribution Console
- **Route:** `public/control/`
- Interface for Sri Lanka Railways operational dispatchers.
- Visualizes incoming verified sightings against railway corridor sections and junction geofences (Gal Oya Junction, Maho Junction).
- Manages section assignment status (`provisional_estimated`, `controller_confirmed`, `topology_ambiguity`).
- Manages active cautionary speed restrictions across railway line segments.

### 6.4 Locomotive Driver Advisory Interface
- **Route:** `public/driver/`
- High-contrast, night-mode Heads-Up Display (HUD) optimized for locomotive cab environments.
- Automatically calculates distance to track and cautionary speed thresholds (Zone 0: 20 km/h; Zone 1: 40 km/h).
- **Operational Safety Policy (Stationary Review Only):** In compliance with railway operating safety discipline, touch acknowledgements are strictly restricted to times when the locomotive is stationary at scheduled station stops, sidings, or holding signals. The interface prominently displays stationary-review safety warning banners. The software does **not** interface with locomotive telemetry or speed sensors; automated speed-based lockout is not implemented, and stationary compliance is governed operationally.

### 6.5 System Administration & Analytics
- **Route:** `public/admin/`
- Executive dashboard displaying system audit logs, user management, and historical temporal risk metrics.
- Read-only historical analytics layer executing under restricted `eleguard_readonly` credentials.

---

## 7. USER ROLES & ACCESS CONTROL MATRIX

| User Role | System Identifier | Authorized Access Scope | Data Masking / Restrictions |
|---|---|---|---|
| **System Administrator** | `admin` | Full user provisioning, system logging, operational audit, global configuration. | Full unmasked coordinates. |
| **Wildlife Officer** | `wildlife_officer` | Verification desk, clarification dispatch, status promotion, historical analytics. | Full coordinates for ecological verification. |
| **Control Officer** | `control_officer` | Railway dispatch dashboard, section attribution confirmation, alert conversion. | Full coordinates within assigned railway division. |
| **Locomotive Driver** | `driver` | In-cab advisory HUD, cautionary speed advisories, stationary acknowledgement. | Coordinates masked to railway corridor zone and milestone ref. |
| **Field Reporter** | `reporter` | Offline PWA sighting submission, clarification response, personal report history. | Restricted strictly to self-authored reports. |

---

## 8. SECURITY ARCHITECTURE & REMEDIATION HIGHLIGHTS

Across Milestone 9C (Stages J.1–J.6), enterprise security hardening achieved the following verifiable results:

1. **SQL Injection Elimination (Stage J.2):**
   - 100% of the 28 dynamic SQL queries audited and converted to parameterized PDO prepared statements.
   - Zero SQL concatenation vulnerabilities detected across automated vulnerability scans.
2. **Database Engine Privilege Separation (Stage J.3):**
   - Three distinct MariaDB user roles enforced: `eleguard_readonly` (production analytics read-only), `eleguard_test_ro` (test read-only), and `eleguard_test_rw` (test read-write scoped exclusively to `eleguard_test_tmp_%`.*).
   - Global administrative credentials isolated in secure user home directory config (`~/.eleguard-test-admin.php`).
3. **Standardized API Response Envelopes (Stage J.4):**
   - Standardized REST envelopes with top-level `success: bool`, `status: int` (numeric RFC 9457 HTTP code), `data`, and RFC 9457 `type`, `title`, `detail`, `invalid_params`.
4. **UUID Idempotency & Replay Isolation (Stage J.5):**
   - Duplicate UUID submissions by the same user return HTTP 200 Replay.
   - Cross-user UUID collisions return HTTP 409 Conflict with zero internal report metadata disclosure.
   - Modified payloads submitted with an existing UUID trigger HTTP 409 Conflict (`payload-mismatch`) via SHA-256 canonical observation fingerprinting (`payload_hash`).
5. **GPS Safety Invariant Enforcement (Stage J.6):**
   - Prohibited fabricated GPS accuracy defaults; missing or invalid device accuracy strictly preserves spatial uncertainty as `NULL`.

---

## 9. TESTING, VALIDATION & TELEMETRY EVIDENCE

The platform has undergone rigorous automated testing across unit, integration, concurrency, and security dimensions:

- **Master Test Runner:** `tests/run_all_tests.php`
- **Total Registered Suites:** 35 suites
- **Execution Result:** **35 / 35 PASSED CLEANLY** (34.84s total elapsed time, Exit Code 0).
- **Assertions Verified:** Over 1,200 individual assertions across RBAC, geodetics, temporal analysis, duplicate clustering, AI advisory governance, encryption, and SQL injection safety.
- **Genuine Concurrency Testing:** Verified multi-process race conditions using `proc_open` and filesystem barrier locks; under 3-worker simultaneous collisions, exactly 1 worker obtained insertion lock (HTTP 201) while remaining workers rolled back safely into idempotent replays (HTTP 200).
- **Operational Database Immutability:** Operational database `eleguard_rail` and persistent test database `eleguard_rail_test` maintained 100% identical row counts before and after all testing runs (43 reports, 15 alerts, 13 acknowledgements, 4 hotspots, 4 railway sections, 185 system logs, 5 users, 3 incident records).

---

## 10. CURRENT IMPLEMENTATION STATUS & LIMITATIONS

### Implemented & Verified Capabilities
- Decoupled observer and animal georeferencing.
- Great-Circle forward projection with RSS uncertainty calculation.
- Offline encrypted PWA sighting capture and sync controller.
- Multi-status batch synchronization with mathematical count reconciliation.
- Complete 5-role RBAC security and authenticated navigation.
- 100% parameterized SQL backend with privilege-separated MariaDB accounts.

### Documented Technical & Operational Limitations
1. **OpenStreetMap Geometry Precision:** Railway centerline data is derived from OpenStreetMap track vectors with an estimated $10\text{--}30\text{m}$ planar uncertainty. It is **not** an official railway engineering survey.
2. **Circular Uncertainty Simplification:** Spatial uncertainty is rendered as isotropic circular buffers. Advanced bivariate Gaussian covariance ellipses cannot be calculated because consumer mobile GNSS APIs do not expose raw NMEA covariance tensors.
3. **Visual Range Parameter Heuristic:** The $20\%$ visual range error and $10^\circ$ compass variance parameters represent conservative engineering defaults and require empirical calibration via laser rangefinder field trials.
4. **Locomotive Telemetry & Speed Interlock Absence:** The platform does not integrate with locomotive speedometers, tachometers, or train data buses. Speed data is unavailable in the software. Stationary acknowledgement is an operational safety policy enforced procedurally and through visual banners, not an automated hardware/software speed-based lockout.
5. **Absence of Live Railway Field Trials:** All validation evidence is derived from automated test suites, simulated concurrency harnesses, and browser automation. No live field testing on operational rolling stock or track corridors has been executed.

---

## 11. DEPLOYMENT STATUS, PREREQUISITES & GOVERNANCE BOUNDARIES

### Deployment Status Summary
- **Technical Maturity:** Stages J.1 through J.6 are technically complete and locally verified.
- **Handover Package:** Handover documentation suite prepared for stakeholder evaluation.
- **Production Authorization:** **NOT AUTHORIZED.** No live network cutover or production rollout is permitted under this reference.
- **Field Trial Status:** Live railway corridor field trials have **not** been completed.
- **Regulatory Approvals:** Formal administrative and operational approvals from DWC and SLR remain pending.
- **Safety Certification:** **No operational safety certification** (e.g., CENELEC EN 50126/50128/50129 or IEC 61508) is claimed or implied.

### Prerequisites Before Live Pilot Consideration
1. **Production Infrastructure:** Deploy high-availability Linux/UNIX application servers with PHP 8.2+ (OPcache, `intl`, `openssl`, `pdo_mysql`) and MariaDB 10.4+ cluster.
2. **TLS 1.3 Transport Security:** Install CA-signed SSL/TLS certificates (mandatory for Web Cryptography API and Service Worker offline caching).
3. **Database Migration Window:** Formally execute Migrations 01 through 06 sequentially on the production database under DBA change management.
4. **Joint Inter-Agency Review:** Present this handover report and technical architecture to the DWC–SLR Joint Wildlife Hazard Management Committee for operational pilot authorization.

---

## 12. CONCLUSION

ELEGUARD RAIL has reached complete technical maturity for Milestone 9C and Stage J. The platform provides a secure, scientifically grounded, offline-resilient solution to wildlife collision prevention. All engineering objectives have been achieved, verified, and documented with verifiable test evidence.
