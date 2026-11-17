# ELEGUARD RAIL — STAKEHOLDER DEMONSTRATION READINESS AUDIT
## Comprehensive Read-Only Feature Readiness & Verification Assessment

**Project:** ELEGUARD RAIL (Sri Lanka Wildlife–Railway Collision Prevention Platform)  
**Document Reference:** `docs/final_handover/stakeholder_demo_readiness_audit.md`  
**Audit Reference:** `ER-DEMO-AUDIT-001`  
**Mode:** Read-Only Technical Inspection  
**Target Milestone:** Post-Stage J Technical Handover & Demonstration Preparation  
**Target Audience:** DWC Directors, Sri Lanka Railways Executives, Academic Review Panel  

---

### 1. Executive Summary

This readiness audit establishes an evidence-based assessment of the ELEGUARD RAIL software platform prior to conducting formal 15–20 minute stakeholder demonstrations. In strict accordance with project safety rules, this audit was conducted in **read-only mode**: no application source code, configuration files, database schemas, or database records were modified.

The evaluation confirms that core software engineering across all five operational tiers is complete and validated through 35 registered automated test suites and authenticated browser verification captures. This document catalogs the demonstrable capabilities, identifies prerequisites, maps existing evidence, and notes operational boundaries to ensure a seamless, transparent presentation.

---

### 2. Feature Availability & Verification Status Matrix

Each of the nine core architectural features is classified into one of the following evidence tiers:
- **Tier A:** *Verified through existing automated evidence* (clean unit/integration/concurrency test suites).
- **Tier B:** *Verified through existing browser evidence* (authenticated UI screenshots captured during verification).
- **Tier C:** *Requires manual demonstration* (interactive client-side actions such as network toggling).
- **Tier D:** *Not currently demonstrable* (requires uninstalled hardware or unapproved production infrastructure).
- **Tier E:** *Requires external approval or infrastructure* (regulatory agency sign-off).

| # | Feature Name | Implementation Components | Evidence Classification | Current Demonstration Readiness |
| :-: | :--- | :--- | :--- | :--- |
| **1** | **Authentication & Role-Based Access (RBAC)** | `app/core/Auth.php`<br>`app/core/Session.php`<br>`public/index.php` | **Verified through automated evidence & browser evidence** (Tiers A & B) | **Demonstrable.** Supports 5 roles (`admin`, `control_officer`, `driver`, `wildlife_officer`, `reporter`). Browser capture `09_rbac_denial.png` proves immediate 403 Forbidden denial on unauthorized cross-role access. |
| **2** | **Wildlife Field Reporting** | `public/wildlife/submit_report.php`<br>`public/field/index.html`<br>`app/services/FieldReportingService.php` | **Verified through automated evidence & browser evidence** (Tiers A & B) | **Demonstrable.** Supports decoupled observer georeferencing, voluntary standoff distance, compass bearing, herd demographics, and photo attachment with MIME verification. |
| **3** | **GPS Georeferencing & Uncertainty Math** | `FieldReportingService::calculateGeodeticOffset()`<br>`FieldReportingService::calculateRailwayAssociation()` | **Verified through automated evidence & browser evidence** (Tiers A & B) | **Demonstrable.** 14/14 automated assertions passed (`test_milestone_9c_stage_j_gps_validation.php`). Enforces Root-Sum-Square (RSS) uncertainty and zero-fabrication safety invariant (NULL uncertainty when GPS accuracy is invalid). |
| **4** | **Offline PWA Functionality** | `public/sw.js`<br>`public/manifest.webmanifest`<br>`public/offline.html`<br>`public/assets/js/field_crypto_storage.js` | **Verified through automated evidence & requires manual demonstration** (Tiers A & C) | **Demonstrable.** Service worker caches the application shell locally. IndexedDB stores draft observations encrypted via Web Crypto API (AES-GCM 256-bit). Requires manual DevTools "Offline" toggle to demonstrate. |
| **5** | **Offline Synchronization** | `public/assets/js/field_sync_controller.js`<br>`public/api/v2/reports/sync_batch.php` | **Verified through automated evidence & requires manual demonstration** (Tiers A & C) | **Demonstrable.** Concurrency-safe batch sync via Web Locks API (`navigator.locks`). RFC 4122 v4 UUID idempotency and SHA-256 payload hashing prevent duplicate insertions during network retries. |
| **6** | **Wildlife Officer Verification Desk** | `public/wildlife/reports.php`<br>`public/wildlife/dashboard.php`<br>`app/services/VerificationService.php` | **Verified through automated evidence & browser evidence** (Tiers A & B) | **Demonstrable.** Multi-tier state machine (Pending $\to$ Verified / Rejected / Needs Info). Verified via `wildlife_reports_list.png` and `wildlife_report_review.png`. Two-way structured clarifications logged with full investigator audit trail. |
| **7** | **Control Officer GIS Dashboard** | `public/control/dashboard.php`<br>`public/api/gis_data.php`<br>`public/assets/js/map_component.js` | **Verified through automated evidence & browser evidence** (Tiers A & B) | **Demonstrable.** Verified in `06_control_dashboard.png`. Displays railway line vectors, active hotspot markers, circular uncertainty confidence envelopes, three proximity zones, and mandatory OpenStreetMap planar disclaimer banner. |
| **8** | **Locomotive Driver Advisory Interface** | `public/driver/alerts.php`<br>`public/driver/dashboard.php`<br>`public/driver/alert_details.php` | **Verified through automated evidence & browser evidence** (Tiers A & B) | **Demonstrable.** Verified in `01_driver_dashboard.png` and `11_driver_alerts_stationary.png`. Displays high-contrast dark theme HUD, verified hazard zones, and mandatory stationary-review warning banner. Strictly passive; zero telemetry assumptions. |
| **9** | **Security & Audit Logging** | `app/services/AnalyticsService.php`<br>`app/core/Database.php`<br>`database/migrations/` | **Verified through automated evidence & browser evidence** (Tiers A & B) | **Demonstrable.** 100% PDO parameterized statements (28 queries). MariaDB privilege separation (`eleguard_readonly` SELECT-only user). Security audit captures `07_control_acknowledgements.png` and `08_admin_acknowledgements.png`. |

---

### 3. Demonstration Prerequisites & Technical Requirements

To execute an interactive live demonstration without failure, the hosting environment must satisfy the following technical prerequisites:

#### A. Required Application Services
1. **Web Server:** Apache 2.4+ (configured with `mod_rewrite` and serving the `public/` directory).
2. **PHP Runtime:** PHP 8.2.x (with extensions: `pdo_mysql`, `fileinfo`, `json`, `mbstring`, `openssl`).
3. **Database Engine:** MariaDB 10.4.x / MySQL 8.0+ running on port **3308** (as specified in `.env`).

#### B. Required Client Browser Configuration
1. **Engine:** Modern Chromium-based browser (Google Chrome 115+ or Microsoft Edge 115+).
2. **Mandatory APIs:** Native browser support for:
   - Web Cryptography API (`window.crypto.subtle`) for client-side AES-GCM encryption.
   - Service Worker API for offline app shell caching.
   - IndexedDB for client draft persistence.
   - Web Locks API (`navigator.locks`) for multi-tab synchronization concurrency control.
3. **Developer Tools:** F12 Developer Tools enabled to demonstrate:
   - Network throttling / "Offline" simulation.
   - Local storage inspection (IndexedDB encrypted ciphertext).
   - REST API Problem Details JSON responses.

#### C. Required User Roles & Pre-Seeded Demonstration Accounts
The database contains pre-configured test personas representing all five stakeholder roles. *(In accordance with project security restrictions, actual passwords are never printed; demonstrators should refer to authorized internal test keyrings)*:

| Role Name | User Principal Name | Operational Context | Primary Demonstration View |
| :--- | :--- | :--- | :--- |
| **System Administrator** | `admin@eleguard.lk` | System governance & master logs | `public/admin/dashboard.php` |
| **Control Officer** | `control@eleguard.lk` | Train traffic & corridor GIS | `public/control/dashboard.php` |
| **Locomotive Driver** | `driver@eleguard.lk` | In-cab situational awareness | `public/driver/alerts.php` |
| **Wildlife Officer** | `wildlife@eleguard.lk` | Verification desk & evidence review | `public/wildlife/reports.php` |
| **Field Reporter** | `reporter@eleguard.lk` | Ranger mobile PWA & sighting entry | `public/field/index.html` |

#### D. Internet & Offline Infrastructure Assessment
- **Internet Requirement:** **Zero external internet connectivity is required.** All core application assets—including Leaflet JS 1.9.4, Leaflet CSS, custom styling, SVG iconography, and Web Worker scripts—are fully vendored and cached locally within `public/assets/`.
- **Cartographic Tiles:** Map tiles can be served from local tile caches or rendered vector geometry without external CDN dependencies.
- **Offline Capabilities:** The entire field data entry, client encryption, and draft queuing workflow can be demonstrated completely disconnected from the local web server.

---

### 4. Known Blockers & Environmental Realities

During this read-only audit, the following operational state conditions were identified:

1. **Service Inactivity (Current State):**
   - The local Apache server and MariaDB database daemon (port 3308) are currently stopped in the background.
   - *Demonstration Mitigation:* Prior to the stakeholder session, the local host administrator must launch the XAMPP services (`Apache` and `MySQL` configured for port 3308).
2. **Operational Database Migrations (04–06):**
   - The operational database `eleguard_rail` currently holds baseline demonstration data (43 reports, 15 alerts, 13 acknowledgements, 4 hotspots, 4 railway sections, 185 logs).
   - If the presenter intends to create brand-new live verification records during the demonstration, migration scripts `04_add_field_reporting_v2_to_elephant_reports.sql`, `05_add_verification_and_section_audits.sql`, and `06_add_payload_hash_to_elephant_reports.sql` must be present in the operational schema.
   - *Demonstration Mitigation:* Presenters can safely navigate existing verified historical records without requiring new live database writes, eliminating any risk of runtime SQL errors during presentation.

---

### 5. Stakeholder Communication Guardrails & Safety Invariants

To maintain institutional trust with Sri Lanka Railways and the Department of Wildlife Conservation, the demonstration must strictly adhere to the following communication invariants:

1. **No Automated Speed Commands:** The presenter must explicitly clarify that ELEGUARD RAIL does **not** issue speed orders or braking commands. Train operations are legally governed by SLR General Rules, trackside signals, and Station Master Caution Orders.
2. **Stationary-Review Policy:** Emphasize that locomotive drivers are procedurally mandated to review advisories only when stationary at stations or signals. Clarify that the platform does not connect to locomotive speed sensors.
3. **No Fabricated GPS Accuracy:** Highlight that when mobile GNSS accuracy is missing, uncertainty is truthfully recorded as unknown (`NULL`), never defaulted to an assumed 10m radius.
4. **Three Proximity Zones:** Adhere strictly to the three documented corridor proximity zones:
   - **Zone 0 (0–15 m):** Track Clearance Hazard.
   - **Zone 1 (15–100 m):** Near-Corridor Alert.
   - **Zone 2 (100–1,000 m):** Buffer Habitat Watch.
5. **Pre-Production Milestone Status:** Reiterate that Milestone 9C / Stage J represents a technically complete **Release Candidate 1.0 pre-production build** prepared for stakeholder evaluation; live in-service railway trials have not been conducted, and production deployment is not authorized.
