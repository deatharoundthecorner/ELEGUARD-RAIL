# Manual QA Testing Script & Verification Guide

This script guides developers and quality assurance engineers through verifying the core workflows of EleGuard Rail.

## Test Case 1: Anonymous Public Visitor Access
1. Open a browser window and navigate to:
   `http://localhost/eleguard-rail/`
2. **Expected Result**: Automatically redirected to `http://localhost/eleguard-rail/public/index.php`. The landing page title matches the project name.
3. Click on the public links in the header navbar:
   - **Safety Instructions**: Verify priority badge levels (Low, Medium, High, Critical) are rendered.
   - **FAQ**: Verify clicking Q&A questions collapses/expands accordion panels.
   - **Awareness**: Verify educational articles display correctly.
   - **Emergency Contacts**: Verify phone numbers are structured as clickable anchors (`tel:` links).

## Test Case 2: Reporter Account Registration
1. Tap **Register** in the header.
2. Fill out the registration form:
   - Full Name: `Test Reporter`
   - Email: `testreporter@eleguard.lk`
   - Password: `password123`
   - Confirm Password: `password123`
3. Click **Register Account**.
4. **Expected Result**: Redirected to `login.php` with a green success message: "Account created successfully! You can now log in to submit sightings."

## Test Case 3: Sighting Submission & Risk Score Calculation
1. Log in using the newly created reporter account.
2. Verify redirection to `reporter/dashboard.php`.
3. Click **➕ File Sighting Report**.
4. Fill out the report details:
   - Railway Section: `Habarana - Gal Oya` (Risk: Critical)
   - Elephant Count: `3` (Triggering +2 count bonus)
   - Sighting Urgency: `High (Close to track line)` (Triggering 3 points urgency)
   - Location Description: `Curve past Habarana forest temple`
   - Comments: `Adult elephants spotted feeding on track-side scrub.`
5. Click **Submit Sighting Report**.
6. **Expected Result**: Redirected to `reporter/my_reports.php` with a green notification banner. Sighting status is *Pending*. The computed risk level is **High** or **Critical** (Critical risk is 4 + urgency 3 + count 2 = 9, which maps to *High* risk category).

## Test Case 4: Wildlife Ranger Review and Verification
1. Log out, then log in using ranger credentials:
   - Email: `wildlife@eleguard.lk`
   - Password: `wildlife123`
2. Verify redirection to `wildlife/dashboard.php`. Confirm the statistic count for **Pending Verification** shows `1`.
3. Open the pending reports list, locate the submission, and click **Review**.
4. Review the details. Scroll to the bottom and click **✔️ Verify Report**.
5. **Expected Result**: Confirmed status changes to *Verified*. Redirected to the reports queue with a success notification.

## Test Case 5: Control Room Alert Conversion & Broadcast
1. Log out, then log in using control credentials:
   - Email: `control@eleguard.lk`
   - Password: `control123`
2. Verify redirection to `control/dashboard.php`.
3. In the **Confirmed Sighting Reports** queue, click **Convert to Alert** on the verified report.
4. Verify the broadcast warning form is prefilled:
   - Warning Danger Level matches the computed risk score of the report.
   - Message text field contains prefilled hazard text.
5. Click **🚨 Broadcast Warning Alert**.
6. **Expected Result**: Redirected to `control/alerts.php`. Sighting status changes to *Converted to Alert* and a live active warning is broadcast.

## Test Case 6: Driver Live Cab Warning Alarm
1. Log out, then log in using driver credentials:
   - Email: `driver@eleguard.lk`
   - Password: `driver123`
2. Verify redirection to `driver/dashboard.php`.
3. **Expected Result**: A high-visibility warning card is visible on the cab dashboard displaying:
   - Danger Level: `HIGH` or `CRITICAL`
   - Target Section: `Habarana - Gal Oya`
   - Warn Message: `COLLISION HAZARD: Sighting of 3 elephant(s)...`
4. If a Critical warning is active, verify that a repeating double-beep sound plays automatically in the browser. Click **Mute Warning Alarms** to silence.

## Test Case 7: Driver Acknowledgement Logging
1. Tap **Acknowledge Speed / Action** on the active warning card.
2. In the cab response form:
   - Select **Slowing Down** (Reducing throttle immediately below 20 km/h).
   - Enter remark notes: `Acknowledging alert. Reducing speed to 15km/h.`
3. Click **✔️ Submit Safety Response**.
4. **Expected Result**: Redirected to the dashboard. The card is acknowledged (and logged), and the driver warning panel is updated.

## Test Case 8: Control Room Audit Checks
1. Log out, then log in as `control@eleguard.lk` (Control) or `admin@eleguard.lk` (Admin).
2. Go to **Driver Acknowledgements** log.
3. **Expected Result**: Confirm a response log row is registered displaying:
   - Driver: `Train Driver A`
   - Section: `Habarana - Gal Oya`
   - Action Taken: `Slowing Down`
   - Driver Notes: `Acknowledging alert. Reducing speed to 15km/h.`
   - Timestamp matching the current time.
