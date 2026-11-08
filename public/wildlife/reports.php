<?php
/**
 * Wildlife Sighting Reports Verification Area
 */
$pageTitle = "Verify Reports";
$allowed_roles = 'wildlife_officer';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/ReportService.php';
require_once __DIR__ . '/../../app/services/AlertService.php';
require_once __DIR__ . '/../../app/services/AiDetectionService.php';

$errors = [];

// Handle verify/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    $action = $_POST['action'] ?? '';
    $reportId = $_POST['report_id'] ?? '';
    
    if ($action === 'verify') {
        if (ReportService::updateStatus((int)$reportId, 'Verified', $currentUser['id'])) {
            Session::flash('success', "Report #{$reportId} successfully verified.");
            Router::redirect('wildlife/reports.php');
        } else {
            $errors['action'] = "Failed to update report status.";
        }
    } elseif ($action === 'reject') {
        if (ReportService::updateStatus((int)$reportId, 'Rejected', $currentUser['id'])) {
            Session::flash('success', "Report #{$reportId} successfully marked as rejected.");
            Router::redirect('wildlife/reports.php');
        } else {
            $errors['action'] = "Failed to update report status.";
        }
    } elseif ($action === 'rerun_ai') {
        if (!empty($reportId)) {
            $rep = ReportService::getById((int)$reportId);
            if ($rep && !empty($rep['image_path'])) {
                $res = AiDetectionService::processReportAi((int)$reportId, $rep['image_path']);
                Session::flash('success', "AI advisory analysis re-executed (Status: {$res['status']}).");
            } else {
                Session::flash('error', "Cannot run AI analysis: no photograph attached to this report.");
            }
            Router::redirect('wildlife/reports.php?id=' . (int)$reportId);
        }
    }
}

// Fetch single report details if ID is requested
$selectedReport = null;
$selectedId = $_GET['id'] ?? null;
if ($selectedId) {
    $selectedReport = ReportService::getById((int)$selectedId);
}

// Fetch all reports with filters
$statusFilter = $_GET['status'] ?? null;
$sectionFilter = $_GET['section_id'] ?? null;
$urgencyFilter = $_GET['urgency_level'] ?? null;

$reports = ReportService::getAll($statusFilter, $sectionFilter, $urgencyFilter);
$sections = AlertService::getAllSections();
?>

<div class="dashboard-title-row">
    <h1>Manage Elephant Movement Reports</h1>
    <p class="text-secondary">Track community and team submissions, confirm threat accuracy, and coordinate warnings.</p>
</div>

<?php if ($selectedReport): ?>
    <?php
    $annotatedWebPath = !empty($selectedReport['image_path']) ? AiDetectionService::getAnnotatedWebPath($selectedReport['image_path']) : null;
    $hasAnnotated = ($annotatedWebPath !== null);
    ?>
    <!-- Detailed Report Review Panel -->
    <div class="card-panel" style="border-top: 4px solid var(--accent-color); max-width: 900px; margin-bottom: 40px;">
        <div class="panel-header">
            <h2 class="panel-title">Reviewing Sighting Report #<?php echo $selectedReport['id']; ?></h2>
            <a href="<?php echo Router::url('wildlife/reports.php'); ?>" class="btn btn-outline btn-sm">Close Review</a>
        </div>
        
        <div class="grid grid-2col" style="gap:30px;">
            <div class="report-details-info">
                <table class="data-table" style="font-size:0.95rem;">
                    <tr>
                        <td style="color:var(--text-secondary); width:40%;">Submitted By:</td>
                        <td><strong><?php echo e($selectedReport['reporter_name']); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Date / Time:</td>
                        <td><?php echo format_date($selectedReport['created_at']); ?></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Railway Section:</td>
                        <td><strong><?php echo e($selectedReport['section_name']); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Track Section Risk:</td>
                        <td><span class="badge <?php echo get_risk_badge_class($selectedReport['section_risk_level']); ?>"><?php echo e($selectedReport['section_risk_level']); ?></span></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Location Details:</td>
                        <td>
                            <code><?php echo e($selectedReport['location_description']); ?></code>
                            <?php if (!empty($selectedReport['latitude']) && !empty($selectedReport['longitude'])): ?>
                                <div style="margin-top:6px;">
                                    <a href="https://www.google.com/maps?q=<?php echo urlencode($selectedReport['latitude'] . ',' . $selectedReport['longitude']); ?>" target="_blank" rel="noopener" style="color:var(--accent-color); text-decoration:underline; font-weight:600;">
                                        📍 GPS: <?php echo number_format($selectedReport['latitude'], 6) . ', ' . number_format($selectedReport['longitude'], 6); ?>
                                    </a>
                                    <?php if (!empty($selectedReport['gps_accuracy_meters'])): ?>
                                        <span class="text-muted" style="font-size:0.8rem;">(&plusmn;<?php echo round($selectedReport['gps_accuracy_meters']); ?>m)</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Elephant Count:</td>
                        <td><strong style="font-size:1.1rem; color:var(--accent-color);"><?php echo e($selectedReport['elephant_count']); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Sighting Urgency:</td>
                        <td><span class="badge <?php echo get_risk_badge_class($selectedReport['urgency_level']); ?>"><?php echo e($selectedReport['urgency_level']); ?></span></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Computed Risk Category:</td>
                        <td><span class="badge <?php echo get_risk_badge_class($selectedReport['risk_score']); ?>"><?php echo e($selectedReport['risk_score']); ?></span></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Report Status:</td>
                        <td>
                            <strong style="color:var(--accent-color);"><?php echo e($selectedReport['report_status']); ?></strong>
                        </td>
                    </tr>
                </table>
                
                <div style="margin-top:20px; background-color:rgba(255,255,255,0.02); padding:15px; border-radius:6px; border:1px solid var(--border-color);">
                    <h4 style="margin-bottom:8px; font-size:0.9rem; color:var(--text-secondary);">Observation Description:</h4>
                    <p style="font-size:0.9rem; line-height:1.5; color:#fff;"><?php echo nl2br(e($selectedReport['description'] ? $selectedReport['description'] : 'No description provided.')); ?></p>
                </div>
            </div>
            
            <div class="report-details-media" style="display:flex; flex-direction:column; gap:15px; align-items:center; justify-content:center;">
                <?php if (!empty($selectedReport['image_path'])): ?>
                    <div style="width:100%; display:flex; justify-content:space-between; align-items:center;">
                        <label style="font-size:0.85rem; color:var(--text-secondary); margin:0;">Sighting Photograph:</label>
                        <?php if ($hasAnnotated): ?>
                            <button type="button" id="btnToggleAiBoxes" class="btn btn-outline btn-sm" style="font-size:0.75rem; padding:4px 8px;" onclick="toggleAiBoundingBoxes()">
                                🎯 Toggle AI Bounding Boxes
                            </button>
                        <?php endif; ?>
                    </div>
                    <div style="position:relative; width:100%; text-align:center;">
                        <a id="sightingImgLink" href="<?php echo Router::url($selectedReport['image_path']); ?>" target="_blank">
                            <img id="sightingImg" src="<?php echo Router::url($selectedReport['image_path']); ?>" alt="Sighting media" style="max-width:100%; max-height:260px; border-radius:6px; border:1px solid var(--border-color); object-fit:contain; transition: opacity 0.2s ease;">
                        </a>
                        <div id="aiOverlayBadge" style="display:none; position:absolute; bottom:10px; right:10px; background:rgba(0,0,0,0.8); color:#10b981; padding:3px 8px; border-radius:4px; font-size:0.75rem; border:1px solid #10b981; font-weight:600;">
                            AI Bounding Boxes Overlay
                        </div>
                    </div>
                    <?php if ($hasAnnotated): ?>
                        <script>
                            let showingAnnotated = false;
                            const originalSrc = "<?php echo Router::url($selectedReport['image_path']); ?>";
                            const annotatedSrc = "<?php echo Router::url($annotatedWebPath); ?>";
                            function toggleAiBoundingBoxes() {
                                const img = document.getElementById('sightingImg');
                                const link = document.getElementById('sightingImgLink');
                                const badge = document.getElementById('aiOverlayBadge');
                                const btn = document.getElementById('btnToggleAiBoxes');
                                if (!img || !link) return;
                                
                                showingAnnotated = !showingAnnotated;
                                if (showingAnnotated) {
                                    img.src = annotatedSrc;
                                    link.href = annotatedSrc;
                                    badge.style.display = 'block';
                                    btn.classList.remove('btn-outline');
                                    btn.classList.add('btn-primary');
                                    btn.innerText = '📷 View Original Photo';
                                } else {
                                    img.src = originalSrc;
                                    link.href = originalSrc;
                                    badge.style.display = 'none';
                                    btn.classList.remove('btn-primary');
                                    btn.classList.add('btn-outline');
                                    btn.innerText = '🎯 Toggle AI Bounding Boxes';
                                }
                            }
                            <?php if (!empty($_GET['show_boxes'])): ?>
                            document.addEventListener('DOMContentLoaded', function() {
                                toggleAiBoundingBoxes();
                            });
                            <?php endif; ?>
                        </script>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="width:100%; height:200px; background:rgba(255,255,255,0.01); border:1px dashed var(--border-color); border-radius:6px; display:flex; justify-content:center; align-items:center; color:var(--text-muted);">
                        No Photo Attachment
                    </div>
                <?php endif; ?>
                
                <?php if ($selectedReport['report_status'] === 'Pending'): ?>
                    <div class="review-actions-wrap" style="width:100%; display:flex; gap:10px; margin-top:20px;">
                        <form action="<?php echo Router::url('wildlife/reports.php'); ?>" method="POST" style="flex:1;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="report_id" value="<?php echo $selectedReport['id']; ?>">
                            <input type="hidden" name="action" value="verify">
                            <button type="submit" class="btn btn-primary" style="width:100%;">✔️ Verify Report</button>
                        </form>
                        <form action="<?php echo Router::url('wildlife/reports.php'); ?>" method="POST" style="flex:1;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="report_id" value="<?php echo $selectedReport['id']; ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="btn btn-danger" style="width:100%;">❌ Reject Sighting</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div style="width:100%; text-align:center; padding:15px; border-radius:6px; background-color:rgba(255,255,255,0.03); color:var(--text-secondary); font-size:0.9rem;">
                        This report has already been processed (<strong><?php echo $selectedReport['report_status']; ?></strong>).
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- AI Sighting Advisory Panel -->
        <div class="ai-advisory-card" style="margin-top: 25px; padding: 20px; border-radius: 8px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; flex-wrap:wrap; gap:10px;">
                <h3 style="font-size: 1.05rem; margin:0; display:flex; align-items:center; gap:8px;">
                    <span>🤖</span>
                    <span>AI Vision Sighting Advisory</span>
                    <span style="font-size:0.75rem; font-weight:normal; color:var(--text-muted);">(YOLOX-Tiny ONNX &bull; Apache-2.0)</span>
                </h3>
                <div>
                    <?php
                    $aiSt = $selectedReport['ai_status'] ?? 'pending';
                    $detected = !empty($selectedReport['ai_detected_elephant']);
                    $conf = isset($selectedReport['ai_confidence']) ? (float)$selectedReport['ai_confidence'] : null;
                    
                    if ($aiSt === 'processed') {
                        if ($detected) {
                            echo '<span class="badge badge-success" style="font-size:0.85rem; padding:6px 12px;">🟢 Potential Elephant Detected</span>';
                        } elseif ($conf !== null && $conf >= 0.20) {
                            echo '<span class="badge badge-warning" style="font-size:0.85rem; padding:6px 12px;">🟡 Uncertain Result (Manual Review Required)</span>';
                        } else {
                            echo '<span class="badge" style="background:#4b5563; color:#fff; font-size:0.85rem; padding:6px 12px;">⚪ No Elephant Detected</span>';
                        }
                    } elseif ($aiSt === 'pending') {
                        echo '<span class="badge badge-warning" style="font-size:0.85rem; padding:6px 12px;">⏳ AI Inference Pending</span>';
                    } elseif ($aiSt === 'failed') {
                        echo '<span class="badge badge-danger" style="font-size:0.85rem; padding:6px 12px;">⚠️ AI Analysis Failed</span>';
                    } else {
                        echo '<span class="badge" style="background:#374151; color:#9ca3af; font-size:0.85rem; padding:6px 12px;">ℹ️ Skipped (No Photo)</span>';
                    }
                    ?>
                </div>
            </div>

            <!-- Mandatory Governance Disclaimer Alert -->
            <div style="background: rgba(245, 158, 11, 0.08); border-left: 4px solid #f59e0b; padding: 10px 14px; border-radius: 4px; margin-bottom: 15px; font-size: 0.85rem; line-height: 1.45; color: #fbbf24;">
                <strong>Advisory Only:</strong> This detection is an automated AI suggestion. Official report verification and railway hazard decisions must be made solely by authorized Wildlife Officers. AI outputs never modify risk scores, alter verification status, or broadcast railway alerts.
            </div>

            <div class="grid grid-2col" style="gap:15px; font-size:0.9rem;">
                <div>
                    <table class="data-table" style="font-size:0.88rem; width:100%;">
                        <tr>
                            <td style="color:var(--text-secondary); width:45%;">Detection Outcome:</td>
                            <td>
                                <?php if ($aiSt === 'processed'): ?>
                                    <strong><?php echo $detected ? 'Elephant Pattern Identified' : ($conf >= 0.20 ? 'Ambiguous / Low Confidence' : 'No Elephant Features'); ?></strong>
                                <?php elseif ($aiSt === 'skipped'): ?>
                                    <span class="text-muted">Skipped (Text-only report)</span>
                                <?php elseif ($aiSt === 'failed'): ?>
                                    <span style="color:#ef4444;">Processing Error</span>
                                <?php else: ?>
                                    <span class="text-muted">Awaiting analysis</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);">Highest Confidence:</td>
                            <td>
                                <?php if ($conf !== null): ?>
                                    <strong style="color:<?php echo $detected ? '#10b981' : ($conf >= 0.20 ? '#f59e0b' : 'inherit'); ?>;">
                                        <?php echo number_format($conf * 100, 1); ?>%
                                    </strong>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);">Estimated Count:</td>
                            <td>
                                <?php if ($selectedReport['ai_estimated_count'] !== null): ?>
                                    <strong><?php echo (int)$selectedReport['ai_estimated_count']; ?> elephant(s)</strong>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
                <div>
                    <table class="data-table" style="font-size:0.88rem; width:100%;">
                        <tr>
                            <td style="color:var(--text-secondary); width:40%;">Model Engine:</td>
                            <td><code><?php echo e($selectedReport['ai_model_version'] ?? 'YOLOX-Tiny-ONNX'); ?></code></td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);">Processed At:</td>
                            <td><?php echo !empty($selectedReport['ai_processed_at']) ? format_date($selectedReport['ai_processed_at']) : '<span class="text-muted">&mdash;</span>'; ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);">Advisory Notes:</td>
                            <td>
                                <span style="font-size:0.82rem; color:var(--text-secondary); line-height:1.4;">
                                    <?php echo e($selectedReport['ai_detection_notes'] ?? 'None recorded.'); ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <?php if (!empty($selectedReport['image_path'])): ?>
                <div style="margin-top: 15px; display:flex; justify-content:flex-end;">
                    <form action="<?php echo Router::url('wildlife/reports.php'); ?>" method="POST" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="report_id" value="<?php echo $selectedReport['id']; ?>">
                        <input type="hidden" name="action" value="rerun_ai">
                        <button type="submit" class="btn btn-outline btn-sm" style="font-size:0.8rem;">🔄 Re-run AI Advisory Analysis</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Filtering Panel -->
<div class="filter-card">
    <form action="<?php echo Router::url('wildlife/reports.php'); ?>" method="GET" class="filter-form">
        <div class="filter-group">
            <label for="status" class="form-label">Filter by Status</label>
            <select name="status" id="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="Pending" <?php echo $statusFilter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="Verified" <?php echo $statusFilter === 'Verified' ? 'selected' : ''; ?>>Verified</option>
                <option value="Rejected" <?php echo $statusFilter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                <option value="Converted to Alert" <?php echo $statusFilter === 'Converted to Alert' ? 'selected' : ''; ?>>Converted to Alert</option>
                <option value="Resolved" <?php echo $statusFilter === 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="section_id" class="form-label">Railway Section</label>
            <select name="section_id" id="section_id" class="form-control">
                <option value="">All Sections</option>
                <?php foreach ($sections as $sec): ?>
                    <option value="<?php echo $sec['id']; ?>" <?php echo $sectionFilter == $sec['id'] ? 'selected' : ''; ?>>
                        <?php echo e($sec['section_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="urgency_level" class="form-label">Urgency Level</label>
            <select name="urgency_level" id="urgency_level" class="form-control">
                <option value="">All Urgencies</option>
                <option value="Low" <?php echo $urgencyFilter === 'Low' ? 'selected' : ''; ?>>Low</option>
                <option value="Medium" <?php echo $urgencyFilter === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                <option value="High" <?php echo $urgencyFilter === 'High' ? 'selected' : ''; ?>>High</option>
                <option value="Critical" <?php echo $urgencyFilter === 'Critical' ? 'selected' : ''; ?>>Critical</option>
            </select>
        </div>

        <div class="filter-btn-group">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="<?php echo Router::url('wildlife/reports.php'); ?>" class="btn btn-secondary">Reset</a>
        </div>
    </form>
</div>

<!-- Elephant Sightings List Panel -->
<div class="card-panel">
    <div class="table-responsive">
        <?php if (empty($reports)): ?>
            <p class="text-center text-muted" style="padding: 40px 0;">No elephant movement reports match the selected filters.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date Filed</th>
                        <th>Reporter</th>
                        <th>Railway Section</th>
                        <th>Elephant Count</th>
                        <th>Photo</th>
                        <th>Urgency</th>
                        <th>Computed Risk</th>
                        <th>AI Advisory</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $rep): ?>
                        <tr>
                            <td><?php echo format_date($rep['created_at']); ?></td>
                            <td><?php echo e($rep['reporter_name']); ?></td>
                            <td>
                                <strong><?php echo e($rep['section_name']); ?></strong>
                                <?php if (!empty($rep['latitude']) && !empty($rep['longitude'])): ?>
                                    <div style="font-size:0.75rem; margin-top:2px;">
                                        <a href="https://www.google.com/maps?q=<?php echo urlencode($rep['latitude'] . ',' . $rep['longitude']); ?>" target="_blank" rel="noopener" style="color:var(--accent-color);">
                                            📍 <?php echo number_format($rep['latitude'], 4) . ', ' . number_format($rep['longitude'], 4); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo e($rep['elephant_count']); ?></td>
                            <td>
                                <?php if (!empty($rep['image_path'])): ?>
                                    <a href="<?php echo Router::url($rep['image_path']); ?>" target="_blank">
                                        <img src="<?php echo Router::url($rep['image_path']); ?>" alt="Thumbnail" class="report-thumbnail">
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo get_risk_badge_class($rep['urgency_level']); ?>">
                                    <?php echo e($rep['urgency_level']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo get_risk_badge_class($rep['risk_score']); ?>">
                                    <?php echo e($rep['risk_score']); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $rAiSt = $rep['ai_status'] ?? 'pending';
                                $rDetected = !empty($rep['ai_detected_elephant']);
                                $rConf = isset($rep['ai_confidence']) ? (float)$rep['ai_confidence'] : null;

                                if ($rAiSt === 'processed') {
                                    if ($rDetected) {
                                        $confPct = round($rConf * 100);
                                        $cnt = (int)$rep['ai_estimated_count'];
                                        echo "<span class=\"badge badge-success\" title=\"" . e($rep['ai_detection_notes']) . "\">🟢 Detected ({$confPct}%)</span>";
                                    } elseif ($rConf !== null && $rConf >= 0.20) {
                                        $confPct = round($rConf * 100);
                                        echo "<span class=\"badge badge-warning\" title=\"" . e($rep['ai_detection_notes']) . "\">🟡 Uncertain ({$confPct}%)</span>";
                                    } else {
                                        echo "<span class=\"badge\" style=\"background:#4b5563; color:#fff;\" title=\"" . e($rep['ai_detection_notes']) . "\">⚪ None</span>";
                                    }
                                } elseif ($rAiSt === 'pending') {
                                    echo "<span class=\"badge badge-warning\">⏳ Pending</span>";
                                } elseif ($rAiSt === 'failed') {
                                    echo "<span class=\"badge badge-danger\" title=\"" . e($rep['ai_detection_notes']) . "\">⚠️ Error</span>";
                                } else {
                                    echo "<span class=\"text-muted\" style=\"font-size:0.75rem;\">&mdash;</span>";
                                }
                                ?>
                            </td>
                            <td>
                                <span class="badge <?php 
                                    $st = $rep['report_status'];
                                    if ($st === 'Pending') echo 'badge-warning';
                                    elseif ($st === 'Verified') echo 'badge-success';
                                    elseif ($st === 'Converted to Alert') echo 'badge-danger';
                                    elseif ($st === 'Resolved') echo 'badge-success';
                                    else echo 'badge-outline';
                                ?>">
                                    <?php echo e($rep['report_status']); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo Router::url('wildlife/reports.php?id=' . $rep['id']); ?>" class="btn btn-outline btn-sm">Review</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
