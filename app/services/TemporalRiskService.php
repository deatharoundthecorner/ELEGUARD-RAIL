<?php
/**
 * EleGuard Rail - Temporal Risk Calculation Service
 * 
 * Reusable component for computing observation-aware diurnal illumination factors,
 * interval-integrated twilight transitions, and experimental temporal latency decay.
 * 
 * Implemented in Milestone 9C — Stage 3C (Controlled Implementation).
 * 
 * IMPORTANT:
 * - Operates strictly in the 'Asia/Colombo' timezone (UTC+05:30).
 * - Diurnal ramp parameters and decay half-lives are identified as EXPERIMENTAL
 *   engineering heuristics requiring empirical wildlife telemetry validation.
 * - Unknown observation times strictly produce ZERO diurnal points.
 * - Never substitutes created_at or updated_at for observation time.
 */

require_once __DIR__ . '/TemporalService.php';

class TemporalRiskService {

    const TIMEZONE = 'Asia/Colombo';
    const MODEL_VERSION = '1.0.0-experimental';

    // Diurnal illumination boundaries in hours (Asia/Colombo)
    const DIURNAL_NIGHT_END   = 5.0;  // 05:00 SLST - End of Full Night / Start of Dawn Transition
    const DIURNAL_DAY_START   = 7.0;  // 07:00 SLST - End of Dawn Transition / Start of Full Daylight
    const DIURNAL_DAY_END     = 17.0; // 17:00 SLST - End of Full Daylight / Start of Dusk Transition
    const DIURNAL_NIGHT_START = 19.0; // 19:00 SLST - End of Dusk Transition / Start of Full Night

    // Maximum diurnal bonus points for complete night visibility hazard
    const MAX_DIURNAL_POINTS  = 2.0;

    // Default experimental decay half-life in hours
    const DEFAULT_DECAY_HALF_LIFE_HOURS = 4.0;

    /**
     * Calculates the continuous instantaneous diurnal illumination factor D(t) for a scalar clock hour.
     * 
     * Piecewise continuous linear function:
     * - [19:00, 05:00]: Full Night = 2.0 (maximum locomotive stopping distance visual hazard)
     * - [05:00, 07:00]: Dawn Transition = 2.0 -> 0.0 (visual sightline expanding with twilight)
     * - [07:00, 17:00]: Full Daytime = 0.0 (maximum daylight visual braking sightline)
     * - [17:00, 19:00]: Dusk Transition = 0.0 -> 2.0 (rapid darkness onset during peak elephant movement)
     * 
     * @param float $hour Decimal clock hour in [0.0, 24.0) (e.g. 18.5 for 18:30)
     * @return float Diurnal factor in [0.0, 2.0]
     */
    public static function getInstantaneousDiurnalFactor($hour) {
        $h = (float)$hour;
        // Normalize cyclically into [0.0, 24.0)
        $h = fmod(fmod($h, 24.0) + 24.0, 24.0);

        if ($h < self::DIURNAL_NIGHT_END) {
            // [00:00, 05:00): Full Night
            return self::MAX_DIURNAL_POINTS;
        } elseif ($h < self::DIURNAL_DAY_START) {
            // [05:00, 07:00): Dawn Transition (Linear ramp down from 2.0 to 0.0)
            $ratio = ($h - self::DIURNAL_NIGHT_END) / (self::DIURNAL_DAY_START - self::DIURNAL_NIGHT_END);
            return self::MAX_DIURNAL_POINTS * (1.0 - $ratio);
        } elseif ($h < self::DIURNAL_DAY_END) {
            // [07:00, 17:00): Full Daytime
            return 0.0;
        } elseif ($h < self::DIURNAL_NIGHT_START) {
            // [17:00, 19:00): Dusk Transition (Linear ramp up from 0.0 to 2.0)
            $ratio = ($h - self::DIURNAL_DAY_END) / (self::DIURNAL_NIGHT_START - self::DIURNAL_DAY_END);
            return self::MAX_DIURNAL_POINTS * $ratio;
        } else {
            // [19:00, 24:00): Full Night
            return self::MAX_DIURNAL_POINTS;
        }
    }

    /**
     * Integrates the continuous diurnal illumination function D(t) over an interval [t_a, t_b].
     * 
     * Because D(t) is piecewise linear, the integral over any subsegment [u_1, u_2]
     * within a linear piece is mathematically exact using the trapezoid rule:
     *   Integral = ((D(u_1) + D(u_2)) / 2) * (u_2 - u_1)
     * 
     * Handles intervals crossing midnight (t_b < t_a) cyclically without negative duration errors.
     * 
     * @param float $tA Start time in decimal hours [0.0, 24.0)
     * @param float $tB End time in decimal hours [0.0, 24.0)
     * @return array ['mean_factor' => float, 'duration_hours' => float, 'integral' => float]
     */
    public static function integrateDiurnalInterval($tA, $tB) {
        $tA = fmod(fmod((float)$tA, 24.0) + 24.0, 24.0);
        $tB = fmod(fmod((float)$tB, 24.0) + 24.0, 24.0);

        // Case 1: Point observation (tA == tB)
        if (abs($tA - $tB) < 1e-6) {
            $val = self::getInstantaneousDiurnalFactor($tA);
            return [
                'mean_factor' => $val,
                'duration_hours' => 0.0,
                'integral' => 0.0
            ];
        }

        // Case 2: Crossing midnight (e.g. 22:00 to 02:00 -> [22, 24] and [0, 2])
        if ($tB < $tA) {
            $res1 = self::integrateNonCrossingInterval($tA, 24.0);
            $res2 = self::integrateNonCrossingInterval(0.0, $tB);
            $totalDuration = $res1['duration_hours'] + $res2['duration_hours'];
            $totalIntegral = $res1['integral'] + $res2['integral'];
            $mean = ($totalDuration > 0) ? ($totalIntegral / $totalDuration) : self::getInstantaneousDiurnalFactor($tA);
            return [
                'mean_factor' => $mean,
                'duration_hours' => $totalDuration,
                'integral' => $totalIntegral
            ];
        }

        // Case 3: Standard interval within the same 24-hour day (tA < tB)
        return self::integrateNonCrossingInterval($tA, $tB);
    }

    /**
     * Helper to integrate D(t) where 0.0 <= tA < tB <= 24.0.
     */
    private static function integrateNonCrossingInterval($tA, $tB) {
        $breakpoints = [0.0, self::DIURNAL_NIGHT_END, self::DIURNAL_DAY_START, self::DIURNAL_DAY_END, self::DIURNAL_NIGHT_START, 24.0];
        
        // Find all nodes in [tA, tB]
        $nodes = [$tA];
        foreach ($breakpoints as $bp) {
            if ($bp > $tA && $bp < $tB) {
                $nodes[] = $bp;
            }
        }
        $nodes[] = $tB;
        sort($nodes);

        $totalIntegral = 0.0;
        for ($i = 0; $i < count($nodes) - 1; $i++) {
            $u1 = $nodes[$i];
            $u2 = $nodes[$i + 1];
            $dt = $u2 - $u1;
            if ($dt <= 0) continue;
            
            $d1 = self::getInstantaneousDiurnalFactor($u1);
            $d2 = self::getInstantaneousDiurnalFactor($u2);
            $subIntegral = (($d1 + $d2) / 2.0) * $dt;
            $totalIntegral += $subIntegral;
        }

        $duration = $tB - $tA;
        $mean = ($duration > 0) ? ($totalIntegral / $duration) : self::getInstantaneousDiurnalFactor($tA);

        return [
            'mean_factor' => $mean,
            'duration_hours' => $duration,
            'integral' => $totalIntegral
        ];
    }

    /**
     * Converts a HH:MM:SS or HH:MM string to decimal hours.
     * 
     * @param string $timeStr
     * @return float|null
     */
    public static function timeStringToDecimalHours($timeStr) {
        if ($timeStr === null || trim((string)$timeStr) === '') {
            return null;
        }
        $parts = explode(':', trim((string)$timeStr));
        if (count($parts) < 2) {
            return null;
        }
        $h = (float)$parts[0];
        $m = (float)$parts[1];
        $s = isset($parts[2]) ? (float)$parts[2] : 0.0;
        return $h + ($m / 60.0) + ($s / 3600.0);
    }

    /**
     * Formats decimal hours into HH:MM string.
     * 
     * @param float $hours
     * @return string
     */
    public static function decimalHoursToTimeString($hours) {
        $h = fmod(fmod((float)$hours, 24.0) + 24.0, 24.0);
        $intH = (int)floor($h);
        $remMin = ($h - $intH) * 60.0;
        $intM = (int)round($remMin);
        if ($intM >= 60) {
            $intH = ($intH + 1) % 24;
            $intM = 0;
        }
        return sprintf('%02d:%02d', $intH, $intM);
    }

    /**
     * Evaluates the observation-aware diurnal adjustment for a report's temporal metadata.
     * 
     * Supported observation types:
     * - Exact: Evaluates D(t_obs) directly at clock time.
     * - Approximate Preset (dawn, morning, afternoon, dusk, night): Integrates across standard preset window.
     * - Approximate Custom: Integrates across [t_ref - uncertainty, t_ref + uncertainty].
     * - Unknown: Evaluates to strictly 0.0 diurnal points.
     * 
     * @param array $temporalMetadata [sighting_date, sighting_time, sighting_time_precision, sighting_time_window, sighting_time_uncertainty_minutes]
     * @return array [
     *     'diurnal_factor' => float,      // Exact unrounded float in [0.0, 2.0]
     *     'diurnal_points' => int,        // Integer rounded points (0, 1, or 2)
     *     'precision' => string,          // 'exact'|'approximate'|'unknown'
     *     'window_name' => string|null,   // Preset window or 'custom'
     *     'interval_start' => string|null,// HH:MM SLST
     *     'interval_end' => string|null,  // HH:MM SLST
     *     'uncertainty_minutes' => int|null,
     *     'explanation' => string,
     *     'warnings' => array
     * ]
     */
    public static function evaluateDiurnalAdjustment(array $temporalMetadata = []) {
        $precision = !empty($temporalMetadata['sighting_time_precision']) 
            ? strtolower(trim((string)$temporalMetadata['sighting_time_precision'])) 
            : TemporalService::PRECISION_UNKNOWN;

        $rawTime = $temporalMetadata['sighting_time'] ?? null;
        $rawWindow = !empty($temporalMetadata['sighting_time_window']) 
            ? strtolower(trim((string)$temporalMetadata['sighting_time_window'])) 
            : null;
        $rawUncertainty = isset($temporalMetadata['sighting_time_uncertainty_minutes']) && $temporalMetadata['sighting_time_uncertainty_minutes'] !== '' && $temporalMetadata['sighting_time_uncertainty_minutes'] !== null
            ? (int)$temporalMetadata['sighting_time_uncertainty_minutes']
            : null;

        $warnings = [];

        // CASE 1: Unknown observation time (Strict zero adjustment policy)
        if ($precision === TemporalService::PRECISION_UNKNOWN || empty($rawTime)) {
            if ($rawTime !== null && $rawTime !== '') {
                $warnings[] = "Time was provided but precision is marked unknown. Zero diurnal adjustment applied.";
            }
            return [
                'diurnal_factor' => 0.0,
                'diurnal_points' => 0,
                'precision' => TemporalService::PRECISION_UNKNOWN,
                'window_name' => null,
                'interval_start' => null,
                'interval_end' => null,
                'uncertainty_minutes' => null,
                'explanation' => 'Observation time unrecorded or unknown; zero diurnal bonus applied (neutral daylight baseline).',
                'warnings' => $warnings
            ];
        }

        // CASE 2: Exact observation time
        if ($precision === TemporalService::PRECISION_EXACT) {
            $decHours = self::timeStringToDecimalHours($rawTime);
            if ($decHours === null) {
                $warnings[] = "Malformed exact time string '{$rawTime}'. Falling back to 0.0 diurnal points.";
                return [
                    'diurnal_factor' => 0.0,
                    'diurnal_points' => 0,
                    'precision' => TemporalService::PRECISION_EXACT,
                    'window_name' => null,
                    'interval_start' => null,
                    'interval_end' => null,
                    'uncertainty_minutes' => 5,
                    'explanation' => 'Invalid exact time format; zero diurnal bonus applied.',
                    'warnings' => $warnings
                ];
            }

            $factor = self::getInstantaneousDiurnalFactor($decHours);
            $points = (int)round($factor);
            $timeFmt = substr($rawTime, 0, 5);

            $desc = "Exact observation at {$timeFmt} SLST";
            if ($factor >= 1.99) {
                $desc .= " (Full Night: extreme stopping distance visual hazard, +{$points} pts).";
            } elseif ($factor <= 0.01) {
                $desc .= " (Full Daylight: visual sightline uninhibited, +0 pts).";
            } else {
                $desc .= " (Twilight transition: intermediate visibility hazard, factor " . round($factor, 2) . " -> +{$points} pts).";
            }

            return [
                'diurnal_factor' => (float)$factor,
                'diurnal_points' => $points,
                'precision' => TemporalService::PRECISION_EXACT,
                'window_name' => null,
                'interval_start' => $timeFmt,
                'interval_end' => $timeFmt,
                'uncertainty_minutes' => 5,
                'explanation' => $desc,
                'warnings' => $warnings
            ];
        }

        // CASE 3: Approximate observation window
        if ($precision === TemporalService::PRECISION_APPROXIMATE) {
            // Subcase A: Named standard preset
            if ($rawWindow && isset(TemporalService::PRESETS[$rawWindow])) {
                $preset = TemporalService::PRESETS[$rawWindow];
                $refDec = self::timeStringToDecimalHours($preset['time']);
                $uncMinutes = $preset['uncertainty'];
                $uncHours = $uncMinutes / 60.0;

                $startDec = fmod($refDec - $uncHours + 24.0, 24.0);
                $endDec = fmod($refDec + $uncHours + 24.0, 24.0);

                $intResult = self::integrateDiurnalInterval($startDec, $endDec);
                $factor = $intResult['mean_factor'];
                $points = (int)round($factor);

                $startStr = self::decimalHoursToTimeString($startDec);
                $endStr = self::decimalHoursToTimeString($endDec);
                $winName = ucfirst($rawWindow);

                $desc = "Approximate {$winName} window ({$startStr} – {$endStr} SLST, ±{$uncMinutes}m): integral mean factor " . round($factor, 2) . " -> +{$points} pts.";

                return [
                    'diurnal_factor' => (float)$factor,
                    'diurnal_points' => $points,
                    'precision' => TemporalService::PRECISION_APPROXIMATE,
                    'window_name' => $rawWindow,
                    'interval_start' => $startStr,
                    'interval_end' => $endStr,
                    'uncertainty_minutes' => $uncMinutes,
                    'explanation' => $desc,
                    'warnings' => $warnings
                ];
            }

            // Subcase B: Custom approximate window
            $decHours = self::timeStringToDecimalHours($rawTime);
            if ($decHours === null) {
                $warnings[] = "Malformed approximate reference time '{$rawTime}'. Falling back to 0.0 diurnal points.";
                return [
                    'diurnal_factor' => 0.0,
                    'diurnal_points' => 0,
                    'precision' => TemporalService::PRECISION_APPROXIMATE,
                    'window_name' => 'custom',
                    'interval_start' => null,
                    'interval_end' => null,
                    'uncertainty_minutes' => $rawUncertainty,
                    'explanation' => 'Invalid approximate time; zero diurnal bonus applied.',
                    'warnings' => $warnings
                ];
            }

            $uncMinutes = ($rawUncertainty !== null && $rawUncertainty > 0) ? $rawUncertainty : 60;
            $uncHours = $uncMinutes / 60.0;
            $startDec = fmod($decHours - $uncHours + 24.0, 24.0);
            $endDec = fmod($decHours + $uncHours + 24.0, 24.0);

            $intResult = self::integrateDiurnalInterval($startDec, $endDec);
            $factor = $intResult['mean_factor'];
            $points = (int)round($factor);

            $startStr = self::decimalHoursToTimeString($startDec);
            $endStr = self::decimalHoursToTimeString($endDec);
            $refStr = substr($rawTime, 0, 5);

            $desc = "Custom approximate window ~{$refStr} ({$startStr} – {$endStr} SLST, ±{$uncMinutes}m): integral mean factor " . round($factor, 2) . " -> +{$points} pts.";

            return [
                'diurnal_factor' => (float)$factor,
                'diurnal_points' => $points,
                'precision' => TemporalService::PRECISION_APPROXIMATE,
                'window_name' => 'custom',
                'interval_start' => $startStr,
                'interval_end' => $endStr,
                'uncertainty_minutes' => $uncMinutes,
                'explanation' => $desc,
                'warnings' => $warnings
            ];
        }

        // Default safety fallback
        return [
            'diurnal_factor' => 0.0,
            'diurnal_points' => 0,
            'precision' => $precision,
            'window_name' => null,
            'interval_start' => null,
            'interval_end' => null,
            'uncertainty_minutes' => null,
            'explanation' => 'Unrecognized temporal configuration; zero diurnal bonus applied.',
            'warnings' => ["Unrecognized precision '{$precision}'."]
        ];
    }

    /**
     * Evaluates experimental exponential temporal threat decay Lambda(Delta t).
     * 
     * Formula:
     *   Lambda(Delta t) = 2^(-(Delta t) / T_half)
     * 
     * Safety Guarantees:
     * - Disabled by default.
     * - Elapsed duration is computed conservatively from window closure.
     * - Only decays the incremental sighting threat. Never decays section base risk.
     * 
     * @param array $temporalMetadata Sighting temporal fields
     * @param string|null $evalTime Evaluation timestamp (defaults to created_at or NOW)
     * @param float $halfLifeHours Configurable half-life parameter (default 4.0h)
     * @return array [
     *     'enabled' => bool,
     *     'elapsed_minutes' => int|null,
     *     'elapsed_hours' => float|null,
     *     'decay_multiplier' => float, // 1.0 if disabled or contemporaneous
     *     'explanation' => string,
     *     'is_chronology_inconsistent' => bool
     * ]
     */
    public static function evaluateExperimentalDecay(array $temporalMetadata, $evalTime = null, $halfLifeHours = self::DEFAULT_DECAY_HALF_LIFE_HOURS) {
        $halfLifeHours = (float)$halfLifeHours > 0 ? (float)$halfLifeHours : self::DEFAULT_DECAY_HALF_LIFE_HOURS;

        if (empty($temporalMetadata['sighting_date'])) {
            return [
                'enabled' => false,
                'elapsed_minutes' => null,
                'elapsed_hours' => null,
                'decay_multiplier' => 1.0,
                'explanation' => 'Observation date unrecorded; temporal decay not applicable.',
                'is_chronology_inconsistent' => false
            ];
        }

        $precision = !empty($temporalMetadata['sighting_time_precision']) 
            ? strtolower(trim((string)$temporalMetadata['sighting_time_precision'])) 
            : TemporalService::PRECISION_UNKNOWN;

        // Determine reference evaluation timestamp
        if ($evalTime) {
            $evalTs = strtotime($evalTime);
        } elseif (!empty($temporalMetadata['created_at'])) {
            $evalTs = strtotime($temporalMetadata['created_at']);
        } else {
            $evalTs = time();
        }

        if (!$evalTs) {
            return [
                'enabled' => false,
                'elapsed_minutes' => null,
                'elapsed_hours' => null,
                'decay_multiplier' => 1.0,
                'explanation' => 'Invalid evaluation timestamp; temporal decay bypassed.',
                'is_chronology_inconsistent' => false
            ];
        }

        // If time is unknown, calculate date-level decay
        if (empty($temporalMetadata['sighting_time']) || $precision === TemporalService::PRECISION_UNKNOWN) {
            $sightingDateTs = strtotime($temporalMetadata['sighting_date'] . ' 00:00:00');
            $evalDateTs = strtotime(date('Y-m-d 00:00:00', $evalTs));
            $diffDays = (int)round(($evalDateTs - $sightingDateTs) / 86400);

            if ($diffDays < 0) {
                return [
                    'enabled' => false,
                    'elapsed_minutes' => null,
                    'elapsed_hours' => null,
                    'decay_multiplier' => 1.0,
                    'explanation' => 'Chronology inconsistency: filing date precedes observation date.',
                    'is_chronology_inconsistent' => true
                ];
            }

            if ($diffDays === 0) {
                return [
                    'enabled' => true,
                    'elapsed_minutes' => 0,
                    'elapsed_hours' => 0.0,
                    'decay_multiplier' => 1.0,
                    'explanation' => 'Reported on same day of observation (time unknown); no decay applied.',
                    'is_chronology_inconsistent' => false
                ];
            }

            $elapsedHours = $diffDays * 24.0;
            $decay = pow(2.0, -($elapsedHours / $halfLifeHours));
            return [
                'enabled' => true,
                'elapsed_minutes' => $diffDays * 1440,
                'elapsed_hours' => $elapsedHours,
                'decay_multiplier' => max(0.0, min(1.0, (float)$decay)),
                'explanation' => "Observed {$diffDays} day" . ($diffDays > 1 ? 's' : '') . " prior ({$elapsedHours}h elapsed); decay multiplier: " . round($decay, 3),
                'is_chronology_inconsistent' => false
            ];
        }

        // For exact or approximate observation
        $sightingStr = $temporalMetadata['sighting_date'] . ' ' . $temporalMetadata['sighting_time'];
        $sightingTs = strtotime($sightingStr);
        if (!$sightingTs) {
            return [
                'enabled' => false,
                'elapsed_minutes' => null,
                'elapsed_hours' => null,
                'decay_multiplier' => 1.0,
                'explanation' => 'Malformed sighting timestamp; temporal decay bypassed.',
                'is_chronology_inconsistent' => false
            ];
        }

        $uncertainty = isset($temporalMetadata['sighting_time_uncertainty_minutes']) 
            ? (int)$temporalMetadata['sighting_time_uncertainty_minutes'] 
            : 0;

        $diffSeconds = $evalTs - $sightingTs;
        $diffMinutes = (int)round($diffSeconds / 60.0);

        // Check pre-window chronology inconsistency
        if ($diffMinutes < -$uncertainty) {
            return [
                'enabled' => false,
                'elapsed_minutes' => $diffMinutes,
                'elapsed_hours' => round($diffMinutes / 60.0, 2),
                'decay_multiplier' => 1.0,
                'explanation' => 'Chronology inconsistency: evaluation time precedes observation window.',
                'is_chronology_inconsistent' => true
            ];
        }

        // If inside observation window, elapsed decay is 0
        if ($diffMinutes <= $uncertainty) {
            return [
                'enabled' => true,
                'elapsed_minutes' => 0,
                'elapsed_hours' => 0.0,
                'decay_multiplier' => 1.0,
                'explanation' => 'Observation window is contemporaneous with evaluation time; zero threat decay.',
                'is_chronology_inconsistent' => false
            ];
        }

        // Post-window decay: elapsed time measured from latest window closure
        $postWindowMinutes = $diffMinutes - $uncertainty;
        $elapsedHours = $postWindowMinutes / 60.0;
        $decay = pow(2.0, -($elapsedHours / $halfLifeHours));

        return [
            'enabled' => true,
            'elapsed_minutes' => $postWindowMinutes,
            'elapsed_hours' => round($elapsedHours, 2),
            'decay_multiplier' => max(0.0, min(1.0, (float)$decay)),
            'explanation' => "Observation window closed {$postWindowMinutes}m ago ({$elapsedHours}h elapsed); decay multiplier: " . round($decay, 3),
            'is_chronology_inconsistent' => false
        ];
    }
}
