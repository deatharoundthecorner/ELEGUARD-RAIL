# EleGuard Rail — Milestone 9C Stage 4C
## Read-Only Historical Analytics Query Layer Architecture & Verification Report

### Document Metadata
- **Project:** EleGuard Rail (Sri Lanka Wildlife-Railway Collision Early Warning Platform)
- **Milestone:** 9C — Historical Intelligence & Analytics Query Layer
- **Stage:** 4C — Read-Only Analytics Query Layer
- **Baseline Git Commits:**
  - Stage 4A (Audit & Architecture): `f6491ca`
  - Stage 4B (Duplicate Detection): `36d4c95`
  - Stage 4B.1 (Hardening & Security): `ad07d87`
- **Date:** November 2026
- **Status:** Complete & Independently Verified

---

## 1. Executive Summary

Milestone 9C Stage 4C introduces a dedicated, read-only analytics query service (`AnalyticsService.php`) for the EleGuard Rail platform. Designed to compute historical statistics, temporal distributions, spatial aggregations, data quality telemetry, and AI advisory concordance, the service provides an explainable analytics layer without performing a single mutation against operational databases.

The implementation strictly honors all 15 safety mandates:
1. **Zero Operational Mutations:** Zero `INSERT`, `UPDATE`, `DELETE`, `ALTER`, `DROP`, `TRUNCATE`, or `REPLACE` queries. Enforced at both the architectural and regex runtime levels.
2. **Operational Database Immutability:** Pre-flight and post-flight audits confirm zero modifications to operational database `eleguard_rail` (retaining exactly 43 reports, 185 system logs, 15 alerts, and Report #1 pristine).
3. **Strict Temporal Integrity:** `created_at` submission timestamps are never substituted for missing `sighting_time` observation timestamps. Reports lacking observation timestamps are transparently placed in unrecorded/uncomputable buckets.
4. **Provenance Segregation:** Development test records, AI evaluation artifacts, and E2E audit fixtures are strictly segregated from genuine field statistics. Unknown provenance is maintained as an explicit separate category and never assumed to be genuine.
5. **Role-Based Coordinate Masking:** Sensitive wildlife sighting and hotspot coordinates are dynamically fuzzed to 2 decimal places (~1.1 km spatial fuzzing) for unauthorized roles (`driver`, `reporter`, and public viewers) to prevent poaching and unauthorized tracking.
6. **AI Advisory Governance:** AI statistics report human officer workflow concordance and decision matrices rather than claiming certified model accuracy without genuine labeled field ground truth.

---

## 2. Service Architecture (`AnalyticsService.php`)

`app/services/AnalyticsService.php` is structured as a stateless, read-only service that accepts an active PDO instance and returns explainable analytical arrays.

### 2.1 SQL Execution Sandbox (`executeReadOnlyQuery`)
All database interactions within `AnalyticsService` route through `executeReadOnlyQuery(PDO $pdo, string $sql, array $params = [])`:
```php
public static function executeReadOnlyQuery(PDO $pdo, string $sql, array $params = []) {
    $trimmed = trim($sql);
    if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $trimmed)) {
        throw new InvalidArgumentException("Write/DDL operations are strictly prohibited in AnalyticsService.");
    }
    if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|REPLACE|CREATE)\b/i', $trimmed)) {
        throw new InvalidArgumentException("Mutation keywords detected; execution blocked.");
    }
    // Execution proceeds with prepared PDO statement...
}
```

### 2.2 Analytical Methods Breakdown

| Method | Analytical Domain | Key Safeguards & Deliverables |
|---|---|---|
| `getGeneralStatistics(PDO, array)` | High-level platform tallies | Total reports, status breakdown, urgency breakdown, risk score distribution, and strict provenance segregation (`genuine_field`, `development_test`, `ai_test`, `e2e_audit`, `unknown`). |
| `getTemporalAnalytics(PDO, array)` | Hourly, diurnal, and monthly distributions | Exactly 24 diurnal hourly bins (`'00'`–`'23'`), 5 diurnal windows (`dawn`, `morning`, `afternoon`, `dusk`, `night`), unrecorded time tallies, and monthly longitudinal spread warnings. |
| `getSectionAnalytics(PDO, array)` | Railway section risk aggregation | All 4 registered railway sections with report counts, verification ratios, GPS coverage, duplicate tallies, and governance notices forbidding automatic geometric reassignments. |
| `getHotspotAnalytics(PDO, array)` | Hotspot inventory | 4 manually configured hotspots with section references, justification metadata, classification as `manually_configured`, and role-based coordinate masking. |
| `getDuplicateAnalytics(PDO, array)` | Spatial-temporal duplicate intelligence | Stored duplicate flags, distinct cluster counts, zero confirmed duplicates policy (requiring human officer verification), and pairwise candidate evaluations. |
| `getReportingLatencyAnalytics(PDO, array)` | Latency and lag telemetry | Exact delay distributions, approximate delay ranges via `TemporalService::calculateReportingDelay`, uncomputable observation counts, and verification latency percentiles. |
| `getDataQualityAnalytics(PDO, array)` | Data quality & completeness audit | Missing dates ratio, missing times ratio, GPS completeness, coordinate validity in Sri Lanka, track section discrepancies via `ProximityService`, and overall completeness index (0–100%). |
| `getAiAdvisoryAnalytics(PDO, array)` | AI subsystem & concordance | AI status breakdown (`processed`, `skipped`, `pending`, `failed`), officer concordance matrix (`concordant_verified`, `concordant_rejected`, `pending_officer_review`), and advisory-only notice. |
| `getFullAnalyticsSummary(PDO, array)` | Unified analytical payload | Consolidates all 7 analytical domains into a single structured response with global generation timestamp and provenance notices. |

---

## 3. Strict Safety & Governance Safeguards

### 3.1 Temporal Integrity & No Created_At Substitution
A central defect identified in Stage 4A was the historical tendency to substitute `created_at` (submission timestamp) for `sighting_time` (observation timestamp).
In `AnalyticsService`:
- Diurnal hourly bins (00–23) are populated strictly when `sighting_time` is non-null and `sighting_time_precision != 'unknown'`.
- All historical baseline records (IDs 1–3) lack observation timestamps and are cataloged as `unrecorded_times`.
- Reporting latency calculations mark reports lacking observation timestamps as `uncomputable_timing_count`, emitting an explicit governance warning that `created_at` cannot be assumed equal to sighting time.

### 3.2 Provenance Segregation
Analytical queries classify reports using `DuplicateService::classifyProvenance()` into five mutually exclusive buckets:
- `genuine_field`: Genuine operational sightings submitted by field users.
- `development_test`: Automated seed records and manual test triads.
- `ai_test`: AI integration and detector verification fixtures.
- `e2e_audit`: End-to-end integration test records.
- `unknown`: Records lacking metadata or having unverified provenance.

*Governance Policy:* Test records are segregated from genuine wildlife statistics, and `unknown` provenance records are maintained as an explicit separate category—never assumed to be genuine field sightings.

### 3.3 Role-Based Coordinate Privacy
To protect endangered Asian elephants (*Elephas maximus maximus*) from poaching and disturbance, coordinate exposures are governed by `AnalyticsService::isRoleAuthorizedForCoordinates($role)`:
- **Authorized Roles:** `wildlife_officer`, `control_officer`, `admin`. Full raw floating-point coordinates are returned.
- **Restricted Roles:** `driver`, `reporter`, unauthenticated public viewers. Coordinates are fuzzed to 2 decimal places (~1.1 km grid resolution), and an explicit `coordinate_masking_notice` is appended.

### 3.4 AI Advisory Governance
In alignment with Platform Policy:
- AI is strictly advisory.
- `getAiAdvisoryAnalytics` reports officer decision concordance rather than asserting predictive accuracy.
- Concordance is tracked as human officer action (`Verified`, `Rejected`, or `Pending`) relative to the AI advisory suggestion.

---

## 4. Verification and Test Results

The test suite `tests/test_milestone_9c_stage4c_analytics_service.php` was executed against an isolated clone database (`eleguard_rail_test`) with pre-flight and post-flight operational database audits.

### 4.1 Stage 4C Test Suite Results (73 / 73 Passed)
```
========================================================================
EleGuard Rail — Milestone 9C Stage 4C Test Suite
Read-Only Historical Analytics Query Layer
========================================================================

--- 0. PRE-FLIGHT OPERATIONAL SAFETY AUDIT ---
  [PASS] 0.1 Operational DB elephant_reports baseline is 43
  [PASS] 0.2 Operational DB system_logs baseline is 185
  [PASS] 0.3 Operational DB alerts baseline is 15
  [PASS] 0.4 Operational Report #1 sighting time is NULL
  [PASS] 0.5 Operational Report #1 risk score baseline is Medium

--- 1. TOTAL REPORT COUNTS ---
  [PASS] 1.1 Total reports count is integer > 0
  [PASS] 1.2 Sum of status breakdown equals total reports
  [PASS] 1.3 Sum of urgency breakdown equals total reports

--- 2. PROVENANCE SEPARATION ---
  [PASS] 2.1 Genuine operational reports total is tracked separately
  [PASS] 2.2 Test/demo reports total is tracked separately
  [PASS] 2.3 Sum of provenance categories equals total reports
  [PASS] 2.4 Provenance notice warns about data segregation

--- 3. UNKNOWN PROVENANCE CATEGORIZATION ---
  [PASS] 3.1 Unknown provenance category exists and is tracked
  [PASS] 3.2 Unknown provenance is not mixed into genuine operational total

--- 4. REPORT STATUS AGGREGATION ---
  [PASS] 4.1 Pending status count is present
  [PASS] 4.2 Verified status count is present
  [PASS] 4.3 Rejected status count is present
  [PASS] 4.4 Converted to Alert status count is present

--- 5. TEMPORAL AGGREGATION (24 HOURLY BINS) ---
  [PASS] 5.1 Exactly 24 diurnal hourly bins are generated (00 to 23)
  [PASS] 5.2 Hourly bin keys are properly padded 2-character strings ('00' through '23')
  [PASS] 5.3 Diurnal time windows tracked (dawn, morning, afternoon, dusk, night, unrecorded)

--- 6. MISSING OBSERVATION DATES ---
  [PASS] 6.1 Unrecorded dates count is positive in historical dataset
  [PASS] 6.2 Data quality audit correctly computes missing dates ratio

--- 7. UNKNOWN OBSERVATION TIMES (NO CREATED_AT SUBSTITUTION) ---
  [PASS] 7.1 Unrecorded times count matches unrecorded observation count
  [PASS] 7.2 Precision breakdown tracks 'unknown' precision count
  [PASS] 7.3 Scarcity warnings explicitly note exclusion of created_at

--- 8. MONTHLY AGGREGATION & LONGITUDINAL SPREAD ---
  [PASS] 8.1 Monthly distribution array is populated where dates exist
  [PASS] 8.2 Warning emitted if longitudinal spread is insufficient (<= 1 month)

--- 9. RAILWAY-SECTION AGGREGATION ---
  [PASS] 9.1 All registered railway sections returned
  [PASS] 9.2 Section record contains ID, name, district, risk level, and report tallies
  [PASS] 9.3 Section governance notice prohibits automatic geometric reassignment

--- 10. HOTSPOT INVENTORY (CONFIGURED VS EMPIRICAL) ---
  [PASS] 10.1 Exactly 4 configured hotspots returned
  [PASS] 10.2 All hotspots classified as 'manually_configured'
  [PASS] 10.3 Hotspot inventory includes section association and justification reason

--- 11. DUPLICATE CANDIDATE STATISTICS ---
  [PASS] 11.1 Stored duplicate flags count is non-negative integer
  [PASS] 11.2 Confirmed duplicates count is strictly 0 without human review
  [PASS] 11.3 Confirmation policy explicitly forbids automated duplicate merging
  [PASS] 11.4 Pairwise evaluation partitions candidates into strong, possible, and spatial-only

--- 12. REPORTING DELAY CALCULATIONS ---
  [PASS] 12.1 Reporting delay payload structures exact, approximate, and uncomputable timings
  [PASS] 12.2 Uncomputable timing count tracks reports lacking observation timing
  [PASS] 12.3 Delay calculation notice asserts created_at is never assumed to equal sighting time

--- 13. GPS COMPLETENESS TELEMETRY ---
  [PASS] 13.1 Missing GPS count is non-negative integer
  [PASS] 13.2 GPS accuracy completeness ratio is within [0.0, 1.0]
  [PASS] 13.3 Overall data completeness percentage is calculated [0, 100]

--- 14. INVALID COORDINATES DETECTION ---
  [PASS] 14.1 Invalid coordinates count is integer >= 0
  [PASS] 14.2 Potential railway section assignment discrepancies evaluated

--- 15. AI ADVISORY AGGREGATION ---
  [PASS] 15.1 AI subsystem status breakdown tracks processed, skipped, pending, failed
  [PASS] 15.2 Human officer decision matrix tracks agreement and pending reviews
  [PASS] 15.3 AI governance notice states output is advisory-only and not certified model accuracy

--- 16. ROLE-BASED ACCESS CONTROL ---
  [PASS] 16.1 Wildlife Officer is authorized role
  [PASS] 16.2 Driver is not in authorized roles list
  [PASS] 16.3 Reporter is not in authorized roles list

--- 17. COORDINATE PRIVACY ---
  [PASS] 17.1 Authorized Wildlife Officer receives exact unmasked coordinates
  [PASS] 17.2 Restricted Driver receives fuzzed coordinates (rounded to 2 decimal places)
  [PASS] 17.3 Restricted Reporter receives fuzzed coordinates
  [PASS] 17.4 Unauthenticated public viewer receives fuzzed coordinates
  [PASS] 17.5 Masking notice attached to unauthorized output

--- 18. SQL READ-ONLY ENFORCEMENT ---
  [PASS] 18.1 executeReadOnlyQuery strictly blocks INSERT statements
  [PASS] 18.2 executeReadOnlyQuery strictly blocks UPDATE statements
  [PASS] 18.3 executeReadOnlyQuery strictly blocks DELETE statements
  [PASS] 18.4 executeReadOnlyQuery strictly blocks DROP statements

--- 19. OPERATIONAL DATABASE IMMUTABILITY ---
  [PASS] 19.1 getFullAnalyticsSummary aggregates all 7 analytical domains cleanly
  [PASS] 19.2 Operational DB elephant_reports remains 43
  [PASS] 19.3 Operational DB system_logs remains 185
  [PASS] 19.4 Operational DB alerts remains 15
  [PASS] 19.5 Operational Report #1 sighting time remains NULL
  [PASS] 19.6 Operational Report #1 risk score remains Medium (identical to baseline)
  [PASS] 19.7 Operational Report #1 status remains Pending

--- 20. EMPTY DATASETS GRACEFUL HANDLING ---
  [PASS] 20.1 Empty dataset general stats returns total_reports = 0 without divide-by-zero
  [PASS] 20.2 Empty temporal stats generates 24 zero-count hourly bins
  [PASS] 20.3 Empty section analytics returns total_sections = 0 cleanly
  [PASS] 20.4 Empty hotspot analytics returns total_hotspots = 0 cleanly
  [PASS] 20.5 Empty data quality analytics returns 0% completeness without error

========================================================================
STAGE 4C READ-ONLY HISTORICAL ANALYTICS TEST RESULTS
Total Tests:  73
Passed Tests: 73
Failed Tests: 0
========================================================================
```

### 4.2 Full Platform Regression Results
All milestone test suites across Phase 8 and Phase 9 were executed and verified at 100% pass rate:
- `test_milestone_9c_stage4c_analytics_service.php`: 73 / 73 PASSED
- `test_milestone_9c_stage4b_duplicate_service.php`: 95 / 95 PASSED
- `test_milestone_9c_stage3e_model_evaluation.php`: 47 / 47 PASSED
- `test_milestone_9c_stage3d_dashboard_integration.php`: 38 / 38 PASSED
- `test_milestone_9c_stage3c_temporal_risk.php`: 32 / 32 PASSED
- `test_milestone_9c_stage2b_frontend_integration.php`: 62 / 62 PASSED
- `test_milestone_9c_stage2a_temporal_service.php`: 49 / 49 PASSED
- `test_milestone_9c_stage1_foundation.php`: 29 / 29 PASSED
- `test_milestone_9b_analytics.php`: 23 / 23 PASSED
- `test_ai_integration.php`: 25 / 25 PASSED

---

## 5. Operational Database Invariant Verification

Operational database `eleguard_rail` on port 3308 was audited post-execution:
- `elephant_reports`: Exactly 43 records
- `system_logs`: Exactly 185 records
- `alerts`: Exactly 15 records
- Historical Report #1: `sighting_date = NULL`, `sighting_time = NULL`, `risk_score = Medium`, `report_status = Pending`, `updated_at = NULL`.

Zero mutations occurred against the operational database.
