-- =========================================================================
-- EleGuard Rail - Rollback Migration 06: Drop Payload Fingerprint Column
-- Stage J Phase J.5: Deterministic Observation Mutation Detection
-- Change Reference: ER-M9C-STAGE-J-J5-007
-- =========================================================================

USE eleguard_rail;

ALTER TABLE elephant_reports
    DROP INDEX idx_reports_payload_hash,
    DROP COLUMN payload_hash;
