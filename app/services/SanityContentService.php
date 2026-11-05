<?php
/**
 * Sanity Content Service
 * Provides public static content for landing, safety, faq, awareness, and contacts pages.
 * Handles graceful fallback to local data arrays if Sanity client returns null.
 */

require_once __DIR__ . '/../includes/sanity_client.php';

class SanityContentService {

    /**
     * Fetch landing page content
     */
    public static function getLandingPage() {
        $query = '*[_type == "landingPage"][0]';
        $result = SanityClient::query($query);
        
        if ($result) {
            return $result;
        }

        // Fallback static data
        return [
            'heroTitle' => "EleGuard Rail Sri Lanka",
            'heroSubtitle' => "Preventing Elephant-Train Collisions Through Early Warnings",
            'description' => "Sri Lanka is home to thousands of majestic Asian elephants. Unfortunately, many are killed or injured in collisions with trains, which also poses significant safety risks to train drivers and passengers. EleGuard Rail bridges villagers, wildlife rangers, and railway control officers to prevent these collisions.",
            'primaryButtonText' => "Report Elephant Sighting",
            'secondaryButtonText' => "Read Safety Rules",
            'updatedAt' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Fetch active safety instructions sorted by order
     */
    public static function getSafetyInstructions() {
        $query = '*[_type == "safetyInstruction" && isActive == true] | order(displayOrder asc)';
        $result = SanityClient::query($query);
        
        if ($result && is_array($result)) {
            return $result;
        }

        // Fallback static data
        return [
            [
                'title' => "Keep Clear of Tracks in Migration Hotspots",
                'body' => "Never walk, loiter, or tether livestock on or near railway lines in known elephant movement corridors. Sound travels poorly around jungle bends.",
                'priorityLevel' => "Critical",
                'displayOrder' => 1
            ],
            [
                'title' => "Do Not Approach or Feed Wild Elephants",
                'body' => "Approaching elephants to feed or photograph them near tracks confuses them and makes them linger longer on dangerous crossings.",
                'priorityLevel' => "High",
                'displayOrder' => 2
            ],
            [
                'title' => "Report Sightings Promptly",
                'body' => "If you see an elephant within 500 meters of a track, log in to EleGuard Rail or call the wildlife department immediately with the exact section location.",
                'priorityLevel' => "Medium",
                'displayOrder' => 3
            ]
        ];
    }

    /**
     * Fetch active FAQs
     */
    public static function getFaqs() {
        $query = '*[_type == "faq" && isActive == true] | order(displayOrder asc)';
        $result = SanityClient::query($query);
        
        if ($result && is_array($result)) {
            return $result;
        }

        // Fallback static data
        return [
            [
                'question' => "What is EleGuard Rail?",
                'answer' => "EleGuard Rail is a web-based early warning and railway monitoring platform designed to reduce elephant-train collisions in Sri Lanka by sharing real-time movement reports and driver alerts."
            ],
            [
                'question' => "Who can submit an elephant sighting report?",
                'answer' => "Any registered reporter, villager, railway worker, or wildlife officer can submit a report containing the section, location description, elephant count, and urgency level."
            ],
            [
                'question' => "How do train drivers receive warnings?",
                'answer' => "Train drivers have a dedicated mobile-friendly dashboard that rings an audible beep and displays large color-coded alerts when active critical hazards are logged in their section."
            ],
            [
                'question' => "Is there an offline mode for train drivers?",
                'answer' => "While the current version is an online web MVP, future upgrades plan to introduce GPS-based location tracking and offline driver notifications."
            ]
        ];
    }

    /**
     * Fetch published awareness articles
     */
    public static function getAwarenessArticles() {
        $query = '*[_type == "awarenessArticle" && isPublished == true] | order(publishedDate desc)';
        $result = SanityClient::query($query);
        
        if ($result && is_array($result)) {
            return $result;
        }

        // Fallback static data
        return [
            [
                'title' => "Understanding Sri Lanka's Seasonal Migratory Corridors",
                'summary' => "Why elephants cross tracks more frequently during dry seasons and where they cross.",
                'body' => "During the dry months between July and October, water bodies dry up in many parts of the North Central and Eastern provinces. Elephants migrate towards large reservoirs such as the Minneriya Tank. Unfortunately, major railway lines cut directly through these traditional corridors, causing high-risk intersections.",
                'publishedDate' => "2026-06-20",
                'coverImage' => null
            ],
            [
                'title' => "Coexistence and Innovation: The EleGuard Rail Mission",
                'summary' => "How community reporting and technology can work together to save wildlife.",
                'body' => "Preventing wildlife-train collisions requires a combination of rapid community response and railway coordination. By empowering local villagers and railway guards to submit alerts directly, we can notify train drivers miles before they reach a danger zone, allowing them to slow down safely without causing track delays.",
                'publishedDate' => "2026-06-18",
                'coverImage' => null
            ]
        ];
    }

    /**
     * Fetch active emergency contacts
     */
    public static function getEmergencyContacts() {
        $query = '*[_type == "emergencyContact" && isActive == true] | order(displayOrder asc)';
        $result = SanityClient::query($query);
        
        if ($result && is_array($result)) {
            return $result;
        }

        // Fallback static data
        return [
            [
                'organizationName' => "Department of Wildlife Conservation (DWC)",
                'phoneNumber' => "1992",
                'district' => "National / Colombo Office",
                'description' => "Emergency wildlife conservation helpline for elephant rescues and human-elephant conflicts."
            ],
            [
                'organizationName' => "Sri Lanka Railway Control Center",
                'phoneNumber' => "+94 11 242 1281",
                'district' => "National / Colombo Fort",
                'description' => "Main central railway operational desk for train drivers and track reporting."
            ],
            [
                'organizationName' => "Habarana Wildlife Ranger Office",
                'phoneNumber' => "+94 66 228 6250",
                'district' => "Anuradhapura/Polonnaruwa",
                'description' => "Local wildlife ranger office responsible for the Critical Habarana-Gal Oya sector."
            ],
            [
                'organizationName' => "Minneriya Wildlife Ranger Office",
                'phoneNumber' => "+94 27 222 4856",
                'district' => "Polonnaruwa",
                'description' => "Ranger unit managing the Minneriya national park perimeter."
            ]
        ];
    }
}
