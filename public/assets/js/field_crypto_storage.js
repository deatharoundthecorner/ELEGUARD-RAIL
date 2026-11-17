/**
 * EleGuard Rail — Client-Side Encrypted Offline Draft Storage Engine
 * Milestone 9C Stage 4E.4.2-C: Encrypted Offline Storage
 *
 * Implements:
 * 1. AES-GCM-256 encryption via native Web Crypto API (SubtleCrypto).
 * 2. 96-bit cryptographically secure unique IV generated per write operation (IV reuse banned).
 * 3. PBKDF2-HMAC-SHA256 key derivation from user-entered passphrase (250,000 iterations).
 * 4. Additional Authenticated Data (AAD) cryptographically binding UUID, schema version, and creation timestamp.
 * 5. IndexedDB storage architecture ('eleguard_field_db' -> 'metadata' and 'encrypted_drafts').
 * 6. Strict passive 72-hour expiry and purge policy, including clock rollback detection.
 * 7. Key verifier mechanism ensuring zero plaintext passphrase or credential storage.
 * 8. Comprehensive resilience against storage quota, corruption, and offline state.
 */

(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.EleGuardStorage = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // Storage Constants
    var DB_NAME = 'eleguard_field_db';
    var DB_VERSION = 1;
    var STORE_METADATA = 'metadata';
    var STORE_DRAFTS = 'encrypted_drafts';
    var PBKDF2_ITERATIONS = 250000;
    var SALT_BYTES = 16;
    var IV_BYTES = 12; // 96 bits for AES-GCM
    var EXPIRY_HOURS = 72;
    var EXPIRY_MS = EXPIRY_HOURS * 60 * 60 * 1000; // 259,200,000 ms
    var VERIFIER_TOKEN = 'ELEGUARD_FIELD_STORAGE_V1_VERIFIED';
    var SCHEMA_VERSION = 1;

    // Volatile Memory State (Evicted on tab close or manual lock)
    var activeCryptoKey = null;
    var isUnlocked = false;

    /* =========================================================================
     * 1. BINARY & STRING UTILITIES
     * ========================================================================= */
    function strToUtf8Bytes(str) {
        if (typeof TextEncoder !== 'undefined') {
            return new TextEncoder().encode(str);
        }
        var utf8 = [];
        for (var i = 0; i < str.length; i++) {
            var charcode = str.charCodeAt(i);
            if (charcode < 0x80) utf8.push(charcode);
            else if (charcode < 0x800) {
                utf8.push(0xc0 | (charcode >> 6), 0x80 | (charcode & 0x3f));
            } else if (charcode < 0xd800 || charcode >= 0xe000) {
                utf8.push(0xe0 | (charcode >> 12), 0x80 | ((charcode >> 6) & 0x3f), 0x80 | (charcode & 0x3f));
            } else {
                i++;
                charcode = 0x10000 + (((charcode & 0x3ff) << 10) | (str.charCodeAt(i) & 0x3ff));
                utf8.push(0xf0 | (charcode >> 18), 0x80 | ((charcode >> 12) & 0x3f), 0x80 | ((charcode >> 6) & 0x3f), 0x80 | (charcode & 0x3f));
            }
        }
        return new Uint8Array(utf8);
    }

    function utf8BytesToStr(bytes) {
        if (typeof TextDecoder !== 'undefined') {
            return new TextDecoder().decode(bytes);
        }
        var out = '';
        var i = 0;
        while (i < bytes.length) {
            var c = bytes[i++];
            if (c > 127) {
                if (c > 191 && c < 224) {
                    c = ((c & 31) << 6) | (bytes[i++] & 63);
                } else if (c > 223 && c < 240) {
                    c = ((c & 15) << 12) | ((bytes[i++] & 63) << 6) | (bytes[i++] & 63);
                } else if (c > 239 && c < 248) {
                    c = ((c & 7) << 18) | ((bytes[i++] & 63) << 12) | ((bytes[i++] & 63) << 6) | (bytes[i++] & 63);
                }
            }
            out += String.fromCharCode(c);
        }
        return out;
    }

    function getCrypto() {
        if (typeof window !== 'undefined' && window.crypto && window.crypto.subtle) {
            return window.crypto;
        }
        if (typeof self !== 'undefined' && self.crypto && self.crypto.subtle) {
            return self.crypto;
        }
        if (typeof globalThis !== 'undefined' && globalThis.crypto && globalThis.crypto.subtle) {
            return globalThis.crypto;
        }
        return null;
    }

    function getRandomBytes(len) {
        var cryptoObj = getCrypto();
        if (!cryptoObj || !cryptoObj.getRandomValues) {
            throw new Error('Web Crypto API (crypto.getRandomValues) is not available.');
        }
        var buf = new Uint8Array(len);
        cryptoObj.getRandomValues(buf);
        return buf;
    }

    /* =========================================================================
     * 2. INDEXEDDB CONNECTION & SCHEMA DEFINITIONS
     * ========================================================================= */
    function openDatabase() {
        return new Promise(function (resolve, reject) {
            if (typeof indexedDB === 'undefined') {
                return reject(new Error('IndexedDB is not supported or is disabled in this browser.'));
            }

            var request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = function (event) {
                var db = event.target.result;
                // Store 1: Metadata & Security Configuration
                if (!db.objectStoreNames.contains(STORE_METADATA)) {
                    db.createObjectStore(STORE_METADATA, { keyPath: 'id' });
                }
                // Store 2: Encrypted Observation Drafts (Keyed by client_report_uuid)
                if (!db.objectStoreNames.contains(STORE_DRAFTS)) {
                    var draftStore = db.createObjectStore(STORE_DRAFTS, { keyPath: 'client_report_uuid' });
                    draftStore.createIndex('created_at_utc', 'created_at_utc', { unique: false });
                }
            };

            request.onsuccess = function (event) {
                resolve(event.target.result);
            };

            request.onerror = function (event) {
                reject(new Error('Failed to open IndexedDB: ' + (event.target.error ? event.target.error.message : 'Unknown error')));
            };

            request.onblocked = function () {
                reject(new Error('IndexedDB open blocked by another active connection.'));
            };
        });
    }

    /* =========================================================================
     * 3. KEY DERIVATION & VERIFICATION (PBKDF2-HMAC-SHA256)
     * ========================================================================= */
    function deriveAesKey(passphrase, saltBytes) {
        var cryptoObj = getCrypto();
        if (!cryptoObj) {
            return Promise.reject(new Error('Web Crypto API is not supported in this environment.'));
        }

        var subtle = cryptoObj.subtle;
        var passBytes = strToUtf8Bytes(passphrase);

        return subtle.importKey(
            'raw',
            passBytes,
            { name: 'PBKDF2' },
            false,
            ['deriveKey']
        ).then(function (baseKey) {
            return subtle.deriveKey(
                {
                    name: 'PBKDF2',
                    salt: saltBytes,
                    iterations: PBKDF2_ITERATIONS,
                    hash: 'SHA-256'
                },
                baseKey,
                { name: 'AES-GCM', length: 256 },
                false, // Non-extractable for memory protection
                ['encrypt', 'decrypt']
            );
        });
    }

    /* =========================================================================
     * 4. AES-GCM-256 ENCRYPTION & DECRYPTION (With Additional Authenticated Data)
     * ========================================================================= */
    function buildAadBytes(uuid, schemaVersion, createdAtUtc) {
        var aadObj = {
            client_report_uuid: uuid,
            schema_version: schemaVersion,
            created_at_utc: createdAtUtc
        };
        return strToUtf8Bytes(JSON.stringify(aadObj));
    }

    function encryptPayload(cryptoKey, plaintextString, uuid, schemaVersion, createdAtUtc) {
        var cryptoObj = getCrypto();
        var subtle = cryptoObj.subtle;
        var iv = getRandomBytes(IV_BYTES);
        var aad = buildAadBytes(uuid, schemaVersion, createdAtUtc);
        var dataBytes = strToUtf8Bytes(plaintextString);

        return subtle.encrypt(
            {
                name: 'AES-GCM',
                iv: iv,
                additionalData: aad,
                tagLength: 128
            },
            cryptoKey,
            dataBytes
        ).then(function (ciphertextBuffer) {
            return {
                iv: iv,
                ciphertext: ciphertextBuffer
            };
        });
    }

    function decryptPayload(cryptoKey, ciphertextBuffer, ivBytes, uuid, schemaVersion, createdAtUtc) {
        var cryptoObj = getCrypto();
        var subtle = cryptoObj.subtle;
        var aad = buildAadBytes(uuid, schemaVersion, createdAtUtc);

        return subtle.decrypt(
            {
                name: 'AES-GCM',
                iv: ivBytes,
                additionalData: aad,
                tagLength: 128
            },
            cryptoKey,
            ciphertextBuffer
        ).then(function (plaintextBuffer) {
            return utf8BytesToStr(new Uint8Array(plaintextBuffer));
        });
    }

    /* =========================================================================
     * 5. 72-HOUR EXPIRY EVALUATION (Passive Enforcement)
     * ========================================================================= */
    function evaluateDraftExpiry(createdAtUtc) {
        var createdTime = new Date(createdAtUtc).getTime();
        var now = Date.now();

        // Check 1: Normal 72-hour wall clock expiry
        if (now - createdTime > EXPIRY_MS) {
            return {
                expired: true,
                reason: 'exceeded_72h',
                age_ms: now - createdTime
            };
        }

        // Check 2: Conservative Clock Rollback Detection
        // If current device time is behind draft creation time by > 60 seconds, flag as clock anomaly
        if (now < createdTime - 60000) {
            return {
                expired: true,
                reason: 'clock_rollback_detected',
                anomaly_ms: createdTime - now
            };
        }

        var remainingMs = EXPIRY_MS - (now - createdTime);
        var remainingHours = Math.max(0, Math.floor(remainingMs / (60 * 60 * 1000)));
        var remainingMinutes = Math.max(0, Math.floor((remainingMs % (60 * 60 * 1000)) / (60 * 1000)));

        return {
            expired: false,
            remaining_ms: remainingMs,
            remaining_human: remainingHours + 'h ' + remainingMinutes + 'm remaining'
        };
    }

    /* =========================================================================
     * 6. PUBLIC API IMPLEMENTATION
     * ========================================================================= */
    var EleGuardStorage = {

        /**
         * Checks if the storage system is initialized (i.e. security salt & verifier set up).
         * @returns {Promise<boolean>}
         */
        isInitialized: function () {
            return openDatabase().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx = db.transaction(STORE_METADATA, 'readonly');
                    var store = tx.objectStore(STORE_METADATA);
                    var req = store.get('security_config');

                    req.onsuccess = function () {
                        resolve(!!req.result);
                    };
                    req.onerror = function () {
                        reject(new Error('Failed to query storage metadata.'));
                    };
                });
            });
        },

        /**
         * Returns whether the storage is currently unlocked in volatile memory.
         * @returns {boolean}
         */
        isUnlocked: function () {
            return isUnlocked && activeCryptoKey !== null;
        },

        /**
         * First-time initialization of storage with user passphrase.
         * @param {string} passphrase 
         * @returns {Promise<{success: boolean}>}
         */
        setupPassphrase: function (passphrase) {
            if (!passphrase || passphrase.length < 6) {
                return Promise.reject(new Error('Passphrase must be at least 6 characters long.'));
            }

            var salt = getRandomBytes(SALT_BYTES);

            return deriveAesKey(passphrase, salt).then(function (derivedKey) {
                // Encrypt verifier token to validate future passphrase entries without storing the password
                var verifierIv = getRandomBytes(IV_BYTES);
                var tokenBytes = strToUtf8Bytes(VERIFIER_TOKEN);
                var cryptoObj = getCrypto();

                return cryptoObj.subtle.encrypt(
                    {
                        name: 'AES-GCM',
                        iv: verifierIv,
                        tagLength: 128
                    },
                    derivedKey,
                    tokenBytes
                ).then(function (ciphertext) {
                    var configRecord = {
                        id: 'security_config',
                        salt: salt,
                        key_verifier: {
                            iv: verifierIv,
                            ciphertext: ciphertext
                        },
                        created_at: new Date().toISOString(),
                        schema_version: SCHEMA_VERSION
                    };

                    return openDatabase().then(function (db) {
                        return new Promise(function (resolve, reject) {
                            var tx = db.transaction(STORE_METADATA, 'readwrite');
                            var store = tx.objectStore(STORE_METADATA);
                            var putReq = store.put(configRecord);

                            putReq.onsuccess = function () {
                                activeCryptoKey = derivedKey;
                                isUnlocked = true;
                                resolve({ success: true, message: 'Encrypted storage initialized and unlocked.' });
                            };
                            putReq.onerror = function (e) {
                                reject(new Error('Failed to save security configuration: ' + e.target.error.message));
                            };
                        });
                    });
                });
            });
        },

        /**
         * Unlocks encrypted storage using the user's passphrase.
         * Verifies authentication tag against key_verifier without revealing password.
         * @param {string} passphrase 
         * @returns {Promise<{success: boolean, draft_count: number}>}
         */
        unlock: function (passphrase) {
            if (!passphrase) {
                return Promise.reject(new Error('Passphrase cannot be empty.'));
            }

            return openDatabase().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx = db.transaction(STORE_METADATA, 'readonly');
                    var store = tx.objectStore(STORE_METADATA);
                    var req = store.get('security_config');

                    req.onsuccess = function () {
                        if (!req.result) {
                            return reject(new Error('Storage not initialized. Please set up a passphrase first.'));
                        }
                        resolve(req.result);
                    };
                    req.onerror = function () {
                        reject(new Error('Failed to read security configuration.'));
                    };
                });
            }).then(function (config) {
                return deriveAesKey(passphrase, config.salt).then(function (candidateKey) {
                    var cryptoObj = getCrypto();
                    var verifier = config.key_verifier;

                    return cryptoObj.subtle.decrypt(
                        {
                            name: 'AES-GCM',
                            iv: verifier.iv,
                            tagLength: 128
                        },
                        candidateKey,
                        verifier.ciphertext
                    ).then(function (decryptedBytes) {
                        var token = utf8BytesToStr(new Uint8Array(decryptedBytes));
                        if (token !== VERIFIER_TOKEN) {
                            throw new Error('Key validation mismatch.');
                        }

                        // Success -> Set active key in memory
                        activeCryptoKey = candidateKey;
                        isUnlocked = true;

                        return EleGuardStorage.getDraftCount().then(function (count) {
                            return { success: true, draft_count: count };
                        });
                    }).catch(function (err) {
                        isUnlocked = false;
                        activeCryptoKey = null;
                        throw new Error('Incorrect passphrase or corrupted authentication tag.');
                    });
                });
            });
        },

        /**
         * Locks storage by purging the CryptoKey from volatile memory.
         */
        lock: function () {
            activeCryptoKey = null;
            isUnlocked = false;
            return { locked: true };
        },

        /**
         * Encrypts and saves an observation draft.
         * Binds record UUID and timestamps via Additional Authenticated Data (AAD).
         * @param {Object} reportState Full observation state
         * @returns {Promise<{success: boolean, client_report_uuid: string}>}
         */
        saveDraft: function (reportState) {
            if (!this.isUnlocked()) {
                return Promise.reject(new Error('Storage is locked. Please unlock storage before saving drafts.'));
            }

            var uuid = reportState.client_report_uuid;
            if (!uuid) {
                return Promise.reject(new Error('Report state missing client_report_uuid.'));
            }

            var nowIso = new Date().toISOString();
            var payloadString = JSON.stringify(reportState);

            return openDatabase().then(function (db) {
                // Check if draft already exists to preserve original created_at_utc
                return new Promise(function (resolve, reject) {
                    var tx = db.transaction(STORE_DRAFTS, 'readonly');
                    var store = tx.objectStore(STORE_DRAFTS);
                    var getReq = store.get(uuid);

                    getReq.onsuccess = function () {
                        var existing = getReq.result;
                        var createdAt = existing ? existing.created_at_utc : nowIso;
                        resolve(createdAt);
                    };
                    getReq.onerror = function () {
                        resolve(nowIso);
                    };
                }).then(function (createdAtUtc) {
                    // Check expiry on update
                    var expiryCheck = evaluateDraftExpiry(createdAtUtc);
                    if (expiryCheck.expired) {
                        throw new Error('Cannot update draft: Record has exceeded the 72-hour retention policy.');
                    }

                    // Encrypt with fresh random 96-bit IV
                    return encryptPayload(activeCryptoKey, payloadString, uuid, SCHEMA_VERSION, createdAtUtc)
                        .then(function (encResult) {
                            // Non-spatial metadata summary for identification in list without early decryption
                            // PRECISE COORDINATES, LANDMARKS, AND REMARKS ARE NEVER STORED HERE!
                            var summary = {
                                category: reportState.observation_category || 'live_sighting',
                                elephant_count: reportState.demographics ? reportState.demographics.elephant_count : 1,
                                has_tusker: reportState.tusker_present && reportState.tusker_present !== 'no',
                                urgency: reportState.urgency_level || 'Medium'
                            };

                            var record = {
                                client_report_uuid: uuid,
                                created_at_utc: createdAtUtc,
                                updated_at_utc: nowIso,
                                schema_version: SCHEMA_VERSION,
                                iv: encResult.iv,
                                ciphertext: encResult.ciphertext,
                                summary: summary
                            };

                            return new Promise(function (resolvePut, rejectPut) {
                                var putTx = db.transaction(STORE_DRAFTS, 'readwrite');
                                var draftStore = putTx.objectStore(STORE_DRAFTS);
                                var putReq = draftStore.put(record);

                                putReq.onsuccess = function () {
                                    resolvePut({
                                        success: true,
                                        client_report_uuid: uuid,
                                        uuid: uuid,
                                        created_at: createdAtUtc,
                                        updated_at: nowIso
                                    });
                                };

                                putReq.onerror = function (e) {
                                    if (e.target.error && e.target.error.name === 'QuotaExceededError') {
                                        rejectPut(new Error('Storage quota exceeded. Please delete old drafts.'));
                                    } else {
                                        rejectPut(new Error('Failed to save draft to IndexedDB: ' + e.target.error.message));
                                    }
                                };
                            });
                        });
                });
            });
        },

        /**
         * Lists all active non-expired drafts.
         * Enforces passive 72-hour expiry: Automatically deletes expired drafts during scan.
         * @returns {Promise<Array<Object>>}
         */
        listDrafts: function () {
            if (!this.isUnlocked()) {
                return Promise.reject(new Error('Storage is locked. Please unlock storage before accessing drafts.'));
            }

            return openDatabase().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx = db.transaction(STORE_DRAFTS, 'readwrite');
                    var store = tx.objectStore(STORE_DRAFTS);
                    var cursorReq = store.openCursor();
                    var activeDrafts = [];
                    var expiredUuids = [];

                    cursorReq.onsuccess = function (e) {
                        var cursor = e.target.result;
                        if (cursor) {
                            var record = cursor.value;
                            var expiry = evaluateDraftExpiry(record.created_at_utc);

                            if (expiry.expired) {
                                // Passive purge: Delete expired draft
                                expiredUuids.push(record.client_report_uuid);
                                cursor.delete();
                            } else {
                                activeDrafts.push({
                                    client_report_uuid: record.client_report_uuid,
                                    created_at_utc: record.created_at_utc,
                                    updated_at_utc: record.updated_at_utc,
                                    remaining_human: expiry.remaining_human,
                                    remaining_ms: expiry.remaining_ms,
                                    summary: record.summary
                                });
                            }
                            cursor.continue();
                        } else {
                            if (expiredUuids.length > 0) {
                                console.info('[EleGuard Storage] Purged ' + expiredUuids.length + ' draft(s) exceeding 72h retention.');
                            }
                            // Sort newest first
                            activeDrafts.sort(function (a, b) {
                                return new Date(b.created_at_utc) - new Date(a.created_at_utc);
                            });
                            resolve(activeDrafts);
                        }
                    };

                    cursorReq.onerror = function (e) {
                        reject(new Error('Failed to scan drafts: ' + e.target.error.message));
                    };
                });
            });
        },

        /**
         * Recovers and decrypts a specific draft by UUID.
         * Strictly checks 72-hour expiry and verifies authentication tag.
         * @param {string} uuid 
         * @returns {Promise<Object>} The recovered reportState
         */
        getDraft: function (uuid) {
            if (!this.isUnlocked()) {
                return Promise.reject(new Error('Storage is locked. Please unlock storage before reading drafts.'));
            }

            return openDatabase().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx = db.transaction(STORE_DRAFTS, 'readwrite');
                    var store = tx.objectStore(STORE_DRAFTS);
                    var req = store.get(uuid);

                    req.onsuccess = function () {
                        var record = req.result;
                        if (!record) {
                            return reject(new Error('Draft not found: ' + uuid));
                        }

                        // Passive 72-hour Expiry Check
                        var expiry = evaluateDraftExpiry(record.created_at_utc);
                        if (expiry.expired) {
                            // Purge expired record
                            store.delete(uuid);
                            return reject(new Error('Draft has expired (exceeded 72-hour retention policy) and was purged.'));
                        }

                        // Decrypt and authenticate
                        decryptPayload(
                            activeCryptoKey,
                            record.ciphertext,
                            record.iv,
                            record.client_report_uuid,
                            record.schema_version,
                            record.created_at_utc
                        ).then(function (decryptedJson) {
                            try {
                                var state = JSON.parse(decryptedJson);
                                resolve(state);
                            } catch (parseErr) {
                                reject(new Error('Draft corrupted: Invalid JSON payload.'));
                            }
                        }).catch(function (cryptoErr) {
                            reject(new Error('Draft decryption failed: Authentication tag mismatch or corrupted ciphertext.'));
                        });
                    };

                    req.onerror = function (e) {
                        reject(new Error('Failed to retrieve draft: ' + e.target.error.message));
                    };
                });
            });
        },

        /**
         * Permanently deletes a single draft.
         * @param {string} uuid 
         * @returns {Promise<{success: boolean}>}
         */
        deleteDraft: function (uuid) {
            return openDatabase().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx = db.transaction(STORE_DRAFTS, 'readwrite');
                    var store = tx.objectStore(STORE_DRAFTS);
                    var req = store.delete(uuid);

                    req.onsuccess = function () {
                        resolve({ success: true, client_report_uuid: uuid });
                    };
                    req.onerror = function (e) {
                        reject(new Error('Failed to delete draft: ' + e.target.error.message));
                    };
                });
            });
        },

        /**
         * Permanently deletes multiple drafts in a single transaction.
         * @param {string[]} uuids
         * @returns {Promise<{success: boolean, deleted_count: number}>}
         */
        deleteDrafts: function (uuids) {
            if (!Array.isArray(uuids) || uuids.length === 0) {
                return Promise.resolve({ success: true, deleted_count: 0 });
            }
            return openDatabase().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx = db.transaction(STORE_DRAFTS, 'readwrite');
                    var store = tx.objectStore(STORE_DRAFTS);
                    var count = 0;
                    for (var i = 0; i < uuids.length; i++) {
                        store.delete(uuids[i]);
                        count++;
                    }
                    tx.oncomplete = function () {
                        resolve({ success: true, deleted_count: count });
                    };
                    tx.onerror = function (e) {
                        reject(new Error('Failed to delete drafts batch: ' + (e.target ? e.target.error.message : 'Transaction error')));
                    };
                });
            });
        },

        /**
         * Retrieves and decrypts a batch of drafts by UUID.
         * @param {string[]} uuids
         * @returns {Promise<Array<Object>>}
         */
        getDraftBatch: function (uuids) {
            var self = this;
            if (!this.isUnlocked()) {
                return Promise.reject(new Error('Storage is locked. Please unlock storage before reading drafts.'));
            }
            if (!Array.isArray(uuids) || uuids.length === 0) {
                return Promise.resolve([]);
            }
            var promises = uuids.map(function (u) {
                return self.getDraft(u);
            });
            return Promise.all(promises);
        },

        /**
         * Clears all local drafts. Requires unlocked state.
         * @returns {Promise<{success: boolean, cleared_count: number}>}
         */
        clearAllDrafts: function () {
            if (!this.isUnlocked()) {
                return Promise.reject(new Error('Storage is locked. Please unlock storage before clearing drafts.'));
            }

            return openDatabase().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx = db.transaction(STORE_DRAFTS, 'readwrite');
                    var store = tx.objectStore(STORE_DRAFTS);
                    var req = store.clear();

                    req.onsuccess = function () {
                        resolve({ success: true, message: 'All local drafts cleared.' });
                    };
                    req.onerror = function (e) {
                        reject(new Error('Failed to clear drafts: ' + e.target.error.message));
                    };
                });
            });
        },

        /**
         * Count total drafts in store without decrypting.
         * @returns {Promise<number>}
         */
        getDraftCount: function () {
            return openDatabase().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx = db.transaction(STORE_DRAFTS, 'readonly');
                    var store = tx.objectStore(STORE_DRAFTS);
                    var req = store.count();

                    req.onsuccess = function () {
                        resolve(req.result || 0);
                    };
                    req.onerror = function () {
                        resolve(0);
                    };
                });
            }).catch(function () {
                return 0;
            });
        },

        /**
         * Complete storage reset (Purges drafts and security configuration).
         * Used when passphrase is forgotten or when clearing all data.
         * @returns {Promise<{reset: boolean}>}
         */
        resetStorage: function () {
            this.lock();
            return new Promise(function (resolve, reject) {
                if (typeof indexedDB === 'undefined') {
                    return resolve({ reset: true });
                }
                var req = indexedDB.deleteDatabase(DB_NAME);
                req.onsuccess = function () {
                    resolve({ reset: true, message: 'Database deleted successfully.' });
                };
                req.onerror = function (e) {
                    reject(new Error('Failed to delete database: ' + e.target.error.message));
                };
                req.onblocked = function () {
                    resolve({ reset: true, message: 'Database delete requested (blocked by other connections).' });
                };
            });
        },

        // Test Helpers / Diagnostic Methods
        _test: {
            deriveAesKey: deriveAesKey,
            encryptPayload: encryptPayload,
            decryptPayload: decryptPayload,
            evaluateDraftExpiry: evaluateDraftExpiry,
            buildAadBytes: buildAadBytes,
            getConstants: function () {
                return {
                    DB_NAME: DB_NAME,
                    STORE_METADATA: STORE_METADATA,
                    STORE_DRAFTS: STORE_DRAFTS,
                    PBKDF2_ITERATIONS: PBKDF2_ITERATIONS,
                    EXPIRY_HOURS: EXPIRY_HOURS,
                    EXPIRY_MS: EXPIRY_MS,
                    SCHEMA_VERSION: SCHEMA_VERSION
                };
            }
        }
    };

    return EleGuardStorage;
}));
