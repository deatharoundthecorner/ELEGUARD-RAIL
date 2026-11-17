<?php
/**
 * EleGuard Rail — Verification Service & State Transition Engine
 * 
 * Milestone 9C — Stage 4E.4.3-D: Backend Verification Service & State Transition System
 * 
 * Responsibilities:
 * 1. Enforces strict server-side RBAC: Only wildlife_officer and admin may verify or reject.
 * 2. Prevents self-verification: Original report author cannot verify or reject their own report.
 * 3. Enforces biological state transition lifecycle:
 *    Pending -> Verified, Rejected, Needs Information
 *    Needs Information -> Clarification Submitted, Rejected
 *    Clarification Submitted -> Verified, Rejected, Needs Information
 * 4. Implements optimistic row-level locking (SELECT ... FOR UPDATE) inside database transactions.
 * 5. Guarantees atomic insertion into audit ledgers (report_verifications, report_clarifications,
 *    report_section_assignment_history).
 * 6. Validates verification methods and requires meaningful justifications (strlen >= 10 for rejections).
 * 7. Enforces cross-report isolation for parent clarifications and inquiry ledgers.
 * 8. Rejects client-supplied actor identities, roles, or claims.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';

class VerificationService {

    public const VERIFICATION_ROLES = ['wildlife_officer', 'admin'];
    public const SECTION_CONFIRMATION_ROLES = ['control_officer', 'admin'];
    
    public const VALID_METHODS = [
        'field_inspection',
        'station_master_vhf',
        'remote_telemetry',
        'resident_interview'
    ];

    public const VALID_DECISIONS = ['Verified', 'Rejected'];

    public const VALID_ASSIGNMENT_TYPES = [
        'initial_provisional',
        'subsequent_reassignment',
        'controller_confirmation'
    ];

    public const VALID_ASSIGNMENT_METHODS = [
        'spatial_nearest_neighbor',
        'station_master_vhf',
        'chainage_milepost_lookup',
        'manual_controller_override'
    ];

    /**
     * Verifies or rejects an elephant observation report with row-level locking and audit logging.
     * 
     * @param int $reportId Report ID
     * @param string $decision 'Verified' or 'Rejected'
     * @param string $method Verification method enum
     * @param string|null $notes Officer notes / justification
     * @param int $officerId Authenticated officer ID (from server session)
     * @param string $officerRole Authenticated officer role (from server session)
     * @param string|null $expectedPreviousStatus Optional optimistic concurrency precondition
     * @return array Standardized result envelope
     */
    public static function verifyReport(
        int $reportId,
        string $decision,
        string $method,
        ?string $notes,
        int $officerId,
        string $officerRole,
        ?string $expectedPreviousStatus = null,
        bool $allowSelfVerification = false
    ): array {
        // 1. Server-side role authorization check
        if (!in_array($officerRole, self::VERIFICATION_ROLES, true)) {
            return [
                'success' => false,
                'status_code' => 403,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/forbidden',
                    'title' => 'Forbidden Action',
                    'status' => 403,
                    'detail' => "Role '{$officerRole}' is not authorized to verify or reject reports. Only Wildlife Officers or Administrators may perform this action."
                ]
            ];
        }

        // 2. Decision validation
        if (!in_array($decision, self::VALID_DECISIONS, true)) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/invalid-decision',
                    'title' => 'Invalid Decision',
                    'status' => 422,
                    'detail' => 'Decision must be either "Verified" or "Rejected".'
                ]
            ];
        }

        // 3. Verification method validation
        if (!in_array($method, self::VALID_METHODS, true)) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/invalid-verification-method',
                    'title' => 'Invalid Verification Method',
                    'status' => 422,
                    'detail' => 'Verification method must be one of: ' . implode(', ', self::VALID_METHODS) . '.'
                ]
            ];
        }

        // 4. Notes validation: Rejection requires a meaningful justification (strlen >= 10)
        $cleanNotes = $notes !== null ? trim($notes) : '';
        if ($decision === 'Rejected' && strlen($cleanNotes) < 10) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/insufficient-rejection-reason',
                    'title' => 'Insufficient Rejection Reason',
                    'status' => 422,
                    'detail' => 'A meaningful rejection justification of at least 10 characters is mandatory when rejecting a report.'
                ]
            ];
        }

        $db = Database::getInstance()->getConnection();

        try {
            $db->beginTransaction();

            // 5. Acquire exclusive row lock
            $stmt = $db->prepare("
                SELECT id, report_status, reported_by, section_id, updated_at 
                FROM elephant_reports 
                WHERE id = :id 
                FOR UPDATE
            ");
            $stmt->execute([':id' => $reportId]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$report) {
                $db->rollBack();
                return [
                    'success' => false,
                    'status_code' => 404,
                    'error' => [
                        'type' => 'https://eleguard.rail.gov.lk/errors/not-found',
                        'title' => 'Report Not Found',
                        'status' => 404,
                        'detail' => "Observation report #{$reportId} does not exist."
                    ]
                ];
            }

            $currentStatus = $report['report_status'];
            $reportedBy = (int)$report['reported_by'];

            // 6. Anti-Self-Verification Enforcement:
            // The original author must never verify or reject their own report unless explicitly authorized (e.g. ranger elevation)
            if ($reportedBy === $officerId && !$allowSelfVerification) {
                $db->rollBack();
                return [
                    'success' => false,
                    'status_code' => 403,
                    'error' => [
                        'type' => 'https://eleguard.rail.gov.lk/errors/self-verification-prohibited',
                        'title' => 'Self-Verification Prohibited',
                        'status' => 403,
                        'detail' => "Anti-self-certification violation: You submitted report #{$reportId} (reported_by={$reportedBy}) and cannot verify or reject your own submission."
                    ]
                ];
            }

            // 7. Optimistic concurrency check (if expectedPreviousStatus supplied)
            if ($expectedPreviousStatus !== null && $currentStatus !== $expectedPreviousStatus) {
                $db->rollBack();
                return [
                    'success' => false,
                    'status_code' => 409,
                    'error' => [
                        'type' => 'https://eleguard.rail.gov.lk/errors/conflict',
                        'title' => 'Concurrent State Conflict',
                        'status' => 409,
                        'detail' => "Report #{$reportId} status was changed by another officer (current: '{$currentStatus}', expected: '{$expectedPreviousStatus}')."
                    ]
                ];
            }

            // 8. Lifecycle State Transition Validation
            if ($decision === 'Verified') {
                if ($currentStatus === 'Needs Information') {
                    $db->rollBack();
                    return [
                        'success' => false,
                        'status_code' => 409,
                        'error' => [
                            'type' => 'https://eleguard.rail.gov.lk/errors/clarification-pending',
                            'title' => 'Clarification Pending',
                            'status' => 409,
                            'detail' => "Cannot verify report #{$reportId} while it is awaiting observer clarification ('Needs Information')."
                        ]
                    ];
                }

                if ($currentStatus === 'Verified') {
                    $db->rollBack();
                    return [
                        'success' => false,
                        'status_code' => 409,
                        'error' => [
                            'type' => 'https://eleguard.rail.gov.lk/errors/already-verified',
                            'title' => 'Report Already Verified',
                            'status' => 409,
                            'detail' => "Report #{$reportId} is already in 'Verified' status."
                        ]
                    ];
                }

                if ($currentStatus === 'Rejected') {
                    $db->rollBack();
                    return [
                        'success' => false,
                        'status_code' => 409,
                        'error' => [
                            'type' => 'https://eleguard.rail.gov.lk/errors/conflict',
                            'title' => 'Invalid State Transition',
                            'status' => 409,
                            'detail' => "Cannot transition a rejected report directly to 'Verified'. Administrative dispute re-opening is required."
                        ]
                    ];
                }

                if (!in_array($currentStatus, ['Pending', 'Clarification Submitted'], true)) {
                    $db->rollBack();
                    return [
                        'success' => false,
                        'status_code' => 409,
                        'error' => [
                            'type' => 'https://eleguard.rail.gov.lk/errors/conflict',
                            'title' => 'Invalid State Transition',
                            'status' => 409,
                            'detail' => "Cannot transition report from '{$currentStatus}' to 'Verified'."
                        ]
                    ];
                }
            } elseif ($decision === 'Rejected') {
                if ($currentStatus === 'Rejected') {
                    $db->rollBack();
                    return [
                        'success' => false,
                        'status_code' => 409,
                        'error' => [
                            'type' => 'https://eleguard.rail.gov.lk/errors/already-rejected',
                            'title' => 'Report Already Rejected',
                            'status' => 409,
                            'detail' => "Report #{$reportId} is already in 'Rejected' status."
                        ]
                    ];
                }

                // If already Verified, wildlife officers and administrators may reject with mandatory justification
                if ($currentStatus === 'Verified' && !in_array($officerRole, ['wildlife_officer', 'admin'], true)) {
                    $db->rollBack();
                    return [
                        'success' => false,
                        'status_code' => 403,
                        'error' => [
                            'type' => 'https://eleguard.rail.gov.lk/errors/forbidden',
                            'title' => 'Forbidden Action',
                            'status' => 403,
                            'detail' => "Only Wildlife Officers or Administrators may reject a previously verified report."
                        ]
                    ];
                }

                if (!in_array($currentStatus, ['Pending', 'Needs Information', 'Clarification Submitted', 'Verified'], true)) {
                    $db->rollBack();
                    return [
                        'success' => false,
                        'status_code' => 409,
                        'error' => [
                            'type' => 'https://eleguard.rail.gov.lk/errors/conflict',
                            'title' => 'Invalid State Transition',
                            'status' => 409,
                            'detail' => "Cannot transition report from '{$currentStatus}' to 'Rejected'."
                        ]
                    ];
                }
            }

            // 9. Update elephant_reports.report_status
            $updateStmt = $db->prepare("
                UPDATE elephant_reports 
                SET report_status = :decision, updated_at = NOW() 
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':decision' => $decision,
                ':id' => $reportId
            ]);

            // 10. Atomically insert into report_verifications audit ledger
            $ledgerStmt = $db->prepare("
                INSERT INTO report_verifications (
                    report_id, verified_by, previous_status, new_status, verification_method, verification_notes, verified_at
                ) VALUES (
                    :rep_id, :ver_by, :prev_status, :new_status, :method, :notes, NOW()
                )
            ");
            $ledgerStmt->execute([
                ':rep_id'      => $reportId,
                ':ver_by'      => $officerId,
                ':prev_status' => $currentStatus,
                ':new_status'  => $decision,
                ':method'      => $method,
                ':notes'       => !empty($cleanNotes) ? $cleanNotes : null
            ]);
            $ledgerId = (int)$db->lastInsertId();

            $db->commit();

            Auth::logActivity($officerId, "Report {$decision}", "Report #{$reportId} transitioned from {$currentStatus} to {$decision}.");

            return [
                'success' => true,
                'status_code' => 200,
                'data' => [
                    'report_id' => $reportId,
                    'previous_status' => $currentStatus,
                    'new_status' => $decision,
                    'verified_by' => $officerId,
                    'verification_method' => $method,
                    'audit_ledger_id' => $ledgerId
                ]
            ];

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return [
                'success' => false,
                'status_code' => 500,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/transaction-error',
                    'title' => 'Verification Transaction Failure',
                    'status' => 500,
                    'detail' => 'Database transaction failed during report verification: ' . $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Requests clarifying information from the observer, transitioning report to 'Needs Information'.
     * Records both an audit ledger entry in report_verifications and an inquiry/follow-up in report_clarifications.
     * 
     * @param int $reportId Report ID
     * @param string $inquiryNotes Detailed question / clarification request
     * @param int $officerId Authenticated officer ID
     * @param string $officerRole Authenticated officer role
     * @param string|null $expectedPreviousStatus Optional concurrency precondition
     * @param int|null $parentClarificationId Optional parent clarification message ID (must belong to this report)
     * @return array Standardized result envelope
     */
    public static function requestAdditionalInfo(
        int $reportId,
        string $inquiryNotes,
        int $officerId,
        string $officerRole,
        ?string $expectedPreviousStatus = null,
        ?int $parentClarificationId = null
    ): array {
        // 1. Server-side role authorization check
        if (!in_array($officerRole, self::VERIFICATION_ROLES, true)) {
            return [
                'success' => false,
                'status_code' => 403,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/forbidden',
                    'title' => 'Forbidden Action',
                    'status' => 403,
                    'detail' => "Role '{$officerRole}' is not authorized to request clarification. Only Wildlife Officers or Administrators may request information."
                ]
            ];
        }

        // 2. Validate inquiry notes (strlen >= 10, strlen <= 5000)
        $cleanNotes = trim($inquiryNotes);
        if (strlen($cleanNotes) < 10) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/empty-notes',
                    'title' => 'Inquiry Notes Required',
                    'status' => 422,
                    'detail' => 'Specific reasoning notes of at least 10 characters are mandatory when requesting observer clarification.'
                ]
            ];
        }

        if (strlen($cleanNotes) > 5000) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/excessive-length',
                    'title' => 'Message Length Exceeded',
                    'status' => 422,
                    'detail' => 'Inquiry message cannot exceed 5,000 characters.'
                ]
            ];
        }

        // 3. Parent ID format validation
        if ($parentClarificationId !== null && $parentClarificationId <= 0) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/invalid-parent',
                    'title' => 'Invalid Parent Identifier',
                    'status' => 422,
                    'detail' => 'Parent clarification ID must be a positive integer.'
                ]
            ];
        }

        $db = Database::getInstance()->getConnection();

        try {
            $db->beginTransaction();

            // 4. Acquire exclusive row lock
            $stmt = $db->prepare("
                SELECT id, report_status, reported_by, updated_at 
                FROM elephant_reports 
                WHERE id = :id 
                FOR UPDATE
            ");
            $stmt->execute([':id' => $reportId]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$report) {
                $db->rollBack();
                return [
                    'success' => false,
                    'status_code' => 404,
                    'error' => [
                        'type' => 'https://eleguard.rail.gov.lk/errors/not-found',
                        'title' => 'Report Not Found',
                        'status' => 404,
                        'detail' => "Report #{$reportId} does not exist."
                    ]
                ];
            }

            $currentStatus = $report['report_status'];

            // 5. Precondition & Concurrency check
            if ($expectedPreviousStatus !== null && $currentStatus !== $expectedPreviousStatus) {
                $db->rollBack();
                return [
                    'success' => false,
                    'status_code' => 409,
                    'error' => [
                        'type' => 'https://eleguard.rail.gov.lk/errors/conflict',
                        'title' => 'Concurrent State Conflict',
                        'status' => 409,
                        'detail' => "Report #{$reportId} state has changed (current: '{$currentStatus}', expected: '{$expectedPreviousStatus}')."
                    ]
                ];
            }

            // Permitted transitions to 'Needs Information': from 'Pending', 'Clarification Submitted', or 'Verified'
            if ($currentStatus === 'Needs Information') {
                $db->rollBack();
                return [
                    'success' => false,
                    'status_code' => 409,
                    'error' => [
                        'type' => 'https://eleguard.rail.gov.lk/errors/conflict',
                        'title' => 'Clarification Already Open',
                        'status' => 409,
                        'detail' => "Report #{$reportId} is already awaiting observer clarification ('Needs Information')."
                    ]
                ];
            }

            if ($currentStatus === 'Rejected') {
                $db->rollBack();
                return [
                    'success' => false,
                    'status_code' => 409,
                    'error' => [
                        'type' => 'https://eleguard.rail.gov.lk/errors/conflict',
                        'title' => 'Invalid State Transition',
                        'status' => 409,
                        'detail' => "Cannot request information on a report in 'Rejected' status."
                    ]
                ];
            }

            if (!in_array($currentStatus, ['Pending', 'Clarification Submitted', 'Verified'], true)) {
                $db->rollBack();
                return [
                    'success' => false,
                    'status_code' => 409,
                    'error' => [
                        'type' => 'https://eleguard.rail.gov.lk/errors/conflict',
                        'title' => 'Invalid State Transition',
                        'status' => 409,
                        'detail' => "Cannot request information on a report in '{$currentStatus}' status."
                    ]
                ];
            }

            // 6. Cross-Report Parent Isolation Guard
            if ($parentClarificationId !== null && $parentClarificationId > 0) {
                $chkParent = $db->prepare("
                    SELECT id, report_id 
                    FROM report_clarifications 
                    WHERE id = :pid
                ");
                $chkParent->execute([':pid' => $parentClarificationId]);
                $parentRow = $chkParent->fetch(PDO::FETCH_ASSOC);

                if (!$parentRow) {
                    $db->rollBack();
                    return [
                        'success' => false,
                        'status_code' => 422,
                        'error' => [
                            'type' => 'https://eleguard.rail.gov.lk/errors/invalid-parent',
                            'title' => 'Parent Clarification Not Found',
                            'status' => 422,
                            'detail' => "Parent clarification message #{$parentClarificationId} does not exist."
                        ]
                    ];
                }

                if ((int)$parentRow['report_id'] !== $reportId) {
                    $db->rollBack();
                    return [
                        'success' => false,
                        'status_code' => 422,
                        'error' => [
                            'type' => 'https://eleguard.rail.gov.lk/errors/cross-report-violation',
                            'title' => 'Cross-Report Violation',
                            'status' => 422,
                            'detail' => "Parent clarification message #{$parentClarificationId} belongs to Report #{$parentRow['report_id']} and cannot be referenced by Report #{$reportId}."
                        ]
                    ];
                }
            } else {
                $parentClarificationId = null;
            }

            // 7. Duplicate message protection within 60 seconds
            $dupStmt = $db->prepare("
                SELECT id FROM report_clarifications 
                WHERE report_id = :rep_id 
                  AND sender_id = :sender_id 
                  AND message_text = :msg_text 
                  AND created_at >= NOW() - INTERVAL 60 SECOND
                LIMIT 1
            ");
            $dupStmt->execute([
                ':rep_id'    => $reportId,
                ':sender_id' => $officerId,
                ':msg_text'  => $cleanNotes
            ]);
            if ($dupStmt->fetch()) {
                $db->rollBack();
                return [
                    'success' => false,
                    'status_code' => 409,
                    'error' => [
                        'type' => 'https://eleguard.rail.gov.lk/errors/duplicate-message',
                        'title' => 'Duplicate Message',
                        'status' => 409,
                        'detail' => 'A duplicate inquiry message was already submitted recently. Please avoid repeated submissions.'
                    ]
                ];
            }

            // 8. Update elephant_reports.report_status
            $updateStmt = $db->prepare("
                UPDATE elephant_reports 
                SET report_status = 'Needs Information', updated_at = NOW() 
                WHERE id = :id
            ");
            $updateStmt->execute([':id' => $reportId]);

            // 9. Insert audit entry in report_verifications
            $ledgerStmt = $db->prepare("
                INSERT INTO report_verifications (
                    report_id, verified_by, previous_status, new_status, verification_method, verification_notes, verified_at
                ) VALUES (
                    :rep_id, :ver_by, :prev_status, 'Needs Information', 'remote_telemetry', :notes, NOW()
                )
            ");
            $ledgerStmt->execute([
                ':rep_id'      => $reportId,
                ':ver_by'      => $officerId,
                ':prev_status' => $currentStatus,
                ':notes'       => $cleanNotes
            ]);
            $ledgerId = (int)$db->lastInsertId();

            // 10. Insert message in report_clarifications
            $msgType = ($currentStatus === 'Clarification Submitted') ? 'officer_followup' : 'officer_inquiry';
            $clarifStmt = $db->prepare("
                INSERT INTO report_clarifications (
                    report_id, parent_clarification_id, inquiry_ledger_id, sender_id, sender_role, message_type, message_text, created_at
                ) VALUES (
                    :rep_id, :parent_id, :ledger_id, :sender_id, :sender_role, :msg_type, :msg_text, NOW()
                )
            ");
            $clarifStmt->execute([
                ':rep_id'      => $reportId,
                ':parent_id'   => $parentClarificationId,
                ':ledger_id'   => $ledgerId,
                ':sender_id'   => $officerId,
                ':sender_role' => in_array($officerRole, ['wildlife_officer', 'admin'], true) ? $officerRole : 'wildlife_officer',
                ':msg_type'    => $msgType,
                ':msg_text'    => $cleanNotes
            ]);
            $clarifId = (int)$db->lastInsertId();

            $db->commit();

            Auth::logActivity($officerId, "Report Clarification Requested", "Report #{$reportId} status set to Needs Information.");

            return [
                'success' => true,
                'status_code' => 200,
                'data' => [
                    'report_id' => $reportId,
                    'previous_status' => $currentStatus,
                    'new_status' => 'Needs Information',
                    'requested_by' => $officerId,
                    'inquiry_notes' => $cleanNotes,
                    'audit_ledger_id' => $ledgerId,
                    'clarification_message_id' => $clarifId,
                    'message_type' => $msgType,
                    'parent_clarification_id' => $parentClarificationId
                ]
            ];

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return [
                'success' => false,
                'status_code' => 500,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/transaction-error',
                    'title' => 'Database Error',
                    'status' => 500,
                    'detail' => 'Failed to record information request: ' . $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Submits a reporter response to an outstanding clarification request.
     * Transitions report to 'Clarification Submitted' while leaving original report description untouched.
     * 
     * @param int $reportId Report ID
     * @param string $replyText Observer response text
     * @param int|null $parentClarificationId Parent message ID (must belong to this report)
     * @param int $userId Authenticated user ID (must match reported_by or admin)
     * @param string $userRole Authenticated user role
     * @return array Standardized result envelope
     */
    public static function submitClarificationReply(
        int $reportId,
        string $replyText,
        ?int $parentClarificationId,
        int $userId,
        string $userRole
    ): array {
        // 1. Validate response text length (strlen >= 5, strlen <= 5000)
        $cleanReply = trim($replyText);
        if (strlen($cleanReply) < 5) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/empty-reply',
                    'title' => 'Clarification Reply Required',
                    'status' => 422,
                    'detail' => 'Clarification reply must be at least 5 characters long.'
                ]
            ];
        }

        if (strlen($cleanReply) > 5000) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/excessive-length',
                    'title' => 'Message Length Exceeded',
                    'status' => 422,
                    'detail' => 'Clarification reply cannot exceed 5,000 characters.'
                ]
            ];
        }

        // Parent ID format validation
        if ($parentClarificationId !== null && $parentClarificationId <= 0) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/invalid-parent',
                    'title' => 'Invalid Parent Identifier',
                    'status' => 422,
                    'detail' => 'Parent clarification ID must be a positive integer.'
                ]
            ];
        }

        $db = Database::getInstance()->getConnection();

        try {
            $db->beginTransaction();

            // 2. Acquire exclusive row lock on report
            $stmt = $db->prepare("
                SELECT id, report_status, reported_by, description, latitude, longitude, observer_latitude, observer_longitude 
                FROM elephant_reports 
                WHERE id = :id 
                FOR UPDATE
            ");
            $stmt->execute([':id' => $reportId]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$report) {
                $db->rollBack();
                return [
                    'success' => false,
                    'status_code' => 404,
                    'error' => [
                        'type' => 'https://eleguard.rail.gov.lk/errors/not-found',
                        'title' => 'Report Not Found',
                        'status' => 404,
                        'detail' => "Report #{$reportId} does not exist."
                    ]
                ];
            }

            $currentStatus = $report['report_status'];
            $reportedBy = (int)$report['reported_by'];

            // 3. Authorization: Only original author or admin may reply
            if ($reportedBy !== $userId && $userRole !== 'admin') {
                $db->rollBack();
                return [
                    'success' => false,
                    'status_code' => 403,
                    'error' => [
                        'type' => 'https://eleguard.rail.gov.lk/errors/forbidden',
                        'title' => 'Forbidden Action',
                        'status' => 403,
                        'detail' => "Only the original observer who submitted Report #{$reportId} (user #{$reportedBy}) or an Administrator may respond to clarification inquiries."
                    ]
                ];
            }

            // 4. State Precondition: Must be 'Needs Information'
            if ($currentStatus !== 'Needs Information') {
                $db->rollBack();
                return [
                    'success' => false,
                    'status_code' => 409,
                    'error' => [
                        'type' => 'https://eleguard.rail.gov.lk/errors/conflict',
                        'title' => 'Clarification Not Open',
                        'status' => 409,
                        'detail' => "Report #{$reportId} is in '{$currentStatus}' status and is not awaiting clarification."
                    ]
                ];
            }

            // 5. Cross-Report Parent Isolation Guard
            if ($parentClarificationId !== null && $parentClarificationId > 0) {
                $chkParent = $db->prepare("
                    SELECT id, report_id 
                    FROM report_clarifications 
                    WHERE id = :pid
                ");
                $chkParent->execute([':pid' => $parentClarificationId]);
                $parentRow = $chkParent->fetch(PDO::FETCH_ASSOC);

                if (!$parentRow) {
                    $db->rollBack();
                    return [
                        'success' => false,
                        'status_code' => 422,
                        'error' => [
                            'type' => 'https://eleguard.rail.gov.lk/errors/invalid-parent',
                            'title' => 'Parent Clarification Not Found',
                            'status' => 422,
                            'detail' => "Parent clarification message #{$parentClarificationId} does not exist."
                        ]
                    ];
                }

                if ((int)$parentRow['report_id'] !== $reportId) {
                    $db->rollBack();
                    return [
                        'success' => false,
                        'status_code' => 422,
                        'error' => [
                            'type' => 'https://eleguard.rail.gov.lk/errors/cross-report-violation',
                            'title' => 'Cross-Report Violation',
                            'status' => 422,
                            'detail' => "Parent clarification message #{$parentClarificationId} belongs to Report #{$parentRow['report_id']} and cannot be referenced by Report #{$reportId}."
                        ]
                    ];
                }
            } else {
                $parentClarificationId = null;
            }

            // 6. Duplicate message protection within 60 seconds
            $dupStmt = $db->prepare("
                SELECT id FROM report_clarifications 
                WHERE report_id = :rep_id 
                  AND sender_id = :sender_id 
                  AND message_text = :msg_text 
                  AND created_at >= NOW() - INTERVAL 60 SECOND
                LIMIT 1
            ");
            $dupStmt->execute([
                ':rep_id'    => $reportId,
                ':sender_id' => $userId,
                ':msg_text'  => $cleanReply
            ]);
            if ($dupStmt->fetch()) {
                $db->rollBack();
                return [
                    'success' => false,
                    'status_code' => 409,
                    'error' => [
                        'type' => 'https://eleguard.rail.gov.lk/errors/duplicate-message',
                        'title' => 'Duplicate Message',
                        'status' => 409,
                        'detail' => 'A duplicate clarification response was already submitted recently. Please avoid repeated submissions.'
                    ]
                ];
            }

            // 7. Append message to report_clarifications
            $senderRoleEnum = ($userRole === 'admin') ? 'admin' : 'reporter';
            $insertMsg = $db->prepare("
                INSERT INTO report_clarifications (
                    report_id, parent_clarification_id, inquiry_ledger_id, sender_id, sender_role, message_type, message_text, created_at
                ) VALUES (
                    :rep_id, :parent_id, NULL, :sender_id, :sender_role, 'reporter_response', :msg_text, NOW()
                )
            ");
            $insertMsg->execute([
                ':rep_id'      => $reportId,
                ':parent_id'   => $parentClarificationId,
                ':sender_id'   => $userId,
                ':sender_role' => $senderRoleEnum,
                ':msg_text'    => $cleanReply
            ]);
            $clarifMsgId = (int)$db->lastInsertId();

            // 8. Transition report_status to 'Clarification Submitted' (original description 100% intact)
            $updateRep = $db->prepare("
                UPDATE elephant_reports 
                SET report_status = 'Clarification Submitted', updated_at = NOW() 
                WHERE id = :id
            ");
            $updateRep->execute([':id' => $reportId]);

            // 9. Record transition in report_verifications
            $ledgerStmt = $db->prepare("
                INSERT INTO report_verifications (
                    report_id, verified_by, previous_status, new_status, verification_method, verification_notes, verified_at
                ) VALUES (
                    :rep_id, :ver_by, 'Needs Information', 'Clarification Submitted', 'remote_telemetry', :notes, NOW()
                )
            ");
            $ledgerStmt->execute([
                ':rep_id'      => $reportId,
                ':ver_by'      => $userId,
                ':notes'       => "Clarification response submitted by observer (Message #{$clarifMsgId})."
            ]);
            $ledgerId = (int)$db->lastInsertId();

            $db->commit();

            Auth::logActivity($userId, "Clarification Submitted", "Clarification reply submitted for Report #{$reportId}.");

            return [
                'success' => true,
                'status_code' => 200,
                'data' => [
                    'report_id' => $reportId,
                    'previous_status' => 'Needs Information',
                    'new_status' => 'Clarification Submitted',
                    'clarification_message_id' => $clarifMsgId,
                    'audit_ledger_id' => $ledgerId,
                    'created_at' => date('Y-m-d H:i:s'),
                    'parent_clarification_id' => $parentClarificationId
                ]
            ];

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return [
                'success' => false,
                'status_code' => 500,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/transaction-error',
                    'title' => 'Database Error',
                    'status' => 500,
                    'detail' => 'Failed to record clarification reply: ' . $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Confirms or reassigns railway section corridor attribution with dedicated audit logging.
     * CRITICAL RULE: Section attribution changes strictly NEVER modify biological report_status.
     * 
     * @param int $reportId Report ID
     * @param int $newSectionId Target section ID
     * @param string $assignmentType 'initial_provisional', 'subsequent_reassignment', or 'controller_confirmation'
     * @param string $assignmentMethod Method used to verify attribution
     * @param string $reason Operational justification
     * @param int $userId Authenticated controller / admin ID
     * @param string $userRole Authenticated controller / admin role
     * @return array Standardized result envelope
     */
    public static function confirmOrReassignSection(
        int $reportId,
        int $newSectionId,
        string $assignmentType,
        string $assignmentMethod,
        string $reason,
        int $userId,
        string $userRole
    ): array {
        // 1. Authorization: Only control_officer or admin
        if (!in_array($userRole, self::SECTION_CONFIRMATION_ROLES, true)) {
            return [
                'success' => false,
                'status_code' => 403,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/forbidden',
                    'title' => 'Forbidden Action',
                    'status' => 403,
                    'detail' => "Role '{$userRole}' is not authorized to manage railway section attribution. Only Railway Control Officers or Administrators may perform this action."
                ]
            ];
        }

        // 2. Validate section existence
        $db = Database::getInstance()->getConnection();
        $secStmt = $db->prepare("SELECT id, section_name, district FROM railway_sections WHERE id = :id");
        $secStmt->execute([':id' => $newSectionId]);
        $section = $secStmt->fetch(PDO::FETCH_ASSOC);

        if (!$section) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/invalid-section',
                    'title' => 'Invalid Section Identifier',
                    'status' => 422,
                    'detail' => "Railway section #{$newSectionId} does not exist in the platform registry."
                ]
            ];
        }

        // 3. Validate assignment type & method
        if (!in_array($assignmentType, self::VALID_ASSIGNMENT_TYPES, true)) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/invalid-assignment-type',
                    'title' => 'Invalid Assignment Type',
                    'status' => 422,
                    'detail' => 'Assignment type must be one of: ' . implode(', ', self::VALID_ASSIGNMENT_TYPES) . '.'
                ]
            ];
        }

        if (!in_array($assignmentMethod, self::VALID_ASSIGNMENT_METHODS, true)) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/invalid-assignment-method',
                    'title' => 'Invalid Assignment Method',
                    'status' => 422,
                    'detail' => 'Assignment method must be one of: ' . implode(', ', self::VALID_ASSIGNMENT_METHODS) . '.'
                ]
            ];
        }

        // 4. Validate reason (strlen >= 5)
        $cleanReason = trim($reason);
        if (strlen($cleanReason) < 5) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/empty-reason',
                    'title' => 'Assignment Reason Required',
                    'status' => 422,
                    'detail' => 'An operational justification reason of at least 5 characters is mandatory for corridor attribution.'
                ]
            ];
        }

        try {
            $db->beginTransaction();

            // 5. Acquire exclusive row lock
            $stmt = $db->prepare("
                SELECT id, section_id, section_assignment_status, report_status 
                FROM elephant_reports 
                WHERE id = :id 
                FOR UPDATE
            ");
            $stmt->execute([':id' => $reportId]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$report) {
                $db->rollBack();
                return [
                    'success' => false,
                    'status_code' => 404,
                    'error' => [
                        'type' => 'https://eleguard.rail.gov.lk/errors/not-found',
                        'title' => 'Report Not Found',
                        'status' => 404,
                        'detail' => "Report #{$reportId} does not exist."
                    ]
                ];
            }

            $prevSectionId = $report['section_id'] !== null ? (int)$report['section_id'] : null;
            $biologicalStatusBefore = $report['report_status'];

            // 6. Record assignment in report_section_assignment_history
            $histStmt = $db->prepare("
                INSERT INTO report_section_assignment_history (
                    report_id, previous_section_id, new_section_id, assignment_type, assignment_method, assignment_reason, assigned_by, assigned_at
                ) VALUES (
                    :rep_id, :prev_sec, :new_sec, :assign_type, :assign_method, :reason, :user_id, NOW()
                )
            ");
            $histStmt->execute([
                ':rep_id'        => $reportId,
                ':prev_sec'      => $prevSectionId,
                ':new_sec'       => $newSectionId,
                ':assign_type'   => $assignmentType,
                ':assign_method' => $assignmentMethod,
                ':reason'        => $cleanReason,
                ':user_id'       => $userId
            ]);
            $historyId = (int)$db->lastInsertId();

            // 7. Update elephant_reports section attribution
            // STRICT INVARIANCE: report_status is explicitly NOT touched!
            $upStmt = $db->prepare("
                UPDATE elephant_reports 
                SET section_id = :new_sec, 
                    section_assignment_status = 'controller_confirmed', 
                    updated_at = NOW() 
                WHERE id = :id
            ");
            $upStmt->execute([
                ':new_sec' => $newSectionId,
                ':id'      => $reportId
            ]);

            $db->commit();

            Auth::logActivity($userId, "Section Attribution Updated", "Report #{$reportId} assigned to Section #{$newSectionId} ({$section['section_name']}).");

            return [
                'success' => true,
                'status_code' => 200,
                'data' => [
                    'report_id' => $reportId,
                    'previous_section_id' => $prevSectionId,
                    'confirmed_section_id' => $newSectionId,
                    'section_name' => $section['section_name'],
                    'assignment_type' => $assignmentType,
                    'assignment_method' => $assignmentMethod,
                    'assignment_status' => 'controller_confirmed',
                    'assignment_history_id' => $historyId,
                    'confirmed_by' => $userId,
                    'biological_status_unaltered' => $biologicalStatusBefore
                ]
            ];

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return [
                'success' => false,
                'status_code' => 500,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/transaction-error',
                    'title' => 'Database Error',
                    'status' => 500,
                    'detail' => 'Failed to record section attribution: ' . $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Retrieves full chronological audit history for a report:
     * 1. Biological verification history (report_verifications)
     * 2. Threaded clarification dialogue (report_clarifications)
     * 3. Railway corridor attribution history (report_section_assignment_history)
     * 
     * @param int $reportId Report ID
     * @param string $requestingRole Authenticated role
     * @param int|null $requestingUserId Authenticated user ID (required for reporter ownership check)
     * @return array Standardized result envelope
     */
    public static function getAuditHistory(int $reportId, string $requestingRole, ?int $requestingUserId = null): array {
        $allowedRoles = ['admin', 'wildlife_officer', 'control_officer', 'reporter'];
        if (!in_array($requestingRole, $allowedRoles, true)) {
            return [
                'success' => false,
                'status_code' => 403,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/forbidden',
                    'title' => 'Forbidden Action',
                    'status' => 403,
                    'detail' => "Role '{$requestingRole}' is not authorized to inspect report audit histories."
                ]
            ];
        }

        $db = Database::getInstance()->getConnection();

        // 1. Check report exists
        $repStmt = $db->prepare("
            SELECT id, client_report_uuid, report_status, section_assignment_status, reported_by,
                   latitude, longitude, observer_latitude, observer_longitude, created_at, updated_at 
            FROM elephant_reports 
            WHERE id = :id
        ");
        $repStmt->execute([':id' => $reportId]);
        $report = $repStmt->fetch(PDO::FETCH_ASSOC);

        if (!$report) {
            return [
                'success' => false,
                'status_code' => 404,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/not-found',
                    'title' => 'Report Not Found',
                    'status' => 404,
                    'detail' => "Report #{$reportId} does not exist."
                ]
            ];
        }

        // 2. Role-Based Report Access Control:
        // A reporter may only inspect the history of their own reports
        if ($requestingRole === 'reporter') {
            if ($requestingUserId === null || (int)$report['reported_by'] !== $requestingUserId) {
                return [
                    'success' => false,
                    'status_code' => 403,
                    'error' => [
                        'type' => 'https://eleguard.rail.gov.lk/errors/forbidden',
                        'title' => 'Forbidden Access',
                        'status' => 403,
                        'detail' => "Reporters are only authorized to view audit history for reports they submitted."
                    ]
                ];
            }
        }

        // 3. Anti-Poaching Coordinate Privacy:
        // Precise coordinates are restricted to wildlife officers and administrators
        $hasHighPrecisionAccess = in_array($requestingRole, self::VERIFICATION_ROLES, true);
        if (!$hasHighPrecisionAccess) {
            foreach (['latitude', 'longitude', 'observer_latitude', 'observer_longitude'] as $coordCol) {
                if (isset($report[$coordCol]) && $report[$coordCol] !== null) {
                    $report[$coordCol] = round((float)$report[$coordCol], 2);
                }
            }
        }

        // 4. Fetch verifications in chronological order
        $verifStmt = $db->prepare("
            SELECT v.id, v.report_id, v.verified_by, u.name as officer_name, u.role as officer_role,
                   v.previous_status, v.new_status, v.verification_method, v.verification_notes, v.verified_at
            FROM report_verifications v
            JOIN users u ON v.verified_by = u.id
            WHERE v.report_id = :id
            ORDER BY v.verified_at ASC, v.id ASC
        ");
        $verifStmt->execute([':id' => $reportId]);
        $verifications = $verifStmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. Fetch clarifications in chronological order
        $clarifStmt = $db->prepare("
            SELECT c.id, c.report_id, c.parent_clarification_id, c.inquiry_ledger_id,
                   c.sender_id, u.name as sender_name, c.sender_role,
                   c.message_type, c.message_text, c.created_at
            FROM report_clarifications c
            JOIN users u ON c.sender_id = u.id
            WHERE c.report_id = :id
            ORDER BY c.created_at ASC, c.id ASC
        ");
        $clarifStmt->execute([':id' => $reportId]);
        $clarifications = $clarifStmt->fetchAll(PDO::FETCH_ASSOC);

        // 6. Fetch section assignment history in chronological order
        $secHistStmt = $db->prepare("
            SELECT h.id, h.report_id, h.previous_section_id, s_prev.section_name as previous_section_name,
                   h.new_section_id, s_new.section_name as new_section_name,
                   h.assignment_type, h.assignment_method, h.assignment_reason,
                   h.assigned_by, u.name as assigned_by_name, h.assigned_at
            FROM report_section_assignment_history h
            JOIN users u ON h.assigned_by = u.id
            LEFT JOIN railway_sections s_prev ON h.previous_section_id = s_prev.id
            JOIN railway_sections s_new ON h.new_section_id = s_new.id
            WHERE h.report_id = :id
            ORDER BY h.assigned_at ASC, h.id ASC
        ");
        $secHistStmt->execute([':id' => $reportId]);
        $sectionAssignments = $secHistStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'status_code' => 200,
            'data' => [
                'report' => $report,
                'verifications' => $verifications,
                'clarifications' => $clarifications,
                'section_assignments' => $sectionAssignments
            ]
        ];
    }
}
