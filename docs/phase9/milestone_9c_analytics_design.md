# EleGuard Rail — Milestone 9C: Temporal & Seasonal Analytics Design (Refined)

**Document:** `docs/phase9/milestone_9c_analytics_design.md`  
**Phase:** 9 — Historical Elephant Sighting Intelligence  
**Milestone:** 9C — Temporal & Seasonal Intelligence  
**Status:** DESIGN-ONLY (Analytics Engine Specifications; No Code Modified)  
**Target Module:** `ai/analytics/temporal_analytics.py` & `app/services/TemporalAnalyticsService.php`

---

## 1. Executive Summary & Scientific Framework

Asian elephants (*Elephas maximus maximus*) in Sri Lanka exhibit pronounced temporal, circadian, and seasonal movement patterns governed by:
1. **Circadian / Solar Drivers:** Foraging and track crossings are primarily crepuscular (dawn: 05:00–07:00, dusk: 17:30–19:30) and nocturnal (20:00–04:00) to avoid diurnal human disturbance and solar heat load.
2. **Monsoonal & Hydrological Drivers:** The North-Central railway corridor (Habarana, Minneriya, Gal Oya) lies entirely within Sri Lanka's **Dry Zone**. Surface water availability and vegetation palatability fluctuate dramatically between the **Yala** dry season and the **Maha** monsoonal wet season.

This document designs the mathematical algorithms, SQL queries, and visualization engines required to analyze these temporal phenomena once authentic field sightings are recorded.

---

## 2. Treatment of Approximate & Unknown Values in Analytics

A critical design refinement is ensuring that approximate presets and unknown timestamps do not distort scientific analytics:

### 2.1 Handling Unknown Values (`sighting_time IS NULL` or `sighting_date IS NULL`)
- **Hourly Circadian Distribution:**
  - Records where `sighting_time IS NULL` or `sighting_time_precision = 'unknown'` are **strictly excluded** from 24-hour histogram binning.
  - They are **never defaulted to 00:00:00** (midnight).
  - The visualization subtitle explicitly states the effective sample size: e.g. `(N=X records with confirmed time; N=Y excluded due to unrecorded time)`.
- **Seasonal Analysis:**
  - Records where `sighting_date IS NULL` are **strictly excluded** from monthly and monsoonal graphs.
  - The 43 existing historical/test records currently have `sighting_date = NULL` and are therefore safely excluded from genuine seasonal curves.

### 2.2 Handling Approximate Values (`sighting_time_precision = 'approximate'`)
Approximate sightings represent an uncertainty interval $[\text{time} - \Delta t, \text{time} + \Delta t]$ (e.g. Dusk = $18:15 \pm 45\text{ mins} \implies [17:30, 19:00]$).
Two analytical modes are provided:
1. **Discrete 1-Hour Circadian Binning (Fractional Window Attribution):**
   - When an observation's uncertainty window spans multiple hour bins (e.g., 17:30 to 19:00 spans Hour 17 [30 mins], Hour 18 [60 mins], and Hour 19 [0 mins]), weight is distributed proportionally:
     $$\text{Weight}(h) = \frac{\text{Duration of intersection of } [t-\Delta, t+\Delta] \text{ with hour } h}{2\Delta t}$$
   - This prevents artificial spiky peaks while accurately reflecting density probability across twilight.
2. **Broad Ecological Band Analysis (Recommended Primary View):**
   - Rather than 24 discrete 1-hour bins, approximate sightings are analyzed across four standard ecological periods:
     - **Dawn Crepuscular:** 04:30 – 07:00
     - **Daytime Foraging:** 07:00 – 17:30
     - **Dusk Crepuscular:** 17:30 – 19:30
     - **Night Crossing:** 19:30 – 04:30
   - Categorical presets (`dawn`, `dusk`, `night`) map cleanly into these broad bands with zero mathematical distortion.

---

## 3. Mathematical & Algorithmic Formulations

### 3.1 Circadian Hourly Distribution Engine
Calculates the effective observation density $D(h)$ across all 24 hours of the day ($h \in [0, 23]$):

$$D(h) = \sum_{i \in \text{Exact}} \mathbb{I}(\text{HOUR}(t_i) = h) + \sum_{j \in \text{Approx}} W_j(h)$$

Where $W_j(h)$ is the fractional overlap weight of approximate sighting $j$ within hour $h$.

### 3.2 Nocturnal vs Diurnal Risk Partitioning
- **Diurnal (Daytime):** 06:00:00 to 17:59:59 (Full ambient visibility for locomotive drivers).
- **Nocturnal (Night-time):** 18:00:00 to 05:59:59 (Requires locomotive high-beam headlights; +2 Risk Score bonus).

### 3.3 Reporting Telemetry Lag Engine
Measures the latency $\Delta t_{\text{lag}}$ between observation and system ingestion:
$$\Delta t_{\text{lag}} = \text{created\_at} - (\text{sighting\_date} + \text{sighting\_time})$$

Stratified into actionable operational tiers:
- **Real-Time Warning ($\Delta t < 15\text{ mins}$):** High alert readiness; locomotive can be slowed immediately.
- **Recent Sighting ($15\text{ mins} \le \Delta t \le 2\text{ hours}$):** Local caution zone; herd likely still in track vicinity.
- **Historical Advisory ($\Delta t > 2\text{ hours}$):** Archived for patrol intelligence; no active train stoppage.

---

## 4. Sri Lankan Seasonal & Monsoonal Regimes

Sri Lanka's climate in the North-Central Dry Zone exhibits four distinct agro-climatic seasons:

| Monsoonal / Agro-Ecological Season | Calendar Months | Regional Climate (North-Central Dry Zone) | Elephant Ecological Behavior & Railway Risk |
|:---|:---:|:---|:---|
| **Maha Season**<br>(North-East Monsoon) | **October – January** | **Heavy rainfall and widespread flooding.** Tank reservoirs and forest waterholes are full; succulent forage is abundant across jungle reserves. | Elephants disperse deeply into protected forests and nature reserves. **Track crossings are lower in frequency but higher in risk** due to heavy rain obscuring driver sightlines and train braking distances increasing on wet rails. |
| **First Inter-Monsoon (FIM)** | **February – April** | Intermittent afternoon thunderstorms; gradual drying of surface water. | Transitional movements as herds begin shifting between seasonal feeding ranges. |
| **Yala Season**<br>(South-West Monsoon / Dry Period) | **May – August / September** | **Severe dry season and drought in Dry Zone.** Peripheral forest streams, minor culverts, and tanks dry up completely. | **Peak Human-Elephant-Railway Conflict Period.** Massive gathering of elephants around perennial reservoirs (notably the world-famous *Minneriya Elephant Gathering*). Herds repeatedly cross railway tracks between Minneriya National Park, Kaudulla, and Habarana in search of water and fresh grasses. |
| **Second Inter-Monsoon (SIM)** | **October – November** | Tropical depressions and heavy cyclonic downpours. | Herds begin seasonal dispersal away from major reservoirs back into expansive forest sanctuaries. |

---

## 5. Strict Provenance Filtering & Scientific Honesty

### Population Stratification Rule
- **Primary Baseline ($N=3$):** Retains `sighting_date = NULL`, `sighting_time = NULL`. Cannot be used to construct seasonal or hourly curves.
- **Automated Test Harness ($N=33$):** Contains batch morning timestamps (`07:00 - 09:00 AM`). Must NEVER be plotted as wild elephant activity.
- **Demo & Audit Cases ($N=7$):** Isolated from authentic wildlife analytics.
- **Production Analytics Code:**
  ```python
  def get_analyzable_records(records, analysis_type='circadian'):
      if analysis_type == 'circadian':
          return [r for r in records if r.get('sighting_time') is not None and r.get('sighting_time_precision') in ['exact', 'approximate']]
      elif analysis_type == 'seasonal':
          return [r for r in records if r.get('sighting_date') is not None]
      return records
  ```
