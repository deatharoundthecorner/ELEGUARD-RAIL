<?php
/**
 * Alert, Acknowledgement, Section, Hotspot and Incident Service
 */

require_once __DIR__ . '/../core/Database.php';

class AlertService {

    // =========================================================================
    // Alerts Operations
    // =========================================================================

    public static function createAlert($reportId, $sectionId, $alertLevel, $alertMessage, $createdBy) {
        $db = Database::getInstance()->getConnection();
        
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO alerts (report_id, section_id, alert_level, alert_message, alert_status, created_by)
                VALUES (:report_id, :section_id, :alert_level, :alert_message, 'Active', :created_by)
            ");
            $stmt->execute([
                ':report_id' => $reportId,
                ':section_id' => $sectionId,
                ':alert_level' => $alertLevel,
                ':alert_message' => $alertMessage,
                ':created_by' => $createdBy
            ]);
            $alertIdNew = $db->lastInsertId();

            // If created from a report, update the report status
            if ($reportId) {
                $repStmt = $db->prepare("UPDATE elephant_reports SET report_status = 'Converted to Alert', updated_at = NOW() WHERE id = :report_id");
                $repStmt->execute([':report_id' => $reportId]);
            }

            // Log activity
            require_once __DIR__ . '/../core/Auth.php';
            Auth::logActivity($createdBy, 'Alert Created', "Alert #{$alertIdNew} ({$alertLevel}) created for railway section #{$sectionId}.");

            $db->commit();
            return $alertIdNew;
        } catch (Exception $e) {
            $db->rollBack();
            return false;
        }
    }

    public static function getAllAlerts($statusFilter = null, $levelFilter = null) {
        $db = Database::getInstance()->getConnection();
        $sql = "
            SELECT a.*, s.section_name, s.district, u.name as creator_name 
            FROM alerts a
            JOIN railway_sections s ON a.section_id = s.id
            JOIN users u ON a.created_by = u.id
            WHERE 1=1
        ";
        $params = [];

        if ($statusFilter) {
            $sql .= " AND a.alert_status = :status";
            $params[':status'] = $statusFilter;
        }
        if ($levelFilter) {
            $sql .= " AND a.alert_level = :level";
            $params[':level'] = $levelFilter;
        }

        $sql .= " ORDER BY a.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getAlertById($id) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT a.*, s.section_name, s.district, s.start_location, s.end_location, s.risk_level as section_risk, u.name as creator_name 
            FROM alerts a
            JOIN railway_sections s ON a.section_id = s.id
            JOIN users u ON a.created_by = u.id
            WHERE a.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public static function updateAlertStatus($id, $status, $userId) {
        $db = Database::getInstance()->getConnection();
        $resolvedAt = ($status === 'Resolved') ? date('Y-m-d H:i:s') : null;
        
        $stmt = $db->prepare("UPDATE alerts SET alert_status = :status, resolved_at = :resolved_at WHERE id = :id");
        $success = $stmt->execute([
            ':status' => $status,
            ':resolved_at' => $resolvedAt,
            ':id' => $id
        ]);

        if ($success) {
            require_once __DIR__ . '/../core/Auth.php';
            Auth::logActivity($userId, 'Alert Status Changed', "Alert #{$id} set to '{$status}'.");
        }
        return $success;
    }

    public static function deleteAlert($id, $userId) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("DELETE FROM alerts WHERE id = :id");
        $success = $stmt->execute([':id' => $id]);
        
        if ($success) {
            require_once __DIR__ . '/../core/Auth.php';
            Auth::logActivity($userId, 'Alert Deleted', "Alert #{$id} was permanently deleted.");
        }
        return $success;
    }

    public static function countAlertsByStatus($status) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM alerts WHERE alert_status = :status");
        $stmt->execute([':status' => $status]);
        return (int)$stmt->fetchColumn();
    }

    public static function countAlertsByLevel($level) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM alerts WHERE alert_level = :level AND alert_status = 'Active'");
        $stmt->execute([':level' => $level]);
        return (int)$stmt->fetchColumn();
    }

    // =========================================================================
    // Driver Acknowledgements
    // =========================================================================

    public static function acknowledgeAlert($alertId, $driverId, $actionStatus, $note = null) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO driver_acknowledgements (alert_id, driver_id, action_status, note)
            VALUES (:alert_id, :driver_id, :action_status, :note)
        ");
        $success = $stmt->execute([
            ':alert_id' => $alertId,
            ':driver_id' => $driverId,
            ':action_status' => $actionStatus,
            ':note' => $note
        ]);

        if ($success) {
            require_once __DIR__ . '/../core/Auth.php';
            Auth::logActivity($driverId, 'Driver Acknowledged Alert', "Driver acknowledged Alert #{$alertId} as '{$actionStatus}'.");
        }
        return $success;
    }

    public static function getAcknowledgements($driverId = null, $alertId = null) {
        $db = Database::getInstance()->getConnection();
        $sql = "
            SELECT k.*, a.alert_level, a.alert_message, s.section_name, u.name as driver_name 
            FROM driver_acknowledgements k
            JOIN alerts a ON k.alert_id = a.id
            JOIN railway_sections s ON a.section_id = s.id
            JOIN users u ON k.driver_id = u.id
            WHERE 1=1
        ";
        $params = [];

        if ($driverId) {
            $sql .= " AND k.driver_id = :driver_id";
            $params[':driver_id'] = $driverId;
        }
        if ($alertId) {
            $sql .= " AND k.alert_id = :alert_id";
            $params[':alert_id'] = $alertId;
        }

        $sql .= " ORDER BY k.acknowledged_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function countAcknowledgements() {
        $db = Database::getInstance()->getConnection();
        return (int)$db->query("SELECT COUNT(*) FROM driver_acknowledgements")->fetchColumn();
    }

    // =========================================================================
    // Railway Sections CRUD
    // =========================================================================

    public static function getAllSections() {
        $db = Database::getInstance()->getConnection();
        return $db->query("SELECT * FROM railway_sections ORDER BY section_name ASC")->fetchAll();
    }

    public static function getSectionById($id) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM railway_sections WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public static function addSection($name, $district, $start, $end, $risk, $desc) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO railway_sections (section_name, district, start_location, end_location, risk_level, description)
            VALUES (:name, :district, :start, :end, :risk, :desc)
        ");
        return $stmt->execute([
            ':name' => $name,
            ':district' => $district,
            ':start' => $start,
            ':end' => $end,
            ':risk' => $risk,
            ':desc' => $desc
        ]);
    }

    public static function updateSection($id, $name, $district, $start, $end, $risk, $desc) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE railway_sections 
            SET section_name = :name, district = :district, start_location = :start, end_location = :end, risk_level = :risk, description = :desc 
            WHERE id = :id
        ");
        return $stmt->execute([
            ':name' => $name,
            ':district' => $district,
            ':start' => $start,
            ':end' => $end,
            ':risk' => $risk,
            ':desc' => $desc,
            ':id' => $id
        ]);
    }

    public static function deleteSection($id) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("DELETE FROM railway_sections WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public static function countSections() {
        $db = Database::getInstance()->getConnection();
        return (int)$db->query("SELECT COUNT(*) FROM railway_sections")->fetchColumn();
    }

    // =========================================================================
    // Hotspots CRUD
    // =========================================================================

    public static function getAllHotspots($district = null, $risk = null) {
        $db = Database::getInstance()->getConnection();
        $sql = "
            SELECT h.*, s.section_name, s.district 
            FROM hotspots h
            JOIN railway_sections s ON h.section_id = s.id
            WHERE 1=1
        ";
        $params = [];

        if ($district) {
            $sql .= " AND s.district LIKE :district";
            $params[':district'] = '%' . $district . '%';
        }
        if ($risk) {
            $sql .= " AND h.risk_level = :risk";
            $params[':risk'] = $risk;
        }

        $sql .= " ORDER BY h.risk_level DESC, h.hotspot_name ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getHotspotById($id) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM hotspots WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public static function addHotspot($sectionId, $name, $lat, $lon, $risk, $reason) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO hotspots (section_id, hotspot_name, latitude, longitude, risk_level, reason)
            VALUES (:section_id, :name, :lat, :lon, :risk, :reason)
        ");
        return $stmt->execute([
            ':section_id' => $sectionId,
            ':name' => $name,
            ':lat' => $lat ? $lat : null,
            ':lon' => $lon ? $lon : null,
            ':risk' => $risk,
            ':reason' => $reason
        ]);
    }

    public static function updateHotspot($id, $sectionId, $name, $lat, $lon, $risk, $reason) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE hotspots 
            SET section_id = :section_id, hotspot_name = :name, latitude = :lat, longitude = :lon, risk_level = :risk, reason = :reason 
            WHERE id = :id
        ");
        return $stmt->execute([
            ':section_id' => $sectionId,
            ':name' => $name,
            ':lat' => $lat ? $lat : null,
            ':lon' => $lon ? $lon : null,
            ':risk' => $risk,
            ':reason' => $reason,
            ':id' => $id
        ]);
    }

    public static function deleteHotspot($id) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("DELETE FROM hotspots WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public static function countHotspots() {
        $db = Database::getInstance()->getConnection();
        return (int)$db->query("SELECT COUNT(*) FROM hotspots")->fetchColumn();
    }

    // =========================================================================
    // Incident History Operations
    // =========================================================================

    public static function getAllIncidents($severity = null, $sectionId = null) {
        $db = Database::getInstance()->getConnection();
        $sql = "
            SELECT i.*, s.section_name, s.district 
            FROM incident_history i
            JOIN railway_sections s ON i.section_id = s.id
            WHERE 1=1
        ";
        $params = [];

        if ($severity) {
            $sql .= " AND i.severity = :severity";
            $params[':severity'] = $severity;
        }
        if ($sectionId) {
            $sql .= " AND i.section_id = :section_id";
            $params[':section_id'] = $sectionId;
        }

        $sql .= " ORDER BY i.incident_date DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getIncidentById($id) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM incident_history WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public static function addIncident($sectionId, $type, $desc, $date, $severity) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO incident_history (section_id, incident_type, description, incident_date, severity)
            VALUES (:section_id, :type, :desc, :date, :severity)
        ");
        return $stmt->execute([
            ':section_id' => $sectionId,
            ':type' => $type,
            ':desc' => $desc,
            ':date' => $date,
            ':severity' => $severity
        ]);
    }

    public static function updateIncident($id, $sectionId, $type, $desc, $date, $severity) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE incident_history 
            SET section_id = :section_id, incident_type = :type, description = :desc, incident_date = :date, severity = :severity 
            WHERE id = :id
        ");
        return $stmt->execute([
            ':section_id' => $sectionId,
            ':type' => $type,
            ':desc' => $desc,
            ':date' => $date,
            ':severity' => $severity,
            ':id' => $id
        ]);
    }

    public static function deleteIncident($id) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("DELETE FROM incident_history WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    // =========================================================================
    // System Logs
    // =========================================================================

    public static function getSystemLogs() {
        $db = Database::getInstance()->getConnection();
        return $db->query("
            SELECT l.*, u.name as user_name, u.role as user_role 
            FROM system_logs l
            LEFT JOIN users u ON l.user_id = u.id
            ORDER BY l.created_at DESC
            LIMIT 100
        ")->fetchAll();
    }
}
