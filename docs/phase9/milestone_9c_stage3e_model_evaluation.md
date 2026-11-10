# EleGuard Rail — Milestone 9C Stage 3E Technical Report
## Temporal Risk Model Evaluation, Mathematical Validation & Scenario Simulation

**Project:** EleGuard Rail (Sri Lanka Wildlife-Railway Collision Early Warning Platform)  
**Baseline Commit:** `8b3760f`  
**Milestone:** 9C — Stage 3E  
**Environment:** PHP 8.2 / MariaDB 10.4 (Port 3308) / Apache (XAMPP)  
**Date:** 10 November 2026  
**Status:** COMPLETE & VERIFIED — EVALUATION ONLY (Zero Operational Mutations)  

---

### 1. Executive Summary & Authorization Scope

Under Milestone 9C Stage 3E, an exhaustive, independent mathematical evaluation, scenario simulation, and sensitivity analysis was conducted on the observation-aware temporal and diurnal risk calculation model developed across Stages 3A through 3D.

#### Strict Operational Safeguards:
1. **Evaluation-Only Scope:** Zero operational risk scoring formulas were modified, zero experimental scores were activated for alerting, and zero database records were altered.
2. **Temporal Decay Preserved as Inactive:** Experimental temporal decay remains disabled by default (`enable_decay = false`); its mathematical properties were evaluated purely through analytical simulation.
3. **No Fabricated Predictive Claims:** In the absence of genuine labelled collision ground truth, no claims of predictive accuracy are manufactured. The model is evaluated strictly on mathematical consistency, logical robustness, and ecological alignment.
4. **Zero Operational Database Modification:** The production database `eleguard_rail` remained completely untouched across all evaluations (`elephant_reports = 43`, `system_logs = 185`, `alerts = 15`, Report #1 pristine).

---

### 2. Phase 1 — Mathematical Validation

#### 2.1 Piecewise Analytical Diurnal Illumination Function $D(t)$
The diurnal illumination function $D(t) \in [0.0, 2.0]$ models solar illumination in Sri Lanka ($06^\circ\text{N}$ to $10^\circ\text{N}$, Asia/Colombo UTC+05:30), where daylight and twilight transitions remain remarkably consistent year-round:

$$D(t) = \begin{cases} 
2.0 & t \in [0.0, 5.0) \cup [19.0, 24.0) & \text{(Full Night)} \\
2.0 - \frac{t - 5.0}{2.0} \times 2.0 = 7.0 - t & t \in [5.0, 7.0) & \text{(Dawn Twilight Ramp Down)} \\
0.0 & t \in [7.0, 17.0) & \text{(Daylight)} \\
\frac{t - 17.0}{2.0} \times 2.0 = t - 17.0 & t \in [17.0, 19.0) & \text{(Dusk Twilight Ramp Up)}
\end{cases}$$

#### 2.2 Mathematical Proofs & Boundary Consistency
The function was tested against 18 discrete decimal-hour sample points across the 24-hour cycle. Independent analytical derivations matched computed code outputs with zero deviation ($< 10^{-6}$ error):

| Clock Time (SLST) | Decimal Hour $t$ | Analytical Expected $D(t)$ | Computed Output | Region / Boundary |
|---|:---:|:---:|:---:|---|
| **00:00** | $0.000$ | $2.000$ | $2.000$ | Midnight Baseline |
| **02:30** | $2.500$ | $2.000$ | $2.000$ | Deep Night |
| **04:59** | $4.983$ | $2.000$ | $2.000$ | Night / Dawn Boundary |
| **05:00** | $5.000$ | $2.000$ | $2.000$ | Dawn Transition Start |
| **05:30** | $5.500$ | $1.500$ | $1.500$ | Dawn Mid-transition ($7.0 - 5.5$) |
| **06:00** | $6.000$ | $1.000$ | $1.000$ | Dawn Midpoint ($7.0 - 6.0$) |
| **06:30** | $6.500$ | $0.500$ | $0.500$ | Dawn Late Transition ($7.0 - 6.5$) |
| **07:00** | $7.000$ | $0.000$ | $0.000$ | Daylight Boundary (Dawn End) |
| **10:00** | $10.000$ | $0.000$ | $0.000$ | Mid-morning Daylight |
| **12:00** | $12.000$ | $0.000$ | $0.000$ | Solar Noon Daylight |
| **16:59** | $16.983$ | $0.000$ | $0.000$ | Pre-dusk Daylight Boundary |
| **17:00** | $17.000$ | $0.000$ | $0.000$ | Dusk Transition Start |
| **17:30** | $17.500$ | $0.500$ | $0.500$ | Dusk Early Transition ($17.5 - 17.0$) |
| **18:00** | $18.000$ | $1.000$ | $1.000$ | Dusk Midpoint ($18.0 - 17.0$) |
| **18:30** | $18.500$ | $1.500$ | $1.500$ | Dusk Late Transition ($18.5 - 17.0$) |
| **19:00** | $19.000$ | $2.000$ | $2.000$ | Full Night Boundary (Dusk End) |
| **21:45** | $21.750$ | $2.000$ | $2.000$ | Night Post-dusk |
| **23:59** | $23.983$ | $2.000$ | $2.000$ | Pre-midnight Boundary |

#### 2.3 Interval Integration & Cyclic Midnight Crossings
Because $D(t)$ is piecewise linear with partition knots $\mathcal{K} = \{0, 5, 7, 17, 19, 24\}$, the integral over any subsegment $[u_1, u_2] \subset [k_i, k_{i+1}]$ is mathematically exact using the single-strip trapezoid rule:

$$\int_{u_1}^{u_2} D(t) dt = \frac{D(u_1) + D(u_2)}{2} (u_2 - u_1)$$

For intervals $[t_A, t_B]$ crossing midnight ($t_B < t_A$):

$$\bar{D} = \frac{1}{(24.0 - t_A) + t_B} \left( \int_{t_A}^{24.0} D(t) dt + \int_0^{t_B} D(t) dt \right)$$

**Analytical Verification Results:**
1. **Dawn Preset [04:30, 06:30] ($\Delta t = 2.0\text{h}$):**  
   $\int = [4.5, 5.0] (0.5 \times 2.0 = 1.0) + [5.0, 6.5] (1.5 \times \frac{2.0 + 0.5}{2} = 1.875) = 2.875$  
   Mean factor $\bar{D} = \frac{2.875}{2.0} = \mathbf{1.4375\text{ pts}}$ (Exact match).
2. **Dusk Preset [17:30, 19:00] ($\Delta t = 1.5\text{h}$):**  
   $\int = [17.5, 19.0] (1.5 \times \frac{0.5 + 2.0}{2} = 1.875)$  
   Mean factor $\bar{D} = \frac{1.875}{1.5} = \mathbf{1.2500\text{ pts}}$ (Exact match).
3. **Morning Preset [07:30, 10:30] ($\Delta t = 3.0\text{h}$):** $\bar{D} = \mathbf{0.0000\text{ pts}}$.
4. **Afternoon Preset [13:00, 16:00] ($\Delta t = 3.0\text{h}$):** $\bar{D} = \mathbf{0.0000\text{ pts}}$.
5. **Night Preset [20:00, 24:00] ($\Delta t = 4.0\text{h}$):** $\bar{D} = \mathbf{2.0000\text{ pts}}$.
6. **Midnight Crossing [22:00, 03:00] ($\Delta t = 5.0\text{h}$):**  
   $\int = [22, 24] (2.0 \times 2.0 = 4.0) + [0, 3] (3.0 \times 2.0 = 6.0) = 10.0$  
   Mean factor $\bar{D} = \frac{10.0}{5.0} = \mathbf{2.0000\text{ pts}}$ (Exact match).
7. **Midnight to Dawn Crossing [22:00, 06:00] ($\Delta t = 8.0\text{h}$):**  
   $\int = [22, 24] (4.0) + [0, 5] (10.0) + [5, 6] (1.5) = 15.5$  
   Mean factor $\bar{D} = \frac{15.5}{8.0} = \mathbf{1.9375\text{ pts}}$ (Exact match).
8. **Point Observation [18:00, 18:00] ($\Delta t = 0.0\text{h}$):** Instantaneous factor $\mathbf{1.0000\text{ pt}}$ (Exact match).

#### 2.4 Unknown Time & Chronology Robustness
- **Unknown Time:** When precision is `'unknown'` or observation time is `NULL`, $\bar{D}$ evaluates strictly to $0.0\text{ pts}$ (neutral daylight baseline), and the system attaches a data quality notice: `"Unknown observation time; zero diurnal adjustment applied (neutral daylight baseline)."`.
- **Chronology Inconsistencies:** Reports filed before the plausible observation interval or future timestamps are intercepted and flagged with warning notices.

---

### 3. Phase 2 & 3 — Scenario Simulation & Comparative Risk Evaluation

A controlled dataset of **20 synthetic scenarios** covering all operational dimensions was simulated and evaluated against both the operational baseline formula and the experimental observation-aware model.

The complete dataset is persisted at [`tests/data/milestone_9c_stage3e_scenarios.json`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/tests/data/milestone_9c_stage3e_scenarios.json).

#### 3.1 Comparative Evaluation Table (20 Scenarios)

| Scenario ID & Description | Operational Score | Operational Tier | Experimental Unrounded | Experimental Tier | Score Diff | Classification Shift | Primary Driver of Divergence |
|---|:---:|:---:|:---:|:---:|:---:|:---:|---|
| **SCN-01:** Daytime Solitary (Colombo-Fort) | $4.00$ | **Low** | $4.00$ | **Low** | $+0.00$ | **Concordant** | Daylight exact agreement ($D=0.0$) |
| **SCN-02:** Daytime Small Group (Polgahawela) | $6.00$ | **Medium** | $6.00$ | **Medium** | $+0.00$ | **Concordant** | Daylight exact agreement ($D=0.0$) |
| **SCN-03:** Daytime Large Herd (Maho) | $9.00$ | **High** | $9.00$ | **High** | $+0.00$ | **Concordant** | Daylight exact agreement ($D=0.0$) |
| **SCN-04:** Nighttime Solitary (Colombo-Fort) | $5.00$ | **Medium** | $5.00$ | **Medium** | $+0.00$ | **Concordant** | Full night exact agreement ($D=2.0$) |
| **SCN-05:** Nighttime High-Urgency (Polgahawela) | $9.00$ | **High** | $9.00$ | **High** | $+0.00$ | **Concordant** | Full night exact agreement ($D=2.0$) |
| **SCN-06:** Nighttime Max Hazard + Alert (Gal Oya) | $15.00$ | **Critical** | $15.00$ | **Critical** | $+0.00$ | **Concordant** | Max score ceiling reached ($15\text{ pts}$) |
| **SCN-07:** Dawn Point 05:45 (Polgahawela) | $7.00$ | **Medium** | $6.25$ | **Medium** | $-0.75$ | **Concordant** | Dawn ramp ($D=1.25$ vs op step $+2$) |
| **SCN-08:** Dawn Preset 04:30–06:30 (Maho) | $8.00$ | **High** | $9.44$ | **High** | $+1.44$ | **Concordant** | Dawn interval integration ($D=1.44$) |
| **SCN-09:** Dusk Point 18:15 (Polgahawela) | $9.00$ | **High** | $8.25$ | **High** | $-0.75$ | **Concordant** | Dusk ramp ($D=1.25$ vs op step $+2$) |
| **SCN-10:** Dusk Preset 17:30–19:00 (Gal Oya) | $10.00$ | **High** | $9.25$ | **High** | $-0.75$ | **Concordant** | Dusk interval integration ($D=1.25$) |
| **SCN-11:** Solitary Night Low Risk (Colombo-Fort) | $5.00$ | **Medium** | $5.00$ | **Medium** | $+0.00$ | **Concordant** | Full night concordance |
| **SCN-12:** Large Herd Day Med Risk (Polgahawela) | $8.00$ | **High** | $8.00$ | **High** | $+0.00$ | **Concordant** | Daylight concordance |
| **SCN-13:** Max Herd Night Critical Alert (Gal Oya) | $15.00$ | **Critical** | $15.00$ | **Critical** | $+0.00$ | **Concordant** | Full scale concordance |
| **SCN-14:** High-Risk Passive Day (Maho) | $5.00$ | **Medium** | $5.00$ | **Medium** | $+0.00$ | **Concordant** | Daylight concordance |
| **SCN-15:** Low-Risk High Urgency Night (Colombo) | $10.00$ | **High** | $10.00$ | **High** | $+0.00$ | **Concordant** | Night concordance |
| **SCN-16:** Morning sighting delayed to Night filing | $9.00$ | **High** | $7.00$ | **Medium** | $-2.00$ | **Demoted** | **Ingestion Bias Correction (Morning sighting stripped of night bonus)** |
| **SCN-17:** Night sighting delayed to Day filing | $7.00$ | **Medium** | $9.00$ | **High** | $+2.00$ | **Promoted** | **Ingestion Bias Correction (Nocturnal hazard restored to daytime report)** |
| **SCN-18:** Custom Window 16:00–18:00 crossing Dusk | $8.00$ | **High** | $6.25$ | **Medium** | $-1.75$ | **Demoted** | **Twilight integration replaces false night bonus ($D=0.25$ vs op $+2$)** |
| **SCN-19:** Unknown Observation Time (Maho) | $8.00$ | **High** | $8.00$ | **High** | $+0.00$ | **Concordant** | Neutral $0.0\text{ pt}$ policy applied safely |
| **SCN-20:** Potential Duplicate Flagged (Gal Oya) | $13.00$ | **Critical** | $13.00$ | **Critical** | $+0.00$ | **Concordant** | Duplicate notice attached; score not inflated |

#### 3.2 Divergence & Classification Shift Analysis
- **Overall Concordance Rate:** **85.0%** (17 of 20 scenarios produce identical 4-tier classifications).
- **Promotions:** **5.0%** (1 scenario: SCN-17).
  - *Ecological Justification:* A high-urgency sighting of 2 elephants observed at 23:00 at night in a medium-risk section was delayed and filed at 11:00 AM the following morning. The operational system scored it as **Medium (7 pts)** because the intake desk received it in bright sunshine ($S_{night}=0$). The experimental model evaluated the actual 23:00 observation time ($D=2.0$), elevating it to **High (9 pts)**. This properly reflects nocturnal collision hazard.
- **Demotions:** **10.0%** (2 scenarios: SCN-16 and SCN-18).
  - *Ecological Justification:* 
    - SCN-16 was observed at 08:00 AM in broad daylight, but filed at 19:30 in the evening. The operational engine awarded a false $+2$ night bonus because of the filing hour, elevating it to **High (9 pts)**. The experimental engine correctly recognized an 08:00 AM observation ($D=0.0$), assigning **Medium (7 pts)**.
    - SCN-18 spanned 16:00 to 18:00 (mostly daylight). The operational filing at 18:30 gave a full $+2$ night bonus, elevating to **High (8 pts)**. The experimental model integrated the actual window ($\bar{D}=0.25$), assigning **Medium (6.25 pts)**.
- **Governance Implication:** A higher score is **not** inherently a better prediction. Ingestion-time bias falsely inflates daylight sightings reported after work hours and dangerously suppresses nocturnal sightings reported the next morning. The observation-aware model aligns risk with field reality.

---

### 4. Phase 4 — Experimental Decay Sensitivity Analysis

Temporal decay models the attenuation of localized collision threat as elephants move away from railway tracks over time.

$$\text{Decay Multiplier: } M(\Delta t) = 2^{-\Delta t / t_{\text{half}}} = e^{-\lambda \Delta t}, \quad \text{where } \lambda = \frac{\ln(2)}{t_{\text{half}}}$$

To evaluate the mathematical robustness of the proposed $4.0\text{h}$ default half-life, four candidate half-lives ($2\text{h}$, $4\text{h}$, $6\text{h}$, $8\text{h}$) were evaluated across seven elapsed durations ($\Delta t \in \{0.5\text{h}, 1.0\text{h}, 2.0\text{h}, 4.0\text{h}, 8.0\text{h}, 12.0\text{h}, 24.0\text{h}\}$).

#### 4.1 Decay Multiplier Matrix $M(\Delta t)$

| Elapsed Time $\Delta t$ | $t_{\text{half}} = 2.0\text{h}$ | $t_{\text{half}} = 4.0\text{h}$ (Default) | $t_{\text{half}} = 6.0\text{h}$ | $t_{\text{half}} = 8.0\text{h}$ | Operational Meaning |
|---|:---:|:---:|:---:|:---:|---|
| **30 minutes (0.5h)** | $0.8409$ | $0.9170$ | $0.9439$ | $0.9576$ | Fresh Sighting (Real-time active patrol) |
| **1 hour (1.0h)** | $0.7071$ | $0.8409$ | $0.8909$ | $0.9170$ | Minor Delay (Recent crossing) |
| **2 hours (2.0h)** | **$0.5000$** | $0.7071$ | $0.7937$ | $0.8409$ | Moderate Delay |
| **4 hours (4.0h)** | $0.2500$ | **$0.5000$** | $0.6300$ | $0.7071$ | Half-Life Boundary for Default |
| **8 hours (8.0h)** | $0.0625$ | $0.2500$ | $0.3969$ | **$0.5000$** | Shift Change / Stale Sighting |
| **12 hours (12.0h)** | $0.0156$ | $0.1250$ | $0.2500$ | $0.3536$ | Next-Day Morning Review |
| **24 hours (24.0h)** | $0.0002$ | $0.0156$ | $0.0625$ | $0.1250$ | Historical Field Report |

#### 4.2 Section Base Risk Floor Protection Rule
Decay is applied **only to incremental sighting threat points** ($S_{\text{sighting}} = S_{\text{urgency}} + S_{\text{count}} + D$), while **Section Base Risk** ($S_{\text{section}}$) and **Active Alerts** ($S_{\text{alert}}$) are strictly preserved as an irreducible floor:

$$S_{\text{total}}(\Delta t) = S_{\text{section}} + S_{\text{alert}} + \left( S_{\text{sighting}} \times M(\Delta t) \right)$$

$$\lim_{\Delta t \to \infty} S_{\text{total}}(\Delta t) = S_{\text{section}} + S_{\text{alert}}$$

**Sensitivity Findings:**
1. **Critical High-Risk Corridors (Gal Oya–Habarana):** With $S_{\text{section}} = 4$ and active alert $S_{\text{alert}} = 2$, the floor is $6\text{ pts}$ (Medium). Even after 24 hours, the score never drops below 6.
2. **Short Half-Life ($t_{\text{half}} = 2.0\text{h}$):** Overly aggressive. By 4 hours, 75% of sighting threat is eliminated. Sri Lankan field studies indicate elephant herds frequently forage near rail embankments for 3 to 6 hours before moving inland.
3. **Long Half-Life ($t_{\text{half}} = 8.0\text{h}$):** Overly permissive. Sighting threat remains at 50% after 8 hours, causing stale warnings to persist into subsequent train shifts.
4. **Balanced Engineering Default ($t_{\text{half}} = 4.0\text{h}$):** Retains 50% threat at 4 hours and 25% at 8 hours, closely matching train controller dispatch cycles.
5. **Operational Status:** Temporal decay remains strictly **disabled** in the live operational system until spatial tracking capabilities (GPS collar telemetry or camera trap feeds) are available.

---

### 5. Phase 5 — Empirical Limitations & Data Scarcity Audit

A rigorous empirical audit of the underlying database and operational context was performed to establish clear scientific boundaries:

1. **Scarcity of Genuine Baseline Historical Data:**
   - The operational database contains only **three (N = 3)** genuine baseline historical reports filed prior to system deployment.
   - All other records (IDs 4 through 43) represent development, QA, and validation entries.
2. **Missing Historical GPS & Observation Timestamps:**
   - In the operational database `eleguard_rail`, all **43 records** have `sighting_date = NULL`, `sighting_time = NULL`, and `sighting_time_precision = 'unknown'`.
   - Historical records possess only operational database insertion timestamps (`created_at`).
3. **Absence of Ground-Truth Collision Labels:**
   - Field reports record elephant **sightings**, not railway collisions.
   - There are zero ground-truth negative labels (trains passing safely without incident) and zero labelled collision outcome records.
4. **Lack of Independent Train Telemetry:**
   - Sighting reports are not linked to locomotive event recorders (tachographs, emergency brake activations, or locomotive cab dashcams).
5. **Absence of Seasonal Weather & Monsoon Multipliers:**
   - The current model does not adjust for Northeast Monsoon (Maha) or Southwest Monsoon (Yala) rainfall variations, which affect elephant foraging ranges and track visibility.
6. **Scientific Boundary:**
   - The observation-aware temporal model is an **analytical engineering model**, not a statistically trained or verified predictive classifier.
   - It improves logical coherence by aligning risk calculations with observation times rather than database insertion times. It must remain in **shadow mode** until verified against multi-year empirical collision logs.

---

### 6. Phase 6 & 7 — Deliverables & Operational Safety Verification

#### 6.1 Artifacts Created
1. **Automated Test & Evaluation Suite:**  
   [`tests/test_milestone_9c_stage3e_model_evaluation.php`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/tests/test_milestone_9c_stage3e_model_evaluation.php) (47 assertions, 100% pass).
2. **Controlled Scenario Simulation Dataset:**  
   [`tests/data/milestone_9c_stage3e_scenarios.json`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/tests/data/milestone_9c_stage3e_scenarios.json) (20 complete scenario profiles, score breakdowns, and decay matrix).
3. **Comprehensive Evaluation Report:**  
   [`docs/phase9/milestone_9c_stage3e_model_evaluation.md`](file:///C:/Users/user/Desktop/Railway/eleguard-rail/docs/phase9/milestone_9c_stage3e_model_evaluation.md).

#### 6.2 Full Regression Suite Execution Results (305 Total Assertions)
All 8 test suites were executed against isolated test databases (`eleguard_rail_test`) with a **100% pass rate**:

| Suite | Scope | Assertions | Result |
|---|---|:---:|:---:|
| `test_milestone_9c_stage3e_model_evaluation.php` | Model Evaluation & Mathematical Validation | 47 | **47 / 47 PASS** |
| `test_milestone_9c_stage3d_dashboard_integration.php` | Dashboard Shadow Mode Integration | 38 | **38 / 38 PASS** |
| `test_milestone_9c_stage3c_temporal_risk.php` | Controlled Temporal Risk Implementation | 32 | **32 / 32 PASS** |
| `test_milestone_9c_stage2b_frontend_integration.php` | Frontend Reporting & Wildlife Elevation | 62 | **62 / 62 PASS** |
| `test_milestone_9c_stage2a_temporal_service.php` | Temporal Service Layer & Normalization | 49 | **49 / 49 PASS** |
| `test_milestone_9c_stage1_foundation.php` | Migration 03 & Schema Foundation | 29 | **29 / 29 PASS** |
| `test_milestone_9b_analytics.php` | Spatial Analytics & Distance Matrix | 23 | **23 / 23 PASS** |
| `test_ai_integration.php` | YOLOX-Tiny AI Advisory & Human Governance | 25 | **25 / 25 PASS** |
| **Grand Total** | | **305** | **305 / 305 PASS (100%)** |

#### 6.3 Operational Database Invariant Verification
The production database (`eleguard_rail` on port 3308) was queried directly via MariaDB client before and after all testing:
- `elephant_reports`: **43** (0 writes)
- `system_logs`: **185** (0 writes)
- `alerts`: **15** (0 writes)
- `Report #1`: Status = `'Pending'`, `sighting_* = NULL`, `updated_at = NULL` (100% pristine).

#### 6.4 Desktop / XAMPP Webroot Parity
All modified and created files have identical cryptographic SHA-256 hashes between `C:\Users\user\Desktop\Railway\eleguard-rail` and `C:\xampp\htdocs\eleguard-rail`.

---

### 7. Conclusion & Next Steps

Milestone 9C Stage 3E successfully validates the mathematical correctness, interval integration, and operational behavior of the temporal risk model. The model addresses ingestion-time bias while preserving operational safety through strict shadow mode isolation.

**Milestone 9C Stage 3 is now fully complete (Stages 3A through 3E). Per instructions, all work is paused awaiting user authorization before initiating Stage 4 (Analytics).**
