# ELEGUARD RAIL — Final Master Release Report & Project Closure Record

**Document ID:** ER-FINAL-RELEASE-001
**Publication Date:** 2026-11-17 (23:45 +05:30)
**Project:** ELEGUARD RAIL — Wildlife–Railway Collision Mitigation & Early Warning Platform
**Repository:** [https://github.com/deatharoundthecorner/ELEGUARD-RAIL](https://github.com/deatharoundthecorner/ELEGUARD-RAIL)
**Primary Branch:** `main`
**Published Release Commit:** `f85dfecc8256023378c3f5df27ce9ac239a5b5e5` (`f85dfec`)
**Commit Message:** `docs: add comprehensive engineering audit, architecture records, and testing deliverables`
**Remote Sync Status:** Verified synchronized with `origin/main` (Remote SHA: `f85dfecc8256023378c3f5df27ce9ac239a5b5e5`)
**Record Status:** Explicitly identified local uncommitted release handover document (retained in working tree per release directive; no automated secondary commit created)

---

## 1. Project Overview

ELEGUARD RAIL is an academic, research, and demonstration software prototype designed to investigate how geospatial information systems (GIS), lightweight permissive edge artificial intelligence, progressive web applications (PWA), and digital reporting workflows can support human-in-the-loop railway situational awareness along critical elephant-crossing corridors in Sri Lanka (specifically the Maho–Galgamuwa–Polonnaruwa lines).

The platform models a collaborative situational awareness loop across four core operational roles:
1. **Field Observers / Community Reporters:** Offline-first Progressive Web App (PWA) with AES-GCM-256 encrypted local caching for recording georeferenced elephant sightings in remote rural areas.
2. **Wildlife Officers (DWC):** Digital verification workspace featuring geodetic track-standoff distance calculations and YOLOX-Tiny ONNX advisory image classification.
3. **Railway Traffic Control Desk:** Real-time GIS corridor monitor displaying verified hazard zones, active advisories, and speed restriction notifications.
4. **Locomotive Drivers:** Cab advisory dashboard displaying track-ahead hazard proximity alerts and operational speed advisories.

---

## 2. Release Identification & Git Provenance

- **Local Working Copy:** `eleguard-rail` (workspace repository root)
- **Active Branch:** `main`
- **Base Commit:** `52223b42f941af091e9dc6733934918828589ae2` (`docs: add ELEGUARD RAIL stakeholder presentation`)
- **Published Release Commit:** `f85dfecc8256023378c3f5df27ce9ac239a5b5e5`
- **Remote Tracking:** `origin/main` hosted on GitHub
- **Upstream Push Status:** Published and confirmed via `git ls-remote origin refs/heads/main` and GitHub REST API v3.
- **Commit Whitespace & Formatting Audit:** `git diff HEAD^ HEAD --check` exited cleanly with `0` errors/warnings.

---

## 3. Files Included in the Release Commit

The published commit (`f85dfec`) contains exactly nine approved files:

| # | File Path | Type | Purpose |
|---|---|---|---|
| 1 | `README.md` | Core Docs | Added Section 9 referencing comprehensive engineering audits, ADRs, and roadmap. |
| 2 | `docs/development/architecture_decisions.md` | Architecture | Architecture Decision Records (ADRs 001–005) covering Web Crypto, sync, advisory boundaries, and DB isolation. |
| 3 | `docs/development/development_roadmap.md` | Engineering | Strategic roadmap covering Stages A through J and future technical milestones. |
| 4 | `docs/development/master_development_audit.md` | Audit Record | Master audit synthesis across repository layout, database baselines, and code health. |
| 5 | `docs/development/security_assessment.md` | Security | Threat model, RBAC matrix, SQL injection hardening records, and privilege separation rules. |
| 6 | `docs/development/testing_report.md` | Quality Assurance | Verification methodology, regression test inventory, and test runner performance tracking. |
| 7 | `public/api/v2/reports/.htaccess` | Configuration | Apache `mod_rewrite` rule ensuring proper REST routing for PWA offline report sync. |
| 8 | `public/assets/css/map.css` | UI/UX | High-contrast Leaflet zoom HUD styling and dark-theme map UI improvements. |
| 9 | `tests/test_milestone_9c_stage4e4_2d_sync.php` | Test Infrastructure | Resilient 25-attempt CDP polling loop (200ms intervals) resolving headless test flakes. |

---

## 4. Verification Categorization & Boundaries

To ensure complete transparency and rigorous engineering standards, the status of project capabilities is categorized into four distinct verification tiers:

```
+-------------------------------------------------------------------------------+
|                        VERIFICATION BOUNDARY MATRIX                           |
+-------------------------------------------------------------------------------+
| Tier 1: Completed Engineering Work                                            |
|   - Full PHP 8.2 backend services, REST APIs, and database migrations 01-06.  |
|   - Vanilla ES6 PWA with Service Worker, Web Crypto AES-GCM, and IndexedDB.   |
|   - Leaflet GIS mapping engine with Vincenty/Haversine distance calculations. |
|   - YOLOX-Tiny ONNX inference script integration and advisory status scoring. |
+-------------------------------------------------------------------------------+
| Tier 2: Automated Test Verification                                           |
|   - 35 / 35 test suites executed and passing under `run_all_tests.php`.        |
|   - >1,647 assertions validated across unit, integration, and security tests. |
|   - Automated database permission isolation verified across 6 engine tests.   |
+-------------------------------------------------------------------------------+
| Tier 3: Local Runtime Verification                                            |
|   - Verified on local Apache/PHP 8.2/MariaDB 10.4 stack (`127.0.0.1:8080`).   |
|   - UI visual verification executed across all 4 operational role dashboards. |
|   - End-to-end report creation, PWA offline sync, and officer review tested.  |
+-------------------------------------------------------------------------------+
| Tier 4: Actual Field Validation                                               |
|   - NOT PERFORMED.                                                            |
|   - No in-service locomotive cab trials or field wildlife ranger deployments. |
|   - All geodata and elephant sightings are synthetic demonstration data.      |
+-------------------------------------------------------------------------------+
```

---

## 5. Regression Test Results

The full unified regression test runner was executed prior to release:

- **Command:** `php tests/run_all_tests.php`
- **Result:** **35 / 35 test suites passed cleanly (0 failed, 0 skipped, 0 warnings)**
- **Total Execution Time:** 34.91 seconds
- **Database Safety Pre-checks:**
  - Operational Read (`eleguard_readonly` -> `eleguard_rail`): **PASS** (43 reports read)
  - Operational Immutability (`eleguard_readonly` INSERT blocked): **PASS** (SQLSTATE 42000)
  - Cross-DB Isolation (`eleguard_readonly` -> disposable DB blocked): **PASS** (Access Denied)
  - Test Read (`eleguard_test_ro` -> disposable DB): **PASS** (43 reports read)
  - Test Immutability (`eleguard_test_ro` INSERT blocked): **PASS** (SQLSTATE 42000)
  - Operational Protection (`eleguard_test_ro` -> `eleguard_rail` blocked): **PASS** (Access Denied)
- **Cleanup Verification:** Disposable test database `eleguard_test_tmp_0c043c3a3195` created with Migrations 01–06 and dropped cleanly upon test suite completion.

---

## 6. Database Protection & Baseline Integrity

The MariaDB database environment adheres to strict immutability and isolation rules:

1. **Operational Database (`eleguard_rail`):**
   - Record Count: Exactly 43 historical sighting records.
   - Schema Status: Frozen at baseline Migration 03 (8 tables).
   - Modification Prohibition: Zero writes, zero migrations, and zero schema alterations permitted.
   - Protection: Engine-level read-only grants enforced for analytical queries.
2. **Demo Runtime Database (`eleguard_rail_demo`):**
   - Record Count: 44 sighting records.
   - Schema Status: Full Migrations 01–06 applied (12 tables including report verifications, driver acknowledgements, and sync logs).
   - Usage: Active backend for local browser walkthroughs and demonstration UI.
3. **Persistent Test Database (`eleguard_rail_test`):**
   - Record Count: 43 sighting records.
   - Schema Status: Migrations 01–05 (12 tables).
   - Isolation: Protected; non-disposable suites only.
4. **Temporary Test Databases (`eleguard_test_tmp_*`):**
   - Master test runner provisions ephemeral randomized schemas per run and drops them upon completion. Existing legacy temporary schemas remain untouched per safety rules.

---

## 7. AI Subsystem & Permissive Vision Model Verification

- **Backend Architecture:** YOLOX-Tiny ONNX (`ai/models/yolox_tiny.onnx`, 416x416 input size, ~19 MB, Apache 2.0 license).
- **Classification Output:** Pure scalar detection confidence and category classification:
  - `Confirmed`: Detection confidence ≥ 40%
  - `Uncertain`: Detection confidence between 20% and 40%
  - `Negative`: Detection confidence < 20%
- **Advisory Role:** The AI output is presented exclusively as an advisory indicator to the Department of Wildlife Conservation (DWC) verifier.
- **Human Governance:** Under no circumstances does the AI automatically approve sightings, generate railway alerts, or trigger speed restrictions without explicit human officer verification.
- **Terminology Compliance:** All legacy YOLOv8 references in user-facing dashboards and documentation have been corrected to `YOLOX-Tiny ONNX`. Historical baseline evaluation files (`ai/models/yolov8n.onnx`) remain retained solely for benchmarking documentation under AGPL-3.0 isolation.

---

## 8. Security & Vulnerability Assessment Summary

- **Authentication & RBAC:** Session-based authentication with strict role checks (`admin`, `control`, `wildlife`, `driver`, `reporter`). Session hijacking mitigation via cookie security flags (`HttpOnly`, `SameSite=Lax`).
- **SQL Injection Prevention:** 100% of dynamic queries use PDO prepared statements with bound parameters. Zero string concatenation in SQL execution paths.
- **Cross-Site Scripting (XSS):** Context-aware output escaping (`htmlspecialchars($str, ENT_QUOTES, 'UTF-8')`) applied across all template rendering.
- **Client Storage Security:** Offline PWA drafts stored in IndexedDB encrypted via Web Crypto API using AES-GCM-256 with user-passphrase PBKDF2 key derivation.
- **Credential Hygiene:** Zero hardcoded API keys, passwords, or production secrets in the Git repository. Environment configuration decoupled via `.env` templates.
- **Note on Pen-testing:** This project has undergone rigorous automated static analysis and developer security audits, but has not been subjected to commercial third-party penetration testing.

---

## 9. Railway Safety Boundaries & Operational Restrictions

> [!CAUTION]
> ### Crucial Operational Boundaries
>
> 1. **Zero Direct Locomotive Control:** ELEGUARD RAIL does not interface with train brake pipes, locomotive traction controls, automatic train protection (ATP/ETCS), or trackside signalling interlocking systems.
> 2. **Advisory Decision Support Only:** All speed advisories (e.g., "Caution 30 km/h") and proximity warnings are decision support suggestions presented to human controllers and locomotive drivers.
> 3. **Non-Safety-Critical Software:** The codebase is **NOT certified safety-critical software** under European Norms CENELEC EN 50126, EN 50128, EN 50129, or IEC 61508 (SIL 1 to SIL 4).
> 4. **Primacy of Railway Operating Rules:** Official Sri Lanka Railways (SLR) General Rules, block telegraph regulations, dispatch train orders, and physical trackside signals retain absolute operational primacy.

---

## 10. Known Limitations & Technical Debt

1. **Simulation of Real-Time Rolling Stock:** Current locomotive position telemetry is simulated using GPS waypoint progression along GeoJSON track segments. Integration with physical GPS/GSM locomotive cab units is future work.
2. **Camera Sensor Ingestion:** Elephant detection relies on user-uploaded JPEG/PNG photos rather than live streaming trackside thermal/optical camera networks.
3. **Single-Node Architecture:** The prototype runs on a unified Apache/PHP/MariaDB instance suitable for local research and pilot demonstrations, requiring decoupling into containerized microservices (Docker/Kubernetes) for horizontal scalability.
4. **Historical Phase 9 Documentation:** Numerous interim phase-specific development records exist locally in `docs/phase9/`. These are retained for historical provenance but are intentionally excluded from the public GitHub repository to keep documentation concise.

---

## 11. Preserved Local Working Tree State

The following local modifications and files remain intact and preserved in the working tree, excluded from the release commit:

- `docs/final_handover/github_publication_audit.md` (unstaged local audit notes)
- `tests/data/milestone_9c_stage3e_scenarios.json` (unstaged local scenario data)
- `docs/phase9/` (historical interim development reports)
- `presentation/` (working presentation artifacts)
- `public/design-previews/` (standalone HTML design previews)
- `verification_evidence/` (runtime audit screenshot captures)
- `docs/final_handover/FINAL_RELEASE_REPORT.md` (this release record)

---

## 12. Final Release Approval & Conclusion

The ELEGUARD RAIL project has successfully concluded its master development, testing, verification, and documentation milestones. The codebase published to GitHub at commit `f85dfecc8256023378c3f5df27ce9ac239a5b5e5` represents a verified, secure, and reproducible open-source academic prototype.

*Signed & Recorded: 2026-11-17 — ELEGUARD RAIL Engineering & Publication Team*
