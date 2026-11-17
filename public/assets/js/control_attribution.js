/**
 * EleGuard Rail — Control Officer Railway Section Attribution Interface
 * 
 * Milestone 9C — Stage 4E.4.3-H2: Frontend Controller & GIS Review Map
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const panelEl = document.getElementById('attribution-panel');
        if (!panelEl) return;

        // 1. Extract configuration and state from DOM
        const reportId = parseInt(panelEl.getAttribute('data-report-id'), 10);
        let currentSectionId = parseInt(panelEl.getAttribute('data-current-section-id'), 10);
        let currentSectionName = panelEl.getAttribute('data-current-section-name') || '';
        
        const latStr = panelEl.getAttribute('data-lat');
        const lonStr = panelEl.getAttribute('data-lon');
        const lat = latStr !== '' && latStr !== 'null' ? parseFloat(latStr) : null;
        const lon = lonStr !== '' && lonStr !== 'null' ? parseFloat(lonStr) : null;

        const closestLatStr = panelEl.getAttribute('data-closest-lat');
        const closestLonStr = panelEl.getAttribute('data-closest-lon');
        const closestLat = closestLatStr !== '' && closestLatStr !== 'null' ? parseFloat(closestLatStr) : null;
        const closestLon = closestLonStr !== '' && closestLonStr !== 'null' ? parseFloat(closestLonStr) : null;

        const distMetersStr = panelEl.getAttribute('data-distance-meters');
        const distMeters = distMetersStr !== '' && distMetersStr !== 'null' ? parseFloat(distMetersStr) : null;

        const csrfToken = panelEl.getAttribute('data-csrf-token') || '';
        const associateUrl = panelEl.getAttribute('data-associate-url') || '';
        const historyUrl = panelEl.getAttribute('data-history-url') || '';
        const geojsonUrl = panelEl.getAttribute('data-geojson-url') || '';

        // UI references
        const modalOverlay = document.getElementById('attribution-modal-overlay');
        const modalCloseBtn = document.getElementById('attribution-modal-close');
        const modalCancelBtn = document.getElementById('attribution-modal-cancel');
        const modalSubmitBtn = document.getElementById('attribution-modal-submit');
        const modalForm = document.getElementById('attribution-modal-form');
        const modalErrorBox = document.getElementById('attribution-modal-error');
        const modalReasonInput = document.getElementById('attribution-modal-reason');
        const modalMethodInput = document.getElementById('attribution-modal-method');
        const modalSectionSelect = document.getElementById('attribution-modal-section');
        const modalCharCount = document.getElementById('attribution-modal-char-count');
        const modalCurrentSecLabel = document.getElementById('attribution-modal-current-sec');

        const btnReassign = document.getElementById('btn-reassign-corridor');
        const btnConfirm = document.getElementById('btn-confirm-attribution');
        const activeSectionBadge = document.getElementById('current-section-badge');
        const activeSectionDisplay = document.getElementById('current-section-display');
        const historyContainer = document.getElementById('attribution-history-container');
        const successToast = document.getElementById('attribution-success-toast');

        let leafletMap = null;
        let geoJsonLayer = null;
        let connectorLine = null;
        let isSubmitting = false;

        // =========================================================================
        // 2. Leaflet GIS Review Map Initialization
        // =========================================================================
        function initGisMap() {
            const mapContainer = document.getElementById('attribution-gis-map');
            if (!mapContainer || typeof L === 'undefined') return;

            // Default center: Sri Lanka North-Central (Habarana area)
            const defaultCenter = (lat !== null && lon !== null) ? [lat, lon] : [8.033, 80.750];
            const defaultZoom = (lat !== null && lon !== null) ? 13 : 10;

            leafletMap = L.map('attribution-gis-map', {
                center: defaultCenter,
                zoom: defaultZoom,
                zoomControl: true
            });

            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors (ODbL 1.0) | HOT OSM HDX | EleGuard Rail'
            }).addTo(leafletMap);

            const boundsGroup = [];

            // Add Sighting Marker if coordinates exist
            if (lat !== null && lon !== null) {
                const elephantMarker = L.circleMarker([lat, lon], {
                    radius: 9,
                    fillColor: '#f59e0b',
                    color: '#ffffff',
                    weight: 2,
                    opacity: 1,
                    fillOpacity: 0.95
                }).addTo(leafletMap);

                elephantMarker.bindPopup(
                    '<strong>🐘 Sighting Observation</strong><br>' +
                    'Latitude: ' + lat.toFixed(5) + '<br>' +
                    'Longitude: ' + lon.toFixed(5) + '<br>' +
                    '<span style="font-size:0.75rem; color:#f59e0b; font-weight:bold;">PROVISIONAL — NOT OFFICIALLY VERIFIED</span>'
                );

                boundsGroup.push([lat, lon]);

                // Draw connector to nearest track point if available
                if (closestLat !== null && closestLon !== null) {
                    connectorLine = L.polyline([[lat, lon], [closestLat, closestLon]], {
                        color: '#ef4444',
                        weight: 2.5,
                        dashArray: '5, 6',
                        opacity: 0.9
                    }).addTo(leafletMap);

                    const distText = distMeters !== null ? Math.round(distMeters) + ' m' : 'Estimated';
                    connectorLine.bindTooltip(
                        '<strong>Track Distance: ' + distText + '</strong><br>' +
                        '<span style="font-size:0.7rem; color:#fbbf24;">PROVISIONAL — NOT OFFICIALLY VERIFIED</span>',
                        { sticky: true }
                    );

                    boundsGroup.push([closestLat, closestLon]);
                }
            }

            // Fetch and render Railway Track Polylines
            if (geojsonUrl) {
                fetch(geojsonUrl)
                    .then(response => {
                        if (!response.ok) throw new Error('Failed to load GeoJSON');
                        return response.json();
                    })
                    .then(data => {
                        geoJsonLayer = L.geoJSON(data, {
                            style: function (feature) {
                                const secId = parseInt(feature.properties.section_id, 10);
                                if (secId === currentSectionId) {
                                    return {
                                        color: '#10b981',
                                        weight: 6,
                                        opacity: 0.95
                                    };
                                }
                                return {
                                    color: '#475569',
                                    weight: 3.5,
                                    opacity: 0.65
                                };
                            },
                            onEachFeature: function (feature, layer) {
                                const p = feature.properties;
                                const secId = parseInt(p.section_id, 10);
                                layer.bindPopup(
                                    '<strong>Section #' + secId + ': ' + escapeHtml(p.section_name) + '</strong><br>' +
                                    'District: ' + escapeHtml(p.district) + '<br>' +
                                    'Length: ' + (p.track_length_km || 'N/A') + ' km<br>' +
                                    'Risk Rating: ' + escapeHtml(p.risk_level) + '<br>' +
                                    '<span style="font-size:0.72rem; color:#fbbf24;">PROVISIONAL — NOT OFFICIALLY VERIFIED</span>'
                                );
                            }
                        }).addTo(leafletMap);

                        if (boundsGroup.length > 0) {
                            leafletMap.fitBounds(L.latLngBounds(boundsGroup), { padding: [40, 40], maxZoom: 15 });
                        } else if (geoJsonLayer) {
                            leafletMap.fitBounds(geoJsonLayer.getBounds(), { padding: [30, 30] });
                        }
                    })
                    .catch(err => {
                        console.warn('[EleGuard GIS] Could not load railway GeoJSON routes:', err);
                    });
            }
        }

        // Update GeoJSON polyline highlighting when section is reassigned
        function updateMapHighlight(newSecId) {
            if (!geoJsonLayer) return;
            geoJsonLayer.eachLayer(function (layer) {
                if (layer.feature && layer.feature.properties) {
                    const secId = parseInt(layer.feature.properties.section_id, 10);
                    if (secId === newSecId) {
                        layer.setStyle({
                            color: '#10b981',
                            weight: 6,
                            opacity: 0.95
                        });
                        layer.bringToFront();
                    } else {
                        layer.setStyle({
                            color: '#475569',
                            weight: 3.5,
                            opacity: 0.65
                        });
                    }
                }
            });
        }

        // =========================================================================
        // 3. Modal Dialog Management
        // =========================================================================
        function openModal(defaultTargetSecId, defaultReason, defaultMethod) {
            if (!modalOverlay) return;
            hideModalError();

            if (modalCurrentSecLabel) {
                modalCurrentSecLabel.textContent = currentSectionName + ' (#' + currentSectionId + ')';
            }

            if (modalSectionSelect && defaultTargetSecId) {
                modalSectionSelect.value = defaultTargetSecId;
            }

            if (modalReasonInput) {
                modalReasonInput.value = defaultReason || '';
                updateCharCount();
            }

            if (modalMethodInput && defaultMethod) {
                modalMethodInput.value = defaultMethod;
            }

            modalOverlay.classList.add('active');
            if (modalReasonInput) {
                modalReasonInput.focus();
            }
        }

        function closeModal() {
            if (!modalOverlay || isSubmitting) return;
            modalOverlay.classList.remove('active');
            hideModalError();
        }

        function showModalError(msg) {
            if (!modalErrorBox) return;
            modalErrorBox.textContent = msg;
            modalErrorBox.style.display = 'block';
        }

        function hideModalError() {
            if (!modalErrorBox) return;
            modalErrorBox.textContent = '';
            modalErrorBox.style.display = 'none';
        }

        function updateCharCount() {
            if (!modalReasonInput || !modalCharCount) return;
            const len = modalReasonInput.value.trim().length;
            modalCharCount.textContent = len + ' / 5 characters minimum';
            if (len >= 5) {
                modalCharCount.style.color = '#10b981';
            } else {
                modalCharCount.style.color = '#f59e0b';
            }
        }

        // =========================================================================
        // 4. Asynchronous API Section Attribution Submission
        // =========================================================================
        function handleAttributionSubmit(e) {
            if (e) e.preventDefault();
            if (isSubmitting) return;

            hideModalError();

            const targetSecId = parseInt(modalSectionSelect ? modalSectionSelect.value : currentSectionId, 10);
            const reason = modalReasonInput ? modalReasonInput.value.trim() : '';
            const method = modalMethodInput ? modalMethodInput.value : 'manual_controller_override';

            if (isNaN(targetSecId) || targetSecId <= 0) {
                showModalError('Please select a valid railway section.');
                return;
            }

            if (reason.length < 5) {
                showModalError('Operational justification reason must be at least 5 characters.');
                if (modalReasonInput) modalReasonInput.focus();
                return;
            }

            // Determine assignment type
            const assignmentType = (targetSecId === currentSectionId) 
                ? 'controller_confirmation' 
                : 'subsequent_reassignment';

            const payload = {
                report_id: reportId,
                section_id: targetSecId,
                reason: reason,
                assignment_type: assignmentType,
                assignment_method: method,
                csrf_token: csrfToken
            };

            setSubmittingState(true);

            fetch(associateUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(async response => {
                const isJson = (response.headers.get('content-type') || '').includes('application/json');
                const data = isJson ? await response.json() : null;

                if (!response.ok) {
                    let errMsg = 'Failed to record section attribution (HTTP ' + response.status + ').';
                    if (data && data.detail) {
                        errMsg = data.detail;
                    } else if (response.status === 401) {
                        errMsg = 'Session expired or unauthenticated. Please refresh and log in.';
                    } else if (response.status === 403) {
                        errMsg = 'Forbidden: Only Railway Control Officers or Administrators can confirm attribution.';
                    } else if (response.status === 409) {
                        errMsg = 'Conflict: Sighting was modified concurrently. Please reload the page.';
                    } else if (response.status === 422) {
                        errMsg = (data && data.detail) ? data.detail : 'Validation failure. Check inputs.';
                    } else if (response.status === 500) {
                        errMsg = 'Internal database transaction error. Changes were rolled back.';
                    }
                    throw new Error(errMsg);
                }

                return data;
            })
            .then(res => {
                setSubmittingState(false);
                closeModal();

                // Update local state
                const resData = res.data || {};
                currentSectionId = resData.confirmed_section_id || targetSecId;
                currentSectionName = resData.section_name || (modalSectionSelect ? modalSectionSelect.options[modalSectionSelect.selectedIndex].text : '');

                // Update DOM elements
                if (activeSectionDisplay) {
                    activeSectionDisplay.textContent = currentSectionName;
                }

                if (activeSectionBadge) {
                    activeSectionBadge.className = 'badge-confirmed';
                    activeSectionBadge.innerHTML = '✔ CONTROLLER CONFIRMED';
                }

                // Update Map Highlight
                updateMapHighlight(currentSectionId);

                // Show success toast
                showSuccessNotification('Railway Corridor Attribution Confirmed: ' + currentSectionName);

                // Refresh History Ledger Asynchronously
                fetchAndRenderHistory();
            })
            .catch(err => {
                setSubmittingState(false);
                showModalError(err.message || 'An unexpected network error occurred.');
            });
        }

        function setSubmittingState(submitting) {
            isSubmitting = submitting;
            if (modalSubmitBtn) {
                modalSubmitBtn.disabled = submitting;
                if (submitting) {
                    modalSubmitBtn.innerHTML = '<span class="btn-spinner"></span> Saving...';
                } else {
                    modalSubmitBtn.innerHTML = 'Confirm Attribution';
                }
            }
            if (modalCancelBtn) modalCancelBtn.disabled = submitting;
            if (modalCloseBtn) modalCloseBtn.disabled = submitting;
        }

        // =========================================================================
        // 5. Assignment History Asynchronous Refresh
        // =========================================================================
        function fetchAndRenderHistory() {
            if (!historyUrl || !historyContainer) return;

            fetch(historyUrl, {
                headers: { 'Accept': 'application/json' }
            })
            .then(res => {
                if (!res.ok) throw new Error('Could not fetch updated audit history');
                return res.json();
            })
            .then(res => {
                if (!res.data || !res.data.section_assignments) return;
                renderHistoryTimeline(res.data.section_assignments);
            })
            .catch(err => {
                console.warn('[EleGuard Attribution] History refresh failed:', err);
            });
        }

        function renderHistoryTimeline(assignments) {
            if (!historyContainer) return;
            historyContainer.innerHTML = '';

            if (!assignments || assignments.length === 0) {
                historyContainer.innerHTML = '<p class="text-muted" style="font-size:0.82rem; font-style:italic;">No historical reassignments recorded. Initial corridor assigned at intake.</p>';
                return;
            }

            const timeline = document.createElement('div');
            timeline.className = 'attribution-timeline';

            assignments.forEach(item => {
                const itemEl = document.createElement('div');
                itemEl.className = 'timeline-item';

                const cardEl = document.createElement('div');
                cardEl.className = 'timeline-card';

                const headerEl = document.createElement('div');
                headerEl.className = 'timeline-header';

                const transEl = document.createElement('span');
                transEl.className = 'timeline-transition';
                const prevName = item.previous_section_name || 'Initial Intake';
                const newName = item.new_section_name || 'Section #' + item.new_section_id;
                transEl.textContent = prevName + ' ➔ ' + newName;

                const timeEl = document.createElement('span');
                timeEl.className = 'timeline-time';
                timeEl.textContent = formatDateTime(item.assigned_at);

                headerEl.appendChild(transEl);
                headerEl.appendChild(timeEl);

                const metaEl = document.createElement('div');
                metaEl.className = 'timeline-meta';
                const assignedBy = item.assigned_by_name || 'Officer #' + item.assigned_by;
                const methodLabel = formatMethodLabel(item.assignment_method);
                metaEl.textContent = 'By: ' + assignedBy + ' | Method: ' + methodLabel;

                const reasonEl = document.createElement('div');
                reasonEl.className = 'timeline-reason';
                reasonEl.textContent = '“' + item.assignment_reason + '”';

                cardEl.appendChild(headerEl);
                cardEl.appendChild(metaEl);
                cardEl.appendChild(reasonEl);
                itemEl.appendChild(cardEl);
                timeline.appendChild(itemEl);
            });

            historyContainer.appendChild(timeline);
        }

        // =========================================================================
        // 6. UI Helpers & Event Listeners
        // =========================================================================
        function showSuccessNotification(message) {
            if (!successToast) return;
            successToast.textContent = message;
            successToast.style.display = 'block';
            setTimeout(function () {
                successToast.style.display = 'none';
            }, 6000);
        }

        function formatDateTime(dtStr) {
            if (!dtStr) return '';
            try {
                const d = new Date(dtStr);
                return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            } catch (e) {
                return dtStr;
            }
        }

        function formatMethodLabel(m) {
            switch (m) {
                case 'manual_controller_override': return 'Manual Controller Override';
                case 'spatial_nearest_neighbor': return 'Spatial Nearest Neighbor';
                case 'station_master_vhf': return 'Station Master VHF';
                case 'chainage_milepost_lookup': return 'Chainage Milepost Lookup';
                default: return m || 'Administrative';
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Event: Quick Confirm Button
        if (btnConfirm) {
            btnConfirm.addEventListener('click', function () {
                openModal(currentSectionId, 'Controller confirmed corridor attribution based on telemetry.', 'manual_controller_override');
            });
        }

        // Event: Reassign Button
        if (btnReassign) {
            btnReassign.addEventListener('click', function () {
                openModal(currentSectionId, '', 'manual_controller_override');
            });
        }

        // Modal triggers
        if (modalCloseBtn) modalCloseBtn.addEventListener('click', closeModal);
        if (modalCancelBtn) modalCancelBtn.addEventListener('click', closeModal);
        if (modalOverlay) {
            modalOverlay.addEventListener('click', function (e) {
                if (e.target === modalOverlay) closeModal();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modalOverlay && modalOverlay.classList.contains('active')) {
                closeModal();
            }
        });

        if (modalReasonInput) {
            modalReasonInput.addEventListener('input', updateCharCount);
        }

        if (modalForm) {
            modalForm.addEventListener('submit', handleAttributionSubmit);
        }

        // Initialize GIS Map
        initGisMap();
    });
})();
