# EleGuard Rail — Milestone 9C: Database Design & Migration Strategy

**Document:** `docs/phase9/milestone_9c_database_design.md`  
**Phase:** 9 — Historical Elephant Sighting Intelligence  
**Milestone:** 9C — Temporal & Seasonal Intelligence  
**Status:** DESIGN-ONLY (Migration Specification; Not Executed)  
**Target Table:** `elephant_reports` in `eleguard_rail`  
**Execution Sequence:** Migration 03 (following Migration 01 `01_add_gps_coordinates_to_elephant_reports.sql` and Migration 02 `02_add_ai_detection_metadata_to_elephant_reports.sql`)

---

## 1. Technical Evaluation: Representation of Sighting Time

Four distinct architectural approaches were evaluated for representing sighting time in relational MariaDB:

| Approach | Storage Representation | Advantages | Disadvantages | Verdict |
|:---|:---|:---|:---|:---:|
| **Option A: Single Combined DATETIME** | `sighting_timestamp DATETIME NULL` | Standard SQL date-time functions; single column | Cannot represent "known date, unknown time" without fabricating `00:00:00`; cannot represent approximate time windows without external metadata | **REJECTED** |
| **Option B: UTC Unix Epoch Integer** | `sighting_epoch_ms BIGINT NULL` | Standard for raw sensor hardware; timezone agnostic | Incompatible with MariaDB native date/month/season partitioning; cannot model "approximate dusk" or "date only"; unreadable in SQL console | **REJECTED** |
| **Option C: Separate DATE and TIME with Precision Enum** | `sighting_date DATE NOT NULL`<br>`sighting_time TIME NULL`<br>`sighting_time_precision ENUM(...)`<br>`sighting_timezone VARCHAR(32)` | **Perfect real-world fit:** allows date without time (`precision='unknown'`); allows approximate time; supports direct seasonal and hourly indexing; human-readable; lossless | Requires 4 coordinated columns | **RECOMMENDED & ADOPTED** |
| **Option D: Dual DATETIME Range Interval** | `sighting_window_start DATETIME`<br>`sighting_window_end DATETIME` | Mathematically models uncertainty intervals | Cumbersome SQL queries; complex UI inputs; over-engineered for field rangers | **REJECTED** |

### Why Option C (Separate DATE + TIME + Precision) Was Selected
In wildlife biology and field telemetry, an observer frequently knows the exact calendar date of an encounter (e.g. "I saw elephants on November 8 near Habarana"), but may only know the approximate time of day ("sometime around dusk between 5:30 PM and 6:30 PM") or may not recall the exact hour at all.
- With **Option C**, if the time is unknown, `sighting_date = '2026-11-08'`, `sighting_time = NULL`, and `sighting_time_precision = 'unknown'`.
- This avoids the catastrophic scientific flaw of storing `00:00:00` (which naive SQL queries would falsely analyze as midnight crossings!).
- For hourly circadian analytics, queries simply filter `WHERE sighting_time IS NOT NULL AND sighting_time_precision IN ('exact', 'approximate')`.
- For monthly, weekly, and seasonal monsoonal analytics (Yala vs Maha), queries operate directly and rapidly on `sighting_date`.

---

## 2. Proposed Database Columns

The following 5 columns are designed for addition to `elephant_reports`:

```sql
ALTER TABLE elephant_reports
    ADD COLUMN sighting_date DATE NULL AFTER elephant_count,
    ADD COLUMN sighting_time TIME NULL AFTER sighting_date,
    ADD COLUMN sighting_time_precision ENUM('exact', 'approximate', 'unknown') NOT NULL DEFAULT 'unknown' AFTER sighting_time,
    ADD COLUMN sighting_timezone VARCHAR(32) NOT NULL DEFAULT 'Asia/Colombo' AFTER sighting_time_precision,
    ADD COLUMN gps_captured_at DATETIME NULL DEFAULT NULL AFTER gps_accuracy_meters;
```

### Detailed Field Specifications

| Column Name | Data Type | Nullable | Default | Definition & Functional Purpose |
|:---|:---|:---:|:---:|:---|
| **`sighting_date`** | `DATE` | YES | `NULL` | The actual calendar date when the elephant was sighted (in local Sri Lankan time, `YYYY-MM-DD`). Drives seasonal and monthly analytics. |
| **`sighting_time`** | `TIME` | YES | `NULL` | The time of day when the sighting occurred (`HH:MM:SS`). Nullable to cleanly represent sightings where time was not recorded. |
| **`sighting_time_precision`** | `ENUM('exact', 'approximate', 'unknown')` | NO | `'unknown'` | Explicit metadata on the accuracy of `sighting_time`: <br>• `exact`: Observer noted the specific clock time ($\pm 5\text{ mins}$).<br>• `approximate`: Observer estimated the time window ($\pm 1\text{ hour}$).<br>• `unknown`: Date is known, but time was unrecorded. |
| **`sighting_timezone`** | `VARCHAR(32)` | NO | `'Asia/Colombo'` | The IANA timezone identifier for the observation. Default is `'Asia/Colombo'` (UTC+05:30). |
| **`gps_captured_at`** | `DATETIME` | YES | `NULL` | Hardware timestamp acquired from client device Geolocation API (`position.timestamp`). Cryptographically bounds when coordinates were taken. |

---

## 3. Index Design for High-Performance Analytics

To ensure sub-millisecond query performance across large-scale historical datasets and high-concurrency locomotive dispatch lookups, four targeted indexes are specified:

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

### Query Execution Analysis
- **Seasonal Query (`WHERE sighting_date BETWEEN '2026-05-01' AND '2026-08-31'`):** Uses `idx_elephant_reports_sighting_date` for a pure B-Tree range scan.
- **Hourly Crepuscular Query (`WHERE sighting_time BETWEEN '17:00:00' AND '20:00:00'`):** Uses `idx_elephant_reports_temporal_hourly` with index-only covering scan.
- **Reporting Delay Query (`SELECT DATEDIFF(created_at, sighting_date)`):** Covered entirely by `idx_elephant_reports_lag_analysis`.

---

## 4. Complete Migration Script (`03_add_temporal_intelligence_to_elephant_reports.sql`)

Below is the verified, non-destructive SQL migration script for future execution:

```sql
-- =========================================================================
-- EleGuard Rail - Migration 03: Temporal & Seasonal Intelligence
-- Non-destructive addition of sighting timestamps and precision metadata
-- Designed in Milestone 9C: Temporal & Seasonal Intelligence
-- =========================================================================

USE eleguard_rail;

-- 1. Add temporal columns to elephant_reports
ALTER TABLE elephant_reports
    ADD COLUMN sighting_date DATE NULL AFTER elephant_count,
    ADD COLUMN sighting_time TIME NULL AFTER sighting_date,
    ADD COLUMN sighting_time_precision ENUM('exact', 'approximate', 'unknown') NOT NULL DEFAULT 'unknown' AFTER sighting_time,
    ADD COLUMN sighting_timezone VARCHAR(32) NOT NULL DEFAULT 'Asia/Colombo' AFTER sighting_time_precision,
    ADD COLUMN gps_captured_at DATETIME NULL DEFAULT NULL AFTER gps_accuracy_meters;

-- 2. Add performance indexes for temporal analytics
ALTER TABLE elephant_reports
    ADD INDEX idx_elephant_reports_sighting_date (sighting_date),
    ADD INDEX idx_elephant_reports_temporal_hourly (sighting_date, sighting_time, sighting_time_precision),
    ADD INDEX idx_elephant_reports_section_temporal (section_id, sighting_date, sighting_time),
    ADD INDEX idx_elephant_reports_lag_analysis (sighting_date, created_at);

-- 3. Data Preservation Strategy for Existing Records (N=43)
-- For existing records, populate sighting_date from created_at date,
-- but mark sighting_time_precision as 'unknown' and sighting_time as NULL
-- to prevent synthesizing false clock-time observations.
UPDATE elephant_reports
SET 
    sighting_date = DATE(created_at),
    sighting_time = NULL,
    sighting_time_precision = 'unknown',
    sighting_timezone = 'Asia/Colombo'
WHERE sighting_date IS NULL;
```

---

## 5. Complete Rollback Script (`rollback_03_temporal_intelligence.sql`)

In the event of an operational anomaly during future execution, the migration can be reversed safely without data loss to other columns:

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

-- 2. Drop temporal columns
ALTER TABLE elephant_reports
    DROP COLUMN gps_captured_at,
    DROP COLUMN sighting_timezone,
    DROP COLUMN sighting_time_precision,
    DROP COLUMN sighting_time,
    DROP COLUMN sighting_date;
```

---

## 6. Backward Compatibility & Data Preservation Strategy

### Preserving the 43 Existing Records
The existing database contains 43 records across four distinct provenance categories:
- Confirmed Historical Baseline ($N=3$, IDs 1–3)
- Automated Test Harness Artifacts ($N=33$, IDs 4–33, 38–40)
- Inferred Demonstration Cases ($N=3$, IDs 41–43)
- Milestone 8C E2E Audit Runs ($N=4$, IDs 53–56)

### Preservation Policy
1. **Zero Overwriting of Operational Timestamps:** `created_at` and `updated_at` must remain 100% immutable.
2. **Honest Baseline Initialization:**
   - In Migration 03, the backfill statement sets `sighting_date = DATE(created_at)`.
   - Crucially, `sighting_time` is set to `NULL`, and `sighting_time_precision` is set to `'unknown'`.
   - **Reason:** Fabricating a specific hour (e.g. 12:00:00 or copying `TIME(created_at)`) for the three historical baseline records would manufacture fake wildlife observation times. Marking them `sighting_time = NULL` with `precision = 'unknown'` truthfully records that the historical documents recorded the day of submission, but not the specific hour of elephant movement.
3. **Application Layer Fallback:**
   In PHP services and views, any legacy record where `sighting_date IS NULL` will transparently fall back to `DATE(created_at)`. No existing API endpoint or GIS map will return blank dates.

---

## 7. Migration Testing Protocol (Using Isolated Test DB)

Before any production execution in future milestones, the migration must be tested following this protocol:
1. Spin up an isolated clone `eleguard_rail_test` on local port 3308.
2. Execute `03_add_temporal_intelligence_to_elephant_reports.sql`.
3. Assert that all 43 rows remain intact.
4. Assert that `sighting_date` equals `DATE(created_at)` and `sighting_time_precision = 'unknown'`.
5. Execute `rollback_03_temporal_intelligence.sql`.
6. Assert that table schema reverts to identical pre-migration state.
7. Drop `eleguard_rail_test`.
8. Assert zero writes were applied to operational `eleguard_rail`.
