# EleGuard Rail — Milestone 9C Stage 3B: Temporal Risk Model Design and Validation
**Sri Lanka Wildlife-Railway Collision Early Warning Platform**  
*Document Version:* 1.0.0  
*Design Date:* 2026-11-10  
*Baseline Git Commit:* `3d5b024`  
*Branch:* `main`  
*Status:* DESIGN & VALIDATION SPECIFICATION (DESIGN ONLY — NO PRODUCTION CHANGES)  

---

## Executive Summary

Milestone 9C Stage 3A established that the legacy risk engine in `RiskService.php` suffers from two critical flaws:
1. **Ingestion-Time Bias:** The night bonus ($+2$) is awarded based on system submission timestamp (`date('Y-m-d H:i:s')`), completely detached from the actual time the elephant was sighted.
2. **Absence of Temporal Decay:** A stale observation filed 72 hours late receives the exact same maximum threat rating as an observation filed 5 minutes ago.

**Stage 3B** designs a scientifically defensible, backward-compatible temporal and diurnal risk model. It incorporates actual elephant sighting observation times, models diurnal visibility hazards continuously across twilight transitions, introduces an interval-aware temporal decay mechanism that protects railway section baseline risk, and replaces arbitrary confidence discounting with transparent interval integration.

---

## 1. Existing Risk Formula Baseline & Invariants

The existing formula in `RiskService::calculateRiskScore()` remains the baseline for all threat computations:

$$S = S_{\text{section}} + S_{\text{urgency}} + S_{\text{count}} + S_{\text{night}} + S_{\text{alert}}$$

### 1.1 Invariant Risk Categories & Thresholds
The discrete threat categories, integer thresholds, and database enums remain strictly invariant:

$$\text{Category}(S) = \begin{cases} 
\text{'Critical'} & \text{if } S \ge 11 \\
\text{'High'} & \text{if } 8 \le S \le 10 \\
\text{'Medium'} & \text{if } 5 \le S \le 7 \\
\text{'Low'} & \text{if } S \le 4 
\end{cases}$$

### 1.2 Baseline Score Components
* **Section Risk ($S_{\text{section}}$):** Derived from `railway_sections.risk_level`  
  $\text{Critical} = 4, \quad \text{High} = 3, \quad \text{Medium} = 2, \quad \text{Low} = 1$.
* **Urgency Level ($S_{\text{urgency}}$):** Derived from user input  
  $\text{Critical} = 4, \quad \text{High} = 3, \quad \text{Medium} = 2, \quad \text{Low} = 1$.
* **Elephant Count ($S_{\text{count}}$):** Herd size bonus  
  $\ge 5 \implies +3, \quad 2\text{ to }4 \implies +2, \quad 1 \implies +1, \quad 0 \implies 0$.
* **Active Alert Bonus ($S_{\text{alert}}$):** Section concurrency  
  Active alert in section $\implies +2, \quad \text{None} \implies 0$.
* **Diurnal Bonus ($S_{\text{diurnal}}$):** Replaces legacy binary $S_{\text{night}}$ ($\in [0, 2]$).

---

## 2. Diurnal Risk Model Design

### 2.1 Decoupling Visibility Hazard from Biological Probability
In railway collision prevention, diurnal risk encompasses two distinct ecological and operational phenomena that must not be conflated:

1. **Locomotive Braking Sightline Hazard (Visibility):**  
   A typical Sri Lankan passenger or oil train operating at 50–70 km/h requires 400 to 800 metres of stopping distance. Locomotive headlamps provide roughly 100–150 metres of visibility on straight tracks and less than 50 metres around curves in dense dry-zone jungle (e.g. Gal Oya, Habarana, Minneriya). At night, stopping distance drastically exceeds visual sightline, converting any track intrusion into an unavoidable collision.
2. **Biological Elephant Activity (Elephant Behavior):**  
   Asian elephants (*Elephas maximus maximus*) are predominantly crepuscular and nocturnal foragers. Activity peaks sharply during dusk (17:30–20:00) and pre-dawn (04:30–06:30) as herds traverse railway corridors between forest reserves and agricultural lands. Mid-day activity (10:00–15:00) drops significantly as elephants seek canopy thermal refuge.

Dusk (17:00–19:00) represents the critical convergence: **maximum biological crossing probability combined with rapidly deteriorating locomotive sightlines.**

---

### 2.2 Continuous Diurnal Illumination Function $D(t)$

Sri Lanka lies between $6^\circ\text{N}$ and $10^\circ\text{N}$ latitude. Sunrise and sunset vary by less than 35 minutes across the entire year (sunrise ~05:45–06:15 SLST, sunset ~18:00–18:30 SLST). Civil twilight spans approximately 20–25 minutes.

To eliminate the artificial step-function jump at 18:00 (where risk formerly jumped from $+0$ to $+2$ in 60 seconds), diurnal illumination is modeled as a continuous piecewise linear curve across the 24-hour cycle ($t \in [0, 24)$ hours SLST):

$$D(t) = \begin{cases}
2.0 & \text{if } t \in [\text{19:00}, \text{05:00}] \quad \text{(Full Night: maximum visual hazard)} \\
2.0 \times \left(1 - \frac{t - \text{05:00}}{\text{2.0}}\right) & \text{if } t \in [\text{05:00}, \text{07:00}] \quad \text{(Dawn Transition: visual daylight expansion)} \\
0.0 & \text{if } t \in [\text{07:00}, \text{17:00}] \quad \text{(Daylight: maximum braking sightline)} \\
2.0 \times \left(\frac{t - \text{17:00}}{\text{2.0}}\right) & \text{if } t \in [\text{17:00}, \text{19:00}] \quad \text{(Dusk Transition: rapid darkness onset)}
\end{cases}$$

```
 Diurnal Bonus (D)
   2.0 ┌────────────┐                                       ┌────────────
       │ Full Night │\                                     /│ Full Night 
   1.5 │            │ \                                   / │            
   1.0 │            │  \ Dawn Transition  Dusk Transition/  │            
   0.5 │            │   \                               /   │            
   0.0 └────────────┴────┴─────────────────────────────┴────┴────────────
      00:00       05:00 07:00                        17:00 19:00       24:00
                                 Daytime (0.0)
```

### 2.3 Evaluation Across Approximate Observation Intervals $[t_a, t_b]$
When an observation is reported with an approximate diurnal window or uncertainty interval $[t_a, t_b]$ (e.g., Dusk $17:30 - 19:00$), the expected diurnal adjustment $\bar{D}$ is the exact continuous integral mean:

$$\bar{D} = \frac{1}{t_b - t_a} \int_{t_a}^{t_b} D(t) \, dt$$

#### Analytical Solutions for Standard Diurnal Presets:
1. **Morning Preset (`07:30 – 10:30`):**  
   Interval is entirely within $[07:00, 17:00]$ where $D(t) = 0.0$.  
   $$\bar{D} = 0.00$$
2. **Afternoon Preset (`13:00 – 16:00`):**  
   Interval is entirely within $[07:00, 17:00]$ where $D(t) = 0.0$.  
   $$\bar{D} = 0.00$$
3. **Dusk Preset (`17:30 – 19:00`, Duration = 1.5h):**  
   Spans part of the dusk ramp $[17:00, 19:00]$.  
   $$D(17:30) = 2.0 \times \frac{17.5 - 17.0}{2.0} = 0.50, \quad D(19:00) = 2.00$$  
   $$\bar{D} = \frac{0.50 + 2.00}{2} = \mathbf{1.25}$$
4. **Night Preset (`20:00 – 00:00`, Duration = 4.0h):**  
   Entirely within full night $[19:00, 05:00]$.  
   $$\bar{D} = \mathbf{2.00}$$
5. **Dawn Preset (`04:30 – 06:30`, Duration = 2.0h):**  
   Spans $[04:30, 05:00]$ (Full Night, $0.5\text{h}$ at $2.0$) and $[05:00, 06:30]$ (Dawn ramp, $1.5\text{h}$ from $2.0$ to $0.5$).  
   $$\int_{04:30}^{05:00} D(t) dt = 2.0 \times 0.5 = 1.0$$  
   $$\int_{05:00}^{06:30} D(t) dt = \frac{2.0 + 0.5}{2} \times 1.5 = 1.875$$  
   $$\bar{D} = \frac{1.0 + 1.875}{2.0} = \mathbf{1.4375} \approx \mathbf{1.44}$$

### 2.4 Midnight Boundary Crossing ($t_b < t_a$)
If an observation window crosses midnight (e.g., custom window `22:00 – 02:00`, where $t_a = 22.0$ and $t_b = 2.0$):
* Split the domain cyclically into two sub-intervals: $[t_a, 24.0]$ and $[0.0, t_b]$.
* Total duration: $(24.0 - t_a) + t_b$.
* Both sub-intervals fall completely within Full Night ($D(t) = 2.0$).
* Result: $\bar{D} = 2.00$ without cyclic wrapping errors or negative durations.

### 2.5 Strict Policy on Unknown Observation Times
> [!IMPORTANT]
> **Zero Diurnal Assumption Rule:**  
> When observation time is `unknown` (or both date and time are `NULL`):
> $$\bar{D} = 0.00$$
> - The system **NEVER** assumes night.
> - The system **NEVER** substitutes `created_at` or `updated_at`.
> - Sighting records with unknown times do not receive unearned threat points.

---

## 3. Temporal Decay Model Design

### 3.1 Mathematical Specification
Sighting threat diminishes as time elapses because elephants vacate track corridors. An exponential decay model represents the diminishing probability of elephant presence:

$$\Lambda(\Delta t) = \exp\left(-\ln(2) \cdot \frac{\Delta t}{T_{1/2}}\right) = 2^{-\frac{\Delta t}{T_{1/2}}}$$

* **Half-Life Parameter ($T_{1/2}$):** Baseline set to **$4.0\text{ hours}$**.
  > [!WARNING]
  > **STATUS: EXPERIMENTAL — REQUIRES VALIDATION.**  
  > $T_{1/2} = 4.0\text{ hours}$ is an operational engineering heuristic balancing real-time driver alert relevance against stale report fatigue. It is NOT scientifically established across all elephant behavioral contexts. Telemetry and GPS radio-collar studies from DWC are required to establish seasonal and corridor-specific residence half-lives.

### 3.2 Elapsed Duration ($\Delta t$) Determination
1. **Timestamp Reference:**  
   $\Delta t$ is strictly calculated from the **actual elephant observation timestamp** ($t_{\text{obs}}$), **NEVER** from system database insertion time (`created_at`).
2. **Evaluation Timestamp ($t_{\text{eval}}$):**  
   * For archival / intake scoring: $t_{\text{eval}} = t_{\text{created\_at}}$ (measures reporting latency).
   * For live dashboard operational threat: $t_{\text{eval}} = \text{NOW()}$ in `Asia/Colombo` (measures real-time track relevance).
3. **Approximate Observation Intervals $[t_a, t_b]$:**  
   To prevent premature threat deflation while an elephant might still be present:
   $$\Delta t = \begin{cases}
   0 & \text{if } t_{\text{eval}} \le t_b \quad \text{(Evaluation occurs during observation window)} \\
   t_{\text{eval}} - t_b & \text{if } t_{\text{eval}} > t_b \quad \text{(Time elapsed since window closed)}
   \end{cases}$$
   *Conservative Biological Principle:* Elapsed decay is measured from the **latest possible observation moment** ($t_b$).

### 3.3 Scope of Decay: Report Incremental Contribution vs Section Base Risk
> [!CAUTION]
> **Section Base Risk Floor Invariant:**  
> Temporal decay must **ONLY** decay the incremental sighting threat introduced by the report ($S_{\text{sighting}}$).  
> Temporal decay must **NEVER** reduce the railway section's baseline infrastructure risk ($S_{\text{section}}$).

Mathematical formulation:
$$S_{\text{sighting}} = S_{\text{urgency}} + S_{\text{count}} + \bar{D}$$
$$S_{\text{decayed}} = S_{\text{sighting}} \times \Lambda(\Delta t)$$
$$S_{\text{final}} = S_{\text{section}} + S_{\text{alert}} + \text{round}(S_{\text{decayed}})$$

* **Guaranteed Lower Bound:** $S_{\text{final}} \ge S_{\text{section}} + S_{\text{alert}}$.
* **Example:** In a `Critical` section ($S_{\text{section}} = 4$):
  Even if an elephant sighting report is 5 days old ($\Lambda \approx 0$), the section score remains $4$ (`Low` baseline for the section), and never deflates below $4$.

---

## 4. Evaluation of Uncertainty Handling

### 4.1 Critique of Proposed Function $W(\Delta)$
Stage 3A proposed a heuristic uncertainty discount:
$$W(\Delta) = \max\left(0.70, \, 1.0 - \frac{\Delta - 5}{180} \times 0.30\right)$$

#### Scientific Evaluation:
1. **Lack of Empirical Basis:** The linear slope $0.30/180$ and arbitrary floor $0.70$ have no ecological, physical, or probabilistic justification.
2. **Pseudo-Precision Hazard:** Multiplying risk points by $0.81$ or $0.93$ creates an illusion of precision that does not reflect real-world wildlife reporting accuracy.
3. **Dual Penalization:** Approximate presets already suffer uncertainty through time span dilution during diurnal integration. Adding an ad-hoc multiplier $W(\Delta)$ penalizes approximate observations twice.

### 4.2 Recommended Alternative: Interval Integration & Explicit Provenance Qualifiers
Instead of an arbitrary scalar multiplier $W(\Delta)$, uncertainty is handled via two mathematically defensible mechanisms:

1. **Natural Interval Integration:**  
   The diurnal adjustment $\bar{D} = \frac{1}{t_b - t_a} \int_{t_a}^{t_b} D(t) dt$ automatically reflects uncertainty. A wide window spanning daylight and dusk receives a diluted, representative fractional score naturally without ad-hoc multipliers.
2. **Discrete Confidence Provenance Badge:**  
   The risk engine emits an explicit qualitative confidence classification:
   * **High Precision:** Exact observation ($\Delta = 5\text{m}$).
   * **Approximate Confidence:** Diurnal preset or custom window ($\Delta \in [15, 180]\text{m}$).
   * **Unverified Timing:** Unknown observation time ($\Delta = \text{NULL}$).

---

## 5. Backward Compatibility & System Integrity

### 5.1 Preservation of Existing Callers
The existing method signature in `app/services/RiskService.php` is preserved intact:
```php
public static function calculateRiskScore(
    $sectionRisk, 
    $urgencyLevel, 
    $elephantCount, 
    $reportTime, 
    $activeAlertsInSection
): string
```
All legacy test suites and external callers continue to receive the identical 4-level categorical string (`Low`, `Medium`, `High`, `Critical`).

### 5.2 Enhanced Integration API
A new structured method is introduced for the modern temporal pipeline:
```php
public static function calculateEnhancedRisk(
    array $reportContext, 
    array $temporalMetadata, 
    ?string $evalTime = null
): array
```

### 5.3 Non-Negotiable Constraints
* **AI Advisory Governance:** Positive AI detection results (`ai_status = 'processed'`, `ai_detected_elephant = 1`) remain **strictly advisory**. AI confidence NEVER modifies the calculated risk score.
* **No Seasonal Multipliers:** Monsoonal / seasonal weather factors are excluded from Stage 3 and deferred to future milestones.
* **Historical Data Protection:** Operational database records in `eleguard_rail` remain 100% read-only throughout Stage 3.

---

## 6. Transparent Explainability Architecture

To ensure locomotive drivers and Railway Control Officers understand and trust automated threat classifications, the risk engine outputs a structured, human-readable breakdown dictionary:

```json
{
  "risk_category": "High",
  "numeric_score": 9,
  "base_score": 8,
  "components": {
    "section_risk": {
      "level": "High",
      "points": 3,
      "description": "Minneriya - Hingurakgoda corridor baseline infrastructure risk"
    },
    "urgency": {
      "level": "High",
      "points": 3,
      "description": "Reported urgent observation near tracks"
    },
    "elephant_count": {
      "count": 3,
      "points": 2,
      "description": "Small herd bonus (2-4 elephants)"
    },
    "active_alerts": {
      "present": false,
      "points": 0,
      "description": "No concurrent active alerts in section"
    }
  },
  "temporal_adjustment": {
    "precision": "approximate",
    "window": "dusk",
    "uncertainty_minutes": 45,
    "interval_slst": "17:30 – 19:00",
    "diurnal_factor": 1.25,
    "diurnal_points": 1,
    "explanation": "Dusk twilight observation (fading visibility sightline)"
  },
  "decay_adjustment": {
    "observation_time": "2026-11-10 18:15:00",
    "evaluation_time": "2026-11-10 19:15:00",
    "elapsed_minutes": 15,
    "decay_lambda": 0.957,
    "points_decayed": 0,
    "explanation": "Contemporaneous report (filed within 1h of window closure)"
  },
  "warnings": []
}
```

---

## 7. Comprehensive Testing Strategy (Stage 3C Test Matrix)

The following 16 test cases must be implemented in `tests/test_milestone_9c_stage3_temporal_risk.php`:

| ID | Scenario | Input Parameters | Expected Mathematical Output |
|:---:|:---|:---|:---|
| **T01** | Exact Daytime | `12:30:00`, $\Delta=5\text{m}$, Sec=Med(2), Urg=Low(1), Count=1(1) | $S = 2 + 1 + 1 + 0 = 4$ (`Low`) |
| **T02** | Exact Nighttime | `23:30:00`, $\Delta=5\text{m}$, Sec=Med(2), Urg=Low(1), Count=1(1) | $S = 2 + 1 + 1 + 2 = 6$ (`Medium`) |
| **T03** | Dawn Transition Exact | `06:00:00`, $\Delta=5\text{m}$, Sec=High(3), Urg=Med(2), Count=1(1) | $D(06:00) = 1.0$, $S = 3 + 2 + 1 + 1 = 7$ (`Medium`) |
| **T04** | Dusk Transition Exact | `18:00:00`, $\Delta=5\text{m}$, Sec=High(3), Urg=Med(2), Count=1(1) | $D(18:00) = 1.0$, $S = 3 + 2 + 1 + 1 = 7$ (`Medium`) |
| **T05** | Approximate Morning | Window `morning` (07:30–10:30), Sec=Crit(4), Urg=High(3), Count=5(3) | $\bar{D} = 0.0$, $S = 4 + 3 + 3 + 0 = 10$ (`High`) |
| **T06** | Approximate Night | Window `night` (20:00–00:00), Sec=Crit(4), Urg=High(3), Count=5(3) | $\bar{D} = 2.0$, $S = 4 + 3 + 3 + 2 = 12$ (`Critical`) |
| **T07** | Interval Crossing Dawn | Window `dawn` (04:30–06:30), $\Delta=60\text{m}$ | $\bar{D} = 1.44 \implies \text{round}(1.44) = 1$ point |
| **T08** | Interval Crossing Dusk | Window `dusk` (17:30–19:00), $\Delta=45\text{m}$ | $\bar{D} = 1.25 \implies \text{round}(1.25) = 1$ point |
| **T09** | Interval Crossing Midnight | Window `custom` (22:00–02:00), $\Delta=120\text{m}$ | $\bar{D} = 2.00 \implies 2$ points (no cyclic wrap crash) |
| **T10** | Unknown Time (Date Known) | Date valid, time `NULL`, precision `'unknown'` | $\bar{D} = 0.00$, warning added, zero night bonus |
| **T11** | Historical Record | Sighting date & time `NULL` (Report #1) | $\bar{D} = 0.00$, matches legacy spatial score safely |
| **T12** | Delayed Sighting Decay | Sighting 12h ago, Sec=Med(2), Urg=Crit(4), Count=5(3), Night(2) | $\Lambda(12\text{h}) = 0.125$, $S_{\text{sighting}} = 9 \to 1$, $S = 2 + 1 = 3$ (`Low`) |
| **T13** | Floor Rule Protection | Sighting 7 days ago in `Critical` section ($S_{\text{section}} = 4$) | $S_{\text{sighting}} \to 0$, $S_{\text{final}} = 4$ (never below section baseline) |
| **T14** | Chronology Inconsistency | Sighting in future relative to filing time | Inconsistency flagged, temporal bonus rejected |
| **T15** | Potential Duplicate Cluster | `is_potential_duplicate = 1` | Sighting evaluated, duplicate cluster warning in explainability |
| **T16** | Legacy Signature Parity | Original 5 positional parameters passed | Output exactly matches legacy baseline string |

---

## 8. Unresolved Policy Decisions Requiring Authorization

The following policy choices cannot be settled purely by software design and require explicit project stakeholder approval:

1. **Rounding Policy for Fractional Diurnal Points:**  
   * *Option A (Conservative Rail Safety):* Round fractional diurnal adjustments UP (`ceil`), e.g., Dusk $\bar{D} = 1.25 \to +2$ points. Maximizes safety margin.  
   * *Option B (Mathematical Standard):* Round to nearest integer (`round`), e.g., $\bar{D} = 1.25 \to +1$ point. Balances alert fatigue. *(Recommended)*
2. **Operational Half-Life Calibration ($T_{1/2}$):**  
   * Baseline recommendation is $4.0\text{ hours}$.  
   * Stakeholders must authorize whether dry-zone forest corridors require longer persistence ($6.0\text{ hours}$) or active agricultural tracks require shorter persistence ($2.5\text{ hours}$).
3. **Display Strategy in Control Dashboard:**  
   * *Option A:* Store only intake risk; compute live decayed risk dynamically in the browser. *(Recommended)*  
   * *Option B:* Periodically update stored database risk scores via a cron job. *(Rejected: violates historical report immutability).*

---

## 9. Final Verification Invariant

* **Git HEAD:** `3d5b024`
* **Production Files Modified:** 0
* **Operational Database (`eleguard_rail` on port 3308):**
  * `elephant_reports` count: **43** (0 writes)
  * `system_logs` count: **185** (0 writes)
  * **Report #1:** Status remains strictly `'Pending'`, `sighting_date` remains `NULL`, `sighting_time` remains `NULL`, and `updated_at` remains `NULL`.
* **Milestone Scope Boundary:** **Stage 3B Complete (Design Only).** Stage 3C (Implementation) has NOT started.
