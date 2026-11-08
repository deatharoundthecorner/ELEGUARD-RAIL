/**
 * EleGuard Rail - Interactive GIS Map Component
 * 
 * Modular Leaflet.js map client that interfaces with public/api/gis_data.php.
 * Renders railway route centerlines, wildlife crossing hotspots,
 * verified sightings, pending reports, and calculated proximity metrics.
 * 
 * License: OpenStreetMap tile attribution (ODbL / CC-BY-SA)
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
        this.routesLayer = null;
        this.hotspotsLayer = null;
        this.verifiedLayer = null;
        this.pendingLayer = null;
        this.proximityLinesLayer = null;
        this.currentData = null;
    }

    setup() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            console.error(`EleGuardMap: Element #${this.containerId} not found.`);
            return;
        }

        // 1. Initialize Leaflet Map
        this.map = L.map(this.containerId, {
            center: this.options.defaultCenter,
            zoom: this.options.defaultZoom,
            minZoom: this.options.minZoom,
            maxZoom: this.options.maxZoom,
            zoomControl: true,
            attributionControl: true
        });

        // 2. OpenStreetMap Tile Layer with mandatory ODbL attribution
        const osmTiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors | EleGuard Rail GIS'
        });
        osmTiles.addTo(this.map);

        // 3. Initialize Feature Layer Groups
        this.routesLayer = L.layerGroup().addTo(this.map);
        this.hotspotsLayer = L.layerGroup().addTo(this.map);
        this.verifiedLayer = L.layerGroup().addTo(this.map);
        this.pendingLayer = L.layerGroup().addTo(this.map);
        this.proximityLinesLayer = L.layerGroup(); // Optional layer, user toggled

        // 4. Layer Control
        const overlays = {
            '<span style="color:#8bc34a;">🛣️ Railway Tracks</span>': this.routesLayer,
            '<span style="color:#ff9800;">📍 Hotspot Corridors</span>': this.hotspotsLayer,
            '<span style="color:#4caf50;">🐘 Verified Sightings</span>': this.verifiedLayer
        };

        // Only add Pending Reports layer for authorized officers
        if (this.options.role === 'admin' || this.options.role === 'wildlife_officer') {
            overlays['<span style="color:#fbc02d;">⚠️ Pending Reports</span>'] = this.pendingLayer;
        }

        overlays['<span style="color:#90caf9;">📏 Proximity Projections</span>'] = this.proximityLinesLayer;

        this.layerControl = L.control.layers(null, overlays, {
            position: 'topright',
            collapsed: false
        }).addTo(this.map);

        // 5. Add Legend Control
        this.createLegend();

        // 6. Bind Filter Toolbar Events
        this.bindFilterEvents();

        // 7. Initial Data Fetch
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
                this.renderData(data);
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

        if (applyBtn) {
            applyBtn.addEventListener('click', () => this.fetchData());
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                const els = [p + 'section', p + 'risk', p + 'status', p + 'date-from', p + 'date-to'];
                els.forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.value = '';
                });
                this.fetchData();
            });
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.fetchData());
        }

        // Auto filter on select change
        const autoChangeEls = [p + 'section', p + 'risk', p + 'status'];
        autoChangeEls.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', () => this.fetchData());
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

    renderData(payload) {
        // Clear all layers
        this.routesLayer.clearLayers();
        this.hotspotsLayer.clearLayers();
        this.verifiedLayer.clearLayers();
        this.pendingLayer.clearLayers();
        this.proximityLinesLayer.clearLayers();

        const bounds = L.latLngBounds([]);

        // 1. Render Railway Routes (LineStrings)
        if (payload.routes && payload.routes.features) {
            payload.routes.features.forEach(feat => {
                const line = this.createRoutePolyline(feat);
                if (line) {
                    this.routesLayer.addLayer(line);
                    bounds.extend(line.getBounds());
                }
            });
        }

        // 2. Render Hotspots
        if (payload.hotspots && payload.hotspots.features) {
            payload.hotspots.features.forEach(feat => {
                const marker = this.createHotspotMarker(feat);
                if (marker) {
                    this.hotspotsLayer.addLayer(marker);
                    bounds.extend(marker.getLatLng());
                }
            });
        }

        // 3. Render Sighting Reports
        if (payload.reports && payload.reports.features) {
            payload.reports.features.forEach(feat => {
                const marker = this.createReportMarker(feat);
                if (marker) {
                    const status = (feat.properties.report_status || '').toLowerCase();
                    if (status === 'verified' || status === 'converted to alert') {
                        this.verifiedLayer.addLayer(marker);
                    } else {
                        this.pendingLayer.addLayer(marker);
                    }
                    bounds.extend(marker.getLatLng());

                    // Render proximity projection line if track point available
                    const proxLine = this.createProximityLine(feat);
                    if (proxLine) {
                        this.proximityLinesLayer.addLayer(proxLine);
                    }
                }
            });
        }

        // Fit map view if valid bounds and autoFitBounds enabled
        if (this.options.autoFitBounds && bounds.isValid()) {
            this.map.fitBounds(bounds, { padding: [30, 30], maxZoom: 14 });
        }
    }

    createRoutePolyline(feature) {
        const props = feature.properties || {};
        const coords = feature.geometry.coordinates || [];
        if (!coords.length) return null;

        // Leaflet expects [lat, lng] whereas GeoJSON has [lng, lat]
        const latLngs = coords.map(c => [c[1], c[0]]);

        // Color by risk level
        const riskColors = {
            'critical': '#d32f2f',
            'high': '#f57c00',
            'medium': '#fbc02d',
            'low': '#388e3c'
        };
        const color = riskColors[(props.risk_level || '').toLowerCase()] || '#4caf50';

        // Outer railway track outline casing
        const casing = L.polyline(latLngs, {
            color: '#1a1a1a',
            weight: 7,
            opacity: 0.8
        });

        // Inner styled polyline
        const line = L.polyline(latLngs, {
            color: color,
            weight: 4,
            opacity: 0.95,
            dashArray: '8, 6'
        });

        const group = L.featureGroup([casing, line]);

        // Popup
        const popupHtml = `
            <div class="gis-popup">
                <div class="gis-popup-header">
                    <h3 class="gis-popup-title">🛣️ ${this.escapeHtml(props.section_name || 'Railway Section')}</h3>
                    <span class="gis-popup-badge" style="background:${color}; color:#fff;">${this.escapeHtml(props.risk_level || 'Normal')}</span>
                </div>
                <div class="gis-popup-body">
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
                        <span class="gis-popup-val">${props.track_length_km || 0} km</span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Surveyed Waypoints:</span>
                        <span class="gis-popup-val">${props.points_count || 0} nodes</span>
                    </div>
                    <p style="font-size:0.72rem; color:#888; margin-top:8px;">${this.escapeHtml(props.disclaimer || '')}</p>
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

        const marker = L.marker(latLng, { icon: icon });

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

        const icon = L.divIcon({
            className: 'gis-custom-icon',
            html: `<div class="gis-marker ${riskClass} ${pendingClass}" style="width:28px; height:28px; font-size:14px;" title="Sighting #${props.id}">🐘</div>`,
            iconSize: [28, 28],
            iconAnchor: [14, 14]
        });

        const marker = L.marker(latLng, { icon: icon });

        // Build Proximity section HTML
        let proximityHtml = '';
        if (props.proximity && props.proximity.distance_meters !== null) {
            const p = props.proximity;
            const dist = p.distance_meters;
            const acc = p.gps_accuracy_meters;
            const range = p.effective_uncertainty_range;

            let distColor = '#4caf50';
            if (dist < 100) distColor = '#d32f2f'; // Immediate rail corridor danger
            else if (dist < 300) distColor = '#f57c00'; // High proximity zone
            else if (dist < 1000) distColor = '#fbc02d'; // Caution zone

            proximityHtml = `
                <div class="gis-proximity-box">
                    <div class="gis-proximity-title">
                        <span>📏 Calculated Railway Proximity</span>
                    </div>
                    <div class="gis-proximity-val" style="color:${distColor};">
                        ${dist.toLocaleString()} metres
                    </div>
                    <div class="gis-popup-row" style="margin-top:4px;">
                        <span class="gis-popup-label">Nearest Section:</span>
                        <span class="gis-popup-val">${this.escapeHtml(p.nearest_section_name || 'N/A')}</span>
                    </div>
                    ${acc !== null ? `
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">GPS Accuracy (Δ):</span>
                        <span class="gis-popup-val">±${acc}m</span>
                    </div>` : ''}
                    ${range ? `
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Uncertainty Range:</span>
                        <span class="gis-popup-val">[${range.min_distance_meters}m - ${range.max_distance_meters}m]</span>
                    </div>` : ''}
                    <div class="gis-uncertainty-note">
                        ⚠️ Geodesic shortest distance to authentic OpenStreetMap track centerline. Does not imply an active train collision.
                    </div>
                </div>
            `;
        } else {
            proximityHtml = `
                <div class="gis-proximity-box">
                    <div class="gis-uncertainty-note">
                        ℹ️ Sighting coordinates recorded without GPS accuracy specification.
                    </div>
                </div>
            `;
        }

        const statusBadgeClass = isVerified ? 'badge-success' : 'badge-warning';

        const popupHtml = `
            <div class="gis-popup">
                <div class="gis-popup-header">
                    <h3 class="gis-popup-title">🐘 Sighting Report #${props.id}</h3>
                    <span class="gis-popup-badge" style="background:${this.getRiskColor(props.risk_level)}; color:#fff;">${this.escapeHtml(props.risk_level || 'Normal')}</span>
                </div>
                <div class="gis-popup-body">
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Status:</span>
                        <span class="gis-popup-val">
                            <span class="badge ${statusBadgeClass}">${this.escapeHtml(props.report_status)}</span>
                        </span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Elephants Sighted:</span>
                        <span class="gis-popup-val"><strong>${props.elephant_count || 1}</strong></span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Urgency Level:</span>
                        <span class="gis-popup-val">${this.escapeHtml(props.urgency_level || 'Normal')}</span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Location:</span>
                        <span class="gis-popup-val">${this.escapeHtml(props.location_description || 'Railway corridor')}</span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Coordinates:</span>
                        <span class="gis-popup-val">${latLng[0].toFixed(5)}, ${latLng[1].toFixed(5)}</span>
                    </div>
                    <div class="gis-popup-row">
                        <span class="gis-popup-label">Time:</span>
                        <span class="gis-popup-val">${this.escapeHtml(props.created_at || 'Recent')}</span>
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
            color: '#64b5f6',
            weight: 2,
            dashArray: '4, 4',
            opacity: 0.75
        });

        line.bindTooltip(`Distance: ${prox.distance_meters}m to ${this.escapeHtml(prox.nearest_section_name || 'track')}`, {
            sticky: true,
            className: 'gis-tooltip'
        });

        return line;
    }

    createLegend() {
        const legend = L.control({ position: 'bottomright' });
        legend.onAdd = () => {
            const div = L.DomUtil.create('div', 'gis-legend');
            div.innerHTML = `
                <div class="gis-legend-title">
                    <span>🗺️ Map Legend</span>
                </div>
                <div class="gis-legend-item">
                    <span class="gis-legend-line" style="background:#d32f2f;"></span>
                    <span>Critical Risk Track</span>
                </div>
                <div class="gis-legend-item">
                    <span class="gis-legend-line" style="background:#f57c00;"></span>
                    <span>High Risk Track</span>
                </div>
                <div class="gis-legend-item">
                    <span class="gis-legend-line" style="background:#fbc02d;"></span>
                    <span>Medium Risk Track</span>
                </div>
                <div class="gis-legend-item">
                    <span class="gis-legend-symbol" style="background:#e65100; border:1px solid #ffe082;"></span>
                    <span>Known Hotspot Area</span>
                </div>
                <div class="gis-legend-item">
                    <span class="gis-legend-symbol" style="background:#d32f2f; border:1px solid #fff;"></span>
                    <span>Verified Sighting (Solid)</span>
                </div>
                <div class="gis-legend-item">
                    <span class="gis-legend-symbol" style="background:#f57c00; border:2px dashed #fff;"></span>
                    <span>Pending Report (Dashed)</span>
                </div>
                <div class="gis-legend-item">
                    <span class="gis-legend-line" style="background:#64b5f6; border-top:1px dashed #64b5f6; height:1px;"></span>
                    <span>Track Proximity Projection</span>
                </div>
            `;
            return div;
        };
        legend.addTo(this.map);
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
}

// Global expose
window.EleGuardMap = EleGuardMap;
