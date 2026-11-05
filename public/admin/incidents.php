<?php
/**
 * Admin Incident History panel
 */
$pageTitle = "Incident History";
$allowed_roles = 'admin';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';
require_once __DIR__ . '/../../app/includes/validation.php';

$errors = [];
$action = $_GET['action'] ?? 'list';
$incidentIdSelected = $_GET['id'] ?? null;
$incidentSelected = null;

if ($incidentIdSelected) {
    $incidentSelected = AlertService::getIncidentById((int)$incidentIdSelected);
}

// Handle Form Submissions & Deletions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $submitAction = $_POST['submit_action'] ?? '';

    if ($submitAction === 'add') {
        $sectionId = $_POST['section_id'] ?? '';
        $type = trim($_POST['incident_type'] ?? '');
        $date = $_POST['incident_date'] ?? '';
        $severity = $_POST['severity'] ?? 'Low';
        $desc = trim($_POST['description'] ?? '');

        $reqErrors = Validation::required($_POST, ['section_id', 'incident_type', 'incident_date', 'severity']);
        $errors = array_merge($errors, $reqErrors);

        if (empty($errors)) {
            if (AlertService::addIncident((int)$sectionId, $type, $desc, $date, $severity)) {
                Auth::logActivity($currentUser['id'], 'Incident Registered', "Admin registered incident: {$type} on {$date}.");
                Session::flash('success', "Historical incident '{$type}' registered successfully.");
                Router::redirect('admin/incidents.php');
            } else {
                $errors['submit'] = 'Failed to register incident.';
            }
        }
    } elseif ($submitAction === 'edit') {
        $id = (int)$_POST['id'];
        $sectionId = $_POST['section_id'] ?? '';
        $type = trim($_POST['incident_type'] ?? '');
        $date = $_POST['incident_date'] ?? '';
        $severity = $_POST['severity'] ?? 'Low';
        $desc = trim($_POST['description'] ?? '');

        $reqErrors = Validation::required($_POST, ['section_id', 'incident_type', 'incident_date', 'severity']);
        $errors = array_merge($errors, $reqErrors);

        if (empty($errors)) {
            if (AlertService::updateIncident($id, (int)$sectionId, $type, $desc, $date, $severity)) {
                Auth::logActivity($currentUser['id'], 'Incident Updated', "Admin updated incident #{$id}.");
                Session::flash('success', "Incident details updated successfully.");
                Router::redirect('admin/incidents.php');
            } else {
                $errors['submit'] = 'Failed to update incident.';
            }
        }
    } elseif ($submitAction === 'delete') {
        $id = (int)$_POST['id'];
        if (AlertService::deleteIncident($id)) {
            Auth::logActivity($currentUser['id'], 'Incident Deleted', "Admin deleted incident record #{$id}.");
            Session::flash('success', "Incident record removed successfully.");
            Router::redirect('admin/incidents.php');
        } else {
            $errors['submit'] = 'Failed to delete incident.';
        }
    }
}

// Fetch all incidents
$incidents = AlertService::getAllIncidents();
$sections = AlertService::getAllSections();
?>

<div class="dashboard-title-row">
    <h1>Incident History & Log</h1>
    <p class="text-secondary">Audit and compile historical records of elephant collisions, near-misses, and false alarms to analyze mitigation trends.</p>
</div>

<!-- Add Incident Panel -->
<?php if ($action === 'add'): ?>
    <div class="card-panel" style="max-width: 650px; border-top: 4px solid var(--primary-light);">
        <div class="panel-header">
            <h2 class="panel-title">Add Incident Record</h2>
            <a href="<?php echo Router::url('admin/incidents.php'); ?>" class="btn btn-outline btn-sm">Back to List</a>
        </div>

        <form action="<?php echo Router::url('admin/incidents.php?action=add'); ?>" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="submit_action" value="add">

            <div class="form-group">
                <label for="section_id" class="form-label">Railway Track Section *</label>
                <select name="section_id" id="section_id" class="form-control" required>
                    <option value="">-- Choose Railway Section --</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?php echo $sec['id']; ?>">
                            <?php echo e($sec['section_name']); ?> (<?php echo e($sec['district']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="incident_type" class="form-label">Incident Type *</label>
                <input type="text" name="incident_type" id="incident_type" class="form-control" placeholder="e.g. Elephant Sighting Collision, Near Miss, False Alarm" required>
            </div>

            <div class="grid grid-2col" style="gap:20px; margin-bottom: 0;">
                <div class="form-group">
                    <label for="incident_date" class="form-label">Date occurred *</label>
                    <input type="date" name="incident_date" id="incident_date" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="severity" class="form-label">Incident Severity *</label>
                    <select name="severity" id="severity" class="form-control" required>
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High">High</option>
                        <option value="Critical">Critical</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Description / Field Notes</label>
                <textarea name="description" id="description" class="form-control" placeholder="Provide event details, damaged assets, and local tracking response..."></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Incident</button>
                <a href="<?php echo Router::url('admin/incidents.php'); ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

<!-- Edit Incident Panel -->
<?php elseif ($action === 'edit' && $incidentSelected): ?>
    <div class="card-panel" style="max-width: 650px; border-top: 4px solid var(--accent-color);">
        <div class="panel-header">
            <h2 class="panel-title">Edit Incident Details</h2>
            <a href="<?php echo Router::url('admin/incidents.php'); ?>" class="btn btn-outline btn-sm">Back to List</a>
        </div>

        <form action="<?php echo Router::url('admin/incidents.php?action=edit&id=' . $incidentSelected['id']); ?>" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="submit_action" value="edit">
            <input type="hidden" name="id" value="<?php echo $incidentSelected['id']; ?>">

            <div class="form-group">
                <label for="section_id" class="form-label">Railway Track Section *</label>
                <select name="section_id" id="section_id" class="form-control" required>
                    <option value="">-- Choose Railway Section --</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?php echo $sec['id']; ?>" <?php echo $incidentSelected['section_id'] == $sec['id'] ? 'selected' : ''; ?>>
                            <?php echo e($sec['section_name']); ?> (<?php echo e($sec['district']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="incident_type" class="form-label">Incident Type *</label>
                <input type="text" name="incident_type" id="incident_type" class="form-control" value="<?php echo e($incidentSelected['incident_type']); ?>" required>
            </div>

            <div class="grid grid-2col" style="gap:20px; margin-bottom: 0;">
                <div class="form-group">
                    <label for="incident_date" class="form-label">Date occurred *</label>
                    <input type="date" name="incident_date" id="incident_date" class="form-control" value="<?php echo $incidentSelected['incident_date']; ?>" required>
                </div>

                <div class="form-group">
                    <label for="severity" class="form-label">Incident Severity *</label>
                    <select name="severity" id="severity" class="form-control" required>
                        <option value="Low" <?php echo $incidentSelected['severity'] === 'Low' ? 'selected' : ''; ?>>Low</option>
                        <option value="Medium" <?php echo $incidentSelected['severity'] === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="High" <?php echo $incidentSelected['severity'] === 'High' ? 'selected' : ''; ?>>High</option>
                        <option value="Critical" <?php echo $incidentSelected['severity'] === 'Critical' ? 'selected' : ''; ?>>Critical</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Description / Field Notes</label>
                <textarea name="description" id="description" class="form-control"><?php echo e($incidentSelected['description']); ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-accent">Update Incident</button>
                <a href="<?php echo Router::url('admin/incidents.php'); ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<!-- Incidents List -->
<?php if ($action === 'list'): ?>
    <div class="filter-card" style="text-align: right;">
        <a href="<?php echo Router::url('admin/incidents.php?action=add'); ?>" class="btn btn-accent">➕ Register Historical Incident</a>
    </div>

    <div class="card-panel">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date Occurred</th>
                        <th>Incident Type</th>
                        <th>Railway Section</th>
                        <th>Jurisdiction</th>
                        <th>Severity</th>
                        <th>Incident Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($incidents as $inc): ?>
                        <tr>
                            <td><?php echo format_raw_date($inc['incident_date']); ?></td>
                            <td><strong><?php echo e($inc['incident_type']); ?></strong></td>
                            <td><?php echo e($inc['section_name']); ?></td>
                            <td><?php echo e($inc['district']); ?></td>
                            <td>
                                <span class="badge <?php echo get_risk_badge_class($inc['severity']); ?>">
                                    <?php echo e($inc['severity']); ?>
                                </span>
                            </td>
                            <td style="font-size:0.85rem; color:var(--text-secondary); max-width:250px;"><?php echo e($inc['description'] ? $inc['description'] : 'N/A'); ?></td>
                            <td>
                                <div class="table-actions">
                                    <a href="<?php echo Router::url('admin/incidents.php?action=edit&id=' . $inc['id']); ?>" class="btn btn-outline btn-sm">Edit</a>
                                    <form action="<?php echo Router::url('admin/incidents.php'); ?>" method="POST" onsubmit="return confirm('Are you sure you want to delete this incident record?');" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="submit_action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $inc['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
