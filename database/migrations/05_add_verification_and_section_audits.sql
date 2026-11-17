-- =========================================================================
-- EleGuard Rail - Migration 05: Verification Database Foundation
-- Stage 4E.4.3-C: Multi-turn Clarification Threads, Railway Section 
-- Assignment Audit Ledgers, and Status Enum Expansion.
-- =========================================================================

USE eleguard_rail;

-- 1. Extend report_status enum on elephant_reports to include 'Clarification Submitted'
ALTER TABLE elephant_reports
    MODIFY COLUMN report_status ENUM(
        'Pending',
        'Verified',
        'Rejected',
        'Needs Information',
        'Clarification Submitted',
        'Duplicate Candidate',
        'Escalated',
        'Converted to Alert',
        'Resolved'
    ) NOT NULL DEFAULT 'Pending';

ALTER TABLE elephant_reports
    ADD INDEX IF NOT EXISTS idx_reports_status_updated (report_status, updated_at);

-- 2. Add performance index on report_verifications if not exists
ALTER TABLE report_verifications
    ADD INDEX IF NOT EXISTS idx_verif_status_time (new_status, verified_at);

-- 3. Create report_clarifications (Threaded multi-turn conversational dialogue ledger)
-- Implements composite foreign key (parent_clarification_id, report_id) ensuring
-- cross-report parent-message relationships are strictly rejected at the engine level.
CREATE TABLE IF NOT EXISTS report_clarifications (
    id INT(11) NOT NULL AUTO_INCREMENT,
    report_id INT(11) NOT NULL,
    parent_clarification_id INT(11) NULL DEFAULT NULL,
    inquiry_ledger_id INT(11) NULL DEFAULT NULL,
    sender_id INT(11) NOT NULL,
    sender_role ENUM('wildlife_officer', 'reporter', 'admin') NOT NULL,
    message_type ENUM('officer_inquiry', 'reporter_response', 'officer_followup') NOT NULL,
    message_text TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clarif_id_report (id, report_id),
    KEY idx_clarif_report (report_id),
    KEY idx_clarif_parent (parent_clarification_id),
    KEY idx_clarif_sender (sender_id),
    KEY idx_clarif_created (created_at),
    CONSTRAINT fk_clarif_report FOREIGN KEY (report_id) 
        REFERENCES elephant_reports (id) ON DELETE CASCADE,
    CONSTRAINT fk_clarif_parent FOREIGN KEY (parent_clarification_id, report_id) 
        REFERENCES report_clarifications (id, report_id) ON DELETE RESTRICT,
    CONSTRAINT fk_clarif_ledger FOREIGN KEY (inquiry_ledger_id) 
        REFERENCES report_verifications (id) ON DELETE SET NULL,
    CONSTRAINT fk_clarif_sender FOREIGN KEY (sender_id) 
        REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Create report_section_assignment_history (Corridor attribution audit ledger)
-- Preserves initial provisional assignment, controller confirmations, and subsequent reassignments.
CREATE TABLE IF NOT EXISTS report_section_assignment_history (
    id INT(11) NOT NULL AUTO_INCREMENT,
    report_id INT(11) NOT NULL,
    previous_section_id INT(11) NULL DEFAULT NULL,
    new_section_id INT(11) NOT NULL,
    assignment_type ENUM('initial_provisional', 'subsequent_reassignment', 'controller_confirmation') NOT NULL,
    assignment_method ENUM('spatial_nearest_neighbor', 'station_master_vhf', 'chainage_milepost_lookup', 'manual_controller_override') NOT NULL DEFAULT 'manual_controller_override',
    assignment_reason TEXT NOT NULL,
    assigned_by INT(11) NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sec_hist_report (report_id),
    KEY idx_sec_hist_assigned_by (assigned_by),
    KEY idx_sec_hist_sections (previous_section_id, new_section_id),
    KEY idx_sec_hist_type (assignment_type),
    KEY idx_sec_hist_time (assigned_at),
    CONSTRAINT fk_sec_hist_report FOREIGN KEY (report_id) 
        REFERENCES elephant_reports (id) ON DELETE CASCADE,
    CONSTRAINT fk_sec_hist_prev_sec FOREIGN KEY (previous_section_id) 
        REFERENCES railway_sections (id) ON DELETE SET NULL,
    CONSTRAINT fk_sec_hist_new_sec FOREIGN KEY (new_section_id) 
        REFERENCES railway_sections (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sec_hist_user FOREIGN KEY (assigned_by) 
        REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
