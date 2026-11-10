# EleGuard Rail — Milestone 9C Stage 3D Technical Report
## Dashboard Integration & Shadow Risk Assessment

**Project:** EleGuard Rail (Sri Lanka Wildlife-Railway Collision Early Warning Platform)  
**Baseline Commit:** `6750222`  
**Milestone:** 9C — Stage 3D  
**Environment:** PHP 8.2 / MariaDB 10.4 (Port 3308) / Apache (XAMPP)  
**Date:** 10 November 2026  
**Status:** COMPLETE & VERIFIED — READY FOR AUDIT  

---

### 1. Executive Summary & Authorization Scope

Under Milestone 9C Stage 3D, the observation-aware temporal and diurnal risk calculation engine developed in Stage 3C (`RiskService::calculateObservationAwareRisk()`) was integrated into the operational user interfaces in **SHADOW MODE ONLY**.

#### Strict Operational Safeguards Enforced:
1. **Shadow Mode Isolation:** The observation-aware temporal risk score is displayed exclusively for analytical comparison and reviewer evaluation. It carries a prominent visual warning:  
   `"EXPERIMENTAL — NOT USED FOR OPERATIONAL ALERTING"`.
2. **Zero Modification to Active Alerts:** All operational railway warning alerts, driver dashboard broadcasts (`driver/dashboard.php`), and intake risk scores stored in `elephant_reports.risk_score` remain 100% governed by the existing operational baseline.
3. **Alert Conversion Form Preserved:** The Control Officer alert broadcast dropdown (`public/control/reports.php`) strictly retains the verified operational risk score (`$selectedReport['risk_score']`) as its default value.
4. **Historical & Unmigrated Robustness:** Reports with unknown observation times or legacy records with `NULL` temporal columns (e.g. Report #1) evaluate gracefully with `0.0` diurnal adjustment points and appropriate data quality notices without exceptions or fatal errors.
5. **Zero Operational Database Modification:** The production database `eleguard_rail` remained completely untouched across all integration and testing activities (`elephant_reports = 43`, `system_logs = 185`, `alerts = 15`, Report #1 pristine).

---

### 2. Dashboard Integration Architecture

#### 2.1 Interface Touchpoints
The dual assessment panel was deployed to two critical human-in-the-loop review screens:
1. **Railway Control Desk Sighting Review (`public/control/reports.php`):**
   - The primary operational screen where Control Officers review elephant sightings and broadcast live collision warnings to train drivers.
   - Enriched with field observation time, uncertainty interval, and reporting delay badges.
   - Houses the full **Dual Risk Assessment & Shadow Mode Panel** immediately beneath the sighting details and alert action form.
2. **Wildlife Officer Verification Panel (`public/wildlife/reports.php`):**
   - The operational area where wildlife rangers verify community field reports.
   - Displays the **Dual Risk Assessment & Shadow Mode Panel** alongside the AI Vision Sighting Advisory card, giving wildlife officers complete ecological context.

#### 2.2 Dual Assessment Side-by-Side Card Layout
```
+----------------------------------------------------------------------------------------------------+
| 🛡️ Risk Assessment Comparison (Operational Baseline vs Shadow Model)                                |
| [SHADOW MODE NOTICE: Running for analytical evaluation. Does not alter operational alerts]         |
+-------------------------------------------------+--------------------------------------------------+
| Card A: Operational Risk (Active)               | Card B: Observation-Aware Risk (Shadow Mode)     |
| [ACTIVE CONTROLLER]                             | [EXPERIMENTAL — NOT USED FOR OPERATIONAL ALERTING]|
|                                                 |                                                  |
| Score: HIGH                                     | Score: HIGH (9.25 pts, Rounded: 9 / 13)          |
| Source: Intake Calculation (ReportService)      | Diurnal Factor: +1.25 pts (Twilight Transition)  |
| Section Risk: Medium                            | Observation Window: 17:30 – 19:00 SLST (±45m)    |
| Urgency: High                                   | Precision: Approximate (Dusk Preset)             |
| Sighting Timestamp: Ingestion Time SLST         | Breakdown: Sec=2 | Urg=3 | Count=2 | Diurnal=+1.25|
|                                                 | Engine: v1.0.0-experimental (Shadow Assessment)  |
| [✔ Active standard. Alert dropdown defaults]    | [⚠️ Informational telemetry only. No alert changes]|
+-------------------------------------------------+--------------------------------------------------+
```

---

### 3. File Modifications & Implementation Details

#### 3.1 `app/services/RiskService.php`
- **Context Normalization:** Line 146 was updated to inspect `$reportContext['section_risk_level']` in addition to `section_risk` and `risk_level`, ensuring seamless integration when callers pass database rows directly from `ReportService::getById()`.
- **Contract Integrity:** The legacy 5-argument `calculateRiskScore()` remains untouched and backward-compatible.

#### 3.2 `app/services/TemporalRiskService.php`
- **Unknown Time Policy:** Enhanced `evaluateDiurnalAdjustment()` to consistently attach a data quality notice whenever an observation has unknown precision or missing time (`"Unknown observation time; zero diurnal adjustment applied (neutral daylight baseline)."`), ensuring transparent feedback to reviewers.

#### 3.3 `public/control/reports.php`
- **Service Inclusions:** Added `TemporalService.php` and `RiskService.php`.
- **Dynamic Context Gathering:** Evaluates active alerts in the section to feed `calculateObservationAwareRisk()` with accurate situational context.
- **Reporting Delay & Observation Display:** Enriched the sighting meta-summary table with observation time formatting and interval reporting delays.
- **Dual Assessment Panel:** Rendered the side-by-side comparison cards (Card A vs Card B) with explicit experimental disclaimers and breakdown metrics.
- **Alert Broadcast Form Protection:** Maintained `<option value="..." <?php echo $selectedReport['risk_score'] === ... ? 'selected' : ''; ?>>` ensuring the operational risk score continues to default into the alert conversion workflow.

#### 3.4 `public/wildlife/reports.php`
- **Service Inclusion:** Added `RiskService.php`.
- **Shadow Evaluation:** Dynamically computes `$shadowRisk` for the selected report.
- **Dual Assessment Panel:** Rendered the matching side-by-side comparison panel below the AI Sighting Advisory card.

---

### 4. Verification & Testing

#### 4.1 Dedicated Stage 3D Automated Test Suite (`tests/test_milestone_9c_stage3d_dashboard_integration.php`)
A comprehensive, isolated automated test suite comprising **38 assertions across 7 groups** was created and executed against an isolated clone database (`eleguard_rail_test`):

| Test Group | Assertions | Result | Notes |
|---|:---:|:---:|---|
| **Group 1: Dual Risk Assessment & Shadow Calculation Logic** | 10 | PASS | Exact night (+2.0 pts), day (0.0 pts), dusk (+1.0 pt), unrounded totals & rounded classifications |
| **Group 2: Unknown Observation Time & Legacy Report Robustness** | 6 | PASS | Zero diurnal adjustment, data quality warnings, legacy Report #1 evaluated safely |
| **Group 3: Control Officer Dashboard Review & Form Safety** | 7 | PASS | Dual cards rendered, EXPERIMENTAL banner displayed, alert dropdown defaults to operational score |
| **Group 4: Wildlife Officer Review Screen Shadow Assessment** | 4 | PASS | Dual cards rendered, AI advisory panel preserved, experimental badges intact |
| **Group 5: Operational Alerting Isolation & Driver Non-Interference** | 5 | PASS | Alert creation uses operational score; driver active alerts unaffected; intake unaffected |
| **Group 6: Role-Based Access Control (RBAC) Integrity** | 2 | PASS | RBAC restrictions strictly enforced (`control_officer`, `wildlife_officer`) |
| **Group 7: Zero Operational Database Writes & Invariant Verification** | 4 | PASS | Operational row counts verified before and after; Report #1 pristine |
| **Total** | **38** | **PASS (100%)** | |

#### 4.2 Comprehensive Regression Suite Results
All existing regression test suites were re-executed and passed with zero regressions:
1. `tests/test_milestone_9c_stage3d_dashboard_integration.php`: **38 / 38 passed**
2. `tests/test_milestone_9c_stage3c_temporal_risk.php`: **32 / 32 passed**
3. `tests/test_milestone_9c_stage2b_frontend_integration.php`: **62 / 62 passed**
4. `tests/test_milestone_9c_stage2a_temporal_service.php`: **49 / 49 passed**
5. `tests/test_milestone_9c_stage1_foundation.php`: **29 / 29 passed**
6. `tests/test_milestone_9b_analytics.php`: **23 / 23 passed**
7. **Total Assertions Across Test Suites:** **233 / 233 PASSED (100%)**

---

### 5. Operational Database Invariant Verification

Before and after all test executions, the operational database (`eleguard_rail` on port 3308) was inspected:

```sql
SELECT 'elephant_reports' as tbl, count(*) as cnt FROM elephant_reports
UNION ALL SELECT 'system_logs', count(*) FROM system_logs
UNION ALL SELECT 'alerts', count(*) FROM alerts;
```

**Results:**
- `elephant_reports`: **43** (0 writes)
- `system_logs`: **185** (0 writes)
- `alerts`: **15** (0 writes)
- `Report #1`: Status = `'Pending'`, `sighting_* = NULL`, `updated_at = NULL` (100% pristine).

---

### 6. Parity Verification (Desktop Workspace vs XAMPP Webroot)

All modified and newly created files were synchronized from `C:\Users\user\Desktop\Railway\eleguard-rail` to `C:\xampp\htdocs\eleguard-rail` and verified with cryptographic SHA-256 hashes:

| File Path | Desktop SHA-256 | XAMPP SHA-256 | Parity |
|---|---|---|:---:|
| `app/services/RiskService.php` | `f0bb8e4...` | `f0bb8e4...` | MATCH |
| `app/services/TemporalRiskService.php` | `61e5365...` | `61e5365...` | MATCH |
| `public/control/reports.php` | `464fd07...` | `464fd07...` | MATCH |
| `public/wildlife/reports.php` | `9ef87b0...` | `9ef87b0...` | MATCH |
| `tests/test_milestone_9c_stage3d_dashboard_integration.php` | `05b6fa1...` | `05b6fa1...` | MATCH |

---

### 7. Conclusion & Next Steps

Milestone 9C Stage 3D is fully implemented, verified, and stabilized. The observation-aware temporal risk calculation is safely deployed in shadow mode, providing reviewers with deep ecological insights into diurnal movement hazards without posing any risk of unvetted alert triggering or operational disturbance.

**Ready for user review and audit. No further stages will be initiated without explicit authorization.**
