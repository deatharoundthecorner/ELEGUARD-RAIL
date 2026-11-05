# System Administrator Guide

This guide describes configuration options available through the central Administrative Console.

## 1. Dashboard Overview
- Renders comprehensive counts: total users, active warnings, pending reports, confirmed sightings, and driver responses.
- Displays the 5 most recent sightings, broadcast warnings, historical incidents, and system audit logs.

## 2. User Management
- **Add User**: Go to **Manage Users**, tap **Add User**.
  - Enter name, email, password (min. 6 characters), select role (Admin, Control Officer, Train Driver, Wildlife Ranger, Reporter), and status.
- **Edit User**: Edit name, email, role, status, and reset password if requested.
- **Deactivate User**: Set status to **Inactive** to block system access.
- **Search & Filter**: Search by name/email or filter by roles.

## 3. Railway Sections
- Define track segments (e.g. Habarana - Gal Oya) including start/end stations, regional districts, and baseline risk levels (Low, Medium, High, Critical) which affect risk scoring.
- **Delete Section**: Deleting a section will cascades-delete associated hotspots and alerts to prevent database constraint issues.

## 4. Hotspots
- Mark coordinates of high-frequency elephant crossings (e.g., Minneriya Crossing).
- Add latitude and longitude decimals (optional) and select risk level.
- Link each hotspot to a defined railway section.

## 5. Reports & Alerts Management
- Administrators have full permissions to review public/ranger sighting reports.
- **Override Status**: Update any report status (e.g. override Rejected reports back to Pending).
- **Manage Alerts**: Monitor active broadcasts, resolve warnings, or delete alert records.

## 6. System Audit Logs
- Open **System Activity Logs** to monitor logins, sighting submissions, ranger verifications, alert broadcasts, and driver response logs.
- System logs record the timestamp, account owner (ID + name), system role, and audit description.
