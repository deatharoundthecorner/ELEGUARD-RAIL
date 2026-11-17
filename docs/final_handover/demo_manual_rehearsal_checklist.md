# ELEGUARD RAIL — Controlled Manual Demonstration Rehearsal Checklist

**Document Reference:** `ER-DEMO-MANUAL-001`  
**Creation Timestamp:** 2026-11-14 23:20:00 IST  
**Environment Workspaces:**
- **Source Repository:** `C:\Users\user\Desktop\Railway\eleguard-rail`
- **Web Server Document Root:** `C:\xampp\htdocs\eleguard-rail`
- **Application Base URL:** `http://localhost/eleguard-rail/public/`

---

## 1. Safety Protocols & Environmental Constraints

This rehearsal checklist provides a structured guide for preparing and verifying the 15–20 minute live stakeholder demonstration. All activities are governed by strict operational boundaries:

1. **Zero Database Mutation on Operational Baseline:**
   - Under no circumstances should test submissions, sighting approvals, alert dispatches, or acknowledgements be performed against the baseline `eleguard_rail` database.
   - Sighting record count (**43 rows**) and user account baseline (**5 accounts**) must remain unaltered.
2. **Execution Classification:**
   - **`[READ-ONLY]`**: Safe to execute in the live demonstration environment. Involves browser inspection, UI navigation, reading dashboards, inspecting maps, testing client-side caching, and verifying unauthenticated redirects.
   - **`[REQUIRES DISPOSABLE TEST ENVIRONMENT]`**: Involves state-changing actions (e.g. submitting a new observation, approving a verification, issuing a railway alert, or submitting a driver acknowledgement). These must **NOT** be executed against the operational database and require an isolated disposable test database.
3. **Infrastructure Integrity:**
   - XAMPP Apache (port 80) and XAMPP MariaDB (port 3308) remain active.
   - Coexisting Windows `MySQL80` (port 3306) remains untouched.
   - Zero application source code modifications or Git staging (`git add`).

---

## 2. Test Accounts Reference (No Password Modification)

| Role | Username / Email | Default Demo Password Reference | Purpose & Scope |
| :--- | :--- | :--- | :--- |
| **Control Officer** | `control@eleguard.lk` | *(Refer to internal demo credentials sheet)* | Rail traffic management, hazard map, corridor alerts |
| **Wildlife Officer** | `wildlife@eleguard.lk` | *(Refer to internal demo credentials sheet)* | Sighting verification, biological classification |
| **Locomotive Driver** | `driver@eleguard.lk` | *(Refer to internal demo credentials sheet)* | Stationary HUD advisories, cautionary route warnings |
| **Field Reporter** | `reporter@eleguard.lk` | *(Refer to internal demo credentials sheet)* | PWA field capture, decoupled georeferencing |
| **Administrator** | `admin@eleguard.lk` | *(Refer to internal demo credentials sheet)* | Audit ledger, system-level user management |

---

## 3. Step-by-Step Browser Testing Checklist

### Domain 1: Control Officer Login and GIS Dashboard

#### Test 1.1: Control Officer Authentication & Session Initialization `[READ-ONLY]`
- **Target URL:** `http://localhost/eleguard-rail/public/login.php`
- **Browser Steps:**
  1. Open a clean browser window (or Incognito/Private window).
  2. Enter email `control@eleguard.lk` and the demo password.
  3. Submit the form.
- **Expected Result:**
  - Authenticates successfully without PHP warnings or database errors.
  - Generates secure session cookie (`PHPSESSID`) with `HttpOnly; SameSite=Lax`.
  - Automatically redirects to `http://localhost/eleguard-rail/public/control/dashboard.php`.
- **Evidence to Capture:**
  - Browser Network tab showing `POST /public/login.php` returning `302 Found` with `Location: /eleguard-rail/public/control/dashboard.php`.
  - Screenshot of landing Control Desk.

#### Test 1.2: Control Desk KPI Cards & Operational Summary `[READ-ONLY]`
- **Target URL:** `http://localhost/eleguard-rail/public/control/dashboard.php`
- **Browser Steps:**
  1. Inspect the four metric cards at the top of the dashboard.
  2. Verify stats displayed: Active Danger Alerts, Critical Hazards, Verified Sightings, Pending Public Reports.
- **Expected Result:**
  - Metrics load from existing baseline records.
  - Numbers format cleanly in color-coded cards (`.stat-card`).
- **Evidence to Capture:**
  - DOM selector verification: `.stats-grid .stat-card`.
  - Screenshot: Control desk summary cards.

#### Test 1.3: Interactive GIS Corridor Hazard Map Inspection `[READ-ONLY]`
- **Target URL:** `http://localhost/eleguard-rail/public/control/dashboard.php` (or `/public/control/map.php`)
- **Browser Steps:**
  1. Scroll down to the **Live Railway Corridor Hazard Map** container (`#map` / `.map-container`).
  2. Verify that Leaflet initializes without JavaScript console errors.
  3. Zoom and pan along the Northern Railway line corridor (e.g. Maho to Galgamuwa section).
  4. Inspect the railway corridor geometry overlay loaded from `railway_routes.geojson`.
  5. Check proximity buffer representations:
     - Zone 0 (0–15 m): Immediate Collision Danger Zone (Track bed)
     - Zone 1 (15–100 m): Cautionary Warning Zone
     - Zone 2 (100–1,000 m): Situational Monitoring Buffer
- **Expected Result:**
  - Map tiles load cleanly from OpenStreetMap tile servers (or cached tiles).
  - Railway track polyline is clearly demarcated with emerald/navy styling.
  - Proximity zones render according to verified three-zone specification.
  - Zero console errors in DevTools Console.
- **Evidence to Capture:**
  - Screenshot of full GIS map container showing railway track line and corridor buffers.
  - Network tab confirming `railway_routes.geojson` loaded with `200 OK`.

#### Test 1.4: Railway Alert Creation & Dispatch `[REQUIRES DISPOSABLE TEST ENVIRONMENT]`
- **Target URL:** `http://localhost/eleguard-rail/public/control/create_alert.php`
- **State-Change Warning:** Submitting this form creates a new operational alert record. **DO NOT EXECUTE in operational baseline.**
- **Safe Demonstration Action:** Presenter displays the form interface in read-only mode to demonstrate input validation, section selector, severity levels (Critical, Warning, Advisory), and cautionary speed policy text.

---

### Domain 2: Wildlife Officer Login and Verification Workflow

#### Test 2.1: Wildlife Officer Authentication & Navigation `[READ-ONLY]`
- **Target URL:** `http://localhost/eleguard-rail/public/login.php`
- **Browser Steps:**
  1. Open a separate private browsing session.
  2. Log in as `wildlife@eleguard.lk`.
- **Expected Result:**
  - Authenticates successfully and redirects to `/public/wildlife/reports.php` (or `/public/wildlife/dashboard.php`).
  - Navigation bar displays Wildlife Department operational options.
- **Evidence to Capture:**
  - Screenshot of Wildlife Verification Workspace.

#### Test 2.2: Verification Workspace Tabs & Sighting Inspection `[READ-ONLY]`
- **Target URL:** `http://localhost/eleguard-rail/public/wildlife/reports.php`
- **Browser Steps:**
  1. Inspect the status filter tabs: `Pending`, `Needs Information`, `Clarification Submitted`, `Verified`, `Rejected`.
  2. Click into an existing baseline report to review the detailed biological observation record.
  3. Verify observation fields:
     - Demographics: Herd composition (Adult males, females, calves, solitary bulls/tuskers).
     - Behavior & Evidence: Dung, footprints, direct visual, feeding damage.
     - Decoupled Geolocation: Observer coordinates vs. Animal coordinates.
     - Geodesic spatial uncertainty buffer ($\pm X\text{ m}$).
     - Railway chainage and corridor section association.
- **Expected Result:**
  - All demographic and spatial fields display accurately without data truncations.
  - Spatial decoupling is prominently presented: observer location is visually separated from projected animal location.
- **Evidence to Capture:**
  - DOM selector: `.verification-review-card`, `.demographics-summary-grid`.
  - Screenshot of detailed sighting inspection card.

#### Test 2.3: Verification Action Execution `[REQUIRES DISPOSABLE TEST ENVIRONMENT]`
- **Target URL:** `http://localhost/eleguard-rail/public/wildlife/reports.php`
- **State-Change Warning:** Clicking **"Verify Sighting"**, **"Request Information"**, or **"Reject"** executes an `UPDATE` on `elephant_reports` and inserts audit entries into `verification_audits`. **DO NOT EXECUTE in operational baseline.**
- **Safe Demonstration Action:** Show the decision panel in read-only view: highlight the 4 verification methods (Direct Field Ranger Sighting, Drone Reconnaissance, Community Callout, Acoustic Sensor), justification text area, and anti-self-verification safeguard.

---

### Domain 3: Driver Login and Stationary Advisory HUD

#### Test 3.1: Locomotive Driver Authentication & Landing `[READ-ONLY]`
- **Target URL:** `http://localhost/eleguard-rail/public/login.php`
- **Browser Steps:**
  1. In a private window, authenticate as `driver@eleguard.lk`.
- **Expected Result:**
  - Redirects immediately to `http://localhost/eleguard-rail/public/driver/alerts.php` (or `/public/driver/dashboard.php`).
- **Evidence to Capture:**
  - Screenshot of Driver Advisory Overview.

#### Test 3.2: Mandatory Stationary Safety Regulation Banner `[READ-ONLY]`
- **Target URL:** `http://localhost/eleguard-rail/public/driver/alerts.php`
- **Browser Steps:**
  1. Inspect the top banner of the driver interface.
  2. Verify the prominent regulatory alert banner.
- **Expected Result:**
  - Banner renders with high-contrast warning styling (`.driver-safety-banner`):
    `⚠️ STATIONARY REVIEW USE ONLY — DO NOT INTERACT WHILE IN MOTION`
  - Explicit instruction text states that advisory archives and route warnings must be reviewed exclusively while stationary at a scheduled stop, siding, or stop signal.
- **Evidence to Capture:**
  - DOM selector: `.driver-safety-banner`, `.driver-safety-banner-header`.
  - High-resolution screenshot of the safety regulation banner.

#### Test 3.3: Route Section Advisory Filtering `[READ-ONLY]`
- **Target URL:** `http://localhost/eleguard-rail/public/driver/alerts.php`
- **Browser Steps:**
  1. Test the railway section dropdown selector (`select[name="section_id"]`).
  2. Select an active corridor section (e.g. `Maho - Galgamuwa Section`).
  3. Verify that the page reloads via HTTP GET and filters active advisories.
- **Expected Result:**
  - Filter operates strictly via query parameters (`?section_id=...`) without issuing database writes.
  - Active warning cards display corridor chainage, hazard severity (Critical, Warning, Advisory), and Caution Order precedence reminder.
- **Evidence to Capture:**
  - Screenshot showing filtered advisory card list.
  - Network tab confirming clean GET request.

#### Test 3.4: Driver Alert Acknowledgement `[REQUIRES DISPOSABLE TEST ENVIRONMENT]`
- **Target URL:** `http://localhost/eleguard-rail/public/driver/alert_details.php`
- **State-Change Warning:** Submitting an acknowledgement records a receipt in `driver_acknowledgements`. **DO NOT EXECUTE in operational baseline.**
- **Safe Demonstration Action:** Review the stationary acknowledgement interface: show that acknowledgement buttons are locked out or restricted during movement simulation and that official Caution Orders strictly govern train braking.

---

### Domain 4: Field Reporter PWA and Offline Behavior

#### Test 4.1: PWA Shell & Service Worker Registration `[READ-ONLY]`
- **Target URL:** `http://localhost/eleguard-rail/public/field/index.php`
- **Browser Steps:**
  1. Open Chrome/Edge DevTools (F12) to the **Application** tab.
  2. Navigate to `http://localhost/eleguard-rail/public/field/index.php` (logged in as `reporter@eleguard.lk`).
  3. Under **Application $\to$ Service Workers**, verify status of `sw.js`.
  4. Under **Application $\to$ Manifest**, verify manifest details (`name: "EleGuard Rail - Field Reporter"`, theme colors, icons).
- **Expected Result:**
  - Service worker `sw.js` is registered, active, and running.
  - Manifest is parsed with zero errors.
- **Evidence to Capture:**
  - DevTools Application tab screenshot showing active Service Worker.
  - Manifest properties screenshot.

#### Test 4.2: Guided 6-Step Field Capture UI Walkthrough `[READ-ONLY]`
- **Target URL:** `http://localhost/eleguard-rail/public/field/index.php`
- **Browser Steps:**
  1. Step 1 (Metadata): Select date/time, report type, observation context.
  2. Step 2 (Decoupled Geolocation): Inspect the observer coordinate inputs, standoff distance slider (50–150 m), and compass bearing dial.
  3. Step 3 (Demographics): Inspect herd counter controls (adult males, females, juveniles, calves).
  4. Step 4 (Ecological Behavior): Inspect gapless diurnal classification (Dusk, Dawn, Day, Night).
  5. Step 5 (Photo Upload): Inspect client-side image compression and camera preview interface.
  6. **STOP at Step 6 (Review)**: Do NOT click Submit Observation.
- **Expected Result:**
  - Guided step wizard navigates smoothly without page reloads.
  - Client-side validation enforces valid geographic ranges and positive integer counts.
- **Evidence to Capture:**
  - Screenshot of Step 2 (Decoupled Geolocation inputs).
  - Screenshot of Step 6 (Pre-submission Review summary).

#### Test 4.3: Simulated Offline Mode & Fallback Verification `[READ-ONLY]`
- **Target URL:** `http://localhost/eleguard-rail/public/offline.html`
- **Browser Steps:**
  1. In DevTools, open the **Network** tab.
  2. Set Throttling dropdown to **"Offline"**.
  3. Attempt to reload `http://localhost/eleguard-rail/public/field/index.php` or navigate to `/public/offline.html`.
  4. Inspect the rendered response.
  5. Restore Network Throttling to **"No Throttling"** immediately after verification.
- **Expected Result:**
  - Browser does not show standard browser network failure ("No Internet" dinosaur).
  - PWA service worker serves the cached offline fallback shell (`offline.html`) explaining that connection is offline and local drafts are securely encrypted in IndexedDB.
- **Evidence to Capture:**
  - Screenshot of offline shell with DevTools Network tab showing "Offline" preset active.

#### Test 4.4: Field Report Background Synchronization `[REQUIRES DISPOSABLE TEST ENVIRONMENT]`
- **Target URL:** `http://localhost/eleguard-rail/public/api/v2/reports/sync_batch.php`
- **State-Change Warning:** Submitting batched offline drafts performs batch insertion into `elephant_reports`. **DO NOT EXECUTE in operational baseline.**
- **Safe Demonstration Action:** Explain the client encryption architecture: local IndexedDB drafts are secured with AES-GCM; synchronization payload includes RFC 4122 v4 UUID for backend idempotency deduplication.

---

### Domain 5: Role-Based Access Restrictions (RBAC)

#### Test 5.1: Unauthenticated Endpoint Redirection `[READ-ONLY]`
- **Target URLs:**
  - `http://localhost/eleguard-rail/public/control/dashboard.php`
  - `http://localhost/eleguard-rail/public/wildlife/reports.php`
  - `http://localhost/eleguard-rail/public/driver/alerts.php`
  - `http://localhost/eleguard-rail/public/admin/dashboard.php`
- **Browser Steps:**
  1. Open an Incognito/Private window with no active session cookies.
  2. Attempt direct navigation to each URL above.
- **Expected Result:**
  - Every protected page immediately redirects to `/public/login.php`.
  - Flash session message informs: *"You must be logged in to access the dashboard."*
- **Evidence to Capture:**
  - Network tab showing `302 Found` with `Location: /eleguard-rail/public/login.php`.
  - Screenshot of login page displaying the flash notification banner.

#### Test 5.2: Cross-Role Access Violation Denial `[READ-ONLY]`
- **Test Matrix:**
  1. Log in as **Driver** (`driver@eleguard.lk`) $\to$ Attempt to navigate to `public/admin/dashboard.php`.
  2. Log in as **Field Reporter** (`reporter@eleguard.lk`) $\to$ Attempt to navigate to `public/control/dashboard.php`.
  3. Log in as **Control Officer** (`control@eleguard.lk`) $\to$ Attempt to navigate to `public/wildlife/reports.php`.
- **Expected Result:**
  - Role-middleware (`app/middleware/role_required.php`) intercepts the unauthorized request.
  - Access is denied cleanly: user is either redirected back to their authorized role dashboard with an unauthorized flash warning or presented with an HTTP 403 Forbidden screen.
  - No system exceptions, fatal PHP errors, or stack traces exposed.
- **Evidence to Capture:**
  - Screenshot of redirect/denial banner showing *"Unauthorized: You do not have permission to view that section."*

---

## 4. Evidence Capture Summary Table

| Test ID | Test Item | Classification | Primary Evidence | Fallback Artifact Reference |
| :--- | :--- | :---: | :--- | :--- |
| **1.1** | Control Officer Login | `[READ-ONLY]` | Network 302 + Session Cookie | `06_control_dashboard.png` |
| **1.2** | Control Desk KPI Cards | `[READ-ONLY]` | DOM `.stat-card` + Screenshot | `06_control_dashboard.png` |
| **1.3** | GIS Corridor Hazard Map | `[READ-ONLY]` | Leaflet canvas + GeoJSON 200 OK | Verified GIS dashboard screenshot |
| **1.4** | Alert Creation | `[REQUIRES DISPOSABLE]` | Form UI inspection only | Pre-staged alert evidence |
| **2.1** | Wildlife Officer Login | `[READ-ONLY]` | Sighting workspace screenshot | `wildlife_report_review.png` |
| **2.2** | Demographics & Decoupling | `[READ-ONLY]` | Decoupled coordinates card | `wildlife_report_review.png` |
| **2.3** | Sighting Verification Action | `[REQUIRES DISPOSABLE]` | Decision panel UI only | `tests/test_milestone_9c_stage4e4_3d_verification.php` |
| **3.1** | Driver Login | `[READ-ONLY]` | Driver HUD screenshot | `01_driver_dashboard.png` |
| **3.2** | Stationary Safety Banner | `[READ-ONLY]` | `.driver-safety-banner` screenshot | `11_driver_alerts_stationary.png` |
| **3.3** | Section Route Filtering | `[READ-ONLY]` | Network GET + Filtered cards | `05_driver_history.png` |
| **3.4** | Driver Acknowledgement | `[REQUIRES DISPOSABLE]` | Stationary modal UI only | `03_ack_submitted.png` |
| **4.1** | PWA Service Worker | `[READ-ONLY]` | DevTools SW active + Manifest 200 | `sw.js` HTTP 200 header |
| **4.2** | 6-Step Field Wizard | `[READ-ONLY]` | Step 2 & Step 6 screenshots | Pre-staged PWA screenshots |
| **4.3** | Offline Mode Simulation | `[READ-ONLY]` | `offline.html` cached rendering | Pre-staged offline screenshot |
| **4.4** | Offline Sync Batch | `[REQUIRES DISPOSABLE]` | Architecture defense only | `tests/test_milestone_9c_stage4e4_2d_sync.php` |
| **5.1** | Unauthenticated Redirect | `[READ-ONLY]` | Network 302 Location header | DevTools Network trace |
| **5.2** | Cross-Role Access Denial | `[READ-ONLY]` | Flash denial message / 403 page | `09_rbac_denial.png` |

---

## 5. Potential Blockers & Pre-Demonstration Mitigations

1. **Tile Server Connectivity:**
   - *Risk:* If the demonstration laptop loses external Internet connectivity, live OpenStreetMap tiles may fail to render.
   - *Mitigation:* Ensure browser has cached map tiles during pre-rehearsal or rely on local GeoJSON corridor overlay rendering.
2. **Session Persistence Collision:**
   - *Risk:* Demonstrating multiple roles in a single browser window can overwrite `PHPSESSID` session cookies.
   - *Mitigation:* Use separate browser profiles or dedicated Private/Incognito windows for Control Officer, Wildlife Officer, and Driver roles.
3. **Accidental Operational State Mutation:**
   - *Risk:* Presenter inadvertently clicking "Submit" on a live form alters baseline records.
   - *Mitigation:* Adhere strictly to the step-by-step checklist; halt all data-entry forms at Step 5/Review stage.
