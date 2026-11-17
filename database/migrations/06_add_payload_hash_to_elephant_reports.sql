-- =========================================================================
-- EleGuard Rail - Migration 06: Payload Fingerprint Column for Idempotency
-- Stage J Phase J.5: Deterministic Observation Mutation Detection
-- Change Reference: ER-M9C-STAGE-J-J5-007
-- =========================================================================

USE eleguard_rail;

ALTER TABLE elephant_reports
    ADD COLUMN payload_hash CHAR(64) NULL DEFAULT NULL AFTER client_report_uuid,
    ADD INDEX idx_reports_payload_hash (payload_hash);
