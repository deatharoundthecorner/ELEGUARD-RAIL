<?php
/**
 * Dashboard Layout Header
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Router.php';
require_once __DIR__ . '/../includes/functions.php';

// Enforce auth check globally on dashboards
if (!Auth::check()) {
    Session::flash('error', 'You must be logged in to access the dashboard.');
    Router::redirect('login.php');
}

// Enforce role-based access control if restricted by the page
if (isset($allowed_roles)) {
    require_once __DIR__ . '/../middleware/role_required.php';
}

$currentUser = Auth::user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? e($pageTitle) . ' | ' . APP_NAME . ' Dashboard' : APP_NAME . ' Dashboard'; ?></title>
    
    <!-- Google Fonts: Inter and Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo Router::url('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo Router::url('assets/css/dashboard.css'); ?>">
    
    <?php if ($currentUser['role'] === 'driver'): ?>
        <link rel="stylesheet" href="<?php echo Router::url('assets/css/driver-alert.css'); ?>">
    <?php endif; ?>
</head>
<body class="dashboard-body">
    
    <div class="dashboard-container">
        
        <!-- Sidebar Navigation -->
        <?php include __DIR__ . '/dashboard_sidebar.php'; ?>
        
        <!-- Main Dashboard View Panel -->
        <div class="dashboard-main">
            <header class="dashboard-topbar">
                <button class="sidebar-toggle-btn" id="sidebarToggle" aria-label="Toggle Sidebar" onclick="toggleSidebar()">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="topbar-left">
                    <span class="system-time">Local Time: <span id="clock"><?php echo date('Y-m-d h:i A'); ?></span></span>
                </div>
                <div class="topbar-right">
                    <div class="user-profile-badge">
                        <span class="user-avatar">👤</span>
                        <div class="user-meta">
                            <span class="user-name"><?php echo e($currentUser['name']); ?></span>
                            <span class="user-role-tag"><?php echo e(ucwords(str_replace('_', ' ', $currentUser['role']))); ?></span>
                        </div>
                    </div>
                    <a href="<?php echo Router::url('logout.php'); ?>" class="btn btn-outline btn-sm logout-btn">Logout</a>
                </div>
            </header>

            <div class="dashboard-content-wrapper">
                
                <?php if ($flashError = Session::flash('error')): ?>
                    <div class="alert alert-danger dismissible-alert">
                        <p><?php echo e($flashError); ?></p>
                        <button class="close-alert-btn" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if ($flashSuccess = Session::flash('success')): ?>
                    <div class="alert alert-success dismissible-alert">
                        <p><?php echo e($flashSuccess); ?></p>
                        <button class="close-alert-btn" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>
