<?php
/**
 * EleGuard Rail — Reporter Sighting Reports & Clarification Workspace
 * 
 * Milestone 9C — Stage 4E.4.3-G: Reporter Workspace / PWA Clarification Interface
 * 
 * Capabilities:
 * 1. Multi-status filtering across all 6 canonical statuses:
 *    All, Pending, Needs Information, Clarification Submitted, Verified, Rejected
 * 2. Unassigned corridor support (LEFT JOIN railway_sections)
 * 3. In-line clarification conversation view with chronologically threaded dialogue
 * 4. Officer inquiry reply submission with CSRF validation and double-submission protection
 * 5. Role-governed coordinate privacy notice for reporters
 */

$pageTitle = "My Sighting Reports";
$allowed_roles = 'reporter';

require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/ReportService.php';
require_once __DIR__ . '/../../app/services/TemporalService.php';
require_once __DIR__ . '/../../app/services/FieldReportingService.php';
require_once __DIR__ . '/../../app/services/VerificationService.php';

$feedbackSuccess = null;
$feedbackError = null;

// Handle Clarification Response Submission from Desktop Portal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_clarification') {
    validate_csrf();

    $reportId = isset($_POST['report_id']) && is_numeric($_POST['report_id']) ? (int)$_POST['report_id'] : 0;
    $replyText = isset($_POST['reply_text']) ? trim($_POST['reply_text']) : '';
    $parentId = isset($_POST['parent_clarification_id']) && is_numeric($_POST['parent_clarification_id']) ? (int)$_POST['parent_clarification_id'] : null;

    if ($reportId <= 0) {
        $feedbackError = "Invalid report ID.";
    } elseif (empty($replyText)) {
        $feedbackError = "Response text cannot be empty.";
    } elseif (mb_strlen($replyText, 'UTF-8') > 5000) {
        $feedbackError = "Response text cannot exceed 5,000 characters.";
    } else {
        $res = VerificationService::submitClarificationReply(
            $reportId,
            $replyText,
            $parentId,
            $currentUser['id'],
            $currentUser['role']
        );

        if ($res['success']) {
            $feedbackSuccess = "Your response for Report #{$reportId} was successfully submitted to the Wildlife Officer.";
        } else {
            $feedbackError = $res['error']['detail'] ?? 'Failed to submit clarification reply.';
        }
    }
}

// Status filter & pagination
$activeFilter = isset($_GET['status']) && $_GET['status'] !== 'all' ? trim($_GET['status']) : null;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 15;

$result = FieldReportingService::getReportsByReporter(
    $currentUser['id'],
    $activeFilter,
    $page,
    $perPage,
    $currentUser['role']
);

$myReports = $result['reports'] ?? [];
$meta = $result['meta'] ?? [];
$statusCounts = $meta['status_counts'] ?? [];

$selectedReportId = isset($_GET['view']) && is_numeric($_GET['view']) ? (int)$_GET['view'] : 0;
$activeReportHistory = null;

if ($selectedReportId > 0) {
    $histResult = VerificationService::getAuditHistory($selectedReportId, $currentUser['role'], $currentUser['id']);
    if ($histResult['success']) {
        $activeReportHistory = $histResult['data'];
    }
}

// Helper to determine status badge classes
function get_report_status_badge($status) {
    switch ($status) {
        case 'Needs Information':
            return 'background: #f97316; color: #fff; border: 1px solid #ea580c;';
        case 'Clarification Submitted':
            return 'background: #0284c7; color: #fff; border: 1px solid #0369a1;';
        case 'Pending':
            return 'background: #f59e0b; color: #000; border: 1px solid #d97706;';
        case 'Verified':
            return 'background: #10b981; color: #fff; border: 1px solid #059669;';
        case 'Rejected':
            return 'background: #ef4444; color: #fff; border: 1px solid #dc2626;';
        default:
            return 'background: #64748b; color: #fff;';
    }
}
?>

<div class="dashboard-title-row">
    <div>
        <h1>My Sighting Reports</h1>
        <p class="text-secondary">Track biological verification status and communicate with Wildlife Officers.</p>
    </div>
    <div>
        <a href="<?php echo Router::url('reporter/submit_report.php'); ?>" class="btn btn-accent">
            ➕ Submit New Sighting
        </a>
    </div>
</div>

<?php if ($feedbackSuccess): ?>
    <div class="notice-box" style="background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; color: #34d399; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
        <span>✔️</span>
        <div><strong>Success:</strong> <?php echo e($feedbackSuccess); ?></div>
    </div>
<?php endif; ?>

<?php if ($feedbackError): ?>
    <div class="notice-box" style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #f87171; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
        <span>⚠️</span>
        <div><strong>Action Blocked:</strong> <?php echo e($feedbackError); ?></div>
    </div>
<?php endif; ?>

<!-- Status Filter Pills -->
<div class="filter-pills-bar" style="display: flex; gap: 8px; overflow-x: auto; margin-bottom: 20px; padding-bottom: 6px;">
    <?php
    $filters = [
        'all' => ['label' => 'All Sightings', 'count' => $statusCounts['all'] ?? 0],
        'Needs Information' => ['label' => '💬 Needs Info', 'count' => $statusCounts['needs_information'] ?? 0],
        'Pending' => ['label' => '⏳ Pending', 'count' => $statusCounts['pending'] ?? 0],
        'Clarification Submitted' => ['label' => '📨 Clarified', 'count' => $statusCounts['clarification_submitted'] ?? 0],
        'Verified' => ['label' => '✔️ Verified', 'count' => $statusCounts['verified'] ?? 0],
        'Rejected' => ['label' => '❌ Rejected', 'count' => $statusCounts['rejected'] ?? 0],
    ];
    foreach ($filters as $key => $f):
        $isActive = ($key === 'all' && empty($activeFilter)) || ($key === $activeFilter);
        $url = Router::url('reporter/my_reports.php' . ($key === 'all' ? '' : '?status=' . urlencode($key)));
    ?>
        <a href="<?php echo $url; ?>" class="status-pill <?php echo $isActive ? 'active' : ''; ?>" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 9999px; font-size: 13px; font-weight: <?php echo $isActive ? '600' : '500'; ?>; background: <?php echo $isActive ? '#10b981' : 'var(--bg-surface-elevated, #1e293b)'; ?>; color: <?php echo $isActive ? '#fff' : 'var(--text-secondary, #94a3b8)'; ?>; border: 1px solid <?php echo $isActive ? '#10b981' : 'rgba(255,255,255,0.1)'; ?>;">
            <span><?php echo $f['label']; ?></span>
            <span style="background: rgba(0,0,0,0.25); padding: 1px 7px; border-radius: 10px; font-size: 11px;">
                <?php echo $f['count']; ?>
            </span>
        </a>
    <?php endforeach; ?>
</div>

<!-- Main Grid Layout -->
<div style="display: grid; grid-template-columns: <?php echo $activeReportHistory ? '1fr 420px' : '1fr'; ?>; gap: 20px; align-items: start;">

    <!-- Left Column: Reports Table -->
    <div class="card-panel" style="background: var(--bg-surface, #1e293b); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 20px;">
        <div class="table-responsive">
            <?php if (empty($myReports)): ?>
                <div class="text-center text-muted" style="padding: 40px 0; text-align: center;">
                    <p style="font-size: 1.1rem; margin-bottom: 15px; color: var(--text-secondary);">No sighting reports found for this filter.</p>
                    <a href="<?php echo Router::url('reporter/submit_report.php'); ?>" class="btn btn-accent">➕ Submit First Sighting</a>
                </div>
            <?php else: ?>
                <table class="data-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.1); text-align: left; font-size: 12px; color: var(--text-secondary); text-transform: uppercase;">
                            <th style="padding: 10px 8px;">ID</th>
                            <th style="padding: 10px 8px;">Observation Time</th>
                            <th style="padding: 10px 8px;">Railway Section</th>
                            <th style="padding: 10px 8px;">Elephants</th>
                            <th style="padding: 10px 8px;">Urgency</th>
                            <th style="padding: 10px 8px;">Status</th>
                            <th style="padding: 10px 8px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myReports as $rep): 
                            $isRowSelected = ($selectedReportId === (int)$rep['id']);
                            $needsInfo = ($rep['report_status'] === 'Needs Information');
                        ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 13.5px; background: <?php echo $isRowSelected ? 'rgba(56, 189, 248, 0.08)' : 'transparent'; ?>;">
                                <td style="padding: 12px 8px; font-weight: 700; color: #f8fafc;">
                                    #<?php echo $rep['id']; ?>
                                </td>
                                <td style="padding: 12px 8px;">
                                    <div style="font-weight: 600; color: #f1f5f9;">
                                        <?php echo e($rep['sighting_date']); ?> <?php echo e(substr($rep['sighting_time'] ?? '', 0, 5)); ?>
                                    </div>
                                    <div style="font-size: 11px; color: var(--text-muted, #64748b); margin-top: 2px;">
                                        Filed: <?php echo format_date($rep['created_at']); ?>
                                    </div>
                                </td>
                                <td style="padding: 12px 8px;">
                                    <strong><?php echo e($rep['section_name'] ?: 'Unassigned Corridor'); ?></strong>
                                    <?php if (!empty($rep['nearest_milestone_ref'])): ?>
                                        <div style="font-size: 11px; color: #94a3b8;">
                                            Ref: <?php echo e($rep['nearest_milestone_ref']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 8px;">
                                    <span style="font-weight: 600;"><?php echo (int)$rep['elephant_count']; ?></span>
                                    <span style="font-size: 11px; color: var(--text-muted); display: block;">
                                        <?php echo e($rep['observation_category'] ?: 'Wild Herd'); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 8px;">
                                    <span class="badge <?php echo get_risk_badge_class($rep['urgency_level']); ?>" style="padding: 3px 8px; border-radius: 4px; font-size: 11px;">
                                        <?php echo e($rep['urgency_level']); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 8px;">
                                    <span class="badge" style="padding: 4px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; <?php echo get_report_status_badge($rep['report_status']); ?>">
                                        <?php echo e($rep['report_status']); ?>
                                    </span>
                                    <?php if ($needsInfo): ?>
                                        <span style="display: inline-block; width: 8px; height: 8px; background: #f97316; border-radius: 50%; margin-left: 4px;" title="Officer inquiry waiting"></span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 8px; text-align: right;">
                                    <?php 
                                    $viewUrl = Router::url('reporter/my_reports.php?view=' . $rep['id'] . ($activeFilter ? '&status=' . urlencode($activeFilter) : ''));
                                    ?>
                                    <?php if ($needsInfo): ?>
                                        <a href="<?php echo $viewUrl; ?>" class="btn btn-primary" style="padding: 4px 10px; font-size: 12px; background: #ea580c; border-color: #ea580c;">
                                            💬 Reply
                                        </a>
                                    <?php else: ?>
                                        <a href="<?php echo $viewUrl; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px;">
                                            🔍 History
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination Bar -->
                <?php if (($meta['total_pages'] ?? 1) > 1): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.08); font-size: 13px;">
                        <div>
                            Page <?php echo $meta['current_page']; ?> of <?php echo $meta['total_pages']; ?> (<?php echo $meta['total_records']; ?> total sightings)
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <?php if ($meta['current_page'] > 1): ?>
                                <a href="<?php echo Router::url('reporter/my_reports.php?page=' . ($meta['current_page'] - 1) . ($activeFilter ? '&status=' . urlencode($activeFilter) : '')); ?>" class="btn btn-secondary" style="padding: 4px 12px; font-size: 12px;">← Previous</a>
                            <?php endif; ?>
                            <?php if ($meta['current_page'] < $meta['total_pages']): ?>
                                <a href="<?php echo Router::url('reporter/my_reports.php?page=' . ($meta['current_page'] + 1) . ($activeFilter ? '&status=' . urlencode($activeFilter) : '')); ?>" class="btn btn-secondary" style="padding: 4px 12px; font-size: 12px;">Next →</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>

    <!-- Right Column: Clarification Drawer / Detail Panel (When Report Selected) -->
    <?php if ($activeReportHistory): 
        $activeRep = $activeReportHistory['report'] ?? [];
        $verifs = $activeReportHistory['verifications'] ?? [];
        $clarifies = $activeReportHistory['clarifications'] ?? [];
        $repStatus = $activeRep['report_status'] ?? 'Pending';
        $isNeedsInfo = ($repStatus === 'Needs Information');

        // Find parent officer inquiry ID if any
        $lastOfficerInquiryId = null;
        for ($i = count($clarifies) - 1; $i >= 0; $i--) {
            if ($clarifies[$i]['message_type'] === 'officer_inquiry') {
                $lastOfficerInquiryId = $clarifies[$i]['id'];
                break;
            }
        }
    ?>
        <div class="card-panel" style="background: var(--bg-surface, #1e293b); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 12px; margin-bottom: 16px;">
                <div>
                    <h3 style="margin: 0; font-size: 16px; color: #f8fafc; font-weight: 700;">
                        Report #<?php echo $selectedReportId; ?>
                    </h3>
                    <span style="font-size: 11px; color: #94a3b8; font-family: monospace;">
                        UUID: <?php echo substr($activeRep['client_report_uuid'] ?? '', 0, 13); ?>...
                    </span>
                </div>
                <span class="badge" style="padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; <?php echo get_report_status_badge($repStatus); ?>">
                    <?php echo e($repStatus); ?>
                </span>
            </div>

            <!-- Sighting Summary -->
            <div style="background: var(--bg-surface-elevated, #273549); border-radius: 6px; padding: 12px; font-size: 12.5px; margin-bottom: 16px; line-height: 1.5;">
                <div>📅 <strong>Observed:</strong> <?php echo e($activeRep['sighting_date'] ?? ''); ?> <?php echo e($activeRep['sighting_time'] ?? ''); ?></div>
                <div>🛤️ <strong>Section:</strong> <?php echo e($activeRep['section_name'] ?: 'Unassigned'); ?></div>
                <?php if (!empty($activeRep['nearest_milestone_ref'])): ?>
                    <div>📍 <strong>Milestone:</strong> <?php echo e($activeRep['nearest_milestone_ref']); ?></div>
                <?php endif; ?>
                <div>🐘 <strong>Demographics:</strong> <?php echo (int)($activeRep['elephant_count'] ?? 1); ?> elephants</div>
                <?php if (!empty($activeRep['description'])): ?>
                    <div style="margin-top: 6px; padding-top: 6px; border-top: 1px solid rgba(255,255,255,0.06); color: #cbd5e1;">
                        <em>"<?php echo e($activeRep['description']); ?>"</em>
                    </div>
                <?php endif; ?>
                <div style="margin-top: 8px; font-size: 11px; color: #94a3b8; background: rgba(0,0,0,0.25); padding: 5px 8px; border-radius: 4px;">
                    🔒 Anti-poaching privacy: Animal telemetry masked to protect wildlife.
                </div>
            </div>

            <!-- Biological Outcome Banner -->
            <?php if ($repStatus === 'Verified'): 
                $lastV = !empty($verifs) ? $verifs[0] : null;
            ?>
                <div style="background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.35); border-radius: 6px; padding: 12px; margin-bottom: 16px; font-size: 12.5px; color: #6ee7b7;">
                    <strong>✔️ Biological Verification Complete</strong><br>
                    Corroborated by Wildlife Officer<?php if ($lastV && !empty($lastV['verification_method'])) echo ' via ' . e(str_replace('_', ' ', $lastV['verification_method'])); ?>.
                    <?php if ($lastV && !empty($lastV['notes'])): ?>
                        <br><em>Notes: "<?php echo e($lastV['notes']); ?>"</em>
                    <?php endif; ?>
                </div>
            <?php elseif ($repStatus === 'Rejected'): 
                $lastR = !empty($verifs) ? $verifs[0] : null;
            ?>
                <div style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); border-radius: 6px; padding: 12px; margin-bottom: 16px; font-size: 12.5px; color: #fca5a5;">
                    <strong>❌ Observation Rejected by Wildlife Officer</strong><br>
                    <?php if ($lastR && !empty($lastR['notes'])): ?>
                        <em>Reason: "<?php echo e($lastR['notes']); ?>"</em>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Conversation Thread -->
            <h4 style="font-size: 13px; font-weight: 700; color: #e2e8f0; margin-bottom: 8px;">
                💬 Clarification Dialogue
            </h4>
            <div style="display: flex; flex-direction: column; gap: 10px; max-height: 320px; overflow-y: auto; padding-right: 4px; margin-bottom: 16px;">
                <?php if (empty($clarifies)): ?>
                    <p style="color: var(--text-muted); font-size: 12px; text-align: center; padding: 20px;">No clarification inquiries have been raised.</p>
                <?php else: ?>
                    <?php foreach ($clarifies as $c): 
                        $isOfficer = ($c['message_type'] === 'officer_inquiry');
                    ?>
                        <div style="padding: 10px 12px; border-radius: 8px; font-size: 12.5px; line-height: 1.4; <?php echo $isOfficer ? 'background: #1e3a5f; border: 1px solid #2563eb; color: #f1f5f9;' : 'background: #064e3b; border: 1px solid #10b981; color: #ecfdf5; margin-left: 20px;'; ?>">
                            <div style="display: flex; justify-content: space-between; font-size: 11px; opacity: 0.85; margin-bottom: 4px;">
                                <strong><?php echo $isOfficer ? '👮 Wildlife Officer' : '👤 You (Observer)'; ?></strong>
                                <span><?php echo format_date($c['created_at']); ?></span>
                            </div>
                            <?php if (!empty($c['parent_clarification_id'])): ?>
                                <div style="font-size: 10.5px; opacity: 0.75; font-style: italic; margin-bottom: 4px; border-left: 2px solid currentColor; padding-left: 4px;">
                                    ↳ In reply to prior inquiry
                                </div>
                            <?php endif; ?>
                            <div style="white-space: pre-wrap;"><?php echo e($c['message_text']); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Clarification Reply Form (Active only if Needs Information) -->
            <?php if ($isNeedsInfo): ?>
                <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 8px; padding: 12px;">
                    <form method="POST" action="<?php echo Router::url('reporter/my_reports.php?view=' . $selectedReportId . ($activeFilter ? '&status=' . urlencode($activeFilter) : '')); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="submit_clarification">
                        <input type="hidden" name="report_id" value="<?php echo $selectedReportId; ?>">
                        <input type="hidden" name="parent_clarification_id" value="<?php echo e($lastOfficerInquiryId); ?>">

                        <label for="reply_text" style="display: block; font-weight: 600; color: #fbbf24; font-size: 12.5px; margin-bottom: 4px;">
                            ✍️ Provide Clarifying Information
                        </label>
                        <p style="font-size: 11.5px; color: var(--text-secondary); margin-bottom: 6px;">
                            Describe animal movement direction, nearest landmark, or requested context:
                        </p>
                        <textarea id="reply_text" name="reply_text" rows="3" maxlength="5000" required style="width: 100%; box-sizing: border-box; background: var(--bg-input, #0b1329); border: 1px solid var(--border-subtle, #334155); color: #f8fafc; border-radius: 6px; padding: 8px; font-size: 13px; font-family: inherit; resize: vertical;" placeholder="Type your response here..."></textarea>
                        
                        <div style="display: flex; justify-content: flex-end; margin-top: 8px;">
                            <button type="submit" class="btn btn-primary" style="font-size: 12.5px; padding: 6px 14px; background: #ea580c; border-color: #ea580c;">
                                📨 Send Response
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <div style="text-align: right; margin-top: 14px;">
                <a href="<?php echo Router::url('reporter/my_reports.php' . ($activeFilter ? '?status=' . urlencode($activeFilter) : '')); ?>" class="btn btn-secondary" style="font-size: 12px; padding: 4px 10px;">
                    Close Panel
                </a>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
