<?php
/**
 * Booking Validator
 * Validates booking data and checks restrictions
 */

require_once 'Database.php';
require_once 'Logger.php';

class BookingValidator {
    /**
     * Validate booking data
     */
    public static function validateBookingData(array $data): array {
        $errors = [];

        // Required fields
        $required = ['property_id', 'guest_id', 'check_in', 'check_out'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[] = "Field '$field' is required";
            }
        }

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        // Validate property exists
        if (!self::propertyExists($data['property_id'])) {
            $errors[] = "Property not found";
        }

        // Validate guest exists
        if (!self::guestExists($data['guest_id'])) {
            $errors[] = "Guest not found";
        }

        // Validate dates
        try {
            $checkIn = new DateTime($data['check_in']);
            $checkOut = new DateTime($data['check_out']);
            $today = new DateTime('today');

            if ($checkIn < $today) {
                $errors[] = "Check-in date cannot be in the past";
            }

            if ($checkOut <= $checkIn) {
                $errors[] = "Check-out date must be after check-in date";
            }

            $nights = $checkIn->diff($checkOut)->days;
            if ($nights > 365) {
                $errors[] = "Booking duration cannot exceed 365 days";
            }

        } catch (Exception $e) {
            $errors[] = "Invalid date format";
        }

        // Validate guest numbers
        if (isset($data['number_of_guests'])) {
            if ($data['number_of_guests'] < 1) {
                $errors[] = "Number of guests must be at least 1";
            }
        }

        if (isset($data['number_of_adults'])) {
            if ($data['number_of_adults'] < 1) {
                $errors[] = "Number of adults must be at least 1";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Check if property exists and is active
     */
    private static function propertyExists(int $propertyId): bool {
        $db = Database::getInstance();
        $sql = "SELECT COUNT(*) as count FROM properties WHERE id = ? AND is_active = TRUE";
        $result = $db->query($sql, [$propertyId]);
        return ($result[0]['count'] ?? 0) > 0;
    }

    /**
     * Check if guest exists
     */
    private static function guestExists(int $guestId): bool {
        $db = Database::getInstance();
        $sql = "SELECT COUNT(*) as count FROM guests WHERE id = ? AND is_blacklisted = FALSE";
        $result = $db->query($sql, [$guestId]);
        return ($result[0]['count'] ?? 0) > 0;
    }

    /**
     * Check booking restrictions
     */
    public static function checkRestrictions(
        int $propertyId,
        string $checkIn,
        string $checkOut,
        int $numberOfGuests
    ): array {
        $violations = [];

        try {
            $checkInDate = new DateTime($checkIn);
            $checkOutDate = new DateTime($checkOut);
            $nights = $checkInDate->diff($checkOutDate)->days;

            $db = Database::getInstance();

            // Get all active restrictions for property
            $sql = "SELECT * FROM booking_restrictions
                    WHERE (property_id = ? OR property_id IS NULL)
                        AND is_active = TRUE";

            $restrictions = $db->query($sql, [$propertyId]);

            foreach ($restrictions as $restriction) {
                $violation = self::checkRestriction(
                    $restriction,
                    $checkInDate,
                    $checkOutDate,
                    $nights,
                    $numberOfGuests
                );

                if ($violation) {
                    $violations[] = $violation;
                }
            }

            // Check property max guests
            $sql = "SELECT max_guests FROM properties WHERE id = ?";
            $property = $db->query($sql, [$propertyId]);

            if (!empty($property)) {
                $maxGuests = $property[0]['max_guests'];
                if ($numberOfGuests > $maxGuests) {
                    $violations[] = [
                        'type' => 'max_guests',
                        'message' => sprintf(
                            'Number of guests (%d) exceeds property maximum (%d)',
                            $numberOfGuests,
                            $maxGuests
                        )
                    ];
                }
            }

        } catch (Exception $e) {
            Logger::error('Failed to check restrictions', [
                'property_id' => $propertyId,
                'error' => $e->getMessage()
            ]);

            $violations[] = [
                'type' => 'system_error',
                'message' => 'Failed to validate restrictions'
            ];
        }

        return [
            'valid' => empty($violations),
            'violations' => $violations
        ];
    }

    /**
     * Check individual restriction
     */
    private static function checkRestriction(
        array $restriction,
        DateTime $checkIn,
        DateTime $checkOut,
        int $nights,
        int $numberOfGuests
    ): ?array {
        // Check if restriction applies to these dates
        if ($restriction['start_date'] && $checkIn < new DateTime($restriction['start_date'])) {
            return null;
        }

        if ($restriction['end_date'] && $checkOut > new DateTime($restriction['end_date'])) {
            return null;
        }

        // Check restriction type
        switch ($restriction['restriction_type']) {
            case 'min_stay':
                if ($nights < $restriction['int_value']) {
                    return [
                        'type' => 'min_stay',
                        'message' => sprintf(
                            'Minimum stay is %d nights, you selected %d nights',
                            $restriction['int_value'],
                            $nights
                        )
                    ];
                }
                break;

            case 'max_stay':
                if ($nights > $restriction['int_value']) {
                    return [
                        'type' => 'max_stay',
                        'message' => sprintf(
                            'Maximum stay is %d nights, you selected %d nights',
                            $restriction['int_value'],
                            $nights
                        )
                    ];
                }
                break;

            case 'blackout':
                return [
                    'type' => 'blackout',
                    'message' => 'Selected dates are blocked for booking',
                    'notes' => $restriction['notes']
                ];

            case 'max_guests':
                if ($numberOfGuests > $restriction['int_value']) {
                    return [
                        'type' => 'max_guests',
                        'message' => sprintf(
                            'Maximum %d guests allowed',
                            $restriction['int_value']
                        )
                    ];
                }
                break;

            case 'check_in_days':
                $checkInDay = strtolower($checkIn->format('l'));
                $allowedDays = explode(',', $restriction['days_of_week']);

                if (!in_array($checkInDay, $allowedDays)) {
                    return [
                        'type' => 'check_in_days',
                        'message' => sprintf(
                            'Check-in only allowed on: %s',
                            implode(', ', $allowedDays)
                        )
                    ];
                }
                break;

            case 'check_out_days':
                $checkOutDay = strtolower($checkOut->format('l'));
                $allowedDays = explode(',', $restriction['days_of_week']);

                if (!in_array($checkOutDay, $allowedDays)) {
                    return [
                        'type' => 'check_out_days',
                        'message' => sprintf(
                            'Check-out only allowed on: %s',
                            implode(', ', $allowedDays)
                        )
                    ];
                }
                break;

            case 'advance_booking':
                $daysInAdvance = (new DateTime('today'))->diff($checkIn)->days;

                if ($daysInAdvance > $restriction['int_value']) {
                    return [
                        'type' => 'advance_booking',
                        'message' => sprintf(
                            'Cannot book more than %d days in advance',
                            $restriction['int_value']
                        )
                    ];
                }
                break;
        }

        return null;
    }

    /**
     * Validate guest data
     */
    public static function validateGuestData(array $data): array {
        $errors = [];

        // Required fields
        $required = ['first_name', 'last_name', 'email', 'phone'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[] = "Field '$field' is required";
            }
        }

        // Validate email
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format";
        }

        // Validate phone (basic)
        if (!empty($data['phone']) && !preg_match('/^[\d\s\+\-\(\)]+$/', $data['phone'])) {
            $errors[] = "Invalid phone format";
        }

        // Validate date of birth
        if (!empty($data['date_of_birth'])) {
            try {
                $dob = new DateTime($data['date_of_birth']);
                $today = new DateTime('today');

                if ($dob > $today) {
                    $errors[] = "Date of birth cannot be in the future";
                }

                $age = $today->diff($dob)->y;
                if ($age < 18) {
                    $errors[] = "Guest must be at least 18 years old";
                }
            } catch (Exception $e) {
                $errors[] = "Invalid date of birth format";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate property data
     */
    public static function validatePropertyData(array $data): array {
        $errors = [];

        // Required fields
        $required = ['name', 'address', 'property_type', 'max_guests'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[] = "Field '$field' is required";
            }
        }

        // Validate property type
        $validTypes = ['apartment', 'house', 'room', 'villa', 'studio'];
        if (!empty($data['property_type']) && !in_array($data['property_type'], $validTypes)) {
            $errors[] = "Invalid property type. Allowed: " . implode(', ', $validTypes);
        }

        // Validate numeric fields
        if (isset($data['max_guests']) && $data['max_guests'] < 1) {
            $errors[] = "Max guests must be at least 1";
        }

        if (isset($data['bedrooms']) && $data['bedrooms'] < 0) {
            $errors[] = "Bedrooms cannot be negative";
        }

        if (isset($data['bathrooms']) && $data['bathrooms'] < 0) {
            $errors[] = "Bathrooms cannot be negative";
        }

        if (isset($data['square_meters']) && $data['square_meters'] <= 0) {
            $errors[] = "Square meters must be positive";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}
