<?php
/**
 * Driver Alert Acknowledgement Details page
 * Milestone 9C Stage 4E.4.3-I: Receipt-Only Driver Acknowledgement
 */
$pageTitle = "Acknowledge Safety Advisory";
$allowed_roles = 'driver';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';
require_once __DIR__ . '/../../app/includes/validation.php';

$errors = [];
$alertId = $_GET['id'] ?? '';
$alert = AlertService::getAlertById((int)$alertId);

if (!$alert || $alert['alert_status'] !== 'Active') {
    Session::flash('error', 'Advisory alert is either resolved, inactive, or does not exist.');
    Router::redirect('driver/dashboard.php');
}

// Fetch enriched advisory details (includes dual timestamps, verification status, suggested speeds)
$advisories = AlertService::getDriverAdvisories((int)$alert['section_id']);
$advMeta = null;
foreach ($advisories as $a) {
    if ($a['id'] === (int)$alertId) {
        $advMeta = $a;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $actionStatus = $_POST['action_status'] ?? '';
    $note = trim($_POST['note'] ?? '');

    $reqErrors = Validation::required($_POST, ['action_status']);
    $errors = array_merge($errors, $reqErrors);

    if (empty($errors)) {
        // Enforce driver ID strictly from authenticated session
        $success = AlertService::acknowledgeAlert(
            (int)$alertId,
            (int)$currentUser['id'],
            $actionStatus,
            $note
        );

        if ($success) {
            Session::flash('success', 'Advisory receipt acknowledged successfully. Your response has been logged into the Railway Control audit log.');
            Router::redirect('driver/dashboard.php');
        } else {
            $errors['submit'] = 'Failed to submit acknowledgement. Please check database connection.';
        }
    }
}
?>

<div class="driver-dashboard-wrap" style="max-width:960px;">

    <!-- 1. MANDATORY STATIONARY-REVIEW SAFETY BANNER -->
    <div class="driver-safety-banner" role="alert">
        <div class="driver-safety-banner-header">
            <span>⚠️</span>
            <span>STATIONARY REVIEW USE ONLY — DO NOT INTERACT WHILE IN MOTION</span>
        </div>
        <div class="driver-safety-banner-body">
            <strong>Operating Safety Regulation:</strong> Do not interact with this console while train is moving. 
            Confirm receipt and submit cab remarks only when train is stationary at a station, siding, or stop signal.
        </div>
    </div>

    <div class="dashboard-title-row">
        <h1 style="font-size:1.75rem; font-family:var(--font-heading);"> locomotive driver safety advisory</h1>
        <p class="text-secondary">Safety protocol: Select your operational response to confirm receipt of track advisory.</p>
    </div>

    <div class="grid grid-2col" style="gap:24px; margin-top:20px;">
        
        <!-- Left Panel: Sighting Advisory Details -->
        <div class="card-panel" style="border-top: 4px solid var(--danger);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <h2 style="font-size:1.3rem; margin:0; color:#fff;">Advisory Details</h2>
                <span class="<?php echo ($advMeta && $advMeta['is_dwc_verified']) ? 'badge-dwc-verified' : 'badge-provisional'; ?>">
                    <?php echo ($advMeta && $advMeta['is_dwc_verified']) ? '🛡️ DWC Ranger Verified' : '👁️ Provisional Observation'; ?>
                </span>
            </div>
            
            <table class="data-table" style="font-size:0.92rem; border-bottom:1px solid var(--border-color);">
                <tr>
                    <td style="color:var(--text-secondary); width:40%;">Track Section:</td>
                    <td><strong><?php echo e($alert['section_name']); ?></strong></td>
                </tr>
                <tr>
                    <td style="color:var(--text-secondary);">Route Corridor:</td>
                    <td><?php echo e($alert['start_location']); ?> ↔ <?php echo e($alert['end_location']); ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text-secondary);">District:</td>
                    <td><?php echo e($alert['district']); ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text-secondary);">Section Risk:</td>
                    <td><span class="badge <?php echo get_risk_badge_class($alert['section_risk']); ?>"><?php echo e($alert['section_risk']); ?></span></td>
                </tr>
                <tr>
                    <td style="color:var(--text-secondary);">Hazard Level:</td>
                    <td><span class="badge <?php echo get_risk_badge_class($alert['alert_level']); ?>"><?php echo e($alert['alert_level']); ?> HAZARD</span></td>
                </tr>
                <tr>
                    <td style="color:var(--text-secondary);">Sighting Observed:</td>
                    <td><strong><?php echo $advMeta ? e($advMeta['observed_display']) : 'Observation time unrecorded'; ?></strong></td>
                </tr>
                <tr>
                    <td style="color:var(--text-secondary);">Broadcast Issued:</td>
                    <td><?php echo date('d-M-Y h:i A', strtotime($alert['created_at'])); ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text-secondary);">Broadcast By:</td>
                    <td><?php echo e($alert['creator_name']); ?> (Railway Control)</td>
                </tr>
            </table>

            <!-- Neutral Hazard Classification & Safety Reminder -->
            <div class="driver-speed-advisory-box" style="margin-top:16px;">
                <div class="driver-speed-title">
                    <span>⚠️</span>
                    <span>Hazard Classification: <?php echo e($alert['alert_level']); ?> Hazard Level</span>
                </div>
                <div class="driver-speed-disclaimer">
                    <strong>Supplementary hazard information only. Follow official Caution Orders and direct Train Controller instructions.</strong>
                </div>
            </div>
            
            <div style="margin-top:16px; background-color:rgba(0,0,0,0.3); padding:14px; border-radius:6px; border:1px solid var(--border-color);">
                <strong style="color:var(--text-secondary); font-size:0.85rem; display:block; margin-bottom:5px;">CONTROL BROADCAST MESSAGE:</strong>
                <p style="font-size:1.05rem; line-height:1.45; color:#fff; font-weight:600; margin:0;"><?php echo e($alert['alert_message']); ?></p>
            </div>
        </div>

        <!-- Right Panel: Driver Action Form (Receipt-Only Semantics) -->
        <div class="card-panel">
            <h2 style="font-size:1.3rem; margin-bottom:15px; color:#fff;">Confirm Receipt &amp; Status</h2>

            <?php if (isset($errors['submit'])): ?>
                <div class="alert alert-danger"><?php echo e($errors['submit']); ?></div>
            <?php endif; ?>

            <form action="<?php echo Router::url('driver/alert_details.php?id=' . (int)$alertId); ?>" method="POST">
                <?php echo csrf_field(); ?>

                <div class="form-group">
                    <label class="form-label" style="font-weight:700; font-size:0.95rem; margin-bottom:10px;">Select Response Status *</label>
                    
                    <div class="radio-card-group">
                        <!-- Option 1: Briefing Noted -->
                        <label class="radio-card driver-radio-touch">
                            <input type="radio" name="action_status" value="Briefing Noted (Standard Vigilance)" checked required>
                            <div class="radio-card-content">
                                <span class="radio-title" style="color:#0f9960;">Briefing Noted (Standard Vigilance)</span>
                                <span class="radio-desc">Advisory reviewed at standstill prior to departure. Standard vigilance maintained per operating rules.</span>
                            </div>
                        </label>

                        <!-- Option 2: Acknowledged — Advisory Received -->
                        <label class="radio-card driver-radio-touch warning-dark">
                            <input type="radio" name="action_status" value="Acknowledged — Advisory Received">
                            <div class="radio-card-content">
                                <span class="radio-title" style="color:#ffb300;">Acknowledged — Advisory Received</span>
                                <span class="radio-desc">Advisory received and logged. Operating under official Caution Orders and operating rules.</span>
                            </div>
                        </label>

                        <!-- Option 3: Stationary Standstill Logged -->
                        <label class="radio-card driver-radio-touch danger">
                            <input type="radio" name="action_status" value="Stationary Standstill Logged">
                            <div class="radio-card-content">
                                <span class="radio-title" style="color:#f44336;">Stationary Standstill Logged</span>
                                <span class="radio-desc">Locomotive currently stationary at station, signal, or siding.</span>
                            </div>
                        </label>

                        <!-- Option 4: No Sighting on Transit (Advisory Note) -->
                        <label class="radio-card driver-radio-touch muted">
                            <input type="radio" name="action_status" value="No Sighting on Transit (Advisory Note)">
                            <div class="radio-card-content">
                                <span class="radio-title" style="color:#8a9ba8;">No Sighting on Transit (Advisory Note)</span>
                                <span class="radio-desc">Transit completed; no elephant observed in sector. (Advisory note only; does NOT clear track or resolve alert).</span>
                            </div>
                        </label>
                    </div>
                    
                    <?php if (isset($errors['action_status'])): ?>
                        <span class="field-error" style="color:var(--danger); font-size:0.8rem;"><?php echo e($errors['action_status']); ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-top:16px;">
                    <label for="note" class="form-label" style="font-weight:600;">Driver Cab Notes / Remarks (Optional)</label>
                    <textarea name="note" id="note" class="form-control" placeholder="Optional administrative note. Do not enter speed, braking, or movement instructions." style="height: 80px;"></textarea>
                </div>

                <div class="form-actions" style="margin-top:20px;">
                    <button type="submit" class="btn btn-accent btn-lg driver-touch-btn" style="width:100%;">
                        ✔️ Submit Advisory Response
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.radio-card-group {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 8px;
}

.radio-card {
    background-color: var(--bg-dark);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 12px 16px;
    display: flex;
    gap: 12px;
    cursor: pointer;
    align-items: flex-start;
    transition: all 0.2s;
    min-height: 56px;
}

.radio-card:hover {
    border-color: var(--text-muted);
}

.radio-card input[type="radio"] {
    margin-top: 4px;
    accent-color: var(--accent-color);
    width: 18px;
    height: 18px;
}

.radio-card-content {
    display: flex;
    flex-direction: column;
    line-height: 1.35;
}

.radio-title {
    font-size: 0.95rem;
    font-weight: 700;
}

.radio-desc {
    font-size: 0.78rem;
    color: var(--text-secondary);
    margin-top: 3px;
}
</style>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
