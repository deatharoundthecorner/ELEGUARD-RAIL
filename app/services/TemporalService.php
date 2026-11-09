<?php
/**
 * EleGuard Rail - Temporal Validation & Intelligence Service
 * 
 * Reusable backend service for managing, validating, and normalizing elephant sighting
 * timestamps, approximate diurnal observation windows, uncertainty intervals,
 * and GPS hardware acquisition timestamps in the Asia/Colombo timezone.
 * 
 * Implemented in Milestone 9C — Stage 2A.
 */

class TemporalService {

    const TIMEZONE = 'Asia/Colombo';

    const PRECISION_EXACT = 'exact';
    const PRECISION_APPROXIMATE = 'approximate';
    const PRECISION_UNKNOWN = 'unknown';

    const WINDOW_DAWN = 'dawn';
    const WINDOW_MORNING = 'morning';
    const WINDOW_AFTERNOON = 'afternoon';
    const WINDOW_DUSK = 'dusk';
    const WINDOW_NIGHT = 'night';
    const WINDOW_CUSTOM = 'custom';

    /**
     * Standard diurnal ecological presets:
     * - Dawn:      05:30:00 reference, ±60 min uncertainty  (04:30 - 06:30)
     * - Morning:   09:00:00 reference, ±90 min uncertainty  (07:30 - 10:30)
     * - Afternoon: 14:30:00 reference, ±90 min uncertainty  (13:00 - 16:00)
     * - Dusk:      18:15:00 reference, ±45 min uncertainty  (17:30 - 19:00)
     * - Night:     22:00:00 reference, ±120 min uncertainty (20:00 - 00:00)
     */
    const PRESETS = [
        self::WINDOW_DAWN => [
            'time' => '05:30:00',
            'uncertainty' => 60,
            'label' => 'Dawn (04:30 – 06:30)'
        ],
        self::WINDOW_MORNING => [
            'time' => '09:00:00',
            'uncertainty' => 90,
            'label' => 'Morning (07:30 – 10:30)'
        ],
        self::WINDOW_AFTERNOON => [
            'time' => '14:30:00',
            'uncertainty' => 90,
            'label' => 'Afternoon (13:00 – 16:00)'
        ],
        self::WINDOW_DUSK => [
            'time' => '18:15:00',
            'uncertainty' => 45,
            'label' => 'Dusk (17:30 – 19:00)'
        ],
        self::WINDOW_NIGHT => [
            'time' => '22:00:00',
            'uncertainty' => 120,
            'label' => 'Night (20:00 – 00:00)'
        ]
    ];

    const EXACT_UNCERTAINTY_MINUTES = 5;
    const CUSTOM_UNCERTAINTY_MIN = 15;
    const CUSTOM_UNCERTAINTY_MAX = 180;

    // Minimum plausible epoch timestamp: 2020-01-01 00:00:00 UTC
    const GPS_MIN_TIMESTAMP = 1577836800;
    // Allow up to 300 seconds (5 minutes) client clock skew into future
    const GPS_FUTURE_SKEW_SECONDS = 300;

    /**
     * Get all approximate presets
     */
    public static function getPresets() {
        return self::PRESETS;
    }

    /**
     * Get a specific preset definition
     */
    public static function getPreset($window) {
        $window = strtolower(trim((string)$window));
        return self::PRESETS[$window] ?? null;
    }

    /**
     * Returns current DateTime object anchored to Asia/Colombo
     */
    public static function getNow() {
        return new DateTime('now', new DateTimeZone(self::TIMEZONE));
    }

    /**
     * Validate calendar date format and ensures it is not in the future.
     * 
     * @param string $dateStr Date string (YYYY-MM-DD)
     * @return array ['valid' => bool, 'error' => string|null, 'date' => string|null]
     */
    public static function validateDate($dateStr) {
        if ($dateStr === null || trim((string)$dateStr) === '') {
            return ['valid' => false, 'error' => 'Sighting date is required.', 'date' => null];
        }

        $trimmed = trim((string)$dateStr);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            return ['valid' => false, 'error' => 'Sighting date must be in YYYY-MM-DD format.', 'date' => null];
        }

        list($y, $m, $d) = explode('-', $trimmed);
        if (!checkdate((int)$m, (int)$d, (int)$y)) {
            return ['valid' => false, 'error' => "Invalid calendar date: '{$trimmed}'.", 'date' => null];
        }

        $today = self::getNow()->format('Y-m-d');
        if ($trimmed > $today) {
            return ['valid' => false, 'error' => "Sighting date cannot be in the future (today is {$today}).", 'date' => null];
        }

        return ['valid' => true, 'error' => null, 'date' => $trimmed];
    }

    /**
     * Validate clock time format (HH:MM or HH:MM:SS) and normalize to HH:MM:SS.
     * 
     * @param string $timeStr Time string
     * @return array ['valid' => bool, 'error' => string|null, 'time' => string|null]
     */
    public static function validateTime($timeStr) {
        if ($timeStr === null || trim((string)$timeStr) === '') {
            return ['valid' => false, 'error' => 'Sighting time is required.', 'time' => null];
        }

        $trimmed = trim((string)$timeStr);

        // Accept HH:MM or HH:MM:SS
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $trimmed, $matches)) {
            $h = (int)$matches[1];
            $m = (int)$matches[2];
            $s = isset($matches[3]) ? (int)$matches[3] : 0;

            if ($h < 0 || $h > 23 || $m < 0 || $m > 59 || $s < 0 || $s > 59) {
                return ['valid' => false, 'error' => "Invalid clock time: '{$trimmed}'.", 'time' => null];
            }

            $normalized = sprintf('%02d:%02d:%02d', $h, $m, $s);
            return ['valid' => true, 'error' => null, 'time' => $normalized];
        }

        return ['valid' => false, 'error' => "Sighting time must be in HH:MM or HH:MM:SS format (got '{$trimmed}').", 'time' => null];
    }

    /**
     * Validate that a combined date and time is not in the future in Asia/Colombo.
     * Includes a 60-second grace window to tolerate submission transit latency.
     */
    public static function validateDateTimeNotFuture($dateStr, $timeStr) {
        $dateRes = self::validateDate($dateStr);
        if (!$dateRes['valid']) {
            return $dateRes;
        }

        $timeRes = self::validateTime($timeStr);
        if (!$timeRes['valid']) {
            return $timeRes;
        }

        $now = self::getNow();
        $dtStr = $dateRes['date'] . ' ' . $timeRes['time'];
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dtStr, new DateTimeZone(self::TIMEZONE));

        // Allow 60 seconds grace
        $nowTimestamp = $now->getTimestamp();
        $dtTimestamp = $dt->getTimestamp();

        if ($dtTimestamp > ($nowTimestamp + 60)) {
            return [
                'valid' => false,
                'error' => "Sighting date and time cannot be in the future (current time in Colombo is " . $now->format('Y-m-d H:i') . ").",
                'datetime' => null
            ];
        }

        return ['valid' => true, 'error' => null, 'datetime' => $dtStr];
    }

    /**
     * Convert and validate W3C browser geolocation epoch timestamp (or ISO string)
     * into Asia/Colombo MySQL DATETIME format (YYYY-MM-DD HH:MM:SS).
     * 
     * Storage Convention:
     * - Stored as MySQL DATETIME in the 'Asia/Colombo' timezone (UTC+05:30).
     * - Automatically detects milliseconds (DOMTimeStamp >= 10^11) vs seconds.
     * - Asserts plausibility: >= 2020-01-01 and <= now + 300s.
     * 
     * @param int|float|string|null $rawTimestamp Browser position.timestamp or string
     * @return array ['valid' => bool, 'error' => string|null, 'datetime' => string|null]
     */
    public static function parseGpsTimestamp($rawTimestamp) {
        if ($rawTimestamp === null || trim((string)$rawTimestamp) === '') {
            return ['valid' => true, 'error' => null, 'datetime' => null];
        }

        $val = trim((string)$rawTimestamp);

        // Case 1: Numeric epoch timestamp (seconds or milliseconds)
        if (is_numeric($val)) {
            $num = (float)$val;
            // Detect milliseconds (W3C DOMTimeStamp is typically >= 10^11, e.g. 1762657445000)
            if ($num >= 100000000000) {
                $epochSeconds = (int)round($num / 1000);
            } else {
                $epochSeconds = (int)round($num);
            }

            // Plausibility checks
            if ($epochSeconds < self::GPS_MIN_TIMESTAMP) {
                return ['valid' => false, 'error' => "GPS timestamp is implausibly early (prior to 2020-01-01).", 'datetime' => null];
            }

            $currentEpoch = time();
            if ($epochSeconds > ($currentEpoch + self::GPS_FUTURE_SKEW_SECONDS)) {
                return ['valid' => false, 'error' => "GPS timestamp is in the future.", 'datetime' => null];
            }

            $dt = new DateTime('@' . $epochSeconds);
            $dt->setTimezone(new DateTimeZone(self::TIMEZONE));
            return ['valid' => true, 'error' => null, 'datetime' => $dt->format('Y-m-d H:i:s')];
        }

        // Case 2: Pre-formatted date-time string (e.g. '2026-11-09 08:30:00' or ISO 8601)
        try {
            $dt = new DateTime($val, new DateTimeZone(self::TIMEZONE));
            $epochSeconds = $dt->getTimestamp();

            if ($epochSeconds < self::GPS_MIN_TIMESTAMP) {
                return ['valid' => false, 'error' => "GPS timestamp is implausibly early (prior to 2020-01-01).", 'datetime' => null];
            }
            if ($epochSeconds > (time() + self::GPS_FUTURE_SKEW_SECONDS)) {
                return ['valid' => false, 'error' => "GPS timestamp is in the future.", 'datetime' => null];
            }

            return ['valid' => true, 'error' => null, 'datetime' => $dt->format('Y-m-d H:i:s')];
        } catch (Exception $e) {
            return ['valid' => false, 'error' => "Malformed GPS timestamp: '{$val}'.", 'datetime' => null];
        }
    }

    /**
     * Master validation and normalization engine for report temporal metadata.
     * 
     * Supported precision modes:
     * - 'exact': Requires sighting_date and sighting_time. Uncertainty fixed to 5m. Window MUST be NULL.
     * - 'approximate': Requires sighting_date and sighting_time_window.
     *                  If window is preset ('dawn', 'morning', 'afternoon', 'dusk', 'night'),
     *                  populates reference time and uncertainty from PRESETS.
     *                  If window is 'custom', requires user reference time and uncertainty (15-180m).
     * - 'unknown': sighting_time, window, and uncertainty MUST be NULL. sighting_date is optional.
     *              NEVER fabricates midnight (00:00:00) or current timestamp.
     * 
     * @param array $input Raw temporal fields
     * @return array [
     *     'valid' => bool,
     *     'errors' => array,
     *     'data' => [
     *         'sighting_date' => string|null,
     *         'sighting_time' => string|null,
     *         'sighting_time_precision' => string,
     *         'sighting_time_window' => string|null,
     *         'sighting_time_uncertainty_minutes' => int|null,
     *         'sighting_timezone' => string,
     *         'gps_captured_at' => string|null
     *     ]
     * ]
     */
    public static function validateAndNormalizeTemporalInput(array $input = []) {
        $errors = [];

        // Raw input fields
        $rawDate = isset($input['sighting_date']) ? trim((string)$input['sighting_date']) : null;
        $rawTime = isset($input['sighting_time']) ? trim((string)$input['sighting_time']) : null;
        $rawPrecision = isset($input['sighting_time_precision']) ? strtolower(trim((string)$input['sighting_time_precision'])) : null;
        $rawWindow = isset($input['sighting_time_window']) ? strtolower(trim((string)$input['sighting_time_window'])) : null;
        $rawUncertainty = isset($input['sighting_time_uncertainty_minutes']) && $input['sighting_time_uncertainty_minutes'] !== '' && $input['sighting_time_uncertainty_minutes'] !== null 
            ? (int)$input['sighting_time_uncertainty_minutes'] 
            : null;

        $rawGpsCaptured = $input['gps_captured_at'] ?? $input['gps_timestamp'] ?? null;

        // Empty string normalizations
        if ($rawDate === '') $rawDate = null;
        if ($rawTime === '') $rawTime = null;
        if ($rawPrecision === '') $rawPrecision = null;
        if ($rawWindow === '') $rawWindow = null;

        // 1. Infer precision if omitted
        if ($rawPrecision === null) {
            if ($rawWindow !== null) {
                $rawPrecision = self::PRECISION_APPROXIMATE;
            } elseif ($rawTime !== null && $rawDate !== null) {
                $rawPrecision = self::PRECISION_EXACT;
            } else {
                $rawPrecision = self::PRECISION_UNKNOWN;
            }
        }

        // Validate precision enum value
        $allowedPrecisions = [self::PRECISION_EXACT, self::PRECISION_APPROXIMATE, self::PRECISION_UNKNOWN];
        if (!in_array($rawPrecision, $allowedPrecisions, true)) {
            $errors['sighting_time_precision'] = "Invalid precision '{$rawPrecision}'. Allowed: exact, approximate, unknown.";
            $rawPrecision = self::PRECISION_UNKNOWN;
        }

        $outDate = null;
        $outTime = null;
        $outPrecision = $rawPrecision;
        $outWindow = null;
        $outUncertainty = null;
        $outTimezone = self::TIMEZONE;
        $outGpsCaptured = null;

        // 2. Validate GPS timestamp if provided
        if ($rawGpsCaptured !== null && trim((string)$rawGpsCaptured) !== '') {
            $gpsRes = self::parseGpsTimestamp($rawGpsCaptured);
            if (!$gpsRes['valid']) {
                $errors['gps_captured_at'] = $gpsRes['error'];
            } else {
                $outGpsCaptured = $gpsRes['datetime'];
            }
        }

        // 3. Precision-specific validation
        switch ($outPrecision) {
            case self::PRECISION_EXACT:
                // Window MUST NOT be set for exact precision
                if ($rawWindow !== null) {
                    $errors['sighting_time_window'] = "Exact precision sightings cannot specify an approximate diurnal window ('{$rawWindow}').";
                }

                // Date is required
                $dateCheck = self::validateDate($rawDate);
                if (!$dateCheck['valid']) {
                    $errors['sighting_date'] = $dateCheck['error'];
                } else {
                    $outDate = $dateCheck['date'];
                }

                // Time is required
                $timeCheck = self::validateTime($rawTime);
                if (!$timeCheck['valid']) {
                    $errors['sighting_time'] = $timeCheck['error'];
                } else {
                    $outTime = $timeCheck['time'];
                }

                // If both date and time passed individual validation, check future constraint
                if ($dateCheck['valid'] && $timeCheck['valid']) {
                    $futureCheck = self::validateDateTimeNotFuture($outDate, $outTime);
                    if (!$futureCheck['valid']) {
                        $errors['sighting_time'] = $futureCheck['error'];
                    }
                }

                // Policy fixed uncertainty for exact sighting
                $outUncertainty = self::EXACT_UNCERTAINTY_MINUTES;
                break;

            case self::PRECISION_APPROXIMATE:
                // Date is required
                $dateCheck = self::validateDate($rawDate);
                if (!$dateCheck['valid']) {
                    $errors['sighting_date'] = $dateCheck['error'];
                } else {
                    $outDate = $dateCheck['date'];
                }

                // Window is required
                $allowedWindows = [
                    self::WINDOW_DAWN,
                    self::WINDOW_MORNING,
                    self::WINDOW_AFTERNOON,
                    self::WINDOW_DUSK,
                    self::WINDOW_NIGHT,
                    self::WINDOW_CUSTOM
                ];

                if ($rawWindow === null || !in_array($rawWindow, $allowedWindows, true)) {
                    $errors['sighting_time_window'] = "Approximate precision requires a valid diurnal window: " . implode(', ', $allowedWindows) . ".";
                } else {
                    $outWindow = $rawWindow;

                    if ($rawWindow === self::WINDOW_CUSTOM) {
                        // Custom window requires user reference time and uncertainty
                        $timeCheck = self::validateTime($rawTime);
                        if (!$timeCheck['valid']) {
                            $errors['sighting_time'] = "Custom approximate window requires a valid reference time: " . $timeCheck['error'];
                        } else {
                            $outTime = $timeCheck['time'];
                        }

                        if ($rawUncertainty === null) {
                            $errors['sighting_time_uncertainty_minutes'] = "Custom approximate window requires uncertainty in minutes (" . self::CUSTOM_UNCERTAINTY_MIN . " - " . self::CUSTOM_UNCERTAINTY_MAX . ").";
                        } elseif ($rawUncertainty < self::CUSTOM_UNCERTAINTY_MIN || $rawUncertainty > self::CUSTOM_UNCERTAINTY_MAX) {
                            $errors['sighting_time_uncertainty_minutes'] = "Custom uncertainty must be between " . self::CUSTOM_UNCERTAINTY_MIN . " and " . self::CUSTOM_UNCERTAINTY_MAX . " minutes (got {$rawUncertainty}).";
                        } else {
                            $outUncertainty = $rawUncertainty;
                        }

                        // Check future if date and time valid
                        if ($dateCheck['valid'] && isset($timeCheck) && $timeCheck['valid']) {
                            $futureCheck = self::validateDateTimeNotFuture($outDate, $outTime);
                            if (!$futureCheck['valid']) {
                                $errors['sighting_time'] = $futureCheck['error'];
                            }
                        }
                    } else {
                        // Standard named preset
                        $preset = self::PRESETS[$rawWindow];
                        $outTime = $preset['time'];
                        $outUncertainty = $preset['uncertainty'];

                        // If sighting date is today, verify the preset reference time is not in the future
                        if ($dateCheck['valid']) {
                            $futureCheck = self::validateDateTimeNotFuture($outDate, $outTime);
                            if (!$futureCheck['valid']) {
                                $errors['sighting_time_window'] = "Cannot report preset window '{$rawWindow}' ({$outTime}) for today because that diurnal period has not occurred yet.";
                            }
                        }
                    }
                }
                break;

            case self::PRECISION_UNKNOWN:
            default:
                // In unknown precision:
                // Date may be optionally known (date known, time unknown) OR null (both unknown)
                if ($rawDate !== null) {
                    $dateCheck = self::validateDate($rawDate);
                    if (!$dateCheck['valid']) {
                        $errors['sighting_date'] = $dateCheck['error'];
                    } else {
                        $outDate = $dateCheck['date'];
                    }
                }

                // Time, window, and uncertainty MUST BE NULL for unknown precision
                if ($rawTime !== null) {
                    $errors['sighting_time'] = "Unknown precision cannot specify a sighting time. Use 'exact' or 'approximate' precision instead.";
                }
                if ($rawWindow !== null) {
                    $errors['sighting_time_window'] = "Unknown precision cannot specify a diurnal window. Use 'approximate' precision instead.";
                }
                if ($rawUncertainty !== null) {
                    $errors['sighting_time_uncertainty_minutes'] = "Unknown precision cannot specify an uncertainty interval.";
                }

                $outTime = null;
                $outWindow = null;
                $outUncertainty = null;
                break;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => [
                'sighting_date' => $outDate,
                'sighting_time' => $outTime,
                'sighting_time_precision' => $outPrecision,
                'sighting_time_window' => $outWindow,
                'sighting_time_uncertainty_minutes' => $outUncertainty,
                'sighting_timezone' => $outTimezone,
                'gps_captured_at' => $outGpsCaptured
            ]
        ];
    }
}
