# ELEGUARD RAIL — LIVE STAKEHOLDER DEMONSTRATION SCRIPT
## Structured 15–20 Minute Walkthrough Guide & Technical Defense

**Project:** ELEGUARD RAIL (Sri Lanka Wildlife–Railway Collision Prevention Platform)  
**Document Reference:** `docs/final_handover/live_demo_script.md`  
**Change Reference:** `ER-FINAL-HANDOVER-DEMO-002`  
**Target Audience:** DWC Wildlife Directors, SLR Train Controllers, Academic Reviewers  
**Target Duration:** 15–20 Minutes (including transitional buffers)  
**Operational Environment:** Local RC 1.0 Instance (`http://localhost/eleguard-rail/`)  

---

### 1. Master Demonstration Timeline

```
[00:00 - 02:00]  ACT 1: Executive Context, Roles & Authentication
[02:00 - 04:30]  ACT 2: Wildlife Field Sighting & Decoupled Georeferencing
[04:30 - 07:00]  ACT 3: GPS Geodesics & Propagated Spatial Uncertainty
[07:00 - 09:30]  ACT 4: Offline PWA, Client Encryption & Sync Controller
[09:30 - 12:00]  ACT 5: Wildlife Officer Verification & Clarification Desk
[12:00 - 14:30]  ACT 6: Railway Control Console & Corridor GIS Zoning
[14:30 - 17:00]  ACT 7: Locomotive Driver HUD & Stationary Safety Policy
[17:00 - 18:30]  ACT 8: Zero-Trust Security, Audit Logging & Test Evidence
[18:30 - 20:00]  ACT 9: Limitations, Governance Roadmap & Stakeholder Q&A
```

---

### 2. Detailed Step-by-Step Walkthrough

---

#### ACT 1: Executive Context, Roles & Authentication [00:00 – 02:00]
- **Target Duration:** 2 minutes.
- **Navigation:** `http://localhost/eleguard-rail/public/index.php`
- **What the Presenter Should Do:**
  1. Open Chrome/Edge to the landing login page.
  2. Explain the five distinct user roles required by DWC and SLR operating procedures.
  3. Enter credentials for the Railway Control Officer (`control@eleguard.lk`) and log in.
  4. In a second private window or tab, attempt to access an unauthorized admin URL (`public/admin/dashboard.php`) as a non-admin to show immediate RBAC enforcement.
- **What the Audience Should See:**
  - Modern, responsive login portal displaying the deep navy and emerald institutional branding.
  - Smooth login redirecting to the Control Officer operational console.
  - A clean, standardized HTTP 403 Forbidden denial screen when unauthorized access is attempted.
- **What the Presenter Should Explain:**
  - *"ELEGUARD RAIL connects two vital state agencies: wildlife rangers in remote forests and railway traffic dispatchers in operational centers."*
  - *"Security begins at the boundary: five segregated roles guarantee that drivers cannot verify sightings, reporters cannot modify railway tracking, and control officers cannot alter wildlife biology records."*
- **Expected Result:** Login succeeds immediately, session cookie is set with `HttpOnly` and `SameSite=Lax`, and RBAC middleware enforces strict role segregation.
- **Possible Failure & Fallback:**
  - *Failure:* Database connection error or local web server offline.
  - *Fallback:* Display verified browser artifact [`09_rbac_denial.png`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/docs/phase9/browser_evidence/09_rbac_denial.png) to demonstrate the verified 403 denial mechanism.

---

#### ACT 2: Wildlife Field Sighting Capture [02:00 – 04:30]
- **Target Duration:** 2 minutes 30 seconds.
- **Navigation:** Log in as Wildlife Reporter (`reporter@eleguard.lk`) $\to$ `public/wildlife/submit_report.php`
- **What the Presenter Should Do:**
  1. Display the field observation form.
  2. Input observer coordinates: Latitude `8.03450000`, Longitude `80.28500000`, GPS Accuracy `7.5m`.
  3. Enter the visual offset measurements: Standoff distance `80m`, Compass bearing `45°` (North-East).
  4. Enter herd demographics: 1 adult male, 3 adult females, 1 calf (total count: 5).
  5. Select nearest railway milestone: `Maho - Galgamuwa Section (Milestone 82)`.
  6. Click **Submit Observation Report**.
- **What the Audience Should See:**
  - Clean field capture workspace with distinct sections for observer location, target offset, and wildlife demographics.
  - Success banner displaying the generated record ID and structured JSON response envelope.
- **What the Presenter Should Explain:**
  - *"In dense jungle terrain, rangers cannot approach wild elephant herds without severe personal risk. Field rangers maintain a documented safety standoff—typically 50 to 150 metres away."*
  - *"If software simply copies the ranger's smartphone GPS, the animal's position is completely distorted relative to the railway track. ELEGUARD RAIL decouples observer location from animal location at the point of capture."*
- **Expected Result:** Payload validates cleanly; backend returns HTTP 201 Created with decoupled observer and projected animal coordinates.
- **Possible Failure & Fallback:**
  - *Failure:* Validation failure due to out-of-range coordinates.
  - *Fallback:* Present verified browser artifact [`wildlife_report_review.png`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/verification_evidence/wildlife_report_review.png) illustrating the verified decoupled fields.

---

#### ACT 3: GPS Geodesics & Propagated Spatial Uncertainty [04:30 – 07:00]
- **Target Duration:** 2 minutes 30 seconds.
- **Navigation:** Inspect the submitted report response $\to$ `public/wildlife/reports.php`
- **What the Presenter Should Do:**
  1. Open the report details view.
  2. Highlight the mathematical fields computed by the backend Geodesic Engine:
     - Observer Coordinates: `8.03450000, 80.28500000`
     - Estimated Animal Coordinates: `8.03501000, 80.28551200`
     - Animal Location Uncertainty: `±22.8m`
  3. Demonstrate the Stage J.6 safety correction: Show that when a device reports invalid or null accuracy, the uncertainty circle is suppressed.
- **What the Audience Should See:**
  - The map interface plotting both the observer pin and the projected animal pin with its spatial uncertainty confidence boundary.
  - Clear labeling indicating that the coordinate is an estimated projection, not an empirical co-location.
- **What the Presenter Should Explain:**
  - *"Our Geodesic Engine solves forward Great-Circle spherical trigonometry on the WGS-84 Earth sphere ($R = 6,371\text{ km}$), computing exact projected coordinates."*
  - *"Crucially, we propagate spatial error via Root-Sum-Square (RSS) math: combining device GPS error, optical distance variance (20%), and compass variance (10°)."*
  - *"In compliance with our Stage J.6 safety audit, if a mobile phone fails to report GPS accuracy, our system strictly leaves uncertainty as unknown (`NULL`). We prohibit inventing an assumed 10-metre precision."*
- **Expected Result:** True geodesic coordinates and isotropic confidence buffers are rendered without planar distortion.
- **Possible Failure & Fallback:**
  - *Failure:* Leaflet map rendering delay or JavaScript geometry error.
  - *Fallback:* Review automated test results from `tests/test_milestone_9c_stage_j_gps_validation.php` (14/14 passed) demonstrating mathematical proof.

---

#### ACT 4: Offline PWA, Client Encryption & Synchronization [07:00 – 09:30]
- **Target Duration:** 2 minutes 30 seconds.
- **Navigation:** Open mobile view / PWA client $\to$ `public/field/index.html`
- **What the Presenter Should Do:**
  1. Open Developer Tools (F12) $\to$ Network tab $\to$ Select **Offline**.
  2. Reload the page to prove that the Service Worker loads the complete application shell without an internet connection.
  3. Fill out an offline sighting (herd of 3 elephants near Galgamuwa).
  4. Click **Save Observation (Offline Mode)**.
  5. Navigate to Developer Tools $\to$ Application tab $\to$ IndexedDB $\to$ Inspect the stored record ciphertext.
  6. Switch Network back to **Online** and trigger **Synchronize Outbox**.
- **What the Audience Should See:**
  - The PWA boots instantly with an "Offline Active" visual status badge.
  - Observation data saved into IndexedDB is visibly stored as an AES-GCM 256-bit encrypted blob (ciphertext + 96-bit IV).
  - Upon reconnection, the sync controller securely transmits the batch and updates the local status to "Synced".
- **What the Presenter Should Explain:**
  - *"Wildlife reserves lack cellular reception. Rangers work in radio dead-zones for days. ELEGUARD RAIL functions offline-first."*
  - *"To prevent ivory poachers from extracting elephant locations if a phone is lost or inspected, drafts are encrypted at rest using AES-GCM 256-bit encryption derived via PBKDF2."*
  - *"Threat Model Realism: We explain that while browser encryption protects against casual extraction, full device theft protection requires mandatory OS-level full-disk encryption and screen PINs."*
  - *"Synchronization uses RFC 4122 v4 UUID idempotency and SHA-256 payload hashing, ensuring network retries never create duplicate records."*
- **Expected Result:** IndexedDB draft persists encrypted; batch sync executes cleanly with HTTP 201 Created or HTTP 200 Replay.
- **Possible Failure & Fallback:**
  - *Failure:* Browser security context blocks Service Worker (if not running on `localhost` or HTTPS).
  - *Fallback:* Demonstrate the automated PWA test suite `tests/test_milestone_9c_stage4e4_2a_pwa.php` and `test_milestone_9c_stage4e4_2c_encrypted_storage.php`.

---

#### ACT 5: Wildlife Officer Verification & Clarification Desk [09:30 – 12:00]
- **Target Duration:** 2 minutes 30 seconds.
- **Navigation:** Switch browser tab $\to$ Log in as Wildlife Officer (`wildlife@eleguard.lk`) $\to$ `public/wildlife/reports.php`
- **What the Presenter Should Do:**
  1. Display the incoming reports queue.
  2. Show that newly ingested sightings enter with status `Pending`.
  3. Select a report and click **Inspect & Review Evidence**.
  4. Show the two-way clarification feature: Officer submits a clarification request (e.g., *"Confirm herd movement direction relative to track"*).
  5. Select **Verify Report** and input verification method (`remote_telemetry_corroborated`).
- **What the Audience Should See:**
  - The status filter tabs: `Pending`, `Needs Info`, `Clarification Submitted`, `Verified`, `Rejected`.
  - The multi-point evidence review panel displaying photographic evidence, georeferenced coordinates, and proximity zones.
  - The immutable verification ledger recording the officer's ID, method, and timestamp.
- **What the Presenter Should Explain:**
  - *"A fundamental architectural principle of ELEGUARD RAIL is that automated AI algorithms and unverified field reports NEVER trigger operational railway alerts directly."*
  - *"Every observation must pass through human governance. Only authorized DWC Wildlife Officers can verify sightings."*
  - *"An anti-self-verification guard prevents officers from approving reports they personally filed, enforcing four-eyes segregation of duties."*
- **Expected Result:** Report transitions cleanly to `Verified` status and is logged in the verification audit ledger.
- **Possible Failure & Fallback:**
  - *Failure:* Database table missing extended verification audit columns (if migrations 04–06 are unapplied).
  - *Fallback:* Present verified browser artifact [`wildlife_reports_list.png`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/verification_evidence/wildlife_reports_list.png) demonstrating the verified tabbed workflow.

---

#### ACT 6: Railway Control Console & Corridor GIS Zoning [12:00 – 14:30]
- **Target Duration:** 2 minutes 30 seconds.
- **Navigation:** Switch tab $\to$ Control Officer Console $\to$ `public/control/dashboard.php`
- **What the Presenter Should Do:**
  1. Open the interactive Leaflet GIS dashboard.
  2. Point out the railway line alignments (Northern and Batticaloa lines) and historical collision hotspots (Maho, Galgamuwa, Habarana).
  3. Review the three documented proximity zones:
     - **Zone 0 (0–15m):** Track Clearance Hazard (red boundary).
     - **Zone 1 (15–100m):** Near-Corridor Alert (amber boundary).
     - **Zone 2 (100–1,000m):** Buffer Habitat Watch (yellow boundary).
  4. Point to the OpenStreetMap planar disclaimer banner at the bottom of the map.
- **What the Audience Should See:**
  - Fully styled Leaflet map with dark railway tiles, track centerlines, active verified elephant pins, and circular uncertainty buffers.
  - Prominent disclaimer banner: *"OpenStreetMap Planar Uncertainty: Track vectors carry an estimated 10–30m planar margin. Non-engineering cadastre."*
- **What the Presenter Should Explain:**
  - *"The Control Console provides dispatchers with instant situational awareness along critical sectors."*
  - *"We classify proximity into three clear zones: Zone 0 indicates immediate track clearance danger; Zone 1 alerts the crew to corridor proximity; Zone 2 monitors habitat movement."*
  - *"In keeping with scientific integrity, we explicitly disclaim OSM geometry: open cartography is suitable for corridor zoning, but is never claimed as a millimeter-accurate railway engineering survey."*
- **Expected Result:** Map renders smoothly with correct layer controls, pins, and buffer zones.
- **Possible Failure & Fallback:**
  - *Failure:* OpenStreetMap tile loading fails due to offline local environment.
  - *Fallback:* Display genuine high-resolution screenshot [`06_control_dashboard.png`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/docs/phase9/browser_evidence/06_control_dashboard.png).

---

#### ACT 7: Locomotive Driver Advisory HUD & Safety Policy [14:30 – 17:00]
- **Target Duration:** 2 minutes 30 seconds.
- **Navigation:** Switch tab $\to$ Log in as Locomotive Driver (`driver@eleguard.lk`) $\to$ `public/driver/alerts.php`
- **What the Presenter Should Do:**
  1. Display the Locomotive Driver HUD.
  2. Highlight the dark-mode high-contrast color scheme.
  3. Direct audience attention to the prominent safety warning banner at the top of the interface:
     > **`STATIONARY REVIEW USE ONLY — DO NOT INTERACT WHILE IN MOTION`**  
     > *Confirm receipt and submit remarks ONLY when train is stationary at a station, siding, or stop signal.*
  4. Demonstrate acknowledging a stationary advisory and submitting remarks.
- **What the Audience Should See:**
  - Glare-free dark interface optimized for night operations, showing active corridor hazard levels (Zone 0 Track Hazard, Zone 1 Near-Corridor Alert).
  - Clear, prominent stationary-review warning banner.
  - Simple, passive card presentation without demanding interactive touch inputs.
- **What the Presenter Should Explain:**
  - *"Operating a 400-ton passenger train at night demands intense vigilance. In-cab technology must NEVER distract the driver."*
  - *"Our Driver HUD is strictly passive: it presents verified hazard proximity without issuing unauthoritative numerical speed orders or braking commands."*
  - *"Regulatory Primacy: Operating speeds, braking actions, and train movements remain under the sole legal authority of official Sri Lanka Railways General Rules, physical trackside signals, and written Caution Orders issued by Station Masters."*
  - *"Stationary Safety Discipline: Drivers review advisories during pre-departure briefings or while halted at scheduled station stops. The system does not interface with locomotive speedometers; stationary discipline is enforced operationally."*
- **Expected Result:** Stationary acknowledgement records successfully with driver ID and timestamp.
- **Possible Failure & Fallback:**
  - *Failure:* Network latency or session timeout.
  - *Fallback:* Display verified browser artifact [`11_driver_alerts_stationary.png`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/docs/phase9/browser_evidence/11_driver_alerts_stationary.png).

---

#### ACT 8: Zero-Trust Security, Audit Logging & Test Evidence [17:00 – 18:30]
- **Target Duration:** 1 minute 30 seconds.
- **Navigation:** Switch tab $\to$ Log in as Admin (`admin@eleguard.lk`) $\to$ `public/admin/dashboard.php`
- **What the Presenter Should Do:**
  1. Open the System Activity Log and show forensic audit entries (`system_logs`).
  2. Point out database privilege separation: Show that analytics dashboards connect as `eleguard_readonly` (strict SELECT-only).
  3. Briefly display the automated regression test output console: `tests/run_all_tests.php`.
- **What the Audience Should See:**
  - Complete forensic audit trail of all logins, sighting submissions, verifications, and driver acknowledgements.
  - Automated test runner output: **35 / 35 test suites passed cleanly in 34.84s (Exit Code 0)** across >1,200 assertions.
- **What the Presenter Should Explain:**
  - *"During Stage J hardening, we eliminated SQL injection across 100% of dynamic queries using PDO prepared statements."*
  - *"Database privilege separation prevents lateral movement: analytics accounts cannot write or delete records at the database engine level."*
  - *"Every technical claim is backed by automated tests running across 35 independent suites."*
- **Expected Result:** System logs display chronological audit trail; test runner outputs 100% pass rate.
- **Possible Failure & Fallback:**
  - *Failure:* Test runner takes too long to execute during live demo.
  - *Fallback:* Display pre-executed test logs documented in [`testing_and_validation_evidence.md`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/docs/final_handover/testing_and_validation_evidence.md).

---

#### ACT 9: Limitations, Governance Roadmap & Stakeholder Q&A [18:30 – 20:00]
- **Target Duration:** 1 minute 30 seconds.
- **Navigation:** Return to presentation slides (Slide 14 and Slide 15).
- **What the Presenter Should Explain:**
  - *"We conclude with complete transparency regarding our boundaries:"*
    1. *Production Deployment is NOT AUTHORIZED; this is a verified Release Candidate 1.0 pre-production build.*
    2. *Live in-service railway field trials on operational locomotives have NOT been conducted.*
    3. *Inter-agency administrative approvals from DWC and SLR remain pending.*
    4. *No formal railway safety engineering certification (CENELEC EN 50126/50128/50129) is claimed.*
  - *"Proposed Conceptual Roadmap: We propose an inter-agency technical evaluation, controlled staging deployment with TLS 1.3, and stationary locomotive simulations at Maho Junction, strictly subject to formal joint authorization."*
  - *"Thank you. We now welcome questions and technical discussion from the panel."*
- **Expected Result:** Clear, professional transition to structured technical Q&A defense using `ELEGUARD_RAIL_QA_Preparation.md`.
