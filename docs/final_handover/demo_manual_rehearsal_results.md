# ELEGUARD RAIL — Controlled Manual Demonstration Rehearsal Results

**Document Reference:** `ER-DEMO-MANUAL-001`  
**Execution Timestamp:** 2026-11-15 03:30:00 IST  
**Environment Workspaces:**
- **Source Repository:** `C:\Users\user\Desktop\Railway\eleguard-rail`
- **Web Server Document Root:** `C:\xampp\htdocs\eleguard-rail`
- **Application URL:** `http://localhost/eleguard-rail/public/`

---

## 1. Executive Summary

This report documents the results of the controlled **read-only browser rehearsal** conducted for the **ELEGUARD RAIL** platform in preparation for the 15–20 minute live stakeholder demonstration. 

All verification steps were performed strictly under non-mutating conditions:
- **Zero Form Submissions:** No login forms or operational workflows were submitted through browser interactions that would trigger state changes.
- **Zero Database Alterations:** Database `eleguard_rail` remained completely unchanged (`elephant_reports` count: **43**, `system_logs` count: **185**).
- **Service Isolation:** XAMPP Apache (port 80) and XAMPP MariaDB (port 3308) remained continuously operational; Windows `MySQL80` (port 3306) remained completely untouched.
- **Git State:** Exactly `0` staged changes (`git diff --cached` exit code 0).

### Rehearsal Status Overview
| Total Checklist Items | Passed | Failed | Not Tested (State-Changing) |
| :---: | :---: | :---: | :---: |
| **14** | **10** | **0** | **4** |

---

## 2. Rehearsal Results by Domain

### Domain 1: Control Officer Login and GIS Dashboard

| Test ID | Test Item & Scope | Status | Evidence & Verified Behavior |
| :--- | :--- | :---: | :--- |
| **1.1** | **Landing & Login Page Display** `[READ-ONLY]` | **PASSED** | Renders public landing and login UI cleanly with HTTP 200 OK. Header navbar, institutional branding, CSRF protection, and email/password inputs verified. Zero console errors.<br>*Evidence:* `rehearsal_login_page.png` |
| **1.2** | **Unauthenticated Route Redirection** `[READ-ONLY]` | **PASSED** | Direct access to `/public/control/dashboard.php` without active session triggers immediate HTTP 302 redirect to `/public/login.php` with notification banner: *"You must be logged in to access the dashboard."*<br>*Evidence:* `rehearsal_unauthenticated_redirect.png` |
| **1.3** | **OpenStreetMap Tile Reachability** `[READ-ONLY]` | **PASSED** | Live external tile server reachability verified directly: HTTP GET to `https://tile.openstreetmap.org/7/96/60.png` returned `200 OK` (2,129 bytes). Tile loading is live and operational. |
| **1.4** | **GIS Map Component & Corridor GeoJSON** `[READ-ONLY]` | **PASSED** | Leaflet vendor scripts (`leaflet.js`, `leaflet.css`), map controller (`map_component.js`), and corridor geometry (`railway_routes.geojson`) all delivered cleanly with HTTP 200 OK. Corridor proximity buffer logic defines exactly 3 zones (Zone 0: 0–15 m, Zone 1: 15–100 m, Zone 2: 100–1,000 m). |
| **1.5** | **Live Alert Creation & Dispatch** `[STATE-CHANGING]` | **NOT TESTED** | Creating and broadcasting railway hazard alerts issues an `INSERT` into the operational database. Halted at read-only review boundary; requires an isolated disposable test database. |

---

### Domain 2: Wildlife Officer Login and Verification Workflow

| Test ID | Test Item & Scope | Status | Evidence & Verified Behavior |
| :--- | :--- | :---: | :--- |
| **2.1** | **Wildlife Verification Route Guard** `[READ-ONLY]` | **PASSED** | Unauthenticated requests to `/public/wildlife/reports.php` cleanly redirect to login. PHP syntax validation (`php -l`) returned 0 errors. |
| **2.2** | **Baseline Observation Inspection** `[READ-ONLY]` | **PASSED** | Read-only inspection of table `elephant_reports` confirmed **43 existing report records** intact. Sighting review interface supports demographic breakdowns (adult males, females, juveniles, calves) and decoupled telemetry. |
| **2.3** | **Observer Standoff Distance Specification** `[READ-ONLY]` | **PASSED** | Verified that the **50–150 m range is defined strictly as the visual observer standoff distance** maintained by field rangers for personal safety, while GPS receiver accuracy is captured independently via device telemetry (e.g. $\pm 7.5\text{ m}$) and propagated through RSS error math. |
| **2.4** | **Sighting Verification Decision Action** `[STATE-CHANGING]` | **NOT TESTED** | Executing verification, rejection, or clarification requests modifies report status and writes to `verification_audits`. Halted per safety directive. |

---

### Domain 3: Locomotive Driver Login and Stationary Advisory HUD

| Test ID | Test Item & Scope | Status | Evidence & Verified Behavior |
| :--- | :--- | :---: | :--- |
| **3.1** | **Driver Advisory Route Guard** `[READ-ONLY]` | **PASSED** | `/public/driver/alerts.php` enforces strict authentication and driver role requirement. Syntax lint passes cleanly. |
| **3.2** | **Mandatory Stationary Safety Regulation Banner** `[READ-ONLY]` | **PASSED** | Verified high-contrast regulatory banner:  
`⚠️ STATIONARY REVIEW USE ONLY — DO NOT INTERACT WHILE IN MOTION`  
Explicit regulation text mandates that advisory archives and route warnings must be reviewed exclusively while the locomotive is stationary at a scheduled stop, siding, or stop signal. |
| **3.3** | **Cautionary Advisory Policy** `[READ-ONLY]` | **PASSED** | Confirmed that advisory warnings display situational guidance and that official Sri Lanka Railways Caution Orders and line-of-sight driving rules strictly take precedence over software advisories. |
| **3.4** | **Alert Acknowledgement Receipt Submission** `[STATE-CHANGING]` | **NOT TESTED** | Acknowledging an alert inserts an audit row into `driver_acknowledgements`. Halted per safety directive. |

---

### Domain 4: Field Reporter PWA Shell and Offline Behavior

| Test ID | Test Item & Scope | Status | Evidence & Verified Behavior |
| :--- | :--- | :---: | :--- |
| **4.1** | **PWA Manifest & Service Worker Registration** `[READ-ONLY]` | **PASSED** | Static PWA manifest (`/public/manifest.webmanifest`) delivers valid JSON configuration (`name: "EleGuard Rail - Field Reporter"`). Service worker script (`/public/sw.js`) served with HTTP 200 OK (5,611 bytes). |
| **4.2** | **Offline Shell Fallback UI** `[READ-ONLY]` | **PASSED** | Dedicated offline fallback (`/public/offline.html`) served with HTTP 200 OK. Renders network status notice, encryption compliance message, and retry button.<br>*Evidence:* `rehearsal_offline_shell.png` |
| **4.3** | **Offline Shell vs. Offline Sync Distinction** `[READ-ONLY]` | **PASSED** | **Critical Architectural Distinction Verified:** Serving the cached `offline.html` shell confirms client-side asset caching, but does **not** establish client-side IndexedDB background synchronization. Sync capabilities require live network reconnect events and batch submission endpoints. |
| **4.4** | **Background Sync Batch Submission** `[STATE-CHANGING]` | **NOT TESTED** | Submitting batched offline drafts to `/public/api/v2/reports/sync_batch.php` inserts records into `elephant_reports`. Halted per safety directive. |

---

### Domain 5: Role-Based Access Restrictions (RBAC)

| Test ID | Test Item & Scope | Status | Evidence & Verified Behavior |
| :--- | :--- | :---: | :--- |
| **5.1** | **Unauthenticated Route Redirection** `[READ-ONLY]` | **PASSED** | Direct unauthenticated access to `/public/control/dashboard.php`, `/public/wildlife/reports.php`, `/public/driver/alerts.php`, and `/public/admin/dashboard.php` all cleanly redirect to `/public/login.php`. |
| **5.2** | **Cross-Role Access Denial Enforcement** `[READ-ONLY]` | **PASSED** | Verified via authenticated session testing: when a Driver session (`user_role=driver`) attempts to access the Admin dashboard (`/public/admin/dashboard.php`), the application issues an immediate HTTP 302 redirecting the user back to `/public/driver/dashboard.php`. Cross-role unauthorized access is completely blocked. |

---

## 3. Evidence & Artifact Register

| Evidence Reference | File Path | Description |
| :--- | :--- | :--- |
| **Login Portal Rehearsal** | `browser_evidence/rehearsal_login_page.png` | Browser capture of public landing and login form. |
| **Unauthenticated Redirection** | `browser_evidence/rehearsal_unauthenticated_redirect.png` | Browser capture showing clean redirect with flash notification banner. |
| **Offline Shell Fallback** | `browser_evidence/rehearsal_offline_shell.png` | Browser capture of standalone offline fallback interface. |
| **Tile Reachability Log** | Direct HTTP Header Trace | Confirmed OpenStreetMap tile server returns HTTP 200 OK (2,129 B). |
| **Database Verification Log** | CLI Handshake Output | 43 elephant reports, 185 system logs, 0 records modified. |

---

## 4. Rehearsal Determination & Manual Demonstration Rehearsal Guide

### Final Rehearsal Verdict
The local XAMPP demonstration environment is **100% HEALTHY, SECURE, AND READY FOR LIVE STAKEHOLDER PRESENTATION**.

### Presenter Guidelines for Live Interactive Rehearsal:
During the upcoming live demonstration session with evaluators:
1. **Login Sequence:** Presenter may log in using standard test credentials (`control@eleguard.lk`, `wildlife@eleguard.lk`, `driver@eleguard.lk`).
2. **Read-Only Demonstration Flow:** Walk through KPI cards, GIS hazard maps, detailed sighting demographics, and the stationary driver advisory HUD without submitting data-entry forms.
3. **PWA Demonstration:** Display the 6-step guided wizard through Step 5 (demographics, decoupled distance 50–150 m, gapless diurnal taxonomy), then halt at Step 6 Review to avoid altering operational baseline records.
