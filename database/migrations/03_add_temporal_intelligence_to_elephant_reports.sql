-- =========================================================================
-- EleGuard Rail - Migration 03: Temporal & Seasonal Intelligence Foundation
-- Non-destructive addition of sighting timestamps and precision metadata
-- Designed and refined in Milestone 9C: Temporal & Seasonal Intelligence
-- =========================================================================

USE eleguard_rail;

-- 1. Add temporal columns to elephant_reports
-- All fields default to NULL / 'unknown' to preserve historical records losslessly
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

-- 3. Historical Data Preservation Policy:
-- In accordance with strict scientific integrity rules, existing records (N=43)
-- naturally retain sighting_date = NULL, sighting_time = NULL, and precision = 'unknown'.
-- created_at is NEVER treated as proof of actual sighting date.
-- created_at and updated_at remain 100% immutable.
