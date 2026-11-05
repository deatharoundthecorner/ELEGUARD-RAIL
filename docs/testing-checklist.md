# System Testing Checklist

This checklist describes manual validation scenarios to verify operational logic and security gates.

## 1. Role-Based Authentication & Access Controls

- [ ] **Admin Login**: Sign in as `admin@eleguard.lk` / `admin123`. Verify redirection to `/public/admin/dashboard.php`.
- [ ] **Control Officer Login**: Sign in as `control@eleguard.lk` / `control123`. Verify redirection to `/public/control/dashboard.php`.
- [ ] **Driver Login**: Sign in as `driver@eleguard.lk` / `driver123`. Verify redirection to `/public/driver/dashboard.php`.
- [ ] **Wildlife ranger Login**: Sign in as `wildlife@eleguard.lk` / `wildlife123`. Verify redirection to `/public/wildlife/dashboard.php`.
- [ ] **Reporter Login**: Sign in as `reporter@eleguard.lk` / `reporter123`. Verify redirection to `/public/reporter/dashboard.php`.
- [ ] **Public Visitor Access**: Open index page without login. Verify visibility of awareness articles, safety rules, emergency phone list, FAQs, and Login/Register anchors.
- [ ] **Direct URL Bypass check**: Attempt to access `/public/admin/dashboard.php` as an anonymous user or logged-in reporter. Verify automatic redirect back to login or own dashboard with an unauthorized flash warning banner.

## 2. Core Sighting & Alert Workflow

- [ ] **Register Reporter**: Navigate to Register, create a new user account, and log in.
- [ ] **Submit Sighting Report**: File a sighting report under the *Habarana - Gal Oya* section, with a count of 3 elephants and Critical urgency. Confirm successful redirection to *My Reports* and calculated risk score rating.
- [ ] **Verify Sighting**: Log in as a wildlife ranger, open the reports list, locate the submitted report, review details, and click **Verify Report**. Confirm status changes to *Verified*.
- [ ] **Broadcast Alert**: Log in as a control officer, locate the verified sighting in your reports queue, and click **Convert to Alert**. Verify prefilled warning fields, check calculated threat severity, and click **Broadcast Warning Alert**.
- [ ] **Driver Warning Panel Check**: Log in as a train driver. Confirm the broadcasted warning card is visible. For Critical hazards, confirm the red card styling and sound alarm. Click **Mute Warning Alarms** to silence.
- [ ] **Driver Acknowledgement**: Tap **Acknowledge Speed / Action**, select **Slowing Down**, add notes, and submit. Verify redirect to driver dashboard.
- [ ] **Control Audit**: Log in as control room or admin, open the acknowledgements log, and verify the driver's response status is updated.

## 3. Administrative Settings & CRUD Operations

- [ ] **User CRUD**: Register, edit, and deactivate user credentials.
- [ ] **Section CRUD**: Define new track segments and baseline risks.
- [ ] **Hotspots CRUD**: Link hotspot crossing markers to sections.
- [ ] **Incident Register**: Log historical collisions.
- [ ] **Security Audits**: Open **System Logs** and verify all actions are logged.
- [ ] **CMS Fallback check**: Set project ID blank in `.env`. Open public index, safety, faq, awareness, and emergency contacts pages. Confirm pages render fallback mock data without errors.
