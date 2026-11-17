# ELEGUARD RAIL — Comprehensive Publication Manifest

**Document Reference:** `ER-PUB-MANIFEST-001`  
**System Name:** ELEGUARD RAIL (Elephant Collision Prevention Platform)  
**Target Repository:** `C:\Users\user\Desktop\Railway\eleguard-rail`  
**Date:** November 15, 2026  
**Auditor / Author:** Autonomous Verification & Packaging Agent  
**Classification Baseline:** Pre-Publication Git Repository Review  

---

## 1. Executive Summary & Purpose

This Publication Manifest establishes an exhaustive, forensic inventory of every file and directory across the ELEGUARD RAIL codebase. The objective is to demarcate:
1. **PUBLIC:** Fully sanitized application source code, configuration templates, database schemas, automated test suites, and technical documentation suitable for public distribution on GitHub and professional presentation.
2. **PRIVATE:** Confidential runtime files, active environment secrets, operational database backups, production telemetry, and unanonymized user uploads that must **never** be committed or published.
3. **REVIEW REQUIRED:** Large binary assets, presentation materials, internal engineering deliberations, and extensive historical phase documentation that require explicit stakeholder approval prior to public release.

This manifest conforms to strict defense-in-depth principles: zero credentials, zero production telemetry, zero operational data, and clear attribution.

---

## 2. Classification Criteria & Guidelines

| Classification Tier | Definition & Security Gate | Handling Rule |
|:---|:---|:---|
| **PUBLIC** | Production-ready PHP, JS, CSS, HTML, synthetic fixtures, migration scripts, official documentation (`README.md`, architecture guides, public reports), and open-source models/configs. | Included in initial Git packaging and public GitHub repository. |
| **PRIVATE** | Live environment configurations (`.env`), operational database backups (`*.sql`), runtime uploads (`public/assets/uploads/*`), user sessions, logs, temporary scratch files, and OS metadata. | Strictly ignored via `.gitignore`; blocked by pre-commit hygiene checks. |
| **REVIEW REQUIRED** | Large binary models (`.onnx`), presentation slide decks (`.pptx`, `.pdf`), comprehensive historical engineering audit logs (`docs/phase9/`), and raw browser screenshot evidence. | Excluded from the initial clean public commit or quarantined in a separate documentation branch pending stakeholder sign-off. |

---

## 3. Forensic Asset Inventory & Classification Breakdown

### 3.1. Environment & Configuration Files

| Path / File Pattern | Status | Size / Format | Classification | Justification & Verification Notes |
|:---|:---:|:---:|:---:|:---|
| `.env` | Untracked / Ignored | 647 B (Plaintext) | **PRIVATE** | Contains active local database credentials (`DB_PASS`), secret keys, and local environment URLs. Must never be committed. |
| `.env.example` | Tracked (Modified) | 971 B (Plaintext) | **PUBLIC** | Sanitized reference template with placeholder strings (`DB_HOST=127.0.0.1`, `DB_PORT=3308`, `DB_PASS=`). Safe for public documentation. |
| `.gitignore` | Tracked (Modified) | 1,025 B (Plaintext) | **PUBLIC** | Core repository hygiene configuration. Explicitly ignores `.env`, `public/assets/uploads/*`, `database/backups/*`, `scratch/`, and temporary logs. |
| `app/config/app.php` | Tracked (Modified) | 2.1 KB (PHP) | **PUBLIC** | Global application configuration, session constants, timezones, and role-based ACL mappings. |
| `app/config/database.php` | Tracked (Modified) | 1.8 KB (PHP) | **PUBLIC** | Environment-aware database connection parameters loading safely from `$_ENV` or defaults. |

---

### 3.2. Core Application Source Code

| Directory / File | Status | Description | Classification |
|:---|:---:|:---|:---:|
| `index.php` | Tracked | Root entry point and public redirector | **PUBLIC** |
| `app/core/` | Tracked (Modified) | Core framework (`Auth.php`, `Database.php`, `Session.php`) | **PUBLIC** |
| `app/helpers/` | Untracked | Sanitization, CSRF, and security helper functions | **PUBLIC** |
| `app/includes/` | Tracked (Modified) | File upload handlers and validation logic | **PUBLIC** |
| `app/layouts/` | Tracked (Modified) | Shared layout templates, sidebars, headers, and navigation | **PUBLIC** |
| `app/services/` | Tracked + Untracked | Domain services (`AlertService.php`, `AnalyticsService.php`, `FieldReportingService.php`, `GisService.php`, `ProximityService.php`, `ReportService.php`, `UserService.php`, `VerificationService.php`) | **PUBLIC** |
| `public/*.php` | Tracked | Main web entry points (`login.php`, `register.php`, `logout.php`, `index.php`, `faq.php`, `awareness.php`, `emergency_contacts.php`, `safety.php`) | **PUBLIC** |
| `public/admin/` | Tracked + Untracked | Station Master / Administrator interfaces (`dashboard.php`, `analytics.php`, `acknowledgements.php`) | **PUBLIC** |
| `public/control/` | Tracked + Untracked | Control Officer dispatch interfaces (`dashboard.php`, `reports.php`, `analytics.php`, `acknowledgements.php`) | **PUBLIC** |
| `public/wildlife/` | Tracked + Untracked | Wildlife Officer verification interface (`dashboard.php`, `reports.php`, `submit_report.php`, `analytics.php`) | **PUBLIC** |
| `public/driver/` | Tracked | Locomotive Driver HUD (`dashboard.php`, `alerts.php`, `alert_details.php`, `history.php`) | **PUBLIC** |
| `public/reporter/` | Tracked | Field Reporter portal (`my_reports.php`) | **PUBLIC** |
| `public/field/` | Untracked | Offline PWA Field Reporter shell (`index.php`) | **PUBLIC** |
| `public/api/` | Tracked + Untracked | Core REST APIs (`gis_data.php`, `public/api/analytics/`, `public/api/v2/reports/`) | **PUBLIC** |
| `public/assets/css/` | Tracked + Untracked | Stylesheets (Vanilla CSS3, responsive grids, dark HUD themes) | **PUBLIC** |
| `public/assets/js/` | Tracked + Untracked | Client-side JavaScript (Leaflet map integrations, Web Crypto, IndexedDB sync) | **PUBLIC** |
| `public/manifest.webmanifest`| Untracked | Progressive Web App manifest | **PUBLIC** |
| `public/sw.js` | Untracked | Service Worker script for offline asset caching | **PUBLIC** |
| `public/offline.html` | Untracked | Offline fallback experience for field reporters | **PUBLIC** |

---

### 3.3. Database Schemas, Migrations & Fixtures

| File / Path | Status | Description | Classification | Safety & Privacy Audit |
|:---|:---:|:---|:---:|:---|
| `database/schema.sql` | Tracked | Base operational database schema | **PUBLIC** | Standard DDL; no production records. |
| `database/migrations/01_*.sql` to `06_*.sql` | Tracked / Untracked | Sequential evolutionary migrations | **PUBLIC** | Clean DDL definitions adding indexes, verification states, and SHA-256 payload audits. |
| `database/migrations/rollback_*.sql` | Untracked | Migration rollback scripts | **PUBLIC** | Reversible migration operations. |
| `tests/fixtures/canonical_baseline.sql` | Untracked | Canonical synthetic test database fixture | **PUBLIC** | **Sanitized:** SHA-256 `CB311BBDA4AF...`. Contains 5 synthetic seed accounts (`@eleguard.lk`), dev passwords, synthetic GPS coordinates along Batticaloa line, and 0 personal data. |
| `database/backups/*.sql` | Ignored | Historical operational database dumps (`backup_before_9c_stage1_*.sql`, etc.) | **PRIVATE** | **Confidential:** May contain developer timestamps, host configurations, and historical system traces. Completely excluded by `.gitignore`. |

---

### 3.4. Uploaded Media & Runtime Assets

| Path | Status | Count / Type | Classification | Policy |
|:---|:---:|:---:|:---:|:---|
| `public/assets/uploads/reports/*` | Ignored | 67 `.jpg` images | **PRIVATE** | User-uploaded elephant sighting photos and test uploads. Excluded from repository to prevent PII/location leaks and repository bloat. |
| `public/assets/uploads/.gitkeep` | Tracked | 0 B | **PUBLIC** | Preserves directory structure in fresh clones. |
| `public/assets/images/*` | Untracked | System branding, railway icons, UI assets | **PUBLIC** | Generic SVG/PNG assets for application UI. |

---

### 3.5. Automated Test Infrastructure

| File / Path | Status | Description | Classification | Notes |
|:---|:---:|:---|:---:|:---|
| `tests/bootstrap.php` | Tracked (Modified) | Test environment bootstrap | **PUBLIC** | Enforces disposable test DB isolation (`eleguard_test_*`). Guarantees operational DB safety. |
| `tests/run_all_tests.php` | Untracked | Master 35-suite test runner | **PUBLIC** | Automated test orchestrator. |
| `tests/test_milestone_*.php` | Tracked / Untracked | 35 comprehensive test suites | **PUBLIC** | Full test coverage (temporal risk, security, API envelopes, GIS, RBAC). |
| `tests/data/*.json` | Tracked | Synthetic test scenarios | **PUBLIC** | Synthetic test cases. |
| `tests/workers/*` | Untracked | Multi-process test worker harnesses | **PUBLIC** | Performance and isolation workers. |

---

### 3.6. AI & Machine Learning Subsystem

| Path | Status | Size / Description | Classification | Recommendation |
|:---|:---:|:---|:---:|:---|
| `ai/detector/*.py` | Tracked | YOLOv8 ONNX inference engine | **PUBLIC** | Clean Python detection code. |
| `ai/analytics/*.py`, `*.php` | Tracked | Spatial & temporal clustering | **PUBLIC** | Statistical analytics algorithms. |
| `ai/models/yolov8n.onnx` | Tracked | 6.2 MB binary ONNX model | **REVIEW REQUIRED** | Pre-trained open-source YOLOv8 Nano weights for elephant detection. Can be published via GitHub Releases or Git LFS to keep main repo lightweight. |
| `ai/models/yolox_tiny.onnx` | Ignored | Large ONNX model candidate | **PRIVATE** | Ignored candidate model weights. |
| `ai/datasets/` | Tracked | Sample evaluation images | **PUBLIC** | Curated synthetic & public domain test images for model benchmarking. |

---

### 3.7. Documentation & Handover Deliverables

| Path | Status | Classification | Purpose & Public Recommendation |
|:---|:---:|:---:|:---|
| `README.md` | Tracked (Modified) | **PUBLIC** | Primary repository documentation, architecture overview, installation guide, and quick start. |
| `docs/final_handover/` | Untracked | **PUBLIC** | Final architectural specifications, verification reports, manual test plans, defect registers, and publication manifests. |
| `docs/phase9/` | Untracked | **REVIEW REQUIRED** | 120+ internal engineering milestone reports, change requests, and architectural deliberations. Highly detailed historical audit trail. Recommended to archive or publish as an engineering companion archive. |
| `presentation/` | Untracked | **REVIEW REQUIRED** | `ELEGUARD_RAIL_Stakeholder_Presentation.pptx` (6.2 MB), `.pdf` (1.3 MB), speaker notes, and QA preparation. Suitable for release alongside portfolio showcase or hosted externally. |
| `verification_evidence/*.png` | Untracked | **REVIEW REQUIRED** | Visual audit screenshots documenting verified system states. Recommended for README showcases or wiki documentation. |

---

### 3.8. Scratch Scripts & Temporary Logs

| Path | Status | Classification | Handling |
|:---|:---:|:---:|:---|
| `scratch/` | Ignored | **PRIVATE** | Local temporary debugging scripts and evaluation outputs. Excluded by `.gitignore`. |
| `*.log` | Ignored | **PRIVATE** | Runtime web server and test execution logs. Excluded by `.gitignore`. |

---

## 4. Proposed Initial Git File Inventory (Public Release Manifest)

For the public GitHub release, the following clean structure is recommended:

```
eleguard-rail/
├── .env.example
├── .gitignore
├── README.md
├── index.php
├── app/
│   ├── config/
│   │   ├── app.php
│   │   └── database.php
│   ├── core/
│   │   ├── Auth.php
│   │   ├── Database.php
│   │   └── Session.php
│   ├── helpers/
│   │   └── *.php
│   ├── includes/
│   │   └── upload.php
│   ├── layouts/
│   │   ├── dashboard_sidebar.php
│   │   └── map_view_partial.php
│   └── services/
│       ├── AlertService.php
│       ├── AnalyticsService.php
│       ├── FieldReportingService.php
│       ├── GisService.php
│       ├── ProximityService.php
│       ├── ReportService.php
│       ├── UserService.php
│       └── VerificationService.php
├── database/
│   ├── schema.sql
│   └── migrations/
│       ├── 01_initial_schema.sql
│       ├── 02_create_driver_acknowledgements.sql
│       ├── 03_add_hotspots_and_incidents.sql
│       ├── 04_add_field_reporting_v2_to_elephant_reports.sql
│       ├── 05_add_verification_and_section_audits.sql
│       ├── 06_add_payload_hash_to_elephant_reports.sql
│       └── rollback_*.sql
├── public/
│   ├── index.php
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   ├── awareness.php
│   ├── emergency_contacts.php
│   ├── faq.php
│   ├── safety.php
│   ├── manifest.webmanifest
│   ├── sw.js
│   ├── offline.html
│   ├── admin/
│   ├── control/
│   ├── driver/
│   ├── wildlife/
│   ├── reporter/
│   ├── field/
│   ├── api/
│   └── assets/
│       ├── css/
│       ├── js/
│       ├── images/
│       └── uploads/ (.gitkeep only)
├── tests/
│   ├── bootstrap.php
│   ├── run_all_tests.php
│   ├── fixtures/
│   │   └── canonical_baseline.sql
│   ├── data/
│   └── test_milestone_*.php
├── ai/
│   ├── detector/
│   ├── analytics/
│   ├── datasets/
│   └── evaluate.py
├── sanity/
└── docs/
    └── final_handover/
        ├── technical_architecture.md
        ├── deployment_readiness_and_limitations.md
        ├── publication_manifest.md
        ├── defect_register.md
        └── ...
```

---

## 5. Security & Privacy Certification

- **Zero Operational Credentials:** No production passwords, API tokens, or server SSH keys exist in tracked or public files.
- **Zero Real PII:** All seed user profiles use fictional administrative identities (`@eleguard.lk`) with synthetic phone numbers and bcrypt hashes.
- **Zero Live Incident Telemetry:** All database fixtures, test records, and coordinates represent synthetic scenarios along the Batticaloa–Trincomalee railway corridor.
- **Defense in Depth:** Sensitive directories (`.env`, `database/backups/`, `public/assets/uploads/`, `scratch/`) are strictly enforced as untracked in `.gitignore`.
