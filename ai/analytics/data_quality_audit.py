"""
EleGuard Rail - Milestone 9A: Historical Elephant Sighting Data Quality Audit Engine
READ-ONLY statistical, geospatial, and duplication analysis tool.
"""

import json
import os
import math
import datetime
from collections import defaultdict, Counter
import numpy as np
import pandas as pd
import matplotlib.pyplot as plt
import matplotlib.ticker as ticker

# Configure matplotlib for headless rendering
plt.style.use('seaborn-v0_8-whitegrid' if 'seaborn-v0_8-whitegrid' in plt.style.available else 'default')
plt.rcParams['font.sans-serif'] = 'Arial', 'DejaVu Sans', 'Helvetica'
plt.rcParams['axes.edgecolor'] = '#cccccc'
plt.rcParams['axes.linewidth'] = 0.8

BASE_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
REPORTS_FILE = os.path.join(BASE_DIR, 'docs', 'phase9', 'elephant_reports_extracted.json')
SUPPORTING_FILE = os.path.join(BASE_DIR, 'docs', 'phase9', 'supporting_tables.json')
VIS_DIR = os.path.join(BASE_DIR, 'docs', 'phase9', 'visualizations')
OUTPUT_METRICS_FILE = os.path.join(BASE_DIR, 'docs', 'phase9', 'audit_metrics.json')

os.makedirs(VIS_DIR, exist_ok=True)

with open(REPORTS_FILE, 'r', encoding='utf-8') as f:
    raw_reports = json.load(f)

with open(SUPPORTING_FILE, 'r', encoding='utf-8') as f:
    supporting_data = json.load(f)

# Convert to DataFrame for structured querying
df = pd.DataFrame(raw_reports)

total_reports = len(df)
print(f"Loaded {total_reports} elephant report records.")

# ==============================================================================
# 1. ORIGIN & DATASET CATEGORIZATION (Filtering Rules)
# ==============================================================================
# Categorization rules based on provenance and metadata:
# - Baseline Historical Seeds: IDs 1-3 (Seeded during initial system setup)
# - Automated Workflow Test Records: IDs 4-33, 38-40 (Repeated triplets from test_gps_and_workflows.php)
# - Milestone 8 Prototype Demonstrators: IDs 41-43 (Curated field demonstration cases)
# - Milestone 8C E2E Runtime Verification: IDs 53-56 (Live audit submissions)

def categorize_origin(row):
    rid = row['id']
    desc = str(row['location_description'])
    if rid in (1, 2, 3):
        return 'Baseline Historical Seed'
    elif 'E2E-AUDIT-' in desc:
        return 'Milestone 8C E2E Audit'
    elif desc in ('Habarana Corridor Milepost 127', 'Minneriya Track Kilometer 214', 'Gal Oya Forest Edge Dusk'):
        return 'Milestone 8 Demonstration'
    elif desc in ('Near Curve 44 (Text Only)', 'Near Minneriya Crossing Marker 105', 'Gal Oya Manual Coordinates'):
        return 'Automated Workflow Test Harness'
    else:
        return 'Other Development Test'

df['data_origin'] = df.apply(categorize_origin, axis=1)
origin_counts = df['data_origin'].value_counts().to_dict()

# Subsets for comparative reporting
df_historical = df[df['data_origin'] == 'Baseline Historical Seed']
df_test = df[df['data_origin'] != 'Baseline Historical Seed']

# ==============================================================================
# 2. OVERALL REPORT STATUS & BASIC METRICS (PHASE 2)
# ==============================================================================
status_counts = df['report_status'].value_counts().to_dict()
status_pcts = {k: round(v / total_reports * 100, 2) for k, v in status_counts.items()}

# GPS metrics
has_gps_count = int(df['has_gps'].sum())
no_gps_count = total_reports - has_gps_count
has_gps_pct = round(has_gps_count / total_reports * 100, 2)
no_gps_pct = round(no_gps_count / total_reports * 100, 2)

# Photo vs text
has_photo_count = int(df['image_path'].notnull().sum())
text_only_count = total_reports - has_photo_count
has_photo_pct = round(has_photo_count / total_reports * 100, 2)
text_only_pct = round(text_only_count / total_reports * 100, 2)

# AI Processing Breakdown
ai_status_counts = df['ai_status'].value_counts().to_dict()
ai_status_pcts = {k: round(v / total_reports * 100, 2) for k, v in ai_status_counts.items()}

# Missing timestamps
missing_timestamps = int(df['created_at'].isnull().sum())

# ==============================================================================
# 3. GPS DATA QUALITY (PHASE 3)
# ==============================================================================
# General Sri Lanka geographic plausibility envelope (approximate regional bounding box)
SL_LAT_MIN, SL_LAT_MAX = 5.9, 9.9
SL_LON_MIN, SL_LON_MAX = 79.6, 81.9

df_gps = df[df['has_gps']].copy()
df_gps['lat_f'] = pd.to_numeric(df_gps['latitude'], errors='coerce')
df_gps['lon_f'] = pd.to_numeric(df_gps['longitude'], errors='coerce')
df_gps['acc_f'] = pd.to_numeric(df_gps['gps_accuracy_meters'], errors='coerce')

# Observed dataset extent
lat_extent = {'min': float(df_gps['lat_f'].min()), 'max': float(df_gps['lat_f'].max()), 'span': round(float(df_gps['lat_f'].max() - df_gps['lat_f'].min()), 4)}
lon_extent = {'min': float(df_gps['lon_f'].min()), 'max': float(df_gps['lon_f'].max()), 'span': round(float(df_gps['lon_f'].max() - df_gps['lon_f'].min()), 4)}

# Regional Plausibility check (within general mainland coordinates)
df_gps['in_sl_bounds'] = (
    (df_gps['lat_f'] >= SL_LAT_MIN) & (df_gps['lat_f'] <= SL_LAT_MAX) &
    (df_gps['lon_f'] >= SL_LON_MIN) & (df_gps['lon_f'] <= SL_LON_MAX)
)

valid_gps_count = int(df_gps['in_sl_bounds'].sum())
invalid_gps_count = len(df_gps) - valid_gps_count
valid_gps_pct = round(valid_gps_count / len(df_gps) * 100, 2) if len(df_gps) > 0 else 0

# Accuracy stats
acc_series = df_gps['acc_f'].dropna()
acc_count = len(acc_series)
acc_pct_of_gps = round(acc_count / len(df_gps) * 100, 2) if len(df_gps) > 0 else 0
acc_mean = round(float(acc_series.mean()), 2) if acc_count > 0 else None
acc_median = round(float(acc_series.median()), 2) if acc_count > 0 else None
acc_std = round(float(acc_series.std()), 2) if acc_count > 0 else None
acc_min = round(float(acc_series.min()), 2) if acc_count > 0 else None
acc_max = round(float(acc_series.max()), 2) if acc_count > 0 else None

# Repeated coordinate pairs
coord_pairs = list(zip(df_gps['latitude'], df_gps['longitude']))
coord_counter = Counter(coord_pairs)
repeated_coords = {f"({lat}, {lon})": count for (lat, lon), count in coord_counter.items() if count > 1}

# ==============================================================================
# 4. RAILWAY PROXIMITY DATA QUALITY (PHASE 4)
# ==============================================================================
# Extract proximity metrics from proximity object
proximities = []
for idx, r in df.iterrows():
    p = r['proximity']
    if p and p.get('is_valid') and p.get('distance_meters') is not None:
        proximities.append({
            'id': r['id'],
            'assigned_section_id': r['assigned_section_id'],
            'assigned_section_name': r['assigned_section_name'],
            'nearest_section_id': p['nearest_section_id'],
            'nearest_section_name': p['nearest_section_name'],
            'distance_meters': float(p['distance_meters']),
            'distance_km': round(float(p['distance_meters']) / 1000.0, 3),
            'gps_accuracy': float(p['gps_accuracy_meters']) if p.get('gps_accuracy_meters') is not None else None,
            'uncertainty_range': p.get('effective_uncertainty_range'),
            'section_mismatch': (r['assigned_section_id'] != p['nearest_section_id'])
        })

df_prox = pd.DataFrame(proximities)
calculable_prox_count = len(df_prox)
non_calculable_prox_count = total_reports - calculable_prox_count
calculable_prox_pct = round(calculable_prox_count / total_reports * 100, 2)

# Distance distribution brackets
dist_series = df_prox['distance_meters']
prox_brackets = {
    'immediate_danger_under_50m': int((dist_series < 50).sum()),
    'corridor_buffer_50_to_100m': int(((dist_series >= 50) & (dist_series < 100)).sum()),
    'near_track_100_to_500m': int(((dist_series >= 100) & (dist_series < 500)).sum()),
    'moderate_500m_to_1km': int(((dist_series >= 500) & (dist_series < 1000)).sum()),
    'distant_1km_to_5km': int(((dist_series >= 1000) & (dist_series <= 5000)).sum()),
    'out_of_corridor_over_5km': int((dist_series > 5000).sum())
}

prox_stats = {
    'min_meters': round(float(dist_series.min()), 1) if calculable_prox_count > 0 else None,
    'max_meters': round(float(dist_series.max()), 1) if calculable_prox_count > 0 else None,
    'mean_meters': round(float(dist_series.mean()), 1) if calculable_prox_count > 0 else None,
    'median_meters': round(float(dist_series.median()), 1) if calculable_prox_count > 0 else None,
    'std_meters': round(float(dist_series.std()), 1) if calculable_prox_count > 0 else None
}

# Section assignment fidelity (Database Assigned vs Geo-Calculated)
section_mismatches = int(df_prox['section_mismatch'].sum())
section_mismatch_pct = round(section_mismatches / calculable_prox_count * 100, 2) if calculable_prox_count > 0 else 0

# ==============================================================================
# 5. AI DETECTION QUALITY & HUMAN CONVERGENCE (PHASE 5)
# ==============================================================================
df_ai = df[df['ai_status'] == 'processed'].copy()
df_ai['conf_f'] = pd.to_numeric(df_ai['ai_confidence'], errors='coerce')
df_ai['count_i'] = pd.to_numeric(df_ai['ai_estimated_count'], errors='coerce')

ai_outcomes = {
    'processed_total': len(df_ai),
    'skipped_total': int((df['ai_status'] == 'skipped').sum()),
    'failed_total': int((df['ai_status'] == 'failed').sum()),
    'positive_detected': int((df_ai['ai_detected_elephant'] == 1).sum()),
    'negative_not_detected': int(((df_ai['ai_detected_elephant'] == 0) & (df_ai['conf_f'] < 0.20)).sum()),
    'uncertain_review_required': int(((df_ai['ai_detected_elephant'] == 0) & (df_ai['conf_f'] >= 0.20) & (df_ai['conf_f'] < 0.40)).sum())
}

# Confidence score distribution
conf_series = df_ai['conf_f'].dropna()
ai_conf_stats = {
    'min': round(float(conf_series.min()), 4) if len(conf_series) > 0 else None,
    'max': round(float(conf_series.max()), 4) if len(conf_series) > 0 else None,
    'mean': round(float(conf_series.mean()), 4) if len(conf_series) > 0 else None,
    'median': round(float(conf_series.median()), 4) if len(conf_series) > 0 else None,
    'q25': round(float(conf_series.quantile(0.25)), 4) if len(conf_series) > 0 else None,
    'q75': round(float(conf_series.quantile(0.75)), 4) if len(conf_series) > 0 else None
}

# Cross-tabulation: AI Outcome vs Wildlife Officer Final Status
crosstab_ai_human = pd.crosstab(df['ai_status'], df['report_status']).to_dict()

# Specific Investigation Categories:
# 1. AI Positive Elephant but Officer Rejected
ai_pos_officer_rej = df[(df['ai_detected_elephant'] == 1) & (df['report_status'] == 'Rejected')]['id'].tolist()
# 2. AI Negative No Elephant but Officer Verified
ai_neg_officer_ver = df[(df['ai_detected_elephant'] == 0) & (df['conf_f'] < 0.20) if 'conf_f' in df else False & (df['report_status'] == 'Verified')]['id'].tolist()
# 3. AI Uncertain
ai_uncertain_ids = df[(df['ai_confidence'].astype(float) >= 0.20) & (df['ai_confidence'].astype(float) < 0.40)]['id'].tolist()

# ==============================================================================
# 6. TEMPORAL DATA QUALITY (PHASE 6)
# ==============================================================================
df['created_dt'] = pd.to_datetime(df['created_at'])
earliest_date = df['created_dt'].min().strftime('%Y-%m-%d %H:%M:%S')
latest_date = df['created_dt'].max().strftime('%Y-%m-%d %H:%M:%S')
time_span_days = round((df['created_dt'].max() - df['created_dt'].min()).total_seconds() / 86400.0, 2)

df['month_year'] = df['created_dt'].dt.strftime('%Y-%m')
df['hour_of_day'] = df['created_dt'].dt.hour
df['day_of_week'] = df['created_dt'].dt.day_name()

monthly_counts = df['month_year'].value_counts().sort_index().to_dict()
hourly_counts = df['hour_of_day'].value_counts().sort_index().to_dict()
dow_counts = df['day_of_week'].value_counts().to_dict()

# ==============================================================================
# 7. DUPLICATION INVESTIGATION (PHASE 7)
# ==============================================================================
# Group by identical location_description and coordinates
dup_groups = defaultdict(list)
for idx, r in df.iterrows():
    key = (
        str(r['location_description']).strip(),
        str(r['latitude']),
        str(r['longitude']),
        str(r['assigned_section_id'])
    )
    dup_groups[key].append(r['id'])

duplicate_clusters = []
cluster_id = 1
for (desc, lat, lon, sec), ids in dup_groups.items():
    if len(ids) > 1:
        duplicate_clusters.append({
            'cluster_id': cluster_id,
            'report_count': len(ids),
            'report_ids': ids,
            'description': desc,
            'coordinates': f"({lat}, {lon})" if lat != 'None' else 'NO GPS',
            'section_id': sec,
            'suspected_cause': 'Automated workflow regression test loop' if desc in (
                'Near Curve 44 (Text Only)', 'Near Minneriya Crossing Marker 105', 'Gal Oya Manual Coordinates'
            ) else 'Repeated human submission / test rerun',
            'confidence': 'High' if len(ids) >= 3 else 'Medium'
        })
        cluster_id += 1

total_duplicate_records = sum(c['report_count'] for c in duplicate_clusters)

# ==============================================================================
# 8. RAILWAY SECTION DISTRIBUTION (PHASE 8)
# ==============================================================================
section_stats = []
for sec in supporting_data['railway_sections']:
    sid = sec['id']
    sec_df = df[df['assigned_section_id'] == sid]
    section_stats.append({
        'section_id': sid,
        'section_name': sec['section_name'],
        'district': sec['district'],
        'length_km': sec.get('length_km'),
        'total_reports': len(sec_df),
        'pct_of_total': round(len(sec_df) / total_reports * 100, 2),
        'verified_reports': int((sec_df['report_status'] == 'Verified').sum()),
        'gps_enabled_reports': int(sec_df['has_gps'].sum()),
        'ai_assisted_reports': int((sec_df['ai_status'] == 'processed').sum()),
        'active_alerts': int(len([a for a in supporting_data['alerts'] if a['section_id'] == sid]))
    })

# Reports without section association
unassigned_sections = int(df['assigned_section_id'].isnull().sum())

# ==============================================================================
# 9. COMPILE METRICS JSON
# ==============================================================================
metrics_payload = {
    'extracted_at': datetime.datetime.now().isoformat(),
    'inventory_summary': {
        'total_tables': len(supporting_data['railway_sections']),
        'total_reports': total_reports,
        'total_alerts': len(supporting_data['alerts']),
        'total_driver_acks': len(supporting_data['driver_acknowledgements']),
        'total_hotspots': len(supporting_data['hotspots']),
        'total_incidents': len(supporting_data['incident_history'])
    },
    'data_provenance': {
        'total_records': total_reports,
        'origin_breakdown': origin_counts,
        'filtering_rules': {
            'baseline_historical_seed': 'IDs 1-3: Domain seed records from initial project setup',
            'automated_workflow_test': 'IDs 4-33, 38-40: 11 identical test triplets from test_gps_and_workflows.php',
            'milestone_8_demo': 'IDs 41-43: Curated prototype demonstration cases for AI and GIS',
            'milestone_8c_audit': 'IDs 53-56: Live end-to-end audit cases submitted via HTTP'
        }
    },
    'report_status': {
        'counts': status_counts,
        'percentages': status_pcts
    },
    'gps_data_quality': {
        'has_gps_count': has_gps_count,
        'has_gps_pct': has_gps_pct,
        'no_gps_count': no_gps_count,
        'no_gps_pct': no_gps_pct,
        'valid_gps_count': valid_gps_count,
        'valid_gps_pct': valid_gps_pct,
        'observed_coordinate_extent': {
            'latitude': lat_extent,
            'longitude': lon_extent
        },
        'geographical_validation_scope': 'North-Central railway corridor study area (Habarana-Gal Oya / Minneriya) within general Sri Lankan mainland envelope',
        'accuracy_recorded_count': acc_count,
        'accuracy_recorded_pct': acc_pct_of_gps,
        'accuracy_statistics': {
            'min_m': acc_min,
            'max_m': acc_max,
            'mean_m': acc_mean,
            'median_m': acc_median,
            'std_m': acc_std
        },
        'repeated_coordinate_clusters': repeated_coords
    },
    'railway_proximity_quality': {
        'calculable_count': calculable_prox_count,
        'calculable_pct': calculable_prox_pct,
        'non_calculable_count': non_calculable_prox_count,
        'distance_brackets': prox_brackets,
        'distance_statistics': prox_stats,
        'section_mismatches_count': section_mismatches,
        'section_mismatch_pct': section_mismatch_pct
    },
    'ai_detection_quality': {
        'outcomes': ai_outcomes,
        'confidence_statistics': ai_conf_stats,
        'ai_human_crosstab': crosstab_ai_human,
        'human_decision_sample_size': {
            'total_ai_processed': len(df_ai),
            'reports_with_human_decision': int((df_ai['report_status'].isin(['Verified', 'Rejected'])).sum()),
            'reports_pending_human_decision': int((df_ai['report_status'] == 'Pending').sum()),
            'scientific_concordance_valid': False,
            'note': 'Sample of N=2 decisions (#53 verified, #54 rejected) is statistically insufficient for model concordance validation.'
        },
        'investigation_cases': {
            'ai_positive_officer_rejected': ai_pos_officer_rej,
            'ai_negative_officer_verified': ai_neg_officer_ver,
            'ai_uncertain_ids': ai_uncertain_ids
        }
    },
    'temporal_quality': {
        'earliest_submission': earliest_date,
        'latest_submission': latest_date,
        'time_span_days': time_span_days,
        'monthly_distribution': monthly_counts,
        'hourly_distribution': hourly_counts,
        'day_of_week_distribution': dow_counts,
        'missing_timestamps': missing_timestamps,
        'sighting_time_limitation': 'elephant_reports table stores only submission created_at and updated_at; no distinct sighting_time column exists in the database schema.'
    },
    'duplication_investigation': {
        'total_duplicate_clusters': len(duplicate_clusters),
        'total_duplicate_records': total_duplicate_records,
        'clusters': duplicate_clusters
    },
    'railway_section_analysis': {
        'sections': section_stats,
        'unassigned_sections': unassigned_sections
    }
}

with open(OUTPUT_METRICS_FILE, 'w', encoding='utf-8') as f:
    json.dump(metrics_payload, f, indent=2)

print(f"Audit metrics successfully exported to {OUTPUT_METRICS_FILE}")

# ==============================================================================
# 10. GENERATE VISUAL ANALYTICS CHARTS (PHASE 10)
# ==============================================================================
print("Generating analytical visualization charts...")

# --- Chart 1: Report Status & Provenance Distribution ---
fig, (ax1, ax2) = plt.subplots(1, 2, figsize=(14, 6))

# Status Pie / Donut
colors_status = ['#f59e0b', '#10b981', '#ef4444', '#3b82f6', '#6b7280']
status_labels = list(status_counts.keys())
status_vals = list(status_counts.values())
ax1.pie(status_vals, labels=status_labels, autopct='%1.1f%%', startangle=140,
        colors=colors_status[:len(status_labels)], wedgeprops={'edgecolor': 'white', 'linewidth': 2})
ax1.set_title('Report Verification Status Distribution (N=43)', fontsize=13, fontweight='bold', pad=15)

# Provenance Bar
orig_labels = [k.replace(' ', '\n') for k in origin_counts.keys()]
orig_vals = list(origin_counts.values())
bars = ax2.bar(orig_labels, orig_vals, color='#4f46e5', edgecolor='#312e81', width=0.55)
for bar in bars:
    yval = bar.get_height()
    ax2.text(bar.get_x() + bar.get_width()/2.0, yval + 0.5, f"{int(yval)} ({yval/total_reports*100:.1f}%)",
             ha='center', va='bottom', fontsize=9, fontweight='bold')
ax2.set_title('Data Provenance: Historical vs Test Artifacts', fontsize=13, fontweight='bold', pad=15)
ax2.set_ylabel('Number of Reports')
ax2.set_ylim(0, max(orig_vals) + 6)
plt.tight_layout()
p1 = os.path.join(VIS_DIR, 'report_status_distribution.png')
plt.savefig(p1, dpi=300)
plt.close()
print(f"  Saved: {p1}")

# --- Chart 2: GPS Coverage & Accuracy Distribution ---
fig, (ax1, ax2) = plt.subplots(1, 2, figsize=(14, 5.5))

# GPS Availability
gps_labels = [f"GPS Enabled\n(N={has_gps_count})", f"No Coordinates\n(N={no_gps_count})"]
ax1.bar(gps_labels, [has_gps_count, no_gps_count], color=['#10b981', '#9ca3af'], width=0.45)
for i, v in enumerate([has_gps_count, no_gps_count]):
    ax1.text(i, v + 0.8, f"{v} ({v/total_reports*100:.1f}%)", ha='center', fontweight='bold')
ax1.set_title('GPS Coordinate Coverage (N=43)', fontsize=12, fontweight='bold', pad=15)
ax1.set_ylabel('Report Count')
ax1.set_ylim(0, 36)

# Accuracy Distribution
acc_data = acc_series.values
ax2.hist(acc_data, bins=range(8, 30, 2), color='#06b6d4', edgecolor='#0891b2', rwidth=0.85)
ax2.axvline(acc_median, color='#dc2626', linestyle='--', linewidth=2, label=f"Median: {acc_median:.1f}m")
ax2.axvline(acc_mean, color='#4338ca', linestyle=':', linewidth=2, label=f"Mean: {acc_mean:.1f}m")
ax2.set_title(f'Recorded GPS Accuracy Distribution (N={len(acc_data)})', fontsize=12, fontweight='bold', pad=15)
ax2.set_xlabel('Accuracy Radius (Metres - Lower is more precise)')
ax2.set_ylabel('Frequency')
ax2.legend(frameon=True)
plt.tight_layout()
p2 = os.path.join(VIS_DIR, 'gps_coverage_and_accuracy.png')
plt.savefig(p2, dpi=300)
plt.close()
print(f"  Saved: {p2}")

# --- Chart 3: AI Processing Outcomes & Confidence Distribution ---
fig, (ax1, ax2) = plt.subplots(1, 2, figsize=(14, 5.5))

# AI Status Overview
ai_labels = ['Processed\n(with photo)', 'Skipped\n(text-only)', 'Failed\n(error)']
ai_vals = [ai_outcomes['processed_total'], ai_outcomes['skipped_total'], ai_outcomes['failed_total']]
ax1.bar(ai_labels, ai_vals, color=['#10b981', '#6b7280', '#ef4444'], width=0.45)
for i, v in enumerate(ai_vals):
    ax1.text(i, v + 0.8, f"{v} ({v/total_reports*100:.1f}%)", ha='center', fontweight='bold')
ax1.set_title('AI Processing Subsystem Outcomes (N=43)', fontsize=12, fontweight='bold', pad=15)
ax1.set_ylabel('Report Count')
ax1.set_ylim(0, 42)

# Sub-classification of Processed
ai_class_labels = ['Positive Detected\n(>=0.40)', 'Uncertain\n(0.20-0.40)', 'No Elephant\n(<0.20)']
ai_class_vals = [ai_outcomes['positive_detected'], ai_outcomes['uncertain_review_required'], ai_outcomes['negative_not_detected']]
ax2.bar(ai_class_labels, ai_class_vals, color=['#059669', '#f59e0b', '#9ca3af'], width=0.45)
for i, v in enumerate(ai_class_vals):
    pct = v / ai_outcomes['processed_total'] * 100 if ai_outcomes['processed_total'] > 0 else 0
    ax2.text(i, v + 0.1, f"{v} ({pct:.1f}%)", ha='center', fontweight='bold')
ax2.set_title(f'AI 3-Tier Classification Breakdown (N={ai_outcomes["processed_total"]})', fontsize=12, fontweight='bold', pad=15)
ax2.set_ylabel('Count')
ax2.set_ylim(0, max(ai_class_vals) + 1.5)
plt.tight_layout()
p3 = os.path.join(VIS_DIR, 'ai_processing_outcomes.png')
plt.savefig(p3, dpi=300)
plt.close()
print(f"  Saved: {p3}")

# --- Chart 4: Railway Section Distribution ---
fig, ax = plt.subplots(figsize=(12, 6))
sec_names = [s['section_name'].replace(' - ', '\n-> ') for s in section_stats]
sec_tot = [s['total_reports'] for s in section_stats]
sec_gps = [s['gps_enabled_reports'] for s in section_stats]
sec_ver = [s['verified_reports'] for s in section_stats]

x = np.arange(len(sec_names))
w = 0.25
ax.bar(x - w, sec_tot, width=w, label='Total Reports', color='#3b82f6')
ax.bar(x, sec_gps, width=w, label='GPS-Enabled', color='#10b981')
ax.bar(x + w, sec_ver, width=w, label='Verified by Officer', color='#8b5cf6')

ax.set_xticks(x)
ax.set_xticklabels(sec_names, fontsize=10)
ax.set_title('Report Distribution Across 4 Monitored Railway Sections', fontsize=13, fontweight='bold', pad=15)
ax.set_ylabel('Report Count')
ax.legend(frameon=True)
for i in range(len(sec_names)):
    ax.text(x[i] - w, sec_tot[i] + 0.3, str(sec_tot[i]), ha='center', fontsize=9, fontweight='bold')
    ax.text(x[i], sec_gps[i] + 0.3, str(sec_gps[i]), ha='center', fontsize=9)
    ax.text(x[i] + w, sec_ver[i] + 0.3, str(sec_ver[i]), ha='center', fontsize=9)
ax.set_ylim(0, max(sec_tot) + 4)
plt.tight_layout()
p4 = os.path.join(VIS_DIR, 'railway_section_distribution.png')
plt.savefig(p4, dpi=300)
plt.close()
print(f"  Saved: {p4}")

# --- Chart 5: Railway Track Proximity Distance Brackets ---
fig, ax = plt.subplots(figsize=(11, 5.5))
bracket_labels = [
    'Danger Zone\n(< 50m)',
    'Immediate\n(50 - 100m)',
    'Close Buffer\n(100 - 500m)',
    'Moderate\n(500m - 1km)',
    'Distant\n(1km - 5km)',
    'Remote\n(> 5km)'
]
bracket_vals = list(prox_brackets.values())
bars = ax.bar(bracket_labels, bracket_vals, color='#f97316', edgecolor='#ea580c', width=0.55)
for bar in bars:
    yval = bar.get_height()
    pct = yval / calculable_prox_count * 100 if calculable_prox_count > 0 else 0
    ax.text(bar.get_x() + bar.get_width()/2.0, yval + 0.4, f"{int(yval)}\n({pct:.1f}%)",
            ha='center', va='bottom', fontsize=9, fontweight='bold')
ax.set_title(f'Geodesic Distance of Sightings to Nearest Railway Track (N={calculable_prox_count})',
             fontsize=13, fontweight='bold', pad=15)
ax.set_ylabel('Report Count')
ax.set_ylim(0, max(bracket_vals) + 5)
plt.tight_layout()
p5 = os.path.join(VIS_DIR, 'railway_proximity_distance_distribution.png')
plt.savefig(p5, dpi=300)
plt.close()
print(f"  Saved: {p5}")

# --- Chart 6: Temporal Submissions Timeline ---
fig, (ax1, ax2) = plt.subplots(1, 2, figsize=(14, 5.5))

# Hourly Distribution
hours = list(range(24))
h_vals = [hourly_counts.get(h, 0) for h in hours]
ax1.bar(hours, h_vals, color='#6366f1', edgecolor='#4338ca', width=0.7)
ax1.set_title('Report Submissions by Hour of Day (Local Time)', fontsize=12, fontweight='bold', pad=15)
ax1.set_xlabel('Hour of Day (00:00 - 23:00)')
ax1.set_ylabel('Report Count')
ax1.set_xticks(range(0, 24, 2))
ax1.set_ylim(0, max(h_vals) + 2)

# Monthly Distribution
m_labels = list(monthly_counts.keys())
m_vals = list(monthly_counts.values())
ax2.bar(m_labels, m_vals, color='#ec4899', edgecolor='#db2777', width=0.45)
for i, v in enumerate(m_vals):
    ax2.text(i, v + 0.8, f"{v} (100%)", ha='center', fontweight='bold')
ax2.set_title('Monthly Sighting Record Volume', fontsize=12, fontweight='bold', pad=15)
ax2.set_xlabel('Month (YYYY-MM)')
ax2.set_ylabel('Report Count')
ax2.set_ylim(0, 50)
plt.tight_layout()
p6 = os.path.join(VIS_DIR, 'temporal_and_hourly_distribution.png')
plt.savefig(p6, dpi=300)
plt.close()
print(f"  Saved: {p6}")

print("\n--- Summary of Audit Engine Execution ---")
print(f"Total reports processed:        {total_reports}")
print(f"Baseline historical seeds:      {len(df_historical)}")
print(f"Test & harness artifacts:       {len(df_test)}")
print(f"GPS-enabled reports:            {has_gps_count} ({has_gps_pct}%)")
print(f"Valid coordinates in SL:        {valid_gps_count} ({valid_gps_pct}%)")
print(f"Calculable track proximities:   {calculable_prox_count} ({calculable_prox_pct}%)")
print(f"AI processed reports:           {ai_outcomes['processed_total']}")
print(f"Duplicate record clusters:      {len(duplicate_clusters)} (encompassing {total_duplicate_records} records)")
print("Visualizations created in:", VIS_DIR)
