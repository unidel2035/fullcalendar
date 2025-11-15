<?php
/**
 * Booking Controller
 * Handles all booking-related API operations
 */

require_once 'Database.php';
require_once 'Logger.php';
require_once 'PricingEngine.php';
require_once 'BookingValidator.php';

class BookingController {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Create a new booking
     */
    public function create(array $data): array {
        try {
            // Validate input
            $validation = BookingValidator::validateBookingData($data);
            if (!$validation['valid']) {
                return $this->error('Validation failed', $validation['errors'], 400);
            }

            // Extract data
            $propertyId = $data['property_id'];
            $guestId = $data['guest_id'];
            $checkIn = $data['check_in'];
            $checkOut = $data['check_out'];
            $numberOfGuests = $data['number_of_guests'] ?? 1;
            $numberOfAdults = $data['number_of_adults'] ?? $numberOfGuests;
            $numberOfChildren = $data['number_of_children'] ?? 0;
            $specialRequests = $data['special_requests'] ?? null;

            // Check availability
            $availability = $this->checkAvailability($propertyId, $checkIn, $checkOut);
            if (!$availability['available']) {
                return $this->error('Property not available for selected dates', [
                    'conflict' => $availability['conflict']
                ], 409);
            }

            // Validate restrictions
            $restrictions = BookingValidator::checkRestrictions($propertyId, $checkIn, $checkOut, $numberOfGuests);
            if (!$restrictions['valid']) {
                return $this->error('Booking violates restrictions', $restrictions['violations'], 400);
            }

            // Calculate pricing
            $pricing = PricingEngine::calculateBookingPrice($propertyId, $checkIn, $checkOut);

            if (!$pricing['success']) {
                return $this->error('Failed to calculate pricing', ['error' => $pricing['error']], 500);
            }

            // Begin transaction
            $this->db->beginTransaction();

            try {
                // Insert booking
                $sql = "INSERT INTO bookings
                        (property_id, guest_id, check_in, check_out, number_of_guests,
                         number_of_adults, number_of_children, base_price, total_price,
                         currency, status, special_requests, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)";

                $params = [
                    $propertyId,
                    $guestId,
                    $checkIn,
                    $checkOut,
                    $numberOfGuests,
                    $numberOfAdults,
                    $numberOfChildren,
                    $pricing['base_price'],
                    $pricing['total_price'],
                    DEFAULT_CURRENCY,
                    $specialRequests,
                    $_SESSION['user_id'] ?? null
                ];

                $this->db->execute($sql, $params);
                $bookingId = (int) $this->db->lastInsertId();

                // Insert price breakdown
                foreach ($pricing['breakdown'] as $item) {
                    $sql = "INSERT INTO booking_price_breakdown
                            (booking_id, line_item_type, description, quantity, unit_price, amount)
                            VALUES (?, ?, ?, ?, ?, ?)";

                    $this->db->execute($sql, [
                        $bookingId,
                        $item['type'],
                        $item['description'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['amount']
                    ]);
                }

                $this->db->commit();

                // Log audit
                Logger::auditBooking(
                    $bookingId,
                    'create',
                    'bookings',
                    $bookingId,
                    null,
                    [
                        'property_id' => $propertyId,
                        'guest_id' => $guestId,
                        'check_in' => $checkIn,
                        'check_out' => $checkOut,
                        'total_price' => $pricing['total_price']
                    ]
                );

                Logger::info('Booking created successfully', [
                    'booking_id' => $bookingId,
                    'property_id' => $propertyId,
                    'guest_id' => $guestId
                ]);

                // Get full booking details
                $booking = $this->getById($bookingId);

                return $this->success('Booking created successfully', $booking['data']);

            } catch (Exception $e) {
                $this->db->rollback();
                throw $e;
            }

        } catch (Exception $e) {
            Logger::error('Failed to create booking', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);

            return $this->error('Failed to create booking', [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get booking by ID
     */
    public function getById(int $id): array {
        try {
            $sql = "SELECT
                        b.*,
                        p.name as property_name,
                        p.address as property_address,
                        p.property_type,
                        CONCAT(g.first_name, ' ', g.last_name) as guest_name,
                        g.email as guest_email,
                        g.phone as guest_phone,
                        g.telegram_id as guest_telegram_id,
                        DATEDIFF(b.check_out, b.check_in) as nights,
                        DATEDIFF(b.check_in, CURDATE()) as days_until_checkin
                    FROM bookings b
                    INNER JOIN properties p ON b.property_id = p.id
                    INNER JOIN guests g ON b.guest_id = g.id
                    WHERE b.id = ?";

            $result = $this->db->query($sql, [$id]);

            if (empty($result)) {
                return $this->error('Booking not found', null, 404);
            }

            $booking = $result[0];

            // Get price breakdown
            $sql = "SELECT * FROM booking_price_breakdown WHERE booking_id = ? ORDER BY id";
            $booking['price_breakdown'] = $this->db->query($sql, [$id]);

            // Get payment history
            $sql = "SELECT * FROM payments WHERE booking_id = ? ORDER BY payment_date DESC";
            $booking['payments'] = $this->db->query($sql, [$id]);

            return $this->success('Booking retrieved', $booking);

        } catch (Exception $e) {
            Logger::error('Failed to retrieve booking', [
                'booking_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->error('Failed to retrieve booking', null, 500);
        }
    }

    /**
     * List bookings with filters
     */
    public function list(array $filters = [], int $page = 1, int $pageSize = 20): array {
        try {
            $pageSize = min($pageSize, MAX_PAGE_SIZE);
            $offset = ($page - 1) * $pageSize;

            $sql = "SELECT
                        b.*,
                        p.name as property_name,
                        CONCAT(g.first_name, ' ', g.last_name) as guest_name,
                        g.phone as guest_phone,
                        DATEDIFF(b.check_out, b.check_in) as nights
                    FROM bookings b
                    INNER JOIN properties p ON b.property_id = p.id
                    INNER JOIN guests g ON b.guest_id = g.id
                    WHERE 1=1";

            $params = [];

            // Apply filters
            if (!empty($filters['property_id'])) {
                $sql .= " AND b.property_id = ?";
                $params[] = $filters['property_id'];
            }

            if (!empty($filters['guest_id'])) {
                $sql .= " AND b.guest_id = ?";
                $params[] = $filters['guest_id'];
            }

            if (!empty($filters['status'])) {
                $sql .= " AND b.status = ?";
                $params[] = $filters['status'];
            }

            if (!empty($filters['payment_status'])) {
                $sql .= " AND b.payment_status = ?";
                $params[] = $filters['payment_status'];
            }

            if (!empty($filters['check_in_from'])) {
                $sql .= " AND b.check_in >= ?";
                $params[] = $filters['check_in_from'];
            }

            if (!empty($filters['check_in_to'])) {
                $sql .= " AND b.check_in <= ?";
                $params[] = $filters['check_in_to'];
            }

            if (!empty($filters['check_out_from'])) {
                $sql .= " AND b.check_out >= ?";
                $params[] = $filters['check_out_from'];
            }

            if (!empty($filters['check_out_to'])) {
                $sql .= " AND b.check_out <= ?";
                $params[] = $filters['check_out_to'];
            }

            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM (" . $sql . ") as filtered";
            $countResult = $this->db->query($countSql, $params);
            $total = $countResult[0]['total'] ?? 0;

            // Add sorting and pagination
            $sql .= " ORDER BY b.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $pageSize;
            $params[] = $offset;

            $bookings = $this->db->query($sql, $params);

            return $this->success('Bookings retrieved', [
                'bookings' => $bookings,
                'pagination' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => $total,
                    'total_pages' => ceil($total / $pageSize)
                ]
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to list bookings', [
                'error' => $e->getMessage(),
                'filters' => $filters
            ]);

            return $this->error('Failed to list bookings', null, 500);
        }
    }

    /**
     * Update booking
     */
    public function update(int $id, array $data): array {
        try {
            // Get existing booking
            $existing = $this->getById($id);
            if (!$existing['success']) {
                return $existing;
            }

            $oldBooking = $existing['data'];

            // Start transaction
            $this->db->beginTransaction();

            try {
                $updateFields = [];
                $params = [];
                $changedFields = [];

                // Build dynamic UPDATE query
                $allowedFields = ['status', 'payment_status', 'special_requests', 'number_of_guests',
                                 'number_of_adults', 'number_of_children'];

                foreach ($allowedFields as $field) {
                    if (isset($data[$field]) && $data[$field] !== $oldBooking[$field]) {
                        $updateFields[] = "$field = ?";
                        $params[] = $data[$field];
                        $changedFields[$field] = [
                            'old' => $oldBooking[$field],
                            'new' => $data[$field]
                        ];
                    }
                }

                if (empty($updateFields)) {
                    $this->db->rollback();
                    return $this->success('No changes to update', $oldBooking);
                }

                $sql = "UPDATE bookings SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $params[] = $id;

                $this->db->execute($sql, $params);

                $this->db->commit();

                // Log audit
                Logger::auditBooking(
                    $id,
                    'update',
                    'bookings',
                    $id,
                    array_column($changedFields, 'old'),
                    array_column($changedFields, 'new'),
                    array_keys($changedFields)
                );

                Logger::info('Booking updated', [
                    'booking_id' => $id,
                    'changes' => $changedFields
                ]);

                // Return updated booking
                return $this->getById($id);

            } catch (Exception $e) {
                $this->db->rollback();
                throw $e;
            }

        } catch (Exception $e) {
            Logger::error('Failed to update booking', [
                'booking_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->error('Failed to update booking', null, 500);
        }
    }

    /**
     * Cancel booking
     */
    public function cancel(int $id, string $reason = ''): array {
        try {
            $data = [
                'status' => 'cancelled',
                'cancellation_reason' => $reason
            ];

            $sql = "UPDATE bookings SET status = 'cancelled', cancellation_reason = ?, cancelled_at = NOW()
                    WHERE id = ? AND status NOT IN ('cancelled', 'checked_out')";

            $affected = $this->db->execute($sql, [$reason, $id]);

            if ($affected === 0) {
                return $this->error('Cannot cancel booking', null, 400);
            }

            Logger::auditBooking($id, 'cancel', 'bookings', $id, null, ['reason' => $reason]);
            Logger::info('Booking cancelled', ['booking_id' => $id, 'reason' => $reason]);

            return $this->getById($id);

        } catch (Exception $e) {
            Logger::error('Failed to cancel booking', [
                'booking_id' => $id,
                'error' => $e->getMessage()
            ]);

            return $this->error('Failed to cancel booking', null, 500);
        }
    }

    /**
     * Check availability for property and dates
     */
    public function checkAvailability(int $propertyId, string $checkIn, string $checkOut): array {
        try {
            $sql = "SELECT id, check_in, check_out, status
                    FROM bookings
                    WHERE property_id = ?
                        AND status IN ('confirmed', 'checked_in', 'pending')
                        AND NOT (check_out <= ? OR check_in >= ?)";

            $conflicts = $this->db->query($sql, [$propertyId, $checkIn, $checkOut]);

            return [
                'available' => empty($conflicts),
                'conflict' => $conflicts[0] ?? null
            ];

        } catch (Exception $e) {
            Logger::error('Failed to check availability', [
                'property_id' => $propertyId,
                'error' => $e->getMessage()
            ]);

            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get availability calendar for property
     */
    public function getAvailabilityCalendar(int $propertyId, string $startDate, string $endDate): array {
        try {
            $sql = "SELECT check_in, check_out, status, id
                    FROM bookings
                    WHERE property_id = ?
                        AND status IN ('confirmed', 'checked_in', 'pending')
                        AND check_in <= ?
                        AND check_out >= ?
                    ORDER BY check_in";

            $bookings = $this->db->query($sql, [$propertyId, $endDate, $startDate]);

            // Transform for FullCalendar format
            $events = [];
            foreach ($bookings as $booking) {
                $events[] = [
                    'id' => $booking['id'],
                    'title' => 'Занято',
                    'start' => $booking['check_in'],
                    'end' => $booking['check_out'],
                    'status' => $booking['status'],
                    'backgroundColor' => $this->getStatusColor($booking['status']),
                    'borderColor' => $this->getStatusColor($booking['status'])
                ];
            }

            return $this->success('Calendar retrieved', [
                'events' => $events,
                'property_id' => $propertyId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to get availability calendar', [
                'property_id' => $propertyId,
                'error' => $e->getMessage()
            ]);

            return $this->error('Failed to get calendar', null, 500);
        }
    }

    /**
     * Get status color for calendar
     */
    private function getStatusColor(string $status): string {
        $colors = [
            'pending' => '#ffc107',
            'confirmed' => '#28a745',
            'checked_in' => '#007bff',
            'checked_out' => '#6c757d',
            'cancelled' => '#dc3545'
        ];

        return $colors[$status] ?? '#6c757d';
    }

    /**
     * Success response helper
     */
    private function success(string $message, $data = null): array {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data
        ];
    }

    /**
     * Error response helper
     */
    private function error(string $message, $errors = null, int $code = 400): array {
        return [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'code' => $code
        ];
    }
}
