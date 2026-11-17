<?php
/**
 * DevHorizan Unified API Master Router (PHP + SQLite)
 * Routes requests to dedicated modular handlers in api/handlers/
 */

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/utils.php';

// Route imports
require_once __DIR__ . '/handlers/auth.php';
require_once __DIR__ . '/handlers/projects.php';
require_once __DIR__ . '/handlers/requests.php';
require_once __DIR__ . '/handlers/payments.php';
require_once __DIR__ . '/handlers/reviews.php';
require_once __DIR__ . '/handlers/users.php';
require_once __DIR__ . '/handlers/notifications.php';
require_once __DIR__ . '/handlers/messages.php';
require_once __DIR__ . '/handlers/reports.php';

// Determine HTTP Method
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
    $method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
}

// Extract Route String
$route = $_GET['route'] ?? '';
if (empty($route)) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    // Strip script directory or /api prefix if present
    if (strpos($uri, '/api/') !== false) {
        $route = substr($uri, strpos($uri, '/api/') + 5);
    } elseif (strpos($uri, '/api') === 0) {
        $route = substr($uri, 4);
    } else {
        $route = ltrim($uri, '/');
    }
}
$route = trim($route, '/');

// Handle Health / Root Check
if (empty($route) || $route === 'health') {
    jsonResponse([
        'status' => 'online',
        'app' => 'DevHorizan Unified Platform (PHP + SQLite)',
        'version' => '2.0.0',
        'timestamp' => date('c')
    ]);
}

$segments = explode('/', $route);
$module = strtolower($segments[0]);
$subSegments = array_slice($segments, 1);

switch ($module) {
    case 'auth':
        handleAuth($method, $subSegments);
        break;

    case 'projects':
        handleProjects($method, $subSegments);
        break;

    case 'requests':
        handleRequests($method, $subSegments);
        break;

    case 'payments':
        handlePayments($method, $subSegments);
        break;

    case 'reviews':
        handleReviews($method, $subSegments);
        break;

    case 'users':
        handleUsers($method, $subSegments);
        break;

    case 'notifications':
        handleNotifications($method, $subSegments);
        break;

    case 'messages':
        handleMessages($method, $subSegments);
        break;

    case 'reports':
        handleReports($method, $subSegments);
        break;

    // Direct compatibility for legacy /send-invoice-email
    case 'send-invoice-email':
        handlePayments($method, ['send-invoice-email']);
        break;

    default:
        errorResponse('API route not found: /api/' . $route, 404);
        break;
}
