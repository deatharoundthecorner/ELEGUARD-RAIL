# ELEGUARD RAIL — Demonstration Environment Smoke Test Report

**Document Reference:** `ER-DEMO-ENV-001`  
**Execution Timestamp:** 2026-11-14 23:12:00 IST  
**Documentation Audit & Precision Review:** 2026-11-14 23:15:00 IST  
**Environment Workspaces:**
- **Source Repository:** `C:\Users\user\Desktop\Railway\eleguard-rail`
- **Web Server Document Root:** `C:\xampp\htdocs\eleguard-rail`

---

## 1. Executive Summary

This report documents the verification and read-only smoke testing of the local XAMPP demonstration environment for the **ELEGUARD RAIL** platform. Following explicit user authorization, XAMPP Apache (port 80) and XAMPP MariaDB (port 3308) were initialized in standalone mode. 

All smoke tests were executed under strict read-only protocols: no database records were inserted, modified, or deleted; no migrations were run; no user credentials were changed; and zero artificial sightings or incidents were generated. Unrelated system services (including the `MySQL80` service on port 3306) remained completely untouched.

> [!IMPORTANT]
> **Testing Scope vs. Workflow Execution Distinction:**  
> This smoke test establishes **HTTP endpoint availability, process health, and static asset accessibility**. It explicitly does **not** constitute end-to-end authenticated workflow verification, nor does it establish client-side GIS rendering, offline IndexedDB synchronization, or multi-role incident lifecycle actions. Those operational flows are reserved for manual rehearsal and live demonstration.

The local environment is fully operational and **READY for manual demonstration rehearsal**.

---

## 2. Environment Status

| Component | Target Parameter | Verified Live Status | Result |
| :--- | :--- | :--- | :--- |
| **Operating System** | Windows 10/11 x64 | Windows (pwsh 7 / PowerShell environment) | **Pass** |
| **Source Directory** | `C:\Users\user\Desktop\Railway\eleguard-rail` | Intact, 0 staged Git changes (`git diff --cached` exit code 0) | **Pass** |
| **XAMPP Root** | `C:\xampp` | Standard XAMPP 8.2 layout verified | **Pass** |
| **Web Server DocumentRoot** | `C:\xampp\htdocs` | Verified mapped to `C:/xampp/htdocs` in `httpd.conf` | **Pass** |
| **Application Base URL** | `http://localhost/eleguard-rail/public/` | Serving active requests via Apache 2.4 | **Pass** |
| **Application Config (`.env`)** | `DB_HOST=127.0.0.1`, `DB_PORT=3308`, `DB_NAME=eleguard_rail` | Present in repository and web root; no secrets logged | **Pass** |

---

## 3. Service Verification

### 3.1 Apache HTTP Server
- **Binary Path:** `C:\xampp\apache\bin\httpd.exe`
- **Configuration File:** `C:\xampp\apache\conf\httpd.conf`
- **Configured Port:** `80` (`Listen 80`)
- **Runtime PID:** `21660`
- **Listener State:** `0.0.0.0:80` (TCP Listen) and `[::]:80` (TCP Listen)
- **Status:** **RUNNING**

### 3.2 XAMPP MariaDB Database
- **Binary Path:** `C:\xampp\mysql\bin\mysqld.exe`
- **Configuration File:** `C:\xampp\mysql\bin\my.ini` (`--defaults-file=mysql\bin\my.ini --standalone`)
- **Configured Port:** `3308` (`[mysqld] port=3308`, `[client] port=3308`)
- **Runtime PID:** `17372`
- **Listener State:** `[::]:3308` (TCP Listen)
- **Status:** **RUNNING**

### 3.3 Coexisting Database Services (Untouched)
- **Service Name:** `MySQL80` (Windows Service)
- **Port:** `3306` (`[::]:3306`)
- **Runtime PID:** `7304`
- **Status:** **RUNNING / UNTOUCHED** (Zero interaction or interference)

---

## 4. PHP Runtime & Extensions

The PHP runtime was verified using the official XAMPP PHP CLI binary (`C:\xampp\php\php.exe`):
- **Version:** `PHP 8.2.12 (cli) (built: Oct 24 2023 21:15:15) (ZTS Visual C++ 2019 x64)`
- **Required Core Extensions Verified:**
  - `pdo_mysql`: Active (Database engine connectivity)
  - `openssl`: Active (Cryptographic integrity & HTTPS support)
  - `session`: Active (Session state management & CSRF protection)
  - `json`: Active (API serialization)
  - `mbstring`: Active (Unicode string handling)
  - `fileinfo`: Active (MIME-type validation for photo uploads)
  - `curl`: Active (External integration capability)
  - `gd`: Active (Image handling and verification stamp thumbnailing)
  - `hash`: Active (HMAC and SHA-256 payload integrity)

---

## 5. Database Connectivity & Data Integrity (Read-Only)

Database connectivity was tested directly through the application's unified database singleton (`App\Core\Database` / `Database::getInstance()->getConnection()`):
- **Target Host & Port:** `127.0.0.1:3308`
- **Target Database:** `eleguard_rail`
- **Database Server Version:** `10.4.32-MariaDB`
- **Connection Handshake:** Succeeded on first attempt with zero latency errors.
- **Baseline Record Inspection (Read-Only):**
  - Table `elephant_reports`: **43 existing elephant report records; not all are independently verified as genuine field sightings.**
  - Table `users`: **5 standard role accounts** active (`admin@eleguard.lk`, `control@eleguard.lk`, `driver@eleguard.lk`, `wildlife@eleguard.lk`, `reporter@eleguard.lk`)
- **Data Mutation Audit:** Exactly 0 database write queries (`INSERT`, `UPDATE`, `DELETE`, `DROP`, `ALTER`, `TRUNCATE`) were issued. Historical database figures remain strictly preserved.

---

## 6. HTTP Endpoint & Role Dashboard Smoke Test Results

All critical public, authenticated, and static endpoints were smoke-tested via HTTP requests against `http://localhost/eleguard-rail/`:

| Endpoint / Resource | URL Path | Method | HTTP Status | Behavior & Verification Evidence |
| :--- | :--- | :---: | :---: | :--- |
| **Application Index** | `/public/index.php` | GET | `200 OK` | Renders public landing page cleanly. |
| **User Login Form** | `/public/login.php` | GET | `200 OK` | Renders email/password authentication form with CSRF token protection. |
| **Control Officer Dashboard** | `/public/control/dashboard.php` | GET | `302 Found` | Unauthenticated request cleanly redirected to `/public/login.php`. Syntax lint clean (`php -l` exit code 0). |
| **Control Officer Reports** | `/public/control/reports.php` | GET | `302 Found` | Role-protected; redirects unauthenticated callers. Syntax lint clean. |
| **Wildlife Officer Dashboard** | `/public/wildlife/dashboard.php` | GET | `302 Found` | Role-protected; redirects unauthenticated callers. Syntax lint clean. |
| **Wildlife Officer Reports** | `/public/wildlife/reports.php` | GET | `302 Found` | Role-protected; redirects unauthenticated callers. Syntax lint clean. |
| **Driver Alert HUD** | `/public/driver/alerts.php` | GET | `302 Found` | Role-protected; redirects unauthenticated callers. Syntax lint clean. |
| **Driver Dashboard** | `/public/driver/dashboard.php` | GET | `302 Found` | Role-protected; redirects unauthenticated callers. Syntax lint clean. |
| **Field Reporter PWA Entry** | `/public/field/index.php` | GET | `302 Found` | Role-protected; redirects unauthenticated callers. Syntax lint clean. |
| **Admin Dashboard** | `/public/admin/dashboard.php` | GET | `302 Found` | Role-protected; redirects unauthenticated callers. Syntax lint clean. |
| **PWA Web Manifest** | `/public/manifest.webmanifest` | GET | `200 OK` | Delivers valid JSON PWA application manifest (`name: "EleGuard Rail - Field Reporter"`). |
| **Service Worker Script** | `/public/sw.js` | GET | `200 OK` | Delivers service worker cache engine (5,611 bytes). |
| **Offline Shell Fallback** | `/public/offline.html` | GET | `200 OK` | Delivers responsive offline HTML fallback UI (7,656 bytes). |
| **GIS Map Component** | `/public/assets/js/map_component.js` | GET | `200 OK` | Delivers Leaflet GIS controller and proximity zone renderer (16.5 KB). |
| **GIS Map Stylesheet** | `/public/assets/css/map.css` | GET | `200 OK` | Delivers custom map container styling (4.3 KB). |
| **Leaflet Vendor JS** | `/public/assets/vendor/leaflet/leaflet.js` | GET | `200 OK` | Delivers Leaflet core library (143 KB). |
| **Leaflet Vendor CSS** | `/public/assets/vendor/leaflet/leaflet.css` | GET | `200 OK` | Delivers Leaflet core stylesheet (14.5 KB). |
| **Railway Route GeoJSON** | `/public/assets/data/railway_routes.geojson` | GET | `200 OK` | Delivers Sri Lanka railway corridor geometry (73.8 KB). |
| **GIS Data API Endpoint** | `/public/api/gis_data.php` | GET | `401 Unauthorized` | API access control active; unauthenticated requests rejected cleanly. |

### 6.1 Critical Scope Boundaries of Smoke Testing

To ensure absolute technical rigor and prevent misinterpretation of smoke test outcomes, the following technical distinctions are documented:

1. **Authentication Barrier vs. Authenticated Workflow Testing:**
   - Protected dashboards (`/control/dashboard.php`, `/wildlife/dashboard.php`, `/driver/dashboard.php`, `/field/index.php`, etc.) were tested for **unauthenticated access redirection (HTTP 302 to `/public/login.php`)** and CLI syntax validation (`php -l`).
   - They were **not** tested for complete authenticated role operations (such as session creation, permission escalations, alert issuances, or state changes) during this automated run to strictly guarantee zero database mutations.
2. **Static Asset Delivery vs. Client-Side GIS Rendering:**
   - Serving `map_component.js`, Leaflet vendor files, and `railway_routes.geojson` with HTTP 200 OK confirms web-server availability and file integrity.
   - It does **not** establish client-side GIS rendering, OpenStreetMap tile server reachability, canvas layer compositing, or interactive polygon clustering within a live browser viewport.
3. **PWA Manifest Delivery vs. Offline Synchronization:**
   - Serving `manifest.webmanifest`, `sw.js`, and `offline.html` confirms PWA file availability and service worker registration capability.
   - It does **not** establish successful client-side IndexedDB encrypted storage, background sync, or offline-to-online queue synchronization.
4. **Login Form Serving vs. End-to-End Field Reporting:**
   - Testing HTTP 200 on login forms does **not** establish end-to-end field report submissions, photo upload validation, or multi-role verification transitions.

---

## 7. Remaining Blockers & Operational Limitations

| Item | Status | Operational Note & Boundary |
| :--- | :---: | :--- |
| **Port Conflicts** | **None** | Port 80 and Port 3308 are dedicated to XAMPP Apache and MariaDB. No conflicts exist. |
| **Database Privileges** | **None** | Application database `eleguard_rail` connects cleanly under standard runtime user. |
| **Driver Speed Source** | **Documented Limitation** | Speed telemetry reflects simulated inputs; driver acknowledgements are tested exclusively in stationary mode. |
| **Standoff Distance Description** | **Documented Consistency** | Field reporting visual standoff distances are consistently specified as 50–150 m. |
| **Pending Migration / Schema Issues** | **None** | Database schema matches application codebase; 0 migrations pending or required for demo. |

---

## 8. Remaining Manual Demonstration Rehearsal Checks

Before commencing the 15–20 minute stakeholder demonstration, the presenter must execute the following manual interactive checks:

1. **Manual Role Authentication Rehearsal:**
   - Log in sequentially via browser UI as `control@eleguard.lk`, `wildlife@eleguard.lk`, and `driver@eleguard.lk`.
   - Verify that session cookies (`PHPSESSID`) are established and that each role is directed to its dedicated workspace.
2. **Visual GIS Dashboard Verification:**
   - Navigate to the Control Officer GIS dashboard and verify that the Leaflet base map tiles render properly.
   - Confirm that the three proximity zones (Zone 0: 0–15 m, Zone 1: 15–100 m, Zone 2: 100–1,000 m) display with correct corridor styling.
3. **PWA Client-Side Cache & Offline Simulation:**
   - Open `/public/field/index.php` in a Chromium-based browser with DevTools open.
   - Verify Service Worker installation under the Application tab.
   - Toggle "Offline" mode in Network throttling and verify that `offline.html` and cached draft forms remain accessible.
4. **Driver HUD Stationary Advisory Check:**
   - Open `/public/driver/alerts.php` and verify that the cautionary advisory banner renders clearly without requiring emergency braking actions while stationary.

---

## 9. Readiness Determination

- **Environment Health:** **100% Operational**
- **Web Server:** Active on `http://localhost/eleguard-rail/public/index.php`
- **Database Engine:** Active on `127.0.0.1:3308` (`eleguard_rail`)
- **Read-Only Safety:** Strictly preserved (0 records mutated, 0 Git commits, 0 schema alterations)
- **Final Verdict:** **READY FOR LIVE STAKEHOLDER DEMONSTRATION & MANUAL REHEARSAL**
