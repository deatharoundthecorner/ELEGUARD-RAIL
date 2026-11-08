-- =========================================================================
-- EleGuard Rail - Migration 01: GPS Sighting Coordinates
-- Non-destructive addition of nullable geographic coordinates to elephant_reports
-- =========================================================================

USE eleguard_rail;

-- Add optional GPS coordinate fields to elephant_reports
-- Nullable so existing records and non-GPS text submissions remain fully functional
ALTER TABLE elephant_reports
    ADD COLUMN latitude DECIMAL(10, 8) NULL DEFAULT NULL AFTER location_description,
    ADD COLUMN longitude DECIMAL(11, 8) NULL DEFAULT NULL AFTER latitude,
    ADD COLUMN gps_accuracy_meters DECIMAL(8, 2) NULL DEFAULT NULL AFTER longitude;

-- Add spatial index for proximity and bounding-box queries
ALTER TABLE elephant_reports
    ADD INDEX idx_elephant_reports_coords (latitude, longitude);
