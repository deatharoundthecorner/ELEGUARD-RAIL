# ELEGUARD RAIL — PHASE B PROTOTYPE FINAL REFINEMENT REPORT

**Document Reference:** `ER-UI-PHASE-B-FINAL-REFINEMENT-REPORT-001`  
**Parent Directive:** `ER-UI-PHASE-B-FINAL-REFINEMENT-001`  
**Parent Validation:** `ER-UI-PHASE-B-VALIDATION-001`  
**Access URL:** `http://localhost/eleguard-rail/public/design-previews/index.html`  
**Date:** 16 November 2026  
**Status:** **COMPLETED & VERIFIED**

---

## 1. Executive Summary

In accordance with directive `ER-UI-PHASE-B-FINAL-REFINEMENT-001`, all six Phase B static HTML design prototypes and the interactive gallery index have undergone comprehensive visual and ergonomic refinement. 

All modifications were **strictly restricted to static HTML design mockups**:
- **Application Backend & Logic:** Zero changes to PHP files, JavaScript application logic, or API endpoints.
- **Databases:** Zero modifications to `eleguard_rail_demo`, `eleguard_rail_test`, or `eleguard_rail`.
- **Git State:** Zero commits, branches, resets, or staging operations performed.
- **Security:** Root `.htaccess` protections for `docs/`, `app/`, `database/`, and `tests/` preserved intact; public previews isolated under `public/design-previews/` with script execution denied.

---

## 2. Refinements Implemented by Role Interface

### Task 1 — Admin Console (`01_admin_dashboard_preview.html`)
1. **Misleading Operational Speed Instructions Removed:**
   - Table title updated from *"Railway Corridor Speed Restrictions & Wildlife Hazards"* to *"Railway Corridor Advisory Speed Cautions & Wildlife Hazards (Demonstration)"*.
   - Table column header changed from *"Speed Restriction"* to *"Advisory Speed Caution"*.
   - Speed values updated to explicit advisory cautions with statutory precedence noted: `20 km/h Advisory Caution (T.409 Precedence)` and `35 km/h Daytime Advisory Caution`.
   - Normal speed entries updated to `Track Speed Caution Cleared`.
   - Statutory notice clarified: *"Speed guidance is advisory decision-support only. Official Sri Lanka Railways Caution Orders (Form T.409/T.410) issued by Train Controllers govern operational locomotive speeds. Automated dashboard indications never supersede official operating circulars."*
2. **Audit Trail Correction:**
   - Audit trail entry formerly reading `Train 6011 (Slowing down to 20 km/h)` corrected to strictly receipt-only semantics: `Train 6011 (Briefing Noted — Standard Vigilance)`.
   - Audit table header labeled: `System Activity & Immutable Audit Trail (Illustrative Demonstration Records)`.
3. **Distinction of Illustrative vs Actual Data:**
   - KPI tiles tagged with `[SAMPLE]` telemetry pills.
   - Header breadcrumb updated to `Infrastructure Overview (Illustrative Showcase)`.
   - Top banner clearly marked `ILLUSTRATIVE SAMPLE VALUES ONLY • STATUS: READ-ONLY TECHNICAL CONSOLE • NO LIVE DISPATCH`.
4. **Readability Improvements:**
   - Table headers increased from 10px to 11px with enhanced contrast (`#94a3b8`).
   - Table cells increased from 12px to 12.5px.
   - KPI subtext and navigation labels increased from 10px to 11px with improved contrast ratios.

---

### Task 2 — Control Desk (`02_control_desk_preview.html`)
1. **Demonstration-Only Controls Labeled:**
   - Emergency dispatch button clearly tagged: `Broadcast Urgent Train Advisory [DEMO CONTROL]`. Interactive click callback explains that dispatch is simulated and requires verified sighting intake and Train Controller sign-off.
   - Triage action buttons labeled `Authorise Dispatch (Demo)` with prototype alert notices.
2. **Operational Workflow Stages Distinguished:**
   - Left panel clearly designated as: `Stage 1 & 2: Verified Sightings Intake & Advisory Preparation`.
   - Right panel clearly designated as: `Stage 3: Locomotive Driver Cab Receipt Telemetry`.
3. **No Automatic Train Tracking Implication:**
   - Header telemetry text updated from `AUTOMATIC SENSING REFRESH: 15s` to `REPORTED OCCUPANCY REFRESH: 30s (SCHEDULED & STATION-REPORTED OCCUPANCY)`.
   - Corridor strip title updated to: `Northern & Batticaloa Line • Active Corridor Occupancy & Wildlife Advisory Status`.
   - Added explicit occupancy disclaimer: *"Corridor occupancy is based on station-reported block clearances and train timetables; continuous GPS train tracking is not operational on this line."*
4. **Railway Controller's Statutory Authority Preserved:**
   - Footnote reinforces: *"Train Controllers hold sole operational authority under Sri Lanka Railways Operating Rules. Advisory speed cautions are situational decision-support tools and do not replace Caution Orders (Form T.409/T.410)."*
5. **Readability:**
   - Table headers upgraded to 11px `#94a3b8`, cells upgraded to 12.5px.

---

### Task 3 — Wildlife Verification Workspace (`03_wildlife_verification_preview.html`)
1. **AI Confidence Thresholds Backend Verification:**
   - **Backend Source Verified:** Inspected `app/services/AiDetectionService.php` (lines 28–34) and `ai/detector/cli.py` (lines 50–59):
     - `DEFAULT_CONF_THRESHOLD = 0.40;` (≥40% is Confirmed Positive)
     - `DEFAULT_UNCERTAIN_THRESHOLD = 0.20;` (<20% is Negative; 20%–40% is Uncertain)
     - Process timeout: `5.0` seconds
     - Primary model: `YOLOX-Tiny ONNX`
   - Telemetry box updated: `Backend Engine: YOLOX-Tiny ONNX (AiDetectionService.php) • Thresholds Verified: Confirmed ≥40%, Uncertain 20–40%, Negative <20%`.
   - The 89% confidence value and 3 elephants count are explicitly labeled `(Illustrative Sample Confidence: 89%)`.
   - Explicit advisory notice: *"YOLOX-Tiny ONNX inference is strictly automated non-blocking decision support. The AI subsystem cannot verify reports or trigger train cautions. Ecological verification requires official human Wildlife Ranger sign-off."*
2. **Synthetic Data Labeling:**
   - Sighting header: `Sighting #88 [Synthetic Record]: Habarana – Gal Oya Forest Reserve`.
   - Observer location labeled: `8.04125000, 80.82564000 [Synthetic Fix]`.
   - Estimated standoff distance labeled: `120 meters [Synthetic Standoff Estimate]`.
   - Observer bearing labeled: `045° North-East [Synthetic]`.
   - Calculated animal fix labeled: `8.04198000, 80.82638000 [Synthetic Geodesic]`.
   - Distance to centerline labeled: `18 meters [Inside Buffer • Synthetic]`.
3. **Role Boundary Distinction:**
   - Button preserved as `Verify Sighting Observation` (no train advisory dispatch powers).
   - Footnote explicitly states: *"Wildlife verification certifies ecological observation validity. Railway caution orders and train advisory broadcasts are executed exclusively by Sri Lanka Railways Train Controllers at the Control Desk."*
4. **Forest Green Identity & Ergonomics:**
   - DWC forest green theme preserved; readability of technical tags and labels enhanced.

---

### Task 4 — Locomotive Driver Cab Desk (`04_driver_dashboard_preview.html`)
1. **Misleading Speed Audits Removed:**
   - Residual CSS class `.btn-ack-slow` replaced with `.btn-ack-briefing`.
   - Zero movement, throttle, braking, or speed-control functionality present.
2. **Receipt-Only Acknowledgement Semantics:**
   - Buttons strictly restricted to:
     1. `Briefing Noted (Standard Vigilance)`
     2. `Acknowledged — Advisory Received`
     3. `Stationary Standstill Logged`
   - Interactive prototype callbacks confirm that clicking logs receipt to the Central Control audit ledger with zero locomotive control interactions.
3. **Stationary-Review Mandate & Anti-Glare Visual Identity:**
   - High-contrast 32px tabular numerals, deep black canvas (`#070a0e`), and solid crimson accent borders preserved.
   - Statutory circular banner preserved: `STATIONARY REVIEW USE ONLY • DO NOT INTERACT WHILE LOCOMOTIVE IS IN MOTION (Circular 2026/04)`.
   - Caution Order Form T.409/T.410 legal precedence card maintained.

---

### Task 5 — Citizen Reporter Portal (`05_reporter_dashboard_preview.html`)
1. **Observer Standoff Guidance Alignment:**
   - Step 1 guide text updated: *"Never approach elephants. Maintain a safe observer standoff distance (50–150m) and do not use bright lights or flash."*
2. **Two-Stage Verification Separation:**
   - Step 3 guide updated to: `Two-Stage Verification. DWC Wildlife Rangers first verify observation data. Next, Railway Operations Control independently assesses track hazards and issues train advisories if necessary. For emergencies, dial 1992.`
   - Submissions history updated from `"VERIFIED & CONVERTED TO TRAIN ALERT"` to:
     - Badge: `<span class="status-pill status-verified">ECOLOGICALLY VERIFIED</span>`
     - Status note: `Stage 1 Verified • Forwarded to Rail Control Desk for Advisory Evaluation`.
3. **Scrolling & SaaS Aesthetic Fixes:**
   - Gradient background on Hero card replaced with flat solid panel (`#1a2736`).
   - Clean vertical scrolling (`overflow-y: auto; padding-bottom: 24px`) prevents nested scroll traps when embedded in iframes or viewed on mobile.
4. **Simple Reporting Workflow:**
   - Prominent call-to-action and 3-step reporting guide retained.

---

### Task 6 — Field Ranger PWA (`06_field_pwa_preview.html`)
1. **Zero Emojis — Professional SVG Icons:**
   - All diurnal emoji icons replaced with clean, scalable SVGs:
     - Dawn (Sunrise over horizon SVG)
     - Morning (Sun with rays SVG)
     - Midday (High sun disc SVG)
     - Afternoon (Sun behind cloud SVG)
     - Dusk (Sunset below horizon SVG)
     - Night (Crescent moon SVG)
   - Save Draft button emoji replaced with floppy disk SVG.
2. **Real Device GNSS vs Simulated Sample Distinction:**
   - Telemetry header badge labeled: `SIMULATED GNSS: ±18m (Scrub Fix)`.
   - Demonstrator title updated: `Hardware GPS Dynamic States (Demonstrator): Illustrative simulator toggling realistic mobile browser accuracy tiers`.
3. **Offline Queue Status Clarification:**
   - Offline pill labeled: `OFFLINE READY (SAMPLE: 0 QUEUED)` to prevent mistaking static values for active IndexedDB sync telemetry.
4. **Mobile-First 6-Stage Form Preserved:**
   - Step progress indicator, touch-friendly 44px/48px tap targets, and 410px mobile container preserved.
5. **GPS Denied/Unavailable State Visible:**
   - Hardware state demonstrator includes prominent `Denied/Unavailable` state with crimson indicator.

---

## 3. Gallery Index Refinements (`index.html`)

The multi-viewport preview portal was updated to reflect all refined features:
- All 6 role cards updated with exact feature bullets matching the refined prototypes.
- Persistent amber synthetic demonstration banner maintained.
- Multi-viewport container controls (Full Desktop, 1280px Laptop, 768px Tablet, 390px Mobile) verified working cleanly across all prototypes.

---

## 4. Verification & Testing Matrix

### 4.1 SHA-256 Integrity Verification

All 7 preview files were mirrored across both target directories (`docs/final_handover/phase_b_design_previews/` and `public/design-previews/`) and XAMPP `htdocs`. Checksums match identically:

| File Name | File Size (Bytes) | SHA-256 Checksum | Integrity Status |
|---|:---:|---|:---:|
| `01_admin_dashboard_preview.html` | 23,194 | `e5e6703fc04488c01a27c05595fa20fd428539aef0de4919967fc785e7ed373a` | Verified Match |
| `02_control_desk_preview.html` | 18,439 | `abcf3d93b0f589366f2ac8892b86450e4b9ab8fa90bca38905f9845418a84382` | Verified Match |
| `03_wildlife_verification_preview.html` | 18,185 | `4e9875b5be9e9182ec83717219ec4e8ca8375d55ef748696a02ec8c74bab3571` | Verified Match |
| `04_driver_dashboard_preview.html` | 11,909 | `a93a3ce80d836b795506f5ecccbf24de5be3c935ca8f6fb558cef3e071dcd126` | Verified Match |
| `05_reporter_dashboard_preview.html` | 9,522 | `387b443126ce5fb451301558a7e8754bfdec7f3026fadd0312981a2620f56398` | Verified Match |
| `06_field_pwa_preview.html` | 14,583 | `7811615781729263f168595bf825490334b110107caeb2fc5ed9e2eebd41ae20` | Verified Match |
| `index.html` | 20,402 | `26d3f53ad9d2ea21893cbe5333f8d30b49e4716858a3af3e8a6d1f731fac3feb` | Verified Match |

### 4.2 HTTP Accessibility & Security Audit

| Target Endpoint | Method | Expected | Actual Status | Result |
|---|:---:|:---:|:---:|:---:|
| `http://localhost/eleguard-rail/public/design-previews/index.html` | GET | `200 OK` | `200 OK` | PASS |
| `http://localhost/eleguard-rail/public/design-previews/01_admin_dashboard_preview.html` | GET | `200 OK` | `200 OK` | PASS |
| `http://localhost/eleguard-rail/public/design-previews/02_control_desk_preview.html` | GET | `200 OK` | `200 OK` | PASS |
| `http://localhost/eleguard-rail/public/design-previews/03_wildlife_verification_preview.html` | GET | `200 OK` | `200 OK` | PASS |
| `http://localhost/eleguard-rail/public/design-previews/04_driver_dashboard_preview.html` | GET | `200 OK` | `200 OK` | PASS |
| `http://localhost/eleguard-rail/public/design-previews/05_reporter_dashboard_preview.html` | GET | `200 OK` | `200 OK` | PASS |
| `http://localhost/eleguard-rail/public/design-previews/06_field_pwa_preview.html` | GET | `200 OK` | `200 OK` | PASS |
| `http://localhost/eleguard-rail/docs/final_handover/phase_b_design_previews/index.html` | GET | `403 Forbidden` | `403 Forbidden` | PASS |
| `http://localhost/eleguard-rail/.env` | GET | `403 Forbidden` | `403 Forbidden` | PASS |
| `http://localhost/eleguard-rail/app/core/Database.php` | GET | `403 Forbidden` | `403 Forbidden` | PASS |
| `http://localhost/eleguard-rail/tests/run_all_tests.php` | GET | `403 Forbidden` | `403 Forbidden` | PASS |

### 4.3 Responsive Viewport Evaluation

- **Desktop (Full / 1440px):** Two-column operational grids, split dossier views, and linear occupancy strips display with generous margins and clear typographic hierarchy.
- **Laptop (1280px):** Grids fit within container boundaries without horizontal viewport scrolling.
- **Tablet (768px):** Tables scroll horizontally within container boundaries without clipping page margins; navigation remains accessible.
- **Mobile (390px):** Field PWA and Reporter interfaces stack naturally; touch targets exceed 44px minimum sizing; diurnal selectors and receipt buttons wrap cleanly.

---

## 5. Summary of Compliance

1. **Operational Safety Logic:** No speed control, braking, or movement capabilities exist on driver interfaces; receipt-only semantics enforced.
2. **Statutory Integrity:** Sri Lanka Railways Caution Orders (Form T.409/T.410) and Train Controller authority given full precedence.
3. **Role Separation:** DWC ecological verification strictly decoupled from Railway Operations Control advisory dispatch.
4. **AI Architecture Honesty:** `YOLOX-Tiny ONNX` thresholds (`DEFAULT_CONF_THRESHOLD = 0.40`, `DEFAULT_UNCERTAIN_THRESHOLD = 0.20`) accurately cited from `AiDetectionService.php`; advisory confidence and bounding box representations truthful to backend capabilities.
5. **Environmental Purity:** Zero application code, database records, or Git history modified.
