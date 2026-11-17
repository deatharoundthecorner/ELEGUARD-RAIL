/**
 * EleGuard Rail — Guided Field Observation Capture Engine
 * Milestone 9C Stage 4E.4.2-B: Complete Observation Capture Workflow
 *
 * Implements:
 * 1. 6-step guided reporting workflow with dynamic stepper & state persistence.
 * 2. RFC 4122 v4 UUID generation and strict preservation across retries (Idempotency).
 * 3. Temporal evidence validation (Asia/Colombo timezone, 7 diurnal periods, future rejection).
 * 4. High-accuracy observer GNSS capture with error handling and spatial decoupling.
 * 5. Method A (Distance & Bearing Geodesic Offset) vs. Method B (Unknown Animal Location).
 * 6. Herd demographics tracking with auto-sum calculation and schema gap preservation.
 * 7. Railway section and milestone association.
 * 8. Comprehensive review screen with jump-to-step editing.
 * 9. API v2 integration: POST /api/v2/reports/submit with CSRF, RFC 9457 handling, 201 Created & 200 Replay.
 */

(function () {
    'use strict';

    var EARTH_RADIUS_METERS = 6371000.0;
    var SRI_LANKA_BOUNDS = { minLat: 5.8, maxLat: 9.9, minLon: 79.6, maxLon: 82.0 };

    /* =========================================================================
     * STRUCTURED LABELS & SUPPLEMENTARY DATA MAPS (MED-01, MED-02, MED-03)
     * ========================================================================= */
    var TUSKER_LABELS = {
        'single_tusker': 'Single Tusker',
        'multiple_tuskers': 'Multiple Tuskers',
        'unknown': 'Unknown'
    };

    var BEHAVIOR_LABELS = {
        'crossing': 'Crossing Railway Track',
        'grazing_verge': 'Grazing Along Verge / Cutting',
        'moving_parallel': 'Moving Parallel to Track',
        'resting_brush': 'Resting in Adjacent Forest'
    };

    var EVIDENCE_LABELS = {
        'direct_sighting': 'Direct Visual Sighting',
        'tracks_footprints': 'Indirect: Footprints / Tracks',
        'dung_fresh': 'Indirect: Fresh Dung',
        'dung_old': 'Indirect: Old Dung',
        'vegetation_damage': 'Indirect: Broken Trees / Crop Damage',
        'auditory': 'Indirect: Auditory / Vocalization'
    };

    /**
     * Builds structured composed notes preserving user description and supplementary metadata.
     * Prevents duplicate tag creation across submission retries.
     */
    function buildComposedNotes(state) {
        var baseNotes = (state.observer_notes || '').trim();
        var structuredTags = [];

        // 1. Demographics: Unclassified Individuals
        if (state.demographics && state.demographics.unclassified !== null && state.demographics.unclassified > 0) {
            var unclassTag = '[Field Demographics: ' + state.demographics.unclassified + ' unclassified individuals observed in herd]';
            if (baseNotes.indexOf('[Field Demographics:') === -1) {
                structuredTags.push(unclassTag);
            }
        }

        // 2. Tusker Sighting (MED-01) - Do not record if user selected 'no'
        var tusker = state.tusker_present;
        if (tusker && tusker !== 'no') {
            var tuskerText = TUSKER_LABELS[tusker] || tusker;
            var tuskerTag = '[Tusker Sighting: ' + tuskerText + ']';
            if (baseNotes.indexOf('[Tusker Sighting:') === -1) {
                structuredTags.push(tuskerTag);
            }
        }

        // 3. Elephant Behavior (MED-02)
        var behavior = state.elephant_behavior;
        if (behavior) {
            var behaviorText = BEHAVIOR_LABELS[behavior] || behavior;
            var behaviorTag = '[Elephant Behavior: ' + behaviorText + ']';
            if (baseNotes.indexOf('[Elephant Behavior:') === -1) {
                structuredTags.push(behaviorTag);
            }
        }

        // 4. Sighting Evidence Type (MED-03)
        var evidence = state.sighting_evidence_type;
        if (evidence) {
            var evidenceText = EVIDENCE_LABELS[evidence] || evidence;
            var evidenceTag = '[Sighting Evidence: ' + evidenceText + ']';
            if (baseNotes.indexOf('[Sighting Evidence:') === -1) {
                structuredTags.push(evidenceTag);
            }
        }

        if (structuredTags.length === 0) {
            return baseNotes || null;
        }

        return baseNotes ? (baseNotes + '\n' + structuredTags.join('\n')) : structuredTags.join('\n');
    }

    /* =========================================================================
     * 1. RFC 4122 v4 UUID GENERATOR & SESSION STATE
     * ========================================================================= */
    function generateUUIDv4() {
        if (typeof crypto !== 'undefined' && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    var reportState = {
        currentStep: 1,
        client_report_uuid: generateUUIDv4(), // Generated once per draft, preserved strictly on retries!
        submission_source: 'mobile_pwa',
        observation_category: 'live_sighting',
        urgency_level: 'Medium',
        location_description: '',
        observer_notes: '',
        nearest_milestone_ref: '',
        section_id: '',
        track_proximity: 'visible_corridor',
        sighting_evidence_type: 'direct_sighting',
        tusker_present: 'no',
        elephant_behavior: 'crossing',
        temporal: {
            sighting_date: '',
            sighting_time: null,
            sighting_time_precision: 'exact',
            diurnal_period: 'midday',
            time_unknown: false
        },
        observer_location: {
            latitude: null,
            longitude: null,
            gps_accuracy_meters: null,
            gps_captured_at: null,
            acquisition_method: 'gps_device'
        },
        animal_location: {
            unknown: false,
            method: 'triangulated_offset',
            observation_distance_meters: null,
            observation_bearing_degrees: null,
            animal_uncertainty: 25.0
        },
        demographics: {
            elephant_count: 1,
            adult_males: null,
            adult_females: null,
            calves: null,
            unclassified: null
        }
    };

    /* =========================================================================
     * 2. SERVICE WORKER & CONNECTIVITY LISTENERS
     * ========================================================================= */
    function initServiceWorker() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('../sw.js', { scope: '../' })
                .then(function (reg) {
                    console.log('[EleGuard PWA] Service Worker registered:', reg.scope);
                })
                .catch(function (err) {
                    console.warn('[EleGuard PWA] Service Worker registration note:', err.message);
                });
        }
    }

    function updateNetworkStatus() {
        var isOnline = navigator.onLine;
        var badge = document.getElementById('connectionStatusBadge');
        var text = document.getElementById('connectionStatusText');
        var syncState = document.getElementById('syncNetworkState');

        if (badge && text) {
            if (isOnline) {
                badge.className = 'status-badge online';
                text.innerText = 'Online';
            } else {
                badge.className = 'status-badge offline';
                text.innerText = 'Offline (Local)';
            }
        }
        if (syncState) {
            syncState.innerHTML = isOnline 
                ? '<span style="color: #10b981;">Online &bull; Connected</span>' 
                : '<span style="color: #ef4444;">Offline &bull; Disconnected</span>';
        }
    }

    window.addEventListener('online', updateNetworkStatus);
    window.addEventListener('offline', updateNetworkStatus);

    /* =========================================================================
     * 3. DIURNAL CONTINUUM CLASSIFIER (Authoritative 7 periods)
     * ========================================================================= */
    function classifyDiurnalPeriod(timeString) {
        if (!timeString) return 'midday';
        var parts = timeString.split(':');
        if (parts.length < 2) return 'midday';
        var hour = parseInt(parts[0], 10);
        var minute = parseInt(parts[1], 10);
        var totalMin = hour * 60 + minute;

        // Dawn: 04:00 - 07:00 (240 - 420)
        if (totalMin >= 240 && totalMin < 420) return 'dawn';
        // Morning: 07:00 - 11:00 (420 - 660)
        if (totalMin >= 420 && totalMin < 660) return 'morning';
        // Midday: 11:00 - 14:00 (660 - 840)
        if (totalMin >= 660 && totalMin < 840) return 'midday';
        // Afternoon: 14:00 - 17:00 (840 - 1020)
        if (totalMin >= 840 && totalMin < 1020) return 'afternoon';
        // Dusk: 17:00 - 19:30 (1020 - 1170)
        if (totalMin >= 1020 && totalMin < 1170) return 'dusk';
        // Night: 19:30 - 23:30 (1170 - 1410)
        if (totalMin >= 1170 && totalMin < 1410) return 'night';
        // Deep Night: 23:30 - 04:00 (>= 1410 or < 240)
        return 'deep_night';
    }

    function selectDiurnalButton(period) {
        reportState.temporal.diurnal_period = period;
        var diurnalInput = document.getElementById('diurnal_window');
        if (diurnalInput) diurnalInput.value = period;

        var diurnalButtons = document.querySelectorAll('.diurnal-btn');
        diurnalButtons.forEach(function (btn) {
            if (btn.getAttribute('data-period') === period) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
    }

    /* =========================================================================
     * 4. GEODESIC GREAT CIRCLE DIRECT SOLVER (Client Preview Only)
     * ========================================================================= */
    function toRadians(deg) { return deg * (Math.PI / 180.0); }
    function toDegrees(rad) { return rad * (180.0 / Math.PI); }

    function recalculateAnimalPositionPreview() {
        var calcDisplay = document.getElementById('calcCoordsDisplay');
        var uncDisplay = document.getElementById('calcUncertaintyDisplay');
        if (!calcDisplay || !uncDisplay) return;

        if (reportState.animal_location.unknown) {
            calcDisplay.innerHTML = '<span style="color: #94a3b8;">Animal Coordinates: NULL (Explicitly Unobserved)</span>';
            uncDisplay.innerText = 'Preserves Observer GPS. No animal coordinates will be written.';
            return;
        }

        var obsLat = reportState.observer_location.latitude;
        var obsLon = reportState.observer_location.longitude;
        var dist = reportState.animal_location.observation_distance_meters;
        var bearing = reportState.animal_location.observation_bearing_degrees;
        var gpsAcc = reportState.observer_location.gps_accuracy_meters || 10.0;
        var estUnc = reportState.animal_location.animal_uncertainty || 25.0;

        if (obsLat === null || obsLon === null) {
            calcDisplay.innerText = 'Waiting for Observer GPS fix...';
            calcDisplay.style.color = '#94a3b8';
            uncDisplay.innerText = 'Observer coordinates required to project distance offset.';
            return;
        }

        if (dist === null || dist === undefined || isNaN(dist)) {
            calcDisplay.innerText = 'Distance offset required for preview.';
            calcDisplay.style.color = '#94a3b8';
            uncDisplay.innerText = 'Enter distance in metres from observer.';
            return;
        }

        if (dist === 0) {
            calcDisplay.innerHTML = 'Direct Inspection (Ballast / Track Verge): <strong>' + obsLat.toFixed(6) + '° N, ' + obsLon.toFixed(6) + '° E</strong>';
            calcDisplay.style.color = '#f59e0b';
            var totalDirectUnc = Math.sqrt(Math.pow(gpsAcc, 2) + Math.pow(estUnc, 2));
            uncDisplay.innerText = 'Total Uncertainty: ±' + totalDirectUnc.toFixed(1) + 'm (Direct Co-location)';
            return;
        }

        if (bearing === null || bearing === undefined || isNaN(bearing)) {
            bearing = 0.0;
        }

        var phi1 = toRadians(obsLat);
        var lambda1 = toRadians(obsLon);
        var theta = toRadians(bearing);
        var delta = dist / EARTH_RADIUS_METERS;

        var phi2 = Math.asin(
            Math.sin(phi1) * Math.cos(delta) +
            Math.cos(phi1) * Math.sin(delta) * Math.cos(theta)
        );

        var lambda2 = lambda1 + Math.atan2(
            Math.sin(theta) * Math.sin(delta) * Math.cos(phi1),
            Math.cos(delta) - Math.sin(phi1) * Math.sin(phi2)
        );

        lambda2 = (lambda2 + 3 * Math.PI) % (2 * Math.PI) - Math.PI;

        var projLat = toDegrees(phi2);
        var projLon = toDegrees(lambda2);
        var totalSpatialUnc = Math.sqrt(Math.pow(gpsAcc, 2) + Math.pow(estUnc, 2));

        calcDisplay.innerHTML = 'Estimated Animal Position: <strong>' + projLat.toFixed(6) + '° N, ' + projLon.toFixed(6) + '° E</strong>';
        calcDisplay.style.color = '#38bdf8';
        uncDisplay.innerHTML = 'Propagated Spatial Uncertainty: <strong>±' + totalSpatialUnc.toFixed(1) + 'm</strong> ' +
            '(Offset: ' + dist.toFixed(0) + 'm @ ' + bearing.toFixed(0) + '° | Observer GNSS: ±' + gpsAcc.toFixed(1) + 'm)';
    }

    /* =========================================================================
     * 5. HIGH-ACCURACY OBSERVER GEOLOCATION HANDLER
     * ========================================================================= */
    function acquireObserverGPS() {
        var gpsBtn = document.getElementById('acquireGpsBtn');
        var gpsMsg = document.getElementById('gpsStatusMsg');

        if (!navigator.geolocation) {
            if (gpsMsg) {
                gpsMsg.innerHTML = '<span style="color: #ef4444;">Geolocation is not supported by this browser.</span>';
            }
            return;
        }

        if (gpsBtn) {
            gpsBtn.disabled = true;
            gpsBtn.innerHTML = '<span>🛰️ Requesting Satellite Fix (High Accuracy)...</span>';
        }
        if (gpsMsg) {
            gpsMsg.innerHTML = '<span style="color: #38bdf8;">Connecting to GNSS hardware...</span>';
        }

        var geoOptions = {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0
        };

        navigator.geolocation.getCurrentPosition(
            function (pos) {
                var lat = pos.coords.latitude;
                var lon = pos.coords.longitude;
                var acc = pos.coords.accuracy || 10.0;
                var nowIso = new Date().toISOString();

                reportState.observer_location.latitude = lat;
                reportState.observer_location.longitude = lon;
                reportState.observer_location.gps_accuracy_meters = acc;
                reportState.observer_location.gps_captured_at = nowIso;
                reportState.observer_location.acquisition_method = acc <= 15 ? 'gps_device' : 'network_coarse';

                var latInput = document.getElementById('observer_lat');
                var lonInput = document.getElementById('observer_lon');
                var accInput = document.getElementById('observer_accuracy');
                var timeInput = document.getElementById('acquisition_time');
                var methodInput = document.getElementById('acquisition_method');

                if (latInput) latInput.value = lat.toFixed(6);
                if (lonInput) lonInput.value = lon.toFixed(6);
                if (accInput) accInput.value = acc.toFixed(1);
                if (timeInput) timeInput.value = nowIso;
                if (methodInput) methodInput.value = reportState.observer_location.acquisition_method;

                if (gpsMsg) {
                    gpsMsg.innerHTML = '<span style="color: #10b981;">✓ Satellite Fix Acquired: Accuracy ±' + acc.toFixed(1) + 'm</span>';
                }
                if (gpsBtn) {
                    gpsBtn.disabled = false;
                    gpsBtn.innerHTML = '<span>📍 Re-acquire Observer GNSS</span>';
                }

                recalculateAnimalPositionPreview();
            },
            function (err) {
                var errDetail = 'GNSS acquisition failed: ';
                switch (err.code) {
                    case err.PERMISSION_DENIED:
                        errDetail += 'Location permission denied by user. Enter manual coordinates or descriptive landmark below.';
                        break;
                    case err.POSITION_UNAVAILABLE:
                        errDetail += 'Satellite signal unavailable (dense canopy or indoor). Enter coordinates manually if known.';
                        break;
                    case err.TIMEOUT:
                        errDetail += 'Satellite fix timed out after 15 seconds. Tap to retry or use descriptive landmark.';
                        break;
                    default:
                        errDetail += err.message;
                }

                if (gpsMsg) {
                    gpsMsg.innerHTML = '<span style="color: #ef4444;">✗ ' + errDetail + '</span>';
                }
                if (gpsBtn) {
                    gpsBtn.disabled = false;
                    gpsBtn.innerHTML = '<span>📍 Retry Satellite Fix</span>';
                }
            },
            geoOptions
        );
    }

    /* =========================================================================
     * 6. STEP VALIDATION & DATA SYNCHRONIZATION
     * ========================================================================= */
    function syncStepInputsToState(step) {
        if (step === 1) {
            var dateInput = document.getElementById('observation_date');
            var timeInput = document.getElementById('observation_time');
            var timeUnknown = document.getElementById('time_unknown');
            var catInput = document.getElementById('observation_category');
            var urgInput = document.getElementById('urgency_level');
            var locDescInput = document.getElementById('location_description');
            var notesInput = document.getElementById('observer_notes');

            reportState.temporal.sighting_date = dateInput ? dateInput.value : '';
            reportState.temporal.time_unknown = timeUnknown ? timeUnknown.checked : false;
            reportState.temporal.sighting_time = (reportState.temporal.time_unknown || !timeInput || !timeInput.value) ? null : timeInput.value;
            reportState.temporal.sighting_time_precision = reportState.temporal.time_unknown ? 'unknown' : (reportState.temporal.sighting_time ? 'exact' : 'approximate');
            
            if (catInput) reportState.observation_category = catInput.value;
            if (urgInput) reportState.urgency_level = urgInput.value;
            if (locDescInput) reportState.location_description = locDescInput.value.trim();
            if (notesInput) reportState.observer_notes = notesInput.value.trim();
        } else if (step === 2) {
            var latInput = document.getElementById('observer_lat');
            var lonInput = document.getElementById('observer_lon');
            var accInput = document.getElementById('observer_accuracy');
            var methodInput = document.getElementById('acquisition_method');

            var latVal = latInput && latInput.value !== '' ? parseFloat(latInput.value) : null;
            var lonVal = lonInput && lonInput.value !== '' ? parseFloat(lonInput.value) : null;
            var accVal = accInput && accInput.value !== '' ? parseFloat(accInput.value) : 10.0;

            reportState.observer_location.latitude = !isNaN(latVal) ? latVal : null;
            reportState.observer_location.longitude = !isNaN(lonVal) ? lonVal : null;
            reportState.observer_location.gps_accuracy_meters = !isNaN(accVal) ? accVal : 10.0;
            if (methodInput) reportState.observer_location.acquisition_method = methodInput.value;
        } else if (step === 3) {
            var unknownCheck = document.getElementById('animal_coords_unknown');
            var distInput = document.getElementById('observation_distance');
            var bearingInput = document.getElementById('observation_bearing');
            var uncInput = document.getElementById('animal_uncertainty');

            reportState.animal_location.unknown = unknownCheck ? unknownCheck.checked : false;

            if (reportState.animal_location.unknown) {
                reportState.animal_location.observation_distance_meters = null;
                reportState.animal_location.observation_bearing_degrees = null;
                reportState.animal_location.method = 'observer_standoff_unadjusted';
            } else {
                var distVal = distInput && distInput.value !== '' ? parseFloat(distInput.value) : null;
                var bearingVal = bearingInput && bearingInput.value !== '' ? parseFloat(bearingInput.value) : null;
                var uncVal = uncInput && uncInput.value !== '' ? parseFloat(uncInput.value) : 25.0;

                reportState.animal_location.observation_distance_meters = !isNaN(distVal) ? distVal : null;
                reportState.animal_location.observation_bearing_degrees = !isNaN(bearingVal) ? bearingVal : null;
                reportState.animal_location.animal_uncertainty = !isNaN(uncVal) ? uncVal : 25.0;
                reportState.animal_location.method = (distVal === 0) ? 'direct_inspection' : 'triangulated_offset';
            }
        } else if (step === 4) {
            var countInput = document.getElementById('elephant_count');
            var malesInput = document.getElementById('adult_males');
            var femalesInput = document.getElementById('adult_females');
            var calvesInput = document.getElementById('calves');
            var unclassInput = document.getElementById('unclassified_count');
            var evidenceInput = document.getElementById('sighting_evidence_type');
            var tuskerInput = document.getElementById('tusker_present');
            var behaviorInput = document.getElementById('elephant_behavior');

            var totalCount = countInput && countInput.value !== '' ? parseInt(countInput.value, 10) : 1;
            reportState.demographics.elephant_count = !isNaN(totalCount) && totalCount >= 1 ? totalCount : 1;

            reportState.demographics.adult_males = malesInput && malesInput.value !== '' ? parseInt(malesInput.value, 10) : null;
            reportState.demographics.adult_females = femalesInput && femalesInput.value !== '' ? parseInt(femalesInput.value, 10) : null;
            reportState.demographics.calves = calvesInput && calvesInput.value !== '' ? parseInt(calvesInput.value, 10) : null;
            reportState.demographics.unclassified = unclassInput && unclassInput.value !== '' ? parseInt(unclassInput.value, 10) : null;

            if (evidenceInput) reportState.sighting_evidence_type = evidenceInput.value;
            if (tuskerInput) reportState.tusker_present = tuskerInput.value;
            if (behaviorInput) reportState.elephant_behavior = behaviorInput.value;
        } else if (step === 5) {
            var sectionSelect = document.getElementById('section_id');
            var milestoneInput = document.getElementById('nearest_milestone_ref');
            var proxSelect = document.getElementById('track_proximity');

            reportState.section_id = sectionSelect ? sectionSelect.value : '';
            reportState.nearest_milestone_ref = milestoneInput ? milestoneInput.value.trim() : '';
            if (proxSelect) reportState.track_proximity = proxSelect.value;
        }
    }

    function validateCurrentStep(step) {
        syncStepInputsToState(step);
        var errors = [];

        if (step === 1) {
            if (!reportState.temporal.sighting_date) {
                errors.push('Observation date is required.');
            } else {
                var selectedDate = new Date(reportState.temporal.sighting_date + 'T00:00:00');
                var today = new Date();
                today.setHours(23, 59, 59, 999);
                if (selectedDate > today) {
                    errors.push('Observation date cannot be in the future.');
                }
            }

            // Check future clock time if date is today and time is known
            if (!reportState.temporal.time_unknown && reportState.temporal.sighting_time && reportState.temporal.sighting_date) {
                var now = new Date();
                var todayIso = now.toISOString().split('T')[0];
                if (reportState.temporal.sighting_date === todayIso) {
                    var parts = reportState.temporal.sighting_time.split(':');
                    var obsHours = parseInt(parts[0], 10);
                    var obsMins = parseInt(parts[1], 10);
                    var currentTotalMin = now.getHours() * 60 + now.getMinutes();
                    var obsTotalMin = obsHours * 60 + obsMins;
                    if (obsTotalMin > currentTotalMin) {
                        errors.push('Observation time cannot be in the future for today.');
                    }
                }
            }

            if (!reportState.location_description) {
                errors.push('Location description is required (e.g. Landmark, corridor name or kilometer reference).');
            }
        } else if (step === 2) {
            var lat = reportState.observer_location.latitude;
            var lon = reportState.observer_location.longitude;

            if (lat !== null || lon !== null) {
                if (lat === null || lon === null) {
                    errors.push('Both Observer Latitude and Longitude must be provided together.');
                } else {
                    if (lat < SRI_LANKA_BOUNDS.minLat || lat > SRI_LANKA_BOUNDS.maxLat) {
                        errors.push('Observer latitude (' + lat + ') is outside Sri Lanka territorial bounds (5.8° to 9.9° N).');
                    }
                    if (lon < SRI_LANKA_BOUNDS.minLon || lon > SRI_LANKA_BOUNDS.maxLon) {
                        errors.push('Observer longitude (' + lon + ') is outside Sri Lanka territorial bounds (79.6° to 82.0° E).');
                    }
                }
            }
        } else if (step === 3) {
            if (!reportState.animal_location.unknown) {
                var d = reportState.animal_location.observation_distance_meters;
                var b = reportState.animal_location.observation_bearing_degrees;

                if (d !== null && (d < 0 || d > 5000)) {
                    errors.push('Observation distance must be between 0 and 5000 metres.');
                }
                if (b !== null && (b < 0 || b >= 360)) {
                    errors.push('Compass bearing must be between 0° and 359°.');
                }
            }
        } else if (step === 4) {
            if (!reportState.demographics.elephant_count || reportState.demographics.elephant_count < 1) {
                errors.push('Total estimated elephant count must be at least 1.');
            }
            if (reportState.demographics.adult_males !== null && reportState.demographics.adult_males < 0) {
                errors.push('Adult males count cannot be negative.');
            }
            if (reportState.demographics.adult_females !== null && reportState.demographics.adult_females < 0) {
                errors.push('Adult females count cannot be negative.');
            }
            if (reportState.demographics.calves !== null && reportState.demographics.calves < 0) {
                errors.push('Calves count cannot be negative.');
            }
            if (reportState.demographics.unclassified !== null && reportState.demographics.unclassified < 0) {
                errors.push('Unclassified count cannot be negative.');
            }
        }

        var errorBanner = document.getElementById('stepErrorBanner');
        if (errorBanner) {
            if (errors.length > 0) {
                errorBanner.innerHTML = '<strong>Validation Error:</strong><br>' + errors.join('<br>');
                errorBanner.style.display = 'flex';
                return false;
            } else {
                errorBanner.style.display = 'none';
                return true;
            }
        }
        return errors.length === 0;
    }

    /* =========================================================================
     * 7. REVIEW SCREEN BUILDER
     * ========================================================================= */
    function populateReviewScreen() {
        var uuidDisplay = document.getElementById('reviewUuidDisplay');
        var syncUuid = document.getElementById('syncActiveUuid');
        if (uuidDisplay) uuidDisplay.innerText = reportState.client_report_uuid;
        if (syncUuid) syncUuid.innerText = reportState.client_report_uuid;

        // Card 1: Observation Details
        var revDateTime = document.getElementById('revDateTime');
        var revDiurnal = document.getElementById('revDiurnal');
        var revCategoryUrgency = document.getElementById('revCategoryUrgency');
        var revLocationDesc = document.getElementById('revLocationDesc');

        if (revDateTime) {
            var timeStr = reportState.temporal.time_unknown ? 'Time Unknown' : (reportState.temporal.sighting_time || 'Approximate Time');
            revDateTime.innerText = (reportState.temporal.sighting_date || '—') + ' (' + timeStr + ')';
        }
        if (revDiurnal) {
            revDiurnal.innerHTML = '<strong>' + (reportState.temporal.diurnal_period || 'midday').toUpperCase() + '</strong>';
        }
        if (revCategoryUrgency) {
            revCategoryUrgency.innerHTML = reportState.observation_category + ' &bull; Urgency: <strong>' + reportState.urgency_level + '</strong>';
        }
        if (revLocationDesc) {
            revLocationDesc.innerText = reportState.location_description || '—';
        }

        // Card 2: Observer Location
        var revObsCoords = document.getElementById('revObserverCoords');
        var revObsAcc = document.getElementById('revObserverAccuracy');

        if (revObsCoords) {
            if (reportState.observer_location.latitude !== null && reportState.observer_location.longitude !== null) {
                revObsCoords.innerHTML = '<strong>' + reportState.observer_location.latitude.toFixed(6) + '° N, ' + 
                                         reportState.observer_location.longitude.toFixed(6) + '° E</strong>';
            } else {
                revObsCoords.innerHTML = '<span style="color: #94a3b8;">Not Acquired / Manual Landmark Only</span>';
            }
        }
        if (revObsAcc) {
            var acc = reportState.observer_location.gps_accuracy_meters;
            var meth = reportState.observer_location.acquisition_method;
            revObsAcc.innerText = (acc ? '±' + acc.toFixed(1) + 'm' : '—') + ' (' + meth + ')';
        }

        // Card 3: Elephant Location
        var revAnimalStatus = document.getElementById('revAnimalStatus');
        var revAnimalOffset = document.getElementById('revAnimalOffset');

        if (revAnimalStatus) {
            if (reportState.animal_location.unknown) {
                revAnimalStatus.innerHTML = '<span style="color: #f59e0b; font-weight: 700;">⚠️ Location Unknown (Coordinates: NULL in database)</span>';
            } else if (reportState.animal_location.observation_distance_meters === 0) {
                revAnimalStatus.innerHTML = '<span style="color: #10b981; font-weight: 700;">Direct Co-location (On track / Ballast)</span>';
            } else {
                revAnimalStatus.innerHTML = '<span style="color: #38bdf8; font-weight: 700;">Triangulated Geodetic Offset</span>';
            }
        }
        if (revAnimalOffset) {
            if (reportState.animal_location.unknown) {
                revAnimalOffset.innerText = 'Observer coordinates preserved separately. Animal coords remain NULL.';
            } else {
                var d = reportState.animal_location.observation_distance_meters;
                var b = reportState.animal_location.observation_bearing_degrees;
                revAnimalOffset.innerText = (d !== null ? d + ' metres' : 'Omitted') + ' @ ' + (b !== null ? b + '° Azimuth' : 'Omitted');
            }
        }

        // Card 4: Demographics
        var revTotal = document.getElementById('revTotalCount');
        var revDemo = document.getElementById('revDemographics');

        if (revTotal) {
            revTotal.innerHTML = '<strong style="color: #10b981; font-size: 16px;">' + reportState.demographics.elephant_count + ' Elephants</strong>';
        }
        if (revDemo) {
            var parts = [];
            if (reportState.demographics.adult_males !== null) parts.push('Males: ' + reportState.demographics.adult_males);
            if (reportState.demographics.adult_females !== null) parts.push('Females: ' + reportState.demographics.adult_females);
            if (reportState.demographics.calves !== null) parts.push('Calves: ' + reportState.demographics.calves);
            if (reportState.demographics.unclassified !== null) parts.push('Unclassified: ' + reportState.demographics.unclassified);
            
            var demoText = parts.length > 0 ? parts.join(', ') : 'No demographic breakdown (Herd estimate only)';
            var extras = [];
            if (reportState.tusker_present && reportState.tusker_present !== 'no') {
                extras.push('🐘 ' + (TUSKER_LABELS[reportState.tusker_present] || reportState.tusker_present));
            }
            if (reportState.elephant_behavior) {
                extras.push('Behavior: ' + (BEHAVIOR_LABELS[reportState.elephant_behavior] || reportState.elephant_behavior));
            }
            if (reportState.sighting_evidence_type) {
                extras.push('Evidence: ' + (EVIDENCE_LABELS[reportState.sighting_evidence_type] || reportState.sighting_evidence_type));
            }
            if (extras.length > 0) {
                demoText += '\n• ' + extras.join(' • ');
            }
            revDemo.innerText = demoText;
        }

        // Card 5: Railway Association
        var revSection = document.getElementById('revRailwaySection');
        var revMilestone = document.getElementById('revMilestoneProximity');

        if (revSection) {
            var sectionSelect = document.getElementById('section_id');
            var selectedText = sectionSelect && sectionSelect.selectedIndex >= 0 ? sectionSelect.options[sectionSelect.selectedIndex].text : '';
            revSection.innerText = reportState.section_id ? selectedText : 'Auto-resolve via GIS proximity';
        }
        if (revMilestone) {
            revMilestone.innerText = (reportState.nearest_milestone_ref ? 'Milestone: ' + reportState.nearest_milestone_ref : 'No milestone ref') + 
                                     ' &bull; Proximity: ' + reportState.track_proximity;
        }
    }

    /* =========================================================================
     * 8. WIZARD STEP NAVIGATION CONTROLLER
     * ========================================================================= */
    var stepTitles = [
        'Step 1: Observation Details',
        'Step 2: Observer Location',
        'Step 3: Elephant Location',
        'Step 4: Group Demographics',
        'Step 5: Railway Association',
        'Step 6: Review & Submit'
    ];

    window.EleGuardWizard = {
        goToStep: function (targetStep) {
            if (targetStep < 1 || targetStep > 6) return;

            // If navigating forward, validate intervening steps
            if (targetStep > reportState.currentStep) {
                for (var s = reportState.currentStep; s < targetStep; s++) {
                    if (!validateCurrentStep(s)) {
                        return;
                    }
                }
            } else {
                syncStepInputsToState(reportState.currentStep);
            }

            reportState.currentStep = targetStep;

            // Update Step Panels
            for (var i = 1; i <= 6; i++) {
                var stepEl = document.getElementById('wizardStep' + i);
                var pillEl = document.getElementById('pillStep' + i);
                if (stepEl) {
                    if (i === targetStep) {
                        stepEl.classList.add('active');
                    } else {
                        stepEl.classList.remove('active');
                    }
                }
                if (pillEl) {
                    pillEl.classList.remove('active');
                    if (i === targetStep) {
                        pillEl.classList.add('active');
                    } else if (i < targetStep) {
                        pillEl.classList.add('completed');
                    }
                }
            }

            // Update Progress Bar & Counter
            var barFill = document.getElementById('stepperBarFill');
            var stepTitle = document.getElementById('stepperStepTitle');
            var counter = document.getElementById('stepperCounter');

            if (barFill) barFill.style.width = ((targetStep - 1) / 5 * 100) + '%';
            if (stepTitle) stepTitle.innerText = stepTitles[targetStep - 1];
            if (counter) counter.innerText = targetStep + ' of 6';

            // Populate Review screen on entering Step 6
            if (targetStep === 6) {
                populateReviewScreen();
            }

            // Clear any error banner
            var errorBanner = document.getElementById('stepErrorBanner');
            if (errorBanner) errorBanner.style.display = 'none';

            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        nextStep: function () {
            this.goToStep(reportState.currentStep + 1);
        },

        prevStep: function () {
            this.goToStep(reportState.currentStep - 1);
        },

        /* =====================================================================
         * 9. API V2 SUBMISSION & IDEMPOTENT RETRY ENGINE
         * ===================================================================== */
        submitObservation: function () {
            var submitBtn = document.getElementById('confirmSubmitBtn');
            var resultArea = document.getElementById('submissionResultArea');
            var actionRow = document.getElementById('submissionActionRow');

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span>⏳ Transmitting Telemetry to Control...</span>';
            }

            // Compose notes including unclassified demographics, tusker sighting, behavior, and evidence type (MED-01, MED-02, MED-03)
            var composedNotes = buildComposedNotes(reportState);

            // Construct API v2 payload matching RFC 9457 / FieldReportingService contract
            var payload = {
                client_report_uuid: reportState.client_report_uuid, // PRESERVED EXACTLY ON RETRY
                csrf_token: window.ELEGUARD_CSRF_TOKEN || '',
                submission_source: 'mobile_pwa',
                observation_category: reportState.observation_category,
                elephant_count: reportState.demographics.elephant_count,
                adult_males: reportState.demographics.adult_males,
                adult_females: reportState.demographics.adult_females,
                calves: reportState.demographics.calves,
                urgency_level: reportState.urgency_level,
                location_description: reportState.location_description,
                description: composedNotes || null,
                nearest_milestone_ref: reportState.nearest_milestone_ref || null,
                section_id: reportState.section_id ? parseInt(reportState.section_id, 10) : null,
                temporal: {
                    sighting_date: reportState.temporal.sighting_date,
                    sighting_time: reportState.temporal.time_unknown ? null : reportState.temporal.sighting_time,
                    sighting_time_precision: reportState.temporal.time_unknown ? 'unknown' : (reportState.temporal.sighting_time ? 'exact' : 'approximate'),
                    diurnal_period: reportState.temporal.time_unknown ? null : reportState.temporal.diurnal_period
                },
                observer_location: {
                    latitude: reportState.observer_location.latitude,
                    longitude: reportState.observer_location.longitude,
                    gps_accuracy_meters: reportState.observer_location.gps_accuracy_meters,
                    gps_captured_at: reportState.observer_location.gps_captured_at,
                    acquisition_method: reportState.observer_location.acquisition_method
                },
                animal_location: reportState.animal_location.unknown ? {
                    method: 'observer_standoff_unadjusted',
                    observation_distance_meters: null,
                    observation_bearing_degrees: null
                } : {
                    method: (reportState.animal_location.observation_distance_meters === 0) ? 'direct_inspection' : 'triangulated_offset',
                    observation_distance_meters: reportState.animal_location.observation_distance_meters,
                    observation_bearing_degrees: reportState.animal_location.observation_bearing_degrees
                }
            };

            var endpoint = window.ELEGUARD_API_SUBMIT || '../api/v2/reports/submit.php';

            fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.ELEGUARD_CSRF_TOKEN || '',
                    'X-Idempotency-Key': reportState.client_report_uuid
                },
                body: JSON.stringify(payload)
            })
            .then(function (res) {
                return res.json().then(function (body) {
                    return { status: res.status, body: body };
                });
            })
            .then(function (result) {
                var status = result.status;
                var data = result.body;

                if (status === 201) {
                    // HTTP 201 Created -> Brand new observation ingested
                    var reportId = data.data ? data.data.report_id : 'SUCCESS';
                    var timestamp = data.data ? data.data.created_at : new Date().toISOString();

                    if (resultArea) {
                        resultArea.innerHTML = 
                            '<div class="submission-banner submission-success">' +
                                '<div class="submission-banner-icon">✅</div>' +
                                '<h3 class="submission-banner-title">Observation Successfully Ingested</h3>' +
                                '<div class="report-id-badge">Report ID #' + reportId + '</div>' +
                                '<p class="submission-banner-text">' +
                                    'Your field sighting has been securely logged with the railway control center and queued for verification.<br>' +
                                    '<small>Created: ' + timestamp + ' | UUID: ' + reportState.client_report_uuid + '</small>' +
                                '</p>' +
                                '<button type="button" class="btn btn-primary" onclick="window.EleGuardWizard.resetAndStartNew()">' +
                                    '➕ Log Another Field Observation' +
                                '</button>' +
                            '</div>';
                    }
                    if (actionRow) actionRow.style.display = 'none';

                    // Stage 4E.4.2-C: Auto-purge draft from local storage upon successful online submission
                    if (window.EleGuardStorage && window.EleGuardStorage.isUnlocked()) {
                        window.EleGuardStorage.deleteDraft(reportState.client_report_uuid).then(function () {
                            if (window.EleGuardStorageUI) window.EleGuardStorageUI.refreshStorageUI();
                        }).catch(function (e) {
                            console.warn('[EleGuard PWA] Auto-purge draft error:', e.message);
                        });
                    }

                } else if (status === 200) {
                    // HTTP 200 OK -> Idempotent replay of previously received UUID
                    var replayReportId = data.data ? data.data.report_id : 'CONFIRMED';

                    if (resultArea) {
                        resultArea.innerHTML = 
                            '<div class="submission-banner submission-replay">' +
                                '<div class="submission-banner-icon">🔁</div>' +
                                '<h3 class="submission-banner-title">Report Acknowledged (Duplicate Safe)</h3>' +
                                '<div class="report-id-badge">Existing Report #' + replayReportId + '</div>' +
                                '<p class="submission-banner-text">' +
                                    'This observation was previously received and confirmed under the same client UUID. No duplicate records were created.<br>' +
                                    '<small>UUID: ' + reportState.client_report_uuid + '</small>' +
                                '</p>' +
                                '<button type="button" class="btn btn-primary" onclick="window.EleGuardWizard.resetAndStartNew()">' +
                                    '➕ Log Another Field Observation' +
                                '</button>' +
                            '</div>';
                    }
                    if (actionRow) actionRow.style.display = 'none';

                    // Stage 4E.4.2-C: Auto-purge draft from local storage upon successful online submission
                    if (window.EleGuardStorage && window.EleGuardStorage.isUnlocked()) {
                        window.EleGuardStorage.deleteDraft(reportState.client_report_uuid).then(function () {
                            if (window.EleGuardStorageUI) window.EleGuardStorageUI.refreshStorageUI();
                        }).catch(function (e) {
                            console.warn('[EleGuard PWA] Auto-purge draft error:', e.message);
                        });
                    }

                } else if (status === 422) {
                    // HTTP 422 -> RFC 9457 Validation Errors
                    var invalidParams = data.invalid_params || {};
                    var paramMessages = [];
                    for (var key in invalidParams) {
                        if (invalidParams.hasOwnProperty(key)) {
                            paramMessages.push('<li><strong>' + key + ':</strong> ' + invalidParams[key] + '</li>');
                        }
                    }

                    if (resultArea) {
                        resultArea.innerHTML = 
                            '<div class="submission-banner submission-error">' +
                                '<div class="submission-banner-icon">⚠️</div>' +
                                '<h3 class="submission-banner-title">' + (data.title || 'Validation Error') + '</h3>' +
                                '<p class="submission-banner-text">' + (data.detail || 'The submission was rejected by the server validation engine.') + '</p>' +
                                '<ul style="text-align: left; max-width: 450px; margin: 0 auto 16px auto; font-size: 13px; color: #fecaca;">' +
                                    paramMessages.join('') +
                                '</ul>' +
                                '<button type="button" class="btn btn-primary" id="retrySubmitBtn" onclick="window.EleGuardWizard.submitObservation()">' +
                                    '🔄 Retry Submission (Same UUID)' +
                                '</button>' +
                            '</div>';
                    }
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<span>🚀 Confirm &amp; Submit Observation</span>';
                    }

                } else if (status === 401 || status === 403) {
                    if (resultArea) {
                        resultArea.innerHTML = 
                            '<div class="submission-banner submission-error">' +
                                '<div class="submission-banner-icon">🔒</div>' +
                                '<h3 class="submission-banner-title">Authentication / Session Expired</h3>' +
                                '<p class="submission-banner-text">Your user session has expired. Please open a new tab, log in again, and return to this tab to retry without losing your entered data.</p>' +
                                '<button type="button" class="btn btn-primary" onclick="window.EleGuardWizard.submitObservation()">' +
                                    '🔄 Retry Submission' +
                                '</button>' +
                            '</div>';
                    }
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<span>🚀 Confirm &amp; Submit Observation</span>';
                    }
                } else {
                    throw new Error(data.detail || 'Unexpected response status: ' + status);
                }
            })
            .catch(function (error) {
                console.warn('[EleGuard PWA] Submission network failure:', error.message);
                if (resultArea) {
                    resultArea.innerHTML = 
                        '<div class="submission-banner submission-error">' +
                            '<div class="submission-banner-icon">📡</div>' +
                            '<h3 class="submission-banner-title">Transmission Interrupted (Offline / Timeout)</h3>' +
                            '<p class="submission-banner-text">' +
                                'A network interruption occurred. Your observation data and unique idempotency key (<code>' + 
                                reportState.client_report_uuid + '</code>) remain securely preserved in session memory.<br>' +
                                'Please retry once connectivity is restored.' +
                            '</p>' +
                            '<button type="button" class="btn btn-primary" onclick="window.EleGuardWizard.submitObservation()">' +
                                '🔄 Retry Submission (Same UUID: ' + reportState.client_report_uuid.substring(0, 8) + '...)' +
                            '</button>' +
                        '</div>';
                }
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<span>🚀 Confirm &amp; Submit Observation</span>';
                }
            });
        },

        resetAndStartNew: function () {
            // Generates a brand new UUID for the new observation draft
            reportState.client_report_uuid = generateUUIDv4();
            reportState.currentStep = 1;

            var form = document.getElementById('guidedReportForm');
            if (form) form.reset();

            // Reset inputs to clean state
            reportState.temporal.time_unknown = false;
            reportState.animal_location.unknown = false;
            reportState.observer_location.latitude = null;
            reportState.observer_location.longitude = null;

            var dateInput = document.getElementById('observation_date');
            if (dateInput) dateInput.value = new Date().toISOString().split('T')[0];

            var resultArea = document.getElementById('submissionResultArea');
            var actionRow = document.getElementById('submissionActionRow');
            if (resultArea) resultArea.innerHTML = '';
            if (actionRow) actionRow.style.display = 'flex';

            var offsetSec = document.getElementById('animal_offset_section');
            if (offsetSec) {
                offsetSec.style.opacity = '1.0';
                offsetSec.style.pointerEvents = 'auto';
            }

            this.goToStep(1);
        },

        resumeDraft: function (uuid) {
            if (window.EleGuardStorageUI && typeof window.EleGuardStorageUI.resumeDraft === 'function') {
                return window.EleGuardStorageUI.resumeDraft(uuid);
            }
        },

        buildComposedNotes: buildComposedNotes,
        getReportState: function () { return reportState; }
    };

    /* =========================================================================
     * 9B. ELEGUARD ENCRYPTED STORAGE UI CONTROLLER
     * ========================================================================= */
    window.EleGuardStorageUI = {
        toastTimer: null,

        showToast: function (message, type) {
            var toast = document.getElementById('storageToast');
            if (!toast) return;
            if (this.toastTimer) clearTimeout(this.toastTimer);
            toast.className = 'storage-toast ' + (type || 'info');
            toast.innerHTML = message;
            toast.style.display = 'flex';
            this.toastTimer = setTimeout(function () {
                toast.style.display = 'none';
            }, 3500);
        },

        closeModals: function () {
            var modals = ['storageSetupModal', 'storageUnlockModal', 'storageDraftsModal'];
            modals.forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.style.display = 'none';
            });
            var err1 = document.getElementById('setupErrorMsg');
            if (err1) { err1.style.display = 'none'; err1.textContent = ''; }
            var err2 = document.getElementById('unlockErrorMsg');
            if (err2) { err2.style.display = 'none'; err2.textContent = ''; }
        },

        openStorageModal: function () {
            if (!window.EleGuardStorage) {
                this.showToast('Encrypted storage engine not loaded.', 'error');
                return;
            }
            var self = this;
            window.EleGuardStorage.isInitialized().then(function (init) {
                if (!init) {
                    self.closeModals();
                    var modal = document.getElementById('storageSetupModal');
                    if (modal) modal.style.display = 'flex';
                } else if (!window.EleGuardStorage.isUnlocked()) {
                    self.closeModals();
                    var modal = document.getElementById('storageUnlockModal');
                    if (modal) modal.style.display = 'flex';
                } else {
                    self.closeModals();
                    self.renderDraftsIntoContainer('modalDraftsListContainer');
                    var modal = document.getElementById('storageDraftsModal');
                    if (modal) modal.style.display = 'flex';
                }
            }).catch(function (e) {
                self.showToast('Storage check error: ' + e.message, 'error');
            });
        },

        init: function () {
            this.refreshStorageUI();
        },

        refreshStorageUI: function () {
            if (!window.EleGuardStorage) return;
            var self = this;
            window.EleGuardStorage.isInitialized().then(function (isInit) {
                var isUnlocked = window.EleGuardStorage.isUnlocked();
                var statusBtn = document.getElementById('storageStatusBtn');
                var statusIcon = document.getElementById('storageStatusIcon');
                var statusText = document.getElementById('storageStatusText');
                var countBubble = document.getElementById('storageDraftCountBubble');
                var tabCountBadge = document.getElementById('draftCountBadge');

                var tabUninit = document.getElementById('tabDraftsUninitialized');
                var tabLocked = document.getElementById('tabDraftsLocked');
                var tabUnlocked = document.getElementById('tabDraftsUnlocked');

                if (!isInit) {
                    if (statusBtn) {
                        statusBtn.className = 'storage-status-pill uninitialized';
                        statusBtn.title = 'Storage Not Initialized (Click to setup)';
                    }
                    if (statusIcon) statusIcon.textContent = '🔒';
                    if (statusText) statusText.textContent = 'Setup Storage';
                    if (countBubble) countBubble.style.display = 'none';
                    if (tabCountBadge) tabCountBadge.textContent = '0';

                    if (tabUninit) tabUninit.style.display = 'block';
                    if (tabLocked) tabLocked.style.display = 'none';
                    if (tabUnlocked) tabUnlocked.style.display = 'none';
                } else if (!isUnlocked) {
                    if (statusBtn) {
                        statusBtn.className = 'storage-status-pill locked';
                        statusBtn.title = 'Storage Locked (Click to unlock)';
                    }
                    if (statusIcon) statusIcon.textContent = '🔒';
                    if (statusText) statusText.textContent = 'Locked';

                    window.EleGuardStorage.getDraftCount().then(function (cnt) {
                        if (countBubble) {
                            countBubble.textContent = cnt;
                            countBubble.style.display = cnt > 0 ? 'inline-flex' : 'none';
                        }
                        if (tabCountBadge) tabCountBadge.textContent = cnt;
                    });

                    if (tabUninit) tabUninit.style.display = 'none';
                    if (tabLocked) tabLocked.style.display = 'block';
                    if (tabUnlocked) tabUnlocked.style.display = 'none';
                } else {
                    if (statusBtn) {
                        statusBtn.className = 'storage-status-pill unlocked';
                        statusBtn.title = 'Storage Unlocked (Click to view drafts)';
                    }
                    if (statusIcon) statusIcon.textContent = '🔓';
                    if (statusText) statusText.textContent = 'Unlocked';

                    window.EleGuardStorage.getDraftCount().then(function (cnt) {
                        if (countBubble) {
                            countBubble.textContent = cnt;
                            countBubble.style.display = cnt > 0 ? 'inline-flex' : 'none';
                        }
                        if (tabCountBadge) tabCountBadge.textContent = cnt;
                    });

                    if (tabUninit) tabUninit.style.display = 'none';
                    if (tabLocked) tabLocked.style.display = 'none';
                    if (tabUnlocked) tabUnlocked.style.display = 'block';

                    self.renderDraftsIntoContainer('tabDraftsListContainer');
                }

                if (window.EleGuardSyncController && typeof window.EleGuardSyncController.renderDashboard === 'function') {
                    window.EleGuardSyncController.renderDashboard();
                }
            }).catch(function (err) {
                console.warn('[EleGuard Storage UI] Refresh error:', err.message);
            });
        },

        handleSetupSubmit: function () {
            var passEl = document.getElementById('setupPassphraseInput');
            var confEl = document.getElementById('setupPassphraseConfirmInput');
            var errEl = document.getElementById('setupErrorMsg');
            this._processSetup(passEl ? passEl.value : '', confEl ? confEl.value : '', errEl);
        },

        handleTabSetupSubmit: function () {
            var passEl = document.getElementById('tabSetupPassphrase');
            var confEl = document.getElementById('tabSetupPassphraseConfirm');
            var errEl = document.getElementById('tabSetupErrorMsg');
            this._processSetup(passEl ? passEl.value : '', confEl ? confEl.value : '', errEl);
        },

        _processSetup: function (pass, conf, errEl) {
            var self = this;
            if (!pass || pass.length < 6) {
                if (errEl) {
                    errEl.textContent = 'Passphrase must be at least 6 characters long.';
                    errEl.style.display = 'block';
                }
                return;
            }
            if (pass !== conf) {
                if (errEl) {
                    errEl.textContent = 'Passphrases do not match.';
                    errEl.style.display = 'block';
                }
                return;
            }
            if (errEl) errEl.style.display = 'none';

            window.EleGuardStorage.setupPassphrase(pass).then(function () {
                self.closeModals();
                self.showToast('🔐 Encrypted storage initialized successfully!', 'success');
                self.refreshStorageUI();
            }).catch(function (err) {
                if (errEl) {
                    errEl.textContent = err.message;
                    errEl.style.display = 'block';
                }
                self.showToast('Storage setup failed: ' + err.message, 'error');
            });
        },

        handleUnlockSubmit: function () {
            var passEl = document.getElementById('unlockPassphraseInput');
            var errEl = document.getElementById('unlockErrorMsg');
            this._processUnlock(passEl ? passEl.value : '', errEl);
        },

        handleTabUnlockSubmit: function () {
            var passEl = document.getElementById('tabUnlockPassphrase');
            var errEl = document.getElementById('tabUnlockErrorMsg');
            this._processUnlock(passEl ? passEl.value : '', errEl);
        },

        _processUnlock: function (pass, errEl) {
            var self = this;
            if (!pass) {
                if (errEl) {
                    errEl.textContent = 'Please enter your passphrase.';
                    errEl.style.display = 'block';
                }
                return;
            }
            if (errEl) errEl.style.display = 'none';

            window.EleGuardStorage.unlock(pass).then(function () {
                self.closeModals();
                self.showToast('🔓 Storage unlocked. Drafts available in memory.', 'success');
                self.refreshStorageUI();
            }).catch(function (err) {
                if (errEl) {
                    errEl.textContent = 'Incorrect passphrase or corrupted storage.';
                    errEl.style.display = 'block';
                }
                self.showToast('Unlock failed: Incorrect passphrase', 'error');
            });
        },

        lockStorage: function () {
            if (!window.EleGuardStorage) return;
            window.EleGuardStorage.lock();
            this.closeModals();
            this.showToast('🔒 Storage locked. Decryption key evicted from memory.', 'info');
            this.refreshStorageUI();
            if (window.EleGuardSyncController && typeof window.EleGuardSyncController.renderDashboard === 'function') {
                window.EleGuardSyncController.renderDashboard();
            }
        },

        saveCurrentDraft: function () {
            if (!window.EleGuardStorage) {
                this.showToast('Storage engine unavailable.', 'error');
                return;
            }
            var self = this;

            // Sync current inputs across all wizard steps into reportState
            for (var s = 1; s <= 5; s++) {
                syncStepInputsToState(s);
            }

            window.EleGuardStorage.isInitialized().then(function (isInit) {
                if (!isInit) {
                    self.showToast('Please setup storage passphrase first.', 'info');
                    self.openStorageModal();
                    return;
                }
                if (!window.EleGuardStorage.isUnlocked()) {
                    self.showToast('Storage is locked. Please unlock to save draft.', 'info');
                    self.openStorageModal();
                    return;
                }

                window.EleGuardStorage.saveDraft(reportState).then(function (res) {
                    var draftUuid = res.client_report_uuid || res.uuid || reportState.client_report_uuid || '';
                    self.showToast('💾 Draft encrypted & saved (UUID: ' + draftUuid.substring(0, 8) + '...)', 'success');
                    self.refreshStorageUI();
                }).catch(function (err) {
                    self.showToast('Failed to save draft: ' + err.message, 'error');
                });
            });
        },

        renderDraftsIntoContainer: function (containerId) {
            var container = document.getElementById(containerId);
            if (!container || !window.EleGuardStorage || !window.EleGuardStorage.isUnlocked()) return;

            window.EleGuardStorage.listDrafts().then(function (drafts) {
                if (!drafts || drafts.length === 0) {
                    container.innerHTML = '<div style="padding: 24px; text-align: center; color: var(--text-muted); font-size: 13px;">No encrypted drafts in storage.</div>';
                    return;
                }

                var html = '';
                var now = Date.now();
                var EXPIRY_MS = 72 * 60 * 60 * 1000;

                drafts.forEach(function (d) {
                    var createdMs = new Date(d.created_at_utc).getTime();
                    var remainingMs = (createdMs + EXPIRY_MS) - now;
                    var remainingHours = Math.max(0, Math.round(remainingMs / (60 * 60 * 1000)));

                    var catLabel = d.observation_category || 'Sighting';
                    var count = d.elephant_count || 1;
                    var urgency = d.urgency_level || 'standard';

                    html += '<div class="draft-item-card">' +
                        '<div class="draft-item-header">' +
                            '<span class="draft-title-badge">🐘 ' + catLabel + ' (' + count + ' ' + (count === 1 ? 'elephant' : 'elephants') + ')</span>' +
                            '<span class="draft-expiry-pill">Expires in ~' + remainingHours + 'h</span>' +
                        '</div>' +
                        '<div class="draft-details-text">' +
                            'UUID: <code>' + d.client_report_uuid.substring(0, 8) + '...</code> &bull; ' +
                            'Saved: ' + new Date(d.updated_at_utc || d.created_at_utc).toLocaleTimeString() + ' &bull; ' +
                            'Urgency: <strong>' + urgency.toUpperCase() + '</strong>' +
                        '</div>' +
                        '<div class="draft-actions-row">' +
                            '<button type="button" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; min-height: 30px;" onclick="window.EleGuardStorageUI.resumeDraft(\'' + d.client_report_uuid + '\')">📂 Resume Draft</button>' +
                            '<button type="button" class="btn btn-danger" style="padding: 4px 10px; font-size: 12px; min-height: 30px;" onclick="window.EleGuardStorageUI.confirmDeleteDraft(\'' + d.client_report_uuid + '\')">🗑️ Delete</button>' +
                        '</div>' +
                    '</div>';
                });

                container.innerHTML = html;
            }).catch(function (err) {
                container.innerHTML = '<div style="color: #ef4444; font-size: 13px; padding: 12px;">Failed to load drafts: ' + err.message + '</div>';
            });
        },

        resumeDraft: function (uuid) {
            if (!window.EleGuardStorage || !window.EleGuardStorage.isUnlocked()) return;
            var self = this;

            window.EleGuardStorage.getDraft(uuid).then(function (draft) {
                if (!draft) {
                    self.showToast('Draft not found or expired.', 'error');
                    return;
                }

                // 1. Restore reportState preserving the exact client_report_uuid (never generate new UUID)
                reportState.client_report_uuid = draft.client_report_uuid;
                reportState.submission_source = draft.submission_source || 'mobile_pwa';
                reportState.observation_category = draft.observation_category || 'live_sighting';
                reportState.urgency_level = draft.urgency_level || 'Medium';
                reportState.location_description = draft.location_description || '';
                reportState.observer_notes = draft.observer_notes || '';
                reportState.nearest_milestone_ref = draft.nearest_milestone_ref || '';
                reportState.section_id = draft.section_id !== undefined ? draft.section_id : '';
                reportState.track_proximity = draft.track_proximity || 'visible_corridor';
                reportState.sighting_evidence_type = draft.sighting_evidence_type || 'direct_sighting';
                reportState.tusker_present = draft.tusker_present || 'no';
                reportState.elephant_behavior = draft.elephant_behavior || (draft.behaviour || 'crossing');

                if (draft.temporal) {
                    reportState.temporal = Object.assign({}, reportState.temporal, draft.temporal);
                }
                if (draft.observer_location) {
                    reportState.observer_location = Object.assign({}, reportState.observer_location, draft.observer_location);
                }
                if (draft.animal_location) {
                    reportState.animal_location = Object.assign({}, reportState.animal_location, draft.animal_location);
                }
                if (draft.demographics) {
                    reportState.demographics = Object.assign({}, reportState.demographics, draft.demographics);
                }

                // 2. Populate Step 1 DOM Controls
                var dateInput = document.getElementById('observation_date');
                var timeInput = document.getElementById('observation_time');
                var timeUnknown = document.getElementById('time_unknown');
                var catInput = document.getElementById('observation_category');
                var urgInput = document.getElementById('urgency_level');
                var locDescInput = document.getElementById('location_description');
                var notesInput = document.getElementById('observer_notes');
                var diurnalWindowInput = document.getElementById('diurnal_window');

                if (dateInput) dateInput.value = reportState.temporal.sighting_date || '';
                if (timeInput) {
                    timeInput.value = reportState.temporal.sighting_time || '';
                    timeInput.disabled = !!reportState.temporal.time_unknown;
                }
                if (timeUnknown) timeUnknown.checked = !!reportState.temporal.time_unknown;
                if (diurnalWindowInput && reportState.temporal.diurnal_period) {
                    diurnalWindowInput.value = reportState.temporal.diurnal_period;
                }
                if (catInput) catInput.value = reportState.observation_category;
                if (urgInput) urgInput.value = reportState.urgency_level;
                if (locDescInput) locDescInput.value = reportState.location_description;
                if (notesInput) notesInput.value = reportState.observer_notes;

                // Sync diurnal buttons
                var diurnalBtns = document.querySelectorAll('.diurnal-btn');
                diurnalBtns.forEach(function (btn) {
                    if (btn.getAttribute('data-period') === reportState.temporal.diurnal_period) {
                        btn.classList.add('active');
                    } else {
                        btn.classList.remove('active');
                    }
                });

                // 3. Populate Step 2 DOM Controls (Observer Location)
                var latInput = document.getElementById('observer_lat');
                var lonInput = document.getElementById('observer_lon');
                var accInput = document.getElementById('observer_accuracy');
                var acqTimeInput = document.getElementById('acquisition_time');
                var acqMethodInput = document.getElementById('acquisition_method');

                if (latInput) latInput.value = (reportState.observer_location.latitude !== null && reportState.observer_location.latitude !== undefined) ? reportState.observer_location.latitude : '';
                if (lonInput) lonInput.value = (reportState.observer_location.longitude !== null && reportState.observer_location.longitude !== undefined) ? reportState.observer_location.longitude : '';
                if (accInput) accInput.value = (reportState.observer_location.gps_accuracy_meters !== null && reportState.observer_location.gps_accuracy_meters !== undefined) ? reportState.observer_location.gps_accuracy_meters : '';
                if (acqTimeInput) acqTimeInput.value = reportState.observer_location.gps_captured_at || '';
                if (acqMethodInput && reportState.observer_location.acquisition_method) acqMethodInput.value = reportState.observer_location.acquisition_method;

                // 4. Populate Step 3 DOM Controls (Animal Location)
                var locUnkCheckbox = document.getElementById('animal_coords_unknown');
                var distInput = document.getElementById('observation_distance');
                var bearingInput = document.getElementById('observation_bearing');
                var uncInput = document.getElementById('animal_uncertainty');
                var animalOffsetSec = document.getElementById('animal_offset_section');

                if (locUnkCheckbox) {
                    locUnkCheckbox.checked = !!reportState.animal_location.unknown;
                    if (animalOffsetSec) {
                        animalOffsetSec.style.opacity = locUnkCheckbox.checked ? '0.4' : '1.0';
                        animalOffsetSec.style.pointerEvents = locUnkCheckbox.checked ? 'none' : 'auto';
                    }
                }
                if (distInput) distInput.value = (reportState.animal_location.observation_distance_meters !== null && reportState.animal_location.observation_distance_meters !== undefined) ? reportState.animal_location.observation_distance_meters : '';
                if (bearingInput) bearingInput.value = (reportState.animal_location.observation_bearing_degrees !== null && reportState.animal_location.observation_bearing_degrees !== undefined) ? reportState.animal_location.observation_bearing_degrees : '';
                if (uncInput) uncInput.value = (reportState.animal_location.animal_uncertainty !== null && reportState.animal_location.animal_uncertainty !== undefined) ? reportState.animal_location.animal_uncertainty : 25;

                // Compass Cardinal Buttons
                var dirBtns = document.querySelectorAll('.dir-btn');
                dirBtns.forEach(function (btn) {
                    var btnVal = btn.getAttribute('data-bearing');
                    if (reportState.animal_location.observation_bearing_degrees !== null && btnVal !== null && parseFloat(btnVal) === parseFloat(reportState.animal_location.observation_bearing_degrees)) {
                        btn.classList.add('active');
                    } else {
                        btn.classList.remove('active');
                    }
                });

                // 5. Populate Step 4 DOM Controls (Demographics & Sighting Info)
                var elephantCountInput = document.getElementById('elephant_count');
                var evidenceSelect = document.getElementById('sighting_evidence_type');
                var malesInput = document.getElementById('adult_males');
                var femalesInput = document.getElementById('adult_females');
                var calvesInput = document.getElementById('calves');
                var unclassInput = document.getElementById('unclassified_count');
                var tuskerSelect = document.getElementById('tusker_present');
                var behaviorSelect = document.getElementById('elephant_behavior');

                if (elephantCountInput) elephantCountInput.value = reportState.demographics.elephant_count || 1;
                if (evidenceSelect && reportState.sighting_evidence_type) {
                    evidenceSelect.value = reportState.sighting_evidence_type;
                }
                if (malesInput) malesInput.value = (reportState.demographics.adult_males !== null && reportState.demographics.adult_males !== undefined) ? reportState.demographics.adult_males : '';
                if (femalesInput) femalesInput.value = (reportState.demographics.adult_females !== null && reportState.demographics.adult_females !== undefined) ? reportState.demographics.adult_females : '';
                if (calvesInput) calvesInput.value = (reportState.demographics.calves !== null && reportState.demographics.calves !== undefined) ? reportState.demographics.calves : '';
                if (unclassInput) unclassInput.value = (reportState.demographics.unclassified !== null && reportState.demographics.unclassified !== undefined) ? reportState.demographics.unclassified : '';
                if (tuskerSelect && reportState.tusker_present) {
                    tuskerSelect.value = reportState.tusker_present;
                }
                if (behaviorSelect && reportState.elephant_behavior) {
                    behaviorSelect.value = reportState.elephant_behavior;
                }

                // 6. Populate Step 5 DOM Controls (Railway Association)
                var sectionSelect = document.getElementById('section_id');
                var milestoneInput = document.getElementById('nearest_milestone_ref');
                var proxSelect = document.getElementById('track_proximity');

                if (sectionSelect) {
                    sectionSelect.value = reportState.section_id !== undefined ? String(reportState.section_id) : '';
                }
                if (milestoneInput) {
                    milestoneInput.value = reportState.nearest_milestone_ref || '';
                }
                if (proxSelect && reportState.track_proximity) {
                    proxSelect.value = reportState.track_proximity;
                }

                // 7. Update Projection Previews & Sync Health Display
                recalculateAnimalPositionPreview();
                var syncUuid = document.getElementById('syncActiveUuid');
                if (syncUuid) syncUuid.innerText = reportState.client_report_uuid;

                // 8. Switch to Observation Tab and Route to Step 6 (Review & Submit)
                var tabButtons = document.querySelectorAll('.tab-btn');
                var tabContents = document.querySelectorAll('.tab-content');
                tabButtons.forEach(function (btn) {
                    if (btn.getAttribute('data-tab') === 'tabObservation') btn.classList.add('active');
                    else btn.classList.remove('active');
                });
                tabContents.forEach(function (cnt) {
                    if (cnt.id === 'tabObservation') cnt.classList.add('active');
                    else cnt.classList.remove('active');
                });

                window.EleGuardWizard.goToStep(6);
                self.closeModals();
                self.showToast('📂 Draft loaded: UUID ' + reportState.client_report_uuid.substring(0, 8) + '...', 'info');
            }).catch(function (err) {
                self.showToast('Failed to resume draft: ' + err.message, 'error');
            });
        },

        confirmDeleteDraft: function (uuid) {
            if (!confirm('Are you sure you want to delete this encrypted draft?')) return;
            var self = this;
            window.EleGuardStorage.deleteDraft(uuid).then(function () {
                self.showToast('🗑️ Draft deleted.', 'info');
                self.refreshStorageUI();
            }).catch(function (err) {
                self.showToast('Failed to delete: ' + err.message, 'error');
            });
        },

        confirmClearAllDrafts: function () {
            if (!confirm('Are you sure you want to permanently clear ALL offline drafts?')) return;
            var self = this;
            window.EleGuardStorage.clearAllDrafts().then(function (count) {
                self.showToast('🗑️ Cleared ' + count + ' draft(s).', 'info');
                self.refreshStorageUI();
            }).catch(function (err) {
                self.showToast('Failed to clear: ' + err.message, 'error');
            });
        },

        confirmResetStorage: function () {
            if (!confirm('WARNING: Resetting storage will destroy all local drafts and clear security keys.\nAre you sure you want to reset?')) return;
            var self = this;
            window.EleGuardStorage.resetStorage().then(function () {
                self.closeModals();
                self.showToast('Storage has been completely reset.', 'info');
                self.refreshStorageUI();
            }).catch(function (err) {
                self.showToast('Reset failed: ' + err.message, 'error');
            });
        }
    };

    /**
     * =========================================================================
     * 9B. REPORTER WORKSPACE & CLARIFICATION INTERFACE (Milestone 9C Stage 4E.4.3-G)
     * =========================================================================
     */
    window.EleGuardReporterWorkspace = {
        state: {
            currentFilter: 'all',
            currentPage: 1,
            perPage: 15,
            totalPages: 1,
            totalRecords: 0,
            statusCounts: {},
            activeReportId: null,
            isSubmitting: false
        },

        init: function () {
            var self = this;
            var pills = document.querySelectorAll ? document.querySelectorAll('.status-pill') : [];
            if (pills && pills.forEach) {
                pills.forEach(function (pill) {
                    if (pill && pill.addEventListener) {
                        pill.addEventListener('click', function () {
                            pills.forEach(function (p) { if (p && p.classList) p.classList.remove('active'); });
                            if (this.classList) this.classList.add('active');
                            self.state.currentFilter = (this.getAttribute && this.getAttribute('data-filter')) || 'all';
                            self.state.currentPage = 1;
                            self.loadReports();
                        });
                    }
                });
            }

            var prevBtn = document.getElementById('myReportsPrevPageBtn');
            var nextBtn = document.getElementById('myReportsNextPageBtn');
            if (prevBtn && prevBtn.addEventListener) {
                prevBtn.addEventListener('click', function () {
                    if (self.state.currentPage > 1) {
                        self.state.currentPage--;
                        self.loadReports();
                    }
                });
            }
            if (nextBtn && nextBtn.addEventListener) {
                nextBtn.addEventListener('click', function () {
                    if (self.state.currentPage < self.state.totalPages) {
                        self.state.currentPage++;
                        self.loadReports();
                    }
                });
            }

            // Network status updates for advisory banner
            function updateOfflineBanner() {
                var banner = document.getElementById('myReportsOfflineBanner');
                if (banner && banner.style) {
                    banner.style.display = (typeof navigator !== 'undefined' && navigator.onLine) ? 'none' : 'flex';
                }
            }
            if (typeof window !== 'undefined' && window.addEventListener) {
                window.addEventListener('online', updateOfflineBanner);
                window.addEventListener('offline', updateOfflineBanner);
            }
            updateOfflineBanner();

            // Initial silent fetch to update badge and counts
            if (typeof fetch === 'function') {
                this.checkActionBadge();
            }
        },

        checkActionBadge: function () {
            var self = this;
            var url = (window.ELEGUARD_API_MY_REPORTS || '/api/v2/reports/my') + '?page=1&per_page=1';
            fetch(url, { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    if (resp && resp.status === 'success' && resp.data && resp.data.meta) {
                        self.updateCounts(resp.data.meta.status_counts);
                    }
                })
                .catch(function () { /* Silent failure if offline */ });
        },

        updateCounts: function (counts) {
            if (!counts) return;
            this.state.statusCounts = counts;

            var setTxt = function (id, val) {
                var el = document.getElementById(id);
                if (el) el.textContent = val !== undefined ? val : 0;
            };

            setTxt('pillCountAll', counts.all);
            setTxt('pillCountNeedsInfo', counts.needs_information);
            setTxt('pillCountPending', counts.pending);
            setTxt('pillCountClarified', counts.clarification_submitted);
            setTxt('pillCountVerified', counts.verified);
            setTxt('pillCountRejected', counts.rejected);

            var actionBadge = document.getElementById('myReportsActionBadge');
            var needsInfoPill = document.querySelector ? document.querySelector('.status-pill.pill-needs-info') : null;
            if (counts.needs_information > 0) {
                if (actionBadge && actionBadge.style) {
                    actionBadge.textContent = '!';
                    actionBadge.style.display = 'inline-block';
                }
                if (needsInfoPill && needsInfoPill.classList) {
                    needsInfoPill.classList.add('has-alerts');
                }
            } else {
                if (actionBadge && actionBadge.style) actionBadge.style.display = 'none';
                if (needsInfoPill && needsInfoPill.classList) needsInfoPill.classList.remove('has-alerts');
            }
        },

        loadReports: function () {
            var self = this;
            var container = document.getElementById('myReportsListContainer');
            if (!container) return;

            container.innerHTML = '<div class="sighting-loading-state"><span class="loading-spinner"></span><p>Loading your sighting reports...</p></div>';

            var base = window.ELEGUARD_API_MY_REPORTS || '/api/v2/reports/my';
            var query = '?page=' + this.state.currentPage + '&per_page=' + this.state.perPage;
            if (this.state.currentFilter && this.state.currentFilter !== 'all') {
                query += '&status=' + encodeURIComponent(this.state.currentFilter);
            }

            fetch(base + query, { credentials: 'same-origin' })
                .then(function (res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(function (resp) {
                    if (resp && resp.status === 'success' && resp.data) {
                        self.renderCards(resp.data.reports || []);
                        if (resp.data.meta) {
                            self.state.totalRecords = resp.data.meta.total_records || 0;
                            self.state.totalPages = resp.data.meta.total_pages || 1;
                            self.updateCounts(resp.data.meta.status_counts);
                            self.renderPagination(resp.data.meta);
                        }
                    } else {
                        throw new Error(resp.detail || 'Unable to load sightings.');
                    }
                })
                .catch(function (err) {
                    container.innerHTML = '<div class="sighting-empty-state"><p>⚠️ Unable to load reports: ' + self.escapeHtml(err.message) + '</p><button type="button" class="btn btn-secondary btn-sm" onclick="window.EleGuardReporterWorkspace.loadReports()" style="margin-top:10px;">Retry</button></div>';
                });
        },

        renderCards: function (reports) {
            var self = this;
            var container = document.getElementById('myReportsListContainer');
            if (!container) return;

            if (!reports || reports.length === 0) {
                container.innerHTML = '<div class="sighting-empty-state"><p>No sighting reports found for this filter.</p></div>';
                return;
            }

            var html = '';
            reports.forEach(function (r) {
                var statusClass = 'status-' + (r.report_status || '').toLowerCase().replace(/\s+/g, '-');
                var statusLabel = self.escapeHtml(r.report_status || 'Pending');

                var badgeBg = '#64748b';
                if (r.report_status === 'Pending') badgeBg = '#f59e0b';
                else if (r.report_status === 'Needs Information') badgeBg = '#f97316';
                else if (r.report_status === 'Clarification Submitted') badgeBg = '#0284c7';
                else if (r.report_status === 'Verified') badgeBg = '#10b981';
                else if (r.report_status === 'Rejected') badgeBg = '#ef4444';

                var dateStr = r.sighting_date || 'Unknown Date';
                var timeStr = r.sighting_time ? r.sighting_time.substring(0, 5) : '';
                var sectionName = r.section_name ? self.escapeHtml(r.section_name) : 'Unassigned Corridor';
                var milestone = r.nearest_milestone_ref ? (' • ' + self.escapeHtml(r.nearest_milestone_ref)) : '';

                html += '<div class="sighting-card ' + statusClass + '" id="sightingCard-' + r.id + '">';
                html += '  <div class="sighting-card-header">';
                html += '    <div class="sighting-card-title">';
                html += '      <span>🐘 Sighting #' + r.id + '</span>';
                html += '      <span style="font-size: 11px; font-weight: normal; color: var(--text-muted);">' + self.escapeHtml(r.observation_category || 'Wild Herd') + '</span>';
                html += '    </div>';
                html += '    <span class="status-pill active" style="background:' + badgeBg + '; border-color:' + badgeBg + '; font-size:11px; padding:3px 10px;">' + statusLabel + '</span>';
                html += '  </div>';

                html += '  <div class="sighting-meta-grid">';
                html += '    <div class="sighting-meta-item">📅 Sighting: <strong>' + self.escapeHtml(dateStr) + ' ' + self.escapeHtml(timeStr) + '</strong></div>';
                html += '    <div class="sighting-meta-item">🔢 Count: <strong>' + (parseInt(r.elephant_count, 10) || 1) + ' Elephant(s)</strong></div>';
                html += '    <div class="sighting-meta-item">🛤️ Section: <strong>' + sectionName + milestone + '</strong></div>';
                html += '    <div class="sighting-meta-item">⚡ Urgency: <strong>' + self.escapeHtml(r.urgency_level || 'Medium') + '</strong></div>';
                html += '  </div>';

                if (r.report_status === 'Needs Information') {
                    html += '  <div class="inquiry-alert-callout">';
                    html += '    <span>⚠️ <strong>Action Required:</strong> Wildlife Officer requested clarifying details.</span>';
                    html += '    <button type="button" class="btn btn-primary btn-sm" onclick="window.EleGuardReporterWorkspace.openReportDetails(' + r.id + ')">💬 Reply Now</button>';
                    html += '  </div>';
                }

                html += '  <div class="sighting-card-footer">';
                html += '    <span style="font-size: 11px; color: var(--text-muted);">Filed: ' + self.escapeHtml(r.created_at || '') + '</span>';
                html += '    <button type="button" class="btn btn-secondary btn-sm" onclick="window.EleGuardReporterWorkspace.openReportDetails(' + r.id + ')">🔍 View Details &amp; History ➔</button>';
                html += '  </div>';
                html += '</div>';
            });

            container.innerHTML = html;
        },

        renderPagination: function (meta) {
            var pagBar = document.getElementById('myReportsPagination');
            var prevBtn = document.getElementById('myReportsPrevPageBtn');
            var nextBtn = document.getElementById('myReportsNextPageBtn');
            var pageInfo = document.getElementById('myReportsPageInfo');

            if (!pagBar || !meta) return;

            if (meta.total_pages <= 1) {
                pagBar.style.display = 'none';
                return;
            }

            pagBar.style.display = 'flex';
            if (prevBtn) prevBtn.disabled = (meta.current_page <= 1);
            if (nextBtn) nextBtn.disabled = (meta.current_page >= meta.total_pages);
            if (pageInfo) pageInfo.textContent = 'Page ' + meta.current_page + ' of ' + meta.total_pages + ' (' + meta.total_records + ' sightings)';
        },

        openReportDetails: function (reportId) {
            var self = this;
            this.state.activeReportId = reportId;

            var modal = document.getElementById('sightingDetailModal');
            var body = document.getElementById('sightingModalBody');
            var title = document.getElementById('sightingModalTitle');
            var badge = document.getElementById('sightingModalStatusBadge');

            if (!modal || !body) return;

            modal.style.display = 'flex';
            if (title) title.textContent = 'Sighting #' + reportId;
            body.innerHTML = '<div class="sighting-loading-state"><span class="loading-spinner"></span><p>Loading observation and verification history...</p></div>';

            var historyUrl = (window.ELEGUARD_API_REPORTS_BASE || '/api/v2/reports') + '/' + reportId + '/history';

            fetch(historyUrl, { credentials: 'same-origin' })
                .then(function (res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(function (resp) {
                    if (resp && resp.status === 'success' && resp.data) {
                        self.renderReportDetails(resp.data);
                    } else {
                        throw new Error(resp.detail || 'Failed to load report history.');
                    }
                })
                .catch(function (err) {
                    body.innerHTML = '<div class="sighting-empty-state"><p>⚠️ Failed to load details: ' + self.escapeHtml(err.message) + '</p></div>';
                });
        },

        renderReportDetails: function (data) {
            var self = this;
            var body = document.getElementById('sightingModalBody');
            var badge = document.getElementById('sightingModalStatusBadge');
            if (!body || !data) return;

            var r = data.report || {};
            var verifs = data.verifications || [];
            var clarifies = data.clarifications || [];

            if (badge) {
                badge.textContent = r.report_status || 'Pending';
                var badgeBg = '#64748b';
                if (r.report_status === 'Pending') badgeBg = '#f59e0b';
                else if (r.report_status === 'Needs Information') badgeBg = '#f97316';
                else if (r.report_status === 'Clarification Submitted') badgeBg = '#0284c7';
                else if (r.report_status === 'Verified') badgeBg = '#10b981';
                else if (r.report_status === 'Rejected') badgeBg = '#ef4444';
                badge.style.background = badgeBg;
                badge.style.borderColor = badgeBg;
            }

            var html = '';

            // Section 1: Observation Summary
            html += '<div style="background: var(--bg-surface-elevated, #273549); border-radius: 8px; padding: 14px; margin-bottom: 16px;">';
            html += '  <h4 style="font-size: 14px; font-weight: 700; color: #f8fafc; margin-bottom: 10px; display: flex; justify-content: space-between;">';
            html += '    <span>📋 Field Observation Summary</span>';
            html += '    <span style="font-size: 11px; color: #94a3b8; font-family: monospace;">UUID: ' + self.escapeHtml((r.client_report_uuid || '').substring(0, 13)) + '...</span>';
            html += '  </h4>';
            html += '  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; font-size: 13px;">';
            html += '    <div>Observation: <strong>' + self.escapeHtml(r.sighting_date || '') + ' ' + self.escapeHtml(r.sighting_time || '') + '</strong></div>';
            html += '    <div>Time Precision: <strong>' + self.escapeHtml(r.sighting_time_precision || 'exact') + '</strong></div>';
            html += '    <div>Elephants: <strong>' + (parseInt(r.elephant_count, 10) || 1) + '</strong></div>';
            html += '    <div>Urgency: <strong>' + self.escapeHtml(r.urgency_level || 'Medium') + '</strong></div>';
            html += '    <div>Section: <strong>' + self.escapeHtml(r.section_name || 'Unassigned') + '</strong></div>';
            html += '    <div>Milestone: <strong>' + self.escapeHtml(r.nearest_milestone_ref || 'N/A') + '</strong></div>';
            html += '  </div>';

            if (r.description) {
                html += '  <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.06); font-size: 13px; color: #cbd5e1;">';
                html += '    <strong>Field Notes:</strong> ' + self.escapeHtml(r.description);
                html += '  </div>';
            }

            // Anti-poaching coordinate privacy notice
            html += '  <div style="margin-top: 10px; font-size: 11.5px; color: #94a3b8; background: rgba(0,0,0,0.2); padding: 6px 10px; border-radius: 4px;">';
            html += '    🔒 <em>Anti-Poaching Protection: Animal coordinates are blurred (~1.1 km) to safeguard endangered wildlife.</em>';
            html += '  </div>';
            html += '</div>';

            // Section 2: Verification / Corroboration Outcome (if Verified or Rejected)
            if (r.report_status === 'Verified') {
                var lastV = verifs.length > 0 ? verifs[0] : null;
                html += '<div class="outcome-banner-verified">';
                html += '  <strong>✔️ Biological Verification Complete</strong><br>';
                html += '  This observation was corroborated by an authorized Wildlife Officer';
                if (lastV && lastV.verification_method) html += ' using <strong>' + self.escapeHtml(lastV.verification_method.replace(/_/g, ' ')) + '</strong>';
                if (lastV && lastV.verified_at) html += ' on ' + self.escapeHtml(lastV.verified_at);
                if (lastV && lastV.notes) html += '<br><em>Officer Notes: "' + self.escapeHtml(lastV.notes) + '"</em>';
                html += '</div>';
            } else if (r.report_status === 'Rejected') {
                var lastR = verifs.length > 0 ? verifs[0] : null;
                html += '<div class="outcome-banner-rejected">';
                html += '  <strong>❌ Observation Rejected by Wildlife Officer</strong><br>';
                if (lastR && lastR.notes) html += 'Reason: "' + self.escapeHtml(lastR.notes) + '"<br>';
                if (lastR && lastR.verified_at) html += 'Review Date: ' + self.escapeHtml(lastR.verified_at);
                html += '</div>';
            }

            // Section 3: Clarification Conversation Thread
            html += '<h4 style="font-size: 14px; font-weight: 700; color: #f8fafc; margin-top: 18px; margin-bottom: 8px;">';
            html += '  💬 Clarification Communication Thread';
            html += '</h4>';

            html += '<div class="clarification-thread" id="clarificationThreadBox">';
            if (!clarifies || clarifies.length === 0) {
                html += '<p style="color: var(--text-muted); font-size: 13px; text-align: center; padding: 20px;">No clarification inquiries have been raised for this observation.</p>';
            } else {
                clarifies.forEach(function (msg) {
                    var isOfficer = (msg.message_type === 'officer_inquiry');
                    var bubbleClass = isOfficer ? 'bubble-officer' : 'bubble-reporter';
                    var senderTag = isOfficer ? '👮 Wildlife Officer' : '👤 You (Observer)';
                    var senderName = msg.sender_name ? (' • ' + self.escapeHtml(msg.sender_name)) : '';

                    html += '<div class="clarification-bubble ' + bubbleClass + '">';
                    html += '  <div class="bubble-header">';
                    html += '    <span class="bubble-badge">' + senderTag + senderName + '</span>';
                    html += '    <span class="bubble-time">' + self.escapeHtml(msg.created_at || '') + '</span>';
                    html += '  </div>';
                    if (msg.parent_clarification_id) {
                        html += '  <div class="bubble-parent-ref">↳ In reply to prior inquiry</div>';
                    }
                    html += '  <div class="bubble-message-text">' + self.escapeHtml(msg.message_text || '') + '</div>';
                    html += '</div>';
                });
            }
            html += '</div>';

            // Section 4: Clarification Response Input Panel (when Needs Information)
            if (r.report_status === 'Needs Information') {
                var lastOfficerMsg = null;
                for (var i = clarifies.length - 1; i >= 0; i--) {
                    if (clarifies[i].message_type === 'officer_inquiry') {
                        lastOfficerMsg = clarifies[i];
                        break;
                    }
                }
                var parentId = lastOfficerMsg ? lastOfficerMsg.id : '';

                html += '<div class="clarification-form-panel">';
                html += '  <label for="clarificationReplyInput">✍️ Provide Clarifying Information</label>';
                html += '  <p style="font-size: 12px; color: var(--text-secondary); margin-bottom: 8px;">';
                html += '    Please describe landmark proximity, herd travel direction, or any additional context requested by the Wildlife Officer.';
                html += '  </p>';
                html += '  <textarea id="clarificationReplyInput" class="clarification-textarea" placeholder="Type your reply here (e.g. The herd crossed 50m north of MP 142 toward the waterbody...)" maxlength="5000"></textarea>';
                html += '  <div class="clarification-form-footer">';
                html += '    <span id="clarificationCharCounter" style="font-size: 12px; color: var(--text-muted);">0 / 5,000 characters</span>';
                html += '    <button type="button" class="btn btn-primary" id="btnSubmitClarification" onclick="window.EleGuardReporterWorkspace.submitReply(' + r.id + ', ' + (parentId || 'null') + ')">';
                html += '      📨 Send Response to Officer';
                html += '    </button>';
                html += '  </div>';
                html += '  <div id="clarificationErrorMsg" style="color: #ef4444; font-size: 12px; margin-top: 6px; display: none;"></div>';
                html += '</div>';
            }

            body.innerHTML = html;

            // Bind live character counter if input exists
            var textarea = document.getElementById('clarificationReplyInput');
            var counter = document.getElementById('clarificationCharCounter');
            if (textarea && counter) {
                textarea.addEventListener('input', function () {
                    counter.textContent = this.value.length + ' / 5,000 characters';
                });
            }

            // Scroll thread to bottom
            var threadBox = document.getElementById('clarificationThreadBox');
            if (threadBox) {
                threadBox.scrollTop = threadBox.scrollHeight;
            }
        },

        submitReply: function (reportId, parentClarificationId) {
            var self = this;
            if (this.state.isSubmitting) return;

            var textarea = document.getElementById('clarificationReplyInput');
            var errorBox = document.getElementById('clarificationErrorMsg');
            var submitBtn = document.getElementById('btnSubmitClarification');

            if (!textarea) return;
            var text = textarea.value.trim();

            if (!navigator.onLine) {
                if (errorBox) {
                    errorBox.textContent = '⚠️ Cannot submit reply while offline. Please connect to a cellular network.';
                    errorBox.style.display = 'block';
                }
                return;
            }

            if (!text || text.length === 0) {
                if (errorBox) {
                    errorBox.textContent = 'Please enter a response before submitting.';
                    errorBox.style.display = 'block';
                }
                textarea.focus();
                return;
            }

            if (text.length > 5000) {
                if (errorBox) {
                    errorBox.textContent = 'Response exceeds the maximum allowed length of 5,000 characters.';
                    errorBox.style.display = 'block';
                }
                return;
            }

            if (errorBox) errorBox.style.display = 'none';

            // Double submission lock
            this.state.isSubmitting = true;
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="loading-spinner"></span> Submitting response...';
            }

            var clarifyUrl = (window.ELEGUARD_API_REPORTS_BASE || '/api/v2/reports') + '/' + reportId + '/clarify';
            var payload = {
                reply_text: text,
                parent_clarification_id: parentClarificationId ? parseInt(parentClarificationId, 10) : null,
                csrf_token: window.ELEGUARD_CSRF_TOKEN || ''
            };

            fetch(clarifyUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            })
                .then(function (res) {
                    return res.json().then(function (json) {
                        return { ok: res.ok, status: res.status, json: json };
                    });
                })
                .then(function (result) {
                    self.state.isSubmitting = false;
                    if (result.ok && result.json && result.json.status === 'success') {
                        if (window.EleGuardStorageUI && window.EleGuardStorageUI.showToast) {
                            window.EleGuardStorageUI.showToast('✅ Response successfully submitted to Wildlife Officer.', 'info');
                        }
                        // Refresh details in modal
                        self.openReportDetails(reportId);
                        // Refresh background cards list & counts
                        self.loadReports();
                    } else {
                        var errDetail = (result.json && (result.json.detail || result.json.message)) || ('Error ' + result.status);
                        if (errorBox) {
                            errorBox.textContent = 'Submission failed: ' + errDetail;
                            errorBox.style.display = 'block';
                        }
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '📨 Send Response to Officer';
                        }
                    }
                })
                .catch(function (err) {
                    self.state.isSubmitting = false;
                    if (errorBox) {
                        errorBox.textContent = 'Network error: ' + err.message;
                        errorBox.style.display = 'block';
                    }
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '📨 Send Response to Officer';
                    }
                });
        },

        closeModal: function () {
            var modal = document.getElementById('sightingDetailModal');
            if (modal) modal.style.display = 'none';
            this.state.activeReportId = null;
            this.state.isSubmitting = false;
        },

        escapeHtml: function (str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    };

    /* =========================================================================
     * 10. EVENT BINDINGS & DOM INITIALIZATION
     * ========================================================================= */
    document.addEventListener('DOMContentLoaded', function () {
        initServiceWorker();
        updateNetworkStatus();

        // Initialize Tab Navigation
        var tabButtons = document.querySelectorAll('.tab-btn');
        var tabContents = document.querySelectorAll('.tab-content');
        tabButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var targetTab = this.getAttribute('data-tab');
                tabButtons.forEach(function (btn) { btn.classList.remove('active'); });
                tabContents.forEach(function (content) { content.classList.remove('active'); });
                this.classList.add('active');
                var activeContent = document.getElementById(targetTab);
                if (activeContent) activeContent.classList.add('active');
                if (targetTab === 'tabDrafts' && window.EleGuardStorageUI) {
                    window.EleGuardStorageUI.refreshStorageUI();
                }
                if (targetTab === 'tabMyReports' && window.EleGuardReporterWorkspace) {
                    window.EleGuardReporterWorkspace.loadReports();
                }
            });
        });

        // Initialize Encrypted Storage Controller
        if (window.EleGuardStorageUI) {
            window.EleGuardStorageUI.init();
        }

        // Initialize Stepper Pill Clicks
        var stepPills = document.querySelectorAll('.step-indicator-pill');
        stepPills.forEach(function (pill) {
            pill.addEventListener('click', function () {
                var stepNum = parseInt(this.getAttribute('data-step'), 10);
                window.EleGuardWizard.goToStep(stepNum);
            });
        });

        // Geolocation Button
        var gpsBtn = document.getElementById('acquireGpsBtn');
        if (gpsBtn) {
            gpsBtn.addEventListener('click', acquireObserverGPS);
        }

        // Unknown Time Toggle
        var timeUnknownCheck = document.getElementById('time_unknown');
        var timeInput = document.getElementById('observation_time');
        if (timeUnknownCheck && timeInput) {
            timeUnknownCheck.addEventListener('change', function () {
                timeInput.disabled = this.checked;
                if (this.checked) {
                    timeInput.value = '';
                }
            });
        }

        // Diurnal Buttons
        var diurnalButtons = document.querySelectorAll('.diurnal-btn');
        diurnalButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var period = this.getAttribute('data-period');
                selectDiurnalButton(period);
            });
        });

        // Clock auto-classification
        if (timeInput) {
            timeInput.addEventListener('change', function () {
                if (this.value) {
                    var detected = classifyDiurnalPeriod(this.value);
                    selectDiurnalButton(detected);
                }
            });

            // Set current time as initial helper
            var now = new Date();
            var hours = String(now.getHours()).padStart(2, '0');
            var minutes = String(now.getMinutes()).padStart(2, '0');
            timeInput.value = hours + ':' + minutes;
            selectDiurnalButton(classifyDiurnalPeriod(timeInput.value));
        }

        // Set today's date default
        var dateInput = document.getElementById('observation_date');
        if (dateInput && !dateInput.value) {
            dateInput.value = new Date().toISOString().split('T')[0];
            reportState.temporal.sighting_date = dateInput.value;
        }

        // Unknown Animal Location Toggle
        var animalUnknownCheck = document.getElementById('animal_coords_unknown');
        var animalOffsetSec = document.getElementById('animal_offset_section');
        if (animalUnknownCheck && animalOffsetSec) {
            animalUnknownCheck.addEventListener('change', function () {
                reportState.animal_location.unknown = this.checked;
                animalOffsetSec.style.opacity = this.checked ? '0.4' : '1.0';
                animalOffsetSec.style.pointerEvents = this.checked ? 'none' : 'auto';
                recalculateAnimalPositionPreview();
            });
        }

        // Distance & Bearing Inputs
        var distInput = document.getElementById('observation_distance');
        var bearingInput = document.getElementById('observation_bearing');
        var uncInput = document.getElementById('animal_uncertainty');

        function onOffsetChange() {
            syncStepInputsToState(3);
            recalculateAnimalPositionPreview();
        }

        if (distInput) distInput.addEventListener('input', onOffsetChange);
        if (bearingInput) bearingInput.addEventListener('input', onOffsetChange);
        if (uncInput) uncInput.addEventListener('input', onOffsetChange);

        // Direction Cardinal Buttons
        var dirButtons = document.querySelectorAll('.dir-btn');
        dirButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                dirButtons.forEach(function (b) { b.classList.remove('active'); });
                this.classList.add('active');
                var bearingVal = this.getAttribute('data-bearing');
                if (bearingInput) bearingInput.value = bearingVal;
                onOffsetChange();
            });
        });

        // Demographic Count Auto-Summing
        var countInputs = document.querySelectorAll('.count-calc-input');
        var elephantCountInput = document.getElementById('elephant_count');
        var autoSumMsg = document.getElementById('autoSumMsg');

        countInputs.forEach(function (inp) {
            inp.addEventListener('input', function () {
                var males = parseInt(document.getElementById('adult_males').value, 10) || 0;
                var females = parseInt(document.getElementById('adult_females').value, 10) || 0;
                var calves = parseInt(document.getElementById('calves').value, 10) || 0;
                var unclass = parseInt(document.getElementById('unclassified_count').value, 10) || 0;
                var sum = males + females + calves + unclass;

                if (sum > 0 && elephantCountInput) {
                    elephantCountInput.value = sum;
                    reportState.demographics.elephant_count = sum;
                    if (autoSumMsg) autoSumMsg.innerHTML = '<span style="color: #38bdf8;">✓ Auto-summed from demographic breakdown: ' + sum + ' elephants</span>';
                }
            });
        });

        // Initialize Reporter Workspace
        if (window.EleGuardReporterWorkspace) {
            window.EleGuardReporterWorkspace.init();
        }

        // Set initial step 1
        window.EleGuardWizard.goToStep(1);
    });

})();
