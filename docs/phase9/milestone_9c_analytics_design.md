# EleGuard Rail — Milestone 9C: Temporal & Seasonal Analytics Design

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

## 2. Core Temporal Analytics Dimensions

The analytics engine models six orthogonal temporal dimensions:

```
+----------------------------------------------------------------------------------------------------+
|                                    TEMPORAL ANALYTICS ENGINE                                       |
+----------------------------------------------------------------------------------------------------+
|                                                                                                    |
|  [ 1. CIRCADIAN HOURLY DISTRIBUTION ]                                                              |
|  24-hour binning [00:00 - 23:59]. Identifies peak crossing windows for train timetable advisories. |
|                                                                                                    |
|  [ 2. DAY VS NIGHT RISK PARTITIONING ]                                                             |
|  Bimodal split: Daytime (06:00 - 17:59) vs Nocturnal (18:00 - 05:59). Direct input to RiskService. |
|                                                                                                    |
|  [ 3. DAY-OF-WEEK / CYCLICAL PATTERNS ]                                                            |
|  Sunday through Saturday. Correlates human agricultural/railway traffic with wildlife activity.   |
|                                                                                                    |
|  [ 4. REPORTING TELEMETRY LAG ]                                                                    |
|  Delta (created_at - sighting_time). Measures dispatch alert latency and offline lag.              |
|                                                                                                    |
|  [ 5. MONTHLY TRENDS ]                                                                             |
|  Calendar month progression [Jan - Dec]. Tracks inter-annual population pressures.                 |
|                                                                                                    |
|  [ 6. MONSOONAL / SEASONAL AGRO-ECOLOGICAL REGIMES ]                                               |
|  Yala (Dry) vs Maha (Wet) vs Inter-monsoons. Models long-range landscape migrations.               |
|                                                                                                    |
+----------------------------------------------------------------------------------------------------+
```

---

## 3. Mathematical & Algorithmic Formulations

### 3.1 Circadian Hourly Distribution Engine
Calculates the hourly observation frequency $H(h)$ across all 24 hours of the day ($h \in [0, 23]$):

$$H(h) = \sum_{i=1}^{N} \mathbb{I}(\text{HOUR}(\text{sighting\_time}_i) = h \land \text{precision}_i \in \{\text{'exact'}, \text{'approximate'}\})$$

- Sightings where `sighting_time_precision = 'unknown'` are explicitly excluded from the denominator to avoid contaminating the 00:00 bin.
- Confidence intervals are computed assuming a Poisson arrival process for each hourly bin:
$$\text{CI}_{95\%}(H(h)) = H(h) \pm 1.96 \sqrt{H(h)}$$

### 3.2 Nocturnal vs Diurnal Risk Partitioning
Sri Lanka's equatorial latitude ($\approx 8^\circ\text{ N}$) exhibits minimal seasonal variation in solar dusk/dawn ($\pm 20\text{ mins}$ year-round). Therefore, standardized operational boundaries are defined:
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

Sri Lanka's climate does not follow temperate four-season cycles (Spring/Summer/Autumn/Winter). In the North-Central railway corridor, elephant movements are strictly governed by **four distinct agro-climatic seasons**:

| Monsoonal / Agro-Ecological Season | Calendar Months | Regional Climate (North-Central Dry Zone) | Elephant Ecological Behavior & Railway Risk |
|:---|:---:|:---|:---|
| **Maha Season**<br>(North-East Monsoon) | **October – January** | **Heavy rainfall and widespread flooding.** Tank reservoirs and forest waterholes are full; succulent forage is abundant across jungle reserves. | Elephants disperse deeply into protected forests and nature reserves. **Track crossings are lower in frequency but higher in risk** due to heavy rain obscuring driver sightlines and train braking distances increasing on wet rails. |
| **First Inter-Monsoon (FIM)** | **February – April** | Intermittent afternoon thunderstorms; gradual drying of surface water. | Transitional movements as herds begin shifting between seasonal feeding ranges. |
| **Yala Season**<br>(South-West Monsoon / Dry Period) | **May – August / September** | **Severe dry season and drought in Dry Zone.** Peripheral forest streams, minor culverts, and tanks dry up completely. | **Peak Human-Elephant-Railway Conflict Period.** Massive gathering of elephants around perennial reservoirs (notably the world-famous *Minneriya Elephant Gathering*). Herds repeatedly cross railway tracks between Minneriya National Park, Kaudulla, and Habarana in search of water and fresh grasses. |
| **Second Inter-Monsoon (SIM)** | **October – November** | Tropical depressions and heavy cyclonic downpours. | Herds begin seasonal dispersal away from major reservoirs back into expansive forest sanctuaries. |

### Seasonal SQL Classification Function
```sql
SELECT 
    CASE 
        WHEN MONTH(sighting_date) IN (10, 11, 12, 1) THEN 'Maha Season (NE Monsoon - Wet)'
        WHEN MONTH(sighting_date) IN (2, 3, 4) THEN 'First Inter-Monsoon (Transitional)'
        WHEN MONTH(sighting_date) IN (5, 6, 7, 8, 9) THEN 'Yala Season (SW Monsoon - Severe Dry / Gathering)'
        ELSE 'Unclassified'
    END AS agro_ecological_season,
    COUNT(*) AS total_sightings,
    SUM(elephant_count) AS total_elephants_observed
FROM elephant_reports
WHERE sighting_date IS NOT NULL
GROUP BY agro_ecological_season;
```

---

## 5. Strict Provenance Filtering & Scientific Honesty

A critical rule of Milestone 9C analytics design is **strict data provenance filtering**.

### The Population Composition Reality
As established in Milestones 9A and 9B:
- **Total Reports:** 43
- **Confirmed Historical Baseline:** $N=3$ (IDs 1, 2, 3) — Text-only, zero GPS, zero specific sighting hours.
- **Automated Test Harness Artifacts:** $N=33$ (IDs 4–33, 38–40) — Created in batch by automated test executions.
- **Demonstration Cases:** $N=3$ (IDs 41–43) — Created during UI demonstrations.
- **E2E Audit Verification Runs:** $N=4$ (IDs 53–56) — Created during Milestone 8C testing.

### Mandatory Analytical Safeguards
1. **Never Conflate Test Timestamps with Sighting Times:**
   The 33 automated test reports and 4 audit reports have `created_at` timestamps clustered between 07:00 AM and 09:00 AM purely because the test scripts were executed in the morning. **These must NEVER be plotted as "morning elephant activity peaks"**.
2. **No Seasonal Claims on $N=3$ Records:**
   The three genuine historical baseline records cannot produce statistically significant seasonal trends.
3. **Engine Filter Specification:**
   Any production analytics engine must implement a strict provenance filter:
   ```python
   # Mandatory filter in temporal_analytics.py
   def filter_population(records, population_type='all'):
       if population_type == 'genuine_only':
           # In current DB, genuine records are IDs 1-3 only
           return [r for r in records if r['id'] in [1, 2, 3]]
       elif population_type == 'operational_synthetic':
           return [r for r in records if r['id'] not in [1, 2, 3]]
       return records
   ```
4. **Visual Warning Banners:**
   Every generated chart must clearly display:
   > *"Sample Size Warning: Historical baseline N=3 is insufficient for empirical seasonal or circadian modeling. Chart illustrates system analytical capability using synthetic test fixtures."*

---

## 6. Target Visualization Deliverables (For Future Milestone 9C Implementation)

When authorized for implementation, the module will generate 6 standardized analytical visualizations under `docs/phase9/visualizations/`:
1. `temporal_hourly_circadian_distribution.png`: 24-hour histogram illustrating crepuscular and nocturnal peaks.
2. `temporal_day_vs_night_risk_split.png`: Bimodal pie and bar breakdown of day vs night sightings.
3. `temporal_seasonal_yala_vs_maha.png`: Seasonal comparison bar chart (Maha vs Yala vs Inter-monsoons).
4. `temporal_reporting_lag_distribution.png`: Histogram of reporting delays (real-time vs delayed reports).
5. `temporal_section_seasonal_heatmap.png`: Matrix heatmap of railway section crossings across the 4 seasons.
6. `temporal_provenance_stratification.png`: Visual audit demonstrating separation of genuine vs test timelines.
