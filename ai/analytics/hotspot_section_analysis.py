#!/usr/bin/env python3
"""
EleGuard Rail - Milestone 9B: Railway Section & Mismatch Analysis Engine
Strict Read-Only Script.

Investigates the 26/29 section mismatch phenomenon between administrative
railway section assignments and nearest geometric track LineStrings.
"""

import json
import math
import os
from collections import Counter

EARTH_RADIUS_METERS = 6371000.0

def haversine_distance(lat1, lon1, lat2, lon2):
    phi1 = math.radians(float(lat1))
    phi2 = math.radians(float(lat2))
    delta_phi = math.radians(float(lat2) - float(lat1))
    delta_lambda = math.radians(float(lon2) - float(lon1))
    sin_half_phi = math.sin(delta_phi / 2.0)
    sin_half_lambda = math.sin(delta_lambda / 2.0)
    a = (sin_half_phi * sin_half_phi) + \
        math.cos(phi1) * math.cos(phi2) * (sin_half_lambda * sin_half_lambda)
    a_clamped = max(0.0, min(1.0, a))
    return EARTH_RADIUS_METERS * 2.0 * math.atan2(math.sqrt(a_clamped), math.sqrt(max(0.0, 1.0 - a_clamped)))

def point_to_segment_distance(lat_p, lon_p, lat_a, lon_a, lat_b, lon_b):
    phi_m = math.radians((lat_a + lat_p) / 2.0)
    cos_phi_m = math.cos(phi_m)

    dx_b = EARTH_RADIUS_METERS * math.radians(lon_b - lon_a) * cos_phi_m
    dy_b = EARTH_RADIUS_METERS * math.radians(lat_b - lat_a)

    dx_p = EARTH_RADIUS_METERS * math.radians(lon_p - lon_a) * cos_phi_m
    dy_p = EARTH_RADIUS_METERS * math.radians(lat_p - lat_a)

    seg_len_sq = (dx_b * dx_b) + (dy_b * dy_b)
    if seg_len_sq <= 1e-12:
        return haversine_distance(lat_p, lon_p, lat_a, lon_a)

    t = ((dx_p * dx_b) + (dy_p * dy_b)) / seg_len_sq
    if t <= 0.0:
        return haversine_distance(lat_p, lon_p, lat_a, lon_a)
    elif t >= 1.0:
        return haversine_distance(lat_p, lon_p, lat_b, lon_b)
    else:
        c_lat = lat_a + t * (lat_b - lat_a)
        c_lon = lon_a + t * (lon_b - lon_a)
        return haversine_distance(lat_p, lon_p, c_lat, c_lon)

def point_to_linestring_distance(lat_p, lon_p, coordinates):
    if len(coordinates) < 2:
        if len(coordinates) == 1:
            return haversine_distance(lat_p, lon_p, coordinates[0][1], coordinates[0][0])
        return float('inf')

    min_d = float('inf')
    for i in range(len(coordinates) - 1):
        lon_a, lat_a = coordinates[i][0], coordinates[i][1]
        lon_b, lat_b = coordinates[i+1][0], coordinates[i+1][1]
        d = point_to_segment_distance(lat_p, lon_p, lat_a, lon_a, lat_b, lon_b)
        if d < min_d:
            min_d = d
            if min_d < 0.1:
                break
    return min_d

def main():
    base_dir = os.path.dirname(os.path.abspath(__file__))
    docs_dir = os.path.join(base_dir, '..', '..', 'docs', 'phase9')
    geojson_file = os.path.join(base_dir, '..', '..', 'public', 'assets', 'data', 'railway_routes.geojson')
    reports_file = os.path.join(docs_dir, 'elephant_reports_extracted.json')
    hotspots_file = os.path.join(docs_dir, 'hotspot_inventory.json')

    with open(geojson_file, 'r', encoding='utf-8') as f:
        geojson_data = json.load(f)

    with open(reports_file, 'r', encoding='utf-8') as f:
        reports = json.load(f)

    with open(hotspots_file, 'r', encoding='utf-8') as f:
        hotspot_data = json.load(f)

    # Railway section spatial profiles from GeoJSON
    section_profiles = {}
    for feat in geojson_data['features']:
        sid = feat['properties']['section_id']
        coords = feat['geometry']['coordinates']
        lats = [c[1] for c in coords]
        lons = [c[0] for c in coords]
        section_profiles[sid] = {
            'section_id': sid,
            'vertex_count': len(coords),
            'lat_min': min(lats),
            'lat_max': max(lats),
            'lon_min': min(lons),
            'lon_max': max(lons),
            'coordinates': coords
        }

    # Hotspot section associations
    hotspot_associations = []
    for h in hotspot_data['hotspots']:
        hotspot_associations.append({
            'hotspot_id': h['id'],
            'name': h['name'],
            'assigned_section_id': h['assigned_section']['section_id'],
            'geometric_nearest_section_id': h['geometric_nearest_section']['section_id'],
            'is_consistent': h['is_section_consistent'],
            'distance_to_nearest_meters': h['geometric_nearest_section']['distance_to_track_meters'],
            'distance_to_assigned_meters': h['assigned_section']['distance_to_track_meters'],
            'explanation': (
                "Administratively assigned to Minneriya - Hingurakgoda (Section 2) due to park corridor, "
                "but spatial location (8.0412, 80.8256) is 5.15 km from Section 1 track and 7.48 km from Section 2 track."
                if h['id'] == 1 else
                "Geographically and administratively consistent with Section 1 (Habarana - Gal Oya)."
                if h['id'] == 2 else
                "Administratively assigned to Section 1 (Habarana - Gal Oya terminus), but spatial coordinates "
                "(8.1256, 80.9124) near junction are 3.74 km from Section 2 track vs 5.13 km from Section 1 track."
                if h['id'] == 3 else
                "Geographically and administratively consistent with Section 3 (Maho - Anuradhapura)."
            )
        })

    # Detailed report analysis
    gps_reports = [r for r in reports if r.get('has_gps') and r.get('latitude') is not None]
    
    mismatch_table = []
    matches_table = []

    for r in gps_reports:
        rid = r['id']
        lat = float(r['latitude'])
        lon = float(r['longitude'])
        assigned_sec = r['assigned_section_id']
        
        # Calculate distance to all sections
        distances = {}
        for sid, prof in section_profiles.items():
            d = point_to_linestring_distance(lat, lon, prof['coordinates'])
            distances[sid] = d

        nearest_sec = min(distances, key=distances.get)
        min_dist = distances[nearest_sec]
        dist_to_assigned = distances.get(assigned_sec, float('inf'))

        is_mismatch = (assigned_sec != nearest_sec)

        # Explain mismatch
        if is_mismatch:
            if rid in [5, 8, 11, 14, 17, 20, 23, 26, 29, 32, 39]: # Coords near Hotspot 1
                explanation = (
                    "Automated test harness artifact instantiated near Hotspot 1 (Minneriya Elephant Crossing). "
                    "Inherited Hotspot 1 administrative section assignment (Section 2), but nearest track centerline "
                    "in GeoJSON is Section 1 (5.16 km vs 7.48 km to Section 2)."
                )
                conf = "High"
            elif rid in [6, 9, 12, 15, 18, 21, 24, 27, 30, 33, 40]: # Coords at Hotspot 3
                explanation = (
                    "Automated test harness artifact instantiated at Hotspot 3 (Gal Oya Corridor). "
                    "Inherited Hotspot 3 administrative section assignment (Section 1), but track geometry "
                    "of Section 2 passes closer to the junction (3.74 km vs 5.13 km to Section 1)."
                )
                conf = "High"
            elif rid == 42: # Inferred Demo near Hotspot 1
                explanation = (
                    "Inferred demonstration report near Hotspot 1 (Minneriya). Administratively assigned to Section 2, "
                    "geometrically closer to Section 1 track (5.18 km vs 7.44 km)."
                )
                conf = "High"
            elif rid == 43: # Inferred Demo intermediate
                explanation = (
                    "Inferred demonstration report at intermediate location (8.0820, 80.8910). Distance difference "
                    "between Section 1 (4.26 km) and Section 2 (4.46 km) is only 197 metres."
                )
                conf = "High"
            elif rid in [54, 56]: # E2E Audit runs
                explanation = (
                    "Milestone 8C end-to-end verification audit run where reporter submitted Section 2 while using "
                    "test coordinates near Habarana Forest (Section 1 territory, 2.24 km from Section 1 track)."
                )
                conf = "High"
            else:
                explanation = "Administrative selection differs from nearest Euclidean track line."
                conf = "Medium"

            mismatch_table.append({
                'report_id': rid,
                'provenance': (
                    'Confirmed Automated Test' if ((4 <= rid <= 33) or (38 <= rid <= 40)) else
                    'Inferred Demonstration' if (41 <= rid <= 43) else
                    'E2E Audit Verification' if (53 <= rid <= 56) else 'Other'
                ),
                'assigned_section': assigned_sec,
                'nearest_geometric_section': nearest_sec,
                'distance_to_nearest_meters': round(min_dist, 2),
                'distance_to_assigned_meters': round(dist_to_assigned, 2),
                'discrepancy_meters': round(dist_to_assigned - min_dist, 2),
                'latitude': lat,
                'longitude': lon,
                'possible_explanation': explanation,
                'confidence_in_interpretation': conf
            })
        else:
            matches_table.append({
                'report_id': rid,
                'provenance': (
                    'Inferred Demonstration' if (41 <= rid <= 43) else
                    'E2E Audit Verification' if (53 <= rid <= 56) else 'Other'
                ),
                'assigned_section': assigned_sec,
                'distance_to_track_meters': round(min_dist, 2),
                'latitude': lat,
                'longitude': lon
            })

    output_data = {
        'metadata': {
            'total_gps_reports': len(gps_reports),
            'mismatch_count': len(mismatch_table),
            'match_count': len(matches_table),
            'mismatch_percentage': round((len(mismatch_table) / len(gps_reports)) * 100.0, 2)
        },
        'railway_sections_spatial_profiles': [
            {
                'section_id': sid,
                'vertex_count': prof['vertex_count'],
                'bounds': {
                    'lat_min': round(prof['lat_min'], 5),
                    'lat_max': round(prof['lat_max'], 5),
                    'lon_min': round(prof['lon_min'], 5),
                    'lon_max': round(prof['lon_max'], 5)
                }
            } for sid, prof in section_profiles.items()
        ],
        'hotspot_section_associations': hotspot_associations,
        'mismatches': mismatch_table,
        'matches': matches_table
    }

    output_file = os.path.join(docs_dir, 'hotspot_section_analysis.json')
    with open(output_file, 'w', encoding='utf-8') as f:
        json.dump(output_data, f, indent=2, ensure_ascii=False)

    print("=== EleGuard Rail - Milestone 9B Railway Section Analysis ===")
    print(f"Total GPS reports analyzed: {len(gps_reports)}")
    print(f"Section mismatches: {len(mismatch_table)} ({(len(mismatch_table)/len(gps_reports))*100.0:.1f}%)")
    print(f"Section matches: {len(matches_table)} ({(len(matches_table)/len(gps_reports))*100.0:.1f}%)")
    print(f"Saved analysis to: {output_file}")

if __name__ == '__main__':
    main()
