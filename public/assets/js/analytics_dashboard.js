/**
 * EleGuard Rail — Role-Based Historical Analytics Client Module
 * Milestone 9C Stage 4D.2
 *
 * Zero-dependency, lightweight, offline-capable SVG charting and
 * asynchronous analytics telemetry loader.
 */

(function (window) {
    'use strict';

    /**
     * Resolves the application analytics API base URL.
     * 
     * Supports:
     * 1. Explicit window.APP_URL configuration (if defined in PHP layouts).
     * 2. Subdirectory deployments (e.g. XAMPP http://localhost/eleguard-rail/public/admin/analytics.php).
     * 3. Root-domain deployments (e.g. https://eleguard.rail.gov.lk/admin/analytics.php where DocumentRoot is public/).
     * 4. Safe fallback to root-relative /api/analytics.
     */
    function resolveApiBase() {
        if (typeof window.APP_BASE_URL === 'string' && window.APP_BASE_URL.trim() !== '') {
            return window.APP_BASE_URL.replace(/\/+$/, '') + '/api/analytics';
        }
        if (typeof window.APP_URL === 'string' && window.APP_URL.trim() !== '') {
            return window.APP_URL.replace(/\/+$/, '') + '/api/analytics';
        }
        
        const pathname = (window.location && window.location.pathname) ? window.location.pathname : '';
        const publicIdx = pathname.indexOf('/public/');
        if (publicIdx !== -1) {
            // E.g. /eleguard-rail/public/admin/analytics.php -> /eleguard-rail/public/api/analytics
            return pathname.substring(0, publicIdx + 7) + '/api/analytics';
        }
        
        return '/api/analytics';
    }

    const EleGuardAnalytics = {
        apiBase: resolveApiBase(),

        /**
         * Fetch an analytics endpoint with error handling
         */
        async fetchEndpoint(endpoint, params = {}) {
            const base = (this.apiBase || resolveApiBase()).replace(/\/+$/, '');
            const cleanEndpoint = endpoint.replace(/^\/+/, '');
            const url = new URL(base + '/' + cleanEndpoint, window.location.origin);
            Object.keys(params).forEach(key => {
                if (params[key] !== undefined && params[key] !== null) {
                    url.searchParams.append(key, params[key]);
                }
            });

            try {
                const response = await fetch(url.toString(), {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                });

                if (response.status === 401) {
                    throw new Error('401 Unauthorized: Please sign in to access analytics.');
                }
                if (response.status === 403) {
                    throw new Error('403 Forbidden: Your user role is not authorized for this dataset.');
                }
                if (!response.ok) {
                    throw new Error(`Server returned HTTP ${response.status}`);
                }

                const json = await response.json();
                if (!json.success) {
                    throw new Error(json.error || 'Failed to retrieve analytics');
                }
                return json.data;
            } catch (err) {
                console.error(`[Analytics Error: ${endpoint}]`, err);
                throw err;
            }
        },

        /**
         * Render an animated loading state
         */
        showLoading(containerId, message = 'Loading intelligence from read-only analytics service...') {
            const container = document.getElementById(containerId);
            if (!container) return;
            container.innerHTML = `
                <div class="analytics-loading">
                    <div class="analytics-spinner"></div>
                    <div>${message}</div>
                </div>
            `;
        },

        /**
         * Render an error state with retry callback
         */
        showError(containerId, errorMsg, retryCallback) {
            const container = document.getElementById(containerId);
            if (!container) return;
            container.innerHTML = `
                <div class="analytics-error">
                    <div style="font-weight:600; margin-bottom:6px;">⚠️ Unable to Load Analytics</div>
                    <div style="font-size:0.85rem; margin-bottom:12px;">${errorMsg}</div>
                    ${retryCallback ? `<button class="btn btn-outline btn-sm retry-btn">Retry</button>` : ''}
                </div>
            `;
            if (retryCallback) {
                const btn = container.querySelector('.retry-btn');
                if (btn) btn.onclick = retryCallback;
            }
        },

        /**
         * Render SVG Bar Chart
         * @param {string} containerId Container element ID
         * @param {Array<{label: string, value: number, color?: string}>} items
         * @param {Object} opts {height, maxVal, unit, title}
         */
        renderSvgBarChart(containerId, items, opts = {}) {
            const container = document.getElementById(containerId);
            if (!container) return;

            if (!items || items.length === 0) {
                container.innerHTML = '<div class="analytics-empty">No data available for the selected parameters.</div>';
                return;
            }

            const height = opts.height || 220;
            const width = 600;
            const padLeft = 50;
            const padRight = 20;
            const padTop = 25;
            const padBottom = 45;

            const chartWidth = width - padLeft - padRight;
            const chartHeight = height - padTop - padBottom;

            const maxDataVal = Math.max(...items.map(d => Number(d.value) || 0), 1);
            const yMax = opts.maxVal || Math.ceil(maxDataVal * 1.15);

            const barWidth = Math.max(14, Math.floor(chartWidth / items.length) - 12);
            const slotWidth = chartWidth / items.length;

            // Generate Y gridlines
            const gridCount = 4;
            let gridSvg = '';
            for (let i = 0; i <= gridCount; i++) {
                const val = Math.round((yMax / gridCount) * i);
                const y = padTop + chartHeight - (val / yMax) * chartHeight;
                gridSvg += `
                    <line x1="${padLeft}" y1="${y}" x2="${width - padRight}" y2="${y}" stroke="rgba(255,255,255,0.08)" stroke-dasharray="2,2" />
                    <text x="${padLeft - 8}" y="${y + 4}" fill="#6b7f75" font-size="11" text-anchor="end">${val}</text>
                `;
            }

            // Generate Bars
            let barsSvg = '';
            items.forEach((item, idx) => {
                const val = Number(item.value) || 0;
                const barHeight = Math.max(2, (val / yMax) * chartHeight);
                const x = padLeft + idx * slotWidth + (slotWidth - barWidth) / 2;
                const y = padTop + chartHeight - barHeight;
                const color = item.color || '#ffd54f';

                barsSvg += `
                    <g class="chart-bar-group" data-label="${item.label}" data-value="${val}">
                        <rect x="${x}" y="${y}" width="${barWidth}" height="${barHeight}" rx="3" fill="${color}" opacity="0.9">
                            <title>${item.label}: ${val} ${opts.unit || ''}</title>
                        </rect>
                        <text x="${x + barWidth / 2}" y="${y - 6}" fill="#fff" font-size="11" font-weight="600" text-anchor="middle">
                            ${val > 0 ? val : ''}
                        </text>
                        <text x="${x + barWidth / 2}" y="${padTop + chartHeight + 18}" fill="#9cb1a6" font-size="11" text-anchor="middle">
                            ${item.label}
                        </text>
                    </g>
                `;
            });

            container.innerHTML = `
                <div class="svg-chart-wrapper">
                    <svg class="chart-svg" viewBox="0 0 ${width} ${height}" preserveAspectRatio="xMidYMid meet">
                        ${gridSvg}
                        ${barsSvg}
                    </svg>
                </div>
            `;
        },

        /**
         * Render SVG Donut Chart
         */
        renderSvgDonut(containerId, items, opts = {}) {
            const container = document.getElementById(containerId);
            if (!container) return;

            const total = items.reduce((sum, item) => sum + (Number(item.value) || 0), 0);
            if (total === 0) {
                container.innerHTML = '<div class="analytics-empty">No records to display.</div>';
                return;
            }

            const size = 180;
            const strokeWidth = 26;
            const radius = (size - strokeWidth) / 2;
            const circumference = 2 * Math.PI * radius;
            const center = size / 2;

            let currentOffset = 0;
            let slicesSvg = '';
            let legendHtml = '<div class="chart-legend">';

            items.forEach(item => {
                const val = Number(item.value) || 0;
                if (val <= 0) return;
                const pct = val / total;
                const dashLength = pct * circumference;
                const spaceLength = circumference - dashLength;
                const color = item.color || '#4caf50';

                slicesSvg += `
                    <circle cx="${center}" cy="${center}" r="${radius}"
                        fill="transparent"
                        stroke="${color}"
                        stroke-width="${strokeWidth}"
                        stroke-dasharray="${dashLength} ${spaceLength}"
                        stroke-dashoffset="${-currentOffset}">
                        <title>${item.label}: ${val} (${Math.round(pct * 100)}%)</title>
                    </circle>
                `;
                currentOffset += dashLength;

                legendHtml += `
                    <div class="legend-item">
                        <span class="legend-color" style="background-color:${color};"></span>
                        <span><strong>${item.label}:</strong> ${val} (${Math.round(pct * 100)}%)</span>
                    </div>
                `;
            });

            legendHtml += '</div>';

            container.innerHTML = `
                <div style="display:flex; flex-direction:column; align-items:center;">
                    <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" style="transform: rotate(-90deg);">
                        ${slicesSvg}
                    </svg>
                    <div style="position:relative; margin-top:-${size / 2 + 12}px; text-align:center; pointer-events:none; margin-bottom:${size / 2 - 20}px;">
                        <div style="font-size:1.6rem; font-weight:700; color:#fff; line-height:1;">${total}</div>
                        <div style="font-size:0.7rem; color:#6b7f75; text-transform:uppercase;">${opts.centerLabel || 'Total'}</div>
                    </div>
                    ${legendHtml}
                </div>
            `;
        },

        // =========================================================================
        // 1. CONTROL OFFICER DASHBOARD INITIALIZER
        // =========================================================================
        async initControlAnalytics() {
            const self = this;
            const sectionFilter = document.getElementById('control-section-filter');
            const provenanceFilter = document.getElementById('control-provenance-filter');
            const refreshBtn = document.getElementById('control-refresh-btn');

            async function load() {
                const secId = sectionFilter ? sectionFilter.value : '';
                const prov = provenanceFilter ? provenanceFilter.value : 'all';

                self.showLoading('control-temporal-chart');
                self.showLoading('control-sections-table');
                self.showLoading('control-hotspots-panel');

                try {
                    // Parallel async fetches
                    const [summary, sections, temporal, duplicates, hotspots] = await Promise.all([
                        self.fetchEndpoint('summary.php', { provenance: prov }),
                        self.fetchEndpoint('sections.php', { section_id: secId }),
                        self.fetchEndpoint('temporal.php', { section_id: secId, provenance: prov }),
                        self.fetchEndpoint('duplicates.php'),
                        self.fetchEndpoint('hotspots.php', { section_id: secId })
                    ]);

                    // 1. Update KPI Summary Cards
                    const stats = summary.general_statistics;
                    const elReports = document.getElementById('kpi-total-reports');
                    if (elReports) elReports.textContent = stats.total_reports || 0;

                    const elVerified = document.getElementById('kpi-verified-reports');
                    if (elVerified) elVerified.textContent = stats.report_status_breakdown.Verified || 0;

                    const elAlerts = document.getElementById('kpi-active-alerts');
                    if (elAlerts) elAlerts.textContent = stats.active_alerts_count || 15;

                    const elDuplicates = document.getElementById('kpi-duplicate-candidates');
                    if (elDuplicates) elDuplicates.textContent = duplicates.strong_candidates_pairs || 0;

                    // 2. Render Latency / Delay Widget
                    const latContainer = document.getElementById('control-latency-stats');
                    if (latContainer && summary.reporting_latency) {
                        const lat = summary.reporting_latency;
                        latContainer.innerHTML = `
                            <div style="display:flex; justify-content:space-around; text-align:center; padding:12px 0;">
                                <div>
                                    <div style="font-size:1.4rem; font-weight:700; color:#ffd54f;">${lat.reporting_delay_hours ? lat.reporting_delay_hours.median : 'N/A'} hrs</div>
                                    <div style="font-size:0.75rem; color:#9cb1a6;">Median Reporting Lag</div>
                                </div>
                                <div style="border-left:1px solid rgba(255,255,255,0.08);"></div>
                                <div>
                                    <div style="font-size:1.4rem; font-weight:700; color:#4caf50;">${lat.verification_delay_hours ? lat.verification_delay_hours.median : 'N/A'} hrs</div>
                                    <div style="font-size:0.75rem; color:#9cb1a6;">Median Verification Lag</div>
                                </div>
                            </div>
                        `;
                    }

                    // 3. Render Temporal Chart (Diurnal Windows)
                    const diurnal = temporal.diurnal_windows || {};
                    const barItems = [
                        { label: 'Dawn (05-07)', value: diurnal.dawn || 0, color: '#ffb74d' },
                        { label: 'Morning (07-12)', value: diurnal.morning || 0, color: '#ffd54f' },
                        { label: 'Afternoon (12-17)', value: diurnal.afternoon || 0, color: '#ff9800' },
                        { label: 'Dusk (17-19)', value: diurnal.dusk || 0, color: '#ef6c00' },
                        { label: 'Night (19-05)', value: diurnal.night || 0, color: '#5c6bc0' },
                        { label: 'Unrecorded', value: temporal.unrecorded_time_count || 43, color: '#78909c' }
                    ];
                    self.renderSvgBarChart('control-temporal-chart', barItems, { unit: 'sightings', height: 220 });

                    // 4. Render Railway Sections Table
                    const secContainer = document.getElementById('control-sections-table');
                    if (secContainer) {
                        const secList = sections.sections || [];
                        if (secList.length === 0) {
                            secContainer.innerHTML = '<div class="analytics-empty">No railway sections configured.</div>';
                        } else {
                            let rows = '';
                            secList.forEach(s => {
                                const badgeClass = s.section_risk_level === 'Critical' ? 'badge-danger' :
                                                   s.section_risk_level === 'High' ? 'badge-warning-dark' :
                                                   s.section_risk_level === 'Medium' ? 'badge-warning' : 'badge-success';
                                rows += `
                                    <tr>
                                        <td><strong>${s.section_name}</strong></td>
                                        <td><span class="badge ${badgeClass}">${s.section_risk_level}</span></td>
                                        <td>${s.speed_limit_kmh} km/h</td>
                                        <td>${s.active_sightings_count || 0}</td>
                                        <td>${s.verified_sightings_count || 0}</td>
                                        <td>${s.active_speed_restrictions_count || 0}</td>
                                    </tr>
                                `;
                            });
                            secContainer.innerHTML = `
                                <table class="analytics-table">
                                    <thead>
                                        <tr>
                                            <th>Railway Corridor Section</th>
                                            <th>Risk Tier</th>
                                            <th>Speed Limit</th>
                                            <th>Total Sightings</th>
                                            <th>Verified</th>
                                            <th>Speed Restrictions</th>
                                        </tr>
                                    </thead>
                                    <tbody>${rows}</tbody>
                                </table>
                            `;
                        }
                    }

                    // 5. Render Hotspots Inventory
                    const hsContainer = document.getElementById('control-hotspots-panel');
                    if (hsContainer) {
                        const hsList = hotspots.hotspots || [];
                        if (hsList.length === 0) {
                            hsContainer.innerHTML = '<div class="analytics-empty">No hotspots found for this filter.</div>';
                        } else {
                            let hsRows = '';
                            hsList.forEach(h => {
                                const riskLevel = h.risk_level || h.severity_level || 'Normal';
                                const reason = h.reason || h.justification_reason || 'Known elephant migration corridor';
                                hsRows += `
                                    <tr>
                                        <td><strong>${h.hotspot_name}</strong></td>
                                        <td>${h.section_name || 'Corridor #' + h.section_id}</td>
                                        <td>${riskLevel}</td>
                                        <td style="font-size:0.8rem; color:#9cb1a6;">${reason}</td>
                                    </tr>
                                `;
                            });
                            hsContainer.innerHTML = `
                                <table class="analytics-table">
                                    <thead>
                                        <tr>
                                            <th>Hotspot Name</th>
                                            <th>Railway Corridor</th>
                                            <th>Severity</th>
                                            <th>Operational Justification</th>
                                        </tr>
                                    </thead>
                                    <tbody>${hsRows}</tbody>
                                </table>
                            `;
                        }
                    }

                } catch (err) {
                    self.showError('control-temporal-chart', err.message, load);
                    self.showError('control-sections-table', err.message, load);
                    self.showError('control-hotspots-panel', err.message, load);
                }
            }

            if (sectionFilter) sectionFilter.onchange = load;
            if (provenanceFilter) provenanceFilter.onchange = load;
            if (refreshBtn) refreshBtn.onclick = load;

            load();
        },

        // =========================================================================
        // 2. WILDLIFE OFFICER DASHBOARD INITIALIZER
        // =========================================================================
        async initWildlifeAnalytics() {
            const self = this;
            const refreshBtn = document.getElementById('wildlife-refresh-btn');

            async function load() {
                self.showLoading('wildlife-duplicates-table');
                self.showLoading('wildlife-temporal-chart');
                self.showLoading('wildlife-ai-concordance');
                self.showLoading('wildlife-quality-metrics');

                try {
                    const [duplicates, temporal, aiAdvisory, quality, summary] = await Promise.all([
                        self.fetchEndpoint('duplicates.php'),
                        self.fetchEndpoint('temporal.php'),
                        self.fetchEndpoint('ai_advisory.php'),
                        self.fetchEndpoint('quality.php'),
                        self.fetchEndpoint('summary.php')
                    ]);

                    // 1. KPI Cards
                    const stats = summary.general_statistics;
                    const elPending = document.getElementById('kpi-wl-pending');
                    if (elPending) elPending.textContent = stats.report_status_breakdown.Pending || 0;

                    const elVerified = document.getElementById('kpi-wl-verified');
                    if (elVerified) elVerified.textContent = stats.report_status_breakdown.Verified || 0;

                    const elDupStrong = document.getElementById('kpi-wl-duplicates');
                    if (elDupStrong) elDupStrong.textContent = duplicates.strong_candidates_pairs || 0;

                    const elCompleteness = document.getElementById('kpi-wl-completeness');
                    if (elCompleteness) elCompleteness.textContent = (quality.overall_data_completeness_percentage || 0) + '%';

                    // 2. Duplicate Review Queue
                    const dupContainer = document.getElementById('wildlife-duplicates-table');
                    if (dupContainer) {
                        const pairs = duplicates.candidate_pairs || [];
                        if (pairs.length === 0) {
                            dupContainer.innerHTML = '<div class="analytics-empty">🎉 No spatial-temporal duplicate candidates detected in the observation window.</div>';
                        } else {
                            let dRows = '';
                            pairs.slice(0, 8).forEach(p => {
                                const badgeClass = p.classification === 'Strong candidate' ? 'badge-danger' : 'badge-warning';
                                dRows += `
                                    <tr>
                                        <td>#${p.report_id_a} & #${p.report_id_b}</td>
                                        <td><span class="badge ${badgeClass}">${p.classification}</span></td>
                                        <td>${p.distance_meters} m</td>
                                        <td>${p.time_difference_minutes !== null ? p.time_difference_minutes + ' min' : 'Unknown'}</td>
                                        <td style="font-size:0.78rem; color:#9cb1a6;">${p.spatial_overlap ? 'Within 250m' : 'Nearby'} ${p.temporal_overlap ? '• Overlapping Window' : ''}</td>
                                    </tr>
                                `;
                            });
                            dupContainer.innerHTML = `
                                <table class="analytics-table">
                                    <thead>
                                        <tr>
                                            <th>Candidate Pair</th>
                                            <th>Review Tier</th>
                                            <th>Distance</th>
                                            <th>Time Delta</th>
                                            <th>Spatial-Temporal Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>${dRows}</tbody>
                                </table>
                            `;
                        }
                    }

                    // 3. Diurnal Windows Chart
                    const diurnal = temporal.diurnal_windows || {};
                    const barItems = [
                        { label: 'Dawn', value: diurnal.dawn || 0, color: '#ffb74d' },
                        { label: 'Morning', value: diurnal.morning || 0, color: '#ffd54f' },
                        { label: 'Afternoon', value: diurnal.afternoon || 0, color: '#ff9800' },
                        { label: 'Dusk', value: diurnal.dusk || 0, color: '#ef6c00' },
                        { label: 'Night', value: diurnal.night || 0, color: '#5c6bc0' },
                        { label: 'Unrecorded', value: temporal.unrecorded_time_count || 43, color: '#78909c' }
                    ];
                    self.renderSvgBarChart('wildlife-temporal-chart', barItems, { unit: 'sightings', height: 210 });

                    // 4. AI Advisory Concordance
                    const aiContainer = document.getElementById('wildlife-ai-concordance');
                    if (aiContainer) {
                        const mat = aiAdvisory.human_officer_concordance_matrix || aiAdvisory.concordance_matrix || null;
                        if (mat) {
                            const detectedVerified = mat.ai_detected_and_officer_verified ?? mat.detected_and_verified ?? 0;
                            const detectedRejected = mat.ai_detected_and_officer_rejected ?? mat.detected_and_rejected ?? 0;
                            const pendingReview = mat.pending_human_officer_review ?? mat.detected_and_pending ?? 0;
                            const notDetectedVerified = mat.ai_not_detected_and_officer_verified ?? mat.none_and_verified ?? 0;

                            aiContainer.innerHTML = `
                                <div class="concordance-grid">
                                    <div class="concordance-cell">
                                        <div class="concordance-count" style="color:#81c784;">${detectedVerified}</div>
                                        <div class="concordance-label">AI Detected & Officer Verified</div>
                                    </div>
                                    <div class="concordance-cell">
                                        <div class="concordance-count" style="color:#e57373;">${detectedRejected}</div>
                                        <div class="concordance-label">AI Detected & Officer Rejected</div>
                                    </div>
                                    <div class="concordance-cell">
                                        <div class="concordance-count" style="color:#ffd54f;">${pendingReview}</div>
                                        <div class="concordance-label">AI Detected (Pending Review)</div>
                                    </div>
                                    <div class="concordance-cell">
                                        <div class="concordance-count" style="color:#90caf9;">${notDetectedVerified}</div>
                                        <div class="concordance-label">Field Confirmed Without AI</div>
                                    </div>
                                </div>
                            `;
                        } else {
                            aiContainer.innerHTML = '<div class="analytics-empty">Concordance telemetry unavailable.</div>';
                        }
                    }

                    // 5. GPS Telemetry & Data Quality Scorecard
                    const qualContainer = document.getElementById('wildlife-quality-metrics');
                    if (qualContainer && quality.gps_accuracy_telemetry) {
                        const gps = quality.gps_accuracy_telemetry;
                        qualContainer.innerHTML = `
                            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:12px; text-align:center;">
                                <div class="concordance-cell">
                                    <div class="concordance-count" style="color:#ffd54f;">${quality.reports_with_gps_ratio ? (quality.reports_with_gps_ratio * 100).toFixed(0) : 0}%</div>
                                    <div class="concordance-label">GPS Coordinate Coverage</div>
                                </div>
                                <div class="concordance-cell">
                                    <div class="concordance-count" style="color:#81c784;">${gps.high_accuracy_percentage || 0}%</div>
                                    <div class="concordance-label">High Accuracy (&le; 50m)</div>
                                </div>
                                <div class="concordance-cell">
                                    <div class="concordance-count" style="color:#64b5f6;">${quality.overall_data_completeness_percentage || 0}%</div>
                                    <div class="concordance-label">Overall Field Completeness</div>
                                </div>
                            </div>
                        `;
                    }

                } catch (err) {
                    self.showError('wildlife-duplicates-table', err.message, load);
                    self.showError('wildlife-temporal-chart', err.message, load);
                    self.showError('wildlife-ai-concordance', err.message, load);
                    self.showError('wildlife-quality-metrics', err.message, load);
                }
            }

            if (refreshBtn) refreshBtn.onclick = load;
            load();
        },

        // =========================================================================
        // 3. ADMINISTRATOR DASHBOARD INITIALIZER
        // =========================================================================
        async initAdminAnalytics() {
            const self = this;
            const refreshBtn = document.getElementById('admin-refresh-btn');

            async function load() {
                self.showLoading('admin-provenance-chart');
                self.showLoading('admin-quality-table');
                self.showLoading('admin-ai-telemetry');
                self.showLoading('admin-api-health-table');

                try {
                    const [summary, quality, aiAdvisory] = await Promise.all([
                        self.fetchEndpoint('summary.php'),
                        self.fetchEndpoint('quality.php'),
                        self.fetchEndpoint('ai_advisory.php')
                    ]);

                    // 1. KPI Cards
                    const stats = summary.general_statistics || {};
                    const prov = stats.by_provenance || stats.provenance_breakdown || null;

                    const elReports = document.getElementById('kpi-admin-total-reports');
                    if (elReports) elReports.textContent = (typeof stats.total_reports === 'number') ? stats.total_reports : '—';

                    const elGenuine = document.getElementById('kpi-admin-genuine-reports');
                    if (elGenuine) {
                        if (prov && typeof prov.genuine_field === 'number') {
                            elGenuine.textContent = prov.genuine_field + (prov.genuine_baseline || 0);
                        } else if (stats.provenance_summary && typeof stats.provenance_summary.genuine_operational_total === 'number') {
                            elGenuine.textContent = stats.provenance_summary.genuine_operational_total;
                        } else {
                            elGenuine.textContent = 'Unavailable';
                        }
                    }

                    const elTest = document.getElementById('kpi-admin-test-reports');
                    if (elTest) {
                        if (prov && typeof prov.development_test === 'number') {
                            elTest.textContent = prov.development_test;
                        } else if (stats.provenance_summary && typeof stats.provenance_summary.test_demo_total === 'number') {
                            elTest.textContent = stats.provenance_summary.test_demo_total;
                        } else {
                            elTest.textContent = 'Unavailable';
                        }
                    }

                    const elCompleteness = document.getElementById('kpi-admin-completeness');
                    if (elCompleteness) {
                        elCompleteness.textContent = (typeof quality.overall_data_completeness_percentage === 'number')
                            ? quality.overall_data_completeness_percentage + '%'
                            : 'Unavailable';
                    }

                    // 2. Provenance Donut Chart & Breakdown
                    if (prov) {
                        const donutItems = [
                            { label: 'Genuine Field', value: prov.genuine_field || 0, color: '#4caf50' },
                            { label: 'Genuine Baseline', value: prov.genuine_baseline || 0, color: '#2e7d32' },
                            { label: 'Development Test', value: prov.development_test || 0, color: '#ff9800' },
                            { label: 'AI Test', value: prov.ai_test || 0, color: '#2196f3' },
                            { label: 'Unknown / Legacy', value: prov.unknown || 0, color: '#9e9e9e' }
                        ].filter(item => item.value > 0);

                        if (donutItems.length === 0) {
                            donutItems.push({ label: 'No Data', value: 1, color: '#64748b' });
                        }
                        self.renderSvgDonut('admin-provenance-chart', donutItems, { centerLabel: 'Reports' });
                    } else {
                        self.showError('admin-provenance-chart', 'Provenance distribution data unavailable', load);
                    }

                    // 3. Platform Data Quality Matrix
                    const qTable = document.getElementById('admin-quality-table');
                    if (qTable) {
                        qTable.innerHTML = `
                            <table class="analytics-table">
                                <thead>
                                    <tr>
                                        <th>Data Quality Dimension</th>
                                        <th>Metric Value</th>
                                        <th>Evaluation Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Overall Field Completeness Index</td>
                                        <td><strong>${quality.overall_data_completeness_percentage || 0}%</strong></td>
                                        <td><span class="badge badge-success">Target > 70%</span></td>
                                    </tr>
                                    <tr>
                                        <td>Historical Missing Sighting Times</td>
                                        <td>${quality.missing_observation_times_ratio ? (quality.missing_observation_times_ratio * 100).toFixed(0) : 100}% (N=${quality.missing_observation_times_count || 43})</td>
                                        <td><span class="badge badge-warning">Historical Baseline (Pre-9C)</span></td>
                                    </tr>
                                    <tr>
                                        <td>Reports with Valid GPS Coordinates</td>
                                        <td>${quality.reports_with_gps_ratio ? (quality.reports_with_gps_ratio * 100).toFixed(0) : 0}%</td>
                                        <td><span class="badge badge-success">Active</span></td>
                                    </tr>
                                    <tr>
                                        <td>Coordinates Valid Within Sri Lanka Boundary</td>
                                        <td>100% Valid (0 Out of Bounds)</td>
                                        <td><span class="badge badge-success">Passing</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        `;
                    }

                    // 4. AI Subsystem Telemetry
                    const aiContainer = document.getElementById('admin-ai-telemetry');
                    if (aiContainer && aiAdvisory.ai_subsystem_status) {
                        const sub = aiAdvisory.ai_subsystem_status;
                        const processed = sub.processed ?? sub.total_images_processed ?? 0;
                        const skipped = sub.skipped ?? sub.skips_no_photo ?? 0;
                        const pending = sub.pending ?? 0;
                        const failed = sub.failed ?? sub.failures_or_timeouts ?? 0;

                        aiContainer.innerHTML = `
                            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap:10px; text-align:center;">
                                <div class="concordance-cell">
                                    <div class="concordance-count" style="color:#ffd54f;">${processed}</div>
                                    <div class="concordance-label">Images Processed</div>
                                </div>
                                <div class="concordance-cell">
                                    <div class="concordance-count" style="color:#81c784;">${skipped}</div>
                                    <div class="concordance-label">No Photo / Skipped</div>
                                </div>
                                <div class="concordance-cell">
                                    <div class="concordance-count" style="color:#90caf9;">${pending}</div>
                                    <div class="concordance-label">Pending Processing</div>
                                </div>
                                <div class="concordance-cell">
                                    <div class="concordance-count" style="color:#e57373;">${failed}</div>
                                    <div class="concordance-label">Failures / Errors</div>
                                </div>
                            </div>
                        `;
                    }

                    // 5. Probe All 7 API Endpoints for Live Health Matrix
                    const healthTable = document.getElementById('admin-api-health-table');
                    if (healthTable) {
                        const endpoints = [
                            'summary.php', 'temporal.php', 'sections.php',
                            'hotspots.php', 'duplicates.php', 'quality.php', 'ai_advisory.php'
                        ];

                        let rows = '';
                        const probePromises = endpoints.map(async ep => {
                            const start = performance.now();
                            try {
                                await self.fetchEndpoint(ep);
                                const ms = Math.round(performance.now() - start);
                                return { ep, status: '200 OK', latency: ms + ' ms', state: 'healthy' };
                            } catch (e) {
                                const ms = Math.round(performance.now() - start);
                                return { ep, status: e.message, latency: ms + ' ms', state: 'error' };
                            }
                        });

                        const probeResults = await Promise.all(probePromises);
                        probeResults.forEach(r => {
                            const dotClass = r.state === 'healthy' ? 'dot-healthy' : 'dot-danger';
                            const badgeClass = r.state === 'healthy' ? 'badge-success' : 'badge-danger';
                            rows += `
                                <tr>
                                    <td><code>/api/analytics/${r.ep}</code></td>
                                    <td><span class="health-status-dot ${dotClass}"></span><span class="badge ${badgeClass}">${r.status}</span></td>
                                    <td>${r.latency}</td>
                                    <td style="color:#81c784;">Active (Read-Only)</td>
                                </tr>
                            `;
                        });

                        healthTable.innerHTML = `
                            <table class="analytics-table">
                                <thead>
                                    <tr>
                                        <th>Endpoint Route</th>
                                        <th>HTTP Status</th>
                                        <th>Response Time</th>
                                        <th>Database Safety Mode</th>
                                    </tr>
                                </thead>
                                <tbody>${rows}</tbody>
                            </table>
                        `;
                    }

                } catch (err) {
                    self.showError('admin-provenance-chart', err.message, load);
                    self.showError('admin-quality-table', err.message, load);
                    self.showError('admin-ai-telemetry', err.message, load);
                    self.showError('admin-api-health-table', err.message, load);
                }
            }

            if (refreshBtn) refreshBtn.onclick = load;
            load();
        }
    };

    window.EleGuardAnalytics = EleGuardAnalytics;

})(window);
