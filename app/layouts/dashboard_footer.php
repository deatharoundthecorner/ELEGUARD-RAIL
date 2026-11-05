<?php
/**
 * Dashboard Layout Footer
 */
require_once __DIR__ . '/../core/Router.php';
require_once __DIR__ . '/../core/Auth.php';

$currentUser = Auth::user();
?>
            </div> <!-- .dashboard-content-wrapper -->
            
            <footer class="dashboard-footer">
                <p>&copy; <?php echo date('Y'); ?> EleGuard Rail Sri Lanka. Control Panel Area.</p>
            </footer>
        </div> <!-- .dashboard-main -->
    </div> <!-- .dashboard-container -->

    <!-- Core Scripts -->
    <script src="<?php echo Router::url('assets/js/main.js'); ?>"></script>
    <script src="<?php echo Router::url('assets/js/dashboard.js'); ?>"></script>
    
    <!-- Load Alert Scripts for Train Driver Warnings -->
    <?php if ($currentUser && $currentUser['role'] === 'driver'): ?>
        <script src="<?php echo Router::url('assets/js/alerts.js'); ?>"></script>
    <?php endif; ?>
</body>
</html>
