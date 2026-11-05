# User Operations Guide

This guide explains the dashboard interfaces and responsibilities for each of the five user roles in EleGuard Rail.

## 1. Public Visitor
- **Landing Page**: Access system information, problem statements, and call-to-actions at `http://localhost/eleguard-rail/`.
- **Public Articles**: Read educational articles under **Awareness Articles**.
- **FAQ**: View collapsible Q&A accordions.
- **Safety Instructions**: Read priority-coded safety guidelines.
- **Emergency Contacts**: Access ranger and dispatcher phone hotlines.
- **Reporter Registration**: Register a reporter account using the **Register** button.

## 2. Villager / Track Reporter
- **Dashboard**: Track your total submitted sightings, pending checks, and confirmed alerts.
- **Submit Sighting**: Tap **File Sighting Report**:
  - Select the railway section.
  - Enter the number of elephants.
  - Choose sighting proximity urgency.
  - Add details and optionally attach a photo (JPEG/PNG max 2MB).
  - Submit. A threat risk score (Low, Medium, High, Critical) is computed automatically.
- **My Sighting Reports**: View your submission logs and track whether they have been upgraded to train warnings.

## 3. Wildlife ranger Officer
- **Dashboard**: Monitor pending ranger reviews, confirmed crossings, and known hotspots.
- **Review Reports**: Open **Sighting Reports List** to view public entries:
  - Tap **Review** on a pending sighting to check details and attached photo.
  - Tap **Verify Report** to confirm accuracy (marks status as *Verified*).
  - Tap **Reject Sighting** if the report is inaccurate (marks status as *Rejected*).
- **Ranger Sighting**: Submit official ranger reports. These are automatically marked *Verified* upon submission for immediate action.

## 4. Railway Control Room Officer
- **Dashboard**: Monitor active warnings and recent driver responses.
- **Sighting Reports**: View pending and verified reports:
  - Select a ranger-verified report and tap **Convert to Alert**.
  - Review the prefilled alert message and danger level (automatically matches the calculated risk score).
  - Tap **Broadcast Warning Alert** to publish.
- **Manual Alert**: Tap **Create Warning Alert** to manually broadcast warnings from phone emergency calls.
- **Active Alerts**: Manage active warnings. Click **Resolve** once rangers confirm tracks are clear, or click **False** to cancel alerts.
- **Acknowledgements Log**: Audit which train drivers have acknowledged alerts and see their status (Slowing Down, Stopped, Acknowledged).

## 5. Train Driver
- **Dashboard WARNING PANEL**: Designed for simple cab visibility:
  - Features big color-coded warning cards: **Critical** alerts blink red.
  - Generates repeating alarm sound beeps for Critical hazards.
  - Allows drivers to mute warning beeps with the **Mute Warning Alarms** button.
- **Acknowledge Warn**: Tap **Acknowledge Speed / Action** on any hazard:
  - Select your response (Slowing Down, Stopped, Acknowledged, False Alert).
  - Add optional notes (e.g., visual fog, tracks clear upon arrival).
  - Submit. Action responses are logged at Railway Control.
- **Archive & History**: Review past logs and acknowledgements.
- **Minimal Navigation**: Simplifies cab use to minimize distractions.
