# ELEGUARD RAIL — DEPLOYMENT READINESS AND OPERATIONAL LIMITATIONS

**Document Reference:** `docs/final_handover/deployment_readiness_and_limitations.md`  
**Change Reference:** `ER-FINAL-HANDOVER-001`  
**Milestone:** 9C (Stage J Finalization)  
**Date:** November 13, 2026  
**Status:** EVIDENCE-BASED READINESS AUDIT  

---

## 1. CRITICAL REGULATORY & SAFETY DISCLAIMER

> [!IMPORTANT]
> **LEGAL NOTICE:** ELEGUARD RAIL is an advisory early-warning decision support system. It is **not** an automated train protection (ATP), positive train control (PTC), or automatic emergency braking (AEB) system. The software does not interface directly with railway signalling interlockings, track circuits, or locomotive braking actuators. In all circumstances, **official Sri Lanka Railways (SLR) operating rules, General Rules & Regulations, physical track signals, and authorized paper/radio Caution Orders issued by Station Masters take absolute legal and operational precedence.**

---

## 2. SUBSYSTEM READINESS AUDIT

| Subsystem Component | Technical Readiness | Operational Prerequisites Required | Deployment Classification |
|---|---|---|---|
| **Core Web Platform (PHP 8.2)** | Fully implemented, parameterized SQL, session-hardened. | Linux production server with OPcache, `intl`, `pdo_mysql`, and OpenSSL. | **READY** |
| **Relational Database (MariaDB 10.4)** | Schema migrations 01–06 authored and tested on disposable DBs. | DBA-managed migration change window to apply scripts to production DB. | **READY WITH LIMITATIONS** |
| **Offline PWA & Crypto Storage** | AES-GCM 256-bit client-side encryption and Service Worker caching verified. | CA-signed TLS 1.3 SSL certificate installed on production domain (mandatory for Web Crypto). | **READY WITH LIMITATIONS** |
| **Geodetic Target Estimation Engine** | Forward Great-Circle projection and RSS uncertainty validated across 14 tests. | Field calibration of visual range estimation error parameter ($20\%$) across dry-zone forest types. | **READY WITH LIMITATIONS** |
| **Wildlife Officer Verification Desk** | State transitions, audit logging, and two-way clarifications verified. | Official DWC administrative directive establishing wildlife officer shift rosters. | **READY WITH LIMITATIONS** |
| **Railway Control Attribution Desk** | Section geometry matching, proximity zones, and junction geofencing verified. | SLR Control Office integration and review of railway section attribution rules. | **READY WITH LIMITATIONS** |
| **Locomotive Driver In-Cab HUD** | Speed restriction advisories and stationary review policy banners verified; automated speed-based lockout is NOT implemented (no telemetry). | Formal SLR Operating Department safety certification prior to in-cab installation. | **REQUIRES EXTERNAL AUTHORIZATION** |
| **Live Alert Frequency Broadcast** | System alert triggers designed and validated. | Telecommunications Regulatory Commission of Sri Lanka (TRCSL) and SLR Chief Signal Engineer authorization. | **REQUIRES EXTERNAL AUTHORIZATION** |

---

## 3. KNOWN TECHNICAL LIMITATIONS

1. **OpenStreetMap Geometry Planar Margin:**
   - Centerline railway geometry is imported from OpenStreetMap vectors. While sufficient for macro-corridor risk zoning (Zone 0: 0–15m; Zone 1: 15–100m), it possesses an estimated $10\text{--}30\text{m}$ planar uncertainty. It must never be treated as an official railway engineering survey or used for sub-metre clearance calculations.
2. **Circular Uncertainty vs Anisotropic Visual Errors:**
   - Standoff distance errors in the field are anisotropic (visual depth perception error is typically larger than transverse compass azimuth error at close range).
   - Because consumer smartphones provide only a single scalar horizontal accuracy radius, Leaflet map circles render an isotropic upper-bound envelope. The platform explicitly makes no claim of a statistically validated bivariate error ellipse.
3. **Absence of Fabricated GPS Defaults:**
   - In accordance with safety invariants, if a field ranger's phone fails to report hardware GPS accuracy, derived spatial uncertainty is recorded as `NULL`. While scientifically honest and tamper-resistant, the map will suppress uncertainty circles for such reports.
4. **Administrative Replay Bypass:**
   - In Stage J.5, administrators submitting an existing UUID are permitted to receive an idempotent replay acknowledgment regardless of author ID (to facilitate dispatch proxying). Standardizing all cross-user replays to strict HTTP 409 rejection across all roles is recommended in future software iterations.
5. **Locomotive Telemetry & Speed Interlock Absence:**
   - The platform does not interface with locomotive speedometers, tachometers, or train data networks. Locomotive speed is unavailable in the software. The stationary acknowledgement requirement is strictly an operational safety regulation enforced procedurally and through visual UI banners, not an automated hardware/software speed-based lockout.
6. **Absence of Live Railway Field Trials:**
   - All verification evidence is based on automated unit, integration, database-isolation, and simulated concurrency test suites. No live in-service field trials on operational locomotives or active railway corridors have taken place.

---

## 4. PRE-DEPLOYMENT RUNBOOK & PREREQUISITES

### Step 1: Infrastructure Provisioning
- Minimum: 2 Virtual CPUs, 4 GB RAM, 50 GB NVMe SSD storage running Ubuntu 22.04 LTS or Rocky Linux 9.
- Software stack: Apache 2.4+ / Nginx 1.22+, PHP 8.2+ with `intl`, `pdo_mysql`, `openssl`, `mbstring`.
- MariaDB 10.4.32+ cluster with strict InnoDB storage engine.

### Step 2: Database Setup & Migration Execution
1. Create operational database: `CREATE DATABASE eleguard_rail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
2. Create restricted user accounts:
   - `eleguard_app`: `GRANT SELECT, INSERT, UPDATE ON eleguard_rail.* TO 'eleguard_app'@'localhost' IDENTIFIED BY '<strong_pass>';`
   - `eleguard_readonly`: `GRANT SELECT ON eleguard_rail.* TO 'eleguard_readonly'@'localhost' IDENTIFIED BY '<strong_pass>';`
3. Execute migrations 01 through 06 sequentially under DBA supervision:
   - `01_baseline_schema.sql`
   - `02_historical_analytics.sql`
   - `03_temporal_risk_indices.sql`
   - `04_add_field_reporting_v2_to_elephant_reports.sql`
   - `05_add_verification_and_section_audits.sql`
   - `06_add_payload_hash_to_elephant_reports.sql`

### Step 3: SSL Certificate & Domain Configuration
- Install TLS 1.3 certificate (Let's Encrypt or Government CA).
- Configure HTTP Strict Transport Security (HSTS) headers: `Strict-Transport-Security: max-age=31536000; includeSubDomains`.

### Step 4: Governance Handover & Pilot Commissioning
- Establish Joint DWC–SLR Pilot Working Group.
- Conduct simulated corridor exercises with stationary trains at Maho Junction prior to active line deployment.

---

## 5. FORMAL DEPLOYMENT STATUS VERDICT

- **Stage J Status:** Technically complete and locally verified (Phases J.1 through J.6).
- **Handover Package:** Documentation and demonstration scripts prepared for stakeholder review.
- **Production Deployment:** **NOT AUTHORIZED.** No live network cutover or hosting is approved under this scope.
- **Field Trials:** Live in-service railway field trials on active trains have **not** been completed.
- **Regulatory Approvals:** Formal administrative and operational approvals from DWC and SLR remain pending.
- **Safety Certification:** No operational railway safety engineering certification is claimed.
