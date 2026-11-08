<?php
/**
 * Basic Validation Helpers
 */

class Validation {
    
    public static function required($data, $fields) {
        $errors = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || trim($data[$field]) === '') {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            }
        }
        return $errors;
    }

    public static function email($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Invalid email address format.';
        }
        return null;
    }

    public static function minLength($value, $length, $fieldName) {
        if (strlen($value) < $length) {
            return ucfirst($fieldName) . " must be at least {$length} characters long.";
        }
        return null;
    }

    public static function numeric($value, $fieldName) {
        if (!is_numeric($value)) {
            return ucfirst($fieldName) . " must be a numeric value.";
        }
        return null;
    }

    public static function coordinate($latitude, $longitude) {
        $errors = [];
        if ($latitude !== null && $latitude !== '') {
            if (!is_numeric($latitude) || (float)$latitude < -90 || (float)$latitude > 90) {
                $errors['latitude'] = 'Latitude must be a valid number between -90 and 90.';
            }
        }
        if ($longitude !== null && $longitude !== '') {
            if (!is_numeric($longitude) || (float)$longitude < -180 || (float)$longitude > 180) {
                $errors['longitude'] = 'Longitude must be a valid number between -180 and 180.';
            }
        }
        return $errors;
    }
}
