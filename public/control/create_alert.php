<?php
/**
 * Manual Alert Creation Page
 */
$pageTitle = "Broadcast Warning Alert";
$allowed_roles = 'control_officer';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';
require_once __DIR__ . '/../../app/includes/validation.php';

$sections = AlertService::getAllSections();
$errors = [];

$sectionId = '';
$alertLevel = 'Medium';
$alertMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $sectionId = $_POST['section_id'] ?? '';
    $alertLevel = $_POST['alert_level'] ?? 'Medium';
    $alertMessage = trim($_POST['alert_message'] ?? '');

    // Validate fields
    $reqErrors = Validation::required($_POST, ['section_id', 'alert_level', 'alert_message']);
    $errors = array_merge($errors, $reqErrors);

    if (empty($errors)) {
        // Create manual alert (report_id = null)
        $alertId = AlertService::createAlert(
            null,
            (int)$sectionId,
            $alertLevel,
            $alertMessage,
            $currentUser['id']
        );

        if ($alertId) {
            Session::flash('success', "Manual warning alert broadcasted successfully (Alert #{$alertId}).");
            Router::redirect('control/alerts.php');
        } else {
            $errors['submit'] = 'Failed to broadcast alert. Please check database connection.';
        }
    }
}
?>

<div class="dashboard-title-row">
    <h1>Manual warning alert Broadcast</h1>
    <p class="text-secondary">Directly broadcast early warnings to train driver dashboards without a corresponding sighting report.</p>
</div>

<div class="card-panel" style="max-width: 700px;">
    <?php if (isset($errors['submit'])): ?>
        <div class="alert alert-danger">
            <p><?php echo e($errors['submit']); ?></p>
        </div>
    <?php endif; ?>

    <form action="<?php echo Router::url('control/create_alert.php'); ?>" method="POST">
        <?php echo csrf_field(); ?>

        <div class="form-group">
            <label for="section_id" class="form-label">Railway Section *</label>
            <select name="section_id" id="section_id" class="form-control" required>
                <option value="">-- Choose Railway Section --</option>
                <?php foreach ($sections as $sec): ?>
                    <option value="<?php echo $sec['id']; ?>" <?php echo $sectionId == $sec['id'] ? 'selected' : ''; ?>>
                        <?php echo e($sec['section_name']); ?> (<?php echo e($sec['district']); ?> - Risk: <?php echo e($sec['risk_level']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['section_id'])): ?>
                <span class="field-error" style="color:var(--danger); font-size:0.8rem; display:block; margin-top:5px;"><?php echo e($errors['section_id']); ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="alert_level" class="form-label">Warning Danger Level *</label>
            <select name="alert_level" id="alert_level" class="form-control" required>
                <option value="Low" <?php echo $alertLevel === 'Low' ? 'selected' : ''; ?>>Low Sighting</option>
                <option value="Medium" <?php echo $alertLevel === 'Medium' ? 'selected' : ''; ?>>Medium Warning</option>
                <option value="High" <?php echo $alertLevel === 'High' ? 'selected' : ''; ?>>High Danger</option>
                <option value="Critical" <?php echo $alertLevel === 'Critical' ? 'selected' : ''; ?>>Critical Collision Hazard</option>
            </select>
            <?php if (isset($errors['alert_level'])): ?>
                <span class="field-error" style="color:var(--danger); font-size:0.8rem; display:block; margin-top:5px;"><?php echo e($errors['alert_level']); ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="alert_message" class="form-label">Broadcast Alert Message *</label>
            <textarea name="alert_message" id="alert_message" class="form-control" placeholder="e.g. SIGHTING EMERGENCY: Villagers reported a herd of 3 elephants near track side fences close to Mile Post 112. Drive with caution." required style="height: 120px;"><?php echo e($alertMessage); ?></textarea>
            <?php if (isset($errors['alert_message'])): ?>
                <span class="field-error" style="color:var(--danger); font-size:0.8rem; display:block; margin-top:5px;"><?php echo e($errors['alert_message']); ?></span>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-danger">🚨 Broadcast Warning Alert</button>
            <a href="<?php echo Router::url('control/dashboard.php'); ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
