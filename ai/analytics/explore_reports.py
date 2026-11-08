import json

reports = json.load(open(r'C:\Users\user\Desktop\Railway\eleguard-rail\docs\phase9\elephant_reports_extracted.json'))
print(f"Total reports: {len(reports)}\n")

for r in reports:
    loc = r['location_description']
    created = r['created_at']
    gps = f"{r['latitude']},{r['longitude']}" if r['has_gps'] else "NO GPS"
    acc = f"{r['gps_accuracy_meters']}m" if r['gps_accuracy_meters'] else "N/A"
    print(f"ID {r['id']:2d} | Sec {r['assigned_section_id']} | Status: {r['report_status']:18s} | AI: {r['ai_status']:9s} | {created} | GPS: {gps:23s} (±{acc:5s}) | {loc[:35]}")
