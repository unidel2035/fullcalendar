<?php
/**
 * Guest Controller
 * Manages guest registration and information
 */

require_once 'Database.php';
require_once 'Logger.php';
require_once 'BookingValidator.php';

class GuestController {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Create a new guest
     */
    public function create(array $data): array {
        try {
            // Validate input
            $validation = BookingValidator::validateGuestData($data);
            if (!$validation['valid']) {
                return $this->error('Validation failed', $validation['errors'], 400);
            }

            // Check for duplicate email/phone
            $duplicate = $this->checkDuplicate($data['email'], $data['phone']);
            if ($duplicate) {
                return $this->error('Guest already exists', ['guest_id' => $duplicate['id']], 409);
            }

            // Insert guest
            $sql = "INSERT INTO guests
                    (first_name, last_name, middle_name, email, phone,
                     passport_series, passport_number, passport_issued_by, passport_issued_date,
                     date_of_birth, citizenship, address, telegram_id, telegram_username,
                     language_code, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $params = [
                $data['first_name'],
                $data['last_name'],
                $data['middle_name'] ?? null,
                $data['email'],
                $data['phone'],
                $data['passport_series'] ?? null,
                $data['passport_number'] ?? null,
                $data['passport_issued_by'] ?? null,
                $data['passport_issued_date'] ?? null,
                $data['date_of_birth'] ?? null,
                $data['citizenship'] ?? null,
                $data['address'] ?? null,
                $data['telegram_id'] ?? null,
                $data['telegram_username'] ?? null,
                $data['language_code'] ?? 'ru',
                $data['notes'] ?? null
            ];

            $this->db->execute($sql, $params);
            $guestId = (int) $this->db->lastInsertId();

            Logger::info('Guest created', ['guest_id' => $guestId, 'email' => $data['email']]);

            return $this->getById($guestId);

        } catch (Exception $e) {
            Logger::error('Failed to create guest', ['error' => $e->getMessage()]);
            return $this->error('Failed to create guest', null, 500);
        }
    }

    /**
     * Get guest by ID
     */
    public function getById(int $id): array {
        try {
            $sql = "SELECT * FROM guests WHERE id = ?";
            $result = $this->db->query($sql, [$id]);

            if (empty($result)) {
                return $this->error('Guest not found', null, 404);
            }

            $guest = $result[0];

            // Get booking history
            $sql = "SELECT b.*, p.name as property_name
                    FROM bookings b
                    INNER JOIN properties p ON b.property_id = p.id
                    WHERE b.guest_id = ?
                    ORDER BY b.check_in DESC
                    LIMIT 10";

            $guest['booking_history'] = $this->db->query($sql, [$id]);

            return $this->success('Guest retrieved', $guest);

        } catch (Exception $e) {
            Logger::error('Failed to retrieve guest', ['guest_id' => $id, 'error' => $e->getMessage()]);
            return $this->error('Failed to retrieve guest', null, 500);
        }
    }

    /**
     * List guests
     */
    public function list(array $filters = [], int $page = 1, int $pageSize = 20): array {
        try {
            $pageSize = min($pageSize, MAX_PAGE_SIZE);
            $offset = ($page - 1) * $pageSize;

            $sql = "SELECT * FROM guests WHERE 1=1";
            $params = [];

            // Apply filters
            if (!empty($filters['search'])) {
                $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            }

            if (isset($filters['is_blacklisted'])) {
                $sql .= " AND is_blacklisted = ?";
                $params[] = (int) $filters['is_blacklisted'];
            }

            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM (" . $sql . ") as filtered";
            $countResult = $this->db->query($countSql, $params);
            $total = $countResult[0]['total'] ?? 0;

            // Add pagination
            $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $pageSize;
            $params[] = $offset;

            $guests = $this->db->query($sql, $params);

            return $this->success('Guests retrieved', [
                'guests' => $guests,
                'pagination' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => $total,
                    'total_pages' => ceil($total / $pageSize)
                ]
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to list guests', ['error' => $e->getMessage()]);
            return $this->error('Failed to list guests', null, 500);
        }
    }

    /**
     * Update guest
     */
    public function update(int $id, array $data): array {
        try {
            $updateFields = [];
            $params = [];

            $allowedFields = ['first_name', 'last_name', 'middle_name', 'email', 'phone',
                             'passport_series', 'passport_number', 'passport_issued_by',
                             'passport_issued_date', 'date_of_birth', 'citizenship', 'address',
                             'telegram_id', 'telegram_username', 'notes', 'is_blacklisted'];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($updateFields)) {
                return $this->error('No fields to update', null, 400);
            }

            $sql = "UPDATE guests SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $params[] = $id;

            $affected = $this->db->execute($sql, $params);

            if ($affected === 0) {
                return $this->error('Guest not found or no changes', null, 404);
            }

            Logger::info('Guest updated', ['guest_id' => $id]);

            return $this->getById($id);

        } catch (Exception $e) {
            Logger::error('Failed to update guest', ['guest_id' => $id, 'error' => $e->getMessage()]);
            return $this->error('Failed to update guest', null, 500);
        }
    }

    /**
     * Check for duplicate guest
     */
    private function checkDuplicate(string $email, string $phone): ?array {
        $sql = "SELECT id FROM guests WHERE email = ? OR phone = ? LIMIT 1";
        $result = $this->db->query($sql, [$email, $phone]);
        return $result[0] ?? null;
    }

    private function success(string $message, $data = null): array {
        return ['success' => true, 'message' => $message, 'data' => $data];
    }

    private function error(string $message, $errors = null, int $code = 400): array {
        return ['success' => false, 'message' => $message, 'errors' => $errors, 'code' => $code];
    }
}
