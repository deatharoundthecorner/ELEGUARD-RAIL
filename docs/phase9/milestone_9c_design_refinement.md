# EleGuard Rail — Milestone 9C: Technical Design Refinement & Safety Architecture

**Document:** `docs/phase9/milestone_9c_design_refinement.md`  
**Phase:** 9 — Historical Elephant Sighting Intelligence  
**Milestone:** 9C — Temporal & Seasonal Intelligence  
**Status:** DESIGN REFINEMENT COMPLETE (Awaiting Implementation Authorization)  
**Baseline Design Commit:** `0180345`  
**Operational Scope:** Design-Only Audit (Zero Operational Database or Application Code Mutations)  
**Target Environments:**
- Development Workspace: `C:\Users\user\Desktop\Railway\eleguard-rail`
- Production / XAMPP Deployment: `C:\xampp\htdocs\eleguard-rail`

---

## 1. Executive Summary & Review Motivation

Following the completion of the preliminary Milestone 9C technical design (Commit `0180345`), an independent engineering and scientific review identified four critical areas requiring architectural refinement prior to implementation authorization:
1. **Historical Data Preservation:** The preliminary migration proposed backfilling `sighting_date = DATE(created_at)`. This erroneously conflated software ingestion/test execution dates with actual biological elephant sighting dates.
2. **Approximate Time Representation:** Storing approximate presets (e.g. "Dusk = 18:15") as plain timestamps risked creating false exactitude in downstream circadian analytics.
3. **Risk Service Safety & Night Bonus Policy:** The operational risk engine must strictly govern when nocturnal bonuses (+2) are awarded to approximate, straddling, or unknown sightings.
4. **Timezone & Representation Safeguards:** The system must avoid silently synthesizing artificial UTC timestamps from incomplete or approximate local wall-clock times.

This document details the refined technical architecture that resolves all four concerns.

---

## 2. Issue Analysis & Corrected Design Specifications

### 2.1 Issue 1: Historical Data Preservation & `created_at` Assumption

#### Original Design Flaw
In the initial draft of Migration 03, the following SQL backfill was proposed:
```sql
-- FLAWED PRELIMINARY STATEMENT:
UPDATE elephant_reports
SET 
    sighting_date = DATE(created_at),
    sighting_time = NULL,
    sighting_time_precision = 'unknown'
WHERE sighting_date IS NULL;
```
- **Why This Was Scientifically Flawed:** `created_at` records when a row was inserted into the MariaDB database. In EleGuard Rail, 33 records were inserted in batch by automated test harnesses on November 8, 2026, and 3 historical baseline records (IDs 1–3) were archived on November 8, 2026 from older paper ledgers. Assuming `sighting_date = '2026-11-08'` creates false temporal claims by asserting that elephants were observed on the software execution date.

#### Corrected Design & Invariant Rules
- **No Inferred Sighting Dates:** In Migration 03, **no `UPDATE` statement will assign `DATE(created_at)` to `sighting_date`**.
- **Explicit NULL State:** All 43 existing records will retain `sighting_date = NULL`, `sighting_time = NULL`, `sighting_time_precision = 'unknown'`, `sighting_time_window = NULL`, and `sighting_time_uncertainty_minutes = NULL`.
- **Truthful Data State:** This maintains complete scientific honesty: historical paper ledger records and automated test artifacts do not possess authenticated sighting dates in the database.
- **Independent Verification Protocol:** Only when independent archival records or ranger logbooks establish genuine encounter dates may an authorized administrator update `sighting_date` through an audited administrative tool.
- **Operational Immutability:** `created_at` and `updated_at` remain 100% immutable operational timestamps.

---

### 2.2 Issue 2: Approximate Time Representation & Uncertainty Intervals

#### Original Design Flaw
The preliminary design mapped approximate presets to fixed clock values (e.g., Dusk $\to$ `18:15:00`). While `precision = 'approximate'` was recorded, naive SQL queries (`GROUP BY HOUR(sighting_time)`) would treat `18:15:00` as an exact sighting in Hour 18, generating an artificial spike at dusk.

#### Corrected Design: Dual Interval & Ecological Window Modeling
The schema is expanded to store both a reference central time and a quantitative uncertainty interval:

```sql
ALTER TABLE elephant_reports
    ADD COLUMN sighting_date DATE NULL DEFAULT NULL AFTER elephant_count,
    ADD COLUMN sighting_time TIME NULL DEFAULT NULL AFTER sighting_date,
    ADD COLUMN sighting_time_precision ENUM('exact', 'approximate', 'unknown') NOT NULL DEFAULT 'unknown' AFTER sighting_time,
    ADD COLUMN sighting_time_window ENUM('dawn', 'morning', 'afternoon', 'dusk', 'night', 'custom') NULL DEFAULT NULL AFTER sighting_time_precision,
    ADD COLUMN sighting_time_uncertainty_minutes INT NULL DEFAULT NULL AFTER sighting_time_window,
    ADD COLUMN sighting_timezone VARCHAR(32) NOT NULL DEFAULT 'Asia/Colombo' AFTER sighting_time_uncertainty_minutes,
    ADD COLUMN gps_captured_at DATETIME NULL DEFAULT NULL AFTER gps_accuracy_meters;
```

#### Standardized Preset Mappings

| Preset | Central Reference Time (`sighting_time`) | Ecological Window (`sighting_time_window`) | Uncertainty Radius (`uncertainty_minutes`) | Effective Interval ($[t-\Delta, t+\Delta]$) |
|:---|:---:|:---:|:---:|:---:|
| **Exact Clock** | User-selected HH:MM | `NULL` | $\pm 5\text{ mins}$ | $[t - 5\text{m}, t + 5\text{m}]$ |
| **Dawn Preset** | `05:30:00` | `'dawn'` | $\pm 60\text{ mins}$ | $04:30:00 - 06:30:00$ |
| **Morning Preset** | `09:00:00` | `'morning'` | $\pm 90\text{ mins}$ | $07:30:00 - 10:30:00$ |
| **Afternoon Preset** | `14:30:00` | `'afternoon'` | $\pm 90\text{ mins}$ | $13:00:00 - 16:00:00$ |
| **Dusk Preset** | `18:15:00` | `'dusk'` | $\pm 45\text{ mins}$ | $17:30:00 - 19:00:00$ |
| **Night Preset** | `22:00:00` | `'night'` | $\pm 120\text{ mins}$ | $20:00:00 - 00:00:00$ |
| **Custom Window** | User reference time | `'custom'` | User slider (15–180m) | User-defined interval |
| **Time Unknown** | `NULL` | `NULL` | `NULL` | Unbounded |

#### Analytics Treatment of Approximate Records
1. **Discrete 1-Hour Binning:** When an uncertainty window spans multiple hourly bins (e.g., Dusk 17:30–19:00 spans Hour 17 and Hour 18), density weight is attributed proportionally across overlapping hours.
2. **Broad Multi-Hour Ecological Bands:** Recommended as the primary scientific view, binning records into:
   - Dawn Crepuscular: 04:30 – 07:00
   - Daytime Foraging: 07:00 – 17:30
   - Dusk Crepuscular: 17:30 – 19:30
   - Night Crossing: 19:30 – 04:30
3. **Unknown Time Exclusion:** Records with `sighting_time IS NULL` are strictly excluded from circadian curves (never defaulted to midnight).

---

### 2.3 Issue 3: Risk Service Safety & Nocturnal Bonus Policy

#### Original Design Flaw
In `app/services/RiskService.php`, the night bonus (+2) was computed using `$hour = (int)date('H', strtotime($reportTime))`. This evaluated the submission time (`created_at`), creating false nocturnal alerts for daytime sightings submitted at night, and missing nighttime alerts for night sightings submitted the next morning.

#### Corrected Risk Engine Temporal Policy
In the refined architecture, `RiskService::calculateRiskScore` evaluates actual observation telemetry under a strict, conservative policy:

```
+----------------------------------------------------------------------------------------------------+
|                                RISK ENGINE NOCTURNAL BONUS POLICY                                  |
+----------------------------------------------------------------------------------------------------+
|                                                                                                    |
|  [ EXACT SIGHTINGS ] (precision = 'exact')                                                         |
|  * Sighting time in 18:00:00 - 05:59:59  ===>  AWARDS +2 NIGHT HAZARD BONUS                        |
|  * Sighting time in 06:00:00 - 17:59:59  ===>  0 Bonus (Standard Daytime Baseline)                |
|                                                                                                    |
|  [ APPROXIMATE SIGHTINGS ] (precision = 'approximate')                                             |
|  * Entire interval falls in 18:00 - 06:00 (e.g. Night Preset: 20:00 - 00:00)                       |
|    ===>  AWARDS +2 NIGHT HAZARD BONUS                                                              |
|  * Interval straddles day and night (e.g. Dusk 17:30 - 19:00 or Dawn 04:30 - 06:30)                |
|    ===>  CONSERVATIVE POLICY: 0 Bonus (Crepuscular Caution; Prevents False Alert Inflation)        |
|  * Entire interval falls in daytime (e.g. Morning 07:30 - 10:30)                                   |
|    ===>  0 Bonus                                                                                   |
|                                                                                                    |
|  [ UNKNOWN SIGHTING TIMES ] (precision = 'unknown' or sighting_time IS NULL)                       |
|  * Time unrecorded (Historical records IDs 1-3, batch reports)                                     |
|    ===>  NEVER AWARDS NIGHT BONUS (Preserves Existing Baseline Scores Exactly)                     |
|                                                                                                    |
|  [ STRICT PROHIBITION ]                                                                            |
|  * submission time (created_at) is NEVER substituted for missing sighting time                     |
|                                                                                                    |
+----------------------------------------------------------------------------------------------------+
```

---

### 2.4 Issue 4: Timezone Architecture & Hardware Fix Telemetry

#### Timezone Canon
- **Canonical Timezone:** Sri Lanka observes standard time (`Asia/Colombo`, UTC+05:30) year-round with zero Daylight Saving Time (DST) transitions.
- **Storage Strategy:** `sighting_date` (`DATE`) and `sighting_time` (`TIME`) are stored as local wall-clock values accompanied by `sighting_timezone VARCHAR(32) DEFAULT 'Asia/Colombo'`.
- **Avoiding Artificial UTC Synthesis:** Incomplete or approximate local times are **never silently converted into exact UTC timestamps**. Combining `'2026-11-09'` and an approximate `'dusk'` into a UTC timestamp would invent a specific millisecond in Greenwich Mean Time that never existed.

#### Device Hardware GPS Timestamp Integration
- JavaScript Geolocation captures `position.timestamp` (DOMTimeStamp from GPS satellite hardware).
- Converted at ingestion into formatted `Asia/Colombo` timestamp and stored in `gps_captured_at DATETIME NULL`.
- If client device lacks hardware fix, `gps_captured_at` remains `NULL` while coordinates are safely preserved.

---

## 3. Migration & Rollback Integrity Verification

The refined migration was tested against an isolated clone database (`eleguard_rail_test`) on MariaDB port 3308:

### Execution Summary
1. **Isolated Clone Setup:** Created `eleguard_rail_test` with identical schema and data replicated from `eleguard_rail` (43 reports, 4 hotspots).
2. **Migration 03 Application:** Executed refined `03_add_temporal_intelligence_to_elephant_reports.sql`:
   - 7 columns added cleanly.
   - 4 performance indexes created.
   - **Data Preservation Verified:** All 43 existing reports retained `sighting_date = NULL`, `sighting_time = NULL`, and `precision = 'unknown'`.
3. **Synthetic Persistence Verification:** Inserted exact (02:30 AM), approximate (Dusk $\pm 45$m), and unknown-time sightings; all queried with 100% fidelity.
4. **Rollback 03 Verification:** Executed `rollback_03_temporal_intelligence.sql`:
   - Dropped the 4 indexes and 7 columns cleanly.
   - Verified schema reverted to exact pre-migration structure.
   - Asserted zero impact on unrelated operational columns (`latitude`, `longitude`, `ai_*`, `report_status`).
5. **Operational Database Audit:** Confirmed operational database `eleguard_rail` row counts remained **100% untouched at 43 reports**.

---

## 4. Reconciled Deliverables & Documentation Index

The following refined design documents have been updated, mirrored to artifacts, and committed:
1. **Refined Design Synthesis:** [`docs/phase9/milestone_9c_design_refinement.md`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/docs/phase9/milestone_9c_design_refinement.md)
2. **Refined Database Design & Migration:** [`docs/phase9/milestone_9c_database_design.md`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/docs/phase9/milestone_9c_database_design.md)
3. **Refined Architecture Specification:** [`docs/phase9/milestone_9c_architecture.md`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/docs/phase9/milestone_9c_architecture.md)
4. **Refined Reporting Workflow & UI Design:** [`docs/phase9/milestone_9c_reporting_workflow.md`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/docs/phase9/milestone_9c_reporting_workflow.md)
5. **Refined Analytics Engine Design:** [`docs/phase9/milestone_9c_analytics_design.md`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/docs/phase9/milestone_9c_analytics_design.md)
6. **Refined Quality Assurance Test Strategy:** [`docs/phase9/milestone_9c_test_strategy.md`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/docs/phase9/milestone_9c_test_strategy.md)
7. **Refined Phased Implementation Roadmap:** [`docs/phase9/milestone_9c_implementation_roadmap.md`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/docs/phase9/milestone_9c_implementation_roadmap.md)

---

## 5. Stop Condition & Implementation Gate

Milestone 9C technical design refinement is complete.

In accordance with strict instructions:
- **No production application code has been modified.**
- **No operational database migrations have been executed.**
- **No operational records have been altered.**
- **All proposed migrations have been pre-tested and validated on an isolated clone.**

Standing by for user review and explicit authorization before commencing Milestone 9C implementation.
