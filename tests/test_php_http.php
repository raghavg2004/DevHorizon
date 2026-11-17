<?php
/**
 * Comprehensive End-to-End HTTP Subprocess Test Suite
 */

function makeRequest(string $method, string $route, array $body = [], ?string $token = null): array {
    $phpBin = 'C:\\Program Files\\php-8.4.8\\php.exe';
    $iniFile = __DIR__ . '/../php.ini';
    $indexPath = __DIR__ . '/../api/index.php';

    $cmd = "\"{$phpBin}\" -c \"{$iniFile}\" \"{$indexPath}\"";

    $env = [
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => '/api/' . ltrim($route, '/'),
        'QUERY_STRING' => 'route=' . urlencode(ltrim($route, '/')),
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => '127.0.0.1'
    ];

    if ($token) {
        $env['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $process = proc_open($cmd, $descriptorSpec, $pipes, dirname(__DIR__), $env);

    if (is_resource($process)) {
        if (!empty($body)) {
            fwrite($pipes[0], json_encode($body));
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        $json = json_decode($stdout, true);

        return [
            'code' => $returnCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'json' => $json
        ];
    }

    return ['error' => 'Process open failed'];
}

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo "  [OK] {$label}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$label} - " . json_encode($details) . "\n";
        $failed++;
    }
}

echo "========================================================\n";
echo "   DEVHORIZAN PHP API END-TO-END PIPELINE VERIFICATION   \n";
echo "========================================================\n\n";

// 1. Health
$r = makeRequest('GET', 'health');
check('GET /api/health returns online status', ($r['json']['status'] ?? '') === 'online', $r['json']);

// 2. Admin Login
$r = makeRequest('POST', 'auth/login', ['email' => 'admin@devhorizan.com', 'password' => 'Admin@12345']);
check('POST /api/auth/login (Admin)', ($r['json']['success'] ?? false) === true, $r['json']);
$adminToken = $r['json']['token'] ?? null;

// 3. Client Login
$r = makeRequest('POST', 'auth/login', ['email' => 'client@devhorizan.com', 'password' => 'Client@12345']);
check('POST /api/auth/login (Client)', ($r['json']['success'] ?? false) === true, $r['json']);
$clientToken = $r['json']['token'] ?? null;

// 4. Auth /me
$r = makeRequest('GET', 'auth/me', [], $adminToken);
check('GET /api/auth/me (Admin)', ($r['json']['user']['email'] ?? '') === 'admin@devhorizan.com', $r['json']);

$r = makeRequest('GET', 'auth/me', [], $clientToken);
check('GET /api/auth/me (Client)', ($r['json']['user']['email'] ?? '') === 'client@devhorizan.com', $r['json']);

// 5. Projects
$r = makeRequest('GET', 'projects', [], $adminToken);
check('GET /api/projects (Admin: all)', count($r['json']['projects'] ?? []) > 0, $r['json']);

$r = makeRequest('GET', 'projects', [], $clientToken);
check('GET /api/projects (Client: own)', is_array($r['json']['projects'] ?? null), $r['json']);

// 6. Project Detail
$r = makeRequest('GET', 'projects/7', [], $adminToken);
check('GET /api/projects/7 (Admin detail with milestones)', isset($r['json']['project']['milestones']), $r['json']);

// 7. Requests
$r = makeRequest('GET', 'requests', [], $adminToken);
check('GET /api/requests (Admin)', is_array($r['json']['requests'] ?? null), $r['json']);

$r = makeRequest('GET', 'requests', [], $clientToken);
check('GET /api/requests (Client)', is_array($r['json']['requests'] ?? null), $r['json']);

// 8. Payments & Invoices
$r = makeRequest('GET', 'payments', [], $adminToken);
check('GET /api/payments (Admin)', count($r['json']['payments'] ?? []) > 0, $r['json']);

$r = makeRequest('GET', 'payments', [], $clientToken);
check('GET /api/payments (Client)', is_array($r['json']['payments'] ?? null), $r['json']);

// 9. Single Invoice
$r = makeRequest('GET', 'payments/1', [], $adminToken);
check('GET /api/payments/1 (Invoice detail)', isset($r['json']['payment']['invoice_number']), $r['json']);

// 10. Reviews
$r = makeRequest('GET', 'reviews/public');
check('GET /api/reviews/public (Unauthenticated)', count($r['json']['reviews'] ?? []) > 0, $r['json']);

$r = makeRequest('GET', 'reviews/my', [], $clientToken);
check('GET /api/reviews/my (Client)', is_array($r['json']['reviews'] ?? null), $r['json']);

$r = makeRequest('GET', 'reviews/admin', [], $adminToken);
check('GET /api/reviews/admin (Admin with stats)', isset($r['json']['stats']['total']), $r['json']);

// 11. Users Management (Admin)
$r = makeRequest('GET', 'users', [], $adminToken);
check('GET /api/users (Admin)', count($r['json']['users'] ?? []) > 0, $r['json']);

// 12. Notifications
$r = makeRequest('GET', 'notifications', [], $clientToken);
check('GET /api/notifications (Client)', isset($r['json']['unreadCount']), $r['json']);

// 13. Reports Dashboard
$r = makeRequest('GET', 'reports/dashboard', [], $adminToken);
check('GET /api/reports/dashboard (Admin stats)', isset($r['json']['stats']['totalRevenue']), $r['json']);

$r = makeRequest('GET', 'reports/dashboard', [], $clientToken);
check('GET /api/reports/dashboard (Client stats)', isset($r['json']['stats']['totalCost']), $r['json']);

// 14. Activity Log
$r = makeRequest('GET', 'reports/activity', [], $adminToken);
check('GET /api/reports/activity (Admin log)', count($r['json']['activities'] ?? []) > 0, $r['json']);

echo "\n========================================================\n";
echo "   TOTAL: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
