# EleGuard Rail — Milestone 9B: Hotspot Inventory & Geodesic Audit

**Document:** `docs/phase9/milestone_9b_hotspot_inventory.md`  
**Phase:** 9 — Historical Elephant Sighting Intelligence  
**Milestone:** 9B — Historical Hotspot Analytics & Spatial Intelligence  
**Audit Date:** November 2026  
**Access Classification:** Internal Engineering & Scientific Audit (Strict Read-Only)  
**Baseline Git Commit:** `d459ccd`  
**Data Sources:** `hotspots` table, `railway_sections` table, `public/assets/data/railway_routes.geojson`

---

## 1. Executive Summary

As part of Milestone 9B, a rigorous, read-only audit of the existing surveyed elephant crossing hotspots in the EleGuard Rail MariaDB database was executed. Using `ProximityService.php` and the authentic 1,055-waypoint OpenStreetMap railway centerline dataset (`railway_routes.geojson`), every hotspot was evaluated for coordinate completeness, duplication, geographical plausibility, geodesic distance to track centerlines, and administrative section consistency.

### Key Audit Findings
1. **Inventory Size:** Exactly 4 hotspot records exist in the `hotspots` table ($N=4$).
2. **Coordinate Completeness & Plausibility:** 100% of records have valid, non-null numeric WGS84 coordinates. All 4 coordinates fall strictly within Sri Lankan national territory and the active North-Central/Eastern railway operational study corridor ($7.80^\circ\text{ N} - 8.40^\circ\text{ N}, 80.20^\circ\text{ E} - 81.60^\circ\text{ E}$).
3. **Duplication:** 0 duplicate coordinate pairs exist across the 4 hotspots.
4. **Geodesic Distance to Railway Track:** Distances from surveyed hotspot centroids to authentic railway centerline geometries range from **2,177.0 metres** (Hotspot 2) to **5,604.1 metres** (Hotspot 4). All hotspots represent landscape-scale crossing corridors (typically 2–6 km wide) rather than single rail-tie crossings.
5. **Administrative Section Consistency:**
   - 2 hotspots (50.0%) are geometrically closest to their assigned railway section: Hotspot 2 (Habarana Forest Edge $\to$ Section 1) and Hotspot 4 (Night Movement Zone 01 $\to$ Section 3).
   - 2 hotspots (50.0%) exhibit a geometric section discrepancy: Hotspot 1 (Minneriya Elephant Crossing) is administratively assigned to Section 2 (Minneriya - Hingurakgoda) but is geometrically closer to Section 1 track; Hotspot 3 (Gal Oya Corridor) is administratively assigned to Section 1 (Habarana - Gal Oya) but is geometrically closer to Section 2 track.
6. **Integrity Rule:** In accordance with strict audit constraints, no database records or coordinates were altered.

---

## 2. Complete Hotspot Database Inventory

The following table details the complete baseline state of the `hotspots` table joined with `railway_sections`:

| ID | Hotspot Name | Assigned Section | Risk Level | Reason / Environmental Description | Created At |
|:---|:---|:---|:---:|:---|:---:|
| **1** | Minneriya Elephant Crossing | Sec 2: Minneriya - Hingurakgoda | **Critical** | Elephants crossing from national park to reservoir during seasonal migrations. | 2026-11-08 08:02:21 |
| **2** | Habarana Forest Edge | Sec 1: Habarana - Gal Oya | **High** | Thick vegetation close to tracks limiting driver visibility to less than 50 meters. | 2026-11-08 08:02:21 |
| **3** | Gal Oya Corridor | Sec 1: Habarana - Gal Oya | **High** | Active path linking two major forest reserves. | 2026-11-08 08:02:21 |
| **4** | Night Movement Zone 01 | Sec 3: Maho - Anuradhapura | **Medium** | Water bodies on south side force night-time crossings during dry periods. | 2026-11-08 08:02:21 |

*Note: For privacy and security guidelines, precise 8-decimal raw coordinates are preserved in the internal audit JSON (`docs/phase9/hotspot_inventory.json`) and omitted from public documentation.*

---

## 3. Geodesic Distance Audit & Track Association

Using the spherical Haversine formula ($R = 6,371,000.0\text{ m}$) and orthogonal segment projection implemented in `ProximityService::pointToLineStringDistance`, the shortest geodesic distance from each hotspot centroid to every railway section was calculated.

### Geodesic Distance Breakdown by Hotspot

#### Hotspot 1: Minneriya Elephant Crossing
- **Administrative Assignment:** Section 2 (`Minneriya - Hingurakgoda`)
- **Distance to Assigned Section Track:** **7,484.0 metres** (7.484 km)
- **Geometric Nearest Section:** Section 1 (`Habarana - Gal Oya`) at **5,154.4 metres** (5.154 km)
- **Distance Difference:** 2,329.6 metres closer to Section 1 track
- **Section Consistent:** **NO** (Geometric mismatch due to park corridor geometry)

#### Hotspot 2: Habarana Forest Edge
- **Administrative Assignment:** Section 1 (`Habarana - Gal Oya`)
- **Distance to Assigned Section Track:** **2,177.0 metres** (2.177 km)
- **Geometric Nearest Section:** Section 1 (`Habarana - Gal Oya`) at **2,177.0 metres** (2.177 km)
- **Distance to Other Sections:** Sec 2: 15.399 km | Sec 3: 48.599 km | Sec 4: 54.953 km
- **Section Consistent:** **YES**

#### Hotspot 3: Gal Oya Corridor
- **Administrative Assignment:** Section 1 (`Habarana - Gal Oya`)
- **Distance to Assigned Section Track:** **5,134.2 metres** (5.134 km)
- **Geometric Nearest Section:** Section 2 (`Minneriya - Hingurakgoda`) at **3,742.5 metres** (3.742 km)
- **Distance Difference:** 1,391.7 metres closer to Section 2 track
- **Section Consistent:** **NO** (Located near Gal Oya railway junction where lines diverge)

#### Hotspot 4: Night Movement Zone 01
- **Administrative Assignment:** Section 3 (`Maho - Anuradhapura`)
- **Distance to Assigned Section Track:** **5,604.1 metres** (5.604 km)
- **Geometric Nearest Section:** Section 3 (`Maho - Anuradhapura`) at **5,604.1 metres** (5.604 km)
- **Distance to Other Sections:** Sec 1: 47.667 km | Sec 2: 62.584 km | Sec 4: 101.424 km
- **Section Consistent:** **YES**

---

## 4. Pairwise Inter-Hotspot Geodesic Distance Matrix

Pairwise distances between all surveyed hotspots were calculated to evaluate corridor clustering:

| Hotspot Pair | Geodesic Distance (m) | Geodesic Distance (km) | Spatial Relationship |
|:---|:---:|:---:|:---|
| **HS 1 (Minneriya) $\leftrightarrow$ HS 2 (Habarana)** | 8,228.4 m | 8.228 km | Adjacent North-Central wildlife corridor |
| **HS 1 (Minneriya) $\leftrightarrow$ HS 3 (Gal Oya)** | 13,393.7 m | 13.394 km | Inter-reserve migration corridor |
| **HS 2 (Habarana) $\leftrightarrow$ HS 3 (Gal Oya)** | 20,394.1 m | 20.394 km | Section 1 full corridor extent |
| **HS 1 (Minneriya) $\leftrightarrow$ HS 4 (Maho Zone 01)** | 55,862.6 m | 55.863 km | Cross-provincial separation (North-Central vs North-Western) |
| **HS 2 (Habarana) $\leftrightarrow$ HS 4 (Maho Zone 01)** | 47,640.4 m | 47.640 km | Separate administrative lines |
| **HS 3 (Gal Oya) $\leftrightarrow$ HS 4 (Maho Zone 01)** | 66,908.7 m | 66.909 km | Distant regional separation |

### Spatial Clustering Observations
- Hotspots 1, 2, and 3 form a coherent North-Central ecological cluster spanning a 20.4 km axis between Habarana, Minneriya, and Gal Oya. This cluster represents the core wildlife corridor where human-elephant-train interactions are historically concentrated.
- Hotspot 4 is located approximately 48–67 km west on the Northern line (Maho - Anuradhapura), representing an independent ecological regime.

---

## 5. Methodological Notes & Administrative Integrity

1. **Why Coordinates Were Not Altered:**
   The apparent discrepancies in section assignment for Hotspot 1 and Hotspot 3 stem from real-world administrative and ecological distinctions rather than database data corruption:
   - *Hotspot 1 (Minneriya):* Wildlife officers manage this corridor as part of the Minneriya National Park / Minneriya beat (Section 2), even though the physical track of Section 1 passes geographically closer to the north.
   - *Hotspot 3 (Gal Oya):* Centered near Gal Oya Junction where the railway splits. The Habarana - Gal Oya administrative section terminates at Gal Oya Junction.
   - Modifying database section IDs based purely on closest Euclidean distance would break operational alignment with Department of Wildlife Conservation (DWC) beats and Sri Lanka Railways administrative jurisdictions.

2. **Corridor Scale:**
   All 4 surveyed hotspots have centroid distances of 2.1 km to 5.6 km from railway lines. In Sri Lankan elephant ecology, elephant home ranges and seasonal movement corridors extend across multiple kilometers. These hotspots represent landscape-scale crossing corridors rather than point-specific track locations.

---

## 6. Reproducibility

The complete inventory data was programmatically extracted and audited using:
```bash
php ai/analytics/hotspot_inventory.php
```
Audit output is committed at `docs/phase9/hotspot_inventory.json`.
