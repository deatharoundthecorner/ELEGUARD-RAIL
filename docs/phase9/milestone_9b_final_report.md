# EleGuard Rail — Milestone 9B: Historical Hotspot Analytics & Spatial Intelligence
## Final Verification & Comprehensive Synthesis Report

**Milestone:** 9B — Historical Hotspot Analytics & Spatial Intelligence  
**Phase:** 9 — Historical Elephant Sighting Intelligence  
**Project:** EleGuard Rail (Sri Lanka Wildlife-Railway Collision Early Warning Platform)  
**Verification Date:** November 2026  
**Baseline Git Commit:** `d459ccd`  
**Classification:** Scientific & Engineering Verification Audit (Strict Read-Only)  
**Primary Workspaces:**
- Source Workspace: `C:\Users\user\Desktop\Railway\eleguard-rail`
- XAMPP Deployment: `C:\xampp\htdocs\eleguard-rail`

---

## 1. Executive Summary & Mission Scope

Milestone 9B delivered a reproducible, evidence-based historical hotspot analytics engine for EleGuard Rail. The mission objective was to investigate the spatial relationships between surveyed elephant crossing hotspots, railway track geometries (1,055 OpenStreetMap centerline waypoints), and historical sighting reports.

### Strict Scope Boundary Enforcement
1. **Analytics Engine, NOT Risk Engine Replacement:** This milestone focused exclusively on historical spatial intelligence and reporting. Existing operational risk scoring algorithms, real-time alert thresholds, and driver notification workflows remain completely untouched and active.
2. **Strict Read-Only Database Governance:** Zero records were inserted, modified, or deleted across all MariaDB tables. The database population remains at exactly 43 reports and 4 surveyed hotspots.
3. **Data Provenance Discipline:** In accordance with Milestone 9A findings, reports were strictly stratified by analytical provenance:
   - Primary Population: Confirmed Historical Baseline ($N=3$).
   - Supplementary Population: Test Artifacts ($N=33$), Demonstration Records ($N=3$), and E2E Audit Runs ($N=4$).
   Synthetic test data was rigorously isolated and never conflated with authentic wildlife sightings.

---

## SECTION A — Existing Hotspot Inventory

A full audit of the `hotspots` table joined with `railway_sections` confirmed exactly 4 surveyed hotspot records:

| Hotspot ID | Hotspot Name | Assigned Section | Risk Level | Latitude | Longitude | Track Distance (Assigned) | Track Distance (Nearest) | Section Consistent? |
|:---:|:---|:---|:---:|:---:|:---:|:---:|:---:|:---:|
| **1** | Minneriya Elephant Crossing | Sec 2: Minneriya - Hingurakgoda | **Critical** | $8.0412^\circ\text{ N}$ | $80.8256^\circ\text{ E}$ | 7,484.0 m | 5,154.4 m (Sec 1) | **NO** (Park Corridor) |
| **2** | Habarana Forest Edge | Sec 1: Habarana - Gal Oya | **High** | $8.0354^\circ\text{ N}$ | $80.7511^\circ\text{ E}$ | 2,177.0 m | 2,177.0 m (Sec 1) | **YES** |
| **3** | Gal Oya Corridor | Sec 1: Habarana - Gal Oya | **High** | $8.1256^\circ\text{ N}$ | $80.9124^\circ\text{ E}$ | 5,134.2 m | 3,742.5 m (Sec 2) | **NO** (Junction Split) |
| **4** | Night Movement Zone 01 | Sec 3: Maho - Anuradhapura | **Medium** | $7.9854^\circ\text{ N}$ | $80.3214^\circ\text{ E}$ | 5,604.1 m | 5,604.1 m (Sec 3) | **YES** |

### Inventory Validation Checks
- **Coordinate Completeness:** 4 / 4 records (100.0%) possess valid numeric coordinates.
- **Duplicate Coordinates:** 0 duplicate coordinate pairs detected.
- **Geographic Plausibility:** 100% of coordinates fall within Sri Lankan national bounds ($5.8^\circ - 10.0^\circ\text{ N}, 79.5^\circ - 82.2^\circ\text{ E}$) and the active North-Central/Eastern railway operational study corridor ($7.8^\circ - 8.4^\circ\text{ N}, 80.2^\circ - 81.6^\circ\text{ E}$).
- **Spatial Scale:** Distances from hotspot centroids to physical track centerlines range from 2.18 km to 5.60 km, reflecting landscape-scale wildlife corridors rather than point-specific rail crossings.

---

## SECTION B — Spatial Analysis Results

Using the spherical Haversine formula ($R = 6,371,000.0\text{ m}$) implemented in `ProximityService.php` and replicated in `ai/analytics/hotspot_spatial_analysis.py`, pairwise distances and spatial distributions were calculated.

### Inter-Hotspot Pairwise Geodesic Matrix
- **Hotspot 1 (Minneriya) $\leftrightarrow$ Hotspot 2 (Habarana):** 8.23 km (Adjacent North-Central crossing corridor)
- **Hotspot 1 (Minneriya) $\leftrightarrow$ Hotspot 3 (Gal Oya):** 13.39 km (Inter-reserve migration route)
- **Hotspot 2 (Habarana) $\leftrightarrow$ Hotspot 3 (Gal Oya):** 20.39 km (Section 1 corridor span)
- **Hotspot 4 (Maho Zone 01) $\leftrightarrow$ Hotspots 1, 2, 3:** 47.64 km to 66.91 km (Cross-provincial ecological regime on Northern line)

---

## SECTION C — Report-to-Hotspot Relationships

### 1. Primary Population: Confirmed Historical Baseline ($N=3$)
- **Reports:** IDs 1, 2, and 3.
- **GPS Availability:** **0 / 3 (0.0%)** contain GPS coordinates. All three are text-only historical records.
- **Scientific Limitation:** Geodesic distance calculations cannot be computed without fabricated data.
- **Qualitative Corridor Alignment:**
  - Report ID 1 ("Near Mile Post 85, close to the small lake") aligns with Maho - Anuradhapura (Section 3).
  - Report ID 2 ("Minneriya Crossing marker 102") aligns with Minneriya Crossing (Section 2, Hotspot 1).
  - Report ID 3 ("Habarana bend near forest temple") aligns with Habarana Forest (Section 1, Hotspot 2).
- **Conclusion:** $N=3$ records cannot substantiate statistically valid elephant movement models or empirical hotspot boundaries.

### 2. Supplementary Population: GPS-Enabled Reports ($N=29$)
Across the 29 GPS-enabled test, demo, and audit reports:
- **$< 25\text{ m}$ (Co-located):** 22 reports (75.9%) — 11 test runs at Hotspot 1 (7.1 m) and 11 test runs at Hotspot 3 (0.0 m).
- **$25\text{ m} - 100\text{ m}$ (Immediate Vicinity):** 1 report (3.4%) — Demo report ID 42 (48.7 m from Hotspot 1).
- **$100\text{ m} - 1\text{ km}$ (Corridor Buffer):** 5 reports (17.2%) — 4 audit reports (108.9 m from Hotspot 2) + 1 demo report (562.1 m from Hotspot 2).
- **$5\text{ km} - 20\text{ km}$ (Regional Scale):** 1 report (3.4%) — Demo report ID 43 (5.3 km from Hotspot 3, 8.6 km from Hotspot 1).

---

## SECTION D — Railway Section Findings & Mismatch Investigation

### The 26/29 Section Mismatch Phenomenon
Milestone 9A identified that 26 out of 29 GPS reports (89.7%) were assigned to a railway section that differed from the geometrically nearest railway track line.

Milestone 9B conducted a spatial geometric trace of `railway_routes.geojson` and resolved the discrepancy:

1. **Root Cause:**
   - **Hotspot 1 (Minneriya):** Administratively assigned to Section 2 (*Minneriya - Hingurakgoda*), but located 5.15 km from Section 1 track and 7.48 km from Section 2 track.
   - **Hotspot 3 (Gal Oya):** Administratively assigned to Section 1 (*Habarana - Gal Oya*), but located at Gal Oya Junction where Section 2 track passes 3.74 km away versus Section 1 track at 5.13 km.
2. **Propagation to Test Reports:**
   - 11 test reports near Hotspot 1 inherited Section 2, but the nearest track is Section 1 (2,328.6 m closer) $\to$ **11 mismatches**.
   - 11 test reports at Hotspot 3 inherited Section 1, but the nearest track is Section 2 (1,391.7 m closer) $\to$ **11 mismatches**.
   - Demo report ID 42 (near Hotspot 1) $\to$ **1 mismatch**.
   - Demo report ID 43 (intermediate point) $\to$ **1 mismatch** (difference < 200 m).
   - Audit reports IDs 54 & 56 (reporter selected Section 2 with Section 1 coordinates) $\to$ **2 mismatches**.
   - **Total Mismatches:** Exactly $11 + 11 + 1 + 1 + 2 = 26$ mismatches.
3. **Core Architectural Principle:**
   Nearest-track Euclidean projection does not determine administrative jurisdiction. Administrative beats represent wildlife ranger patrol ranges, while track distance represents locomotive collision risk. **Section assignments in the database must NOT be altered**.

---

## SECTION E — Historical Evidence Classification

 हॉटस्पॉट (Hotspots) were evaluated under a transparent four-tier descriptive framework without manufactured confidence scores:

| Hotspot ID | Hotspot Name | Risk Level | Administrative Tier | Empirical Evidence Tier | Operational Sighting Support | Recommendation |
|:---:|:---|:---:|:---|:---|:---|:---|
| **1** | Minneriya Elephant Crossing | Critical | **Tier 1 (Mapped)** | **Tier 4 (Insufficient)** | Qualitative match (Report 2: Marker 102, no GPS) | Retain in GIS; capture GPS field data via mobile app. |
| **2** | Habarana Forest Edge | High | **Tier 1 (Mapped)** | **Tier 4 (Insufficient)** | Qualitative match (Report 3: Forest temple bend, no GPS) | Retain in GIS; prioritize trackside vegetation clearing. |
| **3** | Gal Oya Corridor | High | **Tier 1 (Mapped)** | **Tier 4 (Insufficient)** | Qualitative match (Section 1 junction approach, no GPS) | Retain in GIS; formalize junction beat boundary. |
| **4** | Night Movement Zone 01 | Medium | **Tier 1 (Mapped)** | **Tier 4 (Zero Support)** | Zero sighting reports in Section 3 | Retain in GIS; conduct field verification with Northern line staff. |

---

## SECTION F — Visualization Artifacts

Six publication-quality analytical charts (300 DPI) were generated and saved in `docs/phase9/visualizations/`:

1. `hotspot_spatial_distribution.png`: Relative spatial coordinates and risk tiers across the North-Central study corridor ($N=4$).
2. `hotspot_to_railway_distance.png`: Geodesic distance comparison from surveyed hotspots to assigned section track vs closest physical track.
3. `report_to_hotspot_distance_distribution.png`: Ecological distance band distribution for GPS-enabled reports ($N=29$).
4. `railway_section_hotspot_breakdown.png`: Record breakdown across all 4 railway sections, separating surveyed hotspots from historical baseline and synthetic test artifacts.
5. `provenance_historical_vs_test.png`: Total population provenance breakdown ($N=43$) and GPS coordinate availability by category.
6. `hotspot_evidence_classification.png`: Master evidence classification matrix detailing administrative vs empirical tiers.

---

## SECTION G — Test Results

Automated regression and unit test suites were executed across the platform:

| Test Suite | File | Tests Run | Passed | Failed | Status |
|:---|:---|:---:|:---:|:---:|:---:|
| **Milestone 9B Analytics Suite** | `tests/test_milestone_9b_analytics.php` | 23 | 23 | 0 | **PASS (100%)** |
| **GIS & Proximity Engine Suite** | `tests/test_gis_and_proximity.php` | 21 | 21 | 0 | **PASS (100%)** |
| **Milestone 7C Map Integration Suite** | `tests/test_milestone_7c.php` | 12 | 12 | 0 | **PASS (100%)** |
| **Python AI Elephant Detector Suite** | `ai/tests/test_detector.py` | 24 | 24 | 0 | **PASS (100%)** |
| **TOTAL REGRESSION TESTS** | — | **80** | **80** | **0** | **PASS (100%)** |

*Note: Test suites that execute live database INSERT/DELETE operations (`test_gps_and_workflows.php`, `test_ai_integration.php`) were deliberately isolated to uphold the strict read-only mandate.*

---

## SECTION H — Database Integrity Verification

The live MariaDB database (port 3308) was independently audited before and after analytics execution:

| Table Name | Baseline Row Count | Post-Analytics Row Count | Modification Status |
|:---|:---:|:---:|:---:|
| `elephant_reports` | 43 | 43 | **0 changes (Unmodified)** |
| `hotspots` | 4 | 4 | **0 changes (Unmodified)** |
| `railway_sections` | 4 | 4 | **0 changes (Unmodified)** |
| `alerts` | 11 | 11 | **0 changes (Unmodified)** |
| `driver_acknowledgements` | 11 | 11 | **0 changes (Unmodified)** |
| `users` | 5 | 5 | **0 changes (Unmodified)** |
| `system_logs` | 25 | 25 | **0 changes (Unmodified)** |

- **Operational Risk Engine:** 0 risk scores altered; 0 operational alert events generated.
- **Privacy & Security:** Zero reporter PII exposed; zero database credentials committed.

---

## SECTION I — Git Commit and Workspace Status

### Deliverables Created in Milestone 9B
1. **Reproducible Analytics Scripts (`ai/analytics/`):**
   - `hotspot_inventory.php`
   - `hotspot_spatial_analysis.py`
   - `hotspot_section_analysis.py`
   - `hotspot_analytics_report.py`
2. **Automated Test Suite (`tests/`):**
   - `test_milestone_9b_analytics.php`
3. **Structured Data Artifacts (`docs/phase9/`):**
   - `hotspot_inventory.json`
   - `hotspot_spatial_analysis.json`
   - `hotspot_section_analysis.json`
   - `hotspot_evidence_classification.json`
4. **Analytical Visualizations (`docs/phase9/visualizations/`):**
   - 6 high-resolution 300 DPI charts.
5. **Comprehensive Documentation Deliverables (`docs/phase9/`):**
   - `milestone_9b_hotspot_inventory.md`
   - `milestone_9b_spatial_analysis.md`
   - `milestone_9b_section_analysis.md`
   - `milestone_9b_evidence_classification.md`
   - `milestone_9b_final_report.md`

---

## SECTION J — Remaining Limitations

1. **Absence of Historical GPS Sightings:**
   The primary historical baseline contains only three text-only records ($N=3$). Until wildlife officers systematically log GPS-enabled sightings in the field, empirical spatial clustering and dynamic hotspot boundary refinement cannot be performed.
2. **Synthetic Dominance in Database:**
   Of the 43 reports currently stored in `elephant_reports`, 40 (93.0%) are test harness artifacts, demonstration cases, or audit verification runs. While these records validate software functionality, they do not represent biological movement patterns.
3. **Section Boundary Ambiguity at Junctions:**
   Administrative jurisdictions between Section 1 and Section 2 at Gal Oya Junction require formal inter-agency cartographic alignment between the Department of Wildlife Conservation and Sri Lanka Railways.

---

## SECTION K — Recommendations for Milestone 9C

1. **Do NOT Modify Existing Hotspot Geometries:** Retain all four surveyed hotspots in the operational GIS map and database.
2. **Field Logging Campaign:** Equip field rangers with the EleGuard Rail mobile reporting tool to build an authentic, GPS-validated historical dataset.
3. **Dual Attribute Display in GIS:** Display both `Assigned Wildlife Beat` and `Nearest Railway Track & Distance` in GIS officer popups to eliminate cognitive confusion regarding the 26/29 section discrepancy.
4. **Transition to Milestone 9C:** With the historical hotspot baseline rigorously cataloged, validated, and explained, the platform is ready for Milestone 9C (Temporal & Seasonal Intelligence) upon explicit user authorization.

---

## STOP CONDITION
Milestone 9B is complete. No operational changes or Milestone 9C activities have been initiated. Standing by for user review and approval.
