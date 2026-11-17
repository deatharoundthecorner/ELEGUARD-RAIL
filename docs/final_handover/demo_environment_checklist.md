# ELEGUARD RAIL — DEMONSTRATION ENVIRONMENT CHECKLIST
## Pre-Flight Verification & Host Operational Runbook

**Project:** ELEGUARD RAIL (Sri Lanka Wildlife–Railway Collision Prevention Platform)  
**Document Reference:** `docs/final_handover/demo_environment_checklist.md`  
**Change Reference:** `ER-FINAL-HANDOVER-CHECKLIST-003`  
**Purpose:** Pre-demonstration operational inspection guide for host engineers  
**Audience:** Demonstration Facilitators, Presenters, Technical Evaluators  

---

### 1. Pre-Flight Host Service Checklist

Before welcoming academic evaluators or agency directors, the host engineer must complete the following infrastructure verifications:

```text
[ ] 1. Host Operating System: Windows 10/11 or Ubuntu Linux 22.04 LTS.
[ ] 2. Web Server: Apache 2.4+ running and configured to serve public/ on port 80 (or 8097).
[ ] 3. PHP Runtime: PHP 8.2+ verified (php -v) with pdo_mysql, fileinfo, and openssl enabled.
[ ] 4. Database Daemon: MariaDB 10.4+ running and listening on port 3308 (as configured in .env).
[ ] 5. Operational Schema: eleguard_rail accessible with baseline seed records.
[ ] 6. File Permissions: public/uploads/ directory is writable by web server user.
[ ] 7. Local Parity: Verify C:\xampp\htdocs\eleguard-rail\ mirrors project repository.
```

---

### 2. Browser & Client Configuration Checklist

```text
[ ] 1. Chromium Browser: Google Chrome 115+ or Microsoft Edge 115+ installed.
[ ] 2. Web Crypto API: Verified window.crypto.subtle available (requires localhost or HTTPS).
[ ] 3. Service Worker: Unregister any obsolete stale workers prior to demo (DevTools -> Application -> Service Workers).
[ ] 4. IndexedDB: Clear test database outbox (DevTools -> Application -> Storage -> Clear site data).
[ ] 5. Pre-Opened Tabs: Open tabs in advance to minimize typing during the 20-minute presentation:
       - Tab 1: Presentation Slides (ELEGUARD_RAIL_Stakeholder_Presentation.pdf)
       - Tab 2: Login Portal (http://localhost/eleguard-rail/public/index.php)
       - Tab 3: Control GIS Console (pre-authenticated as control@eleguard.lk)
       - Tab 4: Wildlife Verification Desk (pre-authenticated as wildlife@eleguard.lk)
       - Tab 5: Driver Advisory HUD (pre-authenticated as driver@eleguard.lk)
       - Tab 6: Field PWA Mobile View (http://localhost/eleguard-rail/public/field/ in DevTools device mode)
       - Tab 7: Admin Forensic Logs (pre-authenticated as admin@eleguard.lk)
[ ] 6. Display Scaling: Set browser zoom to 100% or 110% to ensure high visibility on projection screens.
```

---

### 3. Demonstration Personas & Account Register

*(Note: In accordance with project security invariants, actual passwords are never recorded in documentation. Demonstrators must use authorized local test credentials)*:

| Persona Title | User Email | Primary Responsibility | Target Interface |
| :--- | :--- | :--- | :--- |
| **System Administrator** | `admin@eleguard.lk` | System governance & forensic audit | `public/admin/dashboard.php` |
| **Railway Controller** | `control@eleguard.lk` | Track GIS & section dispatching | `public/control/dashboard.php` |
| **Locomotive Driver** | `driver@eleguard.lk` | In-cab passive hazard situational awareness | `public/driver/alerts.php` |
| **Wildlife Officer** | `wildlife@eleguard.lk` | Human evidence review & verification | `public/wildlife/reports.php` |
| **Field Reporter** | `reporter@eleguard.lk` | Offline PWA mobile sighting capture | `public/field/index.html` |

---

### 4. Network & Offline Capability Matrix

| Feature | Offline Demonstrable? | Requires Internet? | Technical Explanation |
| :--- | :---: | :---: | :--- |
| **PWA Interface Boot** | **YES** | **NO** | Service Worker (`public/sw.js`) caches HTML shell, CSS, and JS locally using Cache-First strategy. |
| **Offline Sighting Entry** | **YES** | **NO** | Form captures data in memory and queues it locally in IndexedDB without sending HTTP requests. |
| **Client AES-GCM Encryption** | **YES** | **NO** | Browser's native Web Crypto API executes locally in JavaScript without any cloud cryptographic services. |
| **Batch Synchronization** | **NO (Needs Local Server)** | **NO** | Requires local HTTP connection to Apache on `localhost` (`/api/v2/reports/sync_batch.php`). Does not require public internet. |
| **Leaflet GIS Map Display** | **YES** | **NO** | Leaflet JS (v1.9.4) and CSS are vendored locally in `public/assets/`. Vector track centerlines render locally from GeoJSON endpoints. |
| **Driver Advisory HUD** | **YES** | **NO** | Renders locally from existing verified alerts stored in the database. |
| **Automated Test Runner** | **YES** | **NO** | PHP CLI (`php tests/run_all_tests.php`) executes 100% locally against disposable schemas (`eleguard_test_tmp_%`). |

---

### 5. Fallback Contingency Protocols

In the unlikely event of hardware or runtime glitches during a live executive presentation, the presenter must execute the following structured fallback protocols rather than attempting live debugging:

| Failure Scenario | Immediate Presenter Action | Seamless Demonstration Transition |
| :--- | :--- | :--- |
| **Local Web Server / Database Drops** | Do not attempt to debug or restart services in front of stakeholders. | Transition to high-resolution browser verification artifacts in `docs/phase9/browser_evidence/`. Show genuine captures of every single workflow. |
| **Service Worker Registration Fails** | Explain browser security context (Service Workers require secure origin `localhost` or HTTPS). | Demonstrate the automated PWA test suites `test_milestone_9c_stage4e4_2a_pwa.php` and `test_milestone_9c_stage4e4_2c_encrypted_storage.php`. |
| **Cartographic Map Tiles Fail to Load** | Point out the local GeoJSON vector lines and circular uncertainty buffers. | Display screenshot [`06_control_dashboard.png`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/docs/phase9/browser_evidence/06_control_dashboard.png) showing the fully rendered Leaflet dashboard. |
| **Driver Acknowledgement Timeout** | Direct attention to the static safety warning banner. | Emphasize that the stationary rule is an operational policy and show [`11_driver_alerts_stationary.png`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/docs/phase9/browser_evidence/11_driver_alerts_stationary.png). |
| **Cross-User UUID Collision Fails** | Explain the mathematical design of RFC 4122 v4 UUIDs ($2^{122}$ collision entropy). | Review the automated concurrency harness log in `docs/final_handover/testing_and_validation_evidence.md` proving 3 simultaneous child workers handled duplicates safely. |

---

### 6. Safety & Institutional Governance Checklist

Before concluding the demonstration, confirm that all six governance principles were explicitly communicated:

```text
[ ] 1. No Numerical Speed Commands: Confirmed that official SLR General Rules and Station Master Caution Orders govern train movements.
[ ] 2. Operational Stationary Policy: Confirmed that drivers review HUDs only while stationary; no locomotive telemetry integration claimed.
[ ] 3. Zero Fabricated Accuracy: Confirmed that missing GPS accuracy records derived uncertainty as NULL rather than assuming 10m.
[ ] 4. Three Proximity Zones: Adhered strictly to Zone 0 (0–15m), Zone 1 (15–100m), and Zone 2 (100–1,000m).
[ ] 5. Honest Pre-Production Maturity: Clarified that Milestone 9C is a Release Candidate 1.0 pre-production build; production deployment not authorized.
[ ] 6. Zero Operational Data Mutation: Confirmed that the demonstration was read-only and preserved database baseline integrity.
```
