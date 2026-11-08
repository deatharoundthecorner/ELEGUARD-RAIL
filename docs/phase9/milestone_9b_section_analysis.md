# EleGuard Rail — Milestone 9B: Railway Section & Mismatch Investigation

**Document:** `docs/phase9/milestone_9b_section_analysis.md`  
**Phase:** 9 — Historical Elephant Sighting Intelligence  
**Milestone:** 9B — Historical Hotspot Analytics & Spatial Intelligence  
**Audit Date:** November 2026  
**Access Classification:** Internal Engineering & Scientific Audit (Strict Read-Only)  
**Baseline Git Commit:** `d459ccd`  
**Data Sources:** `docs/phase9/hotspot_section_analysis.json`, `public/assets/data/railway_routes.geojson`, `railway_sections`, `hotspots`

---

## 1. Executive Summary

Milestone 9A identified an apparent data anomaly: **26 out of 29 GPS-enabled elephant sighting reports (89.7%) were assigned to a railway section that differed from the geometrically nearest railway track line**.

Milestone 9B conducted a spatial investigation of this phenomenon. The core finding is that **the 26/29 mismatch is not a database corruption bug or indexing error**. Rather, it is the mathematical result of an inherent tension between **administrative management boundaries** and **Euclidean nearest-track projections**.

### Key Investigation Takeaways
1. **The Origin of the Discrepancy:** The 26 mismatched reports derive directly from two surveyed hotspots whose physical coordinates lie closer to a neighbouring railway line than to their assigned administrative section line:
   - **Hotspot 1 (Minneriya Elephant Crossing):** Administratively assigned to Section 2 (*Minneriya - Hingurakgoda*), but located 5.15 km from Section 1 track and 7.48 km from Section 2 track.
   - **Hotspot 3 (Gal Oya Corridor):** Administratively assigned to Section 1 (*Habarana - Gal Oya*), but located near Gal Oya Junction where Section 2 track passes 3.74 km away versus Section 1 track at 5.13 km.
2. **Test Harness Propagation:** 22 automated test reports in `test_gps_and_workflows.php` directly reused the coordinates and administrative section IDs of Hotspots 1 and 3. When `ProximityService::calculateRailwayProximity` computed the nearest physical track, it correctly calculated the Euclidean shortest distance, generating an apparent mismatch against the administrative selection.
3. **Operational Recommendation:** Railway sections represent operational and wildlife management beats, not geometric Voronoi polygons. **Database section assignments must NOT be altered automatically**.

---

## 2. Spatial Profile of Monitored Railway Sections

The EleGuard Rail GIS engine monitors four railway sections defined in `railway_sections` and represented by 1,055 authentic track centerline waypoints in `public/assets/data/railway_routes.geojson`:

| Section ID | Section Name | Operational Termini | Monitored Districts | Waypoints | Lat Extent | Lon Extent |
|:---:|:---|:---|:---|:---:|:---:|:---:|
| **1** | Habarana - Gal Oya | Habarana Station $\to$ Gal Oya Junction | Anuradhapura / Polonnaruwa | 88 | $8.0548^\circ - 8.1457^\circ\text{ N}$ | $80.7486^\circ - 80.8674^\circ\text{ E}$ |
| **2** | Minneriya - Hingurakgoda | Minneriya Station $\to$ Hingurakgoda Station | Polonnaruwa | 128 | $8.0131^\circ - 8.1348^\circ\text{ N}$ | $80.8723^\circ - 80.9779^\circ\text{ E}$ |
| **3** | Maho - Anuradhapura | Maho Junction $\to$ Anuradhapura Station | Kurunegala / Anuradhapura | 755 | $7.8187^\circ - 8.3414^\circ\text{ N}$ | $80.2455^\circ - 80.4109^\circ\text{ E}$ |
| **4** | Batticaloa Line Forest Section | Welikanda Station $\to$ Valachchenai Station | Batticaloa | 84 | $7.9224^\circ - 7.9661^\circ\text{ N}$ | $81.2414^\circ - 81.5140^\circ\text{ E}$ |

---

## 3. The Geometry of the Discrepancy

```
             [SECTION 1: Habarana - Gal Oya Track]
            =======================================\  (Gal Oya Junction)
           /                                        \
          /                                          * Hotspot 3 (Gal Oya Corridor)
         /                                            \ (Assigned: Sec 1 | Nearest: Sec 2)
        /                                              \
       * Hotspot 2 (Habarana Edge)                      \
         (Assigned: Sec 1 | Nearest: Sec 1)              \  [SECTION 2: Minneriya - Hingurakgoda]
                                                          ======================================
                     * Hotspot 1 (Minneriya Crossing)
                       (Assigned: Sec 2 | Nearest: Sec 1)
```

### 1. Hotspot 1 (Minneriya Elephant Crossing)
- **Coordinates:** $8.0412^\circ\text{ N}, 80.8256^\circ\text{ E}$
- **Administrative Rationale:** Wildlife officers manage this crossing zone as part of the Minneriya National Park wildlife beat (associated with Section 2, Minneriya - Hingurakgoda).
- **Physical Geometry:** Section 1 track (Habarana - Gal Oya) runs east-west north of Minneriya reservoir, passing **5.15 km** north of this point. Section 2 track runs north-south east of the reservoir, passing **7.48 km** east of this point.
- **Result:** Any report placed at Hotspot 1's coordinates with Section 2 assigned is **2.33 km closer to Section 1 track than to Section 2 track**.

### 2. Hotspot 3 (Gal Oya Corridor)
- **Coordinates:** $8.1256^\circ\text{ N}, 80.9124^\circ\text{ E}$
- **Administrative Rationale:** This hotspot marks the junction approach corridor of the Habarana - Gal Oya section (Section 1).
- **Physical Geometry:** At Gal Oya, the railway branches into the Batticaloa line (Section 2 heading south to Hingurakgoda) and the Trincomalee line. Section 2's track geometry passes **3.74 km** southeast of this point, whereas Section 1's track centerline in the GeoJSON terminates **5.13 km** to the west.
- **Result:** Any report placed at Hotspot 3's coordinates with Section 1 assigned is **1.39 km closer to Section 2 track than to Section 1 track**.

---

## 4. Complete Mismatch Investigation Table ($N=26$)

All 26 mismatched GPS reports in the database were inspected and classified:

| Report IDs | Provenance | Count | Assigned Sec | Nearest Sec | Distance to Nearest | Distance to Assigned | Discrepancy | Possible Explanation | Interpretation Confidence |
|:---|:---|:---:|:---:|:---:|:---:|:---:|:---:|:---|:---:|
| **5, 8, 11, 14, 17, 20, 23, 26, 29, 32, 39** | Confirmed Automated Test | 11 | Sec 2 | Sec 1 | 5,155.4 m | 7,484.0 m | 2,328.6 m | Test harness instantiated near Hotspot 1 (Minneriya). Inherited Sec 2 administrative assignment, but Sec 1 track is physically closer. | **High** |
| **6, 9, 12, 15, 18, 21, 24, 27, 30, 33, 40** | Confirmed Automated Test | 11 | Sec 1 | Sec 2 | 3,742.5 m | 5,134.2 m | 1,391.7 m | Test harness instantiated at Hotspot 3 (Gal Oya). Inherited Sec 1 administrative assignment, but Sec 2 track is physically closer. | **High** |
| **42** | Inferred Demonstration | 1 | Sec 2 | Sec 1 | 5,183.0 m | 7,440.0 m | 2,257.0 m | Manual demonstration report near Hotspot 1 (Minneriya). Administratively Sec 2, geometrically closer to Sec 1 track. | **High** |
| **43** | Inferred Demonstration | 1 | Sec 2 | Sec 1 | 4,260.8 m | 4,458.1 m | 197.3 m | Intermediate demonstration point. Sec 1 and Sec 2 tracks are nearly equidistant (difference < 200 m). | **High** |
| **54, 56** | E2E Audit Verification | 2 | Sec 2 | Sec 1 | 2,235.6 m | 15,310.0 m | 13,074.4 m | Milestone 8C verification audit where reporter selected Section 2 while using coordinates near Habarana Forest (Sec 1). | **High** |

### Complete Match Table ($N=3$)
For scientific completeness, the three reports where assigned section matched nearest geometric section are:
- **ID 41 (Inferred Demonstration):** Assigned Sec 1, Nearest Sec 1 (Distance to track: 1,958.7 m).
- **ID 53 (E2E Audit Verification):** Assigned Sec 1, Nearest Sec 1 (Distance to track: 2,235.6 m).
- **ID 55 (E2E Audit Verification):** Assigned Sec 1, Nearest Sec 1 (Distance to track: 2,235.6 m).

---

## 5. Administrative vs Geometric Section Assignment Principles

In railway wildlife collision prevention, the relationship between a report location and a railway section involves two distinct concepts:

1. **Administrative Jurisdiction (Operational Beat):**
   - Wildlife officers and station masters operate in designated administrative beats.
   - For example, an elephant herd spotted moving towards Minneriya reservoir is managed by the Minneriya Wildlife Range Office (Section 2 jurisdiction), regardless of whether the northern track line happens to pass closer as the crow flies.
2. **Geometric Proximity (Locomotive Driver Warning):**
   - For train drivers, the critical metric is the perpendicular distance from the locomotive's physical track to the elephant.
   - A train approaching along Section 1 needs to know if an elephant is 5 km south, while a train on Section 2 needs to know if it is 7.5 km west.
3. **Preservation Rule:**
   - **Do NOT overwrite assigned administrative section IDs in the database**.
   - Instead, the EleGuard Rail platform must maintain both attributes: `assigned_section_id` (administrative beat for wildlife response) and `nearest_track_section_id` (physical track for locomotive driver early warnings).

---

## 6. Reproducibility

The complete section mismatch analysis was programmatically verified using:
```bash
python ai/analytics/hotspot_section_analysis.py
```
Output data is saved at `docs/phase9/hotspot_section_analysis.json`.
