-- =============================================================================
-- ELEGUARD RAIL — MILESTONE 9C: STAGE 4E.4.3-I
-- Migration Proposal: Create Append-Only driver_acknowledgement_audits Table
-- Target Environment: MariaDB 10.4+ (InnoDB Engine)
-- Target Table: driver_acknowledgement_audits
--
-- OPERATIONAL SAFETY NOTICE:
-- THIS SCRIPT IS A PROPOSAL ONLY. DO NOT EXECUTE AGAINST OPERATIONAL DATABASE.
-- EXECUTION MUST OCCUR ONLY DURING AN AUTHORIZED MAINTENANCE WINDOW.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- SECTION 1: SESSION TIMEOUT SAFEGUARDS
-- -----------------------------------------------------------------------------
SET SESSION lock_wait_timeout = 5;

-- -----------------------------------------------------------------------------
-- SECTION 2: ACTIVE PRECONDITION ASSERTIONS
-- -----------------------------------------------------------------------------
DELIMITER $$

DROP PROCEDURE IF EXISTS check_audit_migration_preconditions$$
CREATE PROCEDURE check_audit_migration_preconditions()
BEGIN
    DECLARE v_db VARCHAR(64);
    DECLARE v_tbl_count INT DEFAULT 0;
    DECLARE v_alerts_count INT DEFAULT 0;
    DECLARE v_users_count INT DEFAULT 0;

    -- 2.1 Verify authorized target database
    SELECT DATABASE() INTO v_db;
    IF v_db IS NULL OR (v_db != 'eleguard_rail' AND v_db != 'eleguard_rail_test') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Unauthorized or undefined target database.';
    END IF;

    -- 2.2 Verify target audit table does NOT already exist
    SELECT COUNT(*) INTO v_tbl_count
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'driver_acknowledgement_audits';
    IF v_tbl_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Table driver_acknowledgement_audits already exists.';
    END IF;

    -- 2.3 Verify parent foreign key tables exist
    SELECT COUNT(*) INTO v_alerts_count
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'alerts' AND ENGINE = 'InnoDB';
    IF v_alerts_count = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Parent table alerts does not exist or is not InnoDB.';
    END IF;

    SELECT COUNT(*) INTO v_users_count
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = v_db AND TABLE_NAME = 'users' AND ENGINE = 'InnoDB';
    IF v_users_count = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Parent table users does not exist or is not InnoDB.';
    END IF;
END$$

DELIMITER ;

CALL check_audit_migration_preconditions();
DROP PROCEDURE IF EXISTS check_audit_migration_preconditions;

-- -----------------------------------------------------------------------------
-- SECTION 3: DDL CREATION
-- -----------------------------------------------------------------------------
CREATE TABLE driver_acknowledgement_audits (
    id INT(11) NOT NULL AUTO_INCREMENT,
    acknowledgement_id INT(11) DEFAULT NULL,
    alert_id INT(11) NOT NULL,
    driver_id INT(11) NOT NULL,
    action_status ENUM('Acknowledged','Slowing Down','Stopped','False Alert') NOT NULL,
    note TEXT DEFAULT NULL,
    event_type ENUM('FIRST_ACKNOWLEDGEMENT','UPDATED_ACKNOWLEDGEMENT') NOT NULL,
    source_ip VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ack_id (acknowledgement_id),
    KEY idx_alert_driver (alert_id, driver_id),
    KEY idx_created_at (created_at),
    CONSTRAINT fk_ack_audit_alert FOREIGN KEY (alert_id) REFERENCES alerts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ack_audit_driver FOREIGN KEY (driver_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- SECTION 4: POST-CREATION VERIFICATION
-- -----------------------------------------------------------------------------
SELECT TABLE_NAME, ENGINE, TABLE_ROWS 
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'driver_acknowledgement_audits';
