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
}
