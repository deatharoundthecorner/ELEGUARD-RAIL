-- =========================================================================
-- EleGuard Rail - Migration 04: Field Reporting System Backend Foundation
-- Non-destructive addition of decoupled georeferencing, spatial uncertainty,
-- gapless diurnal continuum, client UUID idempotency, and verification audit.
-- Designed and audited in Milestone 9C: Stage 4E.3 & 4E.3-B
-- =========================================================================

USE eleguard_rail;

-- 1. Extend ENUMs and add new field reporting columns to elephant_reports
ALTER TABLE elephant_reports
    -- Offline idempotency & submission metadata
    ADD COLUMN client_report_uuid VARCHAR(36) NULL DEFAULT NULL AFTER id,
    ADD COLUMN submission_source ENUM('web_portal', 'mobile_pwa', 'station_master_vhf', 'offline_sync') NOT NULL DEFAULT 'web_portal' AFTER reported_by,
    ADD COLUMN observation_category ENUM('live_sighting', 'tracks_or_dung', 'crop_damage', 'carcass', 'fence_breach') NOT NULL DEFAULT 'live_sighting' AFTER submission_source,
    
    -- Enhanced temporal classification (continuous 7-period continuum)
    MODIFY COLUMN sighting_time_precision ENUM('exact', 'approximate', 'interval', 'unknown') NOT NULL DEFAULT 'unknown',
    ADD COLUMN diurnal_period ENUM('dawn', 'morning', 'midday', 'afternoon', 'dusk', 'night', 'deep_night') NULL DEFAULT NULL AFTER sighting_time_window,
    
    -- Decoupled observer georeferencing tier
    ADD COLUMN observer_latitude DECIMAL(10,8) NULL DEFAULT NULL AFTER sighting_timezone,
    ADD COLUMN observer_longitude DECIMAL(11,8) NULL DEFAULT NULL AFTER observer_latitude,
    ADD COLUMN observer_gps_accuracy_meters DECIMAL(8,2) NULL DEFAULT NULL AFTER observer_longitude,
    ADD COLUMN observer_gps_captured_at DATETIME NULL DEFAULT NULL AFTER observer_gps_accuracy_meters,
    ADD COLUMN location_acquisition_method ENUM('gps_device', 'manual_map_pinpoint', 'network_coarse', 'landmark_only') NOT NULL DEFAULT 'gps_device' AFTER observer_gps_captured_at,
    
    -- Decoupled animal location & offset georeferencing tier
    ADD COLUMN observation_distance_meters DECIMAL(8,2) NULL DEFAULT NULL AFTER location_acquisition_method,
    ADD COLUMN observation_bearing_degrees DECIMAL(5,2) NULL DEFAULT NULL AFTER observation_distance_meters,
    ADD COLUMN estimated_animal_latitude DECIMAL(10,8) NULL DEFAULT NULL AFTER observation_bearing_degrees,
    ADD COLUMN estimated_animal_longitude DECIMAL(11,8) NULL DEFAULT NULL AFTER estimated_animal_latitude,
    ADD COLUMN animal_location_uncertainty_meters DECIMAL(8,2) NULL DEFAULT NULL AFTER estimated_animal_longitude,
    ADD COLUMN animal_location_method ENUM('direct_inspection', 'triangulated_offset', 'milestone_reference', 'observer_standoff_unadjusted', 'descriptive_landmark') NOT NULL DEFAULT 'observer_standoff_unadjusted' AFTER animal_location_uncertainty_meters,
    ADD COLUMN is_estimated_position TINYINT(1) NOT NULL DEFAULT 0 AFTER animal_location_method,
    
    -- Railway section association & topography
    ADD COLUMN nearest_milestone_ref VARCHAR(64) NULL DEFAULT NULL AFTER is_estimated_position,
    ADD COLUMN distance_to_track_meters DECIMAL(8,2) NULL DEFAULT NULL AFTER nearest_milestone_ref,
    ADD COLUMN track_distance_min_meters DECIMAL(8,2) NULL DEFAULT NULL AFTER distance_to_track_meters,
    ADD COLUMN track_distance_max_meters DECIMAL(8,2) NULL DEFAULT NULL AFTER track_distance_min_meters,
    ADD COLUMN track_proximity_zone ENUM('Zone 0', 'Zone 1', 'Zone 2', 'Zone 3') NULL DEFAULT NULL AFTER track_distance_max_meters,
    ADD COLUMN section_assignment_status ENUM('provisional_estimated', 'controller_confirmed', 'topology_ambiguity') NOT NULL DEFAULT 'provisional_estimated' AFTER track_proximity_zone,
    
    -- Herd demographics breakdown
    ADD COLUMN adult_males INT NULL DEFAULT NULL AFTER elephant_count,
    ADD COLUMN adult_females INT NULL DEFAULT NULL AFTER adult_males,
    ADD COLUMN calves INT NULL DEFAULT NULL AFTER adult_females,
    
    -- Status expansion & research provenance segregation (preserving Converted to Alert for backward compatibility)
    MODIFY COLUMN report_status ENUM('Pending', 'Verified', 'Rejected', 'Needs Information', 'Duplicate Candidate', 'Escalated', 'Converted to Alert', 'Resolved') NOT NULL DEFAULT 'Pending',
    ADD COLUMN provenance ENUM('genuine_field', 'genuine_baseline', 'development_test', 'ai_test', 'e2e_audit') NOT NULL DEFAULT 'genuine_field' AFTER report_status;

-- 2. Add Unique and Performance Indexes
ALTER TABLE elephant_reports
    ADD UNIQUE KEY uq_client_uuid (client_report_uuid),
    ADD INDEX idx_reports_observer_coords (observer_latitude, observer_longitude),
    ADD INDEX idx_reports_estimated_coords (estimated_animal_latitude, estimated_animal_longitude),
    ADD INDEX idx_reports_diurnal (diurnal_period, sighting_date),
    ADD INDEX idx_reports_proximity_zone (track_proximity_zone, section_id),
    ADD INDEX idx_reports_provenance (provenance, report_status);

-- 3. Verification Audit Ledger Table
CREATE TABLE IF NOT EXISTS report_verifications (
    id INT(11) NOT NULL AUTO_INCREMENT,
    report_id INT(11) NOT NULL,
    verified_by INT(11) NOT NULL,
    previous_status VARCHAR(32) NOT NULL,
    new_status VARCHAR(32) NOT NULL,
    verification_method ENUM('field_inspection', 'station_master_vhf', 'remote_telemetry', 'resident_interview') NOT NULL DEFAULT 'remote_telemetry',
    verification_notes TEXT NULL DEFAULT NULL,
    verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_verif_report_id (report_id),
    KEY idx_verif_verified_by (verified_by),
    CONSTRAINT fk_verif_report FOREIGN KEY (report_id) REFERENCES elephant_reports (id) ON DELETE CASCADE,
    CONSTRAINT fk_verif_user FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Optional Railway Milestone Reference Table (DAT-01)
CREATE TABLE IF NOT EXISTS railway_milestones (
    id INT(11) NOT NULL AUTO_INCREMENT,
    section_id INT(11) NOT NULL,
    kilometer_mark DECIMAL(6,2) NOT NULL,
    pole_number INT(11) NULL DEFAULT NULL,
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    track_description VARCHAR(255) NULL DEFAULT NULL,
    survey_source VARCHAR(64) NOT NULL DEFAULT 'SLR_CCE',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_milestone_section (section_id),
    KEY idx_milestone_km (kilometer_mark, pole_number),
    CONSTRAINT fk_milestone_section FOREIGN KEY (section_id) REFERENCES railway_sections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Historical Data Preservation Policy (N=43 records):
-- Copy legacy GPS coordinates to observer georeferencing columns without data loss.
-- Estimated animal coordinates remain explicitly NULL (never fabricated).
UPDATE elephant_reports 
SET observer_latitude = latitude, 
    observer_longitude = longitude, 
    observer_gps_accuracy_meters = gps_accuracy_meters,
    observer_gps_captured_at = gps_captured_at
WHERE observer_latitude IS NULL AND latitude IS NOT NULL;

UPDATE elephant_reports
SET animal_location_method = CASE WHEN latitude IS NOT NULL THEN 'observer_standoff_unadjusted' ELSE 'descriptive_landmark' END
WHERE estimated_animal_latitude IS NULL;

-- Backfill provenance according to audited baseline (Stage 4E.1):
UPDATE elephant_reports SET provenance = 'genuine_baseline' WHERE id IN (1, 2, 3);
UPDATE elephant_reports SET provenance = 'development_test' WHERE id BETWEEN 4 AND 36;
UPDATE elephant_reports SET provenance = 'ai_test' WHERE id BETWEEN 37 AND 39;
UPDATE elephant_reports SET provenance = 'e2e_audit' WHERE id >= 40;
