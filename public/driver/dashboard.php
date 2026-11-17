<?php
/**
 * Train Driver Live Cab Warning Panel
 * Milestone 9C Stage 4E.4.3-I: Locomotive Driver Advisory Interface
 */
$pageTitle = "CAB ADVISORY DESK — STATIONARY REVIEW";
$allowed_roles = 'driver';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';

// Active route / section filtering
$selectedSectionId = null;
if (isset($_GET['section_id']) && is_numeric($_GET['section_id'])) {
    $selectedSectionId = (int)$_GET['section_id'];
    $_SESSION['driver_assigned_section'] = $selectedSectionId;
} elseif (isset($_SESSION['driver_assigned_section'])) {
    $selectedSectionId = (int)$_SESSION['driver_assigned_section'];
}

$allSections = AlertService::getAllSections();
$advisories = AlertService::getDriverAdvisories($selectedSectionId);
$briefingSummary = AlertService::getDriverBriefingSummary($selectedSectionId);
?>

<div class="driver-dashboard-wrap">

    <div class="dashboard-title-row text-center" style="margin-bottom:18px;">
        <h1 style="font-size:2rem; font-family:var(--font-heading); margin-bottom:5px;">CAB ACTIVE WARNING PANEL <span class="badge badge-sample" style="font-size:0.75rem; vertical-align:middle; margin-left:8px;">Demonstration Environment</span></h1>
        <p class="text-secondary" style="font-size:0.95rem;">Locomotive Driver Safety Advisory &amp; Track Hazard Feed (Stationary Review Only)</p>
    </div>

    <!-- 1. MANDATORY STATIONARY-REVIEW SAFETY BANNER -->
    <div class="driver-safety-banner" role="alert">
        <div class="driver-safety-banner-header">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            <span>STATIONARY REVIEW USE ONLY — DO NOT INTERACT WHILE LOCOMOTIVE IS IN MOTION</span>
        </div>
        <div class="driver-safety-banner-body">
            <strong>Railway Safety Mandate:</strong> In-cab mobile device interaction while locomotive is moving is strictly prohibited. 
            Review safety advisories exclusively during pre-departure briefing or while stationary at scheduled station stops, sidings, or holding signals. 
            This system provides supplementary situational awareness only.
        </div>
    </div>

    <!-- 2. DATA FRESHNESS & LIVE SYNC STATUS BAR -->
    <div class="driver-freshness-bar">
        <div class="driver-freshness-indicator">
            <?php if ($briefingSummary['has_stale_data']): ?>
                <span style="color:#ffb300; display:inline-flex; align-items:center; gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> STALE DATA SYNC (Advisories > 2h old)</span>
            <?php else: ?>
                <span style="color:#0f9960; display:inline-flex; align-items:center; gap:6px;"><span class="badge-dot badge-dot-success" style="width:8px; height:8px;"></span> LIVE SYNC ACTIVE</span>
            <?php endif; ?>
            <span style="color:var(--text-muted); margin-left:8px;">| Refreshed: <?php echo date('h:i:s A'); ?></span>
        </div>
        <div>
            <a href="<?php echo Router::url('driver/dashboard.php' . ($selectedSectionId ? '?section_id=' . $selectedSectionId : '')); ?>" class="btn btn-outline btn-sm" style="min-height:36px; padding:6px 14px; display:inline-flex; align-items:center; gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"></path><path d="M16 21h5v-5"></path></svg>Refresh Feed</a>
        </div>
    </div>

    <!-- 3. PRE-DEPARTURE CORRIDOR SAFETY BRIEFING CARD -->
    <div class="driver-briefing-card">
        <div class="driver-briefing-header">
            <div class="driver-briefing-title" style="display:flex; align-items:center; gap:8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg>
                <span>Pre-Departure Corridor Briefing</span>
            </div>
            <!-- Assigned Route / Section Selector -->
            <form method="GET" action="<?php echo Router::url('driver/dashboard.php'); ?>" style="margin:0;">
                <label for="section_select" style="font-size:0.8rem; color:#8a9ba8; display:block; margin-bottom:4px;">ASSIGNED ROUTE / CORRIDOR:</label>
                <select id="section_select" name="section_id" class="driver-corridor-select" onchange="this.form.submit()">
                    <option value="0" <?php echo empty($selectedSectionId) ? 'selected' : ''; ?>>All Network Corridors (Full Grid)</option>
                    <?php foreach ($allSections as $sec): ?>
                        <option value="<?php echo (int)$sec['id']; ?>" <?php echo ($selectedSectionId === (int)$sec['id']) ? 'selected' : ''; ?>>
                            <?php echo e($sec['section_name']); ?> (<?php echo e($sec['district']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="driver-briefing-metrics">
            <div class="driver-metric-box">
                <div class="driver-metric-label">Active Advisories</div>
                <div class="driver-metric-value" style="color:<?php echo $briefingSummary['total_active_alerts'] > 0 ? '#ff7373' : '#0f9960'; ?>;">
                    <?php echo (int)$briefingSummary['total_active_alerts']; ?>
                </div>
            </div>
            <div class="driver-metric-box">
                <div class="driver-metric-label">Highest Hazard</div>
                <div class="driver-metric-value" style="color:<?php 
                    $h = $briefingSummary['highest_level'];
                    echo ($h === 'Critical') ? '#f44336' : (($h === 'High') ? '#ff9800' : '#4caf50');
                ?>;">
                    <?php echo strtoupper($briefingSummary['highest_level']); ?>
                </div>
            </div>
            <div class="driver-metric-box">
                <div class="driver-metric-label">Critical / High</div>
                <div class="driver-metric-value" style="color:#f5f8fa;">
                    <?php echo (int)($briefingSummary['critical_count'] + $briefingSummary['high_count']); ?>
                </div>
            </div>
            <div class="driver-metric-box">
                <div class="driver-metric-label">Route Filter</div>
                <div class="driver-metric-value" style="font-size:0.95rem; line-height:1.3; color:#8a9ba8; padding-top:4px;">
                    <?php echo e($briefingSummary['corridor_name']); ?>
                </div>
            </div>
        </div>

        <div class="driver-caution-order-reminder">
            <strong>Caution Order Precedence Notice:</strong> Official Caution Orders (Form T.409 / T.410) issued by Sri Lanka Railways and direct train controller instructions take absolute legal precedence over this supplementary advisory feed.
        </div>
    </div>

    <!-- 4. AUDIO SAFETY STATUS BOX (SUPPRESSED FOR CAB SAFETY) -->
    <div class="driver-audio-alert-control" style="background:#182026; border:1px solid #30404d;">
        <div class="audio-status-label">
            <span>Cab Audio Governance:</span>
            <strong id="audioStatusText" style="color:#8a9ba8;">SUPPRESSED (CAB SAFETY)</strong>
        </div>
        <button id="toggleAudioBtn" class="audio-toggle-btn muted" disabled style="cursor:not-allowed; opacity:0.8;">Cab Sirens Suppressed by Safety Protocol</button>
    </div>

    <!-- 5. ACTIVE ADVISORY CARDS LIST -->
    <div class="driver-alerts-list">
        <?php if (empty($advisories)): ?>
            <div class="driver-no-alerts-card">
                <div class="driver-no-alerts-icon" style="margin-bottom:12px;">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="m9 12 2 2 4-4"></path></svg>
                </div>
                <h2 style="font-size:1.8rem; color:var(--success); margin-bottom:10px;">TRACKS CLEAR OF ACTIVE ADVISORIES</h2>
                <p style="color:#d8e1e8; max-width:600px; margin:0 auto;">
                    No active elephant advisories reported on <?php echo e($briefingSummary['corridor_name']); ?>. 
                    Maintain standard authorized line speed, observe block signals, and preserve visual track vigilance.
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($advisories as $adv): ?>
                <?php
                $level = strtolower($adv['alert_level']);
                $cardClass = 'driver-alert-' . $level;
                ?>
                <div class="driver-alert-card <?php echo $cardClass; ?>">
                    <div class="driver-alert-header">
                        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                            <span class="driver-alert-level-badge"><?php echo e($adv['alert_level']); ?> HAZARD</span>
                            <!-- Verification Badge -->
                            <span class="<?php echo ($adv['is_dwc_verified']) ? 'badge-dwc-verified' : 'badge-provisional'; ?>">
                                <?php echo ($adv['is_dwc_verified']) ? 'DWC Ranger Verified' : 'Provisional Observation'; ?>
                            </span>
                            <?php if ($adv['is_stale']): ?>
                                <span class="badge" style="background-color:#5c2555; color:#f5a623;">Broadcast &gt; 2h ago</span>
                            <?php endif; ?>
                        </div>
                        <span class="driver-alert-time"><?php echo e($adv['broadcast_display']); ?></span>
                    </div>

                    <div class="driver-alert-body">
                        <!-- Location Context (Strictly Section / Corridor boundaries; NO raw coordinates!) -->
                        <div class="driver-section-name" style="display:flex; align-items:center; gap:6px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            <?php echo e($adv['section_name']); ?>
                        </div>
                        <div style="font-size:0.95rem; color:rgba(255,255,255,0.85); margin-bottom:12px;">
                            Corridor: <strong><?php echo e($adv['start_location']); ?></strong> ↔ <strong><?php echo e($adv['end_location']); ?></strong> (<?php echo e($adv['district']); ?>)
                        </div>

                        <!-- Dual Timestamps (Observed vs Broadcast) -->
                        <div class="driver-dual-timestamps">
                            <div class="driver-ts-item">
                                <strong>Sighting Observation Time</strong>
                                <span><?php echo e($adv['observed_display']); ?></span>
                            </div>
                            <div class="driver-ts-item">
                                <strong>Control Broadcast Time</strong>
                                <span><?php echo e($adv['broadcast_display']); ?> (<?php echo (int)$adv['age_minutes']; ?>m ago)</span>
                            </div>
                        </div>

                        <!-- Neutral Hazard Classification & Safety Reminder -->
                        <div class="driver-speed-advisory-box">
                            <div class="driver-speed-title" style="display:flex; align-items:center; gap:6px;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                                <span>Hazard Classification: <?php echo e($adv['hazard_classification']); ?></span>
                            </div>
                            <div class="driver-speed-disclaimer">
                                <strong>Supplementary hazard information only. Follow official Caution Orders and direct Train Controller instructions.</strong>
                            </div>
                        </div>

                        <!-- Control Broadcast Message -->
                        <div class="driver-alert-msg">
                            <?php echo e($adv['alert_message']); ?>
                        </div>

                        <!-- Ergonomic Large Touch Action Target (>= 52px) -->
                        <div class="driver-alert-actions">
                            <a href="<?php echo Router::url('driver/alert_details.php?id=' . $adv['id']); ?>" 
                               class="btn driver-btn-ack driver-touch-btn" 
                               style="text-align:center;">
                                Review Advisory / Acknowledge Receipt
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
