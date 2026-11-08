-- =========================================================================
-- EleGuard Rail - Rollback 02: Revert AI Detection & Verification Metadata
-- Reverts columns and indexes added by 02_add_ai_detection_metadata_to_elephant_reports.sql
-- =========================================================================

USE eleguard_rail;

ALTER TABLE elephant_reports
    DROP INDEX IF EXISTS idx_elephant_reports_duplicate,
    DROP INDEX IF EXISTS idx_elephant_reports_ai_status;

ALTER TABLE elephant_reports
    DROP COLUMN IF EXISTS duplicate_cluster_id,
    DROP COLUMN IF EXISTS is_potential_duplicate,
    DROP COLUMN IF EXISTS ai_processed_at,
    DROP COLUMN IF EXISTS ai_detection_notes,
    DROP COLUMN IF EXISTS ai_model_version,
    DROP COLUMN IF EXISTS ai_estimated_count,
    DROP COLUMN IF EXISTS ai_confidence,
    DROP COLUMN IF EXISTS ai_detected_elephant,
    DROP COLUMN IF EXISTS ai_status;
