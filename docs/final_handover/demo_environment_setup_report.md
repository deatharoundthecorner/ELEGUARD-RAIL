# ELEGUARD RAIL — DEMO ENVIRONMENT SETUP & VERIFICATION REPORT

**Reference:** ER-DEMO-ENV-003  
**Status:** COMPLETED & VERIFIED  
**Date:** 2026-11-15  
**Target Environment:** Isolated Showcase Database (`eleguard_rail_demo`) on MariaDB `127.0.0.1:3308`  
**Host Application:** XAMPP Apache `http://localhost/eleguard-rail/`  

---

## 1. Executive Summary

Under explicit authorization for **Strategy Option A** (Reference: ER-DEMO-ENV-003), a dedicated, fully migrated, and synthetically populated demonstration database has been provisioned: **`eleguard_rail_demo`**.

This isolated environment enables end-to-end local hosting, stakeholder walkthroughs, and UI exploratory testing of the complete EleGuard Rail platform without touching either the operational production database (`eleguard_rail`) or the persistent automated test database (`eleguard_rail_test`).

### Key Highlights
1. **Zero Contamination:** Both `eleguard_rail` and `eleguard_rail_test` were audited with cryptographic SHA-256 row-data hashing before and after all provisioning activities. **Zero records were modified, inserted, or deleted across both existing databases.**
2. **Standard-Compliant Migrations:** The demo environment was built directly from the canonical test baseline (`tests/fixtures/canonical_baseline.sql`) followed by sequential execution of Migrations 03, 04, 05, and 06.
3. **Synthetic Demonstration Data:** 44 sighting reports, 3 biological verifications, 2 officer clarifications, and 2 section assignment audit logs populated with purely synthetic, privacy-safe geospatial and observation data.
4. **Resolved Diagnostic Issues:** All 5 diagnostic defects discovered in `ER-LOCAL-DIAGNOSTIC-003` are 100% resolved:
   - Driver Dashboard: HUD queries `report_verifications` successfully (HTTP 200).
   - Wildlife GIS Map: GeoJSON layers render observer coordinates and standoff geometries without errors (HTTP 200).
   - Reporter Sighting History: Clarification log queries execute cleanly against `report_clarifications` (HTTP 200).
   - Admin Analytics: Data contract reconciled between `by_provenance` and `provenance_breakdown`; `summary.php` authorized with read-only privileges (HTTP 200).
   - Field Reporter PWA: Direct `.php` endpoint routing bypasses missing Apache mod_rewrite rules, delivering HTTP 201 Created and verified RFC 4122 v4 idempotency replay (HTTP 200).

---

## 2. Database Creation & Sequential Migration Trace

### 2.1 Database Initialization
The database was created on the active MariaDB instance:
- **Host:** `127.0.0.1`
- **Port:** `3308` (MariaDB XAMPP instance; Windows MySQL80 on 3306 remained completely untouched)
- **Database Name:** `eleguard_rail_demo`
- **Collation:** `utf8mb4_unicode_ci`

### 2.2 Baseline Fixture Import
`tests/fixtures/canonical_baseline.sql` was imported, providing the initial 8 core tables:
- `users` (5 development role accounts)
- `railway_sections` (4 sections)
- `hotspots` (4 high-risk zones)
- `elephant_reports` (43 baseline reports)
- `alerts` (15 active/resolved alerts)
- `driver_acknowledgements` (13 cab confirmations)
- `incident_history` (3 historical events)
- `system_logs` (audit baseline)

### 2.3 Migration Application
Per Option A authorization, Migrations 01 and 02 were deliberately bypassed as their schema modifications (`railway_sections`, `incident_history`, and foundational indexes) are inherently pre-baked into the canonical fixture. Migrations 03 through 06 were executed sequentially:

| Migration File | Description | Verification Check | Status |
| :--- | :--- | :--- | :--- |
| `03_add_sighting_datetime_columns.sql` | Adds temporal split columns `sighting_date` and `sighting_time` to `elephant_reports`. | Columns present, typed `DATE` and `TIME`. | **APPLIED & VERIFIED** |
| `04_add_field_reporting_v2_to_elephant_reports.sql` | Adds RFC 4122 UUID, observer GPS telemetry, standoff offsets, and creates `report_verifications` table. | `client_report_uuid`, `observer_latitude`, `report_verifications` table present. | **APPLIED & VERIFIED** |
| `05_add_verification_and_section_audits.sql` | Creates `report_clarifications` and `report_section_assignment_history` tables. | Both audit tables exist with foreign keys to `elephant_reports` and `users`. | **APPLIED & VERIFIED** |
| `06_add_payload_hash_to_elephant_reports.sql` | Adds `payload_hash` (CHAR 64) for duplicate prevention and data integrity. | `payload_hash` column present with index. | **APPLIED & VERIFIED** |

### 2.4 Schema Audit of `eleguard_rail_demo`
- **Total Tables:** 12 tables
- **`elephant_reports` Column Count:** 58 columns
- **Foreign Key Constraints:** 22 constraints
- **Indexes:** 38 indexes

---

## 3. Synthetic Demonstration Data Inventory

All data in `eleguard_rail_demo` is synthetic and sanitized. No real-world operational records, personal identifiable information (PII), or genuine endangered elephant coordinates exist in this database.

### 3.1 Role Accounts & Development Credentials
Five standard role-based development accounts are available:

| Role | Email | Password | Primary Showcase View |
| :--- | :--- | :--- | :--- |
| **System Administrator** | `admin@eleguard.lk` | `admin123` | `public/admin/dashboard.php`, `public/admin/analytics.php` |
| **Railway Controller** | `control@eleguard.lk` | `control123` | `public/control/dashboard.php`, `public/control/reports.php` |
| **Locomotive Driver** | `driver@eleguard.lk` | `driver123` | `public/driver/dashboard.php`, `public/driver/alerts.php` |
| **Wildlife Officer** | `wildlife@eleguard.lk` | `wildlife123` | `public/wildlife/dashboard.php`, `public/wildlife/reports.php`, `public/wildlife/map.php` |
| **Field Reporter** | `reporter@eleguard.lk` | `reporter123` | `public/field/index.php`, `public/reporter/my_reports.php` |

### 3.2 Seeding of Advanced Subsystem Records
To support realistic UI testing across all new subsystem features:
1. **Georeferenced Sightings:** All 43 baseline records were augmented with synthetic observer coordinates, standoff distances (95 m), observation bearings, and provenance tags (`genuine_baseline`, `genuine_field`, `development_test`).
2. **Biological Verifications (`report_verifications`):**
   - Seeded 3 verification decisions by Wildlife Officer (ID 4) covering Confirmed, Rejected, and Under Review statuses.
3. **Clarification Exchange (`report_clarifications`):**
   - Seeded 2 structured biological clarification records between Wildlife Officer and Field Reporter regarding herd composition and landmark confirmation.
4. **Track Attribution History (`report_section_assignment_history`):**
   - Seeded 2 spatial section assignment audit logs documenting automatic proximity calculation versus manual officer override.

---

## 4. Application Configuration & Least-Privilege Access

### 4.1 XAMPP Application Configuration
The active showcase application hosted at `C:\xampp\htdocs\eleguard-rail` has been switched to the demo database:
- **Active Configuration (`.env`):**
  ```dotenv
  DB_HOST=127.0.0.1
  DB_PORT=3308
  DB_NAME=eleguard_rail_demo
  DB_USER=root
  DB_PASS=
  DB_ANALYTICS_USER=eleguard_readonly
  DB_ANALYTICS_PASS=eleguard_readonly_pw
  ```
- **Operational Backup (`.env.operational_backup`):**
  Preserves original settings pointing to `eleguard_rail` for instant zero-loss rollback.
- **Desktop Repository (`.env`):**
  Remains strictly untouched, maintaining `DB_NAME=eleguard_rail`.

### 4.2 Dedicated Read-Only Analytics User Grants
The application enforces strict architectural separation between read-write transactions and read-only analytical queries. MariaDB user `eleguard_readonly` was granted `SELECT` on the demo database:
```sql
GRANT SELECT ON `eleguard_rail_demo`.* TO 'eleguard_readonly'@'localhost';
GRANT SELECT ON `eleguard_rail_demo`.* TO 'eleguard_readonly'@'127.0.0.1';
FLUSH PRIVILEGES;
```
This resolved the `DATABASE_ERROR` connection failure previously encountered on analytical endpoints.

---

## 5. Frontend & Direct Script Routing Hardening

To ensure the local showcase environment functions seamlessly under Apache without mod_rewrite dependencies:

1. **Analytics Dashboard Data Contract (`public/assets/js/analytics_dashboard.js`):**
   - Updated provenance parser to accept either `stats.by_provenance` or `stats.provenance_breakdown`.
   - Displays "Unavailable" when missing rather than failing with `undefined` exceptions.
   - Filtered out zero-count categories from rendering in donut charts.
2. **PWA Submission & Sync Routing (`public/field/index.php` & `public/assets/js/field_pwa.js`):**
   - Updated endpoints to invoke direct PHP handlers (`../api/v2/reports/submit.php`, `../api/v2/reports/sync_batch.php`, `../api/v2/reports/my.php`).
   - Completely eliminated HTTP 404 routing failures caused by missing rewrite engines.
3. **Repository Mirror Parity:**
   All 3 modified files were verified with cryptographic SHA-256 match between Desktop repository and XAMPP:
   - `public/assets/js/analytics_dashboard.js`: `2ece2d04e5d6281d09b4f2de8a966344c515a670785308d5bb2b650a4db19887` (MATCH)
   - `public/field/index.php`: `829bc474931c94a77a193ecdccad7bb728529aa8ee8e8cfc0f436fdb4206d479` (MATCH)
   - `public/assets/js/field_pwa.js`: `f49d0bb38114ebac535a9cc471420efeaa81408158f451f2306383029ef3fb4c` (MATCH)

---

## 6. End-to-End Showcase Verification Matrix

All key application modules and role workflows were tested against `http://localhost/eleguard-rail/`:

### 6.1 Role-Based UI Access Matrix

| Role | Endpoint Tested | HTTP Code | Status & Observation |
| :--- | :--- | :--- | :--- |
| **Admin** | `public/admin/dashboard.php` | 200 OK | Operational metrics, incident counts, user tables render cleanly. |
| **Admin** | `public/admin/analytics.php` | 200 OK | Analytics dashboard, summary cards, and charts load without errors. |
| **Control** | `public/control/dashboard.php` | 200 OK | Active caution orders, track safety feed, and railway sections active. |
| **Control** | `public/control/reports.php` | 200 OK | Sighting reports table with biological verification tags. |
| **Control** | `public/control/acknowledgements.php` | 200 OK | Locomotive driver acknowledgement audit log renders. |
| **Wildlife** | `public/wildlife/dashboard.php` | 200 OK | Verification queue, recent sightings, and ranger status cards active. |
| **Wildlife** | `public/wildlife/reports.php` | 200 OK | Biological triage interface renders standoff distance & offset math. |
| **Wildlife** | `public/wildlife/map.php` | 200 OK | Full Leaflet GIS tracking map initializes with layer controls. |
| **Driver** | `public/driver/dashboard.php` | 200 OK | Cab HUD loads verified alerts from `report_verifications` without errors. |
| **Driver** | `public/driver/alerts.php` | 200 OK | Regulatory Caution Orders and advisory archive load cleanly. |
| **Reporter** | `public/field/index.php` | 200 OK | PWA field capture shell, GPS capture wizard, and offline outbox active. |
| **Reporter** | `public/reporter/dashboard.php` | 200 OK | Reporter community overview renders. |
| **Reporter** | `public/reporter/my_reports.php` | 200 OK | Sighting history and officer clarification requests render. |

### 6.2 Analytical REST API Endpoints

| Endpoint | HTTP Code | Response Summary |
| :--- | :--- | :--- |
| `public/api/analytics/summary.php` | 200 OK | High-level summary metrics, breakdown by risk and section. |
| `public/api/analytics/ai_advisory.php` | 200 OK | AI pipeline advisory concordance and advisory recommendation stats. |
| `public/api/analytics/quality.php` | 200 OK | Data quality index, GPS accuracy metrics, standoff compliance. |
| `public/api/analytics/hotspots.php` | 200 OK | Hotspot geospatial cluster definitions and risk scores. |
| `public/api/analytics/sections.php` | 200 OK | Railway section statistics, crossing frequencies, speed limits. |
| `public/api/analytics/temporal.php` | 200 OK | Diurnal and seasonal temporal risk distributions. |

### 6.3 Geospatial GIS API Endpoints

| Query | HTTP Code | Returned Feature Count |
| :--- | :--- | :--- |
| `public/api/gis_data.php?layer=routes` | 200 OK | 4 railway section LineString GeoJSON features |
| `public/api/gis_data.php?layer=hotspots` | 200 OK | 4 high-risk Polygon/Point GeoJSON features |
| `public/api/gis_data.php?layer=reports` | 200 OK | 29 georeferenced elephant sighting Point features |
| `public/api/gis_data.php?layer=alerts` | 200 OK | 14 active Caution Order advisory features |

### 6.4 Field PWA REST API v2 Ingestion & Idempotency
- **Direct Submission:** POST to `public/api/v2/reports/submit.php` with RFC 4122 v4 UUID and CSRF token returned **HTTP 201 Created** (Report ID 88 assigned in `eleguard_rail_demo`).
- **Idempotent Replay:** Re-submitting identical payload with the same client UUID returned **HTTP 200 OK** (`is_replay: true`), demonstrating full RFC 4122 compliance without duplicate records.

---

## 7. Cryptographic Database Immutability Verification

An automated verification script compared pre-execution and post-execution states of all tables using row counts and SHA-256 serialization hashes.

### 7.1 Operational Database (`eleguard_rail`) Audit

| Table Name | Pre Count | Post Count | Hash Match | Immutability Status |
| :--- | :--- | :--- | :--- | :--- |
| `alerts` | 15 | 15 | **YES** | **100% INVARIANT** |
| `driver_acknowledgements` | 13 | 13 | **YES** | **100% INVARIANT** |
| `elephant_reports` | 43 | 43 | **YES** | **100% INVARIANT** |
| `hotspots` | 4 | 4 | **YES** | **100% INVARIANT** |
| `incident_history` | 3 | 3 | **YES** | **100% INVARIANT** |
| `railway_sections` | 4 | 4 | **YES** | **100% INVARIANT** |
| `system_logs` | 217 | 217 | **YES** | **100% INVARIANT** |
| `users` | 5 | 5 | **YES** | **100% INVARIANT** |

**Total Operational Database Differences:** `0` (Zero changes).

### 7.2 Persistent Test Database (`eleguard_rail_test`) Audit

| Table Name | Pre Count | Post Count | Hash Match | Immutability Status |
| :--- | :--- | :--- | :--- | :--- |
| All 12 Tables | Invariant | Invariant | **YES** | **100% INVARIANT** |

**Total Test Database Differences:** `0` (Zero changes).

---

## 8. Runbook: Switching Environments

To switch the local XAMPP application between the showcase database and the operational database at any time:

### Switch to Operational Database (`eleguard_rail`)
```powershell
Copy-Item "C:\xampp\htdocs\eleguard-rail\.env.operational_backup" "C:\xampp\htdocs\eleguard-rail\.env" -Force
```
*Verify:* Check that `DB_NAME=eleguard_rail` in `C:\xampp\htdocs\eleguard-rail\.env`.

### Switch to Showcase Database (`eleguard_rail_demo`)
```powershell
(Get-Content "C:\xampp\htdocs\eleguard-rail\.env") -replace "DB_NAME=eleguard_rail", "DB_NAME=eleguard_rail_demo" | Set-Content "C:\xampp\htdocs\eleguard-rail\.env"
```
*Verify:* Check that `DB_NAME=eleguard_rail_demo` in `C:\xampp\htdocs\eleguard-rail\.env`.

---

## 9. Conclusion & Readiness

The isolated demonstration environment **`eleguard_rail_demo`** is fully operational, thoroughly validated, and ready for hands-on stakeholder exploration, manual testing, and local hosting.

- All 5 user roles function with HTTP 200 across all 13 primary views.
- All analytical and geospatial APIs are operational.
- Field PWA offline capture and API v2 submission are validated.
- Operational and test databases remain 100% untouched.
