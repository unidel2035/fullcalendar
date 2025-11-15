<?php
/**
 * Property Controller
 * Manages rental properties
 */

require_once 'Database.php';
require_once 'Logger.php';
require_once 'BookingValidator.php';

class PropertyController {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Create a new property
     */
    public function create(array $data): array {
        try {
            $validation = BookingValidator::validatePropertyData($data);
            if (!$validation['valid']) {
                return $this->error('Validation failed', $validation['errors'], 400);
            }

            $sql = "INSERT INTO properties
                    (name, description, address, property_type, max_guests,
                     bedrooms, bathrooms, square_meters, amenities, images, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $params = [
                $data['name'],
                $data['description'] ?? null,
                $data['address'],
                $data['property_type'],
                $data['max_guests'],
                $data['bedrooms'] ?? 1,
                $data['bathrooms'] ?? 1,
                $data['square_meters'] ?? null,
                isset($data['amenities']) ? json_encode($data['amenities']) : null,
                isset($data['images']) ? json_encode($data['images']) : null,
                $data['is_active'] ?? true
            ];

            $this->db->execute($sql, $params);
            $propertyId = (int) $this->db->lastInsertId();

            Logger::info('Property created', ['property_id' => $propertyId, 'name' => $data['name']]);

            return $this->getById($propertyId);

        } catch (Exception $e) {
            Logger::error('Failed to create property', ['error' => $e->getMessage()]);
            return $this->error('Failed to create property', null, 500);
        }
    }

    /**
     * Get property by ID
     */
    public function getById(int $id): array {
        try {
            $sql = "SELECT * FROM properties WHERE id = ?";
            $result = $this->db->query($sql, [$id]);

            if (empty($result)) {
                return $this->error('Property not found', null, 404);
            }

            $property = $result[0];

            // Decode JSON fields
            $property['amenities'] = json_decode($property['amenities'] ?? '[]', true);
            $property['images'] = json_decode($property['images'] ?? '[]', true);

            // Get pricing rules
            $sql = "SELECT * FROM pricing_rules WHERE property_id = ? AND is_active = TRUE ORDER BY priority DESC";
            $property['pricing_rules'] = $this->db->query($sql, [$id]);

            // Get restrictions
            $sql = "SELECT * FROM booking_restrictions WHERE property_id = ? AND is_active = TRUE";
            $property['restrictions'] = $this->db->query($sql, [$id]);

            return $this->success('Property retrieved', $property);

        } catch (Exception $e) {
            Logger::error('Failed to retrieve property', ['property_id' => $id, 'error' => $e->getMessage()]);
            return $this->error('Failed to retrieve property', null, 500);
        }
    }

    /**
     * List properties
     */
    public function list(int $page = 1, int $pageSize = 20): array {
        try {
            $pageSize = min($pageSize, MAX_PAGE_SIZE);
            $offset = ($page - 1) * $pageSize;

            $sql = "SELECT * FROM properties WHERE is_active = TRUE";

            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM (" . $sql . ") as filtered";
            $countResult = $this->db->query($countSql, []);
            $total = $countResult[0]['total'] ?? 0;

            // Add pagination
            $sql .= " ORDER BY name LIMIT ? OFFSET ?";
            $properties = $this->db->query($sql, [$pageSize, $offset]);

            // Decode JSON fields
            foreach ($properties as &$property) {
                $property['amenities'] = json_decode($property['amenities'] ?? '[]', true);
                $property['images'] = json_decode($property['images'] ?? '[]', true);
            }

            return $this->success('Properties retrieved', [
                'properties' => $properties,
                'pagination' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => $total,
                    'total_pages' => ceil($total / $pageSize)
                ]
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to list properties', ['error' => $e->getMessage()]);
            return $this->error('Failed to list properties', null, 500);
        }
    }

    /**
     * Update property
     */
    public function update(int $id, array $data): array {
        try {
            $updateFields = [];
            $params = [];

            $allowedFields = ['name', 'description', 'address', 'property_type', 'max_guests',
                             'bedrooms', 'bathrooms', 'square_meters', 'is_active'];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }

            // Handle JSON fields
            if (isset($data['amenities'])) {
                $updateFields[] = "amenities = ?";
                $params[] = json_encode($data['amenities']);
            }

            if (isset($data['images'])) {
                $updateFields[] = "images = ?";
                $params[] = json_encode($data['images']);
            }

            if (empty($updateFields)) {
                return $this->error('No fields to update', null, 400);
            }

            $sql = "UPDATE properties SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $params[] = $id;

            $affected = $this->db->execute($sql, $params);

            if ($affected === 0) {
                return $this->error('Property not found or no changes', null, 404);
            }

            Logger::info('Property updated', ['property_id' => $id]);

            return $this->getById($id);

        } catch (Exception $e) {
            Logger::error('Failed to update property', ['property_id' => $id, 'error' => $e->getMessage()]);
            return $this->error('Failed to update property', null, 500);
        }
    }

    private function success(string $message, $data = null): array {
        return ['success' => true, 'message' => $message, 'data' => $data];
    }

    private function error(string $message, $errors = null, int $code = 400): array {
        return ['success' => false, 'message' => $message, 'errors' => $errors, 'code' => $code];
    }
}
