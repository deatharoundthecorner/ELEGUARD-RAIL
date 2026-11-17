# ELEGUARD RAIL — Comprehensive Manual System Testing Plan

**Document Reference:** `ER-MANUAL-TEST-PLAN-001`  
**Execution Timestamp:** 2026-11-15 13:50:00 IST  
**Target System:** ELEGUARD RAIL Release Candidate 1.0  
**Repository Location:** `C:\Users\user\Desktop\Railway\eleguard-rail`  
**Web Server Root:** `C:\xampp\htdocs\eleguard-rail`  
**Operational Target:** Public GitHub Publication & LinkedIn Engineering Showcase  

---

## 1. Testing Environment Assessment & Safety Framework

### 1.1 Testing Environment Architecture
The testing environment is partitioned into two distinct operational contexts to guarantee absolute preservation of production-baseline records:

1. **Operational Baseline Context (`eleguard_rail` on port 3308):**
   - Strictly reserved for **`[READ-ONLY]`** testing.
   - Contains 43 baseline elephant report records and 5 standard user role accounts.
   - **Zero-Write Policy:** No form submissions, state updates, report verifications, alert dispatches, or password resets may execute against this database.
2. **Disposable Test Database Context (`eleguard_test_tmp_<hex>` on port 3308):**
   - Mandatory for all **`[DISPOSABLE DATABASE REQUIRED]`** state-changing tests.
   - Dynamically provisioned via `tests/bootstrap.php` using canonical baseline schemas and migrations (`01_` through `06_`).
   - Automatically isolated from operational tables; destroyed or rolled back upon test suite completion.

### 1.2 Test Safety Classification System
Every manual test case in this plan is explicitly tagged with one of four mandatory safety classifications:

| Safety Classification | Operational Policy & Execution Boundary |
| :--- | :--- |
| **`[READ-ONLY]`** | Safe to execute against the operational demonstration instance. Inspects static assets, unauthenticated routes, read-only GET queries, DOM elements, and client-side styling. Zero database mutations. |
| **`[DISPOSABLE DATABASE REQUIRED]`** | Mutates application state (creates, updates, or deletes records). **Must NEVER run on `eleguard_rail`**. Requires provisioning an ephemeral test database (`eleguard_test_tmp_*`). |
| **`[EXTERNAL SERVICE REQUIRED]`** | Relies on third-party network APIs (e.g. OpenStreetMap tile servers, Sanity Headless CMS, external SMS gateway). Subject to upstream network availability. |
| **`[REAL-WORLD VALIDATION REQUIRED]`** | Cannot be fully simulated in local desktop environments (e.g. hardware locomotive CAN bus J1939 speed sensors, live GSM edge-of-network cell towers, field GPS satellite locks in dense canopy). Documented as operational pilot prerequisites. |

---

## 2. Standard Test Accounts Reference

| Role | Username / Email | Default Test Password | Functional Responsibility |
| :--- | :--- | :--- | :--- |
| **Administrator** | `admin@eleguard.lk` | `admin123` | User lifecycle, system logs, master section configuration |
| **Railway Control Officer** | `control@eleguard.lk` | `control123` | Train tracking, hazard GIS map, corridor warning broadcasts |
| **Locomotive Driver** | `driver@eleguard.lk` | `driver123` | Stationary cab HUD advisories, cautionary route receipts |
| **Wildlife Officer** | `wildlife@eleguard.lk` | `wildlife123` | Biological classification, 4-method verification workspace |
| **Field Reporter** | `reporter@eleguard.lk` | `reporter123` | PWA field telemetry capture, decoupled georeferencing |

---

## 3. Comprehensive Manual Test Cases

---

### Section A: Authentication and Session Management

#### TC-AUTH-01: Public Landing Page and Login Navigation
- **Safety Class:** `[READ-ONLY]`
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance (`http://localhost/eleguard-rail/public/`)
- **Preconditions:** Browser open, no active session cookies.
- **Test Steps:**
  1. Navigate to `http://localhost/eleguard-rail/public/index.php`.
  2. Inspect the top navigation bar, branding, and hero section.
  3. Click the "Login" button in the navigation bar.
- **Expected Result:**
  - Landing page loads with HTTP 200 OK.
  - Institutional navy and emerald styling is properly rendered.
  - Redirects or navigates cleanly to `http://localhost/eleguard-rail/public/login.php`.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Browser screenshot of landing hero and network request log.

#### TC-AUTH-02: Universal Login Form CSRF Protection
- **Safety Class:** `[READ-ONLY]`
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance
- **Preconditions:** On `public/login.php`.
- **Test Steps:**
  1. Inspect the HTML source code of the login form.
  2. Verify the presence of `<input type="hidden" name="csrf_token" value="...">`.
  3. Inspect the session cookie generated on page load.
- **Expected Result:**
  - A 64-character hexadecimal CSRF token is present in the hidden input.
  - `PHPSESSID` cookie is assigned with `HttpOnly` and `SameSite=Lax` attributes.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** DOM inspector snippet showing hidden CSRF field and cookie flags.

#### TC-AUTH-03: Multi-Role Authentication & Role Redirection
- **Safety Class:** `[DISPOSABLE DATABASE REQUIRED]` *(Note: `Auth::login` writes to `system_logs`)*
- **Data Mutation:** `YES` (Writes audit log entry)
- **Environment:** Disposable Test Database (`eleguard_test_tmp_*`)
- **Preconditions:** Disposable database seeded with standard test accounts.
- **Test Steps:**
  1. Authenticate sequentially as:
     - `admin@eleguard.lk`
     - `control@eleguard.lk`
     - `wildlife@eleguard.lk`
     - `driver@eleguard.lk`
     - `reporter@eleguard.lk`
  2. Observe the destination URL for each role.
- **Expected Result:**
  - Admin $\to$ `/public/admin/dashboard.php`
  - Control Officer $\to$ `/public/control/dashboard.php`
  - Wildlife Officer $\to$ `/public/wildlife/reports.php`
  - Locomotive Driver $\to$ `/public/driver/alerts.php`
  - Field Reporter $\to$ `/public/field/index.php` (or `/reporter/dashboard.php`)
  - Session ID is regenerated (`session_regenerate_id(true)`) upon successful authentication to prevent session fixation.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Network waterfall showing 302 redirects and session cookie values before/after login.

#### TC-AUTH-04: Session Termination & Invalidation (Logout)
- **Safety Class:** `[DISPOSABLE DATABASE REQUIRED]`
- **Data Mutation:** `YES` (Writes audit log entry)
- **Environment:** Disposable Test Database
- **Preconditions:** User is logged in with an active session.
- **Test Steps:**
  1. Click the "Logout" button in the dashboard sidebar.
  2. Attempt to navigate back using browser history.
  3. Refresh the protected dashboard URL.
- **Expected Result:**
  - User is redirected to `/public/login.php` with a flash logout notification.
  - Server clears session data and expires the `PHPSESSID` cookie.
  - Browser back navigation to protected dashboard forces immediate redirect to login.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Network headers showing expired cookie (`Set-Cookie: PHPSESSID=deleted; expires=...`).

---

### Section B: Admin Dashboard and User Management

#### TC-ADM-01: Admin Operational Dashboard Metric Cards
- **Safety Class:** `[READ-ONLY]`
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance (using authenticated read-only session)
- **Preconditions:** Admin session active.
- **Test Steps:**
  1. Navigate to `http://localhost/eleguard-rail/public/admin/dashboard.php`.
  2. Inspect the operational summary cards (Total Users, Active Alerts, System Health, Total Reports).
- **Expected Result:**
  - Dashboard loads with HTTP 200 OK.
  - All metric cards accurately reflect current database counts.
  - Quick action links (User Management, Railway Sections, Audit Logs) are operational.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Screenshot of Admin dashboard overview.

#### TC-ADM-02: User Lifecycle Management (Create, Update, Deactivate)
- **Safety Class:** `[DISPOSABLE DATABASE REQUIRED]`
- **Data Mutation:** `YES` (Inserts and updates rows in `users` table)
- **Environment:** Disposable Test Database
- **Preconditions:** Admin logged in on disposable instance.
- **Test Steps:**
  1. Navigate to `/public/admin/users.php`.
  2. Click "Add New User" and create a user `test_ranger@eleguard.lk` with role `wildlife_officer`.
  3. Edit the user and change name to "Senior Ranger".
  4. Toggle status from `active` to `inactive`.
  5. In a separate browser, attempt to log in as `test_ranger@eleguard.lk`.
- **Expected Result:**
  - User creation succeeds; password is encrypted with bcrypt (`PASSWORD_BCRYPT`).
  - Update persists accurately.
  - Inactive user is rejected at login with error: *"Your account is deactivated."*
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Database record verification before and after deactivation; login rejection screen.

---

### Section C: Control Officer Dashboard and GIS

#### TC-CTL-01: Control Desk KPI Overview & Layout
- **Safety Class:** `[READ-ONLY]`
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance
- **Preconditions:** Control Officer session active.
- **Test Steps:**
  1. Navigate to `/public/control/dashboard.php`.
  2. Inspect the 4 KPI cards: Active Danger Alerts, Critical Hazards, Verified Sightings, Pending Public Reports.
- **Expected Result:**
  - Metrics load cleanly from baseline database.
  - High-risk hazards are highlighted in crimson (`var(--danger)`).
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Screenshot of Control Officer overview.

#### TC-CTL-02: Interactive GIS Corridor Map Rendering
- **Safety Class:** `[EXTERNAL SERVICE REQUIRED]` / `[READ-ONLY]`
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance
- **Preconditions:** Active Internet connection to load OpenStreetMap tiles.
- **Test Steps:**
  1. Scroll to the "Live Railway Corridor Hazard Map" container on the Control Desk.
  2. Verify that Leaflet initializes without JavaScript errors.
  3. Zoom into the Northern Railway line corridor (Maho to Galgamuwa section).
  4. Inspect the 3 proximity zones:
     - Zone 0 (0–15 m): Track bed hazard buffer.
     - Zone 1 (15–100 m): Cautionary approach zone.
     - Zone 2 (100–1,000 m): Situational monitoring zone.
- **Expected Result:**
  - OpenStreetMap tiles load with HTTP 200 OK.
  - GeoJSON railway corridor polyline renders cleanly.
  - Three distinct proximity buffer polygons display with correct styling and transparency.
  - Zero console errors in DevTools.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Screenshot of full GIS canvas with proximity zones rendered and DevTools Console log.

#### TC-CTL-03: Railway Corridor Alert Broadcast
- **Safety Class:** `[DISPOSABLE DATABASE REQUIRED]`
- **Data Mutation:** `YES` (Inserts into `alerts` table)
- **Environment:** Disposable Test Database
- **Preconditions:** Verified sighting present in reports queue.
- **Test Steps:**
  1. Navigate to `/public/control/create_alert.php`.
  2. Select section `Maho - Galgamuwa Section`.
  3. Select severity `Critical` and enter advisory message: *"Wild elephant herd observed adjacent to track."*
  4. Submit alert broadcast form.
- **Expected Result:**
  - Form validates successfully.
  - Alert is inserted into `alerts` table with `status = 'Active'`.
  - Alert immediately appears on Control Desk and Driver alert feeds.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Database inspection showing new alert record and screenshot of active alert card.

---

### Section D: Wildlife Officer Reporting and Verification

#### TC-WLD-01: Verification Workspace Status Tabs & Filtering
- **Safety Class:** `[READ-ONLY]`
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance
- **Preconditions:** Wildlife Officer session active.
- **Test Steps:**
  1. Navigate to `/public/wildlife/reports.php`.
  2. Click through the status filter tabs: `Pending`, `Needs Information`, `Clarification Submitted`, `Verified`, `Rejected`.
- **Expected Result:**
  - Status tabs switch smoothly without page reload errors.
  - Reports in each queue match the selected operational status.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Screenshot of each status tab queue.

#### TC-WLD-02: Detailed Sighting Inspection & Demographics
- **Safety Class:** `[READ-ONLY]`
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance
- **Preconditions:** On `/public/wildlife/reports.php` inspecting a verified sighting.
- **Test Steps:**
  1. Click to view detailed observation record.
  2. Verify observation demographics: Adult males, adult females, juveniles, calves, solitary bulls/tuskers.
  3. Verify decoupled coordinates: Observer coordinates vs. projected animal coordinates.
  4. Verify reporting standoff distance is documented within the 50–150 m range.
  5. Verify spatial uncertainty radius ($\pm X\text{ m}$) calculated via RSS error math.
- **Expected Result:**
  - Observer location is visually and numerically separated from animal location.
  - Standoff distance is clearly labeled as observer standoff, distinct from device GPS accuracy.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Screenshot of detailed sighting inspection card.

#### TC-WLD-03: Sighting Verification Decision Execution
- **Safety Class:** `[DISPOSABLE DATABASE REQUIRED]`
- **Data Mutation:** `YES` (Updates `elephant_reports`, inserts into `verification_audits`)
- **Environment:** Disposable Test Database
- **Preconditions:** Pending sighting report present in disposable test database.
- **Test Steps:**
  1. Open a pending report on `/public/wildlife/reports.php`.
  2. Select verification method: `Direct Field Ranger Sighting`.
  3. Enter justification notes: *"Confirmed by Wildlife Ranger visual reconnaissance."*
  4. Click "Verify Sighting".
- **Expected Result:**
  - Report status transitions from `Pending` to `Verified`.
  - Verification timestamp and verifying officer ID are recorded.
  - Audit entry is logged in `verification_audits`.
  - Anti-self-verification rule prevents a reporter from verifying their own submission.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Database before/after query of `elephant_reports` and `verification_audits`.

---

### Section E: Field Reporter PWA

#### TC-PWA-01: Service Worker Registration & Manifest Delivery
- **Safety Class:** `[READ-ONLY]`
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance
- **Preconditions:** Chrome/Edge browser with DevTools open (F12).
- **Test Steps:**
  1. Navigate to `http://localhost/eleguard-rail/public/field/index.php`.
  2. Open DevTools $\to$ **Application** tab.
  3. Inspect **Manifest**: verify `name`, `short_name`, `theme_color`, and icon paths.
  4. Inspect **Service Workers**: verify `sw.js` status.
- **Expected Result:**
  - Manifest is parsed with zero errors (`name: "EleGuard Rail - Field Reporter"`).
  - Service worker `sw.js` is installed, active, and controlling the page.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Screenshot of DevTools Application tab showing active service worker.

#### TC-PWA-02: Guided 6-Step Field Capture Wizard
- **Safety Class:** `[READ-ONLY]` *(Halt at Step 6)*
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance
- **Preconditions:** On `/public/field/index.php`.
- **Test Steps:**
  1. Step 1 (Metadata): Enter date, observation time, railway section.
  2. Step 2 (Decoupled Geolocation): Set observer GPS, standoff distance (80 m via slider), compass bearing (45°).
  3. Step 3 (Demographics): Set adult male (1), adult female (2), calf (1).
  4. Step 4 (Ecological Behavior): Select diurnal period `Dusk` and behavior `Crossing track`.
  5. Step 5 (Photo Preview): Select a sample image; observe client-side thumbnail generation.
  6. **Step 6 (Review & Confirmation): Review summary card. DO NOT CLICK SUBMIT.**
- **Expected Result:**
  - Wizard steps advance smoothly with client-side form validation.
  - Standoff distance slider is constrained within documented 50–150 m range.
  - Review screen displays pre-submission summary with decoupled coordinates.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Screenshots of Step 2 (Decoupling) and Step 6 (Review).

---

### Section F: GPS and Geospatial Calculations

#### TC-GPS-01: Forward Geodesic Projection Accuracy
- **Safety Class:** `[DISPOSABLE DATABASE REQUIRED]`
- **Data Mutation:** `YES` (Backend calculation verification)
- **Environment:** Disposable Test Database / Automated Harness
- **Preconditions:** Backend Geodesic Engine (`ProximityService.php` / `FieldReportingService.php`) loaded.
- **Test Steps:**
  1. Input observer coordinate: Lat `8.03450000`, Lon `80.28500000`.
  2. Input standoff distance: `80.0 m`.
  3. Input compass bearing: `45.0°` (North-East).
  4. Execute Great-Circle spherical trigonometric forward projection ($R = 6,371\text{ km}$).
- **Expected Result:**
  - Projected animal coordinates match theoretical spherical values within $\pm 0.000001^{\circ}$ precision ($\approx 11\text{ cm}$).
  - Animal position is decoupled from observer position.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Mathematical calculation log and automated test assertion output.

#### TC-GPS-02: Root-Sum-Square (RSS) Spatial Uncertainty Propagation
- **Safety Class:** `[READ-ONLY]`
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance (Inspection) / Test Harness
- **Test Steps:**
  1. Review uncertainty calculation for an observation with:
     - GPS receiver horizontal accuracy: $\sigma_{\text{gps}} = 7.5\text{ m}$
     - Optical distance estimation variance: $20\%$ of $80\text{ m} = 16.0\text{ m}$
     - Compass angular variance: $10^{\circ}$ over $80\text{ m} = 80 \times \sin(10^{\circ}) \approx 13.89\text{ m}$
  2. Compute RSS: $\sigma_{\text{total}} = \sqrt{7.5^2 + 16.0^2 + 13.89^2} \approx 22.48\text{ m}$.
  3. Verify that if GPS accuracy is missing/null, uncertainty defaults to `NULL` (suppressing uncertain circles per Stage J.6 audit).
- **Expected Result:**
  - Spatial uncertainty matches RSS formula.
  - Missing GPS accuracy strictly leaves uncertainty unknown (`NULL`), never assuming synthetic 10m precision.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Source code verification in `ProximityService.php` and test execution output.

---

### Section G: Photograph Upload and Validation

#### TC-UPL-01: File Extension & MIME-Type Validation
- **Safety Class:** `[DISPOSABLE DATABASE REQUIRED]`
- **Data Mutation:** `YES` (Upload handling)
- **Environment:** Disposable Test Database
- **Test Steps:**
  1. Attempt to upload a disallowed file type (e.g. `.php`, `.exe`, `.html`, `.svg`).
  2. Attempt to upload an image with a spoofed extension (e.g. `malicious.php.jpg`).
  3. Upload a genuine JPEG/PNG image under 5 MB.
- **Expected Result:**
  - Disallowed extensions are rejected immediately by client-side and server-side validation.
  - Server verifies true MIME-type via `finfo_file` (PHP Fileinfo extension). Spoofed files are rejected with HTTP 400/422.
  - Valid images are accepted and stored with randomized alphanumeric filenames.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Error response for malicious upload attempt; server directory inspection of uploaded valid image.

---

### Section H: Offline Storage and Synchronization

#### TC-OFF-01: Offline Fallback Shell Display
- **Safety Class:** `[READ-ONLY]`
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance
- **Preconditions:** Browser DevTools open.
- **Test Steps:**
  1. Open `http://localhost/eleguard-rail/public/offline.html`.
  2. In DevTools Network tab, set Throttling to **"Offline"**.
  3. Refresh the page.
- **Expected Result:**
  - Standalone offline fallback shell renders cleanly with HTTP 200 OK.
  - Displays high-contrast "Network Connection Unavailable" badge and client encryption security notice.
  - Action buttons ("Check Connection & Retry") are operational.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Screenshot of rendered `offline.html` under DevTools Offline throttling.

#### TC-OFF-02: Client-Side Encrypted Storage (AES-GCM in IndexedDB)
- **Safety Class:** `[READ-ONLY]`
- **Data Mutation:** `NO` (Client-side browser storage inspection)
- **Environment:** Operational Local Instance
- **Test Steps:**
  1. On `/public/field/index.php`, open DevTools $\to$ **Application** tab $\to$ **IndexedDB**.
  2. Inspect the local draft storage structure.
  3. Verify that sensitive sighting fields and drafts are stored using AES-GCM encrypted payloads.
- **Expected Result:**
  - Draft payloads stored in IndexedDB are ciphertext strings; plaintext coordinates and demographics are protected at rest on the client device.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** DevTools IndexedDB payload inspection screenshot showing ciphertext value.

#### TC-OFF-03: Background Synchronization with Idempotency Key
- **Safety Class:** `[DISPOSABLE DATABASE REQUIRED]`
- **Data Mutation:** `YES` (Batch insertion into `elephant_reports`)
- **Environment:** Disposable Test Database
- **Preconditions:** Encrypted offline draft stored with client-generated RFC 4122 v4 UUID idempotency key.
- **Test Steps:**
  1. Restore network connection.
  2. Trigger background sync to `/public/api/v2/reports/sync_batch.php`.
  3. Send the identical payload a second time (simulating network duplicate transmission).
- **Expected Result:**
  - First sync request succeeds with HTTP 201 Created; report is stored in database.
  - Duplicate sync request detects matching UUID idempotency key and returns HTTP 200/409 without creating duplicate database records.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Database inspection confirming exactly 1 record created for the unique UUID key.

---

### Section I: Driver Advisory HUD

#### TC-DRV-01: Locomotive Cab Advisory HUD & Stationary Safety Banner
- **Safety Class:** `[READ-ONLY]`
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance
- **Preconditions:** Driver session active (`driver@eleguard.lk`).
- **Test Steps:**
  1. Navigate to `http://localhost/eleguard-rail/public/driver/alerts.php`.
  2. Inspect the top regulatory banner.
  3. Inspect active warning cards and route section selector.
- **Expected Result:**
  - High-contrast regulatory banner renders prominently:  
    `⚠️ STATIONARY REVIEW USE ONLY — DO NOT INTERACT WHILE IN MOTION`
  - Explicit instruction text states that advisory archives must be reviewed exclusively while stationary at a scheduled stop, siding, or stop signal.
  - Active advisories display corridor milestone, severity badge, and Caution Order precedence reminder.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Screenshot of Driver Advisory HUD with stationary regulation banner.

#### TC-DRV-02: Stationary Driver Acknowledgement Receipt
- **Safety Class:** `[DISPOSABLE DATABASE REQUIRED]`
- **Data Mutation:** `YES` (Inserts into `driver_acknowledgements`)
- **Environment:** Disposable Test Database
- **Preconditions:** Active alert displayed on driver HUD.
- **Test Steps:**
  1. Open alert details on `/public/driver/alert_details.php?id=...`.
  2. Verify stationary acknowledgement modal.
  3. Submit acknowledgement receipt with action: `Acknowledged / Operating Under Caution Order`.
- **Expected Result:**
  - Acknowledgement is recorded in `driver_acknowledgements` table with timestamp and driver ID.
  - Button state changes to "Acknowledged".
  - Control room dashboard reflects updated driver acknowledgement status.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Database record inspection in `driver_acknowledgements` and Control Officer acknowledgement view.

---

### Section J: Role-Based Access Control (RBAC)

#### TC-RBAC-01: Unauthenticated Endpoint Redirection
- **Safety Class:** `[READ-ONLY]`
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance
- **Preconditions:** No active session cookies.
- **Test Steps:**
  1. Attempt direct navigation to:
     - `/public/admin/dashboard.php`
     - `/public/control/dashboard.php`
     - `/public/wildlife/reports.php`
     - `/public/driver/alerts.php`
     - `/public/field/index.php`
- **Expected Result:**
  - Every protected page returns HTTP 302 redirecting to `/public/login.php`.
  - Flash notification banner displays: *"You must be logged in to access the dashboard."*
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Network trace showing 302 responses and screenshot of login redirect with banner.

#### TC-RBAC-02: Cross-Role Privilege Escalation Denial
- **Safety Class:** `[READ-ONLY]`
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance (using role session cookies)
- **Test Matrix:**
  1. Log in as **Driver** $\to$ Attempt to access `/public/admin/dashboard.php`.
  2. Log in as **Field Reporter** $\to$ Attempt to access `/public/control/dashboard.php`.
  3. Log in as **Control Officer** $\to$ Attempt to access `/public/wildlife/reports.php`.
- **Expected Result:**
  - Role-middleware (`app/middleware/role_required.php`) denies access.
  - User is cleanly redirected back to their own role dashboard with an unauthorized flash warning, or presented with an HTTP 403 Forbidden screen.
  - Zero sensitive data or admin interfaces leaked.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Screenshot of denial notification for each role violation attempt.

---

### Section K: CSRF and Application Security

#### TC-SEC-01: Cross-Site Request Forgery (CSRF) Token Rejection
- **Safety Class:** `[DISPOSABLE DATABASE REQUIRED]`
- **Data Mutation:** `NO` (Request rejected before database interaction)
- **Environment:** Disposable Test Database
- **Test Steps:**
  1. Intercept a state-changing POST request (e.g. login, alert creation, or report submission).
  2. Tamper with the `csrf_token` parameter (modify value or omit it entirely).
  3. Forward the request to the server.
- **Expected Result:**
  - Middleware `validate_csrf()` intercepts the request.
  - Request is immediately rejected with HTTP 403 Forbidden / CSRF validation error.
  - Zero database queries are executed.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** HTTP response headers and body showing CSRF failure.

#### TC-SEC-02: SQL Injection Resistance (PDO Prepared Statements)
- **Safety Class:** `[READ-ONLY]`
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance / Test Harness
- **Test Steps:**
  1. Test input parameters across search, filter, and report viewing endpoints with SQL injection payloads:
     - `' OR '1'='1`
     - `1; DROP TABLE users;--`
     - `UNION SELECT null, username, password FROM users--`
- **Expected Result:**
  - All database queries use PDO prepared statements with explicit parameter binding (`:param`).
  - Input is treated strictly as literal data; no SQL syntax errors or data dumps occur.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Server response log showing clean input handling with zero database error disclosure.

---

### Section L: Database Integrity and Audit Logging

#### TC-AUD-01: Immutable Audit Trail Logging
- **Safety Class:** `[DISPOSABLE DATABASE REQUIRED]`
- **Data Mutation:** `YES` (Inserts into `system_logs`)
- **Environment:** Disposable Test Database
- **Test Steps:**
  1. Perform a series of actions (login, failed login, sighting verification, alert broadcast).
  2. Query the `system_logs` table.
- **Expected Result:**
  - Every critical action generates an immutable audit record containing:
    - User ID
    - Action type (e.g. `User Login`, `Failed Login Attempt`, `Verify Sighting`)
    - Action description
    - Timestamp
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** SQL query dump of `system_logs` showing chronological audit entries.

#### TC-AUD-02: Operational Database Baseline Invariance
- **Safety Class:** `[READ-ONLY]`
- **Data Mutation:** `NO`
- **Environment:** Operational Local Instance (`eleguard_rail`)
- **Test Steps:**
  1. Record baseline row counts before any read-only manual test run:
     - `SELECT COUNT(*) FROM elephant_reports;` (Baseline: 43)
     - `SELECT COUNT(*) FROM users;` (Baseline: 5)
     - `SELECT COUNT(*) FROM system_logs;` (Baseline: 185)
  2. Execute all approved read-only test cases.
  3. Re-query row counts.
- **Expected Result:**
  - Row counts are 100% identical before and after testing.
  - Exactly 0 rows added, modified, or deleted in the operational database.
- **Actual Result:** `[PENDING EXECUTION]`
- **Evidence Required:** Pre-test and post-test database count queries.

---

## 4. Test Execution Summary Matrix

| Section | Topic | Total Cases | Read-Only | Disposable DB Req. | External / Real-World |
| :---: | :--- | :---: | :---: | :---: | :---: |
| **A** | Authentication & Session Management | 4 | 2 | 2 | 0 |
| **B** | Admin Dashboard & User Management | 2 | 1 | 1 | 0 |
| **C** | Control Officer Dashboard & GIS | 3 | 1 | 1 | 1 |
| **D** | Wildlife Officer Reporting & Verification | 3 | 2 | 1 | 0 |
| **E** | Field Reporter PWA | 2 | 2 | 0 | 0 |
| **F** | GPS & Geospatial Calculations | 2 | 1 | 1 | 0 |
| **G** | Photograph Upload & Validation | 1 | 0 | 1 | 0 |
| **H** | Offline Storage & Synchronization | 3 | 2 | 1 | 0 |
| **I** | Driver Advisory HUD | 2 | 1 | 1 | 0 |
| **J** | Role-Based Access Control (RBAC) | 2 | 2 | 0 | 0 |
| **K** | CSRF & Application Security | 2 | 1 | 1 | 0 |
| **L** | Database Integrity & Audit Logging | 2 | 1 | 1 | 0 |
| **TOTAL** | **Comprehensive System Validation** | **28** | **16** | **11** | **1** |
