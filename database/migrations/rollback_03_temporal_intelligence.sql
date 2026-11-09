-- =========================================================================
-- EleGuard Rail - Rollback Migration 03: Temporal & Seasonal Intelligence
-- Reverses schema alterations introduced by Migration 03
-- Non-destructive reversal; leaves all non-temporal columns and data untouched
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
    DROP COLUMN sighting_time_uncertainty_minutes,
    DROP COLUMN sighting_time_window,
    DROP COLUMN sighting_time_precision,
    DROP COLUMN sighting_time,
    DROP COLUMN sighting_date;
