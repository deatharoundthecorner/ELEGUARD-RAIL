<?php
/**
 * Risk Assessment Service
 * Calculates the threat category (Low, Medium, High, Critical) for elephant sightings.
 */

class RiskService {

    /**
     * Calculates the numerical risk score and returns corresponding level.
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
        switch (ucfirst(strtolower($sectionRisk))) {
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
        switch (ucfirst(strtolower($urgencyLevel))) {
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
        if ($elephantCount >= 5) {
            $score += 3;
        } elseif ($elephantCount >= 2) {
            $score += 2;
        } elseif ($elephantCount == 1) {
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
        if ($score >= 11) {
            return 'Critical';
        } elseif ($score >= 8) {
            return 'High';
        } elseif ($score >= 5) {
            return 'Medium';
        } else {
            return 'Low';
        }
    }
}
