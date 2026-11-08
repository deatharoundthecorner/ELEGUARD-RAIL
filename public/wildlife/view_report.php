<?php
/**
 * Wildlife Sighting Report View / Verification
 * Routes to wildlife/reports.php with selected report ID
 */
require_once __DIR__ . '/../../app/core/Router.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    Router::redirect('wildlife/reports.php?id=' . $id);
} else {
    Router::redirect('wildlife/reports.php');
}
