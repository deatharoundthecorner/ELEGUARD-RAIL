# ELEGUARD RAIL — Architectural Decision Records (ADRs)

**Document Reference:** `ER-ADR-2026-V1`
**Classification:** System Architecture & Technical Governance
**Repository:** `deatharoundthecorner/ELEGUARD-RAIL`
**Working Directory:** `C:\Users\user\Desktop\Railway\eleguard-rail`
**Status:** Approved & Formally Ratified

---

## Index of Architectural Decisions

- [ADR-001: Three-Database Architecture & Operational DB Immutability](#adr-001-three-database-architecture--operational-db-immutability)
- [ADR-002: Least-Privilege Database Role Separation](#adr-002-least-privilege-database-role-separation)
- [ADR-003: Decoupled Observer vs. Animal Georeferencing & Great-Circle Forward Projection](#adr-003-decoupled-observer-vs-animal-georeferencing--great-circle-forward-projection)
- [ADR-004: Continuous 7-Period Diurnal Continuum & Temporal Evidence Normalization](#adr-004-continuous-7-period-diurnal-continuum--temporal-evidence-normalization)
- [ADR-005: Client-Report UUID Idempotency & Deterministic Canonical Fingerprinting](#adr-005-client-report-uuid-idempotency--deterministic-canonical-fingerprinting)
- [ADR-006: Web Crypto API AES-GCM-256 Offline Encrypted Storage with PBKDF2](#adr-006-web-crypto-api-aes-gcm-256-offline-encrypted-storage-with-pbkdf2)
- [ADR-007: Locally Executed Advisory AI Detection via YOLOX-Tiny ONNX Subprocess](#adr-007-locally-executed-advisory-ai-detection-via-yolox-tiny-onnx-subprocess)
- [ADR-008: Human-in-the-Loop Wildlife Verification Workflow & Immutable Auditing](#adr-008-human-in-the-loop-wildlife-verification-workflow--immutable-auditing)
- [ADR-009: GIS Interactive Visualization & Railway Section Topology Attribution](#adr-009-gis-interactive-visualization--railway-section-topology-attribution)
- [ADR-010: RFC 9457 Problem Details Standard for API Error Envelopes](#adr-010-rfc-9457-problem-details-standard-for-api-error-envelopes)

---

## ADR-001: Three-Database Architecture & Operational DB Immutability

### Context
ELEGUARD RAIL maintains historical field observations recorded during preliminary research phases. In addition, the system must support active demonstration environments and a rigorous 35-suite automated regression test runner. Running tests or demonstration scenarios against the historical database risks schema corruption, data loss, and test flakiness.

### Decision
Implement a strict three-database architecture enforced at both the configuration level and the application database connection factory (`app/core/Database.php`):
1. **`eleguard_rail` (Operational Historical Database):** Contains 43 genuine historical sighting records frozen at Migration 03. Engine-level grants strictly forbid `INSERT`, `UPDATE`, or `DELETE` operations from application test runners.
2. **`eleguard_rail_demo` (Demonstration & Local Runtime Database):** Contains 12 fully migrated tables (Migrations 01 through 06) and 44 demonstration records. Configured in `.env` as the default target for local web runtime and interactive UI walkthroughs.
3. **`eleguard_test_tmp_*` (Disposable Test Databases):** Created dynamically on-demand by the test runner (`tests/run_all_tests.php`), provisioned with full schemas, populated with test fixtures, and unconditionally dropped upon completion.

### Consequences
- **Positive:** Zero risk of accidental corruption to historical field data; complete isolation between concurrent test executions; eliminates test contamination.
- **Negative:** Demonstration environments must be explicitly populated; requires proper privilege management on the local MariaDB server.

---

## ADR-002: Least-Privilege Database Role Separation

### Context
Running web applications and automated tests with administrative database credentials (`root` or `ALL PRIVILEGES`) violates fundamental principle of least privilege, exposing the database to SQL injection escalation and accidental schema alteration.

### Decision
Establish four distinct database user accounts with hard boundaries:
- **`eleguard_admin`:** Restricted to DDL migrations and administrative maintenance.
- **`eleguard_user`:** Standard application runtime user with `SELECT`, `INSERT`, `UPDATE` grants on operational tables.
- **`eleguard_readonly`:** Read-only user with `SELECT` privileges only, dedicated to historical analytics, temporal risk calculation, and reporting dashboards.
- **`eleguard_test_ro`:** Dedicated read-only test user for read isolation verification.

Application code in `app/core/Database.php` strictly verifies credentials upon instantiation and refuses to initialize if running under unapproved credentials in a test context.

### Consequences
- **Positive:** Database engine prevents unauthorized mutations; read-only analytics queries cannot inadvertently modify operational state.
- **Negative:** Requires credential synchronization across `.env` files.

---

## ADR-003: Decoupled Observer vs. Animal Georeferencing & Great-Circle Forward Projection

### Context
Wildlife observers and field rangers maintain a safe standoff distance (often 50–500 metres) when observing wild elephant herds along railway cutting verges. Storing the observer's GPS coordinate as the animal's physical location introduces severe spatial error, falsely assigning elephant herds to railway tracks or misleading locomotive drivers.

### Decision
Decouple observer position from estimated target animal position:
1. Record observer hardware GNSS coordinates (`observer_latitude`, `observer_longitude`, `observer_gps_accuracy_meters`).
2. Record visual standoff distance ($d$ in metres) and compass bearing ($\theta$ in degrees from true North).
3. Compute estimated animal geodetic coordinates using Great-Circle Forward Projection (Spherical IUGG model, $R = 6,371,000\text{ m}$):
   $$\phi_2 = \arcsin(\sin\phi_1 \cos\delta + \cos\phi_1 \sin\delta \cos\theta)$$
   $$\lambda_2 = \lambda_1 + \arctan2(\sin\theta \sin\delta \cos\phi_1, \cos\delta - \sin\phi_1 \sin\phi_2)$$
4. Propagate spatial uncertainty using Root-Sum-Square (RSS) error formulation:
   $$\sigma_{pos} = \sqrt{\sigma_{gps}^2 + \sigma_{range}^2 + (d \cdot \sin\sigma_{bearing})^2}$$
   where $\sigma_{range} = \max(5.0, 0.20 \cdot d)$ and $\sigma_{bearing} = 10^\circ$.

If distance or bearing is missing, target coordinates remain strictly `NULL` and `is_estimated_position = 0` to prevent misleading precision.

### Consequences
- **Positive:** Mathematically rigorous spatial positioning; prevents false proximity alerts; maintains observer safety.
- **Negative:** Requires field rangers to record distance and bearing estimates.

---

## ADR-004: Continuous 7-Period Diurnal Continuum & Temporal Evidence Normalization

### Context
Arbitrary temporal classifications (e.g. "Morning", "Evening") create gaps and ambiguities, especially across twilight transitions where elephant movement across railway corridors surges.

### Decision
Standardize all temporal evidence into a gapless 24-hour continuous 7-period diurnal continuum referenced strictly to Sri Lanka Standard Time (`Asia/Colombo`, UTC+05:30):

| Diurnal Period | Clock Time Interval | Midpoint Time | Time Uncertainty ($\sigma_t$) |
|---|---|---|---|
| **Dawn** | 04:00:00 – 07:00:00 | 05:30:00 | $\pm 90\text{ min}$ |
| **Morning** | 07:00:00 – 11:00:00 | 09:00:00 | $\pm 120\text{ min}$ |
| **Midday** | 11:00:00 – 14:00:00 | 12:30:00 | $\pm 90\text{ min}$ |
| **Afternoon** | 14:00:00 – 17:00:00 | 15:30:00 | $\pm 90\text{ min}$ |
| **Dusk** | 17:00:00 – 19:30:00 | 18:15:00 | $\pm 75\text{ min}$ |
| **Night** | 19:30:00 – 23:30:00 | 21:30:00 | $\pm 120\text{ min}$ |
| **Deep Night** | 23:30:00 – 04:00:00 | 01:45:00 | $\pm 135\text{ min}$ |

Future timestamps are rejected at ingestion time. If exact clock time is provided, the diurnal period is automatically inferred; if only an approximate period is provided, the midpoint is assigned with the corresponding uncertainty window.

### Consequences
- **Positive:** Enables consistent statistical modeling of temporal risk curves; handles cross-midnight boundary seamlessly.
- **Negative:** Observers must choose from predefined diurnal periods when exact time is unavailable.

---

## ADR-005: Client-Report UUID Idempotency & Deterministic Canonical Fingerprinting

### Context
Field rangers operating in remote rural areas with intermittent cellular connectivity frequently trigger duplicated network requests during offline synchronization retries. Without robust server-side idempotency, this results in duplicate sighting records, skewed risk scores, and erroneous alerts.

### Decision
Implement server-side transactional idempotency in `FieldReportingService.php`:
1. **Client Report UUID:** Every sighting draft generates an RFC 4122 v4 UUID on the client device. This UUID is preserved across all network retries.
2. **Canonical Payload Fingerprinting:** The server calculates a deterministic SHA-256 fingerprint (`payload_hash`) over canonicalized observation attributes (excluding volatile metadata, timestamps, and server IDs).
3. **Replay Evaluation Logic:**
   - Same User + Matching UUID + Matching Fingerprint $\rightarrow$ **HTTP 200 OK** (Idempotent replay; returns existing record ID).
   - Same User + Matching UUID + Different Fingerprint $\rightarrow$ **HTTP 409 Conflict** (`payload-mismatch`).
   - Different User + Matching UUID $\rightarrow$ **HTTP 409 Conflict** (`uuid-conflict`, zero disclosure of conflicting record).

### Consequences
- **Positive:** 100% resilient against double-submissions and network dropouts; guarantees zero duplicate records from sync retries.
- **Negative:** Requires client application to maintain UUID state throughout the submission lifecycle.

---

## ADR-006: Web Crypto API AES-GCM-256 Offline Encrypted Storage with PBKDF2

### Context
Field observations contain sensitive high-precision wildlife location coordinates. Storing drafts in unencrypted browser storage (`localStorage` or plaintext `IndexedDB`) creates poaching security vulnerabilities if a field device is lost or compromised.

### Decision
Implement a cryptographic vault using the W3C Web Crypto API (`public/assets/js/field_crypto_storage.js`):
1. **Key Derivation:** Derive a 256-bit AES key using PBKDF2 with SHA-256 and **250,000 iterations** from the user's authenticated session secret and a unique cryptographic salt.
2. **Authenticated Encryption:** Encrypt sighting payloads using **AES-GCM-256**.
3. **Initialization Vector (IV):** Generate a cryptographically secure, unique 96-bit (12-byte) IV for every encryption operation using `crypto.getRandomValues`.
4. **AAD Binding:** Bind Additional Authenticated Data (AAD) containing the client UUID to prevent ciphertext transplantation attacks.
5. **Passive Expiry:** Enforce a passive 72-hour pre-read expiry guard; expired records are securely erased.

### Consequences
- **Positive:** Zero plaintext exposure in browser storage; protects high-value wildlife coordinates against device loss.
- **Negative:** Requires modern browser with Web Crypto API support; key material is held in memory during the active session.

---

## ADR-007: Locally Executed Advisory AI Detection via YOLOX-Tiny ONNX Subprocess

### Context
The prototype requires automated elephant detection to assist officers during photograph review without relying on external cloud APIs (maintaining zero operational budget and zero external data exfiltration).

### Decision
1. **Model Selection:** Deploy **YOLOX-Tiny ONNX** (Apache 2.0 license, 3.84 MB, $416 \times 416$ resolution), pre-trained on COCO class 20 (`elephant`).
2. **Execution Method:** Execute locally via a lightweight Python CLI script (`ai/detector/cli.py`) invoked via `proc_open` in PHP (`app/services/AiDetectionService.php`).
3. **Timeout Protection:** Impose a strict **5.0-second subprocess timeout** with non-blocking stream reading; any hanging process is forcefully terminated with graceful degradation (`ai_status = 'failed'`).
4. **Classification Thresholds:**
   - Max Confidence $\ge 0.40 \rightarrow$ Confirmed Elephant Candidate
   - $0.20 \le \text{Max Confidence} < 0.40 \rightarrow$ Uncertain / Low-Confidence
   - Max Confidence $< 0.20 \rightarrow$ Negative / No Elephant Detected
5. **Strict Governance:** AI output is **strictly advisory**. AI detections NEVER automatically verify reports, never alter risk scores, and never trigger train alerts.

### Consequences
- **Positive:** Completely offline and self-contained; zero cloud cost; fast inference (~25ms ONNX runtime); fully decoupled from report ingestion.
- **Negative:** Requires local Python 3 runtime with ONNX Runtime and OpenCV installed.

---

## ADR-008: Human-in-the-Loop Wildlife Verification Workflow & Immutable Auditing

### Context
Elevating unverified citizen sightings or raw AI detections directly to locomotive drivers creates alert fatigue and dangerous operational disruptions.

### Decision
Implement an immutable, human-in-the-loop verification gate (`VerificationService.php`):
1. Only authorized Wildlife Officers (`wildlife_officer`) and System Administrators (`admin`) hold legal authority to verify or reject reports.
2. Verification decisions are recorded in an append-only `report_verifications` table with timestamp, verifying officer ID, action (`Verified`, `Rejected`), and biological justification notes.
3. Once verified, the report status transitions to `Verified`. Only verified reports within active corridor buffers can be elevated to operational situational awareness advisories by Railway Control.

### Consequences
- **Positive:** Guarantees official human accountability; eliminates false-positive driver alarms; creates an unbroken forensic audit trail.
- **Negative:** Introduces a human verification latency between field submission and advisory publication.

---

## ADR-009: GIS Interactive Visualization & Railway Section Topology Attribution

### Context
Locomotive drivers and control officers require instant spatial comprehension of track corridors, sighting clusters, and spatial uncertainty buffers without visual clutter.

### Decision
Implement a modular GIS visualization component (`public/assets/js/map_component.js`) using Leaflet.js with custom panes and HTML5 Canvas optimization:
1. **Layer Hierarchy (Z-Index Panes):**
   - Track Corridors (`300`) $\rightarrow$ Active Corridor Focus (`350`) $\rightarrow$ Ambiguity Zones (`400`) $\rightarrow$ Uncertainty Circles (`450`) $\rightarrow$ Proximity Connectors (`500`) $\rightarrow$ Observer Pins (`550`) $\rightarrow$ Elephant Pins (`600`) $\rightarrow$ Safety Alerts (`650`).
2. **Topology Ambiguity Detection:** When a sighting falls within 300m of multiple converging railway sections (ratio $\ge 0.70$) or within junction geofences (Gal Oya, Maho), the system automatically flags `topology_ambiguity` and renders a 500m ambiguity zone, requiring manual control officer confirmation.
3. **Anti-Poaching Coordinate Masking:** Sighting coordinates displayed to public or unauthorized roles are automatically masked to 2 decimal places ($\approx 1.1\text{km}$ obfuscation). Full 8-decimal precision is restricted to Wildlife Officers.

### Consequences
- **Positive:** Fast rendering performance via Canvas; clear separation between observer standoff and animal location; intuitive risk color coding.
- **Negative:** Offline vector fallback depends on cached GeoJSON route definitions.

---

## ADR-010: RFC 9457 Problem Details Standard for API Error Envelopes

### Context
Inconsistent error formats across legacy endpoints made error handling brittle for mobile clients and offline synchronization controllers.

### Decision
Adopt the IETF **RFC 9457 Problem Details for HTTP APIs** standard for all API error responses (`app/helpers/api_helper.php`):
```json
{
  "type": "https://eleguard.rail.gov.lk/errors/validation-error",
  "title": "Invalid Observation Payload",
  "status": 422,
  "detail": "The provided sighting report contains validation errors.",
  "invalid_params": {
    "elephant_count": "Elephant count must be a positive integer."
  }
}
```

Standardized HTTP status codes:
- `200 OK`: Idempotent replay or successful query.
- `201 Created`: New report ingested.
- `207 Multi-Status`: Batch synchronization with mixed itemized outcomes.
- `400 Bad Request`: Malformed JSON or invalid syntax.
- `401 Unauthorized`: Authentication required.
- `403 Forbidden`: Insufficient role permissions.
- `409 Conflict`: UUID collision or payload mismatch.
- `413 Payload Too Large`: Batch exceeds 10 MB limit.
- `422 Unprocessable Entity`: Semantic validation failures.
- `500 Internal Server Error`: Safe internal database error.

### Consequences
- **Positive:** Predictable, self-documenting client contract; comprehensive machine-readable error handling.
- **Negative:** Slightly increased response payload size compared to plain string errors.
