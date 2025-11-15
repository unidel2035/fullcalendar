<?php
/**
 * API Entry Point
 * Main router for the Booking System API
 */

// Set headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . (in_array($_SERVER['HTTP_ORIGIN'] ?? '*', CORS_ALLOWED_ORIGINS) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Methods: ' . implode(', ', CORS_ALLOWED_METHODS));
header('Access-Control-Allow-Headers: ' . implode(', ', CORS_ALLOWED_HEADERS));

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load configuration and dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/BookingController.php';
require_once __DIR__ . '/GuestController.php';
require_once __DIR__ . '/PropertyController.php';

// Start session
session_name(SESSION_NAME);
session_start();

// Get request information
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_replace(API_BASE_PATH, '', $uri);
$uri = trim($uri, '/');
$segments = explode('/', $uri);

// Get request body for POST/PUT/PATCH
$requestBody = null;
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $requestBody = json_decode(file_get_contents('php://input'), true);
}

// Get query parameters
$queryParams = $_GET;

try {
    // Route the request
    $controller = $segments[0] ?? '';
    $action = $segments[1] ?? '';
    $id = $segments[2] ?? null;

    Logger::debug('API Request', [
        'method' => $method,
        'uri' => $uri,
        'controller' => $controller,
        'action' => $action,
        'id' => $id
    ]);

    $response = null;

    // Route to appropriate controller
    switch ($controller) {
        case 'bookings':
            $bookingController = new BookingController();
            $response = handleBookingRoutes($bookingController, $method, $action, $id, $requestBody, $queryParams);
            break;

        case 'guests':
            $guestController = new GuestController();
            $response = handleGuestRoutes($guestController, $method, $action, $id, $requestBody, $queryParams);
            break;

        case 'properties':
            $propertyController = new PropertyController();
            $response = handlePropertyRoutes($propertyController, $method, $action, $id, $requestBody, $queryParams);
            break;

        case 'health':
            $response = ['success' => true, 'status' => 'healthy', 'version' => API_VERSION];
            break;

        default:
            $response = [
                'success' => false,
                'message' => 'Endpoint not found',
                'code' => 404
            ];
            http_response_code(404);
    }

    // Send response
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    Logger::error('API Error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => APP_DEBUG ? $e->getMessage() : 'An error occurred'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/**
 * Handle booking routes
 */
function handleBookingRoutes($controller, $method, $action, $id, $body, $query) {
    switch ($action) {
        case '':
        case 'list':
            if ($method === 'GET') {
                $page = (int) ($query['page'] ?? 1);
                $pageSize = (int) ($query['page_size'] ?? DEFAULT_PAGE_SIZE);
                $filters = array_diff_key($query, array_flip(['page', 'page_size']));
                return $controller->list($filters, $page, $pageSize);
            } elseif ($method === 'POST') {
                return $controller->create($body);
            }
            break;

        case 'availability':
            if ($method === 'GET' && $id) {
                $startDate = $query['start_date'] ?? date('Y-m-d');
                $endDate = $query['end_date'] ?? date('Y-m-d', strtotime('+30 days'));
                return $controller->getAvailabilityCalendar((int)$id, $startDate, $endDate);
            }
            break;

        case 'check-availability':
            if ($method === 'POST') {
                $available = $controller->checkAvailability(
                    $body['property_id'],
                    $body['check_in'],
                    $body['check_out']
                );
                return ['success' => true, 'data' => $available];
            }
            break;

        case 'cancel':
            if ($method === 'POST' && $id) {
                return $controller->cancel((int)$id, $body['reason'] ?? '');
            }
            break;

        default:
            if ($method === 'GET' && is_numeric($action)) {
                return $controller->getById((int)$action);
            } elseif ($method === 'PUT' && is_numeric($action)) {
                return $controller->update((int)$action, $body);
            }
    }

    return ['success' => false, 'message' => 'Route not found', 'code' => 404];
}

/**
 * Handle guest routes
 */
function handleGuestRoutes($controller, $method, $action, $id, $body, $query) {
    switch ($action) {
        case '':
        case 'list':
            if ($method === 'GET') {
                $page = (int) ($query['page'] ?? 1);
                $pageSize = (int) ($query['page_size'] ?? DEFAULT_PAGE_SIZE);
                $filters = array_diff_key($query, array_flip(['page', 'page_size']));
                return $controller->list($filters, $page, $pageSize);
            } elseif ($method === 'POST') {
                return $controller->create($body);
            }
            break;

        default:
            if ($method === 'GET' && is_numeric($action)) {
                return $controller->getById((int)$action);
            } elseif ($method === 'PUT' && is_numeric($action)) {
                return $controller->update((int)$action, $body);
            } elseif ($method === 'DELETE' && is_numeric($action)) {
                return $controller->delete((int)$action);
            }
    }

    return ['success' => false, 'message' => 'Route not found', 'code' => 404];
}

/**
 * Handle property routes
 */
function handlePropertyRoutes($controller, $method, $action, $id, $body, $query) {
    switch ($action) {
        case '':
        case 'list':
            if ($method === 'GET') {
                $page = (int) ($query['page'] ?? 1);
                $pageSize = (int) ($query['page_size'] ?? DEFAULT_PAGE_SIZE);
                return $controller->list($page, $pageSize);
            } elseif ($method === 'POST') {
                return $controller->create($body);
            }
            break;

        default:
            if ($method === 'GET' && is_numeric($action)) {
                return $controller->getById((int)$action);
            } elseif ($method === 'PUT' && is_numeric($action)) {
                return $controller->update((int)$action, $body);
            }
    }

    return ['success' => false, 'message' => 'Route not found', 'code' => 404];
}
