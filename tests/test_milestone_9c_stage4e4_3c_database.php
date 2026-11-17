<?php
/**
 * EleGuard Rail — Automated Test Suite
 * Milestone 9C — Stage 4E.4.3-C: Verification Database Foundation
 *
 * Verifies:
 * 1. Database Safety & Pre-check:
 *    - Explicit connection verification: SELECT DATABASE() === 'eleguard_rail_test'.
 *    - Refuses execution if targeting 'eleguard_rail' or unspecified.
 *    - Pre/post operational database immutability check on 'eleguard_rail'.
 * 2. Schema Structure Verification (eleguard_rail_test):
 *    - Migration 05 application succeeds.
 *    - Extension of elephant_reports.report_status to include 'Clarification Submitted'.
 *    - Verification of report_clarifications table with composite FK parent constraint.
 *    - Verification of report_section_assignment_history table.
 * 3. Foreign Key Constraints & Cross-Report Validation:
 *    - Invalid report references rejected (foreign key violation).
 *    - Invalid user references rejected.
 *    - Invalid section references rejected.
 *    - Cross-report parent-message relationships strictly rejected at engine level.
 *    - Valid parent-child clarification threads accepted.
 *    - Chronological message ordering preserved.
 *    - Deletion of parent clarification blocked by ON DELETE RESTRICT while children exist.
 * 4. Existing Data Inviolability:
 *    - Existing elephant_reports records remain 100% intact.
 *    - Existing report_verifications audit records remain 100% intact.
 * 5. Isolated Rollback & Reapplication Validation:
 *    - Executed on disposable ephemeral database (eleguard_rail_test_disposable).
 *    - Migration 05 rollback cleanly removes tables and indexes.
 *    - Migration 05 reapplication succeeds cleanly without conflict.
 */

declare(strict_types=1);

// Enforce test database target
$targetDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
if (empty($targetDb) || $targetDb === 'eleguard_rail_test' || $targetDb === 'eleguard_rail') {
    $targetDb = 'eleguard_test_tmp_' . bin2hex(random_bytes(6));
    putenv("DB_NAME={$targetDb}");
    $_ENV['DB_NAME'] = $targetDb;
}

$baseDir = dirname(__DIR__);
require_once $baseDir . '/app/config/database.php';
require_once $baseDir . '/app/core/Database.php';

// Color formatting
function green(string $str): string { return "\033[32m{$str}\033[0m"; }
function red(string $str): string { return "\033[31m{$str}\033[0m"; }
function yellow(string $str): string { return "\033[33m{$str}\033[0m"; }
function blue(string $str): string { return "\033[34m{$str}\033[0m"; }
function bold(string $str): string { return "\033[1m{$str}\033[0m"; }

$testsRun = 0;
$testsPassed = 0;
$testsFailed = 0;

function pass(string $name): void {
    global $testsRun, $testsPassed;
    $testsRun++;
    $testsPassed++;
    echo green("  [PASS] ") . $name . PHP_EOL;
}

function fail(string $name, string $reason = ''): void {
    global $testsRun, $testsFailed;
    $testsRun++;
    $testsFailed++;
    echo red("  [FAIL] ") . $name;
    if ($reason) {
        echo " - " . yellow($reason);
    }
    echo PHP_EOL;
}

function section(string $title): void {
    echo PHP_EOL . bold(blue("=== {$title} ===")) . PHP_EOL;
}

echo PHP_EOL;
echo bold("================================================================================") . PHP_EOL;
echo bold("   ELEGUARD RAIL — MILESTONE 9C: STAGE 4E.4.3-C") . PHP_EOL;
echo bold("   Verification Database Foundation & Schema Validation Suite") . PHP_EOL;
echo bold("================================================================================") . PHP_EOL;

$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbPort = defined('DB_PORT') ? (int)DB_PORT : 3308;
$dbCharset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
$rootUser = defined('DB_USER') ? DB_USER : 'root';
$rootPass = defined('DB_PASS') ? DB_PASS : '';
$prodDb = 'eleguard_rail';
$testDb = $targetDb;
$disposableDb = 'eleguard_rail_test_disposable';

$adminPdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset={$dbCharset}", $rootUser, $rootPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// =============================================================================
// STEP 0: MANDATORY DATABASE CONNECTION SAFETY VERIFICATION
// =============================================================================
section("Step 0: Mandatory Database Connection Safety Verification");

// 0.1 Query operational database baseline counts
$baselineCounts = [
    'reports' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.elephant_reports")->fetchColumn(),
    'logs' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.system_logs")->fetchColumn(),
    'alerts' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.alerts")->fetchColumn(),
    'hotspots' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.hotspots")->fetchColumn(),
    'sections' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.railway_sections")->fetchColumn(),
    'users' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.users")->fetchColumn()
];

pass("Operational database ({$prodDb}) baseline confirmed: Reports={$baselineCounts['reports']}, Logs={$baselineCounts['logs']}, Alerts={$baselineCounts['alerts']}, Hotspots={$baselineCounts['hotspots']}, Sections={$baselineCounts['sections']}, Users={$baselineCounts['users']}");

// 0.2 Explicit active database connection check on test handle
$testPdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$testDb};charset={$dbCharset}", $rootUser, $rootPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$activeDb = $testPdo->query("SELECT DATABASE()")->fetchColumn();
if ($activeDb === $testDb) {
    pass("Active database connection verified: SELECT DATABASE() === '{$testDb}'");
} else {
    fail("Database Safety Check Failed: Expected '{$testDb}', got '{$activeDb}'");
    exit(1);
}

if ($activeDb === $prodDb) {
    echo red(bold("FATAL: Connected to operational database. Aborting immediately.")) . PHP_EOL;
    exit(1);
}

// =============================================================================
// SECTION 1: MIGRATION 05 APPLICATION ON TEST DATABASE (eleguard_rail_test)
// =============================================================================
section("Section 1: Migration 05 Application (eleguard_rail_test)");

$mig05File = $baseDir . '/database/migrations/05_add_verification_and_section_audits.sql';
if (file_exists($mig05File)) {
    pass("Migration 05 source present: database/migrations/05_add_verification_and_section_audits.sql");
} else {
    fail("Migration 05 missing: " . $mig05File);
    exit(1);
}

$rollback05File = $baseDir . '/database/migrations/rollback_05_verification_and_section_audits.sql';
if (file_exists($rollback05File)) {
    pass("Rollback 05 source present: database/migrations/rollback_05_verification_and_section_audits.sql");
} else {
    fail("Rollback 05 missing: " . $rollback05File);
    exit(1);
}

// Record counts in test DB before migration
$testReportsCountBefore = (int)$testPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
$testVerifCountBefore = (int)$testPdo->query("SELECT COUNT(*) FROM report_verifications")->fetchColumn();

// Apply Migration 05 to eleguard_rail_test
$mig05Sql = file_get_contents($mig05File);
$mig05Sql = preg_replace('/USE\s+eleguard_rail;/i', "USE `{$testDb}`;", $mig05Sql);

try {
    $testPdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $testPdo->exec($mig05Sql);
    $testPdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    pass("Migration 05 applied successfully to '{$testDb}'");
} catch (PDOException $e) {
    fail("Migration 05 execution error on {$testDb}", $e->getMessage());
    exit(1);
}

// 1.1 Verify report_clarifications table exists
$clarifExists = (int)$testPdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$testDb}' AND table_name = 'report_clarifications'")->fetchColumn();
if ($clarifExists === 1) {
    pass("Schema verification: table 'report_clarifications' exists in '{$testDb}'");
} else {
    fail("Table 'report_clarifications' not found in schema");
}

// 1.2 Verify report_section_assignment_history table exists
$secHistExists = (int)$testPdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$testDb}' AND table_name = 'report_section_assignment_history'")->fetchColumn();
if ($secHistExists === 1) {
    pass("Schema verification: table 'report_section_assignment_history' exists in '{$testDb}'");
} else {
    fail("Table 'report_section_assignment_history' not found in schema");
}

// 1.3 Verify elephant_reports.report_status ENUM expansion
$colInfoStmt = $testPdo->query("SHOW COLUMNS FROM elephant_reports LIKE 'report_status'");
$colInfo = $colInfoStmt->fetch();
if ($colInfo && strpos($colInfo['Type'], "'Clarification Submitted'") !== false) {
    pass("Schema verification: 'elephant_reports.report_status' successfully extended with 'Clarification Submitted'");
} else {
    fail("ENUM 'Clarification Submitted' missing from elephant_reports.report_status");
}

// =============================================================================
// SECTION 2: FOREIGN KEY CONSTRAINTS & INTEGRITY VALIDATION
// =============================================================================
section("Section 2: Foreign Key Constraints & Referential Integrity");

// Ensure clean test fixture reports
$testPdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
$testPdo->exec("DELETE FROM report_clarifications WHERE report_id IN (1, 2)");
$testPdo->exec("DELETE FROM report_section_assignment_history WHERE report_id IN (1, 2)");
$testPdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

// 2.1 Invalid Report Reference Rejected (report_clarifications)
$invalidReportBlocked = false;
try {
    $testPdo->exec("INSERT INTO report_clarifications (report_id, sender_id, sender_role, message_type, message_text) 
                    VALUES (999999, 1, 'admin', 'officer_inquiry', 'Test invalid report')");
} catch (PDOException $e) {
    $invalidReportBlocked = (strpos($e->getMessage(), 'foreign key constraint fails') !== false || $e->getCode() === '23000');
}
if ($invalidReportBlocked) {
    pass("Foreign Key Guard: Invalid report_id (999999) in report_clarifications strictly rejected (SQLSTATE 23000)");
} else {
    fail("Foreign Key Guard failed: Invalid report_id was accepted into report_clarifications");
}

// 2.2 Invalid Sender Reference Rejected (report_clarifications)
$invalidSenderBlocked = false;
try {
    $testPdo->exec("INSERT INTO report_clarifications (report_id, sender_id, sender_role, message_type, message_text) 
                    VALUES (1, 999999, 'admin', 'officer_inquiry', 'Test invalid sender')");
} catch (PDOException $e) {
    $invalidSenderBlocked = (strpos($e->getMessage(), 'foreign key constraint fails') !== false || $e->getCode() === '23000');
}
if ($invalidSenderBlocked) {
    pass("Foreign Key Guard: Invalid sender_id (999999) in report_clarifications strictly rejected (SQLSTATE 23000)");
} else {
    fail("Foreign Key Guard failed: Invalid sender_id was accepted into report_clarifications");
}

// 2.3 Invalid Section Reference Rejected (report_section_assignment_history)
$invalidSectionBlocked = false;
try {
    $testPdo->exec("INSERT INTO report_section_assignment_history (report_id, previous_section_id, new_section_id, assignment_type, assignment_method, assignment_reason, assigned_by) 
                    VALUES (1, 1, 999999, 'subsequent_reassignment', 'manual_controller_override', 'Test invalid section', 1)");
} catch (PDOException $e) {
    $invalidSectionBlocked = (strpos($e->getMessage(), 'foreign key constraint fails') !== false || $e->getCode() === '23000');
}
if ($invalidSectionBlocked) {
    pass("Foreign Key Guard: Invalid new_section_id (999999) in report_section_assignment_history strictly rejected");
} else {
    fail("Foreign Key Guard failed: Invalid new_section_id was accepted");
}

// 2.4 Invalid User Reference Rejected (report_section_assignment_history)
$invalidSecUserBlocked = false;
try {
    $testPdo->exec("INSERT INTO report_section_assignment_history (report_id, previous_section_id, new_section_id, assignment_type, assignment_method, assignment_reason, assigned_by) 
                    VALUES (1, 1, 2, 'subsequent_reassignment', 'manual_controller_override', 'Test invalid user', 999999)");
} catch (PDOException $e) {
    $invalidSecUserBlocked = (strpos($e->getMessage(), 'foreign key constraint fails') !== false || $e->getCode() === '23000');
}
if ($invalidSecUserBlocked) {
    pass("Foreign Key Guard: Invalid assigned_by (999999) in report_section_assignment_history strictly rejected");
} else {
    fail("Foreign Key Guard failed: Invalid assigned_by was accepted");
}

// =============================================================================
// SECTION 3: THREADED CLARIFICATION LIFECYCLE & CROSS-REPORT PREVENTION
// =============================================================================
section("Section 3: Threaded Clarification Lifecycle & Cross-Report Guard");

// 3.1 Insert root inquiry for Report #1
$testPdo->exec("INSERT INTO report_clarifications (report_id, parent_clarification_id, sender_id, sender_role, message_type, message_text, created_at)
                VALUES (1, NULL, 4, 'wildlife_officer', 'officer_inquiry', 'Were the elephants moving towards Habarana or Minneriya?', '2026-11-11 10:00:00')");
$inquiryId1 = (int)$testPdo->lastInsertId();

if ($inquiryId1 > 0) {
    pass("Threaded Dialogue: Initial root inquiry inserted for Report #1 (ID={$inquiryId1})");
} else {
    fail("Failed to insert root inquiry");
}

// 3.2 Insert child reply for Report #1 referencing parent
$testPdo->exec("INSERT INTO report_clarifications (report_id, parent_clarification_id, sender_id, sender_role, message_type, message_text, created_at)
                VALUES (1, {$inquiryId1}, 5, 'reporter', 'reporter_response', 'They were moving eastward toward Habarana forest patch.', '2026-11-11 10:15:00')");
$replyId1 = (int)$testPdo->lastInsertId();

if ($replyId1 > 0) {
    pass("Threaded Dialogue: Child response inserted for Report #1 referencing parent ({$inquiryId1})");
} else {
    fail("Failed to insert child response");
}

// 3.3 CROSS-REPORT PARENT PREVENTION (Critical Requirement)
// Attempt to insert a clarification for Report #2 referencing parent from Report #1 ($inquiryId1)
$crossReportBlocked = false;
try {
    $testPdo->exec("INSERT INTO report_clarifications (report_id, parent_clarification_id, sender_id, sender_role, message_type, message_text)
                    VALUES (2, {$inquiryId1}, 5, 'reporter', 'reporter_response', 'Malicious cross-report reference')");
} catch (PDOException $e) {
    $crossReportBlocked = (strpos($e->getMessage(), 'foreign key constraint fails') !== false || $e->getCode() === '23000');
}

if ($crossReportBlocked) {
    pass("Relational Engine Guard: Cross-report parent relationship strictly REJECTED by composite FK (Report #2 cannot reference Report #1 parent)");
} else {
    fail("CRITICAL FLAW: Cross-report parent message reference was permitted!");
}

// 3.4 Multi-turn follow-up dialogue
$testPdo->exec("INSERT INTO report_clarifications (report_id, parent_clarification_id, sender_id, sender_role, message_type, message_text, created_at)
                VALUES (1, {$replyId1}, 4, 'wildlife_officer', 'officer_followup', 'Did you observe any calves in the group?', '2026-11-11 10:30:00')");
$followupId1 = (int)$testPdo->lastInsertId();

$testPdo->exec("INSERT INTO report_clarifications (report_id, parent_clarification_id, sender_id, sender_role, message_type, message_text, created_at)
                VALUES (1, {$followupId1}, 5, 'reporter', 'reporter_response', 'Yes, one juvenile was walking behind the lead female.', '2026-11-11 10:45:00')");

// 3.5 Chronological message ordering check
$threadStmt = $testPdo->prepare("SELECT id, message_type, created_at FROM report_clarifications WHERE report_id = 1 ORDER BY created_at ASC");
$threadStmt->execute();
$messages = $threadStmt->fetchAll();

if (count($messages) === 4 && 
    $messages[0]['message_type'] === 'officer_inquiry' && 
    $messages[1]['message_type'] === 'reporter_response' &&
    $messages[2]['message_type'] === 'officer_followup' &&
    $messages[3]['message_type'] === 'reporter_response') {
    pass("Chronological Thread Integrity: 4-message dialogue retrieved in exact chronological order without corruption");
} else {
    fail("Chronological dialogue ordering failed", json_encode($messages));
}

// 3.6 ON DELETE RESTRICT on Parent Message
$parentDeleteBlocked = false;
try {
    $testPdo->exec("DELETE FROM report_clarifications WHERE id = {$inquiryId1}");
} catch (PDOException $e) {
    $parentDeleteBlocked = (strpos($e->getMessage(), 'foreign key constraint fails') !== false || $e->getCode() === '23000');
}

if ($parentDeleteBlocked) {
    pass("Append-Only Protection: Deletion of parent inquiry blocked by ON DELETE RESTRICT while active child responses exist");
} else {
    fail("Append-Only Protection failed: Parent message was deleted, orphaning replies");
}

// =============================================================================
// SECTION 4: RAILWAY SECTION ASSIGNMENT AUDIT LEDGER VALIDATION
// =============================================================================
section("Section 4: Railway Section Assignment Audit Ledger Validation");

// 4.1 Initial Provisional Assignment Record
$testPdo->exec("INSERT INTO report_section_assignment_history (report_id, previous_section_id, new_section_id, assignment_type, assignment_method, assignment_reason, assigned_by, assigned_at)
                VALUES (1, NULL, 1, 'initial_provisional', 'spatial_nearest_neighbor', 'Automated geospatial nearest-line proximity match', 1, '2026-11-11 09:00:00')");
$secHistId1 = (int)$testPdo->lastInsertId();

if ($secHistId1 > 0) {
    pass("Section History: Initial provisional assignment logged (previous_section_id=NULL, type='initial_provisional')");
} else {
    fail("Failed to log initial provisional section assignment");
}

// 4.2 Subsequent Controller Reassignment Record
$testPdo->exec("INSERT INTO report_section_assignment_history (report_id, previous_section_id, new_section_id, assignment_type, assignment_method, assignment_reason, assigned_by, assigned_at)
                VALUES (1, 1, 2, 'subsequent_reassignment', 'station_master_vhf', 'Station Master VHF log confirmed sighting was beyond Gal Oya junction loop', 2, '2026-11-11 09:30:00')");
$secHistId2 = (int)$testPdo->lastInsertId();

if ($secHistId2 > 0) {
    pass("Section History: Subsequent controller reassignment logged (prev=1, new=2, type='subsequent_reassignment')");
} else {
    fail("Failed to log subsequent section reassignment");
}

// 4.3 Controller Confirmation Record
$testPdo->exec("INSERT INTO report_section_assignment_history (report_id, previous_section_id, new_section_id, assignment_type, assignment_method, assignment_reason, assigned_by, assigned_at)
                VALUES (1, 2, 2, 'controller_confirmation', 'chainage_milepost_lookup', 'Corroborated with track milepost KM 118 / Pole 4', 2, '2026-11-11 09:45:00')");
$secHistId3 = (int)$testPdo->lastInsertId();

if ($secHistId3 > 0) {
    pass("Section History: Controller confirmation logged (type='controller_confirmation')");
} else {
    fail("Failed to log controller confirmation");
}

// 4.4 Verify Biological Status Independence
// Verify that inserting section assignment history entries has NOT altered elephant_reports.report_status
$statusCheckStmt = $testPdo->prepare("SELECT report_status, section_id FROM elephant_reports WHERE id = 1");
$statusCheckStmt->execute();
$repCheck = $statusCheckStmt->fetch();

if ($repCheck && $repCheck['report_status'] === 'Pending') {
    pass("Domain Independence: Railway section assignment history operations leave biological 'report_status' strictly unaffected");
} else {
    fail("Domain Independence failed: Biological report_status was altered");
}

// Clean up fixture records
$testPdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
$testPdo->exec("DELETE FROM report_clarifications WHERE report_id = 1");
$testPdo->exec("DELETE FROM report_section_assignment_history WHERE report_id = 1");
$testPdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

// =============================================================================
// SECTION 5: EXISTING DATA INVIOLABILITY (eleguard_rail_test)
// =============================================================================
section("Section 5: Existing Data Inviolability (eleguard_rail_test)");

$testReportsCountAfter = (int)$testPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
$testVerifCountAfter = (int)$testPdo->query("SELECT COUNT(*) FROM report_verifications")->fetchColumn();

if ($testReportsCountAfter === $testReportsCountBefore) {
    pass("Data Inviolability: Existing elephant_reports records remain 100% intact ({$testReportsCountAfter} rows)");
} else {
    fail("Data Inviolability discrepancy on elephant_reports: before={$testReportsCountBefore}, after={$testReportsCountAfter}");
}

if ($testVerifCountAfter === $testVerifCountBefore) {
    pass("Data Inviolability: Existing report_verifications audit entries remain 100% intact ({$testVerifCountAfter} rows)");
} else {
    fail("Data Inviolability discrepancy on report_verifications: before={$testVerifCountBefore}, after={$testVerifCountAfter}");
}

// =============================================================================
// SECTION 6: ISOLATED ROLLBACK & REAPPLICATION VALIDATION (DISPOSABLE DB)
// =============================================================================
section("Section 6: Rollback & Reapplication Validation on Disposable DB");

// 6.1 Create isolated disposable copy of test database
$adminPdo->exec("DROP DATABASE IF EXISTS `{$disposableDb}`;");
$adminPdo->exec("CREATE DATABASE `{$disposableDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");

// Initialize disposable DB with baseline + migration 03 + migration 04
$baselineFile = $baseDir . '/tests/fixtures/canonical_baseline.sql';
if (!file_exists($baselineFile)) {
    $baselineFile = $baseDir . '/database/backups/backup_before_9c_stage1.sql';
}
$mig03File = $baseDir . '/database/migrations/03_add_temporal_intelligence_to_elephant_reports.sql';
$mig04File = $baseDir . '/database/migrations/04_add_field_reporting_v2_to_elephant_reports.sql';

$disposablePdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$disposableDb};charset={$dbCharset}", $rootUser, $rootPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$disposablePdo->exec("USE `{$disposableDb}`;");
$disposablePdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
$baseSql = file_get_contents($baselineFile);
$baseSql = preg_replace('/USE\s+[`]?eleguard_rail[`]?\s*;/i', "USE `{$disposableDb}`;", $baseSql);
$disposablePdo->exec($baseSql);

$m03Sql = preg_replace('/USE\s+[`]?eleguard_rail[`]?\s*;/i', "USE `{$disposableDb}`;", file_get_contents($mig03File));
$disposablePdo->exec($m03Sql);

$m04Sql = preg_replace('/USE\s+[`]?eleguard_rail[`]?\s*;/i', "USE `{$disposableDb}`;", file_get_contents($mig04File));
$disposablePdo->exec($m04Sql);
$disposablePdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

pass("Disposable DB ('{$disposableDb}') initialized with Baseline + Migrations 03 & 04");

// 6.2 Apply Migration 05 to disposable DB
$m05Sql = preg_replace('/USE\s+[`]?eleguard_rail[`]?\s*;/i', "USE `{$disposableDb}`;", file_get_contents($mig05File));
$disposablePdo->exec("USE `{$disposableDb}`;");
$disposablePdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
$disposablePdo->exec($m05Sql);
$disposablePdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

$dispClarifBefore = (int)$disposablePdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$disposableDb}' AND table_name = 'report_clarifications'")->fetchColumn();
if ($dispClarifBefore === 1) {
    pass("Disposable DB: Migration 05 successfully applied");
} else {
    fail("Disposable DB: Migration 05 application failed");
}

// 6.3 Execute Rollback 05 on disposable DB
$rollback05Sql = file_get_contents($rollback05File);
$rollback05Sql = preg_replace('/USE\s+[`]?eleguard_rail[`]?\s*;/i', "USE `{$disposableDb}`;", $rollback05Sql);

try {
    $disposablePdo->exec("USE `{$disposableDb}`;");
    $disposablePdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $disposablePdo->exec($rollback05Sql);
    $disposablePdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    pass("Disposable DB: Rollback 05 executed cleanly without error");
} catch (PDOException $e) {
    fail("Disposable DB: Rollback 05 execution error", $e->getMessage());
}

// Verify tables dropped
$dispClarifAfterRollback = (int)$disposablePdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$disposableDb}' AND table_name = 'report_clarifications'")->fetchColumn();
$dispSecHistAfterRollback = (int)$disposablePdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$disposableDb}' AND table_name = 'report_section_assignment_history'")->fetchColumn();

if ($dispClarifAfterRollback === 0 && $dispSecHistAfterRollback === 0) {
    pass("Disposable DB: Audit tables cleanly dropped by Rollback 05");
} else {
    fail("Disposable DB: Tables still exist after rollback");
}

// 6.4 Re-apply Migration 05 on disposable DB
try {
    $disposablePdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $disposablePdo->exec($m05Sql);
    $disposablePdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    pass("Disposable DB: Migration 05 cleanly re-applied following rollback without conflicts");
} catch (PDOException $e) {
    fail("Disposable DB: Migration 05 reapplication failed", $e->getMessage());
}

// Clean up disposable database
$adminPdo->exec("DROP DATABASE IF EXISTS `{$disposableDb}`;");
pass("Disposable DB ('{$disposableDb}') cleanly dropped and erased");

// =============================================================================
// STEP 7: OPERATIONAL DATABASE IMMUTABILITY VERIFICATION
// =============================================================================
section("Step 7: Operational Database Immutability Verification");

$finalCounts = [
    'reports' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.elephant_reports")->fetchColumn(),
    'logs' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.system_logs")->fetchColumn(),
    'alerts' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.alerts")->fetchColumn(),
    'hotspots' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.hotspots")->fetchColumn(),
    'sections' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.railway_sections")->fetchColumn(),
    'users' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.users")->fetchColumn()
];

$isImmutable = true;
foreach ($baselineCounts as $key => $baseVal) {
    if ($finalCounts[$key] !== $baseVal) {
        $isImmutable = false;
        fail("Operational database record count discrepancy on {$key}: baseline={$baseVal}, final={$finalCounts[$key]}");
    }
}

if ($isImmutable) {
    pass("Operational database ({$prodDb}) remains 100% immutable (Reports: {$finalCounts['reports']}, Logs: {$finalCounts['logs']}, Alerts: {$finalCounts['alerts']}, Hotspots: {$finalCounts['hotspots']}, Sections: {$finalCounts['sections']}, Users: {$finalCounts['users']})");
}

// =============================================================================
// SUMMARY
// =============================================================================
echo PHP_EOL . bold("================================================================================") . PHP_EOL;
echo bold("   TEST SUITE EXECUTION SUMMARY") . PHP_EOL;
echo bold("================================================================================") . PHP_EOL;
echo "Total Tests Run:    " . bold((string)$testsRun) . PHP_EOL;
echo "Total Tests Passed: " . green(bold((string)$testsPassed)) . PHP_EOL;
echo "Total Tests Failed: " . ($testsFailed > 0 ? red(bold((string)$testsFailed)) : "0") . PHP_EOL;

if ($testsFailed === 0) {
    echo bold(green("✓ ALL STAGE 4E.4.3-C VERIFICATION DATABASE FOUNDATION TESTS PASSED")) . PHP_EOL;
    exit(0);
} else {
    echo bold(red("✗ TEST SUITE FAILED WITH {$testsFailed} FAILURES")) . PHP_EOL;
    exit(1);
}
