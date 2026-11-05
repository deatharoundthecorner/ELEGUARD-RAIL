<?php
/**
 * Awareness Articles Page
 */
$pageTitle = "Awareness Articles";
require_once __DIR__ . '/../app/layouts/public_header.php';
require_once __DIR__ . '/../app/services/SanityContentService.php';

$articles = SanityContentService::getAwarenessArticles();
?>

<div class="content-header text-center">
    <h1 class="page-heading">Elephant Conservation & Railway Safety Articles</h1>
    <p class="page-subtitle">Learn about seasonal elephant corridors, behavior, and why train collision prevention is critical.</p>
</div>

<div class="articles-container">
    <?php if (empty($articles)): ?>
        <p class="text-center text-muted">No awareness articles available at this time.</p>
    <?php else: ?>
        <div class="responsive-card-grid">
            <?php foreach ($articles as $article): ?>
                <article class="article-card">
                    <?php if (!empty($article['coverImage'])): ?>
                        <img src="<?php echo e($article['coverImage']); ?>" alt="<?php echo e($article['title']); ?>" class="article-image">
                    <?php else: ?>
                        <div class="article-image-placeholder">🐘 EleGuard Rail Sighting Safety</div>
                    <?php endif; ?>
                    <div class="article-body">
                        <span class="article-date"><?php echo format_raw_date($article['publishedDate']); ?></span>
                        <h2 class="article-title"><?php echo e($article['title']); ?></h2>
                        <p class="article-summary"><?php echo e($article['summary']); ?></p>
                        <div class="article-content">
                            <p><?php echo nl2br(e($article['body'])); ?></p>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../app/layouts/public_footer.php'; ?>
