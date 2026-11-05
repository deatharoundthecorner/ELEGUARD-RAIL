<?php
/**
 * Submit Sighting Report
 */
$pageTitle = "File Elephant Sighting";
$allowed_roles = 'reporter';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';
require_once __DIR__ . '/../../app/services/ReportService.php';
require_once __DIR__ . '/../../app/includes/validation.php';
require_once __DIR__ . '/../../app/includes/upload.php';

$sections = AlertService::getAllSections();
$errors = [];

$sectionId = '';
$elephantCount = '';
$urgencyLevel = 'Medium';
$locationDescription = '';
$description = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $sectionId = $_POST['section_id'] ?? '';
    $elephantCount = $_POST['elephant_count'] ?? '';
    $urgencyLevel = $_POST['urgency_level'] ?? 'Medium';
    $locationDescription = trim($_POST['location_description'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // Validate fields
    $reqErrors = Validation::required($_POST, ['section_id', 'elephant_count', 'urgency_level', 'location_description']);
    $errors = array_merge($errors, $reqErrors);

    if (empty($errors['elephant_count'])) {
        $numErr = Validation::numeric($elephantCount, 'Elephant Count');
        if ($numErr) {
            $errors['elephant_count'] = $numErr;
        } elseif ((int)$elephantCount <= 0) {
            $errors['elephant_count'] = 'Elephant count must be at least 1.';
        }
    }

    $imagePath = null;
    if (empty($errors) && isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadResult = Upload::saveReportImage($_FILES['image']);
        if ($uploadResult['success']) {
            $imagePath = $uploadResult['path'];
        } else {
            $errors['image'] = $uploadResult['error'];
        }
    }

    if (empty($errors)) {
        $success = ReportService::submitReport(
            (int)$sectionId,
            $currentUser['id'],
            (int)$elephantCount,
            $locationDescription,
            $urgencyLevel,
            $description,
            $imagePath
        );

        if ($success) {
            // Log submit action
            Auth::logActivity($currentUser['id'], 'Sighting Submitted', "Sighting reported in section #{$sectionId} ({$elephantCount} elephants).");
            
            Session::flash('success', 'Elephant sighting report submitted successfully. A risk score has been computed and wildlife rangers are notified.');
            Router::redirect('reporter/my_reports.php');
        } else {
            $errors['submit'] = 'Failed to submit report. Please check database connectivity.';
        }
    }
}
?>

<div class="dashboard-title-row">
    <h1>File Elephant Sighting Report</h1>
    <p class="text-secondary">Provide precise details about elephant proximity to railway tracks to safeguard lives.</p>
</div>

<div class="card-panel" style="max-width: 700px;">
    <?php if (isset($errors['submit'])): ?>
        <div class="alert alert-danger">
            <p><?php echo e($errors['submit']); ?></p>
        </div>
    <?php endif; ?>

    <form action="<?php echo Router::url('reporter/submit_report.php'); ?>" method="POST" enctype="multipart/form-data">
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
                <span class="field-error"><?php echo e($errors['section_id']); ?></span>
            <?php endif; ?>
        </div>

        <div class="grid grid-2col" style="gap:20px; margin-bottom: 0;">
            <div class="form-group">
                <label for="elephant_count" class="form-label">Elephant Count *</label>
                <input type="number" name="elephant_count" id="elephant_count" class="form-control" min="1" max="100" value="<?php echo e($elephantCount); ?>" required>
                <?php if (isset($errors['elephant_count'])): ?>
                    <span class="field-error"><?php echo e($errors['elephant_count']); ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="urgency_level" class="form-label">Sighting Urgency *</label>
                <select name="urgency_level" id="urgency_level" class="form-control" required>
                    <option value="Low" <?php echo $urgencyLevel === 'Low' ? 'selected' : ''; ?>>Low (Animal far from track)</option>
                    <option value="Medium" <?php echo $urgencyLevel === 'Medium' ? 'selected' : ''; ?>>Medium (Moving towards track)</option>
                    <option value="High" <?php echo $urgencyLevel === 'High' ? 'selected' : ''; ?>>High (Close to track line)</option>
                    <option value="Critical" <?php echo $urgencyLevel === 'Critical' ? 'selected' : ''; ?>>Critical (Directly on track/crossing)</option>
                </select>
                <?php if (isset($errors['urgency_level'])): ?>
                    <span class="field-error"><?php echo e($errors['urgency_level']); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <label for="location_description" class="form-label">Exact Location Description *</label>
            <input type="text" name="location_description" id="location_description" class="form-control" placeholder="e.g. Near Mile Post 82 curve, 200m past forest temple" value="<?php echo e($locationDescription); ?>" required>
            <?php if (isset($errors['location_description'])): ?>
                <span class="field-error"><?php echo e($errors['location_description']); ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="description" class="form-label">Additional Observation Details</label>
            <textarea name="description" id="description" class="form-control" placeholder="e.g. Elephant seems to be alone and searching for water. Sighted walking north along forest corridor."><?php echo e($description); ?></textarea>
        </div>

        <div class="form-group">
            <label for="image" class="form-label">Upload Photo (Optional, JPEG/PNG max 2MB)</label>
            <input type="file" name="image" id="image" class="form-control" accept="image/png, image/jpeg, image/jpg">
            <?php if (isset($errors['image'])): ?>
                <span class="field-error"><?php echo e($errors['image']); ?></span>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-accent">Submit Sighting Report</button>
            <a href="<?php echo Router::url('reporter/dashboard.php'); ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<style>
.field-error {
    color: var(--danger);
    font-size: 0.8rem;
    display: block;
    margin-top: 5px;
}
</style>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
