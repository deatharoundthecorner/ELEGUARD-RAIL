# EleGuard Rail — Milestone 9C: Sighting Reporting Workflow & Form Design (Refined)

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
1. **Real-Time Sighting:** A commuter or villager spots an elephant right now and submits immediately from a smartphone.
2. **Delayed Sighting:** A farmer spots an elephant crossing at 05:30 AM while walking to their paddy field, but does not have mobile data until returning home at 11:00 AM.
3. **Wildlife Patrol Log:** A ranger on foot patrol notes elephant tracks and a sighting at "dusk yesterday", and logs the report into the terminal upon returning to the field station.
4. **Retrospective / Text-Only Record:** An officer archives a written historical report from a rural paper ledger.

The reporting workflow must cater to all four scenarios intuitively without demanding false precision.

---

## 2. Reporter Submission Form Design (`public/reporter/submit_report.php`)

### Proposed Form Layout & Temporal Module

```
+----------------------------------------------------------------------------------------------------+
| 🐘 WHEN WAS THE ELEPHANT OBSERVED? (Sighting Date & Time)                                           |
+----------------------------------------------------------------------------------------------------+
|                                                                                                    |
|  Sighting Date *                                     Time Precision Classification *               |
|  [ 2026-11-09            📅 ]                        (o) Exact Clock Time (within ~5 mins)         |
|  Max: Today | Cannot be in future                    ( ) Approximate Window (Dusk/Dawn/Range)      |
|                                                      ( ) Time Unknown / Not Recorded               |
|                                                                                                    |
|  Sighting Time                                       Approximate Ecological Window Presets         |
|  [ 07:45 AM              🕒 ]                        [ Dawn (04:30-06:30) ] [ Morning (07:30-10:30) ]
|  Enabled for Exact or Approx                         [ Dusk (17:30-19:00) ] [ Night   (20:00-00:00) ]
|                                                                                                    |
|  Uncertainty Window Bounding:                        [ Custom Window... ]                          |
|  &plusmn; 45 minutes (Auto-calculated from preset)                                                 |
|                                                                                                    |
|  [ℹ️] Note: Night sightings (18:00 - 06:00) receive higher locomotive alert priority (+2 score).   |
+----------------------------------------------------------------------------------------------------+
```

### Form Field Interactions & Behavioral Logic

1. **Selecting "Exact Clock Time":**
   - The `sighting_time` input is enabled.
   - `sighting_time_precision` is set to `'exact'`.
   - `sighting_time_window` is set to `NULL`.
   - `sighting_time_uncertainty_minutes` is set to `5` ($\pm 5\text{ mins}$).
2. **Selecting "Approximate Window":**
   - The time input remains enabled.
   - Preset buttons appear:
     - **Dawn:** Central 05:30 $\to$ `window = 'dawn'`, `uncertainty_minutes = 60` ($04:30 - 06:30$).
     - **Morning:** Central 09:00 $\to$ `window = 'morning'`, `uncertainty_minutes = 90` ($07:30 - 10:30$).
     - **Afternoon:** Central 14:30 $\to$ `window = 'afternoon'`, `uncertainty_minutes = 90` ($13:00 - 16:00$).
     - **Dusk:** Central 18:15 $\to$ `window = 'dusk'`, `uncertainty_minutes = 45` ($17:30 - 19:00$).
     - **Night:** Central 22:00 $\to$ `window = 'night'`, `uncertainty_minutes = 120` ($20:00 - 00:00$).
     - **Custom:** User inputs reference time and selects uncertainty slider ($\pm 15$m, $\pm 30$m, $\pm 60$m, $\pm 120$m).
   - `sighting_time_precision` is set to `'approximate'`.
3. **Selecting "Time Unknown / Not Recorded":**
   - The `sighting_time` input is disabled and cleared.
   - Preset buttons and uncertainty fields are hidden.
   - `sighting_time = NULL`.
   - `sighting_time_precision = 'unknown'`.
   - `sighting_time_window = NULL`.
   - `sighting_time_uncertainty_minutes = NULL`.
4. **Calendar Bounds & Validation:**
   - HTML5 `max` attribute set to today's date in `Asia/Colombo` to prevent future sightings.
   - HTML5 `min="1900-01-01"` to support historical entries while preventing negative/corrupt years.

---

## 3. Geolocation Temporal Integration

The Geolocation JavaScript module captures `position.timestamp` from the HTML5 Geolocation API:

```javascript
navigator.geolocation.getCurrentPosition(
    function(position) {
        btnGet.disabled = false;
        const lat = position.coords.latitude.toFixed(6);
        const lng = position.coords.longitude.toFixed(6);
        const acc = position.coords.accuracy ? Math.round(position.coords.accuracy) : null;
        
        // Capture hardware satellite acquisition timestamp
        if (position.timestamp) {
            const gpsDate = new Date(position.timestamp);
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
    },
    // ...
);
```

---

## 4. Wildlife Officer Review & Verification Interface Updates (`public/wildlife/reports.php`)

In `public/wildlife/reports.php`, the review panel provides full temporal telemetry:

```html
<tr>
    <td style="color:var(--text-secondary); width:35%;">Actual Sighting Time:</td>
    <td>
        <?php if (!empty($selectedReport['sighting_date'])): ?>
            <strong><?php echo date('M d, Y', strtotime($selectedReport['sighting_date'])); ?></strong>
            <?php if (!empty($selectedReport['sighting_time'])): ?>
                at <strong><?php echo date('h:i A', strtotime($selectedReport['sighting_time'])); ?></strong>
                <?php if ($selectedReport['sighting_time_precision'] === 'exact'): ?>
                    <span class="badge badge-sm badge-success">Exact (&plusmn;5m)</span>
                <?php elseif ($selectedReport['sighting_time_precision'] === 'approximate'): ?>
                    <span class="badge badge-sm badge-warning">
                        Approx (<?php echo ucfirst($selectedReport['sighting_time_window'] ?: 'Custom'); ?> &plusmn;<?php echo $selectedReport['sighting_time_uncertainty_minutes']; ?>m)
                    </span>
                <?php endif; ?>
            <?php else: ?>
                <span class="badge badge-sm badge-secondary">Time Unrecorded</span>
            <?php endif; ?>
        <?php else: ?>
            <span class="badge badge-sm badge-secondary">Date & Time Unrecorded (Historical Record)</span>
        <?php endif; ?>
    </td>
</tr>
<tr>
    <td style="color:var(--text-secondary);">System Ingestion Time:</td>
    <td>
        <code><?php echo format_date($selectedReport['created_at']); ?></code>
        <?php if (!empty($selectedReport['sighting_date'])): ?>
            <?php 
                $sightingTs = strtotime($selectedReport['sighting_date'] . ' ' . ($selectedReport['sighting_time'] ?: '00:00:00'));
                $createdTs = strtotime($selectedReport['created_at']);
                $diffHours = round(($createdTs - $sightingTs) / 3600, 1);
            ?>
            <small class="text-muted">(Submitted <?php echo $diffHours; ?> hrs after sighting)</small>
        <?php endif; ?>
    </td>
</tr>
```
