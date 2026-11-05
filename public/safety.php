<?php
/**
 * Safety Instructions Page
 */
$pageTitle = "Safety Instructions";
require_once __DIR__ . '/../app/layouts/public_header.php';
require_once __DIR__ . '/../app/services/SanityContentService.php';

$instructions = SanityContentService::getSafetyInstructions();
?>

<div class="content-header text-center">
    <h1 class="page-heading">Railway Sighting & Safety Instructions</h1>
    <p class="page-subtitle">Standard guidelines for villagers, railway workers, and public travellers near train corridors.</p>
</div>

<div class="instructions-container">
    <?php if (empty($instructions)): ?>
        <p class="text-center text-muted">No safety instructions available at this time.</p>
    <?php else: ?>
        <div class="instructions-list">
            <?php foreach ($instructions as $inst): ?>
                <?php
                $priority = $inst['priorityLevel'] ?? 'Low';
                $badgeClass = get_risk_badge_class($priority);
                ?>
                <div class="instruction-row card-panel-inline">
                    <div class="instruction-meta">
                        <span class="badge <?php echo $badgeClass; ?>"><?php echo e($priority); ?> Importance</span>
                    </div>
                    <div class="instruction-main">
                        <h2 class="instruction-title"><?php echo e($inst['title']); ?></h2>
                        <p class="instruction-body"><?php echo nl2br(e($inst['body'])); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../app/layouts/public_footer.php'; ?>
