<?php
/**
 * FAQ Page
 */
$pageTitle = "Frequently Asked Questions";
require_once __DIR__ . '/../app/layouts/public_header.php';
require_once __DIR__ . '/../app/services/SanityContentService.php';

$faqs = SanityContentService::getFaqs();
?>

<div class="content-header text-center">
    <h1 class="page-heading">Frequently Asked Questions (FAQ)</h1>
    <p class="page-subtitle">Got questions about EleGuard Rail? Find answers to commonly asked questions below.</p>
</div>

<div class="faq-container">
    <?php if (empty($faqs)): ?>
        <p class="text-center text-muted">No FAQ items available at this time.</p>
    <?php else: ?>
        <div class="faq-list">
            <?php foreach ($faqs as $i => $faq): ?>
                <div class="faq-item">
                    <button class="faq-question-btn" onclick="toggleFaq(this)">
                        <span><?php echo e($faq['question']); ?></span>
                        <span class="faq-icon">+</span>
                    </button>
                    <div class="faq-answer-panel">
                        <div class="faq-answer-inner">
                            <p><?php echo nl2br(e($faq['answer'])); ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleFaq(btn) {
    btn.classList.toggle('active');
    const panel = btn.nextElementSibling;
    const icon = btn.querySelector('.faq-icon');
    
    if (panel.style.maxHeight) {
        panel.style.maxHeight = null;
        icon.textContent = '+';
    } else {
        panel.style.maxHeight = panel.scrollHeight + "px";
        icon.textContent = '-';
    }
}
</script>

<?php require_once __DIR__ . '/../app/layouts/public_footer.php'; ?>
