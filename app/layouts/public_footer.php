<?php
/**
 * Public Layout Footer
 */
require_once __DIR__ . '/../core/Router.php';
?>
    </main>

    <footer class="public-footer">
        <div class="container footer-content">
            <p>&copy; <?php echo date('Y'); ?> EleGuard Rail Sri Lanka. All Rights Reserved.</p>
            <p class="footer-note">Providing Early Warnings to Protect Sri Lanka's Wildlife and Train Passengers.</p>
        </div>
    </footer>

    <!-- JavaScript Assets -->
    <script src="<?php echo Router::url('assets/js/main.js'); ?>"></script>
</body>
</html>
