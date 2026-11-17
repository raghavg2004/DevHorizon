<?php
/**
 * DevHorizan Built-in PHP Development Server Router
 * Usage: php -c php.ini -S localhost:3000 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rawurldecode($uri);
$cleanUri = rtrim($uri, '/');
if (empty($cleanUri)) {
    $cleanUri = '/';
}

// 1. Static Uploads
if (strpos($uri, '/uploads/') === 0) {
    $filePath = __DIR__ . $uri;
    if (file_exists($filePath) && !is_dir($filePath)) {
        $mime = mime_content_type($filePath) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        readfile($filePath);
        return true;
    }
    http_response_code(404);
    echo "Upload not found.";
    return true;
}

// 2. API Routes
if (strpos($uri, '/api/') === 0 || $uri === '/api') {
    $_GET['route'] = substr($uri, 5); // strip '/api/'
    require __DIR__ . '/api/index.php';
    return true;
}

if ($uri === '/send-invoice-email') {
    $_GET['route'] = 'send-invoice-email';
    require __DIR__ . '/api/index.php';
    return true;
}

if ($uri === '/health') {
    $_GET['route'] = 'health';
    require __DIR__ . '/api/index.php';
    return true;
}

// 3. Service Aliases
if (in_array($uri, ['/ios-service.html', '/mobile-service.html', '/ios-android-service.html'])) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile(__DIR__ . '/public/android-service.html');
    return true;
}

// 4. Clean Frontend Page Routes
$pageRoutes = [
    '/' => '/public/index.html',
    '/login' => '/public/login.html',
    '/register' => '/public/login.html',
    '/dashboard' => '/public/dashboard.html',
    '/admin' => '/public/admin.html',
    '/admin-login' => '/public/admin-login.html',
    '/admin/login' => '/public/admin-login.html',
    '/forgot-password' => '/public/forgot-password.html',
    '/reset-password' => '/public/reset-password.html',
    '/invoice' => '/public/invoice.html'
];

if (isset($pageRoutes[$cleanUri])) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile(__DIR__ . $pageRoutes[$cleanUri]);
    return true;
}

// Invoice with ID: /invoice/123 or /invoice/INV-...
if (preg_match('#^/invoice/([^/]+)$#', $cleanUri)) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile(__DIR__ . '/public/invoice.html');
    return true;
}

// 5. Existing Public Static Files (.css, .js, .png, .jpg, .svg, .html)
$publicFile = __DIR__ . '/public' . $uri;
if (file_exists($publicFile) && !is_dir($publicFile)) {
    $ext = pathinfo($publicFile, PATHINFO_EXTENSION);
    $mimes = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'html' => 'text/html; charset=UTF-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf'
    ];

    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
    } else {
        header('Content-Type: ' . (mime_content_type($publicFile) ?: 'application/octet-stream'));
    }
    readfile($publicFile);
    return true;
}

// 6. Direct Root Files (.png, favicon, etc.)
$rootFile = __DIR__ . $uri;
if (file_exists($rootFile) && !is_dir($rootFile) && $uri !== '/router.php') {
    return false; // Let PHP built-in server serve it
}

// 7. Fallback to index.html
if (file_exists(__DIR__ . '/public/index.html')) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile(__DIR__ . '/public/index.html');
    return true;
}

http_response_code(404);
echo "Page not found.";
