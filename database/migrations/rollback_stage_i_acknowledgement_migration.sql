-- =============================================================================
-- ELEGUARD RAIL — MILESTONE 9C: STAGE 4E.4.3-I
-- Rollback Migration: Remove Uniqueness Constraint from driver_acknowledgements
-- Target Table: driver_acknowledgements
-- Target Constraint: uq_alert_driver
--
-- SAFETY NOTICE:
-- THIS SCRIPT ONLY DROPS THE UNIQUE INDEX 'uq_alert_driver'.
-- IT DOES NOT DELETE, TRUNCATE, OR MODIFY ANY ACKNOWLEDGEMENT RECORDS.
-- DO NOT EXECUTE WITHOUT AUTHORIZATION.
-- =============================================================================

-- Bound Metadata Lock (MDL) wait time to 5 seconds (RE-03 Remediation)
-- Prevents connection queue starvation if another thread holds an open transaction
SET SESSION lock_wait_timeout = 5;

-- Pre-rollback verification: Confirm constraint exists
SELECT INDEX_NAME, NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'driver_acknowledgements'
  AND INDEX_NAME = 'uq_alert_driver';

-- Drop the unique constraint / index
ALTER TABLE driver_acknowledgements
DROP INDEX uq_alert_driver;

-- Post-rollback verification: Confirm index is removed and table intact
SHOW CREATE TABLE driver_acknowledgements;
SELECT COUNT(*) AS total_records FROM driver_acknowledgements;
