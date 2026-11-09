#!/usr/bin/env python3
"""
EleGuard Rail - Milestone 9B: Master Hotspot Analytics & Visualization Engine
Strict Read-Only Script.

Generates the 6 required analytical charts and the evidence classification structure.
Adheres strictly to scientific standards:
- Explicit sample sizes (N=3 historical, N=33 test, N=3 demo, N=4 audit).
- No deceptive KDE heatmaps.
- Metric units (m / km).
- No exposed raw sensitive coordinates.
- Clear distinction between administrative surveyed hotspots and empirical sightings.
"""

import json
import os
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt
import numpy as np

def main():
    base_dir = os.path.dirname(os.path.abspath(__file__))
    docs_dir = os.path.join(base_dir, '..', '..', 'docs', 'phase9')
    vis_dir = os.path.join(docs_dir, 'visualizations')
    os.makedirs(vis_dir, exist_ok=True)

    hotspot_file = os.path.join(docs_dir, 'hotspot_inventory.json')
    spatial_file = os.path.join(docs_dir, 'hotspot_spatial_analysis.json')
    section_file = os.path.join(docs_dir, 'hotspot_section_analysis.json')

    with open(hotspot_file, 'r', encoding='utf-8') as f:
        hotspot_data = json.load(f)
    with open(spatial_file, 'r', encoding='utf-8') as f:
        spatial_data = json.load(f)
    with open(section_file, 'r', encoding='utf-8') as f:
        section_data = json.load(f)

    hotspots = hotspot_data['hotspots']
    reports = spatial_data['reports']

    # --- 1. Evidence Classification Structure ---
    evidence_classifications = [
        {
            'hotspot_id': 1,
            'name': 'Minneriya Elephant Crossing',
            'assigned_section': 'Section 2 (Minneriya - Hingurakgoda)',
            'recorded_risk_level': 'Critical',
            'administrative_tier': 'Existing Mapped Hotspot',
            'empirical_evidence_tier': 'Insufficient Empirical Evidence',
            'historical_baseline_alignment': 'Qualitative Match (Report ID 2: "Near Minneriya reservoir bund", no GPS)',
            'synthetic_test_coverage': '11 automated test runs + 1 demo report co-located within 50m (synthetic)',
            'recommendation': 'Retain in database as surveyed corridor; deploy GPS field logging to capture authentic movement patterns.'
        },
        {
            'hotspot_id': 2,
            'name': 'Habarana Forest Edge',
            'assigned_section': 'Section 1 (Habarana - Gal Oya)',
            'recorded_risk_level': 'High',
            'administrative_tier': 'Existing Mapped Hotspot',
            'empirical_evidence_tier': 'Insufficient Empirical Evidence',
            'historical_baseline_alignment': 'Qualitative Match (Report ID 1: "Near mile post 127/4", no GPS)',
            'synthetic_test_coverage': '1 demo report at 562m; 4 E2E audit reports at 109m (verification tests)',
            'recommendation': 'Retain in database; recommend authorized environmental and railway field assessment before any trackside interventions.'
        },
        {
            'hotspot_id': 3,
            'name': 'Gal Oya Corridor',
            'assigned_section': 'Section 1 (Habarana - Gal Oya)',
            'recorded_risk_level': 'High',
            'administrative_tier': 'Existing Mapped Hotspot',
            'empirical_evidence_tier': 'Insufficient Empirical Evidence',
            'historical_baseline_alignment': 'Qualitative Match (Report ID 3: "Near Gal Oya junction approach", no GPS)',
            'synthetic_test_coverage': '11 automated test runs at exact coordinates (0m, synthetic test artifacts)',
            'recommendation': 'Retain in database; resolve junction boundary definition with Railway Department.'
        },
        {
            'hotspot_id': 4,
            'name': 'Night Movement Zone 01',
            'assigned_section': 'Section 3 (Maho - Anuradhapura)',
            'recorded_risk_level': 'Medium',
            'administrative_tier': 'Existing Mapped Hotspot',
            'empirical_evidence_tier': 'Zero Observational Support',
            'historical_baseline_alignment': 'No historical reports in database for Section 3',
            'synthetic_test_coverage': '0 test reports in database for Section 3',
            'recommendation': 'Retain surveyed boundary; conduct targeted officer reconnaissance to verify activity.'
        }
    ]

    with open(os.path.join(docs_dir, 'hotspot_evidence_classification.json'), 'w', encoding='utf-8') as f:
        json.dump({'evidence_classifications': evidence_classifications}, f, indent=2, ensure_ascii=False)

    # Style settings for scientific publication quality
    plt.rcParams['font.sans-serif'] = 'Arial'
    plt.rcParams['axes.edgecolor'] = '#333333'
    plt.rcParams['axes.linewidth'] = 0.8

    # =========================================================================
    # CHART 1: Hotspot Spatial Distribution (Relative Spatial Layout)
    # =========================================================================
    fig, ax = plt.subplots(figsize=(9, 6), dpi=300)
    
    # Plot relative offsets in km centered around study corridor centroid (8.04°N, 80.82°E)
    ref_lat, ref_lon = 8.04, 80.82
    colors = {'Critical': '#d9534f', 'High': '#f0ad4e', 'Medium': '#5bc0de'}
    
    for h in hotspots:
        d_north = (h['latitude'] - ref_lat) * 111.0 # approx km
        d_east = (h['longitude'] - ref_lon) * 111.0 * np.cos(np.radians(ref_lat))
        c = colors.get(h['risk_level'], '#777777')
        ax.scatter(d_east, d_north, color=c, s=180, zorder=5, edgecolor='black', linewidth=1.2)
        ax.annotate(
            f"HS {h['id']}: {h['name']}\n[{h['risk_level']} Risk | Sec {h['assigned_section']['section_id']}]",
            (d_east, d_north),
            xytext=(10, -5 if d_north < 0 else 5),
            textcoords='offset points',
            fontsize=8.5,
            fontweight='bold',
            bbox=dict(boxstyle='round,pad=0.3', facecolor='white', alpha=0.85, edgecolor='#cccccc')
        )

    ax.axhline(0, color='#dddddd', linestyle='--', linewidth=0.8)
    ax.axvline(0, color='#dddddd', linestyle='--', linewidth=0.8)
    ax.set_title("Surveyed Hotspot Relative Spatial Layout (N=4 Surveyed Hotspots)\nEleGuard Rail Spatial Intelligence Corridor", fontsize=12, fontweight='bold', pad=12)
    ax.set_xlabel("Relative East-West Offset from Corridor Center (km)", fontsize=10)
    ax.set_ylabel("Relative North-South Offset from Corridor Center (km)", fontsize=10)
    ax.grid(True, linestyle=':', alpha=0.6)
    
    # Legend
    legend_elements = [
        plt.Line2D([0], [0], marker='o', color='w', label='Critical Risk (Minneriya)', markerfacecolor='#d9534f', markersize=10),
        plt.Line2D([0], [0], marker='o', color='w', label='High Risk (Habarana, Gal Oya)', markerfacecolor='#f0ad4e', markersize=10),
        plt.Line2D([0], [0], marker='o', color='w', label='Medium Risk (Maho Zone)', markerfacecolor='#5bc0de', markersize=10)
    ]
    ax.legend(handles=legend_elements, loc='lower left', framealpha=0.95, fontsize=9)
    plt.tight_layout()
    chart1_path = os.path.join(vis_dir, 'hotspot_spatial_distribution.png')
    plt.savefig(chart1_path)
    plt.close()

    # =========================================================================
    # CHART 2: Hotspot to Railway Distance (km)
    # =========================================================================
    fig, ax = plt.subplots(figsize=(8.5, 5), dpi=300)
    names = [f"HS {h['id']}:\n{h['name']}" for h in hotspots]
    dist_assigned = [h['assigned_section']['distance_to_track_km'] for h in hotspots]
    dist_nearest = [h['geometric_nearest_section']['distance_to_track_km'] for h in hotspots]

    x = np.arange(len(names))
    width = 0.35

    rects1 = ax.bar(x - width/2, dist_assigned, width, label='Distance to Assigned Section Track (km)', color='#337ab7')
    rects2 = ax.bar(x + width/2, dist_nearest, width, label='Distance to Nearest Track LineString (km)', color='#5cb85c')

    ax.set_title("Geodesic Distance from Surveyed Hotspots to Railway Track Geometries\n(Comparing Assigned Section Track vs Closest Physical Track)", fontsize=11, fontweight='bold', pad=12)
    ax.set_ylabel("Geodesic Distance (km)", fontsize=10)
    ax.set_xticks(x)
    ax.set_xticklabels(names, fontsize=9)
    ax.grid(axis='y', linestyle=':', alpha=0.6)
    ax.legend(framealpha=0.95, fontsize=9)

    for r in rects1:
        h = r.get_height()
        ax.annotate(f"{h:.2f} km", xy=(r.get_x() + r.get_width()/2, h), xytext=(0, 3),
                    textcoords="offset points", ha='center', va='bottom', fontsize=8, fontweight='bold')
    for r in rects2:
        h = r.get_height()
        ax.annotate(f"{h:.2f} km", xy=(r.get_x() + r.get_width()/2, h), xytext=(0, 3),
                    textcoords="offset points", ha='center', va='bottom', fontsize=8, fontweight='bold')

    plt.tight_layout()
    chart2_path = os.path.join(vis_dir, 'hotspot_to_railway_distance.png')
    plt.savefig(chart2_path)
    plt.close()

    # =========================================================================
    # CHART 3: Report-to-Hotspot Distance Distribution
    # =========================================================================
    fig, ax = plt.subplots(figsize=(9, 5), dpi=300)
    bands = spatial_data['supplementary_gps_population_analysis']['distance_band_distribution']
    labels = [
        '< 25 m\n(Co-located)',
        '25 m - 100 m\n(Immediate)',
        '100 m - 1 km\n(Corridor)',
        '1 km - 5 km\n(Landscape)',
        '5 km - 20 km\n(Regional)'
    ]
    counts = [
        bands['co_located_under_25m'],
        bands['immediate_vicinity_under_100m'],
        bands['corridor_100m_to_1km'],
        bands['landscape_1km_to_5km'],
        bands['regional_5km_to_20km']
    ]

    bar_colors = ['#d9534f', '#f0ad4e', '#337ab7', '#5bc0de', '#777777']
    bars = ax.bar(labels, counts, color=bar_colors, edgecolor='black', linewidth=0.8, width=0.55)

    ax.set_title("Distribution of Geodesic Distances from GPS Reports to Nearest Hotspot\n(Supplementary Population N=29 GPS Reports; Primary N=3 Baseline Excluded due to Missing GPS)", fontsize=11, fontweight='bold', pad=12)
    ax.set_ylabel("Report Count", fontsize=10)
    ax.grid(axis='y', linestyle=':', alpha=0.6)

    for b in bars:
        h = b.get_height()
        ax.annotate(f"N={h}\n({h/29.0*100:.1f}%)", xy=(b.get_x() + b.get_width()/2, h),
                    xytext=(0, 4), textcoords="offset points", ha='center', va='bottom', fontsize=8.5, fontweight='bold')

    plt.tight_layout()
    chart3_path = os.path.join(vis_dir, 'report_to_hotspot_distance_distribution.png')
    plt.savefig(chart3_path)
    plt.close()

    # =========================================================================
    # CHART 4: Railway Section Hotspot & Report Breakdown
    # =========================================================================
    fig, ax = plt.subplots(figsize=(9, 5), dpi=300)
    sec_names = [
        "Sec 1:\nHabarana - Gal Oya",
        "Sec 2:\nMinneriya - Hingurakgoda",
        "Sec 3:\nMaho - Anuradhapura",
        "Sec 4:\nBatticaloa Forest"
    ]
    hotspot_counts = [2, 1, 1, 0] # HS 2 & 3 in Sec 1, HS 1 in Sec 2, HS 4 in Sec 3
    baseline_counts = [2, 1, 0, 0] # Reports 1 & 3 in Sec 1, Report 2 in Sec 2
    test_demo_counts = [15, 25, 0, 0] # Total 40 test/demo/audit reports assigned

    x = np.arange(len(sec_names))
    width = 0.25

    r1 = ax.bar(x - width, hotspot_counts, width, label='Surveyed Hotspots (N=4)', color='#d9534f')
    r2 = ax.bar(x, baseline_counts, width, label='Historical Baseline Reports (N=3)', color='#2e6da4')
    r3 = ax.bar(x + width, test_demo_counts, width, label='Test / Demo / Audit Reports (N=40)', color='#f0ad4e')

    ax.set_title("Inventory Breakdown by Railway Section: Hotspots vs Report Provenance\n(Strict Stratification: Operational Historical Baseline vs Synthetic Artifacts)", fontsize=11, fontweight='bold', pad=12)
    ax.set_ylabel("Record Count", fontsize=10)
    ax.set_xticks(x)
    ax.set_xticklabels(sec_names, fontsize=9)
    ax.grid(axis='y', linestyle=':', alpha=0.6)
    ax.legend(framealpha=0.95, fontsize=8.5)

    for bar_group in [r1, r2, r3]:
        for b in bar_group:
            h = b.get_height()
            if h > 0:
                ax.annotate(f"{h}", xy=(b.get_x() + b.get_width()/2, h),
                            xytext=(0, 3), textcoords="offset points", ha='center', va='bottom', fontsize=8, fontweight='bold')

    plt.tight_layout()
    chart4_path = os.path.join(vis_dir, 'railway_section_hotspot_breakdown.png')
    plt.savefig(chart4_path)
    plt.close()

    # =========================================================================
    # CHART 5: Provenance Classification Breakdown (Total Population N=43)
    # =========================================================================
    fig, (ax1, ax2) = plt.subplots(1, 2, figsize=(10, 4.8), dpi=300)
    prov_labels = [
        'Confirmed Historical\nBaseline (N=3)',
        'Confirmed Automated\nTest (N=33)',
        'Inferred Demo\n(N=3)',
        'E2E Audit\nVerification (N=4)'
    ]
    prov_vals = [3, 33, 3, 4]
    prov_colors = ['#5cb85c', '#d9534f', '#f0ad4e', '#337ab7']

    # Donut chart
    wedges, texts, autotexts = ax1.pie(
        prov_vals, labels=prov_labels, colors=prov_colors, autopct='%1.1f%%',
        startangle=140, pctdistance=0.75, wedgeprops=dict(width=0.45, edgecolor='white')
    )
    for at in autotexts:
        at.set_fontsize(8.5)
        at.set_fontweight('bold')
    for t in texts:
        t.set_fontsize(8)
    ax1.set_title("Total Database Population\n(N=43 Elephant Reports)", fontsize=10, fontweight='bold')

    # GPS presence within provenance categories
    gps_labels = ['Historical\n(N=3)', 'Auto Test\n(N=33)', 'Demo\n(N=3)', 'Audit\n(N=4)']
    has_gps_vals = [0, 22, 3, 4]
    no_gps_vals = [3, 11, 0, 0]

    b1 = ax2.bar(gps_labels, has_gps_vals, label='Has Numerical GPS', color='#5cb85c', width=0.55)
    b2 = ax2.bar(gps_labels, no_gps_vals, bottom=has_gps_vals, label='Missing GPS (Text Only)', color='#d9534f', width=0.55)

    ax2.set_title("GPS Coordinate Availability\nby Provenance Category", fontsize=10, fontweight='bold')
    ax2.set_ylabel("Count", fontsize=9)
    ax2.grid(axis='y', linestyle=':', alpha=0.6)
    ax2.legend(loc='upper left', fontsize=8)

    for i in range(len(gps_labels)):
        tot = has_gps_vals[i] + no_gps_vals[i]
        ax2.annotate(f"GPS: {has_gps_vals[i]}/{tot}", xy=(i, tot), xytext=(0, 3),
                     textcoords="offset points", ha='center', va='bottom', fontsize=8, fontweight='bold')

    fig.suptitle("Data Provenance & Spatial Viability Stratification (N=43 Total Reports)", fontsize=12, fontweight='bold', y=1.02)
    plt.tight_layout()
    chart5_path = os.path.join(vis_dir, 'provenance_historical_vs_test.png')
    plt.savefig(chart5_path)
    plt.close()

    # =========================================================================
    # CHART 6: Hotspot Evidence Classification Matrix
    # =========================================================================
    fig, ax = plt.subplots(figsize=(9, 4.8), dpi=300)
    ax.axis('off')

    table_data = [
        ["Hotspot ID & Name", "Risk Level", "Administrative Tier", "Empirical Evidence Tier", "Operational Sighting Support"],
        ["HS 1: Minneriya Crossing", "Critical", "Existing Mapped Hotspot", "Insufficient Evidence", "Qualitative Match (Report 2: Bund, No GPS)"],
        ["HS 2: Habarana Forest Edge", "High", "Existing Mapped Hotspot", "Insufficient Evidence", "Qualitative Match (Report 1: MP 127/4, No GPS)"],
        ["HS 3: Gal Oya Corridor", "High", "Existing Mapped Hotspot", "Insufficient Evidence", "Qualitative Match (Report 3: Junction, No GPS)"],
        ["HS 4: Night Movement Zone 01", "Medium", "Existing Mapped Hotspot", "Zero Support", "No historical or test reports in Section 3"]
    ]

    col_widths = [0.26, 0.12, 0.22, 0.20, 0.32]
    table = ax.table(cellText=table_data, loc='center', cellLoc='center', colWidths=col_widths)
    table.auto_set_font_size(False)
    table.set_fontsize(8.5)
    table.scale(1.0, 1.8)

    # Style header row
    for col_idx in range(len(col_widths)):
        cell = table[(0, col_idx)]
        cell.set_facecolor('#2c3e50')
        cell.set_text_props(color='white', weight='bold')

    # Style data rows
    row_colors = ['#fdfefe', '#f4f6f6', '#fdfefe', '#f4f6f6']
    tier_colors = {'Insufficient Evidence': '#fef9e7', 'Zero Support': '#fdedec'}

    for row_idx in range(1, len(table_data)):
        for col_idx in range(len(col_widths)):
            cell = table[(row_idx, col_idx)]
            cell.set_facecolor(row_colors[row_idx - 1])
            if col_idx == 3:
                txt = table_data[row_idx][col_idx]
                cell.set_facecolor(tier_colors.get(txt, '#ffffff'))
                cell.set_text_props(weight='bold')

    ax.set_title("EleGuard Rail - Surveyed Hotspot Evidence & Verification Matrix\n(Scientific Classification: Grounded in Historical Reality, Without Manufactured Scores)",
                 fontsize=11, fontweight='bold', pad=15)

    plt.tight_layout()
    chart6_path = os.path.join(vis_dir, 'hotspot_evidence_classification.png')
    plt.savefig(chart6_path)
    plt.close()

    print("=== EleGuard Rail - Milestone 9B Master Visualizations Generated ===")
    print(f"Chart 1: {chart1_path}")
    print(f"Chart 2: {chart2_path}")
    print(f"Chart 3: {chart3_path}")
    print(f"Chart 4: {chart4_path}")
    print(f"Chart 5: {chart5_path}")
    print(f"Chart 6: {chart6_path}")

if __name__ == '__main__':
    main()
