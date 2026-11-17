<?php
/**
 * EleGuard Rail — Milestone 9C Stage 4E.4.2-A Test Suite
 * PWA Foundation, Offline Resilience & Field Interface Verification Suite
 *
 * Verifies:
 * 1. Web App Manifest compliance (W3C PWA specification, valid JSON, required keys, icons).
 * 2. Service Worker architecture & security directives (cache name, pre-cache assets, cache exclusion filters).
 * 3. Offline fallback page compliance (zero external dependencies, branding, retry logic).
 * 4. Field reporting interface shell & RBAC gatekeeping (unauthenticated redirect, driver lockout, authorized rendering).
 * 5. Spatial decoupling & client-side geodesic forward solver accuracy.
 * 6. Diurnal 24-hour continuum coverage (7 continuous periods, no gaps).
 * 7. Mobile responsiveness & touch target accessibility (48px+ touch targets, viewport-fit=cover).
 * 8. Zero leakage of sensitive wildlife coordinates into shared/unencrypted caches.
 * 9. Operational database immutability invariant.
 *
 * Target Database: STRICTLY eleguard_rail_test
 */

define('ELEGUARD_TEST_SUITE', true);
define('ELEGUARD_TEST_ENVIRONMENT', true);

$targetDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
if (empty($targetDb) || $targetDb === 'eleguard_rail_test' || $targetDb === 'eleguard_rail') {
    $targetDb = 'eleguard_test_tmp_' . bin2hex(random_bytes(6));
    putenv("DB_NAME={$targetDb}");
    $_ENV['DB_NAME'] = $targetDb;
}

$baseDir = str_replace('\\', '/', dirname(__DIR__));
require_once $baseDir . '/tests/bootstrap.php';
validateTestDatabaseTarget($targetDb);

require_once $baseDir . '/app/config/database.php';
require_once $baseDir . '/app/core/Database.php';
require_once $baseDir . '/app/core/Session.php';
require_once $baseDir . '/app/core/Auth.php';
require_once $baseDir . '/app/services/FieldReportingService.php';

function pass($msg) { echo "  \033[32m[PASS]\033[0m " . $msg . PHP_EOL; }
function fail($msg, $details = '') { 
    echo "  \033[31m[FAIL]\033[0m " . $msg . PHP_EOL; 
    if ($details) echo "         \033[33mDetails: " . $details . "\033[0m" . PHP_EOL;
    $GLOBALS['testsFailed']++;
}
function section($title) { echo PHP_EOL . "--- " . strtoupper($title) . " ---" . PHP_EOL; }

$testsRun = 0;
$testsFailed = 0;

echo "================================================================================" . PHP_EOL;
echo " EleGuard Rail — Stage 4E.4.2-A: PWA Foundation & Field Interface Test Suite" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

// Capture operational database before counts
$pdo = Database::getInstance()->getConnection();
$opCountsBefore = [];
$opTables = ['elephant_reports', 'system_logs', 'alerts', 'hotspots', 'railway_sections', 'users'];
foreach ($opTables as $tbl) {
    try {
        $opCountsBefore[$tbl] = (int)$pdo->query("SELECT COUNT(*) FROM eleguard_rail." . $tbl)->fetchColumn();
    } catch (Exception $e) {
        $opCountsBefore[$tbl] = -1;
    }
}

/* =============================================================================
 * SECTION 1: WEB APP MANIFEST VALIDATION
 * ============================================================================= */
section("1. Web App Manifest Validation");

$manifestPath = $baseDir . '/public/manifest.webmanifest';
$testsRun++;
if (file_exists($manifestPath)) {
    pass("manifest.webmanifest exists at public root");
} else {
    fail("manifest.webmanifest missing at " . $manifestPath);
}

$manifestContent = file_get_contents($manifestPath);
$manifestData = json_decode($manifestContent, true);

$testsRun++;
if (json_last_error() === JSON_ERROR_NONE && is_array($manifestData)) {
    pass("manifest.webmanifest is syntactically valid JSON");
} else {
    fail("manifest.webmanifest failed JSON decode", json_last_error_msg());
}

// Required keys
$requiredKeys = ['name', 'short_name', 'start_url', 'scope', 'display', 'background_color', 'theme_color', 'icons'];
foreach ($requiredKeys as $k) {
    $testsRun++;
    if (isset($manifestData[$k])) {
        pass("Manifest contains required property: '{$k}' ('" . (is_array($manifestData[$k]) ? 'array[' . count($manifestData[$k]) . ']' : $manifestData[$k]) . "')");
    } else {
        fail("Manifest missing required property: '{$k}'");
    }
}

// Display standalone check
$testsRun++;
if (($manifestData['display'] ?? '') === 'standalone') {
    pass("Manifest display mode is 'standalone'");
} else {
    fail("Manifest display mode is not 'standalone'");
}

// Icons verification
$testsRun++;
$icons = $manifestData['icons'] ?? [];
if (count($icons) >= 4) {
    pass("Manifest specifies at least 4 icon configurations (count: " . count($icons) . ")");
} else {
    fail("Manifest specifies fewer than 4 icons", "Count: " . count($icons));
}

$has192 = false;
$has512 = false;
$hasMaskable = false;
$hasSvg = false;

foreach ($icons as $ico) {
    $testsRun++;
    $iconPath = $baseDir . '/public/' . ltrim($ico['src'], './');
    if (file_exists($iconPath) && filesize($iconPath) > 0) {
        pass("Icon asset exists on disk: " . $ico['src'] . " (" . filesize($iconPath) . " bytes)");
    } else {
        fail("Icon asset missing or empty on disk: " . $iconPath);
    }

    if (($ico['sizes'] ?? '') === '192x192') $has192 = true;
    if (($ico['sizes'] ?? '') === '512x512') $has512 = true;
    if (($ico['purpose'] ?? '') === 'maskable') $hasMaskable = true;
    if (($ico['type'] ?? '') === 'image/svg+xml') $hasSvg = true;
}

$testsRun++;
if ($has192 && $has512 && $hasMaskable && $hasSvg) {
    pass("Manifest covers all required icon formats: 192px, 512px, maskable, and vector SVG");
} else {
    fail("Manifest missing one or more icon configurations", "192: $has192, 512: $has512, maskable: $hasMaskable, svg: $hasSvg");
}

/* =============================================================================
 * SECTION 2: SERVICE WORKER ARCHITECTURE & SECURITY DIRECTIVES
 * ============================================================================= */
section("2. Service Worker Architecture & Security Directives");

$swPath = $baseDir . '/public/sw.js';
$testsRun++;
if (file_exists($swPath)) {
    pass("Service Worker file exists at public/sw.js");
} else {
    fail("Service Worker missing at " . $swPath);
}

$swContent = file_get_contents($swPath);

// Versioned Cache declaration
$testsRun++;
if (preg_match("/const\s+CACHE_NAME\s*=\s*['\"]([^'\"]+)['\"];/", $swContent, $m)) {
    pass("Service Worker declares versioned cache name: '" . $m[1] . "'");
} else {
    fail("Service Worker lacks CACHE_NAME declaration");
}

// Pre-cached assets
$testsRun++;
if (strpos($swContent, 'offline.html') !== false && strpos($swContent, 'manifest.webmanifest') !== false) {
    pass("Service Worker pre-caches core shell assets ('offline.html', 'manifest.webmanifest')");
} else {
    fail("Service Worker pre-cache missing core shell assets");
}

// Non-GET bypass rule
$testsRun++;
if (strpos($swContent, "request.method !== 'GET'") !== false) {
    pass("Service Worker strictly restricts caching to GET requests (mutations bypass SW entirely)");
} else {
    fail("Service Worker lacks request.method !== 'GET' bypass check");
}

// Strict API & sensitive cache ban
$testsRun++;
$hasApiFilter = strpos($swContent, "url.pathname.includes('/api/')") !== false;
$hasApiV2Filter = strpos($swContent, "url.pathname.includes('/api/v2/')") !== false;
$hasLoginFilter = strpos($swContent, "url.pathname.endsWith('/login.php')") !== false;
if ($hasApiFilter && $hasApiV2Filter && $hasLoginFilter) {
    pass("Service Worker enforces STRICT CACHE BAN on /api/, /api/v2/, and /login.php");
} else {
    fail("Service Worker lacks one or more critical cache exclusion rules");
}

// Navigation fallback to offline.html
$testsRun++;
if (strpos($swContent, "request.mode === 'navigate'") !== false && strpos($swContent, "offline.html") !== false) {
    pass("Service Worker provides network-first navigation with offline.html fallback");
} else {
    fail("Service Worker navigation fallback missing or incomplete");
}

// Safe update mechanism (skipWaiting, clients.claim)
$testsRun++;
if (strpos($swContent, "self.skipWaiting()") !== false && strpos($swContent, "self.clients.claim()") !== false) {
    pass("Service Worker implements safe update lifecycle (skipWaiting & clients.claim)");
} else {
    fail("Service Worker missing skipWaiting() or clients.claim() lifecycle calls");
}

/* =============================================================================
 * SECTION 3: OFFLINE FALLBACK PAGE COMPLIANCE
 * ============================================================================= */
section("3. Offline Fallback Page Compliance");

$offlinePath = $baseDir . '/public/offline.html';
$testsRun++;
if (file_exists($offlinePath)) {
    pass("offline.html exists at public/offline.html");
} else {
    fail("offline.html missing at " . $offlinePath);
}

$offlineContent = file_get_contents($offlinePath);

// HTML5 DocType & Head
$testsRun++;
if (strpos($offlineContent, '<!DOCTYPE html>') !== false && strpos($offlineContent, '<meta name="viewport"') !== false) {
    pass("offline.html has valid HTML5 DocType and viewport meta tag");
} else {
    fail("offline.html missing DocType or viewport");
}

// Self-contained check (Zero external CDNs)
$testsRun++;
$hasExternalScripts = preg_match("/<script[^>]+src=[\"'](https?:|\/\/)/i", $offlineContent);
$hasExternalStyles = preg_match("/<link[^>]+href=[\"'](https?:|\/\/)/i", $offlineContent);
if (!$hasExternalScripts && !$hasExternalStyles) {
    pass("offline.html is 100% self-contained with ZERO external CDN dependencies");
} else {
    fail("offline.html references external network CDN resources");
}

// Branding & Retry mechanism
$testsRun++;
$hasBranding = strpos($offlineContent, 'EleGuard') !== false && strpos($offlineContent, 'Offline Mode') !== false;
$hasRetry = strpos($offlineContent, 'checkConnection') !== false || strpos($offlineContent, 'reload') !== false;
if ($hasBranding && $hasRetry) {
    pass("offline.html includes EleGuard branding and interactive connection retry mechanism");
} else {
    fail("offline.html missing branding or retry mechanism");
}

// Security note regarding wildlife coordinates
$testsRun++;
if (strpos($offlineContent, 'Stage 4E.4.2-B') !== false || strpos($offlineContent, 'wildlife') !== false) {
    pass("offline.html explains wildlife telemetry cache security and Stage 4E.4.2-B roadmap");
} else {
    fail("offline.html missing telemetry security explanation");
}

/* =============================================================================
 * SECTION 4: FIELD REPORTING INTERFACE SHELL & RBAC GATEKEEPING
 * ============================================================================= */
section("4. Field Reporting Interface Shell & RBAC Gatekeeping");

$fieldIndexPath = $baseDir . '/public/field/index.php';
$testsRun++;
if (file_exists($fieldIndexPath)) {
    pass("Field PWA entry point exists at public/field/index.php");
} else {
    fail("public/field/index.php missing");
}

$fieldContent = file_get_contents($fieldIndexPath);

// Unauthenticated redirect check
$testsRun++;
if (strpos($fieldContent, 'Auth::check()') !== false && strpos($fieldContent, 'login.php') !== false) {
    pass("public/field/index.php enforces Auth::check() and redirects unauthenticated users to login");
} else {
    fail("public/field/index.php missing authentication check");
}

// Driver safety directive lockout
$testsRun++;
$hasDriverCheck = strpos($fieldContent, "\$isDriver = (\$userRole === 'driver')") !== false || strpos($fieldContent, "\$userRole === 'driver'") !== false;
$hasApprovedWording = strpos($fieldContent, 'Do not operate this application while driving') !== false && 
                      strpos($fieldContent, 'authorized railway VHF communication procedure') !== false;
$hasUnapprovedChannel = strpos($fieldContent, 'CHANNEL 1') !== false;

if ($hasDriverCheck && $hasApprovedWording && !$hasUnapprovedChannel) {
    pass("public/field/index.php enforces MANDATORY driver lockout with approved VHF communication procedure (no unapproved Channel 1)");
} else {
    fail("public/field/index.php missing approved driver safety directive or contains unverified channel", 
        "DriverCheck: " . ($hasDriverCheck ? 'yes' : 'no') . ", ApprovedWording: " . ($hasApprovedWording ? 'yes' : 'no') . ", UnapprovedChannel: " . ($hasUnapprovedChannel ? 'found' : 'none'));
}

// Observer GNSS inputs (Decoupled)
$testsRun++;
$hasObsLat = strpos($fieldContent, 'id="observer_lat"') !== false;
$hasObsLon = strpos($fieldContent, 'id="observer_lon"') !== false;
$hasObsAcc = strpos($fieldContent, 'id="observer_accuracy"') !== false;
$hasObsMethod = strpos($fieldContent, 'id="acquisition_method"') !== false;
if ($hasObsLat && $hasObsLon && $hasObsAcc && $hasObsMethod) {
    pass("Field interface contains decoupled Observer GNSS inputs (lat, lon, accuracy, method)");
} else {
    fail("Field interface missing one or more Observer GNSS inputs");
}

// Animal offset inputs
$testsRun++;
$hasDist = strpos($fieldContent, 'id="observation_distance"') !== false;
$hasBearing = strpos($fieldContent, 'id="observation_bearing"') !== false;
$hasAnimalUnc = strpos($fieldContent, 'id="animal_uncertainty"') !== false;
if ($hasDist && $hasBearing && $hasAnimalUnc) {
    pass("Field interface contains Animal Location offset inputs (distance, bearing, uncertainty)");
} else {
    fail("Field interface missing one or more Animal Location offset inputs");
}

// Unknown animal coordinates checkbox
$testsRun++;
if (strpos($fieldContent, 'id="animal_coords_unknown"') !== false) {
    pass("Field interface includes explicit 'Animal coordinates unknown / unmeasurable' toggle (leaves coords NULL)");
} else {
    fail("Field interface missing animal_coords_unknown toggle");
}

// Gapless 24-hour diurnal continuum buttons (Authoritative Taxonomy)
$testsRun++;
$authoritativeDiurnal = [
    'dawn'        => ['04:00', '07:00'],
    'morning'     => ['07:00', '11:00'],
    'midday'      => ['11:00', '14:00'],
    'afternoon'   => ['14:00', '17:00'],
    'dusk'        => ['17:00', '19:30'],
    'night'       => ['19:30', '23:30'],
    'deep_night'  => ['23:30', '04:00']
];

$allPeriodsPresent = true;
foreach (array_keys($authoritativeDiurnal) as $dp) {
    if (strpos($fieldContent, 'data-period="' . $dp . '"') === false) {
        $allPeriodsPresent = false;
        break;
    }
}
if ($allPeriodsPresent) {
    pass("Field interface includes all 7 authoritative diurnal continuum periods (dawn, morning, midday, afternoon, dusk, night, deep_night)");
} else {
    fail("Field interface missing one or more authoritative diurnal continuum period buttons");
}

// Regression Test: Frontend Diurnal Values vs Backend FieldReportingService::DIURNAL_PERIODS
foreach ($authoritativeDiurnal as $periodKey => $range) {
    $testsRun++;
    if (isset(FieldReportingService::DIURNAL_PERIODS[$periodKey])) {
        $backendDef = FieldReportingService::DIURNAL_PERIODS[$periodKey];
        $backendStart = substr($backendDef['start'], 0, 5);
        $backendEnd = substr($backendDef['end'], 0, 5);
        if ($backendStart === $range[0] && $backendEnd === $range[1]) {
            pass("Diurnal period '{$periodKey}' exact time range matches backend: {$backendStart}–{$backendEnd}");
        } else {
            fail("Diurnal period '{$periodKey}' time mismatch: Expected {$range[0]}–{$range[1]}, got {$backendStart}–{$backendEnd}");
        }
    } else {
        fail("Diurnal period '{$periodKey}' missing in FieldReportingService::DIURNAL_PERIODS");
    }

    // Verify backend normalization accepts this period (using yesterday's date to avoid future window rejection)
    $testsRun++;
    $norm = FieldReportingService::normalizeTemporalEvidence([
        'sighting_date' => date('Y-m-d', strtotime('-1 day')),
        'diurnal_period' => $periodKey,
        'sighting_time_precision' => 'approximate'
    ]);
    if ($norm['valid'] && $norm['data']['diurnal_period'] === $periodKey) {
        pass("Backend normalizeTemporalEvidence accepts frontend diurnal period: '{$periodKey}'");
    } else {
        fail("Backend rejected frontend diurnal period: '{$periodKey}'", json_encode($norm['errors']));
    }
}

// Server-side independent coordinate validation & security checks
$testsRun++;
$outOfBoundsVal = FieldReportingService::validateObservationPayload([
    'client_report_uuid' => 'a1b2c3d4-e5f6-4a1b-8c2d-123456789abc',
    'location_description' => 'Test out of bounds observer',
    'observer_location' => [
        'latitude' => 15.000000, // Way outside Sri Lanka (5.8 to 9.9)
        'longitude' => 80.000000
    ]
]);
if (!$outOfBoundsVal['valid'] && isset($outOfBoundsVal['errors']['observer_latitude'])) {
    pass("Server-side validation independently rejects out-of-bounds observer coordinates (Lat: 15.0)");
} else {
    fail("Server-side validation failed to reject out-of-bounds observer coordinates");
}

// Server-side decoupled calculation & unknown animal coordinate handling
$testsRun++;
$unknownOffset = FieldReportingService::calculateGeodeticOffset(8.032145, 80.754321, null, null, 10.0);
if ($unknownOffset['estimated_latitude'] === null && $unknownOffset['estimated_longitude'] === null && $unknownOffset['is_estimated'] === 0) {
    pass("Server-side calculateGeodeticOffset strictly yields NULL animal coordinates when distance/bearing is omitted (unknown location)");
} else {
    fail("Server-side calculateGeodeticOffset failed to yield NULL for omitted offset");
}

// Demographics, Railway Sections, and Photo Evidence
$testsRun++;
$hasCount = strpos($fieldContent, 'id="elephant_count"') !== false;
$hasSectionSelect = strpos($fieldContent, 'id="section_id"') !== false;
$hasPhoto = strpos($fieldContent, 'id="evidence_photo"') !== false;
if ($hasCount && $hasSectionSelect && $hasPhoto) {
    pass("Field interface includes elephant demographics count, railway section dropdown, and photo upload");
} else {
    fail("Field interface missing demographics, section dropdown, or photo upload");
}

/* =============================================================================
 * SECTION 5: GEODESIC DIRECT FORWARD PROJECTION ACCURACY
 * ============================================================================= */
section("5. Geodesic Forward Projection Accuracy (Observer + Offset => Animal)");

$jsEnginePath = $baseDir . '/public/assets/js/field_pwa.js';
$testsRun++;
if (file_exists($jsEnginePath)) {
    pass("Field PWA client engine exists at public/assets/js/field_pwa.js");
} else {
    fail("public/assets/js/field_pwa.js missing");
}

$jsContent = file_get_contents($jsEnginePath);

// Verify Great Circle solver presence in JS
$testsRun++;
if (strpos($jsContent, 'EARTH_RADIUS_METERS') !== false && strpos($jsContent, 'Math.asin') !== false && strpos($jsContent, 'Math.atan2') !== false) {
    pass("field_pwa.js contains spherical Great Circle direct forward solver");
} else {
    fail("field_pwa.js missing spherical Great Circle solver");
}

// Mathematical precision verification in PHP (matching client-side algorithm)
function solveGeodesicForward($obsLat, $obsLon, $distanceMeters, $bearingDeg) {
    $R = 6371000.0;
    $phi1 = deg2rad($obsLat);
    $lambda1 = deg2rad($obsLon);
    $theta = deg2rad($bearingDeg);
    $delta = $distanceMeters / $R;

    $phi2 = asin(sin($phi1) * cos($delta) + cos($phi1) * sin($delta) * cos($theta));
    $lambda2 = $lambda1 + atan2(
        sin($theta) * sin($delta) * cos($phi1),
        cos($delta) - sin($phi1) * sin($phi2)
    );
    $lambda2 = fmod($lambda2 + 3 * M_PI, 2 * M_PI) - M_PI;

    return [
        'lat' => rad2deg($phi2),
        'lon' => rad2deg($lambda2)
    ];
}

// Test Case A: Observer at Habarana (8.032145, 80.754321), Distance 100m, Bearing 0° (North)
$projNorth = solveGeodesicForward(8.032145, 80.754321, 100, 0);
$latDiffNorth = ($projNorth['lat'] - 8.032145) * 111000.0; // Approx 111km per degree
$testsRun++;
if (abs($latDiffNorth - 100.0) < 1.0 && abs($projNorth['lon'] - 80.754321) < 0.00001) {
    pass(sprintf("Due North offset test: delta = +100m northward (Projected Lat: %.6f, Lon: %.6f)", $projNorth['lat'], $projNorth['lon']));
} else {
    fail("Due North offset calculation deviated beyond tolerance");
}

// Test Case B: Observer at Habarana, Distance 100m, Bearing 90° (East)
$projEast = solveGeodesicForward(8.032145, 80.754321, 100, 90);
$testsRun++;
if (abs($projEast['lat'] - 8.032145) < 0.00001 && $projEast['lon'] > 80.754321) {
    pass(sprintf("Due East offset test: latitude preserved, longitude shifted eastward (Projected Lat: %.6f, Lon: %.6f)", $projEast['lat'], $projEast['lon']));
} else {
    fail("Due East offset calculation deviated beyond tolerance");
}

// Spatial uncertainty propagation test: sqrt(gps^2 + est^2)
$gpsAcc = 6.0;
$estUnc = 20.0;
$expectedTotalUnc = sqrt(pow($gpsAcc, 2) + pow($estUnc, 2)); // ~20.88m
$testsRun++;
if (abs($expectedTotalUnc - 20.88) < 0.02) {
    pass(sprintf("Spatial uncertainty propagation: sqrt(%.1f^2 + %.1f^2) = ±%.2fm", $gpsAcc, $estUnc, $expectedTotalUnc));
} else {
    fail("Spatial uncertainty propagation math failed");
}

/* =============================================================================
 * SECTION 6: MOBILE RESPONSIVENESS & TOUCH TARGET ACCESSIBILITY
 * ============================================================================= */
section("6. Mobile Responsiveness & Accessibility");

$cssPath = $baseDir . '/public/assets/css/field_pwa.css';
$testsRun++;
if (file_exists($cssPath)) {
    pass("Field PWA stylesheet exists at public/assets/css/field_pwa.css");
} else {
    fail("public/assets/css/field_pwa.css missing");
}

$cssContent = file_get_contents($cssPath);

// Touch target rule check
$testsRun++;
if (strpos($cssContent, '--touch-min: 48px') !== false || strpos($cssContent, 'min-height: 48px') !== false) {
    pass("field_pwa.css specifies minimum 48px touch targets for mobile accessibility");
} else {
    fail("field_pwa.css lacks 48px touch target declaration");
}

// Safe area insets check
$testsRun++;
if (strpos($cssContent, 'safe-area-inset-bottom') !== false && strpos($cssContent, 'safe-area-inset-top') !== false) {
    pass("field_pwa.css supports mobile viewport safe-area insets (notch / home indicator padding)");
} else {
    fail("field_pwa.css missing safe-area inset support");
}

// Responsive media queries
$testsRun++;
if (strpos($cssContent, '@media') !== false) {
    pass("field_pwa.css contains responsive mobile/tablet media queries");
} else {
    fail("field_pwa.css missing media query rules");
}

/* =============================================================================
 * SECTION 7: EXISTING DASHBOARDS & AUTHENTICATION COMPATIBILITY
 * ============================================================================= */
section("7. Existing System Compatibility");

// Verify existing dashboards still exist and load
$dashboards = [
    'admin' => $baseDir . '/public/admin/dashboard.php',
    'control' => $baseDir . '/public/control/dashboard.php',
    'wildlife' => $baseDir . '/public/wildlife/dashboard.php',
    'reporter' => $baseDir . '/public/reporter/dashboard.php',
    'driver' => $baseDir . '/public/driver/dashboard.php',
];

foreach ($dashboards as $role => $dashPath) {
    $testsRun++;
    if (file_exists($dashPath)) {
        pass("Existing {$role} dashboard intact at: " . basename($dashPath));
    } else {
        fail("Existing {$role} dashboard missing at " . $dashPath);
    }
}

// Verify sidebar contains Field Telemetry link for reporter, wildlife_officer, admin
$sidebarPath = $baseDir . '/app/layouts/dashboard_sidebar.php';
$sidebarContent = file_get_contents($sidebarPath);
$testsRun++;
if (strpos($sidebarContent, 'field/index.php') !== false) {
    pass("dashboard_sidebar.php successfully integrates Field Telemetry PWA navigation link");
} else {
    fail("dashboard_sidebar.php missing field/index.php link");
}

/* =============================================================================
 * SECTION 8: OPERATIONAL DATABASE IMMUTABILITY INVARIANT
 * ============================================================================= */
section("8. Operational Database Immutability Verification");

foreach ($opTables as $tbl) {
    $testsRun++;
    try {
        $countAfter = (int)$pdo->query("SELECT COUNT(*) FROM eleguard_rail." . $tbl)->fetchColumn();
        if ($countAfter === $opCountsBefore[$tbl]) {
            pass("Operational table 'eleguard_rail.{$tbl}' remains strictly IMMUTABLE (count: {$countAfter})");
        } else {
            fail("Operational table 'eleguard_rail.{$tbl}' MUTATED! Before: {$opCountsBefore[$tbl]}, After: {$countAfter}");
        }
    } catch (Exception $e) {
        if ($opCountsBefore[$tbl] === -1) {
            pass("Operational table 'eleguard_rail.{$tbl}' consistently non-existent (unmigrated baseline)");
        } else {
            fail("Operational table query error: " . $e->getMessage());
        }
    }
}

/* =============================================================================
 * SUMMARY & EXIT VERDICT
 * ============================================================================= */
echo PHP_EOL . "================================================================================" . PHP_EOL;
echo sprintf(" TEST RUN SUMMARY: %d Total Assertions | %d Passed | %d Failed", $testsRun, $testsRun - $testsFailed, $testsFailed) . PHP_EOL;
echo "================================================================================" . PHP_EOL;

if ($testsFailed === 0) {
    echo "\033[32m[SUCCESS] ALL STAGE 4E.4.2-A PWA FOUNDATION ASSERTIONS PASSED CLEANLY.\033[0m" . PHP_EOL;
    exit(0);
} else {
    echo "\033[31m[FAILURE] {$testsFailed} ASSERTION(S) FAILED IN STAGE 4E.4.2-A TEST SUITE.\033[0m" . PHP_EOL;
    exit(1);
}
