<?php
/**
 * Emergency Contacts Page
 */
$pageTitle = "Emergency Contacts";
require_once __DIR__ . '/../app/layouts/public_header.php';
require_once __DIR__ . '/../app/services/SanityContentService.php';

$contacts = SanityContentService::getEmergencyContacts();
?>

<div class="content-header text-center">
    <h1 class="page-heading">Emergency Hotlines & Contacts</h1>
    <p class="page-subtitle">Immediate wildlife ranger and railway control desk phone numbers to report animal collisions or line blockages.</p>
</div>

<div class="contacts-container">
    <?php if (empty($contacts)): ?>
        <p class="text-center text-muted">No emergency contacts available at this time.</p>
    <?php else: ?>
        <div class="responsive-card-grid">
            <?php foreach ($contacts as $contact): ?>
                <div class="contact-card">
                    <div class="contact-icon">📞</div>
                    <div class="contact-body">
                        <h2 class="contact-name"><?php echo e($contact['organizationName']); ?></h2>
                        <span class="contact-district">📍 <?php echo e($contact['district']); ?></span>
                        <a href="tel:<?php echo e($contact['phoneNumber']); ?>" class="contact-phone"><?php echo e($contact['phoneNumber']); ?></a>
                        <p class="contact-desc"><?php echo e($contact['description']); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../app/layouts/public_footer.php'; ?>
