-- =============================================================================
-- ELEGUARD RAIL — MILESTONE 9C: STAGE 4E.4.3-I
-- Migration: Add Database-Level Uniqueness Constraint to driver_acknowledgements
-- Target Environment: MariaDB 10.4+ (InnoDB Engine)
-- Target Table: driver_acknowledgements
-- Constraint Name: uq_alert_driver
--
-- OPERATIONAL SAFETY NOTICE:
-- THIS SCRIPT MUST BE EXECUTED ONLY DURING AN AUTHORIZED MAINTENANCE WINDOW.
-- DO NOT EXECUTE WITHOUT VERIFIED PRE-MIGRATION BACKUP AND CHANGE AUTHORIZATION.
-- ALL PRECONDITIONS ARE ACTIVELY ENFORCED; SCRIPT ABORTS ON ANY VIOLATION.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- SECTION 1: SESSION TIMEOUT & LOCKING SAFEGUARDS
-- -----------------------------------------------------------------------------
-- Bound Metadata Lock (MDL) wait time to 5 seconds.
-- Prevents connection pool starvation if another thread holds an open transaction.
SET SESSION lock_wait_timeout = 5;

-- -----------------------------------------------------------------------------
-- SECTION 2: ACTIVE PRECONDITION ASSERTIONS
-- -----------------------------------------------------------------------------
-- A temporary stored procedure actively asserts all preconditions.
-- On any violation, it raises an unhandled SQLSTATE '45000' exception,
-- forcing MariaDB / mysql CLI batch execution to ABORT IMMEDIATELY.
DELIMITER $$

DROP PROCEDURE IF EXISTS check_migration_preconditions$$
CREATE PROCEDURE check_migration_preconditions()
BEGIN
    DECLARE v_db VARCHAR(64);
    DECLARE v_tbl_count INT DEFAULT 0;
    DECLARE v_engine VARCHAR(64);
    DECLARE v_dup_count INT DEFAULT 0;
    DECLARE v_null_count INT DEFAULT 0;
    DECLARE v_const_count INT DEFAULT 0;

    -- 2.1 Verify authorized target database
    SELECT DATABASE() INTO v_db;
    IF v_db IS NULL OR (v_db != 'eleguard_rail' AND v_db != 'eleguard_rail_test') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Unauthorized or undefined target database.';
    END IF;

    -- 2.2 Verify target table existence
    SELECT COUNT(*) INTO v_tbl_count
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'driver_acknowledgements';
    IF v_tbl_count = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Target table driver_acknowledgements does not exist.';
    END IF;

    -- 2.3 Verify expected InnoDB storage engine
    SELECT ENGINE INTO v_engine
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'driver_acknowledgements';
    IF v_engine != 'InnoDB' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Storage engine is not InnoDB.';
    END IF;

    -- 2.4 Verify constraint does not already exist
    SELECT COUNT(*) INTO v_const_count
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = v_db 
      AND TABLE_NAME = 'driver_acknowledgements' 
      AND INDEX_NAME = 'uq_alert_driver';
    IF v_const_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Constraint uq_alert_driver already exists.';
    END IF;

    -- 2.5 Active duplicate detection
    SELECT COUNT(*) INTO v_dup_count FROM (
        SELECT alert_id, driver_id
        FROM driver_acknowledgements
        GROUP BY alert_id, driver_id
        HAVING COUNT(*) > 1
    ) AS duplicates;
    IF v_dup_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Duplicate (alert_id, driver_id) pairs exist in table.';
    END IF;

    -- 2.6 Active NULL detection
    SELECT COUNT(*) INTO v_null_count
    FROM driver_acknowledgements
    WHERE alert_id IS NULL OR driver_id IS NULL;
    IF v_null_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: NULL values found in alert_id or driver_id.';
    END IF;
END$$

DELIMITER ;

-- Execute active assertions
CALL check_migration_preconditions();

-- Drop temporary check procedure after successful validation
DROP PROCEDURE IF EXISTS check_migration_preconditions;

-- -----------------------------------------------------------------------------
-- SECTION 3: MIGRATION EXECUTION
-- -----------------------------------------------------------------------------
-- Add uniqueness constraint on (alert_id, driver_id)
-- InnoDB uses ALGORITHM=INPLACE, LOCK=SHARED.
-- In the event of an MDL timeout (>5s), MariaDB throws Error 1205 and aborts safely.
ALTER TABLE driver_acknowledgements
ADD CONSTRAINT uq_alert_driver UNIQUE (alert_id, driver_id);

-- -----------------------------------------------------------------------------
-- SECTION 4: POST-MIGRATION VERIFICATION
-- -----------------------------------------------------------------------------

-- Verification 4.1: Confirm unique index exists and is active
SELECT 
    INDEX_NAME, 
    NON_UNIQUE, 
    COLUMN_NAME, 
    SEQ_IN_INDEX
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'driver_acknowledgements'
  AND INDEX_NAME = 'uq_alert_driver'
ORDER BY SEQ_IN_INDEX ASC;

-- Verification 4.2: Confirm table structure and intact primary/foreign keys
SHOW CREATE TABLE driver_acknowledgements;

-- Verification 4.3: Confirm existing row count is preserved
SELECT COUNT(*) AS total_acknowledgements FROM driver_acknowledgements;

-- =============================================================================
-- END OF MIGRATION SCRIPT
-- =============================================================================
