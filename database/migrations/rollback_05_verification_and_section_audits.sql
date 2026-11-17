-- =========================================================================
-- EleGuard Rail - Rollback Migration 05
-- Reverses Migration 05 cleanly, preserving all baseline data intact.
-- =========================================================================

USE eleguard_rail;

-- 1. Drop audit tables
DROP TABLE IF EXISTS report_clarifications;
DROP TABLE IF EXISTS report_section_assignment_history;

-- 2. Drop performance indexes added in Migration 05
ALTER TABLE report_verifications
    DROP INDEX IF EXISTS idx_verif_status_time;

ALTER TABLE elephant_reports
    DROP INDEX IF EXISTS idx_reports_status_updated;

-- 3. Revert report_status enum on elephant_reports to pre-Migration-05 definition
ALTER TABLE elephant_reports
    MODIFY COLUMN report_status ENUM(
        'Pending',
        'Verified',
        'Rejected',
        'Needs Information',
        'Duplicate Candidate',
        'Escalated',
        'Converted to Alert',
        'Resolved'
    ) NOT NULL DEFAULT 'Pending';
