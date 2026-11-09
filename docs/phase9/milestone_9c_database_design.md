# EleGuard Rail — Milestone 9C: Database Design & Migration Strategy (Refined)

**Document:** `docs/phase9/milestone_9c_database_design.md`  
**Phase:** 9 — Historical Elephant Sighting Intelligence  
**Milestone:** 9C — Temporal & Seasonal Intelligence  
**Status:** DESIGN-ONLY (Migration Specification; Verified on Isolated Clone; Not Executed on Operational DB)  
**Target Table:** `elephant_reports` in `eleguard_rail`  
**Execution Sequence:** Migration 03 (following Migration 01 `01_add_gps_coordinates_to_elephant_reports.sql` and Migration 02 `02_add_ai_detection_metadata_to_elephant_reports.sql`)

---

## 1. Technical Evaluation: Representation of Sighting Time

Four distinct architectural approaches were evaluated for representing sighting time in relational MariaDB:

| Approach | Storage Representation | Advantages | Disadvantages | Verdict |
|:---|:---|:---|:---|:---:|
| **Option A: Single Combined DATETIME** | `sighting_timestamp DATETIME NULL` | Standard SQL date-time functions; single column | Cannot represent "known date, unknown time" without fabricating `00:00:00`; cannot represent approximate time windows without external metadata | **REJECTED** |
| **Option B: UTC Unix Epoch Integer** | `sighting_epoch_ms BIGINT NULL` | Standard for raw sensor hardware; timezone agnostic | Incompatible with MariaDB native date/month/season partitioning; cannot model "approximate dusk" or "date only"; unreadable in SQL console | **REJECTED** |
| **Option C: Separate DATE and TIME with Precision Enum** | `sighting_date DATE NULL`<br>`sighting_time TIME NULL`<br>`sighting_time_precision ENUM(...)`<br>`sighting_timezone VARCHAR(32)` | **Perfect real-world fit:** allows date without time (`precision='unknown'`); allows approximate time; supports direct seasonal and hourly indexing; human-readable; lossless | Requires coordinated columns | **RECOMMENDED & EXPANDED** |
| **Option D: Dual DATETIME Range Interval** | `sighting_window_start DATETIME`<br>`sighting_window_end DATETIME` | Mathematically models uncertainty intervals | Cumbersome SQL queries; complex UI inputs; over-engineered for field rangers | **REJECTED** |

### Refined Extension: Quantitative Uncertainty Interval & Ecological Windows
To prevent approximate presets (e.g. "Dusk", "Dawn") from masquerading as exact observations, Option C is enhanced with two explicit uncertainty attributes:
1. `sighting_time_window ENUM('dawn', 'morning', 'afternoon', 'dusk', 'night', 'custom') NULL`: Captures the ecological time period.
2. `sighting_time_uncertainty_minutes INT NULL`: Captures the quantitative bounding radius ($\pm \Delta t$ in minutes).
- **Exact Sighting (07:45 AM):** `sighting_time = '07:45:00'`, `precision = 'exact'`, `uncertainty_minutes = 5`.
- **Approximate Sighting (Dusk):** `sighting_time = '18:15:00'`, `precision = 'approximate'`, `window = 'dusk'`, `uncertainty_minutes = 45` ($\implies [17:30, 19:00]$).
- **Unknown Time Sighting:** `sighting_time = NULL`, `precision = 'unknown'`, `window = NULL`, `uncertainty_minutes = NULL`.

---

## 2. Proposed Database Columns

The following 7 columns are designed for addition to `elephant_reports`:

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

### Detailed Field Specifications

| Column Name | Data Type | Nullable | Default | Definition & Functional Purpose |
|:---|:---|:---:|:---:|:---|
| **`sighting_date`** | `DATE` | YES | `NULL` | The actual calendar date when the elephant was sighted (`YYYY-MM-DD` in `Asia/Colombo`). **Remains NULL for historical records** unless independently verified. |
| **`sighting_time`** | `TIME` | YES | `NULL` | The reference central time of day when the sighting occurred (`HH:MM:SS`). Strictly `NULL` when time is unknown. |
| **`sighting_time_precision`** | `ENUM('exact', 'approximate', 'unknown')` | NO | `'unknown'` | Categorical confidence level: <br>• `exact`: Observer noted the specific clock time ($\pm 5\text{ mins}$).<br>• `approximate`: Observer estimated a general time window.<br>• `unknown`: Observation hour was not recorded. |
| **`sighting_time_window`** | `ENUM('dawn', 'morning', 'afternoon', 'dusk', 'night', 'custom')` | YES | `NULL` | Standardized ecological observation window for approximate sightings. |
| **`sighting_time_uncertainty_minutes`** | `INT` | YES | `NULL` | Explicit uncertainty radius in minutes ($\pm \Delta t$). |
| **`sighting_timezone`** | `VARCHAR(32)` | NO | `'Asia/Colombo'` | The canonical IANA timezone for the observation. Default is `'Asia/Colombo'` (UTC+05:30). |
| **`gps_captured_at`** | `DATETIME` | YES | `NULL` | Hardware timestamp acquired from client device Geolocation API (`position.timestamp`). |

---

## 3. Index Design for High-Performance Analytics

To ensure rapid filtering across large-scale historical datasets and high-concurrency locomotive dispatch lookups, four targeted indexes are specified:

```sql
-- 1. Index on sighting_date for seasonal, monthly, and date-range queries
ALTER TABLE elephant_reports
    ADD INDEX idx_elephant_reports_sighting_date (sighting_date);

-- 2. Composite index for circadian hourly distribution and temporal risk analytics
ALTER TABLE elephant_reports
    ADD INDEX idx_elephant_reports_temporal_hourly (sighting_date, sighting_time, sighting_time_precision);

-- 3. Composite index for section-level seasonal risk profiling
ALTER TABLE elephant_reports
    ADD INDEX idx_elephant_reports_section_temporal (section_id, sighting_date, sighting_time);

-- 4. Audit index for telemetry latency / reporting lag analysis
ALTER TABLE elephant_reports
    ADD INDEX idx_elephant_reports_lag_analysis (sighting_date, created_at);
```

---

## 4. Refined Migration Script (`03_add_temporal_intelligence_to_elephant_reports.sql`)

Below is the verified, non-destructive SQL migration script for future execution:

```sql
-- =========================================================================
-- EleGuard Rail - Migration 03: Temporal & Seasonal Intelligence (Refined)
-- Non-destructive addition of sighting timestamps and precision metadata
-- Designed in Milestone 9C: Temporal & Seasonal Intelligence
-- =========================================================================

USE eleguard_rail;

-- 1. Add temporal columns to elephant_reports
ALTER TABLE elephant_reports
    ADD COLUMN sighting_date DATE NULL DEFAULT NULL AFTER elephant_count,
    ADD COLUMN sighting_time TIME NULL DEFAULT NULL AFTER sighting_date,
    ADD COLUMN sighting_time_precision ENUM('exact', 'approximate', 'unknown') NOT NULL DEFAULT 'unknown' AFTER sighting_time,
    ADD COLUMN sighting_time_window ENUM('dawn', 'morning', 'afternoon', 'dusk', 'night', 'custom') NULL DEFAULT NULL AFTER sighting_time_precision,
    ADD COLUMN sighting_time_uncertainty_minutes INT NULL DEFAULT NULL AFTER sighting_time_window,
    ADD COLUMN sighting_timezone VARCHAR(32) NOT NULL DEFAULT 'Asia/Colombo' AFTER sighting_time_uncertainty_minutes,
    ADD COLUMN gps_captured_at DATETIME NULL DEFAULT NULL AFTER gps_accuracy_meters;

-- 2. Add performance indexes for temporal analytics
ALTER TABLE elephant_reports
    ADD INDEX idx_elephant_reports_sighting_date (sighting_date),
    ADD INDEX idx_elephant_reports_temporal_hourly (sighting_date, sighting_time, sighting_time_precision),
    ADD INDEX idx_elephant_reports_section_temporal (section_id, sighting_date, sighting_time),
    ADD INDEX idx_elephant_reports_lag_analysis (sighting_date, created_at);

-- 3. Historical Data Preservation Policy
-- CRITICAL REFINEMENT: Do NOT set sighting_date = DATE(created_at).
-- created_at represents the server row insertion instant, NOT proof of sighting date.
-- All existing 43 records naturally inherit NULL for sighting_date, NULL for sighting_time,
-- and 'unknown' for sighting_time_precision without fabricating historical timestamps.
-- created_at and updated_at remain 100% immutable.
```

---

## 5. Refined Rollback Script (`rollback_03_temporal_intelligence.sql`)

```sql
-- =========================================================================
-- EleGuard Rail - Rollback Migration 03: Temporal & Seasonal Intelligence
-- Reverses schema alterations introduced by Migration 03
-- =========================================================================

USE eleguard_rail;

-- 1. Drop temporal performance indexes
ALTER TABLE elephant_reports
    DROP INDEX idx_elephant_reports_lag_analysis,
    DROP INDEX idx_elephant_reports_section_temporal,
    DROP INDEX idx_elephant_reports_temporal_hourly,
    DROP INDEX idx_elephant_reports_sighting_date;

-- 2. Drop temporal columns (lossless for all non-temporal operational columns)
ALTER TABLE elephant_reports
    DROP COLUMN gps_captured_at,
    DROP COLUMN sighting_timezone,
    DROP COLUMN sighting_time_uncertainty_minutes,
    DROP COLUMN sighting_time_window,
    DROP COLUMN sighting_time_precision,
    DROP COLUMN sighting_time,
    DROP COLUMN sighting_date;
```

---

## 6. Strict Historical Data Preservation Rules

### Rule 1: `created_at` Is Never Assumed to Be Sighting Date
The server ingestion timestamp (`created_at`) represents the millisecond when the web application saved a row into MariaDB. For automated test artifacts (33 rows) or retrospective historical field reports (IDs 1–3), `created_at` reflects when software executed. Treating `DATE(created_at)` as the genuine wildlife sighting date would manufacture fake biological encounters.

### Rule 2: Explicit NULL State for Unverified Baseline Records
In Migration 03, all 43 existing records will retain:
- `sighting_date = NULL`
- `sighting_time = NULL`
- `sighting_time_precision = 'unknown'`
- `sighting_time_window = NULL`
- `sighting_time_uncertainty_minutes = NULL`

If and only if archival records or field station logbooks independently confirm the actual encounter date for a historical report, an authorized wildlife administrator can update `sighting_date` via a documented administrative verification audit.

### Rule 3: Application-Layer Graceful Degradation
- In user dashboards and map views, if `sighting_date` is `NULL`, the UI displays `"Sighting Date: Unrecorded (Logged on YYYY-MM-DD)"`.
- In analytics queries, records with `sighting_date IS NULL` are excluded from seasonal/monthly graphs, and records with `sighting_time IS NULL` are excluded from hourly circadian curves.

---

## 7. Dry-Run Verification on Isolated Test Database

The refined migration and rollback were tested against an isolated clone `eleguard_rail_test` on MariaDB port 3308:
- **Migration Execution:** Succeeded with zero errors; all 7 columns and 4 indexes added cleanly.
- **Historical Record Audit:** 43 / 43 records preserved with `sighting_date IS NULL`, `sighting_time IS NULL`, and `precision = 'unknown'`.
- **Synthetic Insertion Verification:** Successfully inserted and queried exact, approximate, and unknown temporal records.
- **Rollback Execution:** Succeeded with zero errors; schema reverted to identical pre-migration structure.
- **Operational Database Integrity:** Confirmed `eleguard_rail` row counts remained 100% untouched at 43 records.
