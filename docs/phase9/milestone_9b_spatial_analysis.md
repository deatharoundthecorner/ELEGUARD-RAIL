# EleGuard Rail — Milestone 9B: Report-to-Hotspot Spatial Analysis

**Document:** `docs/phase9/milestone_9b_spatial_analysis.md`  
**Phase:** 9 — Historical Elephant Sighting Intelligence  
**Milestone:** 9B — Historical Hotspot Analytics & Spatial Intelligence  
**Audit Date:** November 2026  
**Access Classification:** Internal Engineering & Scientific Audit (Strict Read-Only)  
**Baseline Git Commit:** `d459ccd`  
**Data Sources:** `docs/phase9/hotspot_spatial_analysis.json`, `docs/phase9/hotspot_inventory.json`, `docs/phase9/elephant_reports_extracted.json`

---

## 1. Executive Summary

Milestone 9B performed a complete geodesic distance analysis linking sighting reports to surveyed hotspots. In adherence to strict scientific rigor, the analysis enforces an uncompromising separation between:
1. **Primary Analysis Population:** Confirmed genuine historical baseline records ($N=3$).
2. **Supplementary Analysis Population:** Test harness, demonstration, and audit verification records possessing GPS coordinates ($N=29$).

### Critical Methodological Finding
All three ($N=3$) confirmed historical baseline elephant sighting reports (IDs 1, 2, and 3) are text-only field records created prior to mobile GPS integration. Because these records contain no numeric GPS coordinates (`latitude = NULL, longitude = NULL`), **geodesic point-to-point and point-to-line distance calculations cannot be performed on the genuine historical baseline without fabricating data**.

The 29 reports with numerical GPS coordinates originate entirely from automated test executions (22 reports), demonstration submissions (3 reports), and Milestone 8C verification audits (4 reports). While these records provide valuable data for testing the spatial engine's mathematical correctness, **they must never be confused with or presented as authentic wild elephant field observations**.

---

## 2. Primary Analysis: Confirmed Historical Baseline ($N=3$)

The primary historical population consists of three legacy sighting reports submitted by field officers in the North-Central railway corridor:

| Report ID | Assigned Section | Location Description | Urgency | Status | GPS Available | Spatial Viability |
|:---:|:---|:---|:---:|:---:|:---:|:---|
| **1** | Sec 1: Habarana - Gal Oya | Near mile post 127/4 | High | Verified | **NO** (`NULL`) | Qualitative Section Alignment Only |
| **2** | Sec 2: Minneriya - Hingurakgoda | Near Minneriya reservoir bund | Critical | Verified | **NO** (`NULL`) | Qualitative Section Alignment Only |
| **3** | Sec 1: Habarana - Gal Oya | Near Gal Oya junction approach | High | Verified | **NO** (`NULL`) | Qualitative Section Alignment Only |

### Scientific Evaluation of Baseline Records
1. **Qualitative Corridor Alignment:**
   - **Report ID 1** cites "Near mile post 127/4" along Section 1. This corresponds geographically to the Habarana forest section near Hotspot 2 (Habarana Forest Edge).
   - **Report ID 2** cites "Near Minneriya reservoir bund" along Section 2. This corresponds directly to the Minneriya crossing zone near Hotspot 1 (Minneriya Elephant Crossing).
   - **Report ID 3** cites "Near Gal Oya junction approach" along Section 1. This corresponds directly to Hotspot 3 (Gal Oya Corridor).
2. **Sample Size & Mathematical Limitation:**
   - With an active sample size of $N=3$ records and zero coordinate pairs, it is mathematically impossible to construct empirical spatial density surfaces, kernel density estimations (KDE), or statistical movement models.
   - Any assertion that the historical database statistically validates the surveyed hotspot boundaries would be scientifically invalid.
   - The three baseline reports provide valuable qualitative confirmation that the three North-Central hotspots (Hotspots 1, 2, and 3) reflect real-world conflict areas known to local wildlife staff, but empirical validation requires a modern GPS-enabled field logging campaign.

---

## 3. Supplementary Analysis: GPS-Enabled Population ($N=29$)

A total of 29 reports in the database contain valid numerical GPS coordinates. These were analyzed using spherical Haversine geodesics against all four surveyed hotspots.

### Provenance Composition of GPS Records
- **Confirmed Automated Test Harness Artifacts:** $N=22$ (75.9%)
- **Inferred Demonstration Records:** $N=3$ (10.3%)
- **Milestone 8C E2E Audit Runs:** $N=4$ (13.8%)
- **Total GPS Reports:** $N=29$ (100.0%)

### Distance Band Distribution to Nearest Hotspot
Distances from the 29 GPS reports to their nearest surveyed hotspot were stratified into standardized ecological distance bands:

| Distance Band | Definition | Report Count ($N=29$) | Percentage | Provenance Nature |
|:---|:---|:---:|:---:|:---|
| **$< 25\text{ m}$ (Co-located)** | Nominal GPS accuracy limit | **22** | **75.9%** | Automated test harness runs (IDs 5, 6, 8, 9, 11, 12, 14, 15, 17, 18, 20, 21, 23, 24, 26, 27, 29, 30, 32, 33, 39, 40) |
| **$25\text{ m} - 100\text{ m}$ (Immediate Vicinity)** | Direct corridor proximity | **1** | **3.4%** | Inferred demo report (ID 42 at 48.7 m from Hotspot 1) |
| **$100\text{ m} - 1\text{ km}$ (Corridor Buffer)** | Local crossing corridor | **5** | **17.2%** | 4 audit reports (IDs 53–56 at 108.9 m from HS 2) + 1 demo (ID 41 at 562.1 m from HS 2) |
| **$1\text{ km} - 5\text{ km}$ (Landscape Scale)** | Intermediate habitat range | **0** | **0.0%** | — |
| **$5\text{ km} - 20\text{ km}$ (Regional Scale)** | Inter-hotspot traverse | **1** | **3.4%** | Inferred demo report (ID 43 at 5.3 km from HS 3 and 8.6 km from HS 1) |
| **$> 20\text{ km}$ (Distant)** | Outside corridor cluster | **0** | **0.0%** | — |

### Spatial Clustering Explanation
The striking concentration of 22 reports within 25 metres of Hotspots 1 and 3 is completely explained by the software test architecture:
- 11 reports were created by test case 1 of `tests/test_gps_and_workflows.php`, which specified coordinates $(8.04125^\circ\text{ N}, 80.82564^\circ\text{ E})$ — located **7.1 metres** from Hotspot 1 centroid $(8.04120^\circ\text{ N}, 80.82560^\circ\text{ E})$.
- 11 reports were created by test case 2 of `tests/test_gps_and_workflows.php`, which specified coordinates $(8.12560^\circ\text{ N}, 80.91240^\circ\text{ E})$ — matching Hotspot 3 centroid **exactly at 0.0 metres**.
- 4 reports were created during Milestone 8C AI runtime audits at $(8.03500^\circ\text{ N}, 80.75200^\circ\text{ E})$ — located **108.9 metres** from Hotspot 2 centroid $(8.03540^\circ\text{ N}, 80.75110^\circ\text{ E})$.

---

## 4. GPS Horizontal Uncertainty Intervals

In real-world mobile GPS positioning, reported coordinates carry horizontal uncertainty ($\Delta$). `ProximityService` models this uncertainty by computing an effective distance interval:
$$\text{Interval} = [\max(0, d - \Delta), d + \Delta]$$

Across the 29 GPS records:
- Test harness reports specified $\Delta = 5.0\text{ m}$ to $12.0\text{ m}$.
- For the 11 reports at Hotspot 1 ($d = 7.1\text{ m}$, $\Delta = 8.5\text{ m}$), the effective uncertainty interval is $[0.0\text{ m}, 15.6\text{ m}]$, indicating potential physical co-location with the hotspot centroid.
- For the 11 reports at Hotspot 3 ($d = 0.0\text{ m}$, $\Delta = 10.0\text{ m}$), the effective uncertainty interval is $[0.0\text{ m}, 10.0\text{ m}]$.
- For the 4 audit reports near Hotspot 2 ($d = 108.9\text{ m}$, $\Delta = 12.0\text{ m}$), the effective uncertainty interval is $[96.9\text{ m}, 120.9\text{ m}]$.

---

## 5. Scientific Caution & Conclusions

1. **Proximity Does Not Equal Field Observation:**
   The proximity of test records to surveyed hotspots demonstrates that test scripts were written to simulate known hotspots. It does **not** provide empirical proof that wild elephants were present at those locations on the recorded dates.
2. **Readiness for Operational Risk Engine:**
   Because genuine field sightings with GPS are currently absent ($0/3$), the operational early warning system cannot yet compute historical empirical collision probabilities. The operational risk engine must continue to rely on surveyed hotspot boundaries, driver reports, and verified real-time sightings until an empirical GPS baseline is established.
3. **Data Protection:**
   Raw coordinates of test records are documented for engineering verification but are excluded from public-facing summaries to prevent misinterpretation.

---

## 6. Reproducibility

The complete spatial analysis was generated using:
```bash
python ai/analytics/hotspot_spatial_analysis.py
```
Output data is saved at `docs/phase9/hotspot_spatial_analysis.json`.
