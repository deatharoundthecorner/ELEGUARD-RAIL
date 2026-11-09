# EleGuard Rail — Milestone 9C: Sighting Reporting Workflow & Form Design

**Document:** `docs/phase9/milestone_9c_reporting_workflow.md`  
**Phase:** 9 — Historical Elephant Sighting Intelligence  
**Milestone:** 9C — Temporal & Seasonal Intelligence  
**Status:** DESIGN-ONLY (Interface & Workflow Specifications; No Code Modified)  
**Target Forms:**
- Public Reporter Interface: `public/reporter/submit_report.php`
- Wildlife Officer Sighting Interface: `public/wildlife/submit_report.php`
- Wildlife Review & Verification Interface: `public/wildlife/reports.php`

---

## 1. Overview & Human Factors Considerations

In field situations, elephant encounters occur under diverse circumstances:
1. **Real-Time Sighting:** A commuter on a train or a villager near the track spots an elephant right now and submits immediately from a smartphone.
2. **Delayed Sighting:** A farmer spots an elephant crossing at 05:30 AM while walking to their paddy field, but does not have mobile data until returning home at 11:00 AM.
3. **Wildlife Patrol Log:** A ranger on foot patrol notes elephant tracks and a sighting at "dusk yesterday", and logs the report into the terminal upon returning to the field station.
4. **Retrospective / Text-Only Record:** An officer archives a written historical report from a rural paper ledger.

The reporting workflow must cater to all four scenarios intuitively without overwhelming the user or demanding false precision.

---

## 2. Reporter Submission Form Design (`public/reporter/submit_report.php`)

### Proposed Form Layout & Temporal Module

```
+----------------------------------------------------------------------------------------------------+
| 🐘 WHEN WAS THE ELEPHANT OBSERVED? (Sighting Date & Time)                                           |
+----------------------------------------------------------------------------------------------------+
|                                                                                                    |
|  Sighting Date *                                     Time Precision *                              |
|  [ 2026-11-09            📅 ]                        (o) Exact Time (within ~5 mins)               |
|  Max: Today | Cannot be in future                    ( ) Approximate Window (within ~1 hr)         |
|                                                      ( ) Time Unknown / Not Recorded               |
|                                                                                                    |
|  Sighting Time                                       Quick Time Presets                            |
|  [ 07:45 AM              🕒 ]                        [ Dawn (05:30) ]   [ Morning (09:00) ]        |
|  Enabled only if Exact or Approx selected            [ Dusk (18:15) ]   [ Night (22:00) ]          |
|                                                                                                    |
|  [ℹ️] Note: EleGuard Rail uses sighting time to calculate locomotive night hazard warnings (+2).   |
+----------------------------------------------------------------------------------------------------+
```

### Form Field Interactions & Behavioral Logic

1. **Default Values on Page Load:**
   - `sighting_date`: Pre-filled with today's date in `Asia/Colombo` (`date('Y-m-d')`).
   - `sighting_time`: Pre-filled with current local time (`date('H:i')`).
   - `sighting_time_precision`: Defaults to `'exact'`.
   - `gps_captured_at`: Hidden input, populated dynamically when Geolocation succeeds.
2. **Selecting "Time Unknown":**
   - If the reporter toggles `( ) Time Unknown / Not Recorded`:
     - The `sighting_time` input is disabled, cleared, and grayed out.
     - Quick preset buttons are hidden.
     - Form validation accepts a null time value.
     - `sighting_time_precision` is set to `'unknown'`.
3. **Selecting "Approximate Window":**
   - The time input remains enabled.
   - Quick preset buttons appear: **Dawn** (05:30), **Morning** (09:00), **Afternoon** (14:30), **Dusk** (18:15), **Night** (22:00).
   - Clicking a preset automatically populates the time field.
   - `sighting_time_precision` is set to `'approximate'`.
4. **Calendar Range Bounds:**
   - HTML5 `max` attribute set to `date('Y-m-d')` to prevent future dates in browser UI.
   - HTML5 `min` attribute set to `'1900-01-01'` to permit valid historical records while rejecting corrupt negative/zero years.

---

## 3. Geolocation Temporal Integration

The existing Geolocation JavaScript module in `public/reporter/submit_report.php` and `public/wildlife/submit_report.php` will be updated to capture the hardware satellite acquisition timestamp:

```javascript
// Enhanced Geolocation Success Callback
navigator.geolocation.getCurrentPosition(
    function(position) {
        btnGet.disabled = false;
        const lat = position.coords.latitude.toFixed(6);
        const lng = position.coords.longitude.toFixed(6);
        const acc = position.coords.accuracy ? Math.round(position.coords.accuracy) : null;
        
        // NEW: Capture device GPS hardware timestamp
        if (position.timestamp) {
            const gpsDate = new Date(position.timestamp);
            // Convert to ISO 8601 string: YYYY-MM-DD HH:MM:SS
            const formattedGpsTime = gpsDate.getFullYear() + '-' +
                String(gpsDate.getMonth() + 1).padStart(2, '0') + '-' +
                String(gpsDate.getDate()).padStart(2, '0') + ' ' +
                String(gpsDate.getHours()).padStart(2, '0') + ':' +
                String(gpsDate.getMinutes()).padStart(2, '0') + ':' +
                String(gpsDate.getSeconds()).padStart(2, '0');
            
            document.getElementById('gps_captured_at').value = formattedGpsTime;
        }

        latInput.value = lat;
        lngInput.value = lng;
        accInput.value = acc || '';
        // ...
    },
    // ...
);
```

### Operational Significance
- If a reporter claims they saw elephants today at 08:00 AM, but the phone's GPS fix was captured yesterday at 18:30 PM, the system preserves the device timestamp for wildlife verification officers without rejecting the report.
- Provides cryptographic/telemetric non-repudiation for field patrols.

---

## 4. Backend Validation Rules (`Validation.php` & `ReportService.php`)

New temporal validation methods will be added to `app/includes/validation.php`:

```php
class Validation {
    // ...
    public static function sightingTemporal($date, $time, $precision) {
        $errors = [];

        // 1. Validate Date Presence & Format
        if (empty($date)) {
            $errors['sighting_date'] = 'Sighting date is required.';
            return $errors;
        }

        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            $errors['sighting_date'] = 'Invalid date format. Expected YYYY-MM-DD.';
            return $errors;
        }

        // 2. Prevent Future Sighting Dates
        $today = new DateTime('now', new DateTimeZone('Asia/Colombo'));
        $todayStr = $today->format('Y-m-d');
        if ($date > $todayStr) {
            $errors['sighting_date'] = 'Sighting date cannot be in the future.';
        }

        // 3. Minimum Sighting Date (Sensible historical boundary)
        if ($date < '1900-01-01') {
            $errors['sighting_date'] = 'Sighting date is earlier than system historical limits (1900).';
        }

        // 4. Validate Time Precision Enum
        $allowedPrecisions = ['exact', 'approximate', 'unknown'];
        if (!in_array($precision, $allowedPrecisions, true)) {
            $errors['sighting_time_precision'] = 'Invalid sighting time precision.';
        }

        // 5. Precision Consistency Checks
        if ($precision === 'unknown') {
            // If unknown, time must be empty or null
            if (!empty($time)) {
                $errors['sighting_time'] = 'Time must not be provided when precision is set to Unknown.';
            }
        } else {
            // If exact or approximate, valid time format is required
            if (empty($time)) {
                $errors['sighting_time'] = 'Sighting time is required when precision is Exact or Approximate.';
            } else {
                $t = DateTime::createFromFormat('H:i', $time) ?: DateTime::createFromFormat('H:i:s', $time);
                if (!$t) {
                    $errors['sighting_time'] = 'Invalid time format. Expected HH:MM.';
                } else {
                    // Prevent future time on today's date
                    if ($date === $todayStr) {
                        $currentTimeStr = $today->format('H:i');
                        $inputTimeStr = $t->format('H:i');
                        // Allow 5-minute clock drift tolerance
                        $driftTolerance = (new DateTime('now', new DateTimeZone('Asia/Colombo')))->modify('+5 minutes')->format('H:i');
                        if ($inputTimeStr > $driftTolerance) {
                            $errors['sighting_time'] = 'Sighting time cannot be in the future for today.';
                        }
                    }
                }
            }
        }

        return $errors;
    }
}
```

---

## 5. Wildlife Officer Review & Verification Interface Updates (`public/wildlife/reports.php`)

In `public/wildlife/reports.php`, the single "Date / Time" row will be expanded into a comprehensive **Temporal Verification Panel**:

```html
<!-- Proposed Temporal Verification Panel in Review Table -->
<tr>
    <td style="color:var(--text-secondary); width:35%;">Actual Sighting Time:</td>
    <td>
        <strong><?php echo date('M d, Y', strtotime($selectedReport['sighting_date'])); ?></strong>
        <?php if (!empty($selectedReport['sighting_time'])): ?>
            at <strong><?php echo date('h:i A', strtotime($selectedReport['sighting_time'])); ?></strong>
            <span class="badge badge-sm <?php echo $selectedReport['sighting_time_precision'] === 'exact' ? 'badge-success' : 'badge-warning'; ?>">
                <?php echo ucfirst($selectedReport['sighting_time_precision']); ?>
            </span>
        <?php else: ?>
            <span class="badge badge-sm badge-secondary">Time Unrecorded</span>
        <?php endif; ?>
    </td>
</tr>
<tr>
    <td style="color:var(--text-secondary);">System Ingestion Time:</td>
    <td>
        <code><?php echo format_date($selectedReport['created_at']); ?></code>
        <?php 
            // Calculate reporting lag
            $sightingTs = strtotime($selectedReport['sighting_date'] . ' ' . ($selectedReport['sighting_time'] ?: '00:00:00'));
            $createdTs = strtotime($selectedReport['created_at']);
            $diffHours = round(($createdTs - $sightingTs) / 3600, 1);
        ?>
        <small class="text-muted">(Submitted <?php echo $diffHours; ?> hrs after sighting)</small>
    </td>
</tr>
<?php if (!empty($selectedReport['gps_captured_at'])): ?>
<tr>
    <td style="color:var(--text-secondary);">Hardware GPS Fix:</td>
    <td>
        <code><?php echo format_date($selectedReport['gps_captured_at']); ?></code>
        <span class="badge badge-sm badge-info">Device Authenticated</span>
    </td>
</tr>
<?php endif; ?>
```

---

## 6. Non-Regression Safeguards

1. **Text-Only Legacy Submissions:** Reporters submitting without GPS coordinates can still submit with exact, approximate, or unknown times. GPS remains completely optional.
2. **Offline-First Resilience:** If a reporter submits without specifying time, the form defaults to `'exact'` and current time, maintaining parity with existing 1-click submission behaviors.
3. **No Risk Score Penalty for 'Unknown' Time:** If a historical report has `precision = 'unknown'`, `RiskService` safely evaluates the daytime baseline (does not trigger the night bonus unless independently verified), preventing manufactured risk inflation.
