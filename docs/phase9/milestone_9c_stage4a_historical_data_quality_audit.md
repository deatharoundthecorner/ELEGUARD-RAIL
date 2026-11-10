# EleGuard Rail — Milestone 9C Stage 4A
## Historical Data Quality & Analytics Architecture Audit

**Project:** EleGuard Rail (Sri Lanka Wildlife-Railway Collision Early Warning Platform)  
**Evaluated Commit:** `c56d5cd`  
**Branch:** `main`  
**Environment:** PHP 8.2 / MariaDB 10.4 (Port 3308) / Apache (XAMPP)  
**Date:** 10 November 2026  
**Status:** COMPLETE & VERIFIED — AUDIT & ARCHITECTURE DESIGN ONLY (Zero Operational Writes)  

---

### 1. Executive Summary

Milestone 9C Stage 4A constitutes a comprehensive, read-only audit of the historical data stored within the EleGuard Rail platform (`eleguard_rail` database on port 3308) and establishes the architectural foundation for Milestone 9C Stage 4 (Historical Intelligence and Analytics).

#### Core Audit Conclusions:
1. **Severe Historical Data Scarcity:**  
   The operational database contains only **three (N = 3)** genuine pre-deployment operational reports (IDs 1, 2, 3). The remaining 40 records (IDs 4 through 56) represent development, QA, and validation artifacts generated on 8 November 2026.
2. **Zero Historical Observation Timestamps:**  
   In the operational database, **100% of reports (43/43)** have `sighting_date = NULL`, `sighting_time = NULL`, and `sighting_time_precision = 'unknown'`. Historical records possess only database creation timestamps (`created_at`).
3. **No Ground-Truth Collision Labels:**  
   The `incident_history` table contains only **3 seed records** without GPS coordinates, precise clock times, or foreign key linkage to elephant reports. There are zero ground-truth negative outcome labels (trains passing safely).
4. **Duplicate Report Clustering Readiness:**  
   The database contains 33 repeated test submissions across 3 identical locations. The schema already includes `is_potential_duplicate` and `duplicate_cluster_id` columns, making the database structurally ready for non-destructive spatial-temporal duplicate clustering.
5. **Analytical Boundaries:**  
   Because all operational timestamps cluster on a single calendar day (8 November 2026), **seasonal (Yala/Maha monsoon) analysis, multi-month trends, and sequential movement vectors are scientifically impossible on existing historical data**. Stage 4 analytics must clearly segregate development provenance and provide analytical infrastructure ready to process genuine data as it accumulates from field operations.

---

### 2. Operational Database Inventory

A complete inventory of the operational MariaDB database (`eleguard_rail`) was conducted:

| Table Name | Engine | Row Format | Total Rows | Data Length | Index Length | Auto Increment | Purpose / Domain |
|---|:---:|:---:|:---:|:---:|:---:|:---:|---|
| `alerts` | InnoDB | Dynamic | **15** | 16.0 KB | 48.0 KB | 16 | Operational speed restriction and collision hazard alerts |
| `driver_acknowledgements` | InnoDB | Dynamic | **13** | 16.0 KB | 32.0 KB | 14 | Train driver cab alert response audit trail |
| `elephant_reports` | InnoDB | Dynamic | **43** | 32.0 KB | 144.0 KB | 92 | Core sighting reports filed by rangers, public, and tests |
| `hotspots` | InnoDB | Dynamic | **4** | 16.0 KB | 16.0 KB | 5 | Known elephant crossing corridors and high-risk track bends |
| `incident_history` | InnoDB | Dynamic | **3** | 16.0 KB | 16.0 KB | 4 | Historical collision, near-miss, and false alarm records |
| `railway_sections` | InnoDB | Dynamic | **4** | 16.0 KB | 0.0 KB | 5 | Track administrative management sections |
| `system_logs` | InnoDB | Dynamic | **185** | 16.0 KB | 16.0 KB | 186 | System audit logs (logins, status updates, alert broadcasts) |
| `users` | InnoDB | Dynamic | **5** | 16.0 KB | 16.0 KB | 6 | User identity and role-based access control (RBAC) |
| **Total** | | | **282** | **144.0 KB** | **288.0 KB** | | |

---

### 3. Data Completeness & Quality Tables

#### 3.1 Field-by-Field Completeness for `elephant_reports` (N = 43)

| Field Name | Data Type | Null Count | Populated | % Complete | Quality Assessment & Limitations |
|---|:---:|:---:|:---:|:---:|---|
| `id` | `INT(11)` | 0 | 43 | **100.0%** | Primary key. Continuous range with gaps (IDs 1-43, 53-56). |
| `section_id` | `INT(11)` | 0 | 43 | **100.0%** | Foreign key to `railway_sections`. 100% referential integrity. |
| `reported_by` | `INT(11)` | 0 | 43 | **100.0%** | Foreign key to `users` (User 4 = 4 rows, User 5 = 39 rows). |
| `elephant_count` | `INT(11)` | 0 | 43 | **100.0%** | Ranged 1 to 5. Mean: 2.33 elephants per sighting. |
| `sighting_date` | `DATE` | 43 | 0 | **0.0%** | **Unpopulated in historical records.** Strict NULL preservation. |
| `sighting_time` | `TIME` | 43 | 0 | **0.0%** | **Unpopulated in historical records.** Strict NULL preservation. |
| `sighting_time_precision` | `ENUM` | 0 | 43 | **100.0%** | All 43 records defaulted to `'unknown'`. |
| `sighting_time_window` | `ENUM` | 43 | 0 | **0.0%** | Null for all historical records. |
| `sighting_time_uncertainty_minutes` | `INT(11)` | 43 | 0 | **0.0%** | Null for all historical records. |
| `sighting_timezone` | `VARCHAR(32)` | 0 | 43 | **100.0%** | Defaulted to `'Asia/Colombo'` across all records. |
| `location_description` | `VARCHAR(255)` | 0 | 43 | **100.0%** | Textual landmark or milepost reference. |
| `latitude` | `DECIMAL(10,8)` | 14 | 29 | **67.4%** | WGS-84 coordinates present for 29 records; 14 records are text-only. |
| `longitude` | `DECIMAL(11,8)` | 14 | 29 | **67.4%** | WGS-84 coordinates present for 29 records; 14 records are text-only. |
| `gps_accuracy_meters` | `DECIMAL(8,2)` | 25 | 18 | **41.9%** | Accuracy recorded for 18 records; 11 records have manual coordinates. |
| `gps_captured_at` | `DATETIME` | 43 | 0 | **0.0%** | Hardware GPS capture timestamp unrecorded in pre-migration data. |
| `urgency_level` | `ENUM` | 0 | 43 | **100.0%** | `'Low'`, `'Medium'`, `'High'`, `'Critical'`. Fully populated. |
| `risk_score` | `ENUM` | 0 | 43 | **100.0%** | Operational intake category. Fully populated. |
| `report_status` | `ENUM` | 0 | 43 | **100.0%** | `'Pending'` (28), `'Converted to Alert'` (12), `'Verified'` (2), `'Rejected'` (1). |
| `description` | `TEXT` | 0 | 43 | **100.0%** | Narrative sighting notes. |
| `image_path` | `VARCHAR(255)` | 37 | 6 | **14.0%** | Attached photo present for 6 records (seeded & E2E audit tests). |
| `ai_status` | `ENUM` | 0 | 43 | **100.0%** | `'skipped'` (37), `'processed'` (6). |
| `ai_detected_elephant` | `TINYINT(1)` | 37 | 6 | **14.0%** | Evaluated for the 6 reports with photographic attachments. |
| `ai_confidence` | `DECIMAL(5,4)` | 37 | 6 | **14.0%** | Evaluated for the 6 reports with photographic attachments. |
| `ai_estimated_count` | `INT(11)` | 37 | 6 | **14.0%** | Evaluated for the 6 reports with photographic attachments. |
| `ai_model_version` | `VARCHAR(50)` | 37 | 6 | **14.0%** | `'YOLOX-Tiny-ONNX'` recorded for processed reports. |
| `ai_detection_notes` | `TEXT` | 0 | 43 | **100.0%** | Advisory explanation notes present for all records. |
| `ai_processed_at` | `DATETIME` | 37 | 6 | **14.0%** | Processing timestamp recorded for the 6 processed reports. |
| `is_potential_duplicate` | `TINYINT(1)` | 0 | 43 | **100.0%** | Defaults to `0`. No historical reports flagged yet. |
| `duplicate_cluster_id` | `INT(11)` | 43 | 0 | **0.0%** | Unpopulated; reserved for Stage 4 duplicate clustering. |
| `created_at` | `TIMESTAMP` | 0 | 43 | **100.0%** | System ingestion timestamp. All cluster on 8 Nov 2026. |
| `updated_at` | `DATETIME` | 30 | 13 | **30.2%** | Updated timestamp present for 13 converted or verified reports. |

---

### 4. Provenance Classification of Historical Records

To prevent biased or fraudulent analytical conclusions, all 43 operational records were classified by documented provenance:

```
Total Operational Records (N = 43)
│
├── 1. Genuine Operational Baseline Reports: N = 3 (7.0%)
│    └── Reports #1, #2, #3 (Historical pre-deployment baseline submissions)
│
├── 2. Automated Test Run Triad Batch: N = 33 (76.7%)
│    └── Reports #4 through #33, and #38 through #40
│         ├── 11x "Near Curve 44 (Text Only)" (No GPS)
│         ├── 11x "Near Minneriya Crossing Marker 105" (GPS: 8.04125, 80.82564)
│         └── 11x "Gal Oya Manual Coordinates" (GPS: 8.12560, 80.91240)
│
├── 3. Seeded Photographic AI Test Batch: N = 3 (7.0%)
│    └── Reports #41, #42, #43 (Seeded with genuine elephant/track images for ONNX verification)
│
└── 4. E2E Automated Audit Batch: N = 4 (9.3%)
     └── Reports #53, #54, #55, #56 (Created during Milestone 8C governance audit)
```

#### Detailed Provenance Ledger:
1. **Batch 1 — Genuine Baseline (IDs 1–3):**
   - Filed between 06:02 and 07:32 on 8 Nov 2026.
   - Textual landmarks only: "Near Mile Post 85", "Minneriya Crossing marker 102", "Habarana bend near forest temple".
   - Zero coordinates, zero images, zero observation dates/times.
2. **Batch 2 — Automated Test Triads (IDs 4–40):**
   - 11 identical test execution runs between 08:10 and 12:42 on 8 Nov 2026.
   - Each run inserted the exact same three test records.
   - All 11 Minneriya records (IDs 5, 8, 11, 14, 17, 20, 23, 26, 29, 32, 39) were converted to operational alerts (Alerts #4–14).
3. **Batch 3 — Seeded Image Tests (IDs 41–43):**
   - Seeded at 08:13 on 8 Nov 2026 to test YOLOX-Tiny inference.
   - Report #41: Asian elephant image, 91.5% confidence, 3 elephants detected.
   - Report #42: Empty track image, 0.04% confidence, negative detection.
   - Report #43: Dusk edge-case image, 37.7% confidence, uncertain result.
4. **Batch 4 — E2E Governance Audit (IDs 53–56):**
   - Created at 20:35 on 8 Nov 2026 to verify Wildlife Officer manual review authority.
   - Identical coordinates `(8.035000, 80.752000)` submitted across three different section IDs (Sections 1, 2, 3).

---

### 5. GPS and Railway Geometry Quality Findings

#### 5.1 Coordinate Completeness & Accuracy
- **Coordinates Present:** 29 of 43 reports (**67.4%**).
- **Missing Coordinates (Text-only):** 14 of 43 reports (**32.6%**).
- **GPS Accuracy:**
  - 18 reports possess hardware accuracy metrics ranging from **$10.0\text{ m}$ to $25.0\text{ m}$** (mean: $14.4\text{ m}$).
  - 11 reports possess coordinates with `gps_accuracy_meters = NULL` (manual map-pin placement without hardware GPS receiver).
- **Coordinate Bounding Box:**
  - Latitude: $8.0350^\circ\text{N}$ to $8.1256^\circ\text{N}$
  - Longitude: $80.7520^\circ\text{E}$ to $80.9124^\circ\text{E}$
  - All coordinates fall strictly within the North-Central railway corridor of Sri Lanka.

#### 5.2 Spatial Coordinate Clusters
Geodesic Haversine clustering reveals that the 29 coordinate-bearing reports collapse into only **6 discrete geographic locations**:

| Cluster Coordinates | Report Count | Accuracy | Section ID | Distance to Nearest Reference Hotspot | Hotspot Association |
|---|:---:|:---:|:---:|:---:|---|
| **(8.041250, 80.825640)** | 11 | $14.5\text{ m}$ | Sec 2 | **$7.1\text{ m}$** | Hotspot #1 (Minneriya Elephant Crossing) |
| **(8.125600, 80.912400)** | 11 | NULL | Sec 1 | **$0.0\text{ m}$** | Hotspot #3 (Gal Oya Corridor — Exact match) |
| **(8.038400, 80.755200)** | 1 | $12.0\text{ m}$ | Sec 1 | **$561.3\text{ m}$** | Hotspot #2 (Habarana Forest Edge) |
| **(8.041000, 80.826000)** | 1 | $15.0\text{ m}$ | Sec 2 | **$49.3\text{ m}$** | Hotspot #1 (Minneriya Elephant Crossing) |
| **(8.082000, 80.891000)** | 1 | $25.0\text{ m}$ | Sec 1 | **$5,390.2\text{ m}$** | Intermediate forest tract (Between Hotspots #2 & #3) |
| **(8.035000, 80.752000)** | 4 | $10.0\text{ m}$ | Sec 1, 2, 3 | **$108.6\text{ m}$** | Hotspot #2 (Habarana Forest Edge) |

#### 5.3 Administrative vs. Geometric Discrepancies
Cluster `(8.035000, 80.752000)` (Reports #53, #54, #55, #56) highlights a critical GIS data quality issue:
- All 4 reports have identical physical coordinates located $108.6\text{ m}$ from Hotspot #2 in Section 1 (Habarana–Gal Oya).
- However, Report #53 is assigned to **Section 1**, Report #54 to **Section 2**, and Report #56 to **Section 3**.
- **Cause:** Community reporters select administrative section names from a manual dropdown rather than having the system derive the section geometrically from railway track shapefiles.
- **Recommendation:** Stage 4 must implement geometric railway corridor snapping to flag administrative/geometric section discrepancies.

---

### 6. Duplicate Detection Readiness

#### 6.1 Existing Database Capability
- The database schema already contains:
  - `is_potential_duplicate TINYINT(1) DEFAULT 0`
  - `duplicate_cluster_id INT(11) DEFAULT NULL`
  - Composite performance index: `idx_elephant_reports_duplicate (is_potential_duplicate, duplicate_cluster_id)`
- Currently, **0%** of operational records have been clustered (`is_potential_duplicate = 0`, `duplicate_cluster_id = NULL`).

#### 6.2 Empirical Duplicate Evidence
The audit identified blatant duplicate clusters created during development:
- **Cluster A:** 11 identical reports for "Near Minneriya Crossing Marker 105" (IDs 5, 8, 11, 14, 17, 20, 23, 26, 29, 32, 39) with identical section, elephant count (3), urgency ('High'), coordinates `(8.041250, 80.825640)`, and accuracy ($14.5\text{ m}$).
- **Cluster B:** 11 identical reports for "Gal Oya Manual Coordinates" (IDs 6, 9, 12, 15, 18, 21, 24, 27, 30, 33, 40) with identical section, elephant count (2), urgency ('High'), and coordinates `(8.125600, 80.912400)`.
- **Cluster C:** 11 identical text-only reports for "Near Curve 44" (IDs 4, 7, 10, 13, 16, 19, 22, 25, 28, 31, 38).

#### 6.3 Technical Readiness
The attributes required for robust automated duplicate detection are available:
1. Geodesic distance calculation via Haversine formula ($D \le 500\text{ m}$).
2. Observation time intervals ($|\Delta t| \le 60\text{ min}$) for post-migration submissions.
3. Administrative section concordance (`section_id`).
4. Sighting herd size tolerance ($|\Delta N| \le 2$).

---

### 7. Seasonal & Historical Analytics Feasibility Audit

A strict feasibility determination was conducted to evaluate what analytical reports can legitimately be generated from the existing database:

| Analytical Capability | Feasibility on Existing Historical Data | Reason / Technical Limitation |
|---|:---:|---|
| **Diurnal / Hourly Sighting Distribution** | **IMPOSSIBLE** | $100\%$ of historical reports have `sighting_time = NULL`. Ingestion times (`created_at`) reflect developer test runs, not wildlife activity. |
| **Daily / Weekly Sighting Trends** | **IMPOSSIBLE** | All 43 operational records were created on a single day (8 Nov 2026). Zero multi-day longitudinal spread exists. |
| **Monthly Sighting Trends** | **IMPOSSIBLE** | Zero multi-month operational data exists. |
| **Seasonal Trends (Yala vs. Maha)** | **IMPOSSIBLE** | Sri Lanka's Southwest Monsoon (Yala: May–Aug) and Northeast Monsoon (Maha: Oct–Jan) cannot be inferred from a single day's test data. Any seasonal conclusions would be scientifically fabricated. |
| **Elephant Movement Vectors / Herd Tracking** | **IMPOSSIBLE** | Movement tracking requires sequential observation timestamps and identified herd IDs. Neither exists in historical records. |
| **Corridor Spatial Density Analysis** | **PARTIALLY FEASIBLE** | Reports can be mapped relative to known railway sections and reference hotspots, but reflects test distribution rather than genuine wildlife density. |
| **Reporting Lag Analysis** | **FEASIBLE FOR NEW DATA** | The schema and indexing (`idx_elephant_reports_lag_analysis`) support calculating $\Delta t = \text{created\_at} - \text{sighting\_time}$, but yields NULL for historical records. |
| **AI Advisory Performance Analytics** | **FEASIBLE** | 6 photographic test reports possess full inference metadata (confidence, bounding boxes, execution times). |

---

### 8. Historical Incident Validation Audit

Inspection of `incident_history` confirmed:
- **Total Records:** **3**
  1. ID 1: `2026-03-12` — Critical collision involving Udayadevi Express (Section 1).
  2. ID 2: `2026-05-02` — Medium severity near-miss involving Night Mail train (Section 2).
  3. ID 3: `2026-06-10` — False alarm sighting (buffalo grazing, Section 3).
- **Data Limitations:**
  - Zero latitude or longitude coordinates recorded.
  - Zero exact clock times (date only).
  - No locomotive ID, train number, or speed at impact recorded.
  - Zero foreign key links connecting incident records to `elephant_reports` or `alerts`.
- **Validation Readiness:**
  - These 3 records represent mock seed data.
  - **They cannot be used as an independent statistical ground truth to validate predictive collision models.**

---

### 9. Database Performance & Indexing Audit

Inspection of database indexes confirmed that **10 indexes** exist on `elephant_reports`:
1. `PRIMARY` (`id`)
2. `section_id` (`section_id`)
3. `reported_by` (`reported_by`)
4. `idx_elephant_reports_coords` (`latitude`, `longitude`)
5. `idx_elephant_reports_ai_status` (`ai_status`)
6. `idx_elephant_reports_duplicate` (`is_potential_duplicate`, `duplicate_cluster_id`)
7. `idx_elephant_reports_sighting_date` (`sighting_date`)
8. `idx_elephant_reports_temporal_hourly` (`sighting_date`, `sighting_time`, `sighting_time_precision`)
9. `idx_elephant_reports_section_temporal` (`section_id`, `sighting_date`, `sighting_time`)
10. `idx_elephant_reports_lag_analysis` (`sighting_date`, `created_at`)

**Performance Findings:**
- For current scale (43 rows), all queries execute in $< 1\text{ ms}$.
- When real field reporting scales into thousands of records, analytics queries will require composite indexes on `(section_id, report_status, sighting_date)` and read-only aggregation views.

---

### 10. Audit Recommendations for Stage 4

1. **Strict Provenance Filtering:**  
   Stage 4 analytics queries must include an explicit provenance filter (e.g., `WHERE id > 56` or `reported_by NOT IN (test_accounts)`) so development test triads do not distort operational reports.
2. **Non-Destructive Duplicate Clustering:**  
   Implement a clustering service that assigns `duplicate_cluster_id` and flags `is_potential_duplicate = 1` without deleting or merging raw reports.
3. **No False Seasonal Claims:**  
   The UI must explicitly display *"Insufficient Historical Data for Seasonal Trends"* until multi-month field data accumulates.
4. **Coordinate Protection (Anti-Poaching):**  
   Fuzz or jitter raw GPS coordinates ($> 1\text{ km}$ grid aggregation) for public and non-wildlife users to prevent poachers from tracking elephant herds.
