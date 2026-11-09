# EleGuard Rail — Milestone 9C: Temporal & Seasonal Intelligence Architecture (Refined)

**Document:** `docs/phase9/milestone_9c_architecture.md`  
**Phase:** 9 — Historical Elephant Sighting Intelligence  
**Milestone:** 9C — Temporal & Seasonal Intelligence (Technical Design & Migration Strategy)  
**Status:** DESIGN-ONLY (Strict Read-Only Inspection; No Code or Database Modifications)  
**Target Environments:**
- Development Repository: `C:\Users\user\Desktop\Railway\eleguard-rail`
- Production / XAMPP Deployment: `C:\xampp\htdocs\eleguard-rail`
- Reference Timezone: `Asia/Colombo` (UTC+05:30)

---

## 1. Executive Summary & Problem Formulation

The EleGuard Rail platform was engineered as a real-time early warning system to protect wild Asian elephants (*Elephas maximus maximus*) and passenger locomotives from catastrophic collisions across Sri Lanka's railway network.

While previous milestones successfully integrated GPS spatial geometry (Milestones 6 & 7), Edge AI computer vision detection (Milestones 8A–8C), and spatial hotspot analytics (Milestones 9A & 9B), **the platform currently lacks an authentic temporal data model for wildlife observations**.

### The Temporal Conflation Problem
In the current architecture, sighting records conflate two fundamentally different events into a single timestamp:
1. **The Physical Ecological Event:** When an elephant or herd was physically observed near or traversing the railway line.
2. **The System Ingestion Event:** When the reporter or ranger gained cellular/internet connectivity and submitted the HTTP POST request into the database (`created_at`).

```
ACTUAL FIELD REALITY:
[ Elephant Sighting ] --------------------> [ Connectivity Gained ] --------> [ System Ingestion ]
    02:30 AM (Night)                             08:15 AM (Morning)                 08:30 AM (Morning)
 (High Ecological Risk)                       (Travel to Range Office)          (Saved to Database)

CURRENT SYSTEM ASSUMPTION:
Record Created: 08:30 AM  ===>  Assumes Sighting Occurred at 08:30 AM (Daytime)!
Misses +2 Night Risk Score! Distorts Hourly Activity Distributions!
```

---

## 2. Refined Dual-Timeline Architecture Paradigm

Milestone 9C introduces a formal **Dual-Timeline Architecture** that untangles operational telematics from ecological observations:

```
+---------------------------------------------------------------------------------------------------+
|                                   ELEGUARD RAIL DUAL-TIMELINE MODEL                               |
+---------------------------------------------------------------------------------------------------+
|                                                                                                   |
|  [ ECOLOGICAL TIMELINE ] (When the Elephant Appeared)                                             |
|  * sighting_date                    : DATE NULL (e.g. 2026-11-09)                                 |
|  * sighting_time                    : TIME NULL (e.g. 02:45:00)                                 |
|  * sighting_time_precision          : ENUM ('exact', 'approximate', 'unknown')                  |
|  * sighting_time_window             : ENUM ('dawn', 'morning', 'afternoon', 'dusk', 'night',..) |
|  * sighting_time_uncertainty_minutes : INT NULL (Explicit uncertainty radius in minutes)         |
|  * sighting_timezone                : VARCHAR(32) ('Asia/Colombo')                              |
|  * gps_captured_at                  : DATETIME NULL (Hardware GPS fix time)                     |
|                                                                                                   |
|         | Drives:                                                                                 |
|         +---> RiskService Nocturnal Bonus (+2 for verified night sightings)                       |
|         +---> Hourly & Circadian Movement Analytics (Peak crossing hours)                         |
|         +---> Seasonal & Monsoonal Migration Intelligence (Yala / Maha regimes)                   |
|         +---> Locomotive Speed Advisory Windows                                                   |
|                                                                                                   |
|  -----------------------------------------------------------------------------------------------  |
|                                                                                                   |
|  [ OPERATIONAL / AUDIT TIMELINE ] (When the System Processed the Data)                            |
|  * created_at                       : DATETIME (Server insertion instant - IMMUTABLE)             |
|  * updated_at                       : DATETIME (Officer verification/rejection - IMMUTABLE)       |
|  * ai_processed_at                  : DATETIME (YOLOX-Tiny inference completion - IMMUTABLE)     |
|                                                                                                   |
|         | Drives:                                                                                 |
|         +---> Dispatch & Driver Alert Broadcast Latency                                           |
|         +---> Reporting Lag / Telemetry Latency Metric (created_at - sighting_time)               |
|         +---> Immutable Regulatory & Legal Audit Trails                                           |
|         +---> System Event Logging (system_logs table)                                            |
|                                                                                                   |
+---------------------------------------------------------------------------------------------------+
```

---

## 3. Core Architectural Refinements

### 3.1 Historical Data Preservation: Zero False Timestamps
A fundamental principle of the refined design is that **`created_at` is NEVER treated as proof of actual sighting date**:
- For all 43 existing records in `elephant_reports`, `sighting_date` remains strictly `NULL`, `sighting_time` remains `NULL`, and `sighting_time_precision` is set to `'unknown'`.
- No bulk SQL `UPDATE` will backfill `sighting_date = DATE(created_at)`.
- This preserves absolute scientific truth: historical paper ledger records and automated test artifacts do not possess authenticated sighting dates in the database.

### 3.2 Approximate Time Uncertainty Modeling
To prevent approximate presets from masquerading as exact observations:
- Each approximate sighting records both a reference central time and a quantitative uncertainty interval:
  - **Dawn:** Reference 05:30 $\pm 60$ minutes (Interval: 04:30 to 06:30).
  - **Morning:** Reference 09:00 $\pm 90$ minutes (Interval: 07:30 to 10:30).
  - **Afternoon:** Reference 14:30 $\pm 90$ minutes (Interval: 13:00 to 16:00).
  - **Dusk:** Reference 18:15 $\pm 45$ minutes (Interval: 17:30 to 19:00).
  - **Night:** Reference 22:00 $\pm 120$ minutes (Interval: 20:00 to 00:00).
  - **Custom:** User specifies time + chosen uncertainty window.
- The precision flag `sighting_time_precision = 'approximate'` informs analytics engines to apply uncertainty error bars rather than treating points as clock-exact.

### 3.3 Risk Service Nocturnal Bonus Safety Policy
In `RiskService.php`, the night hazard bonus (+2) is safeguarded by strict policy rules:
1. **Exact Sighting:** Receives +2 bonus if `sighting_time` is between 18:00:00 and 05:59:59.
2. **Approximate Sighting Policy:**
   - **Full Night Interval:** If the entire uncertainty window $[\text{time} - \Delta, \text{time} + \Delta]$ falls strictly within 18:00–06:00 (e.g. Night preset: 20:00–00:00), the **+2 bonus is awarded**.
   - **Boundary Straddling Intervals:** If the uncertainty window straddles day and night (e.g. Dusk 17:30–19:00 or Dawn 04:30–06:30), the system adopts a **conservative non-bonus policy** (or crepuscular advisory) to prevent artificial risk inflation.
3. **Unknown Sighting Time:** NEVER receives the nighttime bonus. Evaluates the baseline score safely.
4. **Submission Time Substitution Prohibited:** `created_at` is never used as a proxy for sighting time.

### 3.4 Canonical Timezone Architecture
- All client inputs, server calculations, and dashboard displays operate under `Asia/Colombo` (UTC+05:30).
- Hardware GPS timestamps (`position.timestamp` DOMTimeStamp) are converted to `Asia/Colombo` at ingestion.
- The system explicitly avoids silently combining incomplete dates/times into artificial UTC timestamps.
