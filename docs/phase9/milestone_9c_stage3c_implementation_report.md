# EleGuard Rail — Milestone 9C Stage 3C Implementation Report
**Sri Lanka Wildlife-Railway Collision Early Warning Platform**  
*Document Version:* 1.0.0  
*Implementation Date:* 2026-11-10  
*Baseline Git Commit:* `3d5b024`  
*Branch:* `main`  
*Operational Database:* `eleguard_rail` (MariaDB port 3308)  
*Status:* CONTROLLED IMPLEMENTATION COMPLETE (Zero Automated Intake/Alert Activation)  

---

## Executive Summary

**Milestone 9C Stage 3C** successfully implements a backward-compatible, observation-aware temporal risk calculation capability for the EleGuard Rail platform.

In strict adherence to project safety directives, **this stage does NOT automatically activate new operational risk scoring or alter live alert generation**. All operational report submissions through `ReportService.php` continue utilizing the legacy 5-argument evaluation contract, ensuring zero impact on active locomotive driver caution orders, control dashboards, or stored intake scores.

The newly deployed component, `app/services/TemporalRiskService.php`, alongside the expanded API `RiskService::calculateObservationAwareRisk()`, provides mathematically continuous diurnal illumination integration across dawn and dusk twilight ramps, cyclic midnight boundary crossing handling, strict zero diurnal adjustment for unknown observation times, and an opt-in experimental temporal decay capability protected by a section baseline risk floor.

---

## 1. Design Reconciliation & Approved Policy Decisions

Prior to implementation, all Stage 3B design proposals were reconciled against the authorized boundaries:

| # | Approved Policy Decision | Implementation Mechanism | Status |
|:---:|:---|:---|:---:|
| **1** | Unknown observation time produces zero diurnal adjustment. | Evaluated strictly to $\bar{D} = 0.00$ in `TemporalRiskService::evaluateDiurnalAdjustment()`. No night bonus assumed. | **RESOLVED & ENFORCED** |
| **2** | Existing five-argument `RiskService` callers retain current behavior. | Legacy `RiskService::calculateRiskScore($sectionRisk, $urgencyLevel, $elephantCount, $reportTime, $activeAlertsInSection)` preserved 100% intact. | **RESOLVED & ENFORCED** |
| **3** | New observation-aware calculations use an explicit API. | Implemented as `RiskService::calculateObservationAwareRisk($reportContext, $temporalMetadata, $options)`. | **RESOLVED & ENFORCED** |
| **4** | Experimental temporal decay is disabled by default. | Enforced via `['enable_decay' => false]` default option. Multiplier defaults to $1.00$ with $0.0$ points decayed. | **RESOLVED & ENFORCED** |
| **5** | Existing risk category thresholds remain unchanged. | Thresholds strictly maintained: $\text{Critical} \ge 11, \text{High } 8-10, \text{Medium } 5-7, \text{Low } \le 4$. | **RESOLVED & ENFORCED** |
| **6** | Existing stored intake risk scores remain unchanged. | Zero database schema mutations, zero update queries, zero alterations to `elephant_reports.risk_score`. | **RESOLVED & ENFORCED** |
| **7** | AI remains strictly advisory-only. | Positive AI detection confidence NEVER modifies calculated risk scores. | **RESOLVED & ENFORCED** |
| **8** | No seasonal multipliers. | Monsoonal/seasonal climate adjustments excluded and deferred. | **RESOLVED & ENFORCED** |
| **9** | No automated changes to railway alerts. | `AlertService::createAlert()` and driver alert notifications remain completely untouched. | **RESOLVED & ENFORCED** |

---

## 2. Architecture & File Inventory

```
                         ┌───────────────────────────────────────┐
                         │          ReportService.php            │
                         └──────────────────┬────────────────────┘
                                            │
                 Calls legacy 5-arg API     │   Preserves operational intake
                 (Default in Stage 3C)      ▼   (Zero live behavior change)
                                ┌────────────────────────┐
                                │    RiskService.php     │
                                └───────────┬────────────┘
                                            │
                 Calls new explicit API     │   Controlled validation & future
                 (calculateObservationAware)│   dashboard explainability
                                            ▼
                                ┌────────────────────────┐
                                │ TemporalRiskService.php│
                                └────────────────────────┘
```

### 2.1 File Inventory
1. **`app/services/TemporalRiskService.php` (New File):**  
   Autonomous calculation engine for continuous diurnal illumination $D(t)$, analytical interval integration, cyclic midnight crossing, and experimental exponential decay.
2. **`app/services/RiskService.php` (Modified File):**  
   Preserves legacy `calculateRiskScore()` while introducing `calculateObservationAwareRisk()` returning full explainability dictionaries.
3. **`tests/test_milestone_9c_stage3c_temporal_risk.php` (New Test Suite):**  
   32-assertion isolated automated test suite covering all diurnal regimes, boundaries, unknowns, decay limits, and invariants.

---

## 3. Mathematical Formula Implementation

### 3.1 Continuous Diurnal Illumination Function $D(t)$
Modeled in `Asia/Colombo` standard time ($t \in [0.0, 24.0)$ hours):

$$D(t) = \begin{cases}
2.0 & \text{if } t \in [\text{19:00}, \text{05:00}] \quad \text{(Full Night: extreme stopping distance hazard)} \\
2.0 \times \left(1 - \frac{t - \text{05:00}}{\text{2.0}}\right) & \text{if } t \in [\text{05:00}, \text{07:00}] \quad \text{(Dawn Transition: visual daylight expansion)} \\
0.0 & \text{if } t \in [\text{07:00}, \text{17:00}] \quad \text{(Full Daytime: visual sightline uninhibited)} \\
2.0 \times \left(\frac{t - \text{17:00}}{\text{2.0}}\right) & \text{if } t \in [\text{17:00}, \text{19:00}] \quad \text{(Dusk Transition: rapid darkness onset)}
\end{cases}$$

### 3.2 Analytical Interval Integration
For approximate observation intervals $[t_a, t_b]$ (e.g. presets or custom windows), diurnal points are calculated via exact trapezoidal integration across piecewise linear break nodes:

$$\bar{D} = \frac{1}{t_b - t_a} \int_{t_a}^{t_b} D(t) \, dt$$

* **Dusk Preset (`17:30 – 19:00`):** $\bar{D} = \mathbf{1.25}$ points (unrounded).
* **Dawn Preset (`04:30 – 06:30`):** $\bar{D} = \mathbf{1.4375} \approx \mathbf{1.44}$ points.
* **Night Preset (`20:00 – 00:00`):** $\bar{D} = \mathbf{2.00}$ points.
* **Morning / Afternoon Presets:** $\bar{D} = \mathbf{0.00}$ points.
* **Unknown Observation Times:** Strictly $\bar{D} = \mathbf{0.00}$ points.

### 3.3 Experimental Decay Model & Floor Rule Protection
When explicitly enabled via options (`enable_decay = true`):

$$\Lambda(\Delta t) = 2^{-\frac{\Delta t}{T_{1/2}}}, \quad T_{1/2} = 4.0\text{ hours (default)}$$

* **Floor Protection Rule:**
  $$S_{\text{sighting}} = S_{\text{urgency}} + S_{\text{count}} + \bar{D}$$
  $$S_{\text{decayed}} = S_{\text{sighting}} \times \Lambda(\Delta t)$$
  $$S_{\text{total}} = S_{\text{section}} + S_{\text{alert}} + S_{\text{decayed}}$$
  * Decay only attenuates the sighting bonus; it **never** reduces below $S_{\text{section}} + S_{\text{alert}}$.

---

## 4. API Contract & Explainability Payload

### Method Signature
```php
public static function calculateObservationAwareRisk(
    array $reportContext, 
    array $temporalMetadata = [], 
    array $options = []
): array
```

### Response Schema
```json
{
  "base_section_points": 3,
  "urgency_points": 3,
  "elephant_count_points": 2,
  "active_alert_points": 0,
  "diurnal_adjustment": 1.25,
  "observation_precision": "approximate",
  "observation_interval": {
    "start": "17:30",
    "end": "19:00",
    "window": "dusk",
    "uncertainty_minutes": 45
  },
  "data_quality_warnings": [],
  "unrounded_total_score": 9.25,
  "final_score": 9,
  "final_classification": "High",
  "model_version": "1.0.0-experimental",
  "experimental_assumptions": {
    "diurnal_ramp": "Dawn (05:00-07:00) and Dusk (17:00-19:00) transitions are experimental continuous engineering approximations.",
    "temporal_decay": "Temporal decay disabled by default; report threat is unattenuated by elapsed time."
  },
  "decay_details": {
    "enabled": false,
    "multiplier": 1.0,
    "elapsed_minutes": null,
    "points_decayed": 0.0,
    "explanation": "Temporal decay disabled by default in Stage 3C."
  }
}
```

---

## 5. Automated Regression Test Execution Results

All automated test suites were executed sequentially against isolated test databases (`eleguard_rail_test`):

| Test Suite | Focus Area | Assertions | Result | Status |
|:---|:---|:---:|:---:|:---:|
| `test_milestone_9c_stage3c_temporal_risk.php` | Diurnal ramps, interval integration, midnight crossing, unknown time zero policy, decay safety, legacy parity | 32 | 32 Passed, 0 Failed | **PASS** |
| `test_milestone_9c_stage2b_frontend_integration.php` | Frontend forms, Report ID resolution, delay boundaries, chronology consistency | 62 | 62 Passed, 0 Failed | **PASS** |
| `test_milestone_9c_stage2a_temporal_service.php` | Temporal validation, presets, GPS timestamps, lag calculations | 49 | 49 Passed, 0 Failed | **PASS** |
| `test_milestone_9c_stage1_foundation.php` | Schema columns, temporal indexes, rollback, Report #1 integrity | 29 | 29 Passed, 0 Failed | **PASS** |
| `test_milestone_9b_analytics.php` | Geodesic math, hotspot proximity, track centerline projection | 23 | 23 Passed, 0 Failed | **PASS** |
| `test_ai_integration.php` | YOLOv8 advisory detection, 3-tier classification, officer governance | 25 | 25 Passed, 0 Failed | **PASS** |
| **CUMULATIVE VERIFICATION TOTAL** | | **220** | **220 Passed, 0 Failed** | **100% PASS** |

---

## 6. Operational Safety & Forensic Invariants

Direct forensic verification against operational database `eleguard_rail` on MariaDB port 3308:

```sql
SELECT COUNT(*) AS rep_count FROM eleguard_rail.elephant_reports;
-- Result: 43 (0 writes)

SELECT COUNT(*) AS log_count FROM eleguard_rail.system_logs;
-- Result: 185 (0 writes)

SELECT COUNT(*) AS alert_count FROM eleguard_rail.alerts;
-- Result: 15 (0 writes)

SELECT id, report_status, sighting_date, sighting_time, sighting_time_precision, created_at, updated_at 
FROM eleguard_rail.elephant_reports WHERE id = 1\G
```

**Forensic Output:**
```
rep_count: 43
log_count: 185
alert_count: 15

*************************** 1. row ***************************
                     id: 1
          report_status: Pending
          sighting_date: NULL
          sighting_time: NULL
sighting_time_precision: unknown
             created_at: 2026-11-08 07:02:21
             updated_at: NULL
```

- **Row Count Invariance:** Zero records inserted, modified, or deleted in operational database.
- **Report #1 Integrity:** Historical baseline report remains strictly in `'Pending'` status with `NULL` temporal values.
- **Production Code Isolation:** `ReportService.php` continues calling the legacy 5-argument method. No operational behavior was altered.

---

## 7. XAMPP Synchronization Parity

Cryptographic SHA-256 validation between Desktop (`C:\Users\user\Desktop\Railway\eleguard-rail\`) and active XAMPP webroot (`C:\xampp\htdocs\eleguard-rail\`):

| File Path | Desktop SHA-256 | XAMPP SHA-256 | Parity |
|:---|:---:|:---:|:---:|
| `app/services/RiskService.php` | `C2519114B5264ABAD5533563C8D7DC61C260C829007B6A41D25407B4F73A7B43` | `C2519114B5264ABAD5533563C8D7DC61C260C829007B6A41D25407B4F73A7B43` | **MATCH** |
| `app/services/TemporalRiskService.php` | `ADB54D5443FDABC0A22DC35683C55342E761DBA0C5F041566FE482A61AD80B2A` | `ADB54D5443FDABC0A22DC35683C55342E761DBA0C5F041566FE482A61AD80B2A` | **MATCH** |
| `tests/test_milestone_9c_stage3c_temporal_risk.php` | `06B1E6E6F2795D46B1CF0DF3B8454E423DCBB056227CFE968454115098FF238B` | `06B1E6E6F2795D46B1CF0DF3B8454E423DCBB056227CFE968454115098FF238B` | **MATCH** |
| `docs/phase9/milestone_9c_stage3c_implementation_report.md` | Synchronized | Synchronized | **MATCH** |

---

## 8. Git Commit & Repository State

* **Prior Baseline Commit:** `3d5b024`
* **New Commit:** `b17a159`
* **Commit Message:** `feat(phase9): implement controlled observation-aware temporal risk calculation engine`
* **Branch:** `main`
* **Working Tree:** Clean (0 uncommitted files)

---

## Explicit Milestone Boundary Statement

> [!IMPORTANT]
> **Milestone 9C Stage 3C is complete and verified.**
> In strict accordance with directives:
> - The new temporal risk model is NOT automatically activated in `ReportService.php`.
> - No UI dashboard modifications have been introduced.
> - **Stage 3D (Dashboard Integration & Controlled Activation)** has NOT begun.
> - Execution has halted to await explicit user authorization.
