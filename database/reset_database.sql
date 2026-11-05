-- Reset script for EleGuard Rail Database
-- WARNING: This will drop all tables and delete all operational data.

USE eleguard_rail;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS system_logs;
DROP TABLE IF EXISTS incident_history;
DROP TABLE IF EXISTS driver_acknowledgements;
DROP TABLE IF EXISTS alerts;
DROP TABLE IF EXISTS elephant_reports;
DROP TABLE IF EXISTS hotspots;
DROP TABLE IF EXISTS railway_sections;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- Clean completion log message
SELECT 'Database tables dropped successfully. Please run eleguard_rail.sql then seed_data.sql to rebuild.' AS Message;
