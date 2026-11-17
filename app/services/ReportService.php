<?php
/**
 * Elephant Sighting Report Service
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../services/RiskService.php';
require_once __DIR__ . '/../services/AiDetectionService.php';
require_once __DIR__ . '/../services/TemporalService.php';
require_once __DIR__ . '/../services/VerificationService.php';

class ReportService {

    /**
     * Submits a new sighting report, validates temporal metadata, and calculates risk score
     * 
     * Supports both legacy callers (without temporal arguments) and new temporal callers
     * (passing an associative array or positional temporal arguments).
     */
    /**
     * Submits a new elephant sighting report and returns the inserted report primary key ID.
     * 
     * @return int|false Inserted report ID (> 0) on success, or false on failure/validation error.
     */
    public static function submitReportWithId(
        $sectionId, 
        $reportedBy, 
        $elephantCount, 
        $locationDesc, 
        $urgencyLevel, 
        $description, 
        $imagePath = null, 
        $latitude = null, 
        $longitude = null, 
        $accuracy = null,
        $temporalData = null,
        $sightingTime = null,
        $sightingPrecision = null,
        $sightingWindow = null,
        $sightingUncertainty = null,
        $gpsCapturedAt = null
    ) {
        $db = Database::getInstance()->getConnection();

        // 1. Fetch section risk level
        $secStmt = $db->prepare("SELECT risk_level FROM railway_sections WHERE id = :sec_id");
        $secStmt->execute([':sec_id' => $sectionId]);
        $section = $secStmt->fetch();
        $sectionRisk = $section ? $section['risk_level'] : 'Low';

        // 2. Check if active alerts exist in this section
        $alertStmt = $db->prepare("SELECT COUNT(*) FROM alerts WHERE section_id = :sec_id AND alert_status = 'Active'");
        $alertStmt->execute([':sec_id' => $sectionId]);
        $hasActiveAlert = ((int)$alertStmt->fetchColumn()) > 0;

        // 3. Compute dynamic risk level using RiskService (strictly preserving existing behavior)
        $reportTime = date('Y-m-d H:i:s');
        $calculatedRisk = RiskService::calculateRiskScore(
            $sectionRisk, 
            $urgencyLevel, 
            $elephantCount, 
            $reportTime, 
            $hasActiveAlert
        );

        // Sanitize coordinates (ensure valid numeric or NULL)
        $latParam = ($latitude !== null && $latitude !== '' && is_numeric($latitude)) ? (float)$latitude : null;
        $lngParam = ($longitude !== null && $longitude !== '' && is_numeric($longitude)) ? (float)$longitude : null;
        $accParam = ($accuracy !== null && $accuracy !== '' && is_numeric($accuracy)) ? (float)$accuracy : null;

        // 4. Validate and Normalize Temporal Metadata via TemporalService
        if (is_array($temporalData)) {
            $temporalInput = $temporalData;
        } else {
            $temporalInput = [
                'sighting_date' => $temporalData,
                'sighting_time' => $sightingTime,
                'sighting_time_precision' => $sightingPrecision,
                'sighting_time_window' => $sightingWindow,
                'sighting_time_uncertainty_minutes' => $sightingUncertainty,
                'gps_captured_at' => $gpsCapturedAt
            ];
        }

        $temporalResult = TemporalService::validateAndNormalizeTemporalInput($temporalInput);
        if (!$temporalResult['valid']) {
            error_log("ReportService::submitReport Temporal Validation Error: " . json_encode($temporalResult['errors']));
            return false;
        }
        $t = $temporalResult['data'];

        // 5. Save to Database with initial AI status and temporal columns
        $initialAiStatus = !empty($imagePath) ? 'pending' : 'skipped';
        $initialAiNotes = !empty($imagePath) ? 'Awaiting AI advisory inference.' : 'No photograph attached to report.';

        $stmt = $db->prepare("
            INSERT INTO elephant_reports (
                section_id, reported_by, elephant_count,
                sighting_date, sighting_time, sighting_time_precision,
                sighting_time_window, sighting_time_uncertainty_minutes, sighting_timezone,
                location_description, latitude, longitude, gps_accuracy_meters, gps_captured_at,
                urgency_level, risk_score, report_status, description, image_path,
                ai_status, ai_detection_notes, created_at
            )
            VALUES (
                :section_id, :reported_by, :elephant_count,
                :sighting_date, :sighting_time, :sighting_time_precision,
                :sighting_time_window, :sighting_time_uncertainty_minutes, :sighting_timezone,
                :location_desc, :latitude, :longitude, :gps_accuracy, :gps_captured_at,
                :urgency_level, :risk_score, 'Pending', :description, :image_path,
                :ai_status, :ai_notes, :created_at
            )
        ");

        $success = $stmt->execute([
            ':section_id' => $sectionId,
            ':reported_by' => $reportedBy,
            ':elephant_count' => $elephantCount,
            ':sighting_date' => $t['sighting_date'],
            ':sighting_time' => $t['sighting_time'],
            ':sighting_time_precision' => $t['sighting_time_precision'],
            ':sighting_time_window' => $t['sighting_time_window'],
            ':sighting_time_uncertainty_minutes' => $t['sighting_time_uncertainty_minutes'],
            ':sighting_timezone' => $t['sighting_timezone'],
            ':location_desc' => $locationDesc,
            ':latitude' => $latParam,
            ':longitude' => $lngParam,
            ':gps_accuracy' => $accParam,
            ':gps_captured_at' => $t['gps_captured_at'],
            ':urgency_level' => $urgencyLevel,
            ':risk_score' => $calculatedRisk,
            ':description' => $description,
            ':image_path' => $imagePath,
            ':ai_status' => $initialAiStatus,
            ':ai_notes' => $initialAiNotes,
            ':created_at' => $reportTime
        ]);

        if (!$success) {
            return false;
        }

        $reportId = (int)$db->lastInsertId();
        if ($reportId <= 0) {
            return false;
        }

        // 6. Trigger AI advisory inference asynchronously/synchronously if image is provided
        // Failure of AI must NEVER block, invalidate, or duplicate report submission
        if (!empty($imagePath)) {
            try {
                AiDetectionService::processReportAi($reportId, $imagePath);
            } catch (Throwable $e) {
                error_log("EleGuard AI Detection Error on Report #{$reportId}: " . $e->getMessage());
            }
        }

        return $reportId;
    }

    /**
     * Submits a new elephant sighting report and returns boolean true on success.
     * Preserves strict backward compatibility with all existing callers expecting a boolean.
     * 
     * @return bool True on success, false on failure.
     */
    public static function submitReport(
        $sectionId, 
        $reportedBy, 
        $elephantCount, 
        $locationDesc, 
        $urgencyLevel, 
        $description, 
        $imagePath = null, 
        $latitude = null, 
        $longitude = null, 
        $accuracy = null,
        $temporalData = null,
        $sightingTime = null,
        $sightingPrecision = null,
        $sightingWindow = null,
        $sightingUncertainty = null,
        $gpsCapturedAt = null
    ) {
        $result = self::submitReportWithId(
            $sectionId, 
            $reportedBy, 
            $elephantCount, 
            $locationDesc, 
            $urgencyLevel, 
            $description, 
            $imagePath, 
            $latitude, 
            $longitude, 
            $accuracy,
            $temporalData,
            $sightingTime,
            $sightingPrecision,
            $sightingWindow,
            $sightingUncertainty,
            $gpsCapturedAt
        );

        return ($result !== false && $result > 0);
    }

    /**
     * Retrieves sightings with filters
     */
    public static function getAll($statusFilter = null, $sectionFilter = null, $urgencyFilter = null, $reporterId = null) {
        $db = Database::getInstance()->getConnection();

        $sql = "
            SELECT r.*, s.section_name, s.district, u.name as reporter_name 
            FROM elephant_reports r
            JOIN railway_sections s ON r.section_id = s.id
            JOIN users u ON r.reported_by = u.id
            WHERE 1=1
        ";
        $params = [];

        if ($statusFilter) {
            $sql .= " AND r.report_status = :status";
            $params[':status'] = $statusFilter;
        }
        if ($sectionFilter) {
            $sql .= " AND r.section_id = :section_id";
            $params[':section_id'] = $sectionFilter;
        }
        if ($urgencyFilter) {
            $sql .= " AND r.urgency_level = :urgency";
            $params[':urgency'] = $urgencyFilter;
        }
        if ($reporterId) {
            $sql .= " AND r.reported_by = :reporter_id";
            $params[':reporter_id'] = $reporterId;
        }

        $sql .= " ORDER BY r.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getById($id) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT r.*, s.section_name, s.district, s.risk_level as section_risk_level, u.name as reporter_name 
            FROM elephant_reports r
            JOIN railway_sections s ON r.section_id = s.id
            JOIN users u ON r.reported_by = u.id
            WHERE r.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Updates status of report.
     * Enforces that biological verification transitions ('Verified', 'Rejected', 'Needs Information')
     * are strictly routed through the audited VerificationService with mandatory RBAC and ledger logging.
     * Anti-self-verification is enforced by default ($allowSelfVerification = false).
     * Non-verification operational status changes (e.g. 'Converted to Alert', 'Resolved') are validated
     * against a strict operational whitelist and require authenticated, authorized roles.
     * 
     * @param int $id Report ID
     * @param string $status Target status
     * @param int|null $userId Authenticated actor ID
     * @param bool $allowSelfVerification Whether to permit self-verification (default false; requires explicit authorization)
     * @return bool True on successful transition, false on authorization/validation failure
     */
    public static function updateStatus($id, $status, $userId = null, bool $allowSelfVerification = false) {
        $db = Database::getInstance()->getConnection();

        // 1. Unauthenticated or unattributed status changes strictly blocked
        if ($userId === null || (int)$userId <= 0) {
            return false;
        }

        // Fetch actor role server-side from database
        $uStmt = $db->prepare("SELECT id, role FROM users WHERE id = :uid");
        $uStmt->execute([':uid' => (int)$userId]);
        $user = $uStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return false;
        }
        $userRole = (string)$user['role'];

        // 2. Check if target status requires biological verification governance
        if (in_array($status, ['Verified', 'Rejected', 'Needs Information', 'Clarification Submitted'], true)) {
            if (!in_array($userRole, ['wildlife_officer', 'admin'], true)) {
                return false; // Non-officers (controllers, reporters, drivers) strictly forbidden
            }

            if ($status === 'Verified') {
                $res = VerificationService::verifyReport(
                    (int)$id, 
                    'Verified', 
                    'remote_telemetry', 
                    'Audited status transition via ReportService', 
                    (int)$userId, 
                    $userRole, 
                    null, 
                    $allowSelfVerification
                );
                return !empty($res['success']);
            }

            if ($status === 'Rejected') {
                $res = VerificationService::verifyReport(
                    (int)$id, 
                    'Rejected', 
                    'remote_telemetry', 
                    'Rejection justification recorded via ReportService', 
                    (int)$userId, 
                    $userRole, 
                    null, 
                    $allowSelfVerification
                );
                return !empty($res['success']);
            }

            if ($status === 'Needs Information') {
                $res = VerificationService::requestAdditionalInfo((int)$id, 'Information requested via ReportService interface', (int)$userId, $userRole);
                return !empty($res['success']);
            }

            return false;
        }

        // 3. SEC-02: Hardened Non-verification operational status update
        // Strictly whitelist permitted operational statuses and their role authorizations
        $operationalRolePermissions = [
            'Converted to Alert' => ['control_officer', 'admin'],
            'Resolved'           => ['control_officer', 'wildlife_officer', 'admin'],
            'Escalated'          => ['control_officer', 'wildlife_officer', 'admin']
        ];

        if (!array_key_exists($status, $operationalRolePermissions)) {
            return false; // Arbitrary status values or unauthorized biological bypass strictly rejected
        }

        if (!in_array($userRole, $operationalRolePermissions[$status], true)) {
            return false; // Role not authorized for this specific operational status transition
        }

        // Validate source report existence and lifecycle constraints
        $rStmt = $db->prepare("SELECT id, report_status FROM elephant_reports WHERE id = :id");
        $rStmt->execute([':id' => (int)$id]);
        $report = $rStmt->fetch(PDO::FETCH_ASSOC);

        if (!$report) {
            return false; // Non-existent report
        }

        // Operational constraint: Cannot convert or resolve already rejected reports
        if ($report['report_status'] === 'Rejected') {
            return false;
        }

        $stmt = $db->prepare("UPDATE elephant_reports SET report_status = :status, updated_at = NOW() WHERE id = :id");
        $success = $stmt->execute([':status' => $status, ':id' => (int)$id]);

        if ($success) {
            require_once __DIR__ . '/../core/Auth.php';
            Auth::logActivity((int)$userId, 'Report Operational Status Updated', "Report #{$id} status changed from {$report['report_status']} to '{$status}'.");
        }
        return $success;
    }

    public static function countByStatus($status) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM elephant_reports WHERE report_status = :status");
        $stmt->execute([':status' => $status]);
        return (int)$stmt->fetchColumn();
    }
}
