-- =============================================================================
-- ELEGUARD RAIL — MILESTONE 9C: STAGE 4E.4.3-I
-- Rollback Migration Proposal: Drop driver_acknowledgement_audits Table
-- Target Environment: MariaDB 10.4+ (InnoDB Engine)
-- Target Table: driver_acknowledgement_audits
--
-- CRITICAL FORENSIC DATA-LOSS WARNING:
-- DROPPING 'driver_acknowledgement_audits' PERMANENTLY AND IRREVERSIBLY DESTROYS
-- THE ENTIRE FORENSIC AUDIT TRAIL OF LOCOMOTIVE DRIVER ADVISORY ACKNOWLEDGEMENTS,
-- INITIAL TIMESTAMPS, CAB REMARKS, AND CORRIDOR COMPLIANCE EVIDENCE.
--
-- DO NOT EXECUTE WITHOUT:
-- 1. Dual-authorization sign-off from Railway Operations and DWC Wildlife Liaison.
-- 2. Verified, checksummed pre-rollback logical backup of the table:
--    mariadb-dump eleguard_rail driver_acknowledgement_audits > backup.sql
-- 3. Explicit incident tracking change-ticket number.
-- =============================================================================

SET SESSION lock_wait_timeout = 5;

DROP TABLE IF EXISTS driver_acknowledgement_audits;

-- Post-rollback verification: Confirm table no longer exists
SELECT COUNT(*) AS table_count 
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'driver_acknowledgement_audits';
