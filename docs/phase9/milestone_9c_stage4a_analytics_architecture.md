# EleGuard Rail — Milestone 9C Stage 4A
## Historical Intelligence & Analytics Subsystem Architecture

**Project:** EleGuard Rail (Sri Lanka Wildlife-Railway Collision Early Warning Platform)  
**Evaluated Commit:** `c56d5cd`  
**Branch:** `main`  
**Environment:** PHP 8.2 / MariaDB 10.4 (Port 3308) / Apache (XAMPP)  
**Date:** 10 November 2026  
**Status:** ARCHITECTURAL DESIGN COMPLETE (Zero Code Modifications in Stage 4A)  

---

### 1. Architectural Vision & Objectives

The Stage 4 Historical Intelligence and Analytics Subsystem transforms fragmented wildlife field observations, railway section telemetry, and early warning alerts into actionable spatial-temporal intelligence.

#### Key System Objectives:
1. **Corridor Hazard Intelligence:** Identify spatial collision hot-zones and track segments with recurring elephant activity.
2. **Diurnal Activity Profiling:** Analyze field observation times across the 24-hour cycle to identify peak nocturnal crossing windows.
3. **Reporting Lag Telemetry:** Quantify communication latency between field sighting occurrences and system alert broadcasts.
4. **Non-Destructive Sighting Deduplication:** Automatically detect and cluster overlapping sighting reports without deleting raw submissions.
5. **Human-in-the-Loop Governance:** Restrict sensitive wildlife coordinates and preserve officer review authority over analytical interpretations.

---

### 2. End-to-End Analytics Data Flow

```
+----------------------------------------------------------------------------------------------------+
| 1. Field Sighting Ingestion Layer                                                                  |
| - Community Web Portal & Ranger Field Mobile Interface                                             |
| - Structured Temporal Metadata: Sighting Date, Clock Time, Precision, Uncertainty                  |
| - Spatial Telemetry: WGS-84 Coordinates, Hardware Accuracy, Landmark Description                   |
+-------------------------------------------------+--------------------------------------------------+
                                                  │
                                                  ▼
+----------------------------------------------------------------------------------------------------+
| 2. Provenance Normalization & Filtering Layer                                                      |
| - Provenance Partitioning: Historical Baseline (IDs 1-3) vs Verified Field Reports vs Test Runs   |
| - Coordinate Sanity Filter: Sri Lanka Bounding Box (Lat: 5.9-9.9°N, Lon: 79.5-81.9°E)             |
| - Temporal Bounds Validation: Reject future timestamps, flag chronology inconsistencies             |
+-------------------------------------------------+--------------------------------------------------+
                                                  │
                                                  ▼
+----------------------------------------------------------------------------------------------------+
| 3. Spatial-Temporal Clustering & Deduplication Engine (Stage 4B)                                   |
| - Geodesic Proximity: Haversine distance threshold (D_thresh <= 500m)                              |
| - Temporal Window: Observation interval overlap threshold (|Delta t| <= 60 min)                   |
| - Non-Destructive Association: Populates duplicate_cluster_id and flags is_potential_duplicate      |
+-------------------------------------------------+--------------------------------------------------+
                                                  │
                                                  ▼
+----------------------------------------------------------------------------------------------------+
| 4. Analytical Aggregation & Metrics Engine (Stage 4C)                                              |
| - Spatial Aggregations: Corridor density, hotspot proximity, track-section risk ranking            |
| - Temporal Aggregations: Diurnal hourly distribution, twilight ratios, reporting lag metrics      |
| - Data Hygiene Safeguard: Zero seasonal claims without multi-month longitudinal spread             |
+-------------------------------------------------+--------------------------------------------------+
                                                  │
                                                  ▼
+----------------------------------------------------------------------------------------------------+
| 5. Role-Governed Presentation Layer (Stage 4D)                                                     |
|                                                                                                    |
| [Wildlife Officer Analytics]       [Control Desk Analytics]        [Driver Cab / Public Feeds]     |
| - Raw GPS coordinates & heatmaps   - Corridor hazard ranking       - Aggregated grid metrics only  |
| - Herd size & behavioral trends    - Alert response lag times      - Sensitive coordinates masked  |
| - Sighting deduplication review    - Collision prevention index    - Zero raw sighting clutter     |
+----------------------------------------------------------------------------------------------------+
```

---

### 3. Role-Based Access Control (RBAC) & Anti-Poaching Privacy

Because wild elephants in Sri Lanka face severe threats from poaching, human-elephant conflict retaliation, and illegal capture, wildlife location telemetry is highly sensitive.

#### 3.1 Role Access Matrix

| System Domain / Feature | Wildlife Officer | Control Officer | Train Driver | Community Reporter | System Admin |
|---|:---:|:---:|:---:|:---:|:---:|
| **Raw Precision GPS Coordinates** | **FULL** | **FULL** | MASKED | MASKED (Own only) | **FULL** |
| **High-Resolution Spatial Heatmaps** | **FULL** | **FULL** | REGIONAL | REGIONAL | **FULL** |
| **Sighting Deduplication Adjudication** | **MANAGE** | VIEW ONLY | NONE | NONE | **MANAGE** |
| **Diurnal Activity Histograms** | **FULL** | **FULL** | SUMMARY | SUMMARY | **FULL** |
| **Reporting Latency Lag Metrics** | **FULL** | **FULL** | NONE | NONE | **FULL** |
| **Incident History & Validation** | **FULL** | **FULL** | READ-ONLY | NONE | **FULL** |
| **Raw Photo Attachments** | **FULL** | **FULL** | NONE | OWN ONLY | **FULL** |

#### 3.2 Anti-Poaching Coordinate Masking Rules
- For **Drivers and Public/Community Users**, exact latitude and longitude values must never be rendered in HTML, API responses, or map tooltips.
- The system applies **spatial fuzzing (1.0 km grid truncation)**:
  $$\text{Lat}_{\text{masked}} = \text{round}(\text{Lat}, 2), \quad \text{Lon}_{\text{masked}} = \text{round}(\text{Lon}, 2)$$
- Only verified Wildlife Officers and Railway Control Officers possess permission to view sub-meter GPS fixes.

---

### 4. Proposed Duplicate Detection Methodology

#### 4.1 Multi-Dimensional Similarity Metric
Two sighting reports $R_A$ and $R_B$ are evaluated for duplicate candidacy using four orthogonal criteria:

```
+----------------------------------------------------------------------------------+
| Duplicate Sighting Affinity Score: S_dup in [0.0, 1.0]                           |
+----------------------------------------------------------------------------------+
| 1. Spatial Affinity S_dist:                                                      |
|    - If Distance(A, B) <= 250m: S_dist = 1.0                                     |
|    - If 250m < Distance(A, B) <= 750m: S_dist = 1.0 - (Distance - 250) / 500     |
|    - If Distance(A, B) > 750m: S_dist = 0.0                                      |
|                                                                                  |
| 2. Temporal Interval Overlap S_time:                                             |
|    - Evaluates overlap between [t_start_A, t_end_A] and [t_start_B, t_end_B]     |
|    - If intervals overlap: S_time = 1.0                                          |
|    - If |Delta t| <= 60 min: S_time = 1.0 - (|Delta t| / 60)                     |
|    - If |Delta t| > 60 min: S_time = 0.0                                         |
|                                                                                  |
| 3. Corridor Consistency S_sec:                                                   |
|    - Matching section_id: 1.0; Adjacent section: 0.5; Mismatched: 0.0            |
|                                                                                  |
| 4. Herd Size Tolerance S_count:                                                  |
|    - |count_A - count_B| == 0: 1.0; |Delta| == 1: 0.8; |Delta| >= 3: 0.2         |
+----------------------------------------------------------------------------------+
```

#### 4.2 Non-Destructive Cluster Assignment
- If overall similarity score $S_{\text{dup}} \ge 0.75$:
  - A new or existing `duplicate_cluster_id` is assigned.
  - `is_potential_duplicate` is set to `1`.
  - **No records are deleted, merged, or overwritten.**
  - An informational badge is displayed in the review interface: `[⚠️ Potential Duplicate Sighting — Cluster #X]`.
- Wildlife Officers retain sole authority to confirm or dismiss duplicate candidacy during verification.

---

### 5. Proposed Aggregation & Historical Analytics Methodology

#### 5.1 Temporal Aggregations
1. **Diurnal Hourly Distribution:**
   - Aggregates sightings into 24 one-hour bins based on **observation time** (`sighting_time`), completely ignoring system ingestion time.
   - Computes Nocturnal Hazard Ratio:
     $$R_{\text{night}} = \frac{N_{\text{night}} (19:00\text{--}05:00) + N_{\text{twilight}} (05:00\text{--}07:00, 17:00\text{--}19:00)}{N_{\text{total}}}$$
2. **Reporting Delay Lag Metric:**
   - Calculates communication latency:
     $$\Delta t_{\text{lag}} = \text{created\_at} - \text{sighting\_time}$$
   - Segregates real-time alerts ($< 15\text{ min}$), operational delays ($15\text{--}60\text{ min}$), and stale submissions ($> 2\text{ hours}$).
3. **Seasonal Aggregations (Future Field Data):**
   - Strictly disabled until multi-month data exists. When active, partitions data into:
     - **Southwest Monsoon (Yala):** May through August
     - **Northeast Monsoon (Maha):** October through January
     - **Inter-monsoonal dry periods:** February–April, September

#### 5.2 Spatial Corridor Aggregations
1. **Section Hazard Index ($H_{\text{sec}}$):**
   $$H_{\text{sec}} = \frac{\sum_{i=1}^n S_{\text{risk}}(i) \times \text{ElephantCount}(i)}{\text{Length of Section (km)}}$$
2. **Hotspot Proximity Buffers:**
   - Analyzes sighting density in concentric geodesic buffers ($500\text{ m}$, $1\text{ km}$, $3\text{ km}$) around known high-risk track bends.

---

### 6. Incident Validation Architecture

To bridge the gap between sighting early warnings and actual railway outcomes, Stage 4 designs a formal **Collision & Incident Outcome Registry**:

#### 6.1 Proposed Incident Record Schema Upgrades (Stage 4 Schema Design)
- Link incidents directly to nearby sighting reports: `associated_report_id` (nullable foreign key).
- Link incidents to active railway alerts: `associated_alert_id` (nullable foreign key).
- Record exact collision coordinates: `latitude`, `longitude`, `kilometer_post`.
- Record locomotive details: `train_number`, `locomotive_id`, `train_speed_kmh`.
- Record outcome classification: `'Fatal Collision'`, `'Non-Fatal Injury'`, `'Near-Miss (Emergency Stop)'`, `'False Alarm'`.

#### 6.2 Model Evaluation Metric
Enables independent validation of the risk engine:
$$\text{Warning Lead Time} = \text{Incident Time} - \text{Alert Broadcast Time}$$
$$\text{Corridor Warning Precision} = \frac{\text{True Threat Sightings}}{\text{Total Warning Broadcasts}}$$

---

### 7. Future Implementation Sequence (Stage 4 Roadmap)

```
Milestone 9C Stage 4 Implementation Sequence
├── Stage 4A: Historical Data Quality & Analytics Architecture Audit (COMPLETE)
│
├── Stage 4B: Spatial-Temporal Clustering & Non-Destructive Duplicate Detection Service
│    ├── Implement DuplicateService.php
│    ├── Geodesic Haversine & temporal interval clustering algorithms
│    ├── Cluster ID assignment & review badge display
│    └── Comprehensive automated test suite on isolated database
│
├── Stage 4C: Analytics Query Layer, Read-Only Aggregations & Lag Telemetry
│    ├── Implement AnalyticsService.php
│    ├── Diurnal hourly distribution & reporting lag aggregations
│    ├── Provenance-filtered spatial density queries
│    └── Zero-write automated test suite
│
├── Stage 4D: User-Facing Analytics Dashboards & Anti-Poaching Masking
│    ├── Wildlife Officer Intelligence Dashboard (Heatmaps, Herd Distributions)
│    ├── Control Officer Corridor Safety Dashboard (Hazard Ranks, Lag Times)
│    └── Anti-poaching coordinate masking for driver/public endpoints
│
└── Stage 4E: Final Independent Verification & Security Audit
     ├── Complete regression test execution across all project suites
     ├── Operational database zero-write invariant verification
     └── Desktop / XAMPP SHA-256 parity verification
```

---

### 8. Architectural Governance Summary

Stage 4A establishes the necessary architectural discipline to ensure that:
1. Analytical findings never confuse developer test triads with genuine elephant movements.
2. Duplicate reports are flagged non-destructively for human review.
3. Sensitive wildlife coordinates remain protected against poachers.
4. Scientific claims regarding seasonal behavior and collision prevention remain honest, rigorous, and evidence-grounded.
