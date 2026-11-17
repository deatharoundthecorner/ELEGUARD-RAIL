<?php
/**
 * Dashboard Layout Sidebar
 * Renders distinct navigation links depending on the logged-in user's role.
 */
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Router.php';

$role = Auth::role();

// Clean vector icons for professional enterprise navigation
if (!function_exists('renderNavSvg')) {
    function renderNavSvg($name) {
        $icons = [
            'stats' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect></svg>',
            'analytics' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"></path><path d="m19 9-5 5-4-4-3 3"></path></svg>',
            'map' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"></polygon><line x1="9" y1="3" x2="9" y2="18"></line><line x1="15" y1="6" x2="15" y2="21"></line></svg>',
            'users' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>',
            'sections' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"></rect><line x1="4" y1="11" x2="20" y2="11"></line><line x1="12" y1="3" x2="12" y2="21"></line><path d="M8 18l-3 3"></path><path d="M16 18l3 3"></path></svg>',
            'hotspots' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a8 8 0 0 0-8 8c0 5.25 8 12 8 12s8-6.75 8-12a8 8 0 0 0-8-8z"></path><circle cx="12" cy="10" r="3"></circle></svg>',
            'reports' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg>',
            'pwa' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>',
            'alerts' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
            'acknowledgements' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="m9 12 2 2 4-4"></path></svg>',
            'incidents' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>',
            'logs' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>',
            'verification' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="m9 12 2 2 4-4"></path></svg>',
            'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>',
            'notifications' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"></path></svg>',
            'security' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>',
            'train' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="16" rx="2"></rect><path d="M4 11h16"></path><path d="M12 3v8"></path><path d="m8 19-3 3"></path><path d="m16 19 3 3"></path><circle cx="8" cy="15" r="1"></circle><circle cx="16" cy="15" r="1"></circle></svg>',
            'history' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>',
            'plus' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>',
            'home' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>'
        ];
        return $icons[$name] ?? '<span class="nav-fallback-dot"></span>';
    }
}
?>
<aside class="dashboard-sidebar" id="dashboardSidebar">
    <div class="sidebar-header">
        <a href="<?php echo Router::url('index.php'); ?>" class="sidebar-brand">
            <div class="brand-logo" aria-hidden="true">ER</div>
            <span class="brand-text">EleGuard <span class="brand-accent">Rail</span></span>
        </a>
        <button class="sidebar-close-btn" id="sidebarCloseBtn" aria-label="Close Sidebar" onclick="toggleSidebar()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
    </div>

    <nav class="sidebar-nav">
        <ul class="nav-list">
            <!-- COMMON HEADING -->
            <li class="nav-item-heading">Main Operational Hub</li>

            <!-- ADMIN SIDEBAR LINKS -->
            <?php if ($role === 'admin'): ?>
                <li>
                    <a href="<?php echo Router::url('admin/dashboard.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/dashboard.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('stats'); ?></span> Dashboard Statistics
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/analytics.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/analytics.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('analytics'); ?></span> Platform Analytics
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/map.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/map.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('map'); ?></span> Central Railway GIS Map
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/users.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/users.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('users'); ?></span> Manage Users
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/railway_sections.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/railway_sections.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('sections'); ?></span> Railway Sections
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/hotspots.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/hotspots.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('hotspots'); ?></span> Elephant Hotspots
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/reports.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/reports.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('reports'); ?></span> Elephant Sighting Reports
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('field/index.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'field/index.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('pwa'); ?></span> Field Telemetry PWA
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/alerts.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/alerts.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('alerts'); ?></span> Collision Warnings / Alerts
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/acknowledgements.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/acknowledgements.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('acknowledgements'); ?></span> Driver Acknowledgements
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/incidents.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/incidents.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('incidents'); ?></span> Incident History
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('admin/logs.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'admin/logs.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('logs'); ?></span> System Activity Logs
                    </a>
                </li>

            <!-- CONTROL OFFICER SIDEBAR LINKS -->
            <?php elseif ($role === 'control_officer'): ?>
                <li>
                    <a href="<?php echo Router::url('control/dashboard.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'control/dashboard.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('stats'); ?></span> Control Dashboard
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('control/analytics.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'control/analytics.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('analytics'); ?></span> Corridor Analytics
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('control/map.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'control/map.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('map'); ?></span> Operational Railway Map
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('control/reports.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'control/reports.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('reports'); ?></span> Sighting Reports
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('control/create_alert.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'control/create_alert.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('plus'); ?></span> Create Warning Alert
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('control/alerts.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'control/alerts.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('alerts'); ?></span> Active Alerts
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('control/acknowledgements.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'control/acknowledgements.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('acknowledgements'); ?></span> Driver Acknowledgements
                    </a>
                </li>

            <!-- TRAIN DRIVER SIDEBAR LINKS -->
            <?php elseif ($role === 'driver'): ?>
                <li>
                    <a href="<?php echo Router::url('driver/dashboard.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'driver/dashboard.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('alerts'); ?></span> WARNING PANEL (ACTIVE)
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('driver/alerts.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'driver/alerts.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('reports'); ?></span> Active Alerts Archive
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('driver/history.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'driver/history.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('logs'); ?></span> Acknowledgement History
                    </a>
                </li>

            <!-- WILDLIFE OFFICER SIDEBAR LINKS -->
            <?php elseif ($role === 'wildlife_officer'): ?>
                <li>
                    <a href="<?php echo Router::url('wildlife/dashboard.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'wildlife/dashboard.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('stats'); ?></span> Ranger Dashboard
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('wildlife/analytics.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'wildlife/analytics.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('analytics'); ?></span> Sighting Analytics
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('wildlife/map.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'wildlife/map.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('map'); ?></span> Wildlife Tracking Map
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('wildlife/reports.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'wildlife/reports.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('reports'); ?></span> Sighting Reports List
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('wildlife/submit_report.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'wildlife/submit_report.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('plus'); ?></span> Submit Verified Report
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('field/index.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'field/index.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('pwa'); ?></span> Field Telemetry PWA
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('wildlife/hotspots.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'wildlife/hotspots.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('hotspots'); ?></span> Hotspot Corridors
                    </a>
                </li>

            <!-- REPORTER / VILLAGER SIDEBAR LINKS -->
            <?php elseif ($role === 'reporter'): ?>
                <li>
                    <a href="<?php echo Router::url('reporter/dashboard.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'reporter/dashboard.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('stats'); ?></span> Sighting Dashboard
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('field/index.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'field/index.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('pwa'); ?></span> Field Telemetry PWA
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('reporter/submit_report.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'reporter/submit_report.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('plus'); ?></span> File Elephant Sighting
                    </a>
                </li>
                <li>
                    <a href="<?php echo Router::url('reporter/my_reports.php'); ?>" class="nav-link-item <?php echo strpos($_SERVER['REQUEST_URI'], 'reporter/my_reports.php') !== false ? 'active' : ''; ?>">
                        <span class="nav-icon"><?php echo renderNavSvg('reports'); ?></span> My Sighting Reports
                    </a>
                </li>
            <?php endif; ?>

            <!-- SITE NAVIGATION BACK LINK -->
            <li class="nav-item-separator"></li>
            <li class="nav-item-heading">Public Platform</li>
            <li>
                <a href="<?php echo Router::url('index.php'); ?>" class="nav-link-item">
                    <span class="nav-icon"><?php echo renderNavSvg('home'); ?></span> Back to Public Site
                </a>
            </li>
        </ul>
    </nav>
</aside>
