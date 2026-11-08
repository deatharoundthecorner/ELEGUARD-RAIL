-- =========================================================================
-- EleGuard Rail - Migration 02: AI Detection & Verification Metadata
-- Non-destructive addition of nullable AI inference & clustering columns
-- Planned separately from GPS migration; DO NOT EXECUTE YET
-- =========================================================================

USE eleguard_rail;

-- Add AI vision detection fields and duplicate clustering fields
ALTER TABLE elephant_reports
    ADD COLUMN ai_status ENUM('pending', 'processed', 'skipped', 'failed') DEFAULT 'pending' AFTER image_path,
    ADD COLUMN ai_detected_elephant TINYINT(1) NULL DEFAULT NULL AFTER ai_status,
    ADD COLUMN ai_confidence DECIMAL(5, 4) NULL DEFAULT NULL AFTER ai_detected_elephant,
    ADD COLUMN ai_estimated_count INT NULL DEFAULT NULL AFTER ai_confidence,
    ADD COLUMN ai_model_version VARCHAR(50) NULL DEFAULT NULL AFTER ai_estimated_count,
    ADD COLUMN ai_detection_notes TEXT NULL DEFAULT NULL AFTER ai_model_version,
    ADD COLUMN ai_processed_at DATETIME NULL DEFAULT NULL AFTER ai_detection_notes,
    ADD COLUMN is_potential_duplicate TINYINT(1) DEFAULT 0 AFTER ai_processed_at,
    ADD COLUMN duplicate_cluster_id INT NULL DEFAULT NULL AFTER is_potential_duplicate;

-- Add indexes for performance on queue lookups and clustering
ALTER TABLE elephant_reports
    ADD INDEX idx_elephant_reports_ai_status (ai_status),
    ADD INDEX idx_elephant_reports_duplicate (is_potential_duplicate, duplicate_cluster_id);
