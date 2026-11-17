/**
 * EleGuard Rail — Controlled Offline Synchronization Controller
 * Milestone 9C Stage 4E.4.2-D: Controlled Offline Synchronization
 *
 * Implements:
 * 1. User-controlled batch synchronization from encrypted IndexedDB to central server.
 * 2. Cross-tab and same-tab concurrency locking via W3C Web Locks API (with LocalStorage lease fallback).
 * 3. Ephemeral in-memory decryption of micro-batches (configurable default size: 10 reports).
 * 4. Authoritative server idempotency preserving RFC 4122 v4 client_report_uuid across all retries.
 * 5. Defensive HTTP 207 Multi-Status acknowledgement parsing (purge only confirmed synced/replayed).
 * 6. Adaptive HTTP 413 handling with automated batch size halving (10 -> 5 -> 1).
 * 7. Comprehensive HTTP error handling matrix (400, 401, 403, 409, 413, 422, 429, 500, 503).
 * 8. Network uncertainty protection: socket resets and timeouts never delete local drafts.
 * 9. Memory hygiene unlinking ephemeral references post-transmission.
 * 10. Interactive synchronization dashboard UI renderer with itemized status tracking.
 */

(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.EleGuardSyncController = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // Constants
    var DEFAULT_BATCH_SIZE = 10;
    var MAX_BATCH_REPORTS = 50;
    var MAX_BATCH_PAYLOAD_BYTES = 10485760; // 10 MB
    var LOCK_NAME = 'eleguard_sync_mutex';
    var LEASE_STORAGE_KEY = 'eleguard_sync_lease';
    var LEASE_TTL_MS = 60000; // 60 seconds
    var DEFAULT_SYNC_ENDPOINT = '../api/v2/reports/sync-batch';

    // Unique Tab Identifier for Concurrency
    var TAB_ID = 'tab_' + Math.random().toString(36).substring(2, 10) + '_' + Date.now();

    // Internal State
    var state = {
        isSyncInProgress: false,
        batchSize: DEFAULT_BATCH_SIZE,
        lastSyncTime: null,
        syncStats: {
            totalDrafts: 0,
            syncedCount: 0,
            replayedCount: 0,
            rejectedCount: 0,
            uncertainCount: 0,
            pendingCount: 0
        },
        itemStatusMap: {}, // uuid -> { status, serverReportId, error, timestamp }
        activeAbortController: null
    };

    /* =========================================================================
     * 1. CONCURRENCY & CROSS-TAB LOCKING (FAIL-CLOSED)
     * ========================================================================= */

    function showSyncToast(message, type) {
        if (typeof window !== 'undefined' && window.EleGuardStorageUI && typeof window.EleGuardStorageUI.showToast === 'function') {
            window.EleGuardStorageUI.showToast(message, type);
        }
    }

    /**
     * Attempts to acquire the sync lock using Web Locks API or LocalStorage lease.
     * Enforces strict fail-closed behavior: never proceeds without confirmed exclusive lock.
     *
     * @param {Function} taskFn - Async function to execute while holding lock
     * @returns {Promise<{executed: boolean, result: any, reason?: string, message?: string}>}
     */
    function withSyncLock(taskFn) {
        if (state.isSyncInProgress) {
            showSyncToast('⚠️ Synchronization is already in progress in this tab.', 'warning');
            return Promise.resolve({
                executed: false,
                reason: 'same_tab_busy',
                message: 'Synchronization is already running in this tab.'
            });
        }

        // 1. PRIMARY LOCK: W3C Web Locks API
        var nav = typeof navigator !== 'undefined' ? navigator : null;
        if (nav && nav.locks && typeof nav.locks.request === 'function') {
            return nav.locks.request(LOCK_NAME, { ifAvailable: true }, function (lock) {
                if (!lock) {
                    showSyncToast('⚠️ Synchronization is already active in another browser tab.', 'warning');
                    return Promise.resolve({
                        executed: false,
                        reason: 'cross_tab_locked',
                        message: 'Synchronization is already active in another tab.'
                    });
                }
                state.isSyncInProgress = true;
                return Promise.resolve()
                    .then(taskFn)
                    .then(function (res) {
                        state.isSyncInProgress = false;
                        return { executed: true, result: res };
                    })
                    .catch(function (err) {
                        state.isSyncInProgress = false;
                        throw err;
                    });
            });
        }

        // 2. FALLBACK LOCK: Cooperative LocalStorage Lease with Race Verification
        // Note: A localStorage lease is not an atomic OS-level primitive.
        // It provides cooperative leasing with read-after-write verification and expiring TTL.
        // If localStorage is unavailable, disabled, or throws, we MUST FAIL CLOSED!
        return new Promise(function (resolve, reject) {
            // Capability pre-check: verify localStorage accessibility and writability
            var storageAvailable = false;
            try {
                if (typeof localStorage !== 'undefined' && localStorage !== null) {
                    var testKey = '__eleguard_lock_probe__';
                    localStorage.setItem(testKey, '1');
                    localStorage.removeItem(testKey);
                    storageAvailable = true;
                }
            } catch (testEx) {
                storageAvailable = false;
            }

            if (!storageAvailable) {
                // STRICT FAIL-CLOSED: Browser locking is unavailable
                var lockErrMsg = 'Synchronization is temporarily unavailable because browser locking is unavailable. All drafts remain securely preserved.';
                console.warn('[EleGuard Sync] FAIL-CLOSED: Web Locks API and localStorage are both unavailable.');
                showSyncToast('⚠️ ' + lockErrMsg, 'error');
                return resolve({
                    executed: false,
                    reason: 'storage_unavailable',
                    message: lockErrMsg
                });
            }

            var now = Date.now();
            try {
                var rawLease = localStorage.getItem(LEASE_STORAGE_KEY);
                if (rawLease) {
                    try {
                        var lease = JSON.parse(rawLease);
                        if (lease && lease.expiresAt && lease.expiresAt > now && lease.tabId !== TAB_ID) {
                            showSyncToast('⚠️ Synchronization is already active in another browser tab.', 'warning');
                            return resolve({
                                executed: false,
                                reason: 'cross_tab_locked',
                                message: 'Active synchronization lease held by another tab.'
                            });
                        }
                    } catch (parseErr) {
                        // Corrupted lease -> treat as stale/recoverable
                    }
                }

                // Write lease
                var newLease = {
                    tabId: TAB_ID,
                    acquiredAt: now,
                    expiresAt: now + LEASE_TTL_MS
                };
                localStorage.setItem(LEASE_STORAGE_KEY, JSON.stringify(newLease));

                // READ-AFTER-WRITE RACE VERIFICATION:
                // Detect whether another tab wrote concurrently between our read and write
                var verifiedRaw = localStorage.getItem(LEASE_STORAGE_KEY);
                if (!verifiedRaw) {
                    throw new Error('Lease write verification failed: Empty lease');
                }
                var verifiedLease = JSON.parse(verifiedRaw);
                if (verifiedLease.tabId !== TAB_ID) {
                    console.warn('[EleGuard Sync] Cross-tab write race detected. Yielding lock.');
                    showSyncToast('⚠️ Synchronization collision detected. Retrying lock in another tab.', 'warning');
                    return resolve({
                        executed: false,
                        reason: 'cross_tab_locked',
                        message: 'Cross-tab lease collision detected during write verification.'
                    });
                }
            } catch (leaseErr) {
                // STRICT FAIL-CLOSED: Any error during lease acquisition terminates execution safely
                console.warn('[EleGuard Sync] FAIL-CLOSED on lease exception:', leaseErr.message);
                var exErrMsg = 'Synchronization is temporarily unavailable because browser locking failed (' + leaseErr.message + '). All drafts remain securely preserved.';
                showSyncToast('⚠️ ' + exErrMsg, 'error');
                return resolve({
                    executed: false,
                    reason: 'storage_unavailable',
                    message: exErrMsg
                });
            }

            // Confirmed lock acquired: Execute task
            state.isSyncInProgress = true;
            Promise.resolve()
                .then(taskFn)
                .then(function (res) {
                    state.isSyncInProgress = false;
                    releaseLocalStorageLease();
                    resolve({ executed: true, result: res });
                })
                .catch(function (err) {
                    state.isSyncInProgress = false;
                    releaseLocalStorageLease();
                    reject(err);
                });
        });
    }

    function releaseLocalStorageLease() {
        try {
            if (typeof localStorage !== 'undefined' && localStorage !== null) {
                var rawLease = localStorage.getItem(LEASE_STORAGE_KEY);
                if (rawLease) {
                    var lease = JSON.parse(rawLease);
                    if (lease && lease.tabId === TAB_ID) {
                        localStorage.removeItem(LEASE_STORAGE_KEY);
                    }
                }
            }
        } catch (e) {
            // Ignore errors during lease release
        }
    }

    /* =========================================================================
     * 2. PAYLOAD ADAPTATION & COMPOSITION
     * ========================================================================= */

    function getCsrfToken() {
        if (typeof window !== 'undefined' && window.ELEGUARD_CSRF_TOKEN) {
            return window.ELEGUARD_CSRF_TOKEN;
        }
        var meta = typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]') : null;
        return meta ? meta.getAttribute('content') : '';
    }

    function getSyncEndpoint() {
        if (typeof window !== 'undefined' && window.ELEGUARD_API_SYNC_BATCH) {
            return window.ELEGUARD_API_SYNC_BATCH;
        }
        return DEFAULT_SYNC_ENDPOINT;
    }

    /**
     * Fallback composed notes builder if window.EleGuardWizard is absent.
     */
    function fallbackBuildComposedNotes(draft) {
        var baseNotes = (draft.observer_notes || '').trim();
        var tags = [];

        if (draft.demographics && draft.demographics.unclassified !== null && draft.demographics.unclassified > 0) {
            var unclassTag = '[Field Demographics: ' + draft.demographics.unclassified + ' unclassified individuals observed in herd]';
            if (baseNotes.indexOf('[Field Demographics:') === -1) {
                tags.push(unclassTag);
            }
        }

        var tusker = draft.tusker_present;
        if (tusker && tusker !== 'no') {
            var tLabels = {
                'confirmed': 'Confirmed Tusker (Single/Double visible tusks)',
                'suspected': 'Suspected Tusker (Partial view/Probable tusks)',
                'tuskless_male': 'Tuskless Adult Male (Makna)',
                'unknown': 'Unknown / Unverified Tusk Status'
            };
            var tuskerText = tLabels[tusker] || tusker;
            var tuskerTag = '[Tusker Sighting: ' + tuskerText + ']';
            if (baseNotes.indexOf('[Tusker Sighting:') === -1) {
                tags.push(tuskerTag);
            }
        }

        var behavior = draft.elephant_behavior;
        if (behavior) {
            var bLabels = {
                'crossing_track': 'Crossing Railway Track Directly',
                'walking_along_ballast': 'Walking Along Ballast / Formation',
                'foraging_rail_reserve': 'Foraging inside Railway Reserve (Within 15m)',
                'stationary_near_track': 'Stationary / Resting Near Track',
                'moving_away': 'Moving Away from Railway Corridor into Forest',
                'aggressive_mock_charge': 'Aggressive / Mock Charging Track Area',
                'calm_herd_movement': 'Calm General Herd Movement'
            };
            var behaviorText = bLabels[behavior] || behavior;
            var behaviorTag = '[Behavior: ' + behaviorText + ']';
            if (baseNotes.indexOf('[Behavior:') === -1) {
                tags.push(behaviorTag);
            }
        }

        var evidence = draft.sighting_evidence_type;
        if (evidence) {
            var eLabels = {
                'direct_visual': 'Direct Visual Sighting (Animals visible)',
                'fresh_dung': 'Fresh Dung / Droppings (Estimated < 2 hours)',
                'footprints_tracks': 'Clear Footprints / Track Marks on Ballast',
                'vocalization_audio': 'Audible Trumpeting / Vocalization / Breaking Branches',
                'thermal_infrared': 'Thermal / Infrared Optic Detection',
                'local_informant': 'Verified Report by Local Farmer / Trackman'
            };
            var evidenceText = eLabels[evidence] || evidence;
            var evidenceTag = '[Evidence: ' + evidenceText + ']';
            if (baseNotes.indexOf('[Evidence:') === -1) {
                tags.push(evidenceTag);
            }
        }

        if (tags.length === 0) return baseNotes;
        if (!baseNotes) return tags.join(' ');
        return baseNotes + '\n\n' + tags.join('\n');
    }

    /**
     * Transforms an encrypted storage draft structure into the backend API v2 schema.
     * Preserves the client_report_uuid strictly.
     * @param {Object} draft
     * @returns {Object}
     */
    function buildReportSyncPayload(draft) {
        var composedNotes = '';
        if (typeof window !== 'undefined' && window.EleGuardWizard && typeof window.EleGuardWizard.buildComposedNotes === 'function') {
            composedNotes = window.EleGuardWizard.buildComposedNotes(draft);
        } else {
            composedNotes = fallbackBuildComposedNotes(draft);
        }

        var payload = {
            client_report_uuid: draft.client_report_uuid, // STRICT PRESERVATION
            submission_source: 'offline_sync',
            observation_category: draft.observation_category || 'live_sighting',
            elephant_count: (draft.demographics && draft.demographics.elephant_count) ? parseInt(draft.demographics.elephant_count, 10) : 1,
            adult_males: (draft.demographics && draft.demographics.adult_males !== null && draft.demographics.adult_males !== undefined) ? parseInt(draft.demographics.adult_males, 10) : null,
            adult_females: (draft.demographics && draft.demographics.adult_females !== null && draft.demographics.adult_females !== undefined) ? parseInt(draft.demographics.adult_females, 10) : null,
            calves: (draft.demographics && draft.demographics.calves !== null && draft.demographics.calves !== undefined) ? parseInt(draft.demographics.calves, 10) : null,
            urgency_level: draft.urgency_level || 'standard',
            location_description: draft.location_description || '',
            description: composedNotes || null,
            nearest_milestone_ref: draft.nearest_milestone_ref || null,
            section_id: draft.section_id ? parseInt(draft.section_id, 10) : null,
            temporal: {
                sighting_date: (draft.temporal && draft.temporal.sighting_date) ? draft.temporal.sighting_date : '',
                sighting_time: (draft.temporal && !draft.temporal.time_unknown && draft.temporal.sighting_time) ? draft.temporal.sighting_time : null,
                sighting_time_precision: (draft.temporal && draft.temporal.time_unknown) ? 'unknown' : ((draft.temporal && draft.temporal.sighting_time) ? 'exact' : 'approximate'),
                diurnal_period: (draft.temporal && !draft.temporal.time_unknown && draft.temporal.diurnal_period) ? draft.temporal.diurnal_period : null
            },
            observer_location: {
                latitude: (draft.observer_location && draft.observer_location.latitude !== null && draft.observer_location.latitude !== undefined) ? parseFloat(draft.observer_location.latitude) : null,
                longitude: (draft.observer_location && draft.observer_location.longitude !== null && draft.observer_location.longitude !== undefined) ? parseFloat(draft.observer_location.longitude) : null,
                gps_accuracy_meters: (draft.observer_location && draft.observer_location.gps_accuracy_meters !== null && draft.observer_location.gps_accuracy_meters !== undefined) ? parseFloat(draft.observer_location.gps_accuracy_meters) : null,
                gps_captured_at: (draft.observer_location && draft.observer_location.gps_captured_at) ? draft.observer_location.gps_captured_at : null,
                acquisition_method: (draft.observer_location && draft.observer_location.acquisition_method) ? draft.observer_location.acquisition_method : 'manual_entry'
            },
            animal_location: (draft.animal_location && draft.animal_location.unknown) ? {
                method: 'observer_standoff_unadjusted',
                observation_distance_meters: null,
                observation_bearing_degrees: null
            } : {
                method: (draft.animal_location && draft.animal_location.observation_distance_meters === 0) ? 'direct_inspection' : 'triangulated_offset',
                observation_distance_meters: (draft.animal_location && draft.animal_location.observation_distance_meters !== null && draft.animal_location.observation_distance_meters !== undefined) ? parseFloat(draft.animal_location.observation_distance_meters) : null,
                observation_bearing_degrees: (draft.animal_location && draft.animal_location.observation_bearing_degrees !== null && draft.animal_location.observation_bearing_degrees !== undefined) ? parseFloat(draft.animal_location.observation_bearing_degrees) : null
            }
        };

        return payload;
    }

    /* =========================================================================
     * 3. HTTP 207 MULTI-STATUS DEFENSIVE PARSER
     * ========================================================================= */

    /**
     * Parses an HTTP 207 Multi-Status response individually.
     * Deletes only confirmed 'synced' or 'replayed' drafts.
     * Retains 'rejected' and uncertain items.
     *
     * @param {Object} responseBody - Parsed JSON response
     * @param {Array<Object>} submittedChunk - The chunk of draft objects submitted
     * @returns {Promise<{syncedUuids: string[], replayedUuids: string[], rejectedUuids: string[], uncertainUuids: string[]}>}
     */
    function processMultiStatusResponse(responseBody, submittedChunk) {
        return new Promise(function (resolve, reject) {
            if (!responseBody || typeof responseBody !== 'object' || !responseBody.data || !Array.isArray(responseBody.data.acknowledgements)) {
                return reject(new Error('Malformed HTTP 207 response: Missing data.acknowledgements array.'));
            }

            var acks = responseBody.data.acknowledgements;
            var submittedMap = {};
            submittedChunk.forEach(function (d) {
                submittedMap[d.client_report_uuid] = d;
            });

            var acknowledgedUuids = {};
            var syncedUuids = [];
            var replayedUuids = [];
            var rejectedUuids = [];
            var uncertainUuids = [];

            for (var i = 0; i < acks.length; i++) {
                var ack = acks[i];
                var uuid = ack.client_report_uuid;

                // Validate UUID exists in submitted chunk
                if (!uuid || !submittedMap[uuid]) {
                    console.warn('[EleGuard Sync] Extraneous UUID in acknowledgement, ignored:', uuid);
                    continue;
                }

                acknowledgedUuids[uuid] = true;

                if (ack.status === 'synced') {
                    syncedUuids.push(uuid);
                    state.itemStatusMap[uuid] = {
                        status: 'synced',
                        serverReportId: ack.server_report_id,
                        error: null,
                        timestamp: ack.created_at || new Date().toISOString()
                    };
                } else if (ack.status === 'replayed') {
                    replayedUuids.push(uuid);
                    state.itemStatusMap[uuid] = {
                        status: 'replayed',
                        serverReportId: ack.server_report_id,
                        error: null,
                        timestamp: ack.created_at || new Date().toISOString()
                    };
                } else if (ack.status === 'rejected') {
                    rejectedUuids.push(uuid);
                    state.itemStatusMap[uuid] = {
                        status: 'rejected',
                        serverReportId: null,
                        error: ack.error || 'Server validation error',
                        timestamp: new Date().toISOString()
                    };
                } else {
                    // Unrecognized status string -> treat as uncertain, retain in DB
                    uncertainUuids.push(uuid);
                    state.itemStatusMap[uuid] = {
                        status: 'uncertain',
                        serverReportId: null,
                        error: 'Unrecognized server status: ' + ack.status,
                        timestamp: new Date().toISOString()
                    };
                }
            }

            // Omitted UUID protection: Any UUID submitted but not acknowledged is uncertain
            submittedChunk.forEach(function (draft) {
                var u = draft.client_report_uuid;
                if (!acknowledgedUuids[u]) {
                    uncertainUuids.push(u);
                    state.itemStatusMap[u] = {
                        status: 'uncertain',
                        serverReportId: null,
                        error: 'Server omitted report from acknowledgements.',
                        timestamp: new Date().toISOString()
                    };
                }
            });

            // Conservative deletion: Purge only 'synced' and 'replayed' from storage
            var purgeList = syncedUuids.concat(replayedUuids);
            var storage = (typeof window !== 'undefined' && window.EleGuardStorage) ? window.EleGuardStorage : null;

            if (purgeList.length > 0 && storage) {
                var deletePromises = purgeList.map(function (uuid) {
                    return storage.deleteDraft(uuid).catch(function (delErr) {
                        console.warn('[EleGuard Sync] Non-fatal draft deletion error for ' + uuid + ':', delErr.message);
                    });
                });

                Promise.all(deletePromises).then(function () {
                    resolve({
                        syncedUuids: syncedUuids,
                        replayedUuids: replayedUuids,
                        rejectedUuids: rejectedUuids,
                        uncertainUuids: uncertainUuids
                    });
                }).catch(function () {
                    resolve({
                        syncedUuids: syncedUuids,
                        replayedUuids: replayedUuids,
                        rejectedUuids: rejectedUuids,
                        uncertainUuids: uncertainUuids
                    });
                });
            } else {
                resolve({
                    syncedUuids: syncedUuids,
                    replayedUuids: replayedUuids,
                    rejectedUuids: rejectedUuids,
                    uncertainUuids: uncertainUuids
                });
            }
        });
    }

    /* =========================================================================
     * 4. BATCH TRANSMISSION & ADAPTIVE CHUNKING ENGINE
     * ========================================================================= */

    /**
     * Transmits a single batch chunk to the server.
     * Implements adaptive halving on HTTP 413.
     *
     * @param {Array<Object>} chunkDrafts - Array of decrypted draft objects
     * @param {Object} options - Sync options
     * @returns {Promise<Object>}
     */
    function transmitChunk(chunkDrafts, options) {
        var endpoint = options.endpoint || getSyncEndpoint();
        var csrfToken = options.csrfToken || getCsrfToken();

        var reportsPayload = chunkDrafts.map(function (draft) {
            return buildReportSyncPayload(draft);
        });

        var requestBody = JSON.stringify({
            csrf_token: csrfToken,
            reports: reportsPayload
        });

        // Set status to syncing in UI map
        chunkDrafts.forEach(function (d) {
            state.itemStatusMap[d.client_report_uuid] = {
                status: 'syncing',
                serverReportId: null,
                error: null,
                timestamp: new Date().toISOString()
            };
        });
        renderDashboard();

        var fetchFn = (typeof options.fetch === 'function') ? options.fetch : ((typeof fetch !== 'undefined') ? fetch : null);
        if (!fetchFn) {
            return Promise.reject(new Error('Fetch API is not available in current environment.'));
        }

        return fetchFn(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: requestBody
        }).then(function (response) {
            var statusCode = response.status;

            // HTTP 207 Multi-Status
            if (statusCode === 207) {
                return response.json().then(function (json) {
                    return processMultiStatusResponse(json, chunkDrafts);
                });
            }

            // HTTP 413 Payload Too Large -> Adaptive Halving
            if (statusCode === 413) {
                if (chunkDrafts.length > 1) {
                    var half = Math.ceil(chunkDrafts.length / 2);
                    var chunkA = chunkDrafts.slice(0, half);
                    var chunkB = chunkDrafts.slice(half);

                    console.warn('[EleGuard Sync] HTTP 413 Payload Too Large. Adaptively halving batch: ' + chunkDrafts.length + ' -> (' + chunkA.length + ', ' + chunkB.length + ')');

                    return transmitChunk(chunkA, options).then(function (resA) {
                        return transmitChunk(chunkB, options).then(function (resB) {
                            return {
                                syncedUuids: resA.syncedUuids.concat(resB.syncedUuids),
                                replayedUuids: resA.replayedUuids.concat(resB.replayedUuids),
                                rejectedUuids: resA.rejectedUuids.concat(resB.rejectedUuids),
                                uncertainUuids: resA.uncertainUuids.concat(resB.uncertainUuids)
                            };
                        });
                    });
                } else {
                    // Single item is oversized (>10 MB). Mark as rejected, retain locally.
                    var singleDraft = chunkDrafts[0];
                    var uuid = singleDraft.client_report_uuid;
                    state.itemStatusMap[uuid] = {
                        status: 'rejected',
                        serverReportId: null,
                        error: 'Payload Too Large: Single report exceeds 10 MB limit.',
                        timestamp: new Date().toISOString()
                    };
                    return {
                        syncedUuids: [],
                        replayedUuids: [],
                        rejectedUuids: [uuid],
                        uncertainUuids: []
                    };
                }
            }

            // HTTP 429 Too Many Requests or HTTP 503 Service Unavailable -> Backoff
            if (statusCode === 429 || statusCode === 503) {
                var retryAfter = response.headers ? response.headers.get('Retry-After') : null;
                var backoffSeconds = retryAfter ? parseInt(retryAfter, 10) : 5;
                if (isNaN(backoffSeconds) || backoffSeconds <= 0) backoffSeconds = 5;

                return response.text().then(function (bodyText) {
                    var errMsg = (statusCode === 429 ? 'Rate Limited (429)' : 'Service Unavailable (503)') + ': Server requested backoff of ' + backoffSeconds + 's.';
                    chunkDrafts.forEach(function (d) {
                        state.itemStatusMap[d.client_report_uuid] = {
                            status: 'uncertain',
                            serverReportId: null,
                            error: errMsg,
                            timestamp: new Date().toISOString()
                        };
                    });
                    var err = new Error(errMsg);
                    err.httpStatus = statusCode;
                    err.retryAfter = backoffSeconds;
                    throw err;
                });
            }

            // HTTP 401 Unauthorized / HTTP 403 Forbidden
            if (statusCode === 401 || statusCode === 403) {
                return response.json().catch(function () { return {}; }).then(function (errJson) {
                    var reason = (statusCode === 401) ? 'Session Expired (401)' : 'Access Forbidden / Driver Lockout (403)';
                    var detail = errJson.detail || errJson.error || 'Authentication or role permission required.';
                    chunkDrafts.forEach(function (d) {
                        state.itemStatusMap[d.client_report_uuid] = {
                            status: 'uncertain',
                            serverReportId: null,
                            error: reason + ': ' + detail,
                            timestamp: new Date().toISOString()
                        };
                    });
                    var err = new Error(reason + ': ' + detail);
                    err.httpStatus = statusCode;
                    err.isAuthError = true;
                    throw err;
                });
            }

            // HTTP 400 or HTTP 422 Batch Validation Error
            if (statusCode === 400 || statusCode === 422) {
                return response.json().catch(function () { return {}; }).then(function (errJson) {
                    var detail = errJson.detail || errJson.title || 'Batch validation rejected by server.';
                    chunkDrafts.forEach(function (d) {
                        state.itemStatusMap[d.client_report_uuid] = {
                            status: 'rejected',
                            serverReportId: null,
                            error: 'HTTP ' + statusCode + ': ' + detail,
                            timestamp: new Date().toISOString()
                        };
                    });
                    var err = new Error('HTTP ' + statusCode + ': ' + detail);
                    err.httpStatus = statusCode;
                    throw err;
                });
            }

            // HTTP 500 or any other unexpected status
            return response.text().then(function (text) {
                var errMsg = 'Server returned HTTP ' + statusCode + ' unexpected response.';
                chunkDrafts.forEach(function (d) {
                    state.itemStatusMap[d.client_report_uuid] = {
                        status: 'uncertain',
                        serverReportId: null,
                        error: errMsg,
                        timestamp: new Date().toISOString()
                    };
                });
                var err = new Error(errMsg);
                err.httpStatus = statusCode;
                throw err;
            });

        }).catch(function (fetchErr) {
            // Memory Hygiene: unbind references
            reportsPayload = null;
            requestBody = null;

            // Retain drafts in uncertain state on network drop or timeout
            chunkDrafts.forEach(function (d) {
                var existing = state.itemStatusMap[d.client_report_uuid];
                if (!existing || existing.status === 'syncing') {
                    state.itemStatusMap[d.client_report_uuid] = {
                        status: 'uncertain',
                        serverReportId: null,
                        error: 'Network connection error / timeout: ' + fetchErr.message,
                        timestamp: new Date().toISOString()
                    };
                }
            });

            throw fetchErr;
        }).finally(function () {
            // Memory hygiene: unlink
            reportsPayload = null;
            requestBody = null;
        });
    }

    /* =========================================================================
     * 5. MASTER SYNCHRONIZATION PIPELINE
     * ========================================================================= */

    /**
     * Executes batch synchronization of all active drafts.
     * Controlled by user action.
     *
     * @param {Object} [options] - Optional overrides (batchSize, endpoint, csrfToken)
     * @returns {Promise<Object>}
     */
    function synchronizeAll(options) {
        options = options || {};
        var storage = (typeof window !== 'undefined' && window.EleGuardStorage) ? window.EleGuardStorage : options.storage;

        if (!storage) {
            return Promise.reject(new Error('Encrypted storage engine is not available.'));
        }

        if (!storage.isUnlocked()) {
            return Promise.reject(new Error('Encrypted storage is locked. Please unlock before synchronizing.'));
        }

        // Online check
        var isOnline = (typeof navigator !== 'undefined' && typeof navigator.onLine === 'boolean') ? navigator.onLine : true;
        if (!isOnline && !options.ignoreOnlineCheck) {
            return Promise.reject(new Error('Offline: No active network connection detected. Cannot synchronize.'));
        }

        return withSyncLock(function () {
            return storage.listDrafts().then(function (summaryList) {
                if (!summaryList || summaryList.length === 0) {
                    updateStats();
                    renderDashboard();
                    return {
                        totalProcessed: 0,
                        syncedCount: 0,
                        replayedCount: 0,
                        rejectedCount: 0,
                        uncertainCount: 0,
                        message: 'No drafts pending synchronization.'
                    };
                }

                var batchSize = options.batchSize || state.batchSize || DEFAULT_BATCH_SIZE;
                if (batchSize > MAX_BATCH_REPORTS) batchSize = MAX_BATCH_REPORTS;
                if (batchSize < 1) batchSize = 1;

                // Divide into chunks
                var chunks = [];
                for (var i = 0; i < summaryList.length; i += batchSize) {
                    chunks.push(summaryList.slice(i, i + batchSize));
                }

                var totalSynced = 0;
                var totalReplayed = 0;
                var totalRejected = 0;
                var totalUncertain = 0;
                var authHaltError = null;

                // Sequentially process each chunk
                return chunks.reduce(function (prevPromise, chunkSummaries, chunkIdx) {
                    return prevPromise.then(function () {
                        if (authHaltError) {
                            return Promise.resolve();
                        }

                        // Update progress bar
                        updateSyncProgress(chunkIdx, chunks.length, 'Decrypting and synchronizing batch ' + (chunkIdx + 1) + ' of ' + chunks.length + '...');

                        // Ephemeral in-memory decryption of chunk items only
                        var uuids = chunkSummaries.map(function (s) { return s.client_report_uuid; });
                        var decryptPromises = uuids.map(function (u) {
                            return storage.getDraft(u);
                        });

                        return Promise.all(decryptPromises).then(function (decryptedDrafts) {
                            return transmitChunk(decryptedDrafts, options).then(function (chunkResult) {
                                totalSynced += chunkResult.syncedUuids.length;
                                totalReplayed += chunkResult.replayedUuids.length;
                                totalRejected += chunkResult.rejectedUuids.length;
                                totalUncertain += chunkResult.uncertainUuids.length;

                                // Ephemeral memory cleanup
                                decryptedDrafts = null;
                            }).catch(function (chunkErr) {
                                decryptedDrafts = null;
                                if (chunkErr.isAuthError) {
                                    authHaltError = chunkErr;
                                }
                                console.warn('[EleGuard Sync] Chunk transmission failed:', chunkErr.message);
                            });
                        });
                    });
                }, Promise.resolve()).then(function () {
                    state.lastSyncTime = new Date().toISOString();
                    updateSyncProgress(chunks.length, chunks.length, 'Synchronization finished.');
                    updateStats();
                    renderDashboard();

                    if (authHaltError) {
                        throw authHaltError;
                    }

                    return {
                        totalProcessed: summaryList.length,
                        syncedCount: totalSynced,
                        replayedCount: totalReplayed,
                        rejectedCount: totalRejected,
                        uncertainCount: totalUncertain,
                        message: 'Synchronization batch complete.'
                    };
                });
            });
        }).then(function (lockResult) {
            if (lockResult && lockResult.executed === false) {
                // Fail-closed resolution: lock was not acquired, all drafts preserved
                updateStats();
                renderDashboard();
                return {
                    totalProcessed: 0,
                    syncedCount: 0,
                    replayedCount: 0,
                    rejectedCount: 0,
                    uncertainCount: 0,
                    executed: false,
                    reason: lockResult.reason,
                    message: lockResult.message || 'Synchronization aborted: Lock acquisition failed.'
                };
            }
            return lockResult ? lockResult.result : null;
        });
    }

    /**
     * Retries a single draft by UUID under confirmed lock.
     * @param {string} uuid
     * @param {Object} [options]
     * @returns {Promise<Object>}
     */
    function retrySingleDraft(uuid, options) {
        options = options || {};
        var storage = (typeof window !== 'undefined' && window.EleGuardStorage) ? window.EleGuardStorage : options.storage;

        if (!storage || !storage.isUnlocked()) {
            return Promise.reject(new Error('Storage must be unlocked to retry draft.'));
        }

        return withSyncLock(function () {
            return storage.getDraft(uuid).then(function (draft) {
                return transmitChunk([draft], options).then(function (result) {
                    updateStats();
                    renderDashboard();
                    return result;
                });
            });
        }).then(function (lockResult) {
            if (lockResult && lockResult.executed === false) {
                return {
                    executed: false,
                    reason: lockResult.reason,
                    message: lockResult.message || 'Retry aborted: Lock acquisition failed.'
                };
            }
            return lockResult ? lockResult.result : null;
        });
    }

    /* =========================================================================
     * 6. STATS & PROGRESS TRACKING
     * ========================================================================= */

    function updateStats() {
        var storage = (typeof window !== 'undefined' && window.EleGuardStorage) ? window.EleGuardStorage : null;
        if (!storage) return;

        storage.getDraftCount().then(function (cnt) {
            state.syncStats.totalDrafts = cnt;
            var synced = 0;
            var replayed = 0;
            var rejected = 0;
            var uncertain = 0;
            var pending = 0;

            for (var u in state.itemStatusMap) {
                if (state.itemStatusMap.hasOwnProperty(u)) {
                    var st = state.itemStatusMap[u].status;
                    if (st === 'synced') synced++;
                    else if (st === 'replayed') replayed++;
                    else if (st === 'rejected') rejected++;
                    else if (st === 'uncertain') uncertain++;
                }
            }

            state.syncStats.syncedCount = synced;
            state.syncStats.replayedCount = replayed;
            state.syncStats.rejectedCount = rejected;
            state.syncStats.uncertainCount = uncertain;
            state.syncStats.pendingCount = Math.max(0, cnt - rejected - uncertain);

            renderDashboard();
        }).catch(function () {});
    }

    function updateSyncProgress(currentChunk, totalChunks, label) {
        if (typeof document === 'undefined') return;
        var bar = document.getElementById('syncProgressBarFill');
        var text = document.getElementById('syncProgressText');
        var container = document.getElementById('syncProgressContainer');

        if (container) {
            container.style.display = state.isSyncInProgress ? 'block' : 'none';
        }
        if (bar && totalChunks > 0) {
            var pct = Math.round((currentChunk / totalChunks) * 100);
            bar.style.width = pct + '%';
        }
        if (text) {
            text.textContent = label || '';
        }
    }

    /* =========================================================================
     * 7. SYNCHRONIZATION DASHBOARD UI RENDERER
     * ========================================================================= */

    function renderDashboard() {
        if (typeof document === 'undefined') return;

        // Metric Tiles
        var pendingEl = document.getElementById('syncMetricPending');
        var syncedEl = document.getElementById('syncMetricSynced');
        var rejectedEl = document.getElementById('syncMetricRejected');
        var totalEl = document.getElementById('syncMetricTotal');
        var netStatusEl = document.getElementById('syncNetworkState');
        var syncNowBtn = document.getElementById('syncNowBtn');
        var lastSyncEl = document.getElementById('syncLastSyncTime');

        if (pendingEl) pendingEl.textContent = state.syncStats.pendingCount;
        if (syncedEl) syncedEl.textContent = state.syncStats.syncedCount + state.syncStats.replayedCount;
        if (rejectedEl) rejectedEl.textContent = state.syncStats.rejectedCount;
        if (totalEl) totalEl.textContent = state.syncStats.totalDrafts;

        var isOnline = (typeof navigator !== 'undefined' && typeof navigator.onLine === 'boolean') ? navigator.onLine : true;
        if (netStatusEl) {
            if (isOnline) {
                netStatusEl.innerHTML = '<span style="color: #10b981;">Online &bull; Gateway Connected</span>';
            } else {
                netStatusEl.innerHTML = '<span style="color: #ef4444;">Offline &bull; No Gateway Signal</span>';
            }
        }

        if (lastSyncEl) {
            if (state.lastSyncTime) {
                var d = new Date(state.lastSyncTime);
                lastSyncEl.textContent = d.toLocaleTimeString() + ' (' + d.toLocaleDateString() + ')';
            } else {
                lastSyncEl.textContent = 'Never (Session Start)';
            }
        }

        // Button State
        if (syncNowBtn) {
            var storage = (typeof window !== 'undefined' && window.EleGuardStorage) ? window.EleGuardStorage : null;
            var isUnlocked = storage ? storage.isUnlocked() : false;

            if (state.isSyncInProgress) {
                syncNowBtn.disabled = true;
                syncNowBtn.innerHTML = '<span>⏳ Synchronizing Outbox...</span>';
            } else if (!isOnline) {
                syncNowBtn.disabled = true;
                syncNowBtn.innerHTML = '<span>📡 Offline (Connect to Sync)</span>';
            } else if (!isUnlocked) {
                syncNowBtn.disabled = true;
                syncNowBtn.innerHTML = '<span>🔒 Unlock Storage to Sync</span>';
            } else if (state.syncStats.totalDrafts === 0) {
                syncNowBtn.disabled = true;
                syncNowBtn.innerHTML = '<span>✓ Outbox Empty (Nothing to Sync)</span>';
            } else {
                syncNowBtn.disabled = false;
                syncNowBtn.innerHTML = '<span>🚀 Synchronize Now (' + state.syncStats.totalDrafts + ' Drafts)</span>';
            }
        }

        // Render Itemized Outbox List
        renderSyncDraftsList();
    }

    function renderSyncDraftsList() {
        if (typeof document === 'undefined') return;
        var container = document.getElementById('syncDraftsListContainer');
        if (!container) return;

        var storage = (typeof window !== 'undefined' && window.EleGuardStorage) ? window.EleGuardStorage : null;
        if (!storage || !storage.isUnlocked()) {
            container.innerHTML = '<div style="text-align: center; padding: 24px; color: #94a3b8;">' +
                '🔒 Unlock encrypted storage above to view outbox items and error details.' +
                '</div>';
            return;
        }

        storage.listDrafts().then(function (drafts) {
            if (!drafts || drafts.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 24px; color: #10b981;">' +
                    '✓ All drafts are synchronized. Outbox is clear.' +
                    '</div>';
                return;
            }

            var html = '<div class="sync-items-grid">';
            drafts.forEach(function (d) {
                var uuid = d.client_report_uuid;
                var stInfo = state.itemStatusMap[uuid] || { status: 'pending', error: null, serverReportId: null };
                var badgeClass = 'sync-badge-pending';
                var badgeLabel = 'Pending ⏳';

                if (stInfo.status === 'syncing') {
                    badgeClass = 'sync-badge-syncing';
                    badgeLabel = 'Syncing 🔄';
                } else if (stInfo.status === 'synced') {
                    badgeClass = 'sync-badge-synced';
                    badgeLabel = 'Synced #' + (stInfo.serverReportId || '') + ' ✅';
                } else if (stInfo.status === 'replayed') {
                    badgeClass = 'sync-badge-replayed';
                    badgeLabel = 'Replayed #' + (stInfo.serverReportId || '') + ' 🔁';
                } else if (stInfo.status === 'rejected') {
                    badgeClass = 'sync-badge-rejected';
                    badgeLabel = 'Rejected ⚠️';
                } else if (stInfo.status === 'uncertain') {
                    badgeClass = 'sync-badge-uncertain';
                    badgeLabel = 'Uncertain ❓';
                }

                html += '<div class="sync-item-card ' + badgeClass + '">' +
                    '<div class="sync-item-header">' +
                        '<div class="sync-item-title">' +
                            '<strong>' + (d.observation_category || 'Observation') + '</strong> &bull; ' +
                            '<span style="font-size: 11px; color: #94a3b8;">' + (d.sighting_date || '') + ' ' + (d.sighting_time || '') + '</span>' +
                        '</div>' +
                        '<span class="sync-status-badge ' + badgeClass + '">' + badgeLabel + '</span>' +
                    '</div>' +
                    '<div class="sync-item-uuid"><code>' + uuid + '</code></div>';

                if (stInfo.error) {
                    html += '<div class="sync-item-error">' +
                        '<strong>Reason:</strong> ' + escapeHtml(stInfo.error) +
                    '</div>';
                }

                html += '<div class="sync-item-actions">' +
                    '<button type="button" class="btn btn-sm btn-outline" onclick="window.EleGuardWizard && window.EleGuardWizard.resumeDraft(\'' + uuid + '\')">' +
                        '✏️ Edit & Fix' +
                    '</button>' +
                    '<button type="button" class="btn btn-sm btn-primary" onclick="window.EleGuardSyncController && window.EleGuardSyncController.retrySingleDraft(\'' + uuid + '\')">' +
                        '🔄 Retry' +
                    '</button>' +
                '</div>' +
                '</div>';
            });
            html += '</div>';

            container.innerHTML = html;
        }).catch(function (err) {
            container.innerHTML = '<div style="color: #ef4444; padding: 12px;">Failed to load drafts: ' + err.message + '</div>';
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* =========================================================================
     * 8. INITIALIZATION & EVENT HOOKS
     * ========================================================================= */

    function init() {
        if (typeof window !== 'undefined') {
            window.addEventListener('online', function () {
                renderDashboard();
            });
            window.addEventListener('offline', function () {
                renderDashboard();
            });
            window.addEventListener('storage', function (e) {
                if (e.key === LEASE_STORAGE_KEY) {
                    renderDashboard();
                }
            });

            // Initial dashboard render when DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function () {
                    renderDashboard();
                });
            } else {
                renderDashboard();
            }
        }
    }

    init();

    /* =========================================================================
     * 9. PUBLIC API EXPORTS
     * ========================================================================= */

    var EleGuardSyncController = {
        DEFAULT_BATCH_SIZE: DEFAULT_BATCH_SIZE,
        MAX_BATCH_REPORTS: MAX_BATCH_REPORTS,
        MAX_BATCH_PAYLOAD_BYTES: MAX_BATCH_PAYLOAD_BYTES,

        getState: function () {
            return {
                isSyncInProgress: state.isSyncInProgress,
                batchSize: state.batchSize,
                lastSyncTime: state.lastSyncTime,
                syncStats: Object.assign({}, state.syncStats),
                itemStatusMap: Object.assign({}, state.itemStatusMap)
            };
        },

        setBatchSize: function (size) {
            var n = parseInt(size, 10);
            if (!isNaN(n) && n >= 1 && n <= MAX_BATCH_REPORTS) {
                state.batchSize = n;
            }
        },

        synchronizeAll: synchronizeAll,
        retrySingleDraft: retrySingleDraft,
        renderDashboard: renderDashboard,
        buildReportSyncPayload: buildReportSyncPayload,
        processMultiStatusResponse: processMultiStatusResponse,
        transmitChunk: transmitChunk,
        withSyncLock: withSyncLock,

        // Test helper hooks
        _test: {
            TAB_ID: TAB_ID,
            state: state,
            fallbackBuildComposedNotes: fallbackBuildComposedNotes
        }
    };

    return EleGuardSyncController;
}));
