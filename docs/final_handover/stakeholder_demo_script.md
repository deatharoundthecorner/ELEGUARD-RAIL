# ELEGUARD RAIL — STAKEHOLDER DEMONSTRATION SCRIPT
## Step-by-Step Technical Presentation & Live Demonstration Guide

**Document Reference:** `docs/final_handover/stakeholder_demo_script.md`  
**Change Reference:** `ER-FINAL-HANDOVER-001`  
**Target Audience:** DWC Officials, SLR Train Operations, Transport Ministry, Academic Reviewers  
**Estimated Duration:** 15–20 Minutes  
**Demonstration Mode:** Live Local Instance (`http://127.0.0.1:8097/` or `http://localhost/eleguard-rail/`)  

---

## 1. DEMONSTRATION OVERVIEW & PRE-FLIGHT CHECKLIST

### Pre-Flight Verification (Prior to Stakeholder Arrival)
1. **Database Service:** Ensure XAMPP MariaDB is running on port 3308 (or standard port).
2. **Web Server:** Ensure Apache is running and serving `public/` directory.
3. **Browser State:** Open Chrome or Edge with Developer Tools available (Console & Network tabs).
4. **Demonstration Accounts:** Ensure credentials for test personas are accessible:
   - Admin (`admin@rail.gov.lk`)
   - Wildlife Officer (`wildlife@wildlife.gov.lk`)
   - Control Officer (`control@rail.gov.lk`)
   - Locomotive Driver (`driver@rail.gov.lk`)
   - Field Reporter (`reporter@wildlife.gov.lk`)

---

## 2. TIMED DEMONSTRATION SEQUENCE

```
[00:00 - 02:00]  1. Context, Ecological Problem & Proposed Solution
[02:00 - 04:30]  2. Central Railway System Dashboard & GIS Mapping
[04:30 - 07:30]  3. Field Sighting Submission & Decoupled Georeferencing
[07:30 - 10:00]  4. Offline PWA, AES-GCM Encrypted Storage & Synchronization
[10:00 - 12:30]  5. Wildlife Officer Verification Desk & Clarifications
[12:30 - 14:30]  6. Locomotive Driver Heads-Up Display (HUD) & Cab Safety
[14:30 - 16:30]  7. Security Hardening & Zero-Trust Privilege Isolation
[16:30 - 18:30]  8. Automated Testing Telemetry & Immutability Evidence
[18:30 - 20:00]  9. Operational Limitations, Governance & Q&A
```

---

### ACT 1: Context & Problem Overview [00:00 – 02:00]
- **Where to Navigate:** Project Introduction slide or platform landing page.
- **Narrative:**
  > "Welcome, esteemed stakeholders. Between Maho and Galgamuwa, the Northern railway line intersects core migratory paths of Sri Lanka's wild elephant population. ELEGUARD RAIL was engineered to replace slow, unstructured phone and VHF sighting reports with real-time, mathematically validated spatial intelligence—while ensuring locomotive driver safety is never compromised."
- **Key Takeaway:** The system is an early-warning telemetry bridge between the Department of Wildlife Conservation (DWC) and Sri Lanka Railways (SLR).
- **Prohibited Claim:** Do **not** claim the system automatically brakes trains or overrides railway signals.

---

### ACT 2: Central Dashboard & Cartographic Intelligence [02:00 – 04:30]
- **Where to Navigate:** Login as Control Officer $\to$ `http://localhost/eleguard-rail/public/control/dashboard.php`
- **What to Show:**
  1. The central interactive Leaflet map displaying railway track geometry and active wildlife hotspots.
  2. The distinct track proximity zones:
     - **Zone 0 (Red, 0–15m):** Track clearance zone.
     - **Zone 1 (Amber, 15–100m):** Near-corridor zone.
     - **Zone 2 (Yellow, 100–1,000m):** Corridor buffer zone.
     - **Zone 3 (Gray, >1,000m):** Off-corridor habitat.
  3. Railway junction geofences (Gal Oya and Maho Junctions) where automated section attribution is suppressed due to topological divergence.
- **What to Explain:** Point out the OSM disclaimer banner at the bottom of the map. Explain that distances are derived from OpenStreetMap geometry ($10\text{--}30\text{m}$ planar uncertainty) and are clearly distinguished from official engineering surveys.

---

### ACT 3: Decoupled Field Reporting & Geodetic Forward Projection [04:30 – 07:30]
- **Where to Navigate:** Open second tab / mobile simulation $\to$ `public/wildlife/submit_report.php`
- **What to Do:**
  1. Input observer coordinates: Lat `8.03450000`, Lon `80.28500000`, Accuracy `8.5m`.
  2. Input visual offset: Standoff distance `80m`, Compass bearing `45°` (North-East).
  3. Submit the report.
- **What to Show:**
  - Show the returned ingestion envelope.
  - Explain how the server decoupled the observer's position from the animal:
    - Observer coordinate remained `8.03450000, 80.28500000`.
    - Animal position was projected forward along $45^\circ$ to `8.03501000, 80.28551200`.
    - Spatial uncertainty was computed via Root-Sum-Square (RSS): combining the $8.5\text{m}$ GPS error, visual range error ($16\text{m}$), and $10^\circ$ compass variance ($13.9\text{m}$) into an exact $\pm 22.8\text{m}$ uncertainty buffer.
- **Prohibited Claim:** Do **not** describe the circular visualization as an uncertainty ellipse; explain it is an isotropic circular confidence buffer.

---

### ACT 4: Offline PWA & Encrypted Local Synchronization [07:30 – 10:00]
- **Where to Navigate:** Mobile PWA interface $\to$ `public/field/`
- **What to Do:**
  1. Open Developer Tools $\to$ Network tab $\to$ Select **Offline**.
  2. Fill out a wildlife sighting report (elephant herd of 4 near milestone 82).
  3. Click **Save Observation**.
  4. Open Application tab $\to$ IndexedDB $\to$ Show that draft observation is stored in AES-GCM encrypted format.
  5. Toggle network back to **Online**.
  6. Trigger synchronization $\to$ Show batch receipt HTTP 207 Multi-Status.
- **What to Explain:**
  - Explain client-generated RFC 4122 v4 UUID idempotency.
  - Explain that drafts are deleted locally **only** after verified server acknowledgment (`synced` or `replayed`). If the server rejects or flags uncertainty, the draft remains securely preserved in the ranger's device.

---

### ACT 5: Wildlife Officer Verification & Clarification Desk [10:00 – 12:30]
- **Where to Navigate:** Switch tab $\to$ `public/wildlife/reports.php`
- **What to Show:**
  1. The newly ingested report appears with status `Pending`.
  2. Click **Inspect & Verify**. (Note: Extended governance tables `report_verifications` and `report_clarifications` are active when migrations 04–06 are provisioned).
  3. Demonstrate the audit trail: Show that an officer must record verification method (e.g., `remote_telemetry` or `station_master_vhf`) and notes.
  4. Demonstrate two-way clarification: Officer posts an inquiry asking for calf age verification; show the inquiry logged in `report_clarifications`.
  5. Promote report to `Verified`.
- **What to Explain:** Highlight governance integrity: Unverified reports cannot trigger operational railway alerts. Human officer verification is legally mandatory under DWC guidelines.

---

### ACT 6: Locomotive Driver Heads-Up Display (HUD) [12:30 – 14:30]
- **Where to Navigate:** Login as Locomotive Driver $\to$ `public/driver/alerts.php`
- **What to Show:**
  1. High-contrast, night-vision HUD showing active cautionary speed advisories.
  2. Show that verified elephant reports in Zone 0 recommend cautionary speed ($20\text{ km/h}$).
  3. **Stationary Review Safety Policy:** Highlight the prominent safety warning banner:
     > `STATIONARY REVIEW USE ONLY — DO NOT INTERACT WHILE IN MOTION`
     > `Confirm receipt and submit cab remarks only when train is stationary at a station, siding, or stop signal.`
  4. **Explain Technical Reality:** Clarify to stakeholders that the platform does **not** interface with locomotive telemetry or speed sensors. There is no automated speed-based software lockout. The stationary requirement is an operational safety rule enforced procedurally and through clear visual UI warnings.
- **What to Emphasize:** The HUD is strictly passive while running. Locomotive drivers must keep eyes on track; operational Caution Orders issued by station staff remain the primary legal authority.

---

### ACT 7: Security Hardening & Zero-Trust Isolation [14:30 – 16:30]
- **What to Explain / Present:**
  1. **SQL Injection Hardening:** 100% of 28 dynamic queries parameterized using PDO.
  2. **MariaDB Privilege Separation:** The analytics dashboard runs as `eleguard_readonly`, completely incapable of writing or deleting data at the database engine level.
  3. **UUID Collision Isolation:** Demonstrate that if User B submits User A's UUID, the server returns generic HTTP 409 Conflict without leaking User A's report ID, status, or timestamps.
  4. **Prohibition of Fabricated GPS:** Show code proof in `FieldReportingService.php` that missing GPS accuracy strictly preserves uncertainty as `NULL` rather than inventing an assumed 10m radius.

---

### ACT 8: Automated Verification & Immutability Telemetry [16:30 – 18:30]
- **What to Show:**
  1. Display the test execution console output from `tests/run_all_tests.php`.
  2. Point to the verified result: **35 / 35 test suites passed cleanly**.
  3. Show the database isolation check: Operational databases `eleguard_rail` and `eleguard_rail_test` maintained identical record counts (43 reports, 15 alerts, 185 logs) before and after testing.

---

### ACT 9: Operational Limitations, Regulatory Boundaries & Q&A [18:30 – 20:00]
- **Closing Remarks:**
  > "ELEGUARD RAIL has completed all authorized software engineering and security hardening stages. The platform is ready for controlled staging and field trials. Live operational commissioning on active trains will take place only under the regulatory supervision of the General Manager of Railways and the Director General of Wildlife Conservation."
- **Invite Questions from Stakeholders.**

---

## 3. FORMAL DEPLOYMENT & READINESS STATUS

To ensure complete transparency during stakeholder presentations, the presenter must emphasize the following project status facts:
1. **Technical Completion:** Stages J.1 through J.6 are technically complete and verified across all 35 test suites.
2. **Production Status:** Production deployment is **NOT AUTHORIZED** under this scope; this demonstration is conducted on a local verification environment.
3. **Field Trials:** Live in-service railway field trials have **not** been completed.
4. **Regulatory Approval:** Formal operational approvals from DWC and SLR remain pending.
5. **Safety Certification:** No official railway safety engineering certification is claimed.
