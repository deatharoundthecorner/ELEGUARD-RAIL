<?php
/**
 * Elephant Sighting Report Service
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../services/RiskService.php';

class ReportService {

    /**
     * Submits a new sighting report and automatically calculates the risk score
     */
    public static function submitReport($sectionId, $reportedBy, $elephantCount, $locationDesc, $urgencyLevel, $description, $imagePath = null) {
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

        // 3. Compute dynamic risk level using RiskService
        $reportTime = date('Y-m-d H:i:s');
        $calculatedRisk = RiskService::calculateRiskScore(
            $sectionRisk, 
            $urgencyLevel, 
            $elephantCount, 
            $reportTime, 
            $hasActiveAlert
        );

        // 4. Save to Database
        $stmt = $db->prepare("
            INSERT INTO elephant_reports (section_id, reported_by, elephant_count, location_description, urgency_level, risk_score, report_status, description, image_path, created_at)
            VALUES (:section_id, :reported_by, :elephant_count, :location_desc, :urgency_level, :risk_score, 'Pending', :description, :image_path, :created_at)
        ");

        $success = $stmt->execute([
            ':section_id' => $sectionId,
            ':reported_by' => $reportedBy,
            ':elephant_count' => $elephantCount,
            ':location_desc' => $locationDesc,
            ':urgency_level' => $urgencyLevel,
            ':risk_score' => $calculatedRisk,
            ':description' => $description,
            ':image_path' => $imagePath,
            ':created_at' => $reportTime
        ]);

        return $success;
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
     * Updates status of report
     */
    public static function updateStatus($id, $status, $userId = null) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE elephant_reports SET report_status = :status, updated_at = NOW() WHERE id = :id");
        $success = $stmt->execute([':status' => $status, ':id' => $id]);

        if ($success && $userId) {
            require_once __DIR__ . '/../core/Auth.php';
            Auth::logActivity($userId, 'Report Updated', "Report #{$id} status changed to '{$status}'.");
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
