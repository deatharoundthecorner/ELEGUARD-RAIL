<?php
/**
 * EleGuard Rail Root Redirector
 * Forwards traffic arriving at the project base folder to /public/
 */

header("Location: public/index.php");
exit;
