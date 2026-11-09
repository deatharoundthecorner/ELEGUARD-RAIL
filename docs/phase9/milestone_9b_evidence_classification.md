# EleGuard Rail — Milestone 9B: Hotspot Evidence Classification

**Document:** `docs/phase9/milestone_9b_evidence_classification.md`  
**Phase:** 9 — Historical Elephant Sighting Intelligence  
**Milestone:** 9B — Historical Hotspot Analytics & Spatial Intelligence  
**Audit Date:** November 2026  
**Access Classification:** Internal Engineering & Scientific Audit (Strict Read-Only)  
**Baseline Git Commit:** `d459ccd`  
**Data Sources:** `docs/phase9/hotspot_evidence_classification.json`, `docs/phase9/hotspot_inventory.json`, `docs/phase9/hotspot_spatial_analysis.json`

---

## 1. Executive Summary

Milestone 9B establishes an evidence-based classification hierarchy for all surveyed elephant crossing hotspots in the EleGuard Rail platform.

### Scientific Grounding Principle
A core engineering guideline of Milestone 9B is **honesty and scientific integrity in reporting**. We explicitly reject the manufacture of artificial numerical "hotspot confidence scores" (e.g., claiming "94.2% confidence") when the underlying genuine historical dataset comprises only three ($N=3$) legacy records, all of which lack GPS coordinates.

Instead, we employ a descriptive, four-tier evidence classification framework based on empirical ground-truth verification.

---

## 2. The Four-Tier Evidence Classification Framework

| Tier Level | Classification Label | Definition & Criteria | Operational Implication |
|:---:|:---|:---|:---|
| **Tier 1** | **Existing Mapped Hotspot** | Location formally recorded in the database based on wildlife ranger surveys, track history, or Department of Wildlife Conservation (DWC) expert knowledge. | Retained in operational GIS map; triggers driver caution zones. |
| **Tier 2** | **Historical Evidence-Supported Hotspot** | Hotspot supported by multiple, independently verified genuine field observations with validated GPS coordinates. | Validated high-priority intervention zone for acoustic/visual warning systems. |
| **Tier 3** | **Candidate Area** | Area exhibiting emerging clusters of verified sightings requiring dedicated field survey to establish formal boundaries. | Queued for wildlife ranger ground truthing and camera trap monitoring. |
| **Tier 4** | **Insufficient Evidence** | Recorded or proposed location lacking sufficient empirical field sightings or GPS data to scientifically confirm movement frequency or corridor boundaries. | Maintained under administrative caution; flagged for baseline data collection. |

---

## 3. Evidence Classification of Monitored Hotspots

Each of the four hotspots in the `hotspots` table was evaluated against this framework:

### Hotspot 1: Minneriya Elephant Crossing
- **Assigned Section:** Section 2 (`Minneriya - Hingurakgoda`)
- **Recorded Risk Level:** **Critical**
- **Administrative Status:** **Tier 1 — Existing Mapped Hotspot**
- **Empirical Evidence Tier:** **Tier 4 — Insufficient Empirical Evidence**
- **Detailed Evaluation:**
  - *Database & Field Survey:* Formally surveyed corridor documented in DWC records as a major crossing path between Minneriya National Park and the reservoir.
  - *Genuine Field Sightings:* Historical baseline Report ID 2 cites "Near Minneriya reservoir bund", providing qualitative confirmation. However, zero genuine records contain GPS coordinates.
  - *Synthetic Data:* 11 automated test runs and 1 demo report (ID 42) are located within 50 metres, but these are software artifacts.
  - *Empirical Verdict:* Retain as an Existing Mapped Hotspot based on surveyed baseline. Empirical corridor modeling is not possible until GPS-enabled field reporting begins.

### Hotspot 2: Habarana Forest Edge
- **Assigned Section:** Section 1 (`Habarana - Gal Oya`)
- **Recorded Risk Level:** **High**
- **Administrative Status:** **Tier 1 — Existing Mapped Hotspot**
- **Empirical Evidence Tier:** **Tier 4 — Insufficient Empirical Evidence**
- **Detailed Evaluation:**
  - *Database & Field Survey:* Formally recorded crossing zone where dense jungle vegetation reduces train driver sightlines to under 50 metres.
  - *Genuine Field Sightings:* Historical baseline Report ID 1 cites "Near mile post 127/4" in Section 1, qualitatively aligning with this forest corridor. GPS coordinates are absent.
  - *Audit & Demo Data:* Demo report ID 41 is 562 metres away; 4 audit verification runs (Milestone 8C) were submitted ~109 metres away.
  - *Empirical Verdict:* Retain as an Existing Mapped Hotspot. High operational priority for locomotive speed restrictions due to visibility hazards.

### Hotspot 3: Gal Oya Corridor
- **Assigned Section:** Section 1 (`Habarana - Gal Oya`)
- **Recorded Risk Level:** **High**
- **Administrative Status:** **Tier 1 — Existing Mapped Hotspot**
- **Empirical Evidence Tier:** **Tier 4 — Insufficient Empirical Evidence**
- **Detailed Evaluation:**
  - *Database & Field Survey:* Formally recorded junction corridor linking two major forest reserves near Gal Oya Junction.
  - *Genuine Field Sightings:* Historical baseline Report ID 3 cites "Near Gal Oya junction approach" in Section 1, qualitatively aligning with this zone. GPS coordinates are absent.
  - *Synthetic Data:* 11 automated test runs were executed at this exact point (0.0 m), but these are synthetic test records.
  - *Empirical Verdict:* Retain as an Existing Mapped Hotspot. Recommended for inter-departmental boundary alignment between Section 1 and Section 2.

### Hotspot 4: Night Movement Zone 01
- **Assigned Section:** Section 3 (`Maho - Anuradhapura`)
- **Recorded Risk Level:** **Medium**
- **Administrative Status:** **Tier 1 — Existing Mapped Hotspot**
- **Empirical Evidence Tier:** **Tier 4 — Insufficient Evidence (Zero Observational Support)**
- **Detailed Evaluation:**
  - *Database & Field Survey:* Formally recorded dry-season nocturnal crossing zone along the Maho - Anuradhapura line.
  - *Genuine Field Sightings:* Zero historical reports exist in the database for Section 3 or within 45 km of this hotspot.
  - *Synthetic Data:* Zero test or demo reports exist in the database for Section 3.
  - *Empirical Verdict:* Retain as an Existing Mapped Hotspot based on initial survey. Flagged for targeted field verification by Northern line wildlife officers.

---

## 4. Comprehensive Hotspot Classification Matrix

| Hotspot ID | Hotspot Name | Risk Level | Administrative Tier | Empirical Evidence Tier | Genuine Historical Sighting Support | Synthetic Test Artifacts ($N$) | Actionable Recommendation |
|:---:|:---|:---:|:---:|:---:|:---|:---:|:---|
| **1** | Minneriya Elephant Crossing | Critical | **Tier 1 (Mapped)** | **Tier 4 (Insufficient)** | Qualitative match (Report ID 2: "Near Minneriya reservoir bund", no GPS) | $N=12$ (11 test, 1 demo) | Retain in GIS map; deploy mobile app for GPS field verification. |
| **2** | Habarana Forest Edge | High | **Tier 1 (Mapped)** | **Tier 4 (Insufficient)** | Qualitative match (Report ID 1: "Near mile post 127/4", no GPS) | $N=5$ (1 demo, 4 audit) | Retain in GIS map; conduct authorized environmental and railway field assessment before interventions. |
| **3** | Gal Oya Corridor | High | **Tier 1 (Mapped)** | **Tier 4 (Insufficient)** | Qualitative match (Report ID 3: "Near Gal Oya junction approach", no GPS) | $N=11$ (11 test) | Retain in GIS map; clarify junction section boundary. |
| **4** | Night Movement Zone 01 | Medium | **Tier 1 (Mapped)** | **Tier 4 (Zero Support)** | None ($0$ reports in database for Section 3) | $N=0$ | Retain in GIS map; conduct field survey to re-verify seasonal usage. |

---

## 5. Candidate Area Assessment

The audit investigated whether any new "Candidate Areas" (Tier 3) could be derived from the current database.

### Audit Findings on Candidate Areas
1. **Historical Baseline Reports:**
   The three historical baseline reports point to three qualitative locations (Mile Post 127/4, Minneriya Reservoir Bund, Gal Oya Junction Approach). Each of these three locations already corresponds directly to one of the three surveyed hotspots (Hotspots 2, 1, and 3 respectively).
2. **Cluster Analysis on Test Data:**
   While clusters exist around coordinates $(8.04125, 80.82564)$ and $(8.12560, 80.91240)$, these are automated test artifacts resulting from 11 uncleaned runs of `tests/test_gps_and_workflows.php`. Declaring these clusters as "new candidate hotspots" would be a catastrophic methodology error (confusing software test artifacts with wild elephants).
3. **Conclusion:**
   **No new candidate hotspot coordinates can be legitimately declared at this time**. New candidate areas must await authentic mobile field reports.

---

## 6. Recommendations for Milestone 9C & Field Operations

1. **Maintain Surveyed Baseline:** All 4 existing mapped hotspots should remain active in the EleGuard Rail operational database and GIS map displays. They represent surveyed ecological knowledge that must not be discarded simply because historical digital logging was incomplete.
2. **Prioritize Mobile GPS Adoption:** Wildlife officers operating in Sections 1 and 2 should be equipped with the EleGuard Rail mobile reporting interface to capture high-accuracy GPS coordinates for all future sightings.
3. **Preserve Operational Safety:** In the absence of dense empirical sighting data, early warning alerts must continue to be driven by:
   - Locomotive proximity to surveyed hotspots.
   - Verified real-time sighting reports.
   - Wildlife officer patrol advisories.

---

## 7. Reproducibility

The evidence classification dataset was produced by:
```bash
python ai/analytics/hotspot_analytics_report.py
```
Output data is saved at `docs/phase9/hotspot_evidence_classification.json`.
