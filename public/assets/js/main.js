/**
 * EleGuard Rail Common JavaScript
 */

// Mobile Public Navbar Toggle
function toggleNav() {
    const nav = document.getElementById('publicNav');
    if (nav) {
        nav.classList.toggle('show');
    }
}

// Auto-dismiss alert notifications after 5 seconds
document.addEventListener('DOMContentLoaded', () => {
    const dismissibleAlerts = document.querySelectorAll('.dismissible-alert');
    dismissibleAlerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.remove();
            }, 500);
        }, 5000);
    });
});
