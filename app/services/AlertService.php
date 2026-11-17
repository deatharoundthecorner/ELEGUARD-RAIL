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
        
        // Safety check: Locomotive drivers are strictly forbidden from modifying alert status
        if ($userId) {
            $uStmt = $db->prepare("SELECT role FROM users WHERE id = :id");
            $uStmt->execute([':id' => $userId]);
            $userRole = $uStmt->fetchColumn();
            if ($userRole === 'driver') {
                return false;
            }
        }

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
    // Driver Acknowledgements & Advisory Operations (Milestone 9C Stage 4E.4.3-I)
    // =========================================================================

    /**
     * Submit or update a locomotive driver receipt-only advisory acknowledgement.
     * Enforces:
     * - Alert existence & 'Active' status (cannot acknowledge resolved alerts).
     * - Receipt-only action status mapping (safely maps UI semantics to database ENUM).
     * - Transactional deduplication, idempotency & append-only audit history with bounded retries.
     * - Alert status immutability (driver cannot resolve or alter alert status).
     * - Atomic append-only audit recording (driver_acknowledgement_audits).
     * - Safe handling of deadlocks, lock timeouts, and duplicate-key conflicts.
     *
     * @param int $alertId
     * @param int $driverId
     * @param string $actionStatus
     * @param string|null $note
     * @param bool $simulateAuditFailure Testing flag for atomicity verification
     * @return bool
     */
    public static function acknowledgeAlert($alertId, $driverId, $actionStatus, $note = null, $simulateAuditFailure = false) {
        $alertId = (int)$alertId;
        $driverId = (int)$driverId;
        if ($alertId <= 0 || $driverId <= 0 || empty($actionStatus)) {
            return false;
        }

        $db = Database::getInstance()->getConnection();

        // 1. Validate alert existence and Active status
        $alertCheck = $db->prepare("SELECT id, alert_status FROM alerts WHERE id = :id");
        $alertCheck->execute([':id' => $alertId]);
        $alertRow = $alertCheck->fetch();
        if (!$alertRow || $alertRow['alert_status'] !== 'Active') {
            return false;
        }

        // 2. Safe mapping to database ENUM: 'Acknowledged', 'Slowing Down', 'Stopped', 'False Alert'
        $statusClean = trim($actionStatus);
        $cleanNote = $note !== null ? trim($note) : null;
        $dbStatus = 'Acknowledged';

        if (in_array($statusClean, ['Acknowledged', 'Briefing Noted', 'Briefing Noted (Standard Vigilance)', 'Acknowledged — Advisory Received', 'Acknowledged - Advisory Received'], true)) {
            $dbStatus = 'Acknowledged';
        } elseif (in_array($statusClean, ['Slowing Down', 'Caution Speed Planned'], true)) {
            $dbStatus = 'Slowing Down';
        } elseif (in_array($statusClean, ['Stopped', 'Stationary Standstill Logged'], true)) {
            $dbStatus = 'Stopped';
        } elseif (in_array($statusClean, ['No Sighting on Transit', 'No Sighting on Transit (Advisory Note)'], true)) {
            $dbStatus = 'Acknowledged';
            $prefix = '[No Sighting on Transit]';
            $cleanNote = empty($cleanNote) ? $prefix : ($prefix . ' ' . $cleanNote);
        } elseif ($statusClean === 'False Alert') {
            // Legacy / fallback: Drivers cannot declare false alerts; map to Acknowledged with remark
            $dbStatus = 'Acknowledged';
            $prefix = '[Driver Advisory: Unverified sighting]';
            $cleanNote = empty($cleanNote) ? $prefix : ($prefix . ' ' . $cleanNote);
        }

        // 3. Robust Idempotency & Concurrency Strategy with Bounded Retries
        $maxAttempts = 3;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;

            // Ensure clean transaction boundary
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            try {
                $db->beginTransaction();

                // Check for existing acknowledgement by this driver for this alert
                $driverName = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
                $forUpdate = ($driverName === 'sqlite') ? '' : 'FOR UPDATE';
                $checkStmt = $db->prepare("
                    SELECT id FROM driver_acknowledgements
                    WHERE alert_id = :alert_id AND driver_id = :driver_id
                    ORDER BY acknowledged_at DESC LIMIT 1
                    {$forUpdate}
                ");
                $checkStmt->execute([':alert_id' => $alertId, ':driver_id' => $driverId]);
                $existingId = $checkStmt->fetchColumn();

                if ($existingId) {
                    // Update existing record idempotently
                    $updateStmt = $db->prepare("
                        UPDATE driver_acknowledgements
                        SET action_status = :action_status, note = :note, acknowledged_at = NOW()
                        WHERE id = :id
                    ");
                    $updateStmt->execute([
                        ':action_status' => $dbStatus,
                        ':note' => $cleanNote,
                        ':id' => $existingId
                    ]);

                    $ackRecordId = (int)$existingId;
                    $eventType = 'UPDATED_ACKNOWLEDGEMENT';
                } else {
                    // Insert new acknowledgement record
                    $insertStmt = $db->prepare("
                        INSERT INTO driver_acknowledgements (alert_id, driver_id, action_status, note, acknowledged_at)
                        VALUES (:alert_id, :driver_id, :action_status, :note, NOW())
                    ");
                    $insertStmt->execute([
                        ':alert_id' => $alertId,
                        ':driver_id' => $driverId,
                        ':action_status' => $dbStatus,
                        ':note' => $cleanNote
                    ]);

                    $ackRecordId = (int)$db->lastInsertId();
                    $eventType = 'FIRST_ACKNOWLEDGEMENT';
                }

                // Append-only audit history recording (RE-01 Remediation)
                if ($simulateAuditFailure) {
                    throw new \PDOException("Simulated audit-event insertion failure for atomicity testing", 45000);
                }

                if (self::hasAuditTable($db)) {
                    $auditStmt = $db->prepare("
                        INSERT INTO driver_acknowledgement_audits
                        (acknowledgement_id, alert_id, driver_id, action_status, note, event_type, source_ip, user_agent, created_at)
                        VALUES
                        (:ack_id, :alert_id, :driver_id, :action_status, :note, :event_type, :source_ip, :user_agent, NOW())
                    ");
                    $auditStmt->execute([
                        ':ack_id' => $ackRecordId,
                        ':alert_id' => $alertId,
                        ':driver_id' => $driverId,
                        ':action_status' => $dbStatus,
                        ':note' => $cleanNote,
                        ':event_type' => $eventType,
                        ':source_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        ':user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/System', 0, 255)
                    ]);
                }

                $db->commit();
                require_once __DIR__ . '/../core/Auth.php';
                $logMsg = ($eventType === 'FIRST_ACKNOWLEDGEMENT') 
                    ? "Driver acknowledged Alert #{$alertId} as '{$dbStatus}'." 
                    : "Driver updated acknowledgement for Alert #{$alertId} as '{$dbStatus}'.";
                Auth::logActivity($driverId, ($eventType === 'FIRST_ACKNOWLEDGEMENT' ? 'Driver Acknowledged Alert' : 'Driver Updated Alert Acknowledgement'), $logMsg);
                return true;
            } catch (\PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                $sqlState = (string)$e->getCode();
                $errInfo = (int)($e->errorInfo[1] ?? 0);

                // Deadlock (1213 / SQLSTATE 40001), Lock wait timeout (1205 / SQLSTATE HY000), or Unique violation (1062 / SQLSTATE 23000)
                $isRetryable = ($errInfo === 1213 || $errInfo === 1205 || $errInfo === 1062 || $sqlState === '40001' || $sqlState === '23000');

                if ($isRetryable && $attempt < $maxAttempts && !$simulateAuditFailure) {
                    usleep(random_int(10000, 50000)); // 10ms - 50ms jittered backoff
                    continue;
                }

                error_log("AlertService::acknowledgeAlert PDO error (attempt {$attempt}/{$maxAttempts}): " . $e->getMessage());
                return false;
            } catch (\Throwable $t) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log("AlertService::acknowledgeAlert unexpected error: " . $t->getMessage());
                return false;
            }
        }

        return false;
    }

    /**
     * Parses and independently validates sighting observation date and time.
     * Enforces strict safety rules:
     * - Only constructs timestamp when BOTH valid date and valid time are present.
     * - Never substitutes report creation time, alert creation time, or broadcast time.
     * - Never invents midnight (00:00:00) when time is missing.
     * - Returns observed_at = null and observed_display = 'Observation time unrecorded' when unknown or invalid.
     *
     * @param string|null $sDate
     * @param string|null $sTime
     * @return array{observed_ts: int|null, observed_at: string|null, observed_display: string, has_verified_observation_time: bool}
     */
    public static function parseObservationTimestamp(?string $sDate, ?string $sTime): array {
        $observedTs = null;
        $observedAt = null;
        $observedDisplay = 'Observation time unrecorded';

        $cleanDate = ($sDate !== null) ? trim($sDate) : '';
        $cleanTime = ($sTime !== null) ? trim($sTime) : '';

        // Only construct when BOTH actual observation date and observation time are available and non-empty
        if ($cleanDate !== '' && $cleanTime !== '') {
            $dParts = explode('-', $cleanDate);
            $isDateValid = (count($dParts) === 3 && checkdate((int)$dParts[1], (int)$dParts[2], (int)$dParts[0]));
            $isTimeValid = (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $cleanTime);

            if ($isDateValid && $isTimeValid) {
                $combinedStr = $cleanDate . ' ' . $cleanTime;
                $ts = strtotime($combinedStr);
                if ($ts !== false && $ts > 0) {
                    $observedTs = $ts;
                    $observedAt = date('Y-m-d H:i:s', $ts);
                    $observedDisplay = date('d-M-Y h:i A', $ts);
                }
            }
        }

        return [
            'observed_ts' => $observedTs,
            'observed_at' => $observedAt,
            'observed_display' => $observedDisplay,
            'has_verified_observation_time' => ($observedAt !== null)
        ];
    }

    /**
     * Retrieve active driver advisories, strictly enforcing coordinate privacy and
     * authoritative railway safety standards (zero unauthoritative numerical speeds,
     * zero substitution of missing sighting timestamps).
     *
     * @param int|null $sectionId
     * @return array
     */
    public static function getDriverAdvisories(?int $sectionId = null): array {
        $db = Database::getInstance()->getConnection();

        $sql = "
            SELECT 
                a.id,
                a.report_id,
                a.section_id,
                a.alert_level,
                a.alert_message,
                a.alert_status,
                a.created_at as broadcast_at,
                s.section_name,
                s.district,
                s.start_location,
                s.end_location,
                s.risk_level as section_risk,
                u.name as creator_name,
                r.sighting_date,
                r.sighting_time,
                r.created_at as report_created_at,
                r.submission_source,
                r.provenance,
                r.report_status as report_verification_status,
                (SELECT COUNT(*) FROM report_verifications rv WHERE rv.report_id = r.id AND rv.new_status = 'Verified') as dwc_verified_count
            FROM alerts a
            JOIN railway_sections s ON a.section_id = s.id
            JOIN users u ON a.created_by = u.id
            LEFT JOIN elephant_reports r ON a.report_id = r.id
            WHERE a.alert_status = 'Active'
        ";
        $params = [];

        if ($sectionId !== null && (int)$sectionId > 0) {
            $sql .= " AND a.section_id = :section_id";
            $params[':section_id'] = (int)$sectionId;
        }

        $sql .= " ORDER BY FIELD(a.alert_level, 'Critical', 'High', 'Medium', 'Low'), a.created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        $advisories = [];

        foreach ($rows as $row) {
            $broadcastTs = strtotime($row['broadcast_at']);
            $ageMinutes = (int)max(0, round(($now - $broadcastTs) / 60));
            $isStale = ($ageMinutes > 120);

            // Determine Observation Timestamp strictly without fallback substitution
            $obsData = self::parseObservationTimestamp($row['sighting_date'] ?? null, $row['sighting_time'] ?? null);
            $observedTs = $obsData['observed_ts'];
            $observedAt = $obsData['observed_at'];
            $observedDisplay = $obsData['observed_display'];

            // Determine DWC Verification Status
            $isDwcVerified = ($row['dwc_verified_count'] > 0) 
                || ($row['report_verification_status'] === 'Verified') 
                || ($row['submission_source'] === 'wildlife_pwa');

            if ($isDwcVerified) {
                $verificationBadge = 'DWC Ranger Verified';
                $verificationClass = 'badge-success';
            } else {
                $verificationBadge = 'Provisional Observation';
                $verificationClass = 'badge-warning';
            }

            // Neutral Hazard-Severity Classification (NO numerical speed recommendations)
            $level = ucfirst(strtolower($row['alert_level']));
            $hazardClassification = $level . ' Hazard Advisory';
            $advisoryNotice = 'Supplementary hazard information only. Follow official Caution Orders and direct Train Controller instructions.';

            $advisories[] = [
                'id' => (int)$row['id'],
                'report_id' => $row['report_id'] ? (int)$row['report_id'] : null,
                'section_id' => (int)$row['section_id'],
                'section_name' => $row['section_name'],
                'district' => $row['district'],
                'start_location' => $row['start_location'],
                'end_location' => $row['end_location'],
                'section_risk' => $row['section_risk'],
                'alert_level' => $level,
                'hazard_classification' => $hazardClassification,
                'alert_message' => $row['alert_message'],
                'alert_status' => $row['alert_status'],
                'creator_name' => $row['creator_name'],
                'broadcast_at' => $row['broadcast_at'],
                'broadcast_display' => date('d-M-Y h:i A', $broadcastTs),
                'observed_at' => $observedAt,
                'observed_display' => $observedDisplay,
                'has_verified_observation_time' => ($observedTs !== null),
                'verification_badge' => $verificationBadge,
                'verification_class' => $verificationClass,
                'is_dwc_verified' => (bool)$isDwcVerified,
                'advisory_notice' => $advisoryNotice,
                'speed_disclaimer' => $advisoryNotice,
                'age_minutes' => $ageMinutes,
                'is_stale' => $isStale,
                'freshness_status' => $isStale ? 'Stale (>2h)' : 'Current'
            ];
        }

        return $advisories;
    }

    /**
     * Get pre-departure corridor briefing summary metrics.
     *
     * @param int|null $sectionId
     * @return array
     */
    public static function getDriverBriefingSummary(?int $sectionId = null): array {
        $advisories = self::getDriverAdvisories($sectionId);
        $total = count($advisories);

        $criticalCount = 0;
        $highCount = 0;
        $mediumCount = 0;
        $lowCount = 0;
        $hasStale = false;

        foreach ($advisories as $adv) {
            $lvl = strtolower($adv['alert_level']);
            if ($lvl === 'critical') $criticalCount++;
            elseif ($lvl === 'high') $highCount++;
            elseif ($lvl === 'medium') $mediumCount++;
            elseif ($lvl === 'low') $lowCount++;

            if ($adv['is_stale']) {
                $hasStale = true;
            }
        }

        $highestLevel = 'None';
        if ($criticalCount > 0) $highestLevel = 'Critical';
        elseif ($highCount > 0) $highestLevel = 'High';
        elseif ($mediumCount > 0) $highestLevel = 'Medium';
        elseif ($lowCount > 0) $highestLevel = 'Low';

        $corridorName = 'All Network Corridors';
        if ($sectionId !== null && (int)$sectionId > 0) {
            $sec = self::getSectionById((int)$sectionId);
            if ($sec) {
                $corridorName = $sec['section_name'];
            }
        }

        return [
            'total_active_alerts' => $total,
            'critical_count' => $criticalCount,
            'high_count' => $highCount,
            'medium_count' => $mediumCount,
            'low_count' => $lowCount,
            'highest_level' => $highestLevel,
            'corridor_name' => $corridorName,
            'has_stale_data' => $hasStale,
            'briefing_timestamp' => date('d-M-Y h:i A')
        ];
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
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $action = $r['action_status'];
            $note = $r['note'] ?? '';
            if ($action === 'Acknowledged') {
                if (stripos($note, '[No Sighting on Transit]') !== false) {
                    $r['action_status_display'] = 'No Sighting on Transit (Advisory Note)';
                } else {
                    $r['action_status_display'] = 'Acknowledged — Advisory Received';
                }
            } elseif ($action === 'Slowing Down') {
                $r['action_status_display'] = 'Slowing Down (Historical)';
            } elseif ($action === 'Stopped') {
                $r['action_status_display'] = 'Stopped (Historical)';
            } else {
                $r['action_status_display'] = $action;
            }
        }
        unset($r);

        return $rows;
    }

    public static function countAcknowledgements() {
        $db = Database::getInstance()->getConnection();
        return (int)$db->query("SELECT COUNT(*) FROM driver_acknowledgements")->fetchColumn();
    }

    /**
     * Retrieve immutable append-only audit history for driver acknowledgements.
     * Milestone 9C Stage 4E.4.3-I: Forensic Audit Trail (RE-01 Remediation)
     *
     * @param int|null $alertId
     * @param int|null $driverId
     * @return array
     */
    public static function getAcknowledgementHistory($alertId = null, $driverId = null): array {
        $db = Database::getInstance()->getConnection();
        if (!self::hasAuditTable($db)) {
            return [];
        }

        $sql = "
            SELECT a.*, u.name as driver_name, u.email as driver_email, al.alert_level, al.alert_message
            FROM driver_acknowledgement_audits a
            LEFT JOIN users u ON a.driver_id = u.id
            LEFT JOIN alerts al ON a.alert_id = al.id
            WHERE 1=1
        ";
        $params = [];
        if ($alertId) {
            $sql .= " AND a.alert_id = :alert_id";
            $params[':alert_id'] = (int)$alertId;
        }
        if ($driverId) {
            $sql .= " AND a.driver_id = :driver_id";
            $params[':driver_id'] = (int)$driverId;
        }
        $sql .= " ORDER BY a.created_at ASC, a.id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $action = $r['action_status'];
            $note = $r['note'] ?? '';
            if ($action === 'Acknowledged') {
                if (stripos($note, '[No Sighting on Transit]') !== false) {
                    $r['action_status_display'] = 'No Sighting on Transit (Advisory Note)';
                } else {
                    $r['action_status_display'] = 'Acknowledged — Advisory Received';
                }
            } elseif ($action === 'Slowing Down') {
                $r['action_status_display'] = 'Slowing Down (Historical)';
            } elseif ($action === 'Stopped') {
                $r['action_status_display'] = 'Stopped (Historical)';
            } else {
                $r['action_status_display'] = $action;
            }
        }
        unset($r);

        return $rows;
    }

    /**
     * Check if the driver_acknowledgement_audits table exists in active connection.
     *
     * @param \PDO $db
     * @return bool
     */
    public static function hasAuditTable($db): bool {
        static $cached = [];
        try {
            $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='driver_acknowledgement_audits'");
                return ($stmt && $stmt->fetchColumn() !== false);
            }
            $dbName = $db->query("SELECT DATABASE()")->fetchColumn();
            if (!$dbName) {
                return false;
            }
            if (isset($cached[$dbName])) {
                return $cached[$dbName];
            }
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'driver_acknowledgement_audits'
            ");
            $stmt->execute([':db' => $dbName]);
            $exists = ((int)$stmt->fetchColumn() > 0);
            $cached[$dbName] = $exists;
            return $exists;
        } catch (\Throwable $e) {
            return false;
        }
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
