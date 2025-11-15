<?php
/**
 * Pricing Engine
 * Handles dynamic pricing calculations based on rules
 */

require_once 'Database.php';
require_once 'Logger.php';

class PricingEngine {
    /**
     * Calculate total booking price with all adjustments
     */
    public static function calculateBookingPrice(int $propertyId, string $checkIn, string $checkOut): array {
        try {
            $db = Database::getInstance();

            // Validate dates
            $checkInDate = new DateTime($checkIn);
            $checkOutDate = new DateTime($checkOut);

            if ($checkOutDate <= $checkInDate) {
                return [
                    'success' => false,
                    'error' => 'Check-out date must be after check-in date'
                ];
            }

            $nights = $checkInDate->diff($checkOutDate)->days;

            // Get base price
            $basePrice = self::getBasePrice($propertyId);
            if ($basePrice === null) {
                return [
                    'success' => false,
                    'error' => 'No base price found for property'
                ];
            }

            // Initialize pricing breakdown
            $breakdown = [];
            $totalPrice = 0;

            // Calculate price for each night
            $currentDate = clone $checkInDate;
            $nightPrices = [];

            while ($currentDate < $checkOutDate) {
                $nightPrice = self::calculateNightPrice(
                    $propertyId,
                    $currentDate,
                    $basePrice,
                    $nights
                );

                $nightPrices[] = $nightPrice;
                $totalPrice += $nightPrice['price'];

                $currentDate->modify('+1 day');
            }

            // Add base price to breakdown
            $breakdown[] = [
                'type' => 'base_price',
                'description' => sprintf('Базовая цена (%d ночей × %.2f ₽)', $nights, $basePrice),
                'quantity' => $nights,
                'unit_price' => $basePrice,
                'amount' => $basePrice * $nights
            ];

            // Calculate weekend surcharges
            $weekendNights = self::countWeekendNights($nightPrices);
            if ($weekendNights > 0) {
                $weekendRule = self::getWeekendRule($propertyId);
                if ($weekendRule) {
                    $surcharge = self::calculateAdjustment(
                        $basePrice * $weekendNights,
                        $weekendRule['adjustment_type'],
                        $weekendRule['adjustment_value']
                    );

                    if ($surcharge > 0) {
                        $breakdown[] = [
                            'type' => 'weekend_surcharge',
                            'description' => sprintf('Наценка за выходные (%d ночей)', $weekendNights),
                            'quantity' => $weekendNights,
                            'unit_price' => $surcharge / $weekendNights,
                            'amount' => $surcharge
                        ];
                        $totalPrice += $surcharge;
                    }
                }
            }

            // Apply length of stay discounts
            $lengthDiscount = self::getLengthOfStayDiscount($propertyId, $nights);
            if ($lengthDiscount) {
                $discountAmount = self::calculateAdjustment(
                    $totalPrice,
                    $lengthDiscount['adjustment_type'],
                    $lengthDiscount['adjustment_value']
                );

                if ($discountAmount > 0) {
                    $breakdown[] = [
                        'type' => 'length_discount',
                        'description' => sprintf('Скидка за длительность (%d+ ночей)', $nights),
                        'quantity' => 1,
                        'unit_price' => -$discountAmount,
                        'amount' => -$discountAmount
                    ];
                    $totalPrice -= $discountAmount;
                }
            }

            // Apply seasonal adjustments
            $seasonalRule = self::getSeasonalRule($propertyId, $checkInDate);
            if ($seasonalRule) {
                $adjustment = self::calculateAdjustment(
                    $totalPrice,
                    $seasonalRule['adjustment_type'],
                    $seasonalRule['adjustment_value']
                );

                if ($adjustment != 0) {
                    $breakdown[] = [
                        'type' => 'seasonal_adjustment',
                        'description' => $seasonalRule['rule_name'],
                        'quantity' => 1,
                        'unit_price' => $adjustment,
                        'amount' => $adjustment
                    ];
                    $totalPrice += $adjustment;
                }
            }

            Logger::debug('Pricing calculated', [
                'property_id' => $propertyId,
                'nights' => $nights,
                'base_price' => $basePrice,
                'total_price' => $totalPrice
            ]);

            return [
                'success' => true,
                'base_price' => $basePrice,
                'total_price' => round($totalPrice, 2),
                'nights' => $nights,
                'price_per_night' => round($totalPrice / $nights, 2),
                'breakdown' => $breakdown,
                'night_prices' => $nightPrices
            ];

        } catch (Exception $e) {
            Logger::error('Pricing calculation failed', [
                'property_id' => $propertyId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get base price for property
     */
    private static function getBasePrice(int $propertyId): ?float {
        $db = Database::getInstance();

        $sql = "SELECT price_per_night
                FROM pricing_rules
                WHERE property_id = ? AND rule_type = 'base' AND is_active = TRUE
                ORDER BY priority DESC
                LIMIT 1";

        $result = $db->query($sql, [$propertyId]);

        return $result[0]['price_per_night'] ?? null;
    }

    /**
     * Calculate price for a single night
     */
    private static function calculateNightPrice(
        int $propertyId,
        DateTime $date,
        float $basePrice,
        int $totalNights
    ): array {
        $dayOfWeek = strtolower($date->format('l'));
        $isWeekend = in_array($dayOfWeek, ['saturday', 'sunday']);

        return [
            'date' => $date->format('Y-m-d'),
            'day_of_week' => $dayOfWeek,
            'is_weekend' => $isWeekend,
            'price' => $basePrice
        ];
    }

    /**
     * Count weekend nights
     */
    private static function countWeekendNights(array $nightPrices): int {
        return count(array_filter($nightPrices, function($night) {
            return $night['is_weekend'];
        }));
    }

    /**
     * Get weekend pricing rule
     */
    private static function getWeekendRule(int $propertyId): ?array {
        $db = Database::getInstance();

        $sql = "SELECT *
                FROM pricing_rules
                WHERE property_id = ? AND rule_type = 'weekend' AND is_active = TRUE
                ORDER BY priority DESC
                LIMIT 1";

        $result = $db->query($sql, [$propertyId]);

        return $result[0] ?? null;
    }

    /**
     * Get length of stay discount
     */
    private static function getLengthOfStayDiscount(int $propertyId, int $nights): ?array {
        $db = Database::getInstance();

        $sql = "SELECT *
                FROM pricing_rules
                WHERE property_id = ? AND rule_type = 'length_of_stay'
                    AND is_active = TRUE
                    AND (min_stay_nights IS NULL OR min_stay_nights <= ?)
                ORDER BY priority DESC, min_stay_nights DESC
                LIMIT 1";

        $result = $db->query($sql, [$propertyId, $nights]);

        return $result[0] ?? null;
    }

    /**
     * Get seasonal pricing rule
     */
    private static function getSeasonalRule(int $propertyId, DateTime $date): ?array {
        $db = Database::getInstance();

        $dateStr = $date->format('Y-m-d');

        $sql = "SELECT *
                FROM pricing_rules
                WHERE property_id = ? AND rule_type = 'seasonal'
                    AND is_active = TRUE
                    AND (start_date IS NULL OR start_date <= ?)
                    AND (end_date IS NULL OR end_date >= ?)
                ORDER BY priority DESC
                LIMIT 1";

        $result = $db->query($sql, [$propertyId, $dateStr, $dateStr]);

        return $result[0] ?? null;
    }

    /**
     * Calculate adjustment based on type and value
     */
    private static function calculateAdjustment(
        float $baseAmount,
        string $adjustmentType,
        float $adjustmentValue
    ): float {
        switch ($adjustmentType) {
            case 'fixed':
                return $adjustmentValue;

            case 'percentage':
                return $baseAmount * ($adjustmentValue / 100);

            case 'multiplier':
                return $baseAmount * ($adjustmentValue - 1);

            default:
                return 0;
        }
    }

    /**
     * Get pricing preview for date range
     */
    public static function getPricingPreview(int $propertyId, string $startDate, string $endDate): array {
        try {
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);

            $preview = [];
            $current = clone $start;

            while ($current <= $end) {
                $nextDay = clone $current;
                $nextDay->modify('+1 day');

                $pricing = self::calculateBookingPrice(
                    $propertyId,
                    $current->format('Y-m-d'),
                    $nextDay->format('Y-m-d')
                );

                $preview[] = [
                    'date' => $current->format('Y-m-d'),
                    'day_of_week' => $current->format('l'),
                    'price' => $pricing['success'] ? $pricing['total_price'] : null,
                    'base_price' => $pricing['success'] ? $pricing['base_price'] : null
                ];

                $current->modify('+1 day');
            }

            return [
                'success' => true,
                'property_id' => $propertyId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'preview' => $preview
            ];

        } catch (Exception $e) {
            Logger::error('Failed to generate pricing preview', [
                'property_id' => $propertyId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
