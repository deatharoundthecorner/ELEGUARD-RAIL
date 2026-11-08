<?php
/**
 * Dashboard Layout Sidebar
 * Renders distinct navigation links depending on the logged-in user's role.
 */
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Router.php';

$role = Auth::role();
?>
<aside class="dashboard-sidebar" id="dashboardSidebar">
    <div class="sidebar-header">
        <a href="<?php echo Router::url('index.php'); ?>" class="sidebar-brand">
            <span class="logo-icon">🐘</span>
            <span class="brand-text">EleGuard <span class="brand-accent">Rail</span></span>
        </a>
    </div>

    <nav class="sidebar-nav">
        <ul class="nav-list">
            <!-- COMMON HEADING -->
            <li class="nav-item-heading">Main Operational Hub</li>

            <!-- ADMIN SIDEBAR LINKS -->
            <?php if ($role === 'admin'): ?>
                <li>
                    <a href="<?php echo Router::url('admin/dashboard.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/dashboard.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">📊</span> Dashboard Statistics
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/map.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/map.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">🗺️</span> Central Railway GIS Map
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/users.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/users.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">👥</span> Manage Users
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/railway_sections.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/railway_sections.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">🛣️</span> Railway Sections
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/hotspots.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/hotspots.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">📍</span> Elephant Hotspots
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/reports.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/reports.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">📝</span> Elephant Sighting Reports
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/alerts.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/alerts.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">🚨</span> Collision Warnings / Alerts
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/acknowledgements.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/acknowledgements.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">🛡️</span> Driver Acknowledgements
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/incidents.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/incidents.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">💥</span> Incident History
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/logs.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/logs.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">📜</span> System Activity Logs
                    </a>
                </li>

            <!-- CONTROL OFFICER SIDEBAR LINKS -->
            <?php elseif ($role === 'control_officer'): ?>
                <li>
                    <a href="<?php echo Router::url('control/dashboard.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'control/dashboard.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">📊</span> Control Dashboard
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('control/map.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'control/map.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">🗺️</span> Operational Railway Map
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('control/reports.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'control/reports.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">📝</span> Sighting Reports
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('control/create_alert.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'control/create_alert.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">➕</span> Create Warning Alert
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('control/alerts.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'control/alerts.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">🚨</span> Active Alerts
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('control/acknowledgements.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'control/acknowledgements.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">🛡️</span> Driver Acknowledgements
                    </a>
                </li>

            <!-- TRAIN DRIVER SIDEBAR LINKS -->
            <?php elseif ($role === 'driver'): ?>
                <li>
                    <a href="<?php echo Router::url('driver/dashboard.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'driver/dashboard.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">⚠️</span> WARNING PANEL (ACTIVE)
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('driver/alerts.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'driver/alerts.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">🚨</span> Active Alerts Archive
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('driver/history.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'driver/history.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">📜</span> Acknowledgement History
                    </a>
                </li>

            <!-- WILDLIFE OFFICER SIDEBAR LINKS -->
            <?php elseif ($role === 'wildlife_officer'): ?>
                <li>
                    <a href="<?php echo Router::url('wildlife/dashboard.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'wildlife/dashboard.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">📊</span> Ranger Dashboard
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('wildlife/map.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'wildlife/map.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">🗺️</span> Wildlife Tracking Map
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('wildlife/reports.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'wildlife/reports.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">📝</span> Sighting Reports List
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('wildlife/submit_report.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'wildlife/submit_report.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">➕</span> Submit Verified Report
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('wildlife/hotspots.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'wildlife/hotspots.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">📍</span> Hotspot Corridors
                    </a>
                </li>

            <!-- REPORTER / VILLAGER SIDEBAR LINKS -->
            <?php elseif ($role === 'reporter'): ?>
                <li>
                    <a href="<?php echo Router::url('reporter/dashboard.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'reporter/dashboard.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">📊</span> Sighting Dashboard
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('reporter/submit_report.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'reporter/submit_report.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">➕</span> File Elephant Sighting
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('reporter/my_reports.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'reporter/my_reports.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon">🗂️</span> My Sighting Reports
                    </a>
                </li>
            <?php endif; ?>

            <!-- SITE NAVIGATION BACK LINK -->
            <li class="nav-item-separator"></li>
            <li class="nav-item-heading">Public Platform</li>
            <li>
                <a href="<?php echo Router::url('index.php'); ?>" class="nav-link-item">
                    <span class="nav-icon">🏠</span> Back to Public Site
                </a>
            </li>
        </ul>
    </nav>
</aside>
