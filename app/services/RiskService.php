<?php
/**
 * Risk Assessment Service
 * Calculates the threat category (Low, Medium, High, Critical) for elephant sightings.
 * 
 * Milestone 9C — Stage 3C: Controlled observation-aware temporal risk integration.
 */

require_once __DIR__ . '/TemporalRiskService.php';

class RiskService {

    /**
     * Calculates the numerical risk score and returns corresponding level.
     * Legacy 5-argument method strictly preserved for backward compatibility.
     * 
     * @param string $sectionRisk Risk level of railway section ('Low', 'Medium', 'High', 'Critical')
     * @param string $urgencyLevel Urgency of report ('Low', 'Medium', 'High', 'Critical')
     * @param int $elephantCount Number of elephants reported
     * @param string $reportTime Timestamp/time of the report
     * @param bool $activeAlertsInSection Whether there are active alerts in the same section
     * @return string Calculated risk category ('Low', 'Medium', 'High', 'Critical')
     */
    public static function calculateRiskScore($sectionRisk, $urgencyLevel, $elephantCount, $reportTime, $activeAlertsInSection) {
        $score = 0;

        // 1. Section Risk Mapping
        switch (ucfirst(strtolower((string)$sectionRisk))) {
            case 'Critical':
                $score += 4;
                break;
            case 'High':
                $score += 3;
                break;
            case 'Medium':
                $score += 2;
                break;
            case 'Low':
            default:
                $score += 1;
                break;
        }

        // 2. Urgency Level Mapping
        switch (ucfirst(strtolower((string)$urgencyLevel))) {
            case 'Critical':
                $score += 4;
                break;
            case 'High':
                $score += 3;
                break;
            case 'Medium':
                $score += 2;
                break;
            case 'Low':
            default:
                $score += 1;
                break;
        }

        // 3. Elephant Count Bonus
        $count = (int)$elephantCount;
        if ($count >= 5) {
            $score += 3;
        } elseif ($count >= 2) {
            $score += 2;
        } elseif ($count == 1) {
            $score += 1;
        }

        // 4. Night-time (6 PM to 6 AM) Sighting Bonus
        $hour = (int)date('H', strtotime($reportTime));
        if ($hour >= 18 || $hour < 6) {
            $score += 2;
        }

        // 5. Existing Active Alerts Bonus
        if ($activeAlertsInSection) {
            $score += 2;
        }

        // Final Category Translation
        return self::classifyScore($score);
    }

    /**
     * Translates an integer risk score into standard 4-tier category.
     * 
     * @param int|float $score
     * @return string 'Critical'|'High'|'Medium'|'Low'
     */
    public static function classifyScore($score) {
        $s = (int)round($score);
        if ($s >= 11) {
            return 'Critical';
        } elseif ($s >= 8) {
            return 'High';
        } elseif ($s >= 5) {
            return 'Medium';
        } else {
            return 'Low';
        }
    }

    /**
     * Calculates observation-aware dynamic risk score incorporating temporal intelligence.
     * 
     * Controlled Milestone 9C Stage 3C API:
     * - Preserves legacy 4-tier thresholds: Critical (11+), High (8-10), Medium (5-7), Low (0-4).
     * - Evaluates continuous diurnal illumination based on actual observation time.
     * - Does NOT substitute created_at or updated_at for sighting time.
     * - Unknown observation time receives 0.0 diurnal points.
     * - Experimental temporal decay is disabled by default (enable_decay = false).
     * - Emits structured explainability metadata for review interfaces.
     * 
     * @param array $reportContext [
     *     'section_risk' => string,     // 'Low'|'Medium'|'High'|'Critical'
     *     'urgency_level' => string,    // 'Low'|'Medium'|'High'|'Critical'
     *     'elephant_count' => int,      // Number of elephants
     *     'active_alerts' => bool,      // Whether active alerts exist in section
     *     'is_potential_duplicate' => bool // Optional duplicate flag
     * ]
     * @param array $temporalMetadata [
     *     'sighting_date' => string|null,
     *     'sighting_time' => string|null,
     *     'sighting_time_precision' => string|null,
     *     'sighting_time_window' => string|null,
     *     'sighting_time_uncertainty_minutes' => int|null,
     *     'created_at' => string|null   // Ingestion time for lag calculation
     * ]
     * @param array $options [
     *     'enable_decay' => bool,       // Default false (experimental)
     *     'eval_time' => string|null,   // Evaluation timestamp
     *     'decay_half_life' => float    // Default 4.0 hours (experimental)
     * ]
     * @return array Structured risk result
     */
    public static function calculateObservationAwareRisk(
        array $reportContext, 
        array $temporalMetadata = [], 
        array $options = []
    ) {
        $warnings = [];

        // 1. Base Section Risk Mapping (1 to 4 points)
        $rawSectionRisk = $reportContext['section_risk'] ?? $reportContext['risk_level'] ?? $reportContext['section_risk_level'] ?? 'Low';
        switch (ucfirst(strtolower((string)$rawSectionRisk))) {
            case 'Critical':
                $baseSectionPoints = 4;
                break;
            case 'High':
                $baseSectionPoints = 3;
                break;
            case 'Medium':
                $baseSectionPoints = 2;
                break;
            case 'Low':
            default:
                $baseSectionPoints = 1;
                break;
        }

        // 2. Urgency Level Mapping (1 to 4 points)
        $rawUrgency = $reportContext['urgency_level'] ?? 'Low';
        switch (ucfirst(strtolower((string)$rawUrgency))) {
            case 'Critical':
                $urgencyPoints = 4;
                break;
            case 'High':
                $urgencyPoints = 3;
                break;
            case 'Medium':
                $urgencyPoints = 2;
                break;
            case 'Low':
            default:
                $urgencyPoints = 1;
                break;
        }

        // 3. Elephant Count Bonus (0 to 3 points)
        $count = isset($reportContext['elephant_count']) ? (int)$reportContext['elephant_count'] : 0;
        if ($count >= 5) {
            $elephantCountPoints = 3;
        } elseif ($count >= 2) {
            $elephantCountPoints = 2;
        } elseif ($count == 1) {
            $elephantCountPoints = 1;
        } else {
            $elephantCountPoints = 0;
        }

        // 4. Active Alert Concurrency Bonus (0 or 2 points)
        $hasActiveAlerts = !empty($reportContext['active_alerts']) || !empty($reportContext['activeAlertsInSection']);
        $activeAlertPoints = $hasActiveAlerts ? 2 : 0;

        // Duplicate flag warning check
        if (!empty($reportContext['is_potential_duplicate'])) {
            $warnings[] = "Report is flagged as a potential duplicate sighting; risk evaluation reflects individual report telemetry.";
        }

        // 5. Diurnal Illumination Adjustment via TemporalRiskService
        $diurnalRes = TemporalRiskService::evaluateDiurnalAdjustment($temporalMetadata);
        $diurnalAdjustment = (float)$diurnalRes['diurnal_factor'];
        if (!empty($diurnalRes['warnings'])) {
            $warnings = array_merge($warnings, $diurnalRes['warnings']);
        }

        // 6. Incremental Sighting Threat vs Section Base Risk
        // Incremental threat introduced by this observation
        $incrementalSightingPoints = $urgencyPoints + $elephantCountPoints + $diurnalAdjustment;

        // 7. Experimental Temporal Decay (DISABLED by default)
        $enableDecay = !empty($options['enable_decay']);
        $decayDetails = [
            'enabled' => $enableDecay,
            'multiplier' => 1.0,
            'elapsed_minutes' => null,
            'points_decayed' => 0.0,
            'explanation' => 'Temporal decay disabled by default in Stage 3C.'
        ];

        if ($enableDecay) {
            $halfLife = isset($options['decay_half_life']) ? (float)$options['decay_half_life'] : TemporalRiskService::DEFAULT_DECAY_HALF_LIFE_HOURS;
            $evalTime = $options['eval_time'] ?? null;
            $decayRes = TemporalRiskService::evaluateExperimentalDecay($temporalMetadata, $evalTime, $halfLife);

            if (!empty($decayRes['is_chronology_inconsistent'])) {
                $warnings[] = $decayRes['explanation'];
            }

            if ($decayRes['enabled']) {
                $multiplier = $decayRes['decay_multiplier'];
                $decayedSighting = $incrementalSightingPoints * $multiplier;
                $pointsDecayed = $incrementalSightingPoints - $decayedSighting;

                $decayDetails = [
                    'enabled' => true,
                    'multiplier' => $multiplier,
                    'elapsed_minutes' => $decayRes['elapsed_minutes'],
                    'elapsed_hours' => $decayRes['elapsed_hours'],
                    'points_decayed' => round($pointsDecayed, 3),
                    'explanation' => $decayRes['explanation']
                ];

                // Section Base Risk Floor Rule: decay never reduces below section baseline + alerts
                $unroundedTotal = $baseSectionPoints + $activeAlertPoints + $decayedSighting;
            } else {
                $unroundedTotal = $baseSectionPoints + $activeAlertPoints + $incrementalSightingPoints;
            }
        } else {
            // Standard non-decayed observation-aware score
            $unroundedTotal = $baseSectionPoints + $activeAlertPoints + $incrementalSightingPoints;
        }

        // 8. Rounded final score and standard 4-tier classification
        $finalScore = (int)round($unroundedTotal);
        $finalClassification = self::classifyScore($finalScore);

        return [
            'base_section_points' => $baseSectionPoints,
            'urgency_points' => $urgencyPoints,
            'elephant_count_points' => $elephantCountPoints,
            'active_alert_points' => $activeAlertPoints,
            'diurnal_adjustment' => $diurnalAdjustment,
            'observation_precision' => $diurnalRes['precision'],
            'observation_interval' => [
                'start' => $diurnalRes['interval_start'],
                'end' => $diurnalRes['interval_end'],
                'window' => $diurnalRes['window_name'],
                'uncertainty_minutes' => $diurnalRes['uncertainty_minutes']
            ],
            'data_quality_warnings' => array_values(array_unique($warnings)),
            'unrounded_total_score' => round($unroundedTotal, 3),
            'final_score' => $finalScore,
            'final_classification' => $finalClassification,
            'model_version' => TemporalRiskService::MODEL_VERSION,
            'experimental_assumptions' => [
                'diurnal_ramp' => 'Dawn (05:00-07:00) and Dusk (17:00-19:00) transitions are experimental continuous engineering approximations.',
                'temporal_decay' => $enableDecay 
                    ? "Experimental half-life of {$options['decay_half_life']}h applied to incremental sighting points only."
                    : 'Temporal decay disabled by default; report threat is unattenuated by elapsed time.'
            ],
            'decay_details' => $decayDetails
        ];
    }
}

