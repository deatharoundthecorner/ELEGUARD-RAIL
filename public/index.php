<?php
/**
 * Public Landing Page
 */
$pageTitle = "Early Sighting Warning System";
require_once __DIR__ . '/../app/layouts/public_header.php';
require_once __DIR__ . '/../app/services/SanityContentService.php';

// Fetch public content (fallback handled internally in the service)
$content = SanityContentService::getLandingPage();
?>

<!-- Hero Banner Section -->
<section class="hero-section text-center">
    <div class="hero-overlay"></div>
    <div class="hero-content">
        <h1 class="hero-title"><?php echo e($content['heroTitle']); ?></h1>
        <p class="hero-subtitle"><?php echo e($content['heroSubtitle']); ?></p>
        <p class="hero-desc"><?php echo e($content['description']); ?></p>
        <div class="hero-buttons">
            <?php if (Auth::check() && Auth::role() === 'reporter'): ?>
                <a href="<?php echo Router::url('reporter/submit_report.php'); ?>" class="btn btn-accent btn-lg"><?php echo e($content['primaryButtonText']); ?></a>
            <?php elseif (Auth::check()): ?>
                <a href="<?php echo Router::url(Auth::role() . '/dashboard.php'); ?>" class="btn btn-accent btn-lg">Access Dashboard</a>
            <?php else: ?>
                <a href="<?php echo Router::url('register.php'); ?>" class="btn btn-accent btn-lg"><?php echo e($content['primaryButtonText']); ?></a>
            <?php endif; ?>
            <a href="<?php echo Router::url('safety.php'); ?>" class="btn btn-secondary btn-lg"><?php echo e($content['secondaryButtonText']); ?></a>
        </div>
    </div>
</section>

<!-- Problem Statement & Mission -->
<section class="info-section">
    <div class="grid grid-2col">
        <div class="info-card border-accent">
            <h2 class="section-title">The Challenge in Sri Lanka</h2>
            <p>Every year, Sri Lanka loses dozens of wild Asian elephants due to collisions with trains operating on forest-bisected railway lines. These tragic collisions can also lead to passenger carriage derailments, causing injury and death to train drivers and commuters.</p>
            <p style="margin-top: 15px;">Due to blind jungle curves and high train momentum, drivers cannot spot elephants on the tracks in time to stop. Traditional warning options have been too slow to notify control staff and train operators.</p>
        </div>
        <div class="info-card">
            <h2 class="section-title">Our Solution</h2>
            <p><strong>EleGuard Rail</strong> is a unified railway early-warning network. We combine sighting reports from local villagers, railway workers, and wildlife officers. These reports undergo validation and are immediately converted into live safety alerts that display directly on train driver dashboards with audible warnings.</p>
            <p style="margin-top: 15px;">By providing drivers with critical speed limits and distance warnings, we give them the margins they need to slow down safely and prevent collisions.</p>
        </div>
    </div>
</section>

<!-- How The System Works -->
<section class="workflow-section">
    <h2 class="section-heading text-center">How the System Protects Wildlife & Trains</h2>
    <div class="grid grid-4col workflow-grid">
        <div class="workflow-step">
            <div class="step-num">1</div>
            <h3>Sighting Filed</h3>
            <p>A reporter, track walker, or villager spots an elephant near the tracks and submits a report via their mobile phone.</p>
        </div>
        <div class="workflow-step">
            <div class="step-num">2</div>
            <h3>Verified by Ranger</h3>
            <p>Wildlife rangers review report details, assess proximity risk scores, and verify the movement report status.</p>
        </div>
        <div class="workflow-step">
            <div class="step-num">3</div>
            <h3>Alert Generated</h3>
            <p>Railway control officers convert verified hazard reports into active safety alerts for specific track sections.</p>
        </div>
        <div class="workflow-step">
            <div class="step-num">4</div>
            <h3>Driver Alerted</h3>
            <p>Train driver dashboards blink red and sound audio warnings, allowing them to slow down or stop before impact.</p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../app/layouts/public_footer.php'; ?>
