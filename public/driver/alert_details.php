<?php
/**
 * Driver Alert Acknowledgement Details page
 */
$pageTitle = "Acknowledge Warning";
$allowed_roles = 'driver';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';
require_once __DIR__ . '/../../app/includes/validation.php';

$errors = [];
$alertId = $_GET['id'] ?? '';
$alert = AlertService::getAlertById((int)$alertId);

if (!$alert || $alert['alert_status'] !== 'Active') {
    Session::flash('error', 'Alert is either resolved, false alarm, or does not exist.');
    Router::redirect('driver/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $actionStatus = $_POST['action_status'] ?? '';
    $note = trim($_POST['note'] ?? '');

    $reqErrors = Validation::required($_POST, ['action_status']);
    $errors = array_merge($errors, $reqErrors);

    if (empty($errors)) {
        $success = AlertService::acknowledgeAlert(
            (int)$alertId,
            $currentUser['id'],
            $actionStatus,
            $note
        );

        if ($success) {
            Session::flash('success', 'Warning alert acknowledged successfully. Your speed and status response have been logged at Railway Control.');
            Router::redirect('driver/dashboard.php');
        } else {
            $errors['submit'] = 'Failed to submit acknowledgement. Please check database connection.';
        }
    }
}
?>

<div class="dashboard-title-row">
    <h1>Acknowledge Track Sighting Alert</h1>
    <p class="text-secondary">Safety protocol: Select your speed or action response to confirm sighting receipt.</p>
</div>

<div class="grid grid-2col" style="max-width: 900px; gap:30px;">
    
    <!-- Left Panel: Sighting Details -->
    <div class="card-panel" style="border-top: 4px solid var(--danger);">
        <h2 style="font-size:1.4rem; margin-bottom:15px; color:#fff;">Sighting Alert Details</h2>
        
        <table class="data-table" style="font-size:0.95rem; border-bottom:1px solid var(--border-color);">
            <tr>
                <td style="color:var(--text-secondary); width:40%;">Track Section:</td>
                <td><strong><?php echo e($alert['section_name']); ?></strong></td>
            </tr>
            <tr>
                <td style="color:var(--text-secondary);">Start Location:</td>
                <td><?php echo e($alert['start_location']); ?></td>
            </tr>
            <tr>
                <td style="color:var(--text-secondary);">End Location:</td>
                <td><?php echo e($alert['end_location']); ?></td>
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
                <td style="color:var(--text-secondary);">Time Issued:</td>
                <td><?php echo format_date($alert['created_at']); ?></td>
            </tr>
            <tr>
                <td style="color:var(--text-secondary);">Issued By:</td>
                <td><?php echo e($alert['creator_name']); ?> (Railway Control)</td>
            </tr>
        </table>
        
        <div style="margin-top:20px; background-color:rgba(0,0,0,0.2); padding:15px; border-radius:6px; border:1px solid var(--border-color);">
            <strong style="color:var(--text-secondary); font-size:0.9rem; display:block; margin-bottom:5px;">CONTROL BROADCAST MESSAGE:</strong>
            <p style="font-size:1.1rem; line-height:1.4; color:#fff; font-weight:600;"><?php echo e($alert['alert_message']); ?></p>
        </div>
    </div>

    <!-- Right Panel: Driver Action Form -->
    <div class="card-panel">
        <h2 style="font-size:1.4rem; margin-bottom:15px; color:#fff;">Confirm Action Taken</h2>

        <?php if (isset($errors['submit'])): ?>
            <div class="alert alert-danger"><?php echo e($errors['submit']); ?></div>
        <?php endif; ?>

        <form action="<?php echo Router::url('driver/alert_details.php?id=' . $alertId); ?>" method="POST">
            <?php echo csrf_field(); ?>

            <div class="form-group">
                <label class="form-label" style="font-weight:600; font-size:0.95rem;">Select Cab Response Status *</label>
                
                <div class="radio-card-group">
                    <label class="radio-card">
                        <input type="radio" name="action_status" value="Acknowledged" checked required>
                        <div class="radio-card-content">
                            <span class="radio-title">Acknowledged</span>
                            <span class="radio-desc">Message read. Proceeding at standard cautious speed.</span>
                        </div>
                    </label>

                    <label class="radio-card warning-dark">
                        <input type="radio" name="action_status" value="Slowing Down">
                        <div class="radio-card-content">
                            <span class="radio-title" style="color:var(--warning-dark);">Slowing Down</span>
                            <span class="radio-desc">Reducing throttle immediately below 20 km/h.</span>
                        </div>
                    </label>

                    <label class="radio-card danger">
                        <input type="radio" name="action_status" value="Stopped">
                        <div class="radio-card-content">
                            <span class="radio-title" style="color:var(--danger);">Stopped Train</span>
                            <span class="radio-desc">Train fully stopped before segment curves.</span>
                        </div>
                    </label>

                    <label class="radio-card muted">
                        <input type="radio" name="action_status" value="False Alert">
                        <div class="radio-card-content">
                            <span class="radio-title" style="color:var(--text-muted);">False Alert</span>
                            <span class="radio-desc">Track is clear. Checked bend and found no elephants.</span>
                        </div>
                    </label>
                </div>
                
                <?php if (isset($errors['action_status'])): ?>
                    <span class="field-error" style="color:var(--danger); font-size:0.8rem;"><?php echo e($errors['action_status']); ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="note" class="form-label">Driver Cab Notes / Remarks (Optional)</label>
                <textarea name="note" id="note" class="form-control" placeholder="e.g. Sighted sector. Track is clear. / Slowed to 15km/h due to visual fog." style="height: 80px;"></textarea>
            </div>

            <div class="form-actions" style="margin-top:20px;">
                <button type="submit" class="btn btn-accent btn-lg" style="width:100%;">✔️ Submit Safety response</button>
            </div>
        </form>
    </div>
</div>

<style>
.radio-card-group {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 10px;
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
}

.radio-card:hover {
    border-color: var(--text-muted);
}

.radio-card input[type="radio"] {
    margin-top: 4px;
    accent-color: var(--accent-color);
}

.radio-card-content {
    display: flex;
    flex-direction: column;
    line-height: 1.3;
}

.radio-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--success);
}

.radio-desc {
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-top: 2px;
}
</style>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
