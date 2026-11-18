# ELEGUARD RAIL — Project Status

**Official Status:** **COMPLETED — DEVELOPMENT FROZEN**
**Final Release Baseline:** November 2026
**License:** [MIT License](LICENSE)
**Public Repository:** [https://github.com/deatharoundthecorner/ELEGUARD-RAIL](https://github.com/deatharoundthecorner/ELEGUARD-RAIL)

---

## 1. Executive Notice: Active Development Concluded

Active engineering, iterative feature development, and formal milestone delivery for **ELEGUARD RAIL** have officially concluded.

The project repository is now frozen and published in a stable, verified, documented state for the benefit of academic researchers, railway engineering students, wildlife conservation technologists, and open-source software developers.

> [!IMPORTANT]
> **Maintenance & Support Notice:**
> The original project team is no longer actively developing, monitoring, or maintaining this codebase. No further feature roadmaps, commercial deployments, or maintenance releases will be issued by the founding authors.
>
> Under the terms of the [MIT License](LICENSE), third parties, research institutions, and community contributors are invited to independently clone, fork, modify, audit, and extend the software for academic, ecological, or prototype engineering purposes.

---

## 2. Final Implemented Features & Capabilities

The platform implements an end-to-end early-warning and situational-awareness decision-support prototype covering four operational domains:

### A. Field Reporting & Offline Telemetry (PWA)
- **Offline-First Progressive Web App (PWA):** Service Worker caching with connectivity-aware visual status indicator.
- **Client-Side Cryptographic Vault:** Web Crypto API (`AES-GCM-256`) with PBKDF2 passphrase key derivation protecting offline draft sightings at rest in IndexedDB.
- **Forward Geodesic Projection:** Forward azimuth and distance trigonometry (Haversine/Vincenty equations) computing estimated animal coordinates from safe observer standoff distances (50–150 m) with Root-Sum-Square (RSS) uncertainty propagation.
- **Idempotent Batch Synchronization:** Client-generated UUIDv4 tokens and SHA-256 payload hashing preventing duplicate records during sporadic rural connectivity reconnects.

### B. Biological Triage & Verification (DWC Wildlife Officers)
- **Finite-State Machine (FSM) Lifecycle:** Strict state transitions (`pending` -> `verified` or `rejected` with reversible review audits).
- **Two-Tier Structured Review:** Split-screen dossier comparing observer field photos, standoff calculations, and AI advisory signals.
- **YOLOX-Tiny ONNX Advisory Vision:** Permissively licensed (Apache 2.0) lightweight neural network providing scalar classification confidence (`Confirmed` ≥40%, `Uncertain` 20%–40%, `Negative` <20%).
- **Interactive Standoff Geometry:** Geodesic track standoff distance calculation and biological certainty indexing.

### C. Railway Corridor Situational Awareness (Traffic Control Desk)
- **Interactive GIS Corridor Map:** Leaflet.js map with custom high-contrast dark theme, corridor GeoJSON track alignments, and dynamic hazard heat zones.
- **Diurnal Risk Engine:** Continuous temporal hazard calculation factoring in 7 diurnal circadian phases (peak crepuscular crossing activity) and proximity decay.
- **Speed Advisory Calculation:** Automatic caution speed recommendations (e.g. 30 km/h) displayed for active, verified corridor hazards.

### D. In-Cab Advisory HUD (Locomotive Drivers)
- **High-Contrast Safety HUD:** Clean, non-distracting driver interface displaying track-ahead hazard proximity alerts.
- **Stationary Review Enforcement:** Interface prompts drivers to review advisory details strictly during stationary safety stops, mitigating in-transit operator distraction.
- **Driver Acknowledgement Auditing:** Driver acknowledgements recorded with timestamp, train ID, and station stop context.

### E. Platform Governance & Auditing (System Administrators)
- **Dataset Provenance Segregation:** Operational isolation separating operational baseline records from simulation scenarios and synthetic stress test data.
- **REST API v2:** RFC 9457 compliant error handling and standard envelope structures (`status`, `data`, `meta`, `errors`).
- **Database Privilege Separation:** Multi-user MariaDB configuration with engine-level immutable read-only constraints (`eleguard_readonly`).

---

## 3. Technology Stack

| Layer | Component | Version / Specification |
|---|---|---|
| **Backend Runtime** | PHP | 8.2+ (PDO MySQL, OpenSSL, JSON, MBString) |
| **Relational Database** | MariaDB / MySQL | MariaDB 10.4+ / MySQL 8.0+ (InnoDB, UTF-8 MB4) |
| **Web Server** | Apache HTTP Server | 2.4+ (`mod_rewrite`, `.htaccess` security hardening) |
| **Frontend Architecture** | Vanilla ES6 JavaScript | Native ES Modules, Service Worker API, IndexedDB API |
| **Client-Side Cryptography** | W3C Web Crypto API | AES-GCM (256-bit keys), PBKDF2 (SHA-256, 100,000 iterations) |
| **Mapping Engine** | Leaflet.js | 1.9.4 with custom SVG layer renderer |
| **Edge AI Runtime** | Python & ONNX Runtime | Python 3.10+, `onnxruntime` 1.16+, OpenCV 4.8+ |
| **Edge Vision Model** | YOLOX-Tiny ONNX | Apache 2.0 license (~19 MB, 416×416 input size) |
| **Test Infrastructure** | Custom PHP Test Runner | 35 automated test suites with headless CDP verification |

---

## 4. Verification & Testing Status

- **Automated Regression Suite:** 35 / 35 test suites passing (`tests/run_all_tests.php`).
- **Assertion Coverage:** Over 1,647 automated assertions validating geodetic solvers, cryptographic envelopes, RBAC rules, database isolation, and API endpoints.
- **Database Safety Isolation:** Engine-level read-only immutability verified; all tests execute on ephemeral disposable test databases (`eleguard_test_tmp_*`) that are dropped upon completion.
- **Browser Concurrency:** Headless Chromium/Edge integration verified via Chrome DevTools Protocol (CDP) with resilient polling loops.

---

## 5. Deployment & Operational Status

- **Current Deployment Status:** **Academic & Laboratory Prototype Only.**
- **Operational Railway Deployment:** **NOT AUTHORIZED.** The software is not deployed in active revenue railway service.
- **Safety Certification Status:** **NOT CERTIFIED.** The system has not undergone formal safety-critical software certification (CENELEC EN 50126, EN 50128, EN 50129, or IEC 61508 SIL 1–4).
- **Physical Locomotive Control:** **NONE.** The system operates completely outside physical train control loops. It does not interface with locomotive braking systems, throttle controls, cab signalling, or track interlocking.

---

## 6. Field Validation Status

- **Real-World Field Trials:** **PENDING / NOT CONDUCTED.**
- **Data Provenance:** All demonstration sighting records, telemetry coordinates, incident reports, and driver responses in the public repository represent **synthetic demonstration fixtures** designed for functional validation and educational demonstration.
- **Biological Accuracy:** Animal detection and geodetic algorithms have been tested against static image datasets and simulated scenarios, but have not undergone multi-year ecological field validation in Sri Lankan jungle reserves.

---

## 7. Known Limitations

1. **Locomotive Position Simulation:** Real-time train tracking is currently simulated using GeoJSON track geometries rather than live GPS/GSM locomotive transponders.
2. **Static Image Ingestion:** AI computer vision evaluates uploaded static photographs; it does not ingest live high-frame-rate RTSP video feeds from trackside thermal/optical camera networks.
3. **Tile Caching Connectivity:** Leaflet base tiles require internet connectivity for initial regional caching, although local GeoJSON track alignment vectors function offline.
4. **Single-Node Architecture:** Configured for single-instance Apache/PHP environments; horizontal clustering or containerized orchestration (Docker/Kubernetes) is left as a future engineering objective.

---

## 8. Opportunities for Future Research & Development

Independent researchers, students, and organizations wishing to extend ELEGUARD RAIL may consider the following avenues:

1. **Hardware In-Cab Telemetry:** Integrating physical GPS receivers and LoRaWAN/satellite communication modules for real-time locomotive telemetry in cellular blind spots.
2. **Trackside Edge AI Camera Nodes:** Deploying solar-powered edge computing devices (e.g. NVIDIA Jetson, Raspberry Pi with Coral TPU) along critical forest cuttings for autonomous sighting ingestion.
3. **Dynamic Diurnal Migration Modeling:** Incorporating seasonal weather indices, water reservoir levels, and satellite vegetation data (NDVI) into the spatial risk engine.
4. **Offline Vector Tile Packaging:** Implementing client-side MBTiles or PMTiles stored directly in IndexedDB for complete zero-connectivity mapping.
