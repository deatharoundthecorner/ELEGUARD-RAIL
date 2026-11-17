/**
 * EleGuard Rail - Interactive GIS Map Component
 * 
 * Milestone 9C — Stage 4E.4.3-H3: Interactive Railway Map & Section Visualization
 * 
 * Modular Leaflet.js map client that interfaces with public/api/gis_data.php.
 * Renders railway corridors, active/inactive section filtering, wildlife observation
 * markers, separate observer/animal positions, geodesic nearest-track connectors,
 * spatial uncertainty circles, topology ambiguity zones, and safety alert restrictions.
 * 
 * License: OpenStreetMap tile attribution (ODbL / CC-BY-SA)
 * Advisory Notice: PROVISIONAL — NOT OFFICIALLY VERIFIED (OSM/HOT-HDX)
 */

class EleGuardMap {
    /**
     * Factory initializer
     * @param {string} containerId DOM ID for map element
     * @param {Object} options Configuration parameters
     */
    static init(containerId, options = {}) {
        const instance = new EleGuardMap(containerId, options);
        instance.setup();
        return instance;
    }

    constructor(containerId, options = {}) {
        this.containerId = containerId;
        this.options = Object.assign({
            role: 'wildlife_officer', // 'admin', 'control_officer', 'wildlife_officer'
            apiEndpoint: '../api/gis_data.php',
            defaultCenter: [8.12, 80.85], // Center of North-Central Sri Lanka railway network
            defaultZoom: 9,
            minZoom: 7,
            maxZoom: 18,
            autoFitBounds: true,
            filterPrefix: 'gis-filter-'
        }, options);

        this.map = null;
        this.layerControl = null;
        this.legendControl = null;
        this.routesLayer = null;
        this.activeCorridorLayer = null;
        this.hotspotsLayer = null;
        this.verifiedLayer = null;
        this.pendingLayer = null;
        this.observerLayer = null;
        this.proximityLinesLayer = null;
        this.uncertaintyLayer = null;
        this.ambiguityLayer = null;
        this.alertsLayer = null;
        this.currentData = null;
        this.boundFilterHandlers = [];
    }

    setup() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            console.error(`EleGuardMap: Element #${this.containerId} not found.`);
            return;
        }

        // 1. Initialize Leaflet Map with Canvas optimization
        this.map = L.map(this.containerId, {
            center: this.options.defaultCenter,
            zoom: this.options.defaultZoom,
            minZoom: this.options.minZoom,
            maxZoom: this.options.maxZoom,
            zoomControl: true,
            attributionControl: true,
            preferCanvas: true // HTML5 Canvas rendering for smooth vector performance
        });

        // 2. Setup Custom Leaflet Panes with strict Z-Index ordering (ADR-H3-02)
        const customPanes = [
            { name: 'railwayTrackPane', zIndex: 300 },
            { name: 'activeCorridorPane', zIndex: 350 },
            { name: 'ambiguityZonePane', zIndex: 400 },
            { name: 'uncertaintyCirclePane', zIndex: 450 },
            { name: 'connectorPane', zIndex: 500 },
            { name: 'observerMarkerPane', zIndex: 550 },
            { name: 'observationMarkerPane', zIndex: 600 },
            { name: 'alertPane', zIndex: 650 }
        ];

        customPanes.forEach(p => {
            const pane = this.map.createPane(p.name);
            pane.style.zIndex = p.zIndex;
        });

        // 3. OpenStreetMap Tile Layer with mandatory ODbL attribution
        const osmTiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors | EleGuard Rail GIS (PROVISIONAL — NOT OFFICIALLY VERIFIED)'
        });
        osmTiles.addTo(this.map);

        // 4. Initialize Feature Layer Groups
        this.routesLayer = L.layerGroup().addTo(this.map);
        this.activeCorridorLayer = L.layerGroup().addTo(this.map);
        this.hotspotsLayer = L.layerGroup().addTo(this.map);
        this.verifiedLayer = L.layerGroup().addTo(this.map);
        this.pendingLayer = L.layerGroup().addTo(this.map);
        this.observerLayer = L.layerGroup().addTo(this.map);
        this.proximityLinesLayer = L.layerGroup().addTo(this.map);
        this.uncertaintyLayer = L.layerGroup().addTo(this.map);
        this.ambiguityLayer = L.layerGroup().addTo(this.map);
        this.alertsLayer = L.layerGroup().addTo(this.map);

        // 5. Layer Visibility Control
        const overlays = {
            '<span style="color:#8bc34a;">🛣️ Railway Corridors</span>': this.routesLayer,
            '<span style="color:#10b981;">🛤️ Active Section Focus</span>': this.activeCorridorLayer,
            '<span style="color:#4caf50;">🐘 Verified Observations</span>': this.verifiedLayer,
            '<span style="color:#3b82f6;">📍 Observer Standoff Pins</span>': this.observerLayer,
            '<span style="color:#ef4444;">📏 Nearest Track Connectors</span>': this.proximityLinesLayer,
            '<span style="color:#f59e0b;">⭕ Spatial Uncertainty Buffers</span>': this.uncertaintyLayer,
            '<span style="color:#ec4899;">⚡ Topology Ambiguity Zones</span>': this.ambiguityLayer,
            '<span style="color:#ff9800;">📍 Monitored Hotspots</span>': this.hotspotsLayer,
            '<span style="color:#dc2626;">🚨 Active Safety Alerts</span>': this.alertsLayer
        };

        // Only add Pending Reports layer for authorized officers
        if (this.options.role === 'admin' || this.options.role === 'wildlife_officer') {
            overlays['<span style="color:#fbc02d;">⚠️ Pending Ranger Review</span>'] = this.pendingLayer;
        }

        this.layerControl = L.control.layers(null, overlays, {
            position: 'topright',
            collapsed: true
        }).addTo(this.map);

        // 6. Add Informational Legend Control
        this.createLegend();

        // 7. Bind Filter Toolbar Events
        this.bindFilterEvents();

        // 8. Initial Data Fetch
        this.fetchData();
    }

    showLoading(show = true) {
        const overlay = document.getElementById(this.containerId + '-loading');
        if (overlay) {
            if (show) overlay.classList.add('active');
            else overlay.classList.remove('active');
        }
    }

    showError(message) {
        const banner = document.getElementById(this.containerId + '-error');
        if (banner) {
            banner.textContent = '⚠️ ' + message;
            banner.style.display = 'flex';
            setTimeout(() => { banner.style.display = 'none'; }, 6000);
        }
    }

    buildQueryString(filters = {}) {
        const params = new URLSearchParams();
        for (const [key, val] of Object.entries(filters)) {
            if (val !== undefined && val !== null && val !== '') {
                params.append(key, val);
            }
        }
        return params.toString();
    }

    fetchData() {
        this.showLoading(true);
        const filters = this.readCurrentFilters();
        const qs = this.buildQueryString(filters);
        const url = this.options.apiEndpoint + (qs ? '?' + qs : '');

        fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (response.status === 401) {
                throw new Error('Session expired or authentication required. Please log in.');
            }
            if (response.status === 403) {
                throw new Error('Access denied. Precise wildlife coordinates are restricted to authorized personnel.');
            }
            if (!response.ok) {
                throw new Error(`GIS API returned HTTP ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            this.showLoading(false);
            if (data.status === 'success') {
                this.currentData = data;
                this.renderData(data, filters.section_id);
                this.updateSummaryCounters(data.summary);
            } else {
                this.showError(data.message || 'Error loading GIS data.');
            }
        })
        .catch(err => {
            this.showLoading(false);
            console.error('EleGuardMap fetch error:', err);
            this.showError(err.message || 'Failed to connect to GIS service.');
        });
    }

    readCurrentFilters() {
        const p = this.options.filterPrefix;
        const sectionEl = document.getElementById(p + 'section');
        const riskEl = document.getElementById(p + 'risk');
        const statusEl = document.getElementById(p + 'status');
        const dateFromEl = document.getElementById(p + 'date-from');
        const dateToEl = document.getElementById(p + 'date-to');

        return {
            section_id: sectionEl ? sectionEl.value : '',
            risk_level: riskEl ? riskEl.value : '',
            status: statusEl ? statusEl.value : '',
            date_from: dateFromEl ? dateFromEl.value : '',
            date_to: dateToEl ? dateToEl.value : ''
        };
    }

    bindFilterEvents() {
        const p = this.options.filterPrefix;
        const applyBtn = document.getElementById(p + 'apply');
        const resetBtn = document.getElementById(p + 'reset');
        const refreshBtn = document.getElementById(p + 'refresh');

        const addListener = (el, evt, handler) => {
            if (el) {
                el.addEventListener(evt, handler);
                this.boundFilterHandlers.push({ el, evt, handler });
            }
        };

        if (applyBtn) {
            addListener(applyBtn, 'click', () => this.fetchData());
        }

        if (resetBtn) {
            addListener(resetBtn, 'click', () => {
                const els = [p + 'section', p + 'risk', p + 'status', p + 'date-from', p + 'date-to'];
                els.forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.value = '';
                });
                this.fetchData();
            });
        }

        if (refreshBtn) {
            addListener(refreshBtn, 'click', () => this.fetchData());
        }

        // Auto filter on select change
        const autoChangeEls = [p + 'section', p + 'risk', p + 'status'];
        autoChangeEls.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                addListener(el, 'change', () => this.fetchData());
            }
        });
    }

    updateSummaryCounters(summary) {
        if (!summary) return;
        const update = (id, val) => {
            const el = document.getElementById('gis-stat-' + id);
            if (el) el.textContent = val;
        };
        update('total-reports', summary.total_sighting_reports ?? 0);
        update('verified-reports', summary.verified_reports ?? 0);
        update('pending-reports', summary.pending_reports ?? 0);
        update('critical-reports', summary.critical_reports ?? 0);
        update('hotspots', summary.total_hotspots ?? 0);
    }

    renderData(payload, activeSectionFilter = null) {
        // Clear all layers before re-populating
        this.routesLayer.clearLayers();
        this.activeCorridorLayer.clearLayers();
        this.hotspotsLayer.clearLayers();
        this.verifiedLayer.clearLayers();
        this.pendingLayer.clearLayers();
        this.observerLayer.clearLayers();
        this.proximityLinesLayer.clearLayers();
        this.uncertaintyLayer.clearLayers();
        this.ambiguityLayer.clearLayers();
        this.alertsLayer.clearLayers();

        const bounds = L.latLngBounds([]);
        const activeSecId = activeSectionFilter ? parseInt(activeSectionFilter, 10) : null;

        // 1. Render Railway Corridors (Active vs Inactive Section Styling)
        if (payload.routes && payload.routes.features) {
            payload.routes.features.forEach(feat => {
                const secId = feat.properties && feat.properties.section_id ? parseInt(feat.properties.section_id, 10) : null;
                const isActive = activeSecId && secId === activeSecId;

                const lineGroup = this.createRoutePolyline(feat, isActive, activeSecId !== null);
                if (lineGroup) {
                    if (isActive) {
                        this.activeCorridorLayer.addLayer(lineGroup);
                        bounds.extend(lineGroup.getBounds());
                    } else {
                        this.routesLayer.addLayer(lineGroup);
                        if (!activeSecId) {
                            bounds.extend(lineGroup.getBounds());
                        }
                    }
                }
            });
        }

        // 2. Render Hotspots
        if (payload.hotspots && payload.hotspots.features) {
            payload.hotspots.features.forEach(feat => {
                const marker = this.createHotspotMarker(feat);
                if (marker) {
                    this.hotspotsLayer.addLayer(marker);
                    if (!activeSecId) bounds.extend(marker.getLatLng());
                }
            });
        }

        // 3. Render Sighting Reports (Animal Pins, Observers, Uncertainty, Connectors)
        if (payload.reports && payload.reports.features) {
            payload.reports.features.forEach(feat => {
                const props = feat.properties || {};
                const animalMarker = this.createReportMarker(feat);

                if (animalMarker) {
                    const status = (props.report_status || '').toLowerCase();
                    if (status === 'verified' || status === 'converted to alert') {
                        this.verifiedLayer.addLayer(animalMarker);
                    } else {
                        this.pendingLayer.addLayer(animalMarker);
                    }
                    bounds.extend(animalMarker.getLatLng());

                    // Render Nearest-Track Geodesic Connector
                    const proxLine = this.createProximityLine(feat);
                    if (proxLine) {
                        this.proximityLinesLayer.addLayer(proxLine);
                    }

                    // Render Spatial Uncertainty Circles (only where actual measurements exist)
                    const uncertaintyCircles = this.createUncertaintyCircles(feat);
                    if (uncertaintyCircles) {
                        uncertaintyCircles.forEach(c => this.uncertaintyLayer.addLayer(c));
                    }

                    // Render Separate Observer Position & Standoff Vector (when available)
                    const observerElements = this.createObserverElements(feat);
                    if (observerElements) {
                        observerElements.forEach(el => this.observerLayer.addLayer(el));
                    }

                    // Render Topology Ambiguity Highlight (when flagged)
                    if (props.section_assignment_status === 'topology_ambiguity' || (props.proximity && props.proximity.is_ambiguous)) {
                        const ambiguityCircle = this.createAmbiguityZone(feat);
                        if (ambiguityCircle) {
                            this.ambiguityLayer.addLayer(ambiguityCircle);
                        }
                    }
                }
            });
        }

        // 4. Render Active Safety Alerts (Speed Restrictions & Collision Warnings)
        if (payload.alerts && payload.alerts.features) {
            payload.alerts.features.forEach(alertFeat => {
                const alertMarker = this.createAlertElement(alertFeat, payload.routes);
                if (alertMarker) {
                    this.alertsLayer.addLayer(alertMarker);
                }
            });
        }

        // Auto-fit camera view
        if (this.options.autoFitBounds && bounds.isValid()) {
            this.map.fitBounds(bounds, { padding: [30, 30], maxZoom: activeSecId ? 14 : 12 });
        }
    }

    createRoutePolyline(feature, isActive = false, isFilterActive = false) {
        const props = feature.properties || {};
        const coords = feature.geometry.coordinates || [];
        if (!coords.length) return null;

        // Leaflet expects [lat, lng], GeoJSON standard is [lng, lat]
        const latLngs = coords.map(c => [c[1], c[0]]);

        const riskColors = {
            'critical': '#d32f2f',
            'high': '#f57c00',
            'medium': '#fbc02d',
            'low': '#388e3c'
        };
        const baseColor = riskColors[(props.risk_level || '').toLowerCase()] || '#4caf50';

        let casingWeight = 6;
        let strokeWeight = 3.5;
        let strokeColor = baseColor;
        let strokeOpacity = 0.9;
        let paneName = 'railwayTrackPane';

        if (isActive) {
            // Prominent Emerald focus for active section filter
            casingWeight = 8;
            strokeWeight = 5.5;
            strokeColor = '#10b981';
            strokeOpacity = 1.0;
            paneName = 'activeCorridorPane';
        } else if (isFilterActive) {
            // Subdued slate grey for non-active corridors
            casingWeight = 4;
            strokeWeight = 2;
            strokeColor = '#475569';
            strokeOpacity = 0.35;
            paneName = 'railwayTrackPane';
        }

        const casing = L.polyline(latLngs, {
            color: '#0f172a',
            weight: casingWeight,
            opacity: isActive ? 0.9 : 0.6,
            pane: paneName
        });

        const line = L.polyline(latLngs, {
            color: strokeColor,
            weight: strokeWeight,
            opacity: strokeOpacity,
            dashArray: isActive ? null : '6, 6',
            pane: paneName
        });

        const group = L.featureGroup([casing, line]);

        // Interactive hover feedback
        line.on('mouseover', () => {
            if (!isActive) line.setStyle({ color: '#38bdf8', weight: strokeWeight + 1.5 });
        });
        line.on('mouseout', () => {
            if (!isActive) line.setStyle({ color: strokeColor, weight: strokeWeight });
        });

        const popupHtml = `
            <div class="gis-popup">
                <div class="gis-popup-header">
                    <h3 class="gis-popup-title">🛣️ ${this.escapeHtml(props.section_name || 'Railway Section')}</h3>
                    <span class="gis-popup-badge" style="background:${isActive ? '#10b981' : strokeColor}; color:#fff;">
                        ${isActive ? 'ACTIVE FOCUS' : this.escapeHtml(props.risk_level || 'Normal')}
                    </span>
                </div>
                <div class="gis-popup-body">
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Section ID:</span>
                        <span class="gis-popup-val">Corridor #${props.section_id || 'N/A'}</span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">District:</span>
                        <span class="gis-popup-val">${this.escapeHtml(props.district || 'N/A')}</span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Start Terminal:</span>
                        <span class="gis-popup-val">${this.escapeHtml(props.start_location || 'N/A')}</span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">End Terminal:</span>
                        <span class="gis-popup-val">${this.escapeHtml(props.end_location || 'N/A')}</span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Track Length:</span>
                        <span class="gis-popup-val">${props.track_length_km || 0} km (${props.points_count || coords.length} nodes)</span>
                    </div>
                    <div class="gis-disclaimer-tag">
                        ⚠️ PROVISIONAL — NOT OFFICIALLY VERIFIED (OSM/HOT-HDX)
                    </div>
                </div>
            </div>
        `;
        group.bindPopup(popupHtml);

        return group;
    }

    createHotspotMarker(feature) {
        const props = feature.properties || {};
        const coords = feature.geometry.coordinates || [];
        if (coords.length < 2) return null;

        const latLng = [coords[1], coords[0]];
        const icon = L.divIcon({
            className: 'gis-custom-icon',
            html: `<div class="gis-marker gis-marker-hotspot" style="width:26px; height:26px; font-size:13px;" title="${this.escapeHtml(props.name || 'Hotspot')}">📍</div>`,
            iconSize: [26, 26],
            iconAnchor: [13, 13]
        });

        const marker = L.marker(latLng, { icon: icon, pane: 'observationMarkerPane' });

        const popupHtml = `
            <div class="gis-popup">
                <div class="gis-popup-header">
                    <h3 class="gis-popup-title">📍 ${this.escapeHtml(props.name || 'Hotspot Area')}</h3>
                    <span class="gis-popup-badge" style="background:#e65100; color:#fff;">${this.escapeHtml(props.risk_level || 'High')}</span>
                </div>
                <div class="gis-popup-body">
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Track Section:</span>
                        <span class="gis-popup-val">${this.escapeHtml(props.section_name || 'N/A')}</span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">District:</span>
                        <span class="gis-popup-val">${this.escapeHtml(props.district || 'N/A')}</span>
                    </div>
                    <div style="margin-top:8px;">
                        <span class="gis-popup-label">Movement Reason:</span>
                        <p style="margin:4px 0 0 0; color:#e0e6e0; font-size:0.78rem;">${this.escapeHtml(props.reason || 'Known elephant migration path')}</p>
                    </div>
                </div>
            </div>
        `;
        marker.bindPopup(popupHtml);

        return marker;
    }

    createReportMarker(feature) {
        const props = feature.properties || {};
        const coords = feature.geometry.coordinates || [];
        if (coords.length < 2) return null;

        const latLng = [coords[1], coords[0]];
        const risk = (props.risk_level || 'Medium').toLowerCase();
        const status = (props.report_status || 'Pending').toLowerCase();
        const isVerified = status === 'verified' || status === 'converted to alert';

        const riskClass = 'gis-marker-' + (['critical', 'high', 'medium', 'low'].includes(risk) ? risk : 'medium');
        const pendingClass = !isVerified ? 'gis-marker-pending' : '';
        const isSynthetic = props.is_synthetic === true || props.provenance === 'development_test';
        const synthTitleSuffix = isSynthetic ? ' [🧪 Synthetic Showcase]' : '';
        const synthBorderStyle = isSynthetic ? 'border: 2px dashed #6366f1;' : '';

        const icon = L.divIcon({
            className: 'gis-custom-icon',
            html: `<div class="gis-marker ${riskClass} ${pendingClass}" style="width:28px; height:28px; font-size:14px; ${synthBorderStyle}" title="Sighting #${props.id}${synthTitleSuffix}">🐘</div>`,
            iconSize: [28, 28],
            iconAnchor: [14, 14]
        });

        const marker = L.marker(latLng, { icon: icon, pane: 'observationMarkerPane' });

        // Attribution status badge
        const secStatus = props.section_assignment_status || 'provisional_estimated';
        let secBadgeColor = '#3b82f6';
        let secBadgeText = '⏳ PROVISIONAL';
        if (secStatus === 'controller_confirmed') {
            secBadgeColor = '#10b981';
            secBadgeText = '✔ CONTROLLER CONFIRMED';
        } else if (secStatus === 'topology_ambiguity') {
            secBadgeColor = '#f59e0b';
            secBadgeText = '⚡ TOPOLOGY AMBIGUITY';
        }

        // Privacy indicator
        const isMasked = props.is_masked === true;
        const privacyBadge = isMasked 
            ? '<span class="badge" style="background:#475569; font-size:0.7rem; color:#fff;">Approximate (~1.1km Masked)</span>'
            : '<span class="badge" style="background:#059669; font-size:0.7rem; color:#fff;">Operational Precision</span>';

        // Provenance badge
        const provenanceBadge = isSynthetic
            ? '<span class="badge" style="background:#4338ca; color:#e0e7ff; font-size:0.7rem; font-weight:600;">🧪 Synthetic Demonstration</span>'
            : '<span class="badge" style="background:#065f46; color:#d1fae5; font-size:0.7rem; font-weight:600;">📍 Field Observation</span>';

        // Proximity box
        let proximityHtml = '';
        if (props.proximity && props.proximity.distance_meters !== null) {
            const p = props.proximity;
            const dist = p.distance_meters;
            let distColor = '#4caf50';
            if (dist < 100) distColor = '#d32f2f';
            else if (dist < 300) distColor = '#f57c00';
            else if (dist < 1000) distColor = '#fbc02d';

            proximityHtml = `
                <div class="gis-proximity-box">
                    <div class="gis-proximity-title">
                        <span>📏 Calculated Railway Proximity</span>
                    </div>
                    <div class="gis-proximity-val" style="color:${distColor};">
                        ${dist.toLocaleString()} metres ${isMasked ? '(approximate)' : ''}
                    </div>
                    <div class="gis-popup-row" style="margin-top:4px;">
                        <span class="gis-popup-label">Nearest Section:</span>
                        <span class="gis-popup-val">${this.escapeHtml(p.nearest_section_name || props.section_name || 'N/A')}</span>
                    </div>
                    <div class="gis-uncertainty-note">
                        ⚠️ PROVISIONAL — NOT OFFICIALLY VERIFIED (OSM/HOT-HDX)
                    </div>
                </div>
            `;
        }

        const statusBadgeClass = isVerified ? 'badge-success' : 'badge-warning';

        const popupHtml = `
            <div class="gis-popup">
                <div class="gis-popup-header">
                    <h3 class="gis-popup-title">🐘 Sighting Observation #${props.id}</h3>
                    <span class="gis-popup-badge" style="background:${this.getRiskColor(props.risk_level)}; color:#fff;">
                        ${this.escapeHtml(props.risk_level || 'Normal')}
                    </span>
                </div>
                <div class="gis-popup-body">
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Biological Status:</span>
                        <span class="gis-popup-val"><span class="badge ${statusBadgeClass}">${this.escapeHtml(props.report_status)}</span></span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Data Provenance:</span>
                        <span class="gis-popup-val">${provenanceBadge}</span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Corridor Attribution:</span>
                        <span class="gis-popup-val"><span class="badge" style="background:${secBadgeColor}; color:#fff;">${secBadgeText}</span></span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Coordinate Privacy:</span>
                        <span class="gis-popup-val">${privacyBadge}</span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Elephants Sighted:</span>
                        <span class="gis-popup-val"><strong>${props.elephant_count || 1}</strong></span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Location:</span>
                        <span class="gis-popup-val">${this.escapeHtml(props.location_description || 'Railway corridor')}</span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Coordinates:</span>
                        <span class="gis-popup-val"><code>${latLng[0].toFixed(isMasked ? 2 : 5)}, ${latLng[1].toFixed(isMasked ? 2 : 5)}</code></span>
                    </div>
                    ${proximityHtml}
                </div>
            </div>
        `;
        marker.bindPopup(popupHtml);

        return marker;
    }

    createProximityLine(feature) {
        const props = feature.properties || {};
        const coords = feature.geometry.coordinates || [];
        const prox = props.proximity;

        if (!prox || !prox.closest_track_point || !coords.length) {
            return null;
        }

        const sightingLatLng = [coords[1], coords[0]];
        const trackLatLng = [prox.closest_track_point.latitude, prox.closest_track_point.longitude];

        const line = L.polyline([sightingLatLng, trackLatLng], {
            color: '#ef4444',
            weight: 2.5,
            dashArray: '5, 5',
            opacity: 0.85,
            pane: 'connectorPane'
        });

        line.bindTooltip(`Distance: ${prox.distance_meters}m to ${this.escapeHtml(prox.nearest_section_name || 'track')} (PROVISIONAL)`, {
            sticky: true,
            className: 'gis-tooltip'
        });

        return line;
    }

    createUncertaintyCircles(feature) {
        const props = feature.properties || {};
        const coords = feature.geometry.coordinates || [];
        if (coords.length < 2) return null;

        const circles = [];
        const animalLatLng = [coords[1], coords[0]];

        // Animal location uncertainty circle (only if actual measurement exists)
        const animUncert = props.animal_uncertainty_meters ? parseFloat(props.animal_uncertainty_meters) : null;
        if (animUncert && animUncert > 0) {
            const animalCircle = L.circle(animalLatLng, {
                radius: animUncert,
                color: '#f59e0b',
                fillColor: '#f59e0b',
                fillOpacity: 0.12,
                weight: 1.5,
                dashArray: '4, 4',
                pane: 'uncertaintyCirclePane'
            });
            animalCircle.bindTooltip(`Animal Location Uncertainty: ±${Math.round(animUncert)}m`, { sticky: true });
            circles.push(animalCircle);
        }

        // Observer GPS accuracy circle (only if actual measurement exists)
        const obsCoords = props.observer_coordinates;
        const obsAcc = props.observer_accuracy_meters ? parseFloat(props.observer_accuracy_meters) : null;
        if (obsCoords && obsCoords.length >= 2 && obsAcc && obsAcc > 0) {
            const obsCircle = L.circle([obsCoords[1], obsCoords[0]], {
                radius: obsAcc,
                color: '#3b82f6',
                fillColor: '#3b82f6',
                fillOpacity: 0.10,
                weight: 1.5,
                pane: 'uncertaintyCirclePane'
            });
            obsCircle.bindTooltip(`Observer GPS Accuracy: ±${Math.round(obsAcc)}m`, { sticky: true });
            circles.push(obsCircle);
        }

        return circles.length ? circles : null;
    }

    createObserverElements(feature) {
        const props = feature.properties || {};
        const obsCoords = props.observer_coordinates;
        const animalCoords = feature.geometry.coordinates;

        // Only draw if observation is estimated and observer coordinates are present
        if (!obsCoords || obsCoords.length < 2 || props.is_estimated_position !== 1) {
            return null;
        }

        const elements = [];
        const obsLatLng = [obsCoords[1], obsCoords[0]];
        const animalLatLng = [animalCoords[1], animalCoords[0]];

        // Observer Pin Marker
        const obsIcon = L.divIcon({
            className: 'gis-custom-icon',
            html: '<div style="background:#2563eb; color:#fff; width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; border:2px solid #fff; box-shadow:0 2px 6px rgba(0,0,0,0.4); font-size:11px;">📍</div>',
            iconSize: [22, 22],
            iconAnchor: [11, 11]
        });

        const obsMarker = L.marker(obsLatLng, { icon: obsIcon, pane: 'observerMarkerPane' });
        obsMarker.bindPopup(`
            <div class="gis-popup">
                <div class="gis-popup-header">
                    <h3 class="gis-popup-title">📍 Observer Standoff Location</h3>
                    <span class="gis-popup-badge" style="background:#3b82f6; color:#fff;">Report #${props.id}</span>
                </div>
                <div class="gis-popup-body">
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Observer Lat/Lon:</span>
                        <span class="gis-popup-val"><code>${obsLatLng[0].toFixed(props.is_masked ? 2 : 5)}, ${obsLatLng[1].toFixed(props.is_masked ? 2 : 5)}</code></span>
                    </div>
                    ${props.observer_accuracy_meters ? `
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">GPS Accuracy:</span>
                        <span class="gis-popup-val">±${Math.round(props.observer_accuracy_meters)}m</span>
                    </div>` : ''}
                    <div style="font-size:0.75rem; color:#94a3b8; margin-top:6px;">
                        Human observer stood here; maintained safe standoff distance from wild elephant herd.
                    </div>
                </div>
            </div>
        `);
        elements.push(obsMarker);

        // Standoff vector linking observer pin to animal pin
        const standoffLine = L.polyline([obsLatLng, animalLatLng], {
            color: '#94a3b8',
            weight: 2,
            dashArray: '4, 4',
            opacity: 0.75,
            pane: 'connectorPane'
        });
        standoffLine.bindTooltip('Observer-to-Animal Standoff Sightline', { sticky: true });
        elements.push(standoffLine);

        return elements;
    }

    createAmbiguityZone(feature) {
        const coords = feature.geometry.coordinates || [];
        if (coords.length < 2) return null;

        // 500m radius circle around sighting coordinate denoting convergence ambiguity
        // System-defined topology ambiguity heuristic — not an officially validated Sri Lanka Railways engineering threshold.
        const circle = L.circle([coords[1], coords[0]], {
            radius: 500,
            color: '#ec4899',
            fillColor: '#ec4899',
            fillOpacity: 0.08,
            weight: 2,
            dashArray: '6, 6',
            pane: 'ambiguityZonePane'
        });

        circle.bindTooltip('⚡ Multi-Section Ambiguity Zone (500m heuristic — not an official SLR threshold)', { sticky: true });
        return circle;
    }

    createAlertElement(alertFeat, routesData) {
        const props = alertFeat.properties || {};
        const secId = props.section_id;

        // Locate midpoint of the associated railway section for alert banner pin
        let alertLatLng = null;
        if (routesData && routesData.features) {
            const secFeat = routesData.features.find(f => f.properties && f.properties.section_id === secId);
            if (secFeat && secFeat.geometry && secFeat.geometry.coordinates) {
                const c = secFeat.geometry.coordinates;
                const midIdx = Math.floor(c.length / 2);
                alertLatLng = [c[midIdx][1], c[midIdx][0]];
            }
        }

        if (!alertLatLng) {
            // Default Sri Lanka North-Central coordinate fallback
            alertLatLng = [8.033, 80.750];
        }

        const icon = L.divIcon({
            className: 'gis-custom-icon',
            html: `<div style="background:#dc2626; color:#fff; width:30px; height:30px; border-radius:6px; display:flex; align-items:center; justify-content:center; border:2px solid #fff; box-shadow:0 3px 8px rgba(0,0,0,0.5); font-size:16px;" title="Alert #${props.id}">🚨</div>`,
            iconSize: [30, 30],
            iconAnchor: [15, 15]
        });

        const marker = L.marker(alertLatLng, { icon: icon, pane: 'alertPane' });

        const cautionSpeed = props.suggested_caution_speed_kmh || props.speed_restriction_kmh;
        const popupHtml = `
            <div class="gis-popup">
                <div class="gis-popup-header">
                    <h3 class="gis-popup-title">🚨 Active Railway Alert #${props.id}</h3>
                    <span class="gis-popup-badge" style="background:#dc2626; color:#fff;">${this.escapeHtml(props.alert_level || 'Critical')}</span>
                </div>
                <div class="gis-popup-body">
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Corridor:</span>
                        <span class="gis-popup-val">${this.escapeHtml(props.section_name || 'N/A')}</span>
                    </div>
                    ${cautionSpeed ? `
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Suggested Caution Speed:</span>
                        <span class="gis-popup-val"><strong style="color:#ef4444;">${cautionSpeed} km/h (System Advisory)</strong></span>
                    </div>` : ''}
                    <div class="gis-disclaimer-tag" style="background:rgba(220,38,38,0.15); color:#fca5a5; border-color:rgba(220,38,38,0.4); margin:6px 0; font-size:0.65rem; padding:4px 6px; border-radius:4px; text-align:center;">
                        ⚠️ ADVISORY RECOMMENDATION ONLY — NOT AN OFFICIAL TRAIN ORDER
                    </div>
                    <div style="margin-top:6px;">
                        <span class="gis-popup-label">Broadcast Warning:</span>
                        <p style="margin:4px 0 0 0; color:#f87171; font-size:0.8rem; line-height:1.4;">${this.escapeHtml(props.message || '')}</p>
                    </div>
                </div>
            </div>
        `;
        marker.bindPopup(popupHtml);

        return marker;
    }

    createLegend() {
        if (this.legendControl) return;

        const legend = L.control({ position: 'bottomright' });
        legend.onAdd = () => {
            const div = L.DomUtil.create('div', 'gis-legend');
            div.innerHTML = `
                <div class="gis-legend-title">
                    <span>🗺️ Map Symbology & Layers</span>
                </div>
                <div class="gis-legend-item">
                    <span class="gis-legend-line" style="background:#10b981; height:4px;"></span>
                    <span>Active Selected Corridor</span>
                </div>
                <div class="gis-legend-item">
                    <span class="gis-legend-line" style="background:#475569; height:2px;"></span>
                    <span>Network Track Centerlines</span>
                </div>
                <div class="gis-legend-item">
                    <span class="gis-legend-symbol" style="background:#10b981; border:2px solid #fff;"></span>
                    <span>Verified Sighting (Pin)</span>
                </div>
                <div class="gis-legend-item">
                    <span class="gis-legend-symbol" style="background:#f59e0b; border:2px dashed #fff;"></span>
                    <span>Pending Ranger Review</span>
                </div>
                <div class="gis-legend-item">
                    <span class="gis-legend-symbol" style="background:#2563eb; border:2px solid #fff;"></span>
                    <span>Observer Standoff Fix</span>
                </div>
                <div class="gis-legend-item">
                    <span class="gis-legend-line" style="background:#ef4444; border-top:1px dashed #ef4444; height:1px;"></span>
                    <span>Nearest Track Connector</span>
                </div>
                <div class="gis-legend-item">
                    <span class="gis-legend-symbol" style="background:rgba(236,72,153,0.25); border:1px dashed #ec4899;"></span>
                    <span>500m Ambiguity Zone (System Heuristic)</span>
                </div>
                <div class="gis-legend-item">
                    <span class="gis-legend-symbol" style="background:#dc2626; border:1px solid #fff;"></span>
                    <span>Active Safety Alert (System Advisory)</span>
                </div>
                <div class="gis-disclaimer-notice">
                    PROVISIONAL — NOT OFFICIALLY VERIFIED (OSM/HOT-HDX)<br>
                    ADVISORY ONLY — NOT AN OFFICIAL TRAIN ORDER
                </div>
            `;
            return div;
        };
        legend.addTo(this.map);
        this.legendControl = legend;
    }

    getRiskColor(level) {
        switch ((level || '').toLowerCase()) {
            case 'critical': return '#d32f2f';
            case 'high': return '#f57c00';
            case 'medium': return '#fbc02d';
            case 'low': return '#388e3c';
            default: return '#757575';
        }
    }

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Complete lifecycle teardown and memory cleanup mechanism
     */
    destroy() {
        // 1. Unbind all filter event listeners
        if (this.boundFilterHandlers && this.boundFilterHandlers.length) {
            this.boundFilterHandlers.forEach(({ el, evt, handler }) => {
                if (el && el.removeEventListener) {
                    el.removeEventListener(evt, handler);
                }
            });
            this.boundFilterHandlers = [];
        }

        // 2. Clear all layer groups
        const layers = [
            this.routesLayer,
            this.activeCorridorLayer,
            this.hotspotsLayer,
            this.verifiedLayer,
            this.pendingLayer,
            this.observerLayer,
            this.proximityLinesLayer,
            this.uncertaintyLayer,
            this.ambiguityLayer,
            this.alertsLayer
        ];
        layers.forEach(lyr => {
            if (lyr && lyr.clearLayers) {
                lyr.clearLayers();
            }
        });

        // 3. Remove controls
        if (this.layerControl && this.map) {
            this.map.removeControl(this.layerControl);
            this.layerControl = null;
        }
        if (this.legendControl && this.map) {
            this.map.removeControl(this.legendControl);
            this.legendControl = null;
        }

        // 4. Remove Leaflet Map instance
        if (this.map) {
            this.map.remove();
            this.map = null;
        }

        this.currentData = null;
    }
}

// Global expose
window.EleGuardMap = EleGuardMap;
