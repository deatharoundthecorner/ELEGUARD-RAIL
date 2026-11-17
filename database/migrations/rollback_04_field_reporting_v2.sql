-- =========================================================================
-- EleGuard Rail - Rollback Migration 04
-- Reverses Migration 04 cleanly without data loss to legacy columns.
-- =========================================================================

USE eleguard_rail;

-- 1. Drop supporting tables
DROP TABLE IF EXISTS report_verifications;
DROP TABLE IF EXISTS railway_milestones;

-- 2. Drop indexes
ALTER TABLE elephant_reports
    DROP INDEX uq_client_uuid,
    DROP INDEX idx_reports_observer_coords,
    DROP INDEX idx_reports_estimated_coords,
    DROP INDEX idx_reports_diurnal,
    DROP INDEX idx_reports_proximity_zone,
    DROP INDEX idx_reports_provenance;

-- 3. Drop columns and restore legacy ENUM definitions
ALTER TABLE elephant_reports
    DROP COLUMN client_report_uuid,
    DROP COLUMN submission_source,
    DROP COLUMN observation_category,
    DROP COLUMN diurnal_period,
    DROP COLUMN observer_latitude,
    DROP COLUMN observer_longitude,
    DROP COLUMN observer_gps_accuracy_meters,
    DROP COLUMN observer_gps_captured_at,
    DROP COLUMN location_acquisition_method,
    DROP COLUMN observation_distance_meters,
    DROP COLUMN observation_bearing_degrees,
    DROP COLUMN estimated_animal_latitude,
    DROP COLUMN estimated_animal_longitude,
    DROP COLUMN animal_location_uncertainty_meters,
    DROP COLUMN animal_location_method,
    DROP COLUMN is_estimated_position,
    DROP COLUMN nearest_milestone_ref,
    DROP COLUMN distance_to_track_meters,
    DROP COLUMN track_distance_min_meters,
    DROP COLUMN track_distance_max_meters,
    DROP COLUMN track_proximity_zone,
    DROP COLUMN section_assignment_status,
    DROP COLUMN adult_males,
    DROP COLUMN adult_females,
    DROP COLUMN calves,
    DROP COLUMN provenance,
    MODIFY COLUMN sighting_time_precision ENUM('exact', 'approximate', 'unknown') NOT NULL DEFAULT 'unknown',
    MODIFY COLUMN report_status ENUM('Pending', 'Verified', 'Rejected', 'Converted to Alert', 'Resolved') NOT NULL DEFAULT 'Pending';
