# EleGuard Rail — Milestone 9C Stage 4B
## Non-Destructive Spatial-Temporal Duplicate Detection Specification

### 1. Architectural Overview & Design Philosophy

The duplicate detection engine for EleGuard Rail (`DuplicateService.php`) is designed to address a critical challenge in railway-wildlife collision prevention: multiple observers (track inspectors, locomotive drivers, nearby villagers, and automated cameras) frequently file independent reports regarding the same elephant or elephant herd within a narrow corridor and observation window.

In high-consequence railway operations, **premature, automated, or destructive deduplication poses severe ecological and operational safety risks**:
- Automatically merging reports risks erasing critical individual observer telemetry (differing GPS precision, timestamps, and photographs).
- Automatically rejecting or suppressing suspected duplicates risks silencing genuine reports when separate elephant herds traverse the same track section.
- Inflating operational risk scores through naive count accumulation risks triggering unnecessary emergency speed restrictions that paralyze freight and passenger schedules.

To resolve these tensions, `DuplicateService` implements a **strictly non-destructive, explainable spatial-temporal clustering and recommendation model** governed by the following core principles:

```
+----------------------------------------------------------------------------------------------------+
|                         NON-DESTRUCTIVE DUPLICATE DETECTION ARCHITECTURE                          |
+----------------------------------------------------------------------------------------------------+
|  1. NON-DESTRUCTIVE CANDIDACY: Reports are never deleted, merged, overwritten, or auto-elevated.   |
|  2. IMMUTABLE OPERATIONAL SCORING: Stored risk_score, report_status, & timestamps are unmodified. |
|  3. TEMPORAL INTEGRITY: Observation intervals [t_start, t_end] are evaluated; created_at is NEVER  |
|     substituted for missing observation times.                                                     |
|  4. PROVENANCE ISOLATION: Development triads & AI test datasets are strictly excluded from         |
|     operational candidate clustering.                                                              |
|  5. NON-TRANSITIVE PROTECTION: A ~ B and B ~ C does not blindly establish A ~ C duplication.        |
|  6. ANTI-POACHING SECURITY: Precise coordinates are fuzzed for unauthorized viewer roles.         |
|  7. HUMAN-IN-THE-LOOP SUPREMACY: Wildlife Officers retain exclusive verification authority.        |
+----------------------------------------------------------------------------------------------------+
```

---

### 2. Multi-Dimensional Matching Methodology

Two sighting reports $R_A$ and $R_B$ are evaluated across five orthogonal dimensions:

```
                                  [ Report A ]       [ Report B ]
                                        \                 /
                                         \               /
                    +---------------------------------------------------------+
                    |           Multi-Dimensional Candidate Evaluator         |
                    +---------------------------------------------------------+
                    |  1. Geographic Distance & GPS Uncertainty Range         |
                    |  2. Diurnal Observation Interval Overlap & Delta        |
                    |  3. Railway Section & Corridor Compatibility            |
                    |  4. Elephant Herd Size Difference Evaluation            |
                    |  5. Dataset Provenance & Non-Operational Filter         |
                    +---------------------------------------------------------+
                                                 │
                                                 ▼
                    +---------------------------------------------------------+
                    |             Explainable Candidate Payload               |
                    |  - Candidate Classification                             |
                    |  - Matching Evidence & Telemetry Warnings               |
                    |  - Role-Masked Coordinates Display                      |
                    |  - Actionable Human Review Recommendation               |
                    +---------------------------------------------------------+
```

#### 2.1 Geographic Distance & GPS Uncertainty Range
- **Haversine Geodesic Distance ($D$):**
  Calculates great-circle distance on the WGS 84 sphere ($R = 6,371,000\text{ m}$):
  $$\Delta \phi = \phi_2 - \phi_1, \quad \Delta \lambda = \lambda_2 - \lambda_1$$
  $$a = \sin^2\left(\frac{\Delta \phi}{2}\right) + \cos(\phi_1)\cos(\phi_2)\sin^2\left(\frac{\Delta \lambda}{2}\right)$$
  $$c = 2 \cdot \text{atan2}\left(\sqrt{a}, \sqrt{1 - a}\right), \quad D = R \cdot c$$
- **Sri Lanka Geographic Bounding Box:**
  Coordinates are strictly validated against Sri Lanka terrestrial bounds ($\text{Lat} \in [5.8, 10.0]$, $\text{Lon} \in [79.5, 82.2]$). Out-of-bounds or non-numeric coordinates return `null` and evaluate to `Insufficient evidence`.
- **GPS Uncertainty Buffer:**
  Horizonal GPS accuracy ($\text{acc}_A$, $\text{acc}_B$) expands the nominal distance into an effective interval:
  $$D_{\text{effective}} \in \left[\max(0, D - \text{acc}_A - \text{acc}_B), D + \text{acc}_A + \text{acc}_B\right]$$
  If $\text{acc}_A$ or $\text{acc}_B > 100\text{ m}$, a low-precision warning is attached to the candidate payload.

#### 2.2 Observation-Time Interval Comparison
- **Observation Intervals:**
  Each report $R$ with a recorded observation date and time has a validated uncertainty interval $[S_R, E_R]$ computed in `Asia/Colombo` SLST:
  - Exact precision: $[t_{\text{obs}}, t_{\text{obs}}]$ (with $\pm 0\text{ m}$ base uncertainty).
  - Approximate precision: $[t_{\text{ref}} - \text{unc}, t_{\text{ref}} + \text{unc}]$ where $\text{unc}$ is the explicit uncertainty minutes or diurnal preset uncertainty (e.g. Dawn: $\pm 60\text{m}$, Dusk: $\pm 45\text{m}$, Night: $\pm 120\text{m}$).
- **Interval Overlap Calculus:**
  $$\text{Overlap} \iff \max(S_A, S_B) \le \min(E_A, E_B)$$
  When overlapping, $\Delta t = 0\text{ min}$.
  When non-overlapping, the shortest boundary gap is:
  $$\Delta t = \frac{\max(0, \max(S_A, S_B) - \min(E_A, E_B))}{60} \text{ minutes}$$
- **Midnight-Crossing Invariance:**
  Timestamps are stored as epoch seconds via `DateTimeImmutable` with timezone `Asia/Colombo`. Observations crossing from 23:45 (Day $N$) to 00:15 (Day $N+1$) evaluate correctly without 24-hour wrap errors.
- **Strict Prohibition Against Ingestion Substitution:**
  If `sighting_time` is NULL or precision is `'unknown'`, the service **strictly refuses to substitute `created_at`**. The comparison is explicitly classified as `Spatial similarity only`.

#### 2.3 Railway-Section Compatibility
- Compares assigned administrative `section_id` values.
- Matching sections establish positive supporting evidence.
- Mismatched sections emit a discrepancy warning, but **never automatically disqualify** a candidate, as elephants frequently linger near mile-post borders separating administrative sections (e.g. Habarana vs Minneriya).

#### 2.4 Elephant Herd Size Comparison
- Compares reported herd sizes ($N_A, N_B$).
- If $|N_A - N_B| \le 2$, considered compatible evidence.
- If $|N_A - N_B| > 2$, candidate is preserved with an informative variance warning (reflecting potential herd splitting, calf occlusion, or differing vantage points).

#### 2.5 Provenance Awareness & Isolation
To prevent artificial test data from contaminating operational wildlife clusters, reports are automatically classified into provenance categories based on affirmative signatures and authenticated metadata (strictly without hardcoding primary key ID ranges):
- `genuine_baseline`: Historical commissioning records identified by narrative descriptions (e.g. "two adult elephants spotted drinking water", etc.).
- `genuine_field`: Authenticated field submissions from verified users (`reported_by`) with affirmative field descriptions, valid coordinates, and zero test markers.
- `development_test`: Automated test triads identified by specific test narrative signatures (`Testing text-only submission.`, `Testing GPS-enabled submission.`, `Testing manual coordinate entry.`, `automated test`, `test triad`, `minneriya track observation`, `gal oya wildlife sighting`, `mock`, `dummy`).
- `ai_test`: Seeded photograph AI model test records identified by seeded image filenames (`report_habarana_herd.jpg`, `report_ambewela_track.jpg`, `report_minneriya_dusk.jpg`, `qa_test_elephant`, `ai_elephant_`) or AI test notes.
- `e2e_audit`: Governance and E2E audit test reports identified by `e2e-audit`, `e2e audit`, or `governance test` in location or description.
- `unknown`: Reports lacking verifiable credentials or affirmative text signatures default strictly to `unknown` without being falsely assumed genuine or falsely assumed test.

**Invariant Rule:** Comparisons involving any test/demo provenance are immediately classified as `Excluded test/demo comparison` and blocked from operational clustering. Comparisons involving unknown provenance emit an explicit observer credential review warning without dropping the candidate. Future genuine reports are never excluded based on ID numbers.

---

### 3. Candidate Classifications & Explainability

The candidate evaluator emits one of five mutually exclusive, transparent classifications:

| Classification | Spatial Criterion | Temporal Criterion | Section / Count | Provenance | Review Action |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Strong candidate** | $D \le 250\text{ m}$ | Overlapping or $\Delta t \le 30\text{ m}$ | Section matches, $\Delta N \le 2$ | Both operational | High duplicate probability. Recommend officer inspect photos/notes. |
| **Possible candidate** | $D \le 500\text{ m}$ | Overlapping or $\Delta t \le 60\text{ m}$ | Section mismatch or $\Delta N > 2$ allowed | Both operational | Telemetry variance detected. Field verification recommended. |
| **Spatial similarity only** | $D \le 500\text{ m}$ | Observation time unknown or missing | Any | Both operational | Proximate locations, but temporal correlation unverified. Do not merge. |
| **Insufficient evidence** | $D > 500\text{ m}$ or invalid GPS | $\Delta t > 60\text{ m}$ or missing telemetry | Any | Any | Independent sightings preserved. |
| **Excluded test/demo** | Any | Any | Any | Test/demo artifact | Non-operational artifact. Excluded from wildlife clusters. |

---

### 4. Non-Transitive Clustering Engine

A classic pitfall in naive spatial-temporal clustering is **transitive chaining (multi-hop contamination)**:
Suppose Report A is at km 140 (09:00), Report B is at km 140.4 (09:20), and Report C is at km 140.8 (09:40).
- $A \sim B$ satisfies proximity ($380\text{m} \le 500\text{m}$) and temporal threshold ($20\text{m} \le 60\text{m}$).
- $B \sim C$ satisfies proximity ($450\text{m} \le 500\text{m}$) and temporal threshold ($20\text{m} \le 60\text{m}$).
- However, $A$ and $C$ are separated by $830\text{m}$ (exceeding cluster diameter) and $40\text{m}$.
In naive union-find clustering, $A, B, C$ are collapsed into a single cluster, falsely asserting that $A$ and $C$ are duplicates of each other!

`DuplicateService::clusterReports()` prevents transitive chaining through **Diameter and Temporal Span Bounding**:
1. When evaluating candidate additions to an existing cluster, every prospective member must satisfy:
   $$\max_{k \in C} \text{Distance}(\text{candidate}, k) \le D_{\text{max\_diameter}} \quad (750\text{ m})$$
   $$\max_{k \in C} \Delta t(\text{candidate}, k) \le T_{\text{max\_span}} \quad (90\text{ min})$$
2. If adding Report C would cause the cluster diameter or time span to exceed these limits, Report C is **rejected from joining Cluster 1**.
3. The rejection is formally logged in `transitive_splits` with an explicit mathematical justification.

---

### 5. Security & Privacy: Anti-Poaching Coordinate Masking

To prevent poachers and unauthorized parties from abusing real-time or historical elephant coordinate telemetry:
- **Authorized Roles:** `admin`, `wildlife_officer`, `control_officer` receive exact, unmasked high-precision GPS coordinates (`is_masked = false`).
- **Unauthorized Roles:** `reporter`, `driver`, unauthenticated/public users receive fuzzed coordinates rounded to 2 decimal places ($\approx \pm 1.1\text{ km}$ spatial blur):
  $$\text{Lat}_{\text{masked}} = \text{round}(\text{Lat}, 2), \quad \text{Lon}_{\text{masked}} = \text{round}(\text{Lon}, 2)$$
  An accompanying notice `Precise coordinates masked for wildlife protection (anti-poaching policy)` is attached to the payload.

---

### 6. Verification & Automated Test Results

The automated test suite `tests/test_milestone_9c_stage4b_duplicate_service.php` covers all 18 mandatory operational, mathematical, and safety scenarios:

| Group | Scenarios Tested | Assertions | Status |
| :--- | :--- | :---: | :---: |
| **0. Pre-Flight Safety** | Operational database baseline counts (`reports=43`, `logs=185`, `alerts=15`, `#1=Medium/Pending`) | 5 | **PASS** |
| **1. Identical Points** | Identical GPS (0.0m) & exact observation times (overlap) $\rightarrow$ Strong candidate | 4 | **PASS** |
| **2. Nearby Points** | Proximity $\le 250$m, overlapping intervals, herd count diff = 1 $\rightarrow$ Strong candidate | 4 | **PASS** |
| **3. Unknown Time** | Proximity $\le 500$m, unknown observation time $\rightarrow$ Spatial similarity only (no created_at substitution) | 4 | **PASS** |
| **4. Distant Points** | Distant points (> 5km) in same section $\rightarrow$ Insufficient evidence | 2 | **PASS** |
| **5. Cross-Section** | Proximity $\le 250$m across section borders $\rightarrow$ Possible candidate with section warning | 4 | **PASS** |
| **6. Missing GPS** | Missing latitude/longitude $\rightarrow$ Insufficient evidence with telemetry warning | 3 | **PASS** |
| **7. Invalid Coords** | Out-of-bounds coordinates (0.0, 0.0) rejected by Sri Lanka bounding box | 3 | **PASS** |
| **8. Poor GPS** | Accuracy > 100m expands effective distance range; low-precision warning emitted | 3 | **PASS** |
| **9. Presets** | Dusk preset (18:15 $\pm 45$m) overlaps custom interval $\rightarrow$ Strong candidate | 2 | **PASS** |
| **10. Midnight Crossing** | Observation interval spanning 23:45 (Day $N$) to 00:05 (Day $N+1$) correctly overlaps | 3 | **PASS** |
| **11. Herd Variance** | Herd count diff = 5 does not disqualify; classified as Possible candidate with warning | 3 | **PASS** |
| **12. Test Provenance** | Dev triads (IDs 4–40) & AI tests classified as Excluded test/demo comparison | 5 | **PASS** |
| **13. Unknown Prov** | Missing metadata resolves to unknown provenance without false exclusion | 2 | **PASS** |
| **14. Transitive Split** | Three points A-B-C with $D(A,B) \le 500$, $D(B,C) \le 500$, but $D(A,C) > 750\text{m}$ safely split | 5 | **PASS** |
| **15. Flag Schema** | Existing `is_potential_duplicate` and `duplicate_cluster_id` schema access verified | 2 | **PASS** |
| **16. Empty Result** | Empty candidate pools and empty report arrays handled gracefully | 2 | **PASS** |
| **17. RBAC & Masking** | Reporter/Driver/Public coordinates fuzzed to 2 decimals; Wildlife/Control/Admin exact | 8 | **PASS** |
| **18. DB Immutability** | `assignClustersInDatabase` throws exception on operational DB; 0 risk score changes in test DB | 6 | **PASS** |
| **20. Hardening Audit** | Provenance ID independence, coordinate precision, cluster diameter/span limits, API RBAC | 19 | **PASS** |
| **19. Post-Test Audit** | Operational DB row counts remain strictly `43`, `185`, `15`; Report #1 pristine | 6 | **PASS** |
| **TOTAL** | **20 Test Groups Across All Requirements** | **95 / 95** | **100% PASS** |

---

### 7. Known Operational Limitations

1. **Historical Baseline Reports (IDs 1–3):**
   Baseline operational reports have NULL observation dates and times. Under Stage 4B safety rules, these reports can never establish temporal overlap and will strictly evaluate as `Spatial similarity only` if compared against proximate reports.
2. **GPS Horizonal Drift:**
   Consumer smartphones in dense rainforest canopy can exhibit horizontal drift exceeding 150 metres. While the effective distance range accounts for this uncertainty, field confirmation by Wildlife Officers remains essential.
3. **Multi-Observer Vantage Points:**
   Observers on opposite sides of a railway embankment may report different herd counts due to visual occlusion. The service preserves duplicate candidates despite count variance.
