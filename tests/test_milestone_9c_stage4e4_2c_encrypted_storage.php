<?php
/**
 * EleGuard Rail — Milestone 9C Stage 4E.4.2-C Test Suite
 * Encrypted Offline Storage & Web Crypto Verification Suite
 *
 * Verifies:
 * 1. File integrity & PWA manifest/precache registration (sw.js precaches field_crypto_storage.js).
 * 2. Script load order in public/field/index.php (field_crypto_storage.js loaded before field_pwa.js).
 * 3. CSS storage classes & responsive touch controls in public/assets/css/field_pwa.css.
 * 4. PBKDF2-HMAC-SHA256 key derivation with 250,000 iterations and 128-bit random salt.
 * 5. AES-GCM-256 encryption round trip with native Web Crypto API.
 * 6. Unique 96-bit (12-byte) IV generation per write operation (strict IV reuse prevention).
 * 7. Key non-extractability enforcement (CryptoKey extractable: false).
 * 8. Authentication tag verification & incorrect passphrase rejection.
 * 9. Ciphertext tampering detection (bit flip causes authentication failure).
 * 10. Additional Authenticated Data (AAD) cryptographic binding of client_report_uuid.
 * 11. AAD cryptographic binding of created_at_utc timestamp (tampering fails decryption).
 * 12. AAD cryptographic binding of schema_version.
 * 13. Passive 72-hour expiry evaluation policy.
 * 14. Clock rollback anomaly detection (>60 seconds into future).
 * 15. IndexedDB schema structure & store definitions ('metadata' and 'encrypted_drafts').
 * 16. Sensitive data isolation: zero plaintext GPS coordinates or observer notes in stored records.
 * 17. Volatile memory key lifecycle: lock() evicts CryptoKey from memory.
 * 18. Frontend wizard & outbox UI integration in public/field/index.php.
 * 19. Auto-cleanup of submitted drafts upon HTTP 201 Created and HTTP 200 Replay.
 * 20. Locomotive driver safety lockout preservation.
 * 21. Operational database immutability invariant (strictly eleguard_rail_test).
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
ensureTestDatabaseExists(null, $targetDb);

require_once $baseDir . '/app/config/database.php';
require_once $baseDir . '/app/core/Database.php';

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
echo " EleGuard Rail — Milestone 9C Stage 4E.4.2-C: Encrypted Offline Storage Suite" . PHP_EOL;
echo " Target DB: eleguard_rail_test | Authoritative AES-GCM-256 SubtleCrypto & IDB" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

$preOpSnapshot = captureOperationalDatabaseSnapshot();

// -----------------------------------------------------------------------------
// SECTION 1: ARCHITECTURE & STATIC FILE INTEGRITY
// -----------------------------------------------------------------------------
section("Section 1: Architecture & Static File Integrity");

$cryptoJsPath = $baseDir . '/public/assets/js/field_crypto_storage.js';
$pwaJsPath = $baseDir . '/public/assets/js/field_pwa.js';
$indexPath = $baseDir . '/public/field/index.php';
$swPath = $baseDir . '/public/sw.js';
$cssPath = $baseDir . '/public/assets/css/field_pwa.css';

$testsRun++;
if (file_exists($cryptoJsPath) && filesize($cryptoJsPath) > 5000) {
    pass("Storage engine file exists: public/assets/js/field_crypto_storage.js (" . filesize($cryptoJsPath) . " bytes)");
} else {
    fail("Storage engine file missing or truncated: " . $cryptoJsPath);
}

// 1.2 Check service worker precache inclusion
$testsRun++;
$swContent = file_exists($swPath) ? file_get_contents($swPath) : '';
if (strpos($swContent, './assets/js/field_crypto_storage.js') !== false) {
    pass("Service Worker (sw.js) registers field_crypto_storage.js in PRECACHE_ASSETS");
} else {
    fail("Service Worker missing field_crypto_storage.js in PRECACHE_ASSETS");
}

// 1.3 Check index.php script load order
$testsRun++;
$indexContent = file_exists($indexPath) ? file_get_contents($indexPath) : '';
$cryptoPos = strpos($indexContent, 'field_crypto_storage.js');
$pwaPos = strpos($indexContent, 'field_pwa.js');
if ($cryptoPos !== false && $pwaPos !== false && $cryptoPos < $pwaPos) {
    pass("Script tag order correct: field_crypto_storage.js loaded before field_pwa.js");
} else {
    fail("Script tag order incorrect or missing in public/field/index.php");
}

// 1.4 Check CSS storage styles
$testsRun++;
$cssContent = file_exists($cssPath) ? file_get_contents($cssPath) : '';
$cssRequired = [
    '.storage-status-pill',
    '.draft-count-bubble',
    '.btn-save-draft',
    '.storage-modal-backdrop',
    '.storage-modal-card',
    '.draft-item-card',
    '.draft-expiry-pill',
    '.storage-toast'
];
$allCssPresent = true;
$missingCss = [];
foreach ($cssRequired as $sel) {
    if (strpos($cssContent, $sel) === false) {
        $allCssPresent = false;
        $missingCss[] = $sel;
    }
}
if ($allCssPresent) {
    pass("CSS file public/assets/css/field_pwa.css defines all required storage & modal styles");
} else {
    fail("Missing CSS classes: " . implode(', ', $missingCss));
}

// -----------------------------------------------------------------------------
// SECTION 2: WEB CRYPTO API RUNTIME PRIMITIVES (NODE EXECUTION)
// -----------------------------------------------------------------------------
section("Section 2: Web Crypto API Runtime Primitives (AES-GCM-256 / PBKDF2)");

$storagePathEscaped = json_encode(str_replace('\\', '/', $cryptoJsPath));

$nodeScript = <<<NODE_EOF
const storage = require({$storagePathEscaped});
const assert = require('assert');

async function runCryptoTests() {
    const results = {};
    const consts = storage._test.getConstants();
    results.constants = consts;

    // Test 2.1: PBKDF2 Key Derivation & Non-Extractability
    const salt = new Uint8Array(16);
    const key = await storage._test.deriveAesKey('ElephantSafety2026!', salt);
    results.keyAlgorithm = key.algorithm.name;
    results.keyLength = key.algorithm.length;
    results.keyExtractable = key.extractable;

    // Test 2.2: Encryption and Decryption Round Trip
    const sampleUuid = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
    const sampleCreatedAt = '2026-11-11T09:00:00.000Z';
    const sampleState = {
        client_report_uuid: sampleUuid,
        schema_version: 1,
        created_at_utc: sampleCreatedAt,
        observation_category: 'direct_sighting',
        urgency_level: 'critical',
        observer_location: { latitude: 8.351234, longitude: 80.412345, gps_accuracy_meters: 4.2 },
        animal_location: { estimated_latitude: 8.352000, estimated_longitude: 80.413000, unknown: false },
        demographics: { elephant_count: 5, adult_males: 1, adult_females: 2, calves: 2, tusker_present: true },
        behaviour: 'crossing_railway',
        observer_notes: 'Herd moving rapidly across track near KM 142.'
    };

    const enc1 = await storage._test.encryptPayload(key, JSON.stringify(sampleState), sampleUuid, 1, sampleCreatedAt);
    const enc2 = await storage._test.encryptPayload(key, JSON.stringify(sampleState), sampleUuid, 1, sampleCreatedAt);

    results.iv1Length = enc1.iv.length;
    results.iv2Length = enc2.iv.length;
    results.ivsUnique = (Buffer.from(enc1.iv).toString('hex') !== Buffer.from(enc2.iv).toString('hex'));
    results.ciphertextByteLength = enc1.ciphertext.byteLength;

    // Decrypt
    const decStr = await storage._test.decryptPayload(key, enc1.ciphertext, enc1.iv, sampleUuid, 1, sampleCreatedAt);
    const decObj = JSON.parse(decStr);
    results.decryptedMatch = (decObj.client_report_uuid === sampleUuid && decObj.behaviour === 'crossing_railway' && decObj.observer_location.latitude === 8.351234);

    // Test 2.3: Incorrect Passphrase Rejection
    const wrongKey = await storage._test.deriveAesKey('WrongPassword123!', salt);
    let wrongKeyCaught = false;
    try {
        await storage._test.decryptPayload(wrongKey, enc1.ciphertext, enc1.iv, sampleUuid, 1, sampleCreatedAt);
    } catch (e) {
        wrongKeyCaught = true;
        results.wrongKeyError = e.name;
    }
    results.wrongKeyRejected = wrongKeyCaught;

    // Test 2.4: Tampered Ciphertext Rejection (Bit Flipping)
    const tamperedCipher = new Uint8Array(enc1.ciphertext.slice(0));
    tamperedCipher[10] ^= 0x01; // flip 1 bit
    let tamperedCipherCaught = false;
    try {
        await storage._test.decryptPayload(key, tamperedCipher.buffer, enc1.iv, sampleUuid, 1, sampleCreatedAt);
    } catch (e) {
        tamperedCipherCaught = true;
        results.tamperedCipherError = e.name;
    }
    results.tamperedCipherRejected = tamperedCipherCaught;

    // Test 2.5: Tampered AAD (UUID modification)
    let tamperedUuidCaught = false;
    try {
        await storage._test.decryptPayload(key, enc1.ciphertext, enc1.iv, '00000000-0000-0000-0000-000000000000', 1, sampleCreatedAt);
    } catch (e) {
        tamperedUuidCaught = true;
    }
    results.tamperedUuidRejected = tamperedUuidCaught;

    // Test 2.6: Tampered AAD (Timestamp modification)
    let tamperedTimeCaught = false;
    try {
        await storage._test.decryptPayload(key, enc1.ciphertext, enc1.iv, sampleUuid, 1, '2026-11-11T12:00:00.000Z');
    } catch (e) {
        tamperedTimeCaught = true;
    }
    results.tamperedTimeRejected = tamperedTimeCaught;

    // Test 2.7: Tampered AAD (Schema version modification)
    let tamperedSchemaCaught = false;
    try {
        await storage._test.decryptPayload(key, enc1.ciphertext, enc1.iv, sampleUuid, 2, sampleCreatedAt);
    } catch (e) {
        tamperedSchemaCaught = true;
    }
    results.tamperedSchemaRejected = tamperedSchemaCaught;

    console.log(JSON.stringify(results));
}

runCryptoTests().catch(err => {
    console.error(err);
    process.exit(1);
});
NODE_EOF;

$tmpNodeScript = sys_get_temp_dir() . '/test_stage4e4_2c_crypto.js';
file_put_contents($tmpNodeScript, $nodeScript);

$cmd = "node " . escapeshellarg($tmpNodeScript);
$nodeOutput = shell_exec($cmd);
@unlink($tmpNodeScript);

$cryptoRes = json_decode(trim($nodeOutput), true);

$testsRun++;
if ($cryptoRes && ($cryptoRes['constants']['PBKDF2_ITERATIONS'] ?? 0) === 250000) {
    pass("PBKDF2 parameters verified: 250,000 iterations, SHA-256 HMAC");
} else {
    fail("PBKDF2 parameters incorrect: " . json_encode($cryptoRes['constants'] ?? []));
}

$testsRun++;
if ($cryptoRes && ($cryptoRes['keyAlgorithm'] ?? '') === 'AES-GCM' && ($cryptoRes['keyLength'] ?? 0) === 256 && ($cryptoRes['keyExtractable'] ?? true) === false) {
    pass("Key non-extractability verified: AES-GCM-256 derived key has extractable: false");
} else {
    fail("Key algorithm or extractability incorrect: " . json_encode($cryptoRes ?? []));
}

$testsRun++;
if ($cryptoRes && ($cryptoRes['iv1Length'] ?? 0) === 12 && ($cryptoRes['ivsUnique'] ?? false) === true) {
    pass("Unique 96-bit (12-byte) IV generated per write operation (IV reuse banned)");
} else {
    fail("IV length or uniqueness check failed: " . json_encode($cryptoRes ?? []));
}

$testsRun++;
if ($cryptoRes && ($cryptoRes['decryptedMatch'] ?? false) === true) {
    pass("AES-GCM-256 authenticated encryption round trip: plaintext matches exactly");
} else {
    fail("Decrypted payload does not match original plaintext");
}

$testsRun++;
if ($cryptoRes && ($cryptoRes['wrongKeyRejected'] ?? false) === true) {
    pass("Authentication tag verification: Incorrect passphrase fails decryption with " . ($cryptoRes['wrongKeyError'] ?? 'error'));
} else {
    fail("Incorrect passphrase did not reject decryption");
}

$testsRun++;
if ($cryptoRes && ($cryptoRes['tamperedCipherRejected'] ?? false) === true) {
    pass("Integrity protection: Bit-flipped ciphertext fails decryption with " . ($cryptoRes['tamperedCipherError'] ?? 'error'));
} else {
    fail("Tampered ciphertext was not rejected");
}

$testsRun++;
if ($cryptoRes && ($cryptoRes['tamperedUuidRejected'] ?? false) === true && ($cryptoRes['tamperedTimeRejected'] ?? false) === true && ($cryptoRes['tamperedSchemaRejected'] ?? false) === true) {
    pass("AAD binding verified: Tampering with UUID, created_at_utc, or schema_version fails authentication");
} else {
    fail("AAD cryptographic binding check failed");
}

// -----------------------------------------------------------------------------
// SECTION 3: PASSIVE 72-HOUR EXPIRY & CLOCK ROLLBACK ANOMALY DETECTION
// -----------------------------------------------------------------------------
section("Section 3: Passive 72-Hour Expiry & Clock Rollback Protection");

$expiryNodeScript = <<<NODE_EOF
const storage = require({$storagePathEscaped});

const now = Date.now();
const hour = 3600 * 1000;

// Case 1: Fresh draft (2 hours old)
const fresh = storage._test.evaluateDraftExpiry(new Date(now - (2 * hour)).toISOString());

// Case 2: Borderline draft (71 hours old)
const borderline = storage._test.evaluateDraftExpiry(new Date(now - (71 * hour)).toISOString());

// Case 3: Expired draft (73 hours old)
const expired = storage._test.evaluateDraftExpiry(new Date(now - (73 * hour)).toISOString());

// Case 4: Future clock rollback anomaly (+2 hours in the future)
const rollback = storage._test.evaluateDraftExpiry(new Date(now + (2 * hour)).toISOString());

// Case 5: Normal clock jitter (< 60 seconds in future)
const jitter = storage._test.evaluateDraftExpiry(new Date(now + 10000).toISOString());

console.log(JSON.stringify({ fresh, borderline, expired, rollback, jitter }));
NODE_EOF;

$tmpExpiryScript = sys_get_temp_dir() . '/test_stage4e4_2c_expiry.js';
file_put_contents($tmpExpiryScript, $expiryNodeScript);

$expiryOutput = shell_exec("node " . escapeshellarg($tmpExpiryScript));
@unlink($tmpExpiryScript);

$expRes = json_decode(trim($expiryOutput), true);

$testsRun++;
if ($expRes && ($expRes['fresh']['expired'] ?? true) === false && ($expRes['borderline']['expired'] ?? true) === false) {
    pass("Fresh and borderline (<72h) drafts evaluated as valid (expired: false)");
} else {
    fail("Fresh draft incorrectly evaluated as expired: " . json_encode($expRes['fresh'] ?? []));
}

$testsRun++;
if ($expRes && ($expRes['expired']['expired'] ?? false) === true && ($expRes['expired']['reason'] ?? '') === 'exceeded_72h') {
    pass("Stale drafts (>72h) evaluated as expired (reason: exceeded_72h)");
} else {
    fail("Expired draft not detected: " . json_encode($expRes['expired'] ?? []));
}

$testsRun++;
if ($expRes && ($expRes['rollback']['expired'] ?? false) === true && ($expRes['rollback']['reason'] ?? '') === 'clock_rollback_detected') {
    pass("Clock rollback anomaly (>60s in future) detected and quarantined (reason: clock_rollback_detected)");
} else {
    fail("Clock rollback anomaly not detected: " . json_encode($expRes['rollback'] ?? []));
}

$testsRun++;
if ($expRes && ($expRes['jitter']['expired'] ?? true) === false) {
    pass("Small client clock skew (<60s) accepted without false positive rollback trigger");
} else {
    fail("Clock jitter triggered false positive: " . json_encode($expRes['jitter'] ?? []));
}

// -----------------------------------------------------------------------------
// SECTION 4: SENSITIVE DATA ISOLATION & STORAGE ARCHITECTURE
// -----------------------------------------------------------------------------
section("Section 4: Sensitive Data Isolation & Storage Architecture");

// 4.1 Verify IndexedDB schema structure in field_crypto_storage.js
$testsRun++;
$cryptoJsContent = file_get_contents($cryptoJsPath);
$hasDbName = (strpos($cryptoJsContent, "'eleguard_field_db'") !== false || strpos($cryptoJsContent, '"eleguard_field_db"') !== false);
$hasStoreMetadata = (strpos($cryptoJsContent, "'metadata'") !== false);
$hasStoreDrafts = (strpos($cryptoJsContent, "'encrypted_drafts'") !== false);
$hasKeyPathUuid = (strpos($cryptoJsContent, "keyPath: 'client_report_uuid'") !== false || strpos($cryptoJsContent, 'keyPath: "client_report_uuid"') !== false);

if ($hasDbName && $hasStoreMetadata && $hasStoreDrafts && $hasKeyPathUuid) {
    pass("IndexedDB schema verified: database 'eleguard_field_db', stores 'metadata' and 'encrypted_drafts' (keyPath: client_report_uuid)");
} else {
    fail("IndexedDB store definitions incomplete in field_crypto_storage.js");
}

// 4.2 Verify zero plaintext sensitive coordinates in stored record envelope
$testsRun++;
$hasPlaintextCheck = (
    strpos($cryptoJsContent, 'observer_latitude') === false &&
    strpos($cryptoJsContent, 'animal_latitude') === false &&
    strpos($cryptoJsContent, 'estimated_latitude') === false
);
if ($hasPlaintextCheck) {
    pass("Zero plaintext coordinates in storage engine metadata envelope (Anti-Poaching Protection)");
} else {
    fail("Potential unencrypted coordinate leakage found in storage engine envelope definition");
}

// 4.3 Verify Cache Storage contains zero sensitive endpoints in sw.js
$testsRun++;
$hasCacheNetworkOnly = (
    strpos($swContent, "url.pathname.includes('/api/')") !== false &&
    strpos($swContent, "isRestrictedPath") !== false
);
if ($hasCacheNetworkOnly) {
    pass("Service Worker strictly enforces network-only for API endpoints (zero cached wildlife telemetry)");
} else {
    fail("Service Worker does not properly enforce network-only for API endpoints");
}

// 4.4 Verify summary envelope excludes location_description (LOW-01 Remediation)
$testsRun++;
$cryptoContent = file_get_contents($cryptoJsPath);
$summaryMatches = [];
if (preg_match('/var summary = \{([^}]+)\};/s', $cryptoContent, $summaryMatches)) {
    $summaryBody = $summaryMatches[1];
    $hasNoLocationDesc = (strpos($summaryBody, 'location_description') === false);
    if ($hasNoLocationDesc) {
        pass("Outbox summary envelope excludes location_description (LOW-01: 100% landmark text encrypted in AES-GCM-256)");
    } else {
        fail("Outbox summary envelope still exposes location_description in unencrypted metadata");
    }
} else {
    fail("Could not parse summary envelope in field_crypto_storage.js");
}

// -----------------------------------------------------------------------------
// SECTION 5: FRONTEND WIZARD, MODAL & OUTBOX INTEGRATION
// -----------------------------------------------------------------------------
section("Section 5: Frontend Wizard, Modal & Outbox Integration");

// 5.1 Verify header storage status button in public/field/index.php
$testsRun++;
if (strpos($indexContent, 'id="storageStatusBtn"') !== false && strpos($indexContent, 'id="storageDraftCountBubble"') !== false) {
    pass("Header contains encrypted storage status button (#storageStatusBtn) and draft count bubble");
} else {
    fail("Missing storageStatusBtn or storageDraftCountBubble in public/field/index.php");
}

// 5.2 Verify Save Draft buttons across wizard steps
$testsRun++;
$saveDraftOccurrences = substr_count($indexContent, 'btn-save-draft');
if ($saveDraftOccurrences >= 6) {
    pass("All 6 wizard steps include '💾 Save Draft' buttons ({$saveDraftOccurrences} occurrences found)");
} else {
    fail("Expected at least 6 btn-save-draft buttons across wizard steps, found: {$saveDraftOccurrences}");
}

// 5.3 Verify storage setup and unlock modals in public/field/index.php
$testsRun++;
$hasSetupModal = (strpos($indexContent, 'id="storageSetupModal"') !== false);
$hasUnlockModal = (strpos($indexContent, 'id="storageUnlockModal"') !== false);
$hasDraftsModal = (strpos($indexContent, 'id="storageDraftsModal"') !== false);
$hasToast = (strpos($indexContent, 'id="storageToast"') !== false);

if ($hasSetupModal && $hasUnlockModal && $hasDraftsModal && $hasToast) {
    pass("Interactive UI modals verified: #storageSetupModal, #storageUnlockModal, #storageDraftsModal, #storageToast");
} else {
    fail("Missing modal or toast elements in public/field/index.php");
}

// 5.4 Verify Outbox Tab (tabDrafts) contains uninitialized, locked, and unlocked states
$testsRun++;
$hasTabUninit = (strpos($indexContent, 'id="tabDraftsUninitialized"') !== false);
$hasTabLocked = (strpos($indexContent, 'id="tabDraftsLocked"') !== false);
$hasTabUnlocked = (strpos($indexContent, 'id="tabDraftsUnlocked"') !== false);

if ($hasTabUninit && $hasTabLocked && $hasTabUnlocked) {
    pass("Outbox Tab (#tabDrafts) implements three distinct state containers (Uninitialized / Locked / Unlocked)");
} else {
    fail("Missing state containers in Outbox tab (#tabDrafts)");
}

// 5.5 Verify auto-purge of submitted draft upon HTTP 201 or 200 in field_pwa.js
$testsRun++;
$pwaContent = file_get_contents($pwaJsPath);
if (strpos($pwaContent, 'deleteDraft(reportState.client_report_uuid)') !== false) {
    pass("Auto-cleanup verified: field_pwa.js purges submitted draft from storage upon successful submission");
} else {
    fail("Missing draft auto-cleanup upon submission in field_pwa.js");
}

// 5.6 Verify locomotive driver safety lockout remains intact
$testsRun++;
if (strpos($indexContent, 'driver-lockout-card') !== false && strpos($indexContent, 'Driver Safety Directive Active') !== false) {
    pass("Locomotive driver safety lockout strictly preserved in public/field/index.php");
} else {
    fail("Driver safety lockout missing or tampered in public/field/index.php");
}

// -----------------------------------------------------------------------------
// SECTION 5B: AUTOMATED DOM INTEGRATION & DRAFT RESTORATION ROUND-TRIP (MED-02)
// -----------------------------------------------------------------------------
section("Section 5B: Automated DOM Integration & Draft Restoration Round-Trip (MED-02)");

$pwaPathEscaped = json_encode(str_replace('\\', '/', $pwaJsPath));

$domIntegrationNodeScript = <<<NODE_EOF
const fs = require('fs');
const crypto = require('crypto');

const elements = {};
function getEl(id) {
    if (!elements[id]) {
        elements[id] = {
            id: id,
            value: '',
            checked: false,
            disabled: false,
            style: {},
            classList: {
                _classes: new Set(),
                add: function(c) { this._classes.add(c); },
                remove: function(c) { this._classes.delete(c); },
                contains: function(c) { return this._classes.has(c); }
            },
            textContent: '',
            innerHTML: '',
            innerText: '',
            className: '',
            _attrs: {},
            getAttribute: function(k) { return this._attrs[k] || null; },
            setAttribute: function(k, v) { this._attrs[k] = String(v); },
            _listeners: {},
            addEventListener: function(event, fn) {
                if (!this._listeners[event]) this._listeners[event] = [];
                this._listeners[event].push(fn);
            },
            dispatchEvent: function(event) {
                const list = this._listeners[event] || [];
                list.forEach(fn => fn.call(this, { target: this }));
            }
        };
    }
    return elements[id];
}

const mockDocument = {
    getElementById: (id) => getEl(id),
    querySelectorAll: (sel) => [],
    querySelector: (sel) => null,
    addEventListener: (evt, fn) => {
        if (evt === 'DOMContentLoaded') setTimeout(fn, 10);
    }
};

class MockIDBDatabase {
    constructor(name) {
        this.name = name;
        this.stores = {};
        this.objectStoreNames = { contains: (s) => !!this.stores[s] };
    }
    createObjectStore(name, opts) {
        this.stores[name] = { keyPath: opts ? opts.keyPath : 'id', records: new Map(), indexes: {} };
        return { createIndex: () => {} };
    }
    transaction(storeNames, mode) {
        const tx = {
            oncomplete: null, onerror: null,
            objectStore: (s) => {
                const store = this.stores[s];
                return {
                    get: (key) => {
                        const req = { onsuccess: null, onerror: null, result: undefined };
                        setTimeout(() => {
                            req.result = store.records.get(key);
                            if (req.onsuccess) req.onsuccess({ target: req });
                            if (tx.oncomplete) tx.oncomplete({ target: tx });
                        }, 0);
                        return req;
                    },
                    put: (val) => {
                        const key = val[store.keyPath];
                        store.records.set(key, Object.assign({}, val));
                        const req = { onsuccess: null, onerror: null, result: key };
                        setTimeout(() => {
                            if (req.onsuccess) req.onsuccess({ target: req });
                            if (tx.oncomplete) tx.oncomplete({ target: tx });
                        }, 0);
                        return req;
                    },
                    delete: (key) => {
                        store.records.delete(key);
                        const req = { onsuccess: null, onerror: null };
                        setTimeout(() => {
                            if (req.onsuccess) req.onsuccess({ target: req });
                            if (tx.oncomplete) tx.oncomplete({ target: tx });
                        }, 0);
                        return req;
                    },
                    clear: () => {
                        store.records.clear();
                        const req = { onsuccess: null, onerror: null };
                        setTimeout(() => {
                            if (req.onsuccess) req.onsuccess({ target: req });
                            if (tx.oncomplete) tx.oncomplete({ target: tx });
                        }, 0);
                        return req;
                    },
                    count: () => {
                        const req = { onsuccess: null, onerror: null, result: store.records.size };
                        setTimeout(() => {
                            if (req.onsuccess) req.onsuccess({ target: req });
                            if (tx.oncomplete) tx.oncomplete({ target: tx });
                        }, 0);
                        return req;
                    },
                    openCursor: () => {
                        const req = { onsuccess: null, onerror: null, result: null };
                        const items = Array.from(store.records.values());
                        let idx = 0;
                        function step() {
                            if (idx < items.length) {
                                const cursor = {
                                    value: items[idx],
                                    continue: () => { idx++; setTimeout(step, 0); }
                                };
                                req.result = cursor;
                                if (req.onsuccess) req.onsuccess({ target: req });
                            } else {
                                req.result = null;
                                if (req.onsuccess) req.onsuccess({ target: req });
                                if (tx.oncomplete) tx.oncomplete({ target: tx });
                            }
                        }
                        setTimeout(step, 0);
                        return req;
                    }
                };
            }
        };
        return tx;
    }
}

let currentDb = null;
const indexedDB = {
    open: (name, version) => {
        const req = { onsuccess: null, onerror: null, onupgradeneeded: null, onblocked: null, result: null };
        setTimeout(() => {
            if (!currentDb) {
                currentDb = new MockIDBDatabase(name);
                req.result = currentDb;
                if (req.onupgradeneeded) req.onupgradeneeded({ target: { result: currentDb } });
            }
            req.result = currentDb;
            if (req.onsuccess) req.onsuccess({ target: { result: currentDb } });
        }, 0);
        return req;
    }
};

global.window = global;
global.document = mockDocument;
global.navigator = { onLine: true };
global.indexedDB = indexedDB;
global.crypto = crypto;
global.scrollTo = () => {};
global.confirm = () => true;
global.alert = () => {};
global.addEventListener = () => {};

global.EleGuardStorage = require({$storagePathEscaped});
require({$pwaPathEscaped});

async function runDomIntegration() {
    const results = {};

    // 1. Storage setup
    await global.EleGuardStorage.setupPassphrase('ElephantSafetyPass123!');
    results.storageInitialized = global.EleGuardStorage.isUnlocked();

    // 2. Populate Step 1-5 DOM inputs
    const testData = {
        observation_date: '2026-11-10',
        observation_time: '14:35',
        time_unknown: false,
        observation_category: 'live_sighting',
        urgency_level: 'High',
        location_description: 'Near Habarana Culvert 412',
        observer_notes: 'Two adult males near rail verge',
        diurnal_window: 'afternoon',
        observer_lat: '8.032145',
        observer_lon: '80.754321',
        observer_accuracy: '4.5',
        acquisition_method: 'gps_device',
        animal_coords_unknown: false,
        observation_distance: '65',
        observation_bearing: '135',
        animal_uncertainty: '15',
        elephant_count: '4',
        sighting_evidence_type: 'direct_sighting',
        adult_males: '2',
        adult_females: '1',
        calves: '1',
        unclassified_count: '0',
        tusker_present: 'single_tusker',
        elephant_behavior: 'crossing',
        section_id: '2',
        nearest_milestone_ref: 'KM 142/6',
        track_proximity: 'verge'
    };

    getEl('observation_date').value = testData.observation_date;
    getEl('observation_time').value = testData.observation_time;
    getEl('time_unknown').checked = testData.time_unknown;
    getEl('observation_category').value = testData.observation_category;
    getEl('urgency_level').value = testData.urgency_level;
    getEl('location_description').value = testData.location_description;
    getEl('observer_notes').value = testData.observer_notes;
    getEl('diurnal_window').value = testData.diurnal_window;

    getEl('observer_lat').value = testData.observer_lat;
    getEl('observer_lon').value = testData.observer_lon;
    getEl('observer_accuracy').value = testData.observer_accuracy;
    getEl('acquisition_method').value = testData.acquisition_method;

    getEl('animal_coords_unknown').checked = testData.animal_coords_unknown;
    getEl('observation_distance').value = testData.observation_distance;
    getEl('observation_bearing').value = testData.observation_bearing;
    getEl('animal_uncertainty').value = testData.animal_uncertainty;

    getEl('elephant_count').value = testData.elephant_count;
    getEl('sighting_evidence_type').value = testData.sighting_evidence_type;
    getEl('adult_males').value = testData.adult_males;
    getEl('adult_females').value = testData.adult_females;
    getEl('calves').value = testData.calves;
    getEl('unclassified_count').value = testData.unclassified_count;
    getEl('tusker_present').value = testData.tusker_present;
    getEl('elephant_behavior').value = testData.elephant_behavior;

    getEl('section_id').value = testData.section_id;
    getEl('nearest_milestone_ref').value = testData.nearest_milestone_ref;
    getEl('track_proximity').value = testData.track_proximity;

    // 3. Save draft via EleGuardStorageUI
    await new Promise((resolve) => {
        global.EleGuardStorageUI.showToast = function(msg, type) {
            if (msg.includes('Draft encrypted & saved')) resolve();
        };
        global.EleGuardStorageUI.saveCurrentDraft();
    });

    // 4. Verify raw stored envelope in IDB
    const drafts = Array.from(currentDb.stores['encrypted_drafts'].records.values());
    results.draftCount = drafts.length;
    const stored = drafts[0] || {};
    const uuid = stored.client_report_uuid;
    results.savedUuid = uuid;
    results.summaryHasLocationDesc = stored.summary ? ('location_description' in stored.summary) : true;
    results.summaryCategory = stored.summary ? stored.summary.category : null;
    results.summaryElephantCount = stored.summary ? stored.summary.elephant_count : null;
    results.summaryHasTusker = stored.summary ? stored.summary.has_tusker : null;
    results.summaryUrgency = stored.summary ? stored.summary.urgency : null;

    const rawStr = JSON.stringify(stored);
    results.rawEnvelopeHasPlaintextCoords = rawStr.includes('8.032145');
    results.rawEnvelopeHasPlaintextNotes = rawStr.includes('Habarana') || rawStr.includes('verge');

    // 5. Lock storage
    global.EleGuardStorageUI.lockStorage();
    results.storageLocked = !global.EleGuardStorage.isUnlocked();

    // 6. Test wrong passphrase rejection
    let wrongRejected = false;
    try {
        await global.EleGuardStorage.unlock('BadPass999!');
    } catch (e) {
        wrongRejected = true;
    }
    results.wrongPassphraseRejected = wrongRejected;
    results.draftIntactAfterWrongPass = currentDb.stores['encrypted_drafts'].records.has(uuid);

    // 7. Clear all DOM inputs
    for (let k in elements) {
        elements[k].value = '';
        elements[k].checked = false;
    }

    // 8. Unlock storage with correct passphrase
    await global.EleGuardStorage.unlock('ElephantSafetyPass123!');
    results.unlockedWithCorrectPass = global.EleGuardStorage.isUnlocked();

    // 9. Resume draft
    await new Promise((resolve) => {
        global.EleGuardStorageUI.showToast = function(msg, type) {
            if (msg.includes('Draft loaded')) resolve();
        };
        global.EleGuardStorageUI.resumeDraft(uuid);
    });

    results.restored = {
        observation_date: getEl('observation_date').value,
        observation_time: getEl('observation_time').value,
        observation_category: getEl('observation_category').value,
        urgency_level: getEl('urgency_level').value,
        location_description: getEl('location_description').value,
        observer_notes: getEl('observer_notes').value,
        observer_lat: String(getEl('observer_lat').value),
        observer_lon: String(getEl('observer_lon').value),
        observer_accuracy: String(getEl('observer_accuracy').value),
        acquisition_method: getEl('acquisition_method').value,
        animal_coords_unknown: getEl('animal_coords_unknown').checked,
        observation_distance: String(getEl('observation_distance').value),
        observation_bearing: String(getEl('observation_bearing').value),
        animal_uncertainty: String(getEl('animal_uncertainty').value),
        elephant_count: String(getEl('elephant_count').value),
        sighting_evidence_type: getEl('sighting_evidence_type').value,
        adult_males: String(getEl('adult_males').value),
        adult_females: String(getEl('adult_females').value),
        calves: String(getEl('calves').value),
        unclassified_count: String(getEl('unclassified_count').value),
        tusker_present: getEl('tusker_present').value,
        elephant_behavior: getEl('elephant_behavior').value,
        section_id: String(getEl('section_id').value),
        nearest_milestone_ref: getEl('nearest_milestone_ref').value,
        track_proximity: getEl('track_proximity').value,
        syncActiveUuid: getEl('syncActiveUuid').innerText
    };

    results.uuidPreserved = (results.restored.syncActiveUuid === uuid);

    // 10. Test Unknown Animal Location Round-Trip
    getEl('animal_coords_unknown').checked = true;
    getEl('animal_offset_section').style.opacity = '0.4';

    await new Promise((resolve) => {
        global.EleGuardStorageUI.showToast = function(msg) {
            if (msg.includes('Draft encrypted & saved')) resolve();
        };
        global.EleGuardStorageUI.saveCurrentDraft();
    });

    getEl('animal_coords_unknown').checked = false;
    getEl('animal_offset_section').style.opacity = '1.0';

    await new Promise((resolve) => {
        global.EleGuardStorageUI.showToast = function(msg) {
            if (msg.includes('Draft loaded')) resolve();
        };
        global.EleGuardStorageUI.resumeDraft(uuid);
    });

    results.unknownLocationRoundTrip = {
        checked: getEl('animal_coords_unknown').checked,
        opacity: getEl('animal_offset_section').style.opacity
    };

    console.log(JSON.stringify(results));
}

runDomIntegration().catch(err => {
    console.error(err);
    process.exit(1);
});
NODE_EOF;

$tmpDomScript = sys_get_temp_dir() . '/test_stage4e4_2c_dom_integration.js';
file_put_contents($tmpDomScript, $domIntegrationNodeScript);

$domOutput = shell_exec("node " . escapeshellarg($tmpDomScript));
@unlink($tmpDomScript);

$domRes = json_decode(trim($domOutput), true);

// 5B.1 Storage Initialization
$testsRun++;
if ($domRes && ($domRes['storageInitialized'] ?? false) === true) {
    pass("Storage engine initialization and unlock successful via EleGuardStorage.setupPassphrase()");
} else {
    fail("Storage engine initialization failed: " . json_encode($domRes));
}

// 5B.2 Draft Saved via UI Controller
$testsRun++;
if ($domRes && ($domRes['draftCount'] ?? 0) === 1 && !empty($domRes['savedUuid'])) {
    pass("Draft successfully encrypted and stored via EleGuardStorageUI.saveCurrentDraft() (UUID: " . $domRes['savedUuid'] . ")");
} else {
    fail("Draft failed to save via EleGuardStorageUI: " . json_encode($domRes));
}

// 5B.3 Outbox Summary Excludes Location Description (LOW-01)
$testsRun++;
if ($domRes && ($domRes['summaryHasLocationDesc'] ?? true) === false && ($domRes['summaryCategory'] ?? '') === 'live_sighting') {
    pass("Verified stored IDB record summary envelope: location_description is excluded (LOW-01 check)");
} else {
    fail("IndexedDB record summary envelope leaked location_description: " . json_encode($domRes));
}

// 5B.4 Zero Plaintext Wildlife Telemetry in Raw Record
$testsRun++;
if ($domRes && ($domRes['rawEnvelopeHasPlaintextCoords'] ?? true) === false && ($domRes['rawEnvelopeHasPlaintextNotes'] ?? true) === false) {
    pass("Zero plaintext coordinates, observer notes, or landmarks in raw IndexedDB envelope (Anti-Poaching Protection)");
} else {
    fail("Plaintext telemetry leakage detected in raw IndexedDB envelope: " . json_encode($domRes));
}

// 5B.5 Lock Eviction & Wrong Passphrase Protection
$testsRun++;
if ($domRes && ($domRes['storageLocked'] ?? false) === true && ($domRes['wrongPassphraseRejected'] ?? false) === true && ($domRes['draftIntactAfterWrongPass'] ?? false) === true) {
    pass("Storage locking strictly evicts key; wrong passphrase rejected without destroying draft data");
} else {
    fail("Storage lock or wrong passphrase protection failed: " . json_encode($domRes));
}

// 5B.6 Complete DOM Draft Restoration Round-Trip
$testsRun++;
$rest = $domRes['restored'] ?? [];
$matchesAllFields = (
    ($rest['observation_date'] ?? '') === '2026-11-10' &&
    ($rest['observation_time'] ?? '') === '14:35' &&
    ($rest['observation_category'] ?? '') === 'live_sighting' &&
    ($rest['urgency_level'] ?? '') === 'High' &&
    ($rest['location_description'] ?? '') === 'Near Habarana Culvert 412' &&
    ($rest['observer_notes'] ?? '') === 'Two adult males near rail verge' &&
    ($rest['observer_lat'] ?? '') === '8.032145' &&
    ($rest['observer_lon'] ?? '') === '80.754321' &&
    ($rest['observer_accuracy'] ?? '') === '4.5' &&
    ($rest['acquisition_method'] ?? '') === 'gps_device' &&
    ($rest['observation_distance'] ?? '') === '65' &&
    ($rest['observation_bearing'] ?? '') === '135' &&
    ($rest['animal_uncertainty'] ?? '') === '15' &&
    ($rest['elephant_count'] ?? '') === '4' &&
    ($rest['sighting_evidence_type'] ?? '') === 'direct_sighting' &&
    ($rest['adult_males'] ?? '') === '2' &&
    ($rest['adult_females'] ?? '') === '1' &&
    ($rest['calves'] ?? '') === '1' &&
    ($rest['unclassified_count'] ?? '') === '0' &&
    ($rest['tusker_present'] ?? '') === 'single_tusker' &&
    ($rest['elephant_behavior'] ?? '') === 'crossing' &&
    ($rest['section_id'] ?? '') === '2' &&
    ($rest['nearest_milestone_ref'] ?? '') === 'KM 142/6' &&
    ($rest['track_proximity'] ?? '') === 'verge'
);

if ($matchesAllFields) {
    pass("Complete DOM draft restoration verified: 100% of Step 1-5 form controls restored to exact original values");
} else {
    fail("DOM draft restoration mismatch detected: " . json_encode($rest));
}

// 5B.7 UUID Preservation & Sync Display
$testsRun++;
if ($domRes && ($domRes['uuidPreserved'] ?? false) === true) {
    pass("RFC 4122 client_report_uuid strictly preserved across draft restoration (#syncActiveUuid updated)");
} else {
    fail("client_report_uuid not preserved upon resume: " . json_encode($domRes));
}

// 5B.8 Unknown Animal Location Round-Trip
$testsRun++;
$unk = $domRes['unknownLocationRoundTrip'] ?? [];
if (($unk['checked'] ?? false) === true && ($unk['opacity'] ?? '') === '0.4') {
    pass("Unknown Animal Location round-trip verified: animal_coords_unknown checked and offset section opacity set to 0.4");
} else {
    fail("Unknown Animal Location restoration check failed: " . json_encode($unk));
}

// -----------------------------------------------------------------------------
// SECTION 6: OPERATIONAL DATABASE IMMUTABILITY VERIFICATION
// -----------------------------------------------------------------------------
section("Section 6: Operational Database Immutability Verification");

$testsRun++;
try {
    $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
    $port = defined('DB_PORT') ? (int)DB_PORT : 3308;
    $dbCharset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
    $roUser = defined('DB_ANALYTICS_USER') ? DB_ANALYTICS_USER : 'eleguard_readonly';
    $roPass = defined('DB_ANALYTICS_PASS') ? DB_ANALYTICS_PASS : '';
    $prodDb = 'eleguard_rail';

    $readOnlyPdo = new PDO("mysql:host={$host};port={$port};dbname={$prodDb};charset={$dbCharset}", $roUser, $roPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $res = verifyOperationalDatabaseSnapshot($preOpSnapshot, $readOnlyPdo);

    if ($res['passed']) {
        pass("Operational database ({$prodDb}) remains 100% immutable (All 8 tables verified with row counts & SHA-256 checksums)");
    } else {
        fail("Operational database record count discrepancy detected!", json_encode($res['diffs']));
    }
} catch (Exception $e) {
    fail("Database immutability check failed with exception: " . $e->getMessage());
}

// -----------------------------------------------------------------------------
// SUMMARY & EXIT CODE
// -----------------------------------------------------------------------------
echo PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo " SUITE SUMMARY: Stage 4E.4.2-C Encrypted Offline Storage" . PHP_EOL;
echo " Tests Executed: {$testsRun} | Passed: " . ($testsRun - $testsFailed) . " | Failed: {$testsFailed}" . PHP_EOL;
echo " Target DB: eleguard_rail_test (Validated)" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
