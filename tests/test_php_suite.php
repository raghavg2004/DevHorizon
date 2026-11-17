<?php
/**
 * DevHorizan PHP API Test Suite
 * Directly tests database, auth, handlers, and responses without needing TCP sockets.
 */

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/auth.php';
require_once __DIR__ . '/../api/utils.php';

echo "========================================\n";
echo "   DEVHORIZAN PHP + SQLITE TEST SUITE   \n";
echo "========================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $name, bool $condition, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$name} - {$detail}\n";
        $failCount++;
    }
}

// 1. Database Connection & Table Counts
echo "[1] Testing SQLite Database Connection & Tables...\n";
$db = getDb();
assertTest('Database connection established', $db instanceof PDO);

$tables = ['users', 'user_profiles', 'projects', 'project_requests', 'project_revisions', 'payments', 'client_reviews', 'notifications', 'activity_logs', 'messages'];
foreach ($tables as $table) {
    try {
        $stmt = $db->query("SELECT COUNT(*) as count FROM {$table}");
        $count = $stmt->fetch()['count'];
        assertTest("Table '{$table}' exists with count ({$count})", true);
    } catch (Exception $e) {
        assertTest("Table '{$table}' query", false, $e->getMessage());
    }
}

// 2. JWT Generation & Verification
echo "\n[2] Testing JWT HS256 Token Handling...\n";
$testPayload = ['id' => 999, 'email' => 'test@devhorizan.com', 'role' => 'client'];
$token = generateJwt($testPayload);
assertTest('JWT generation returns valid string', !empty($token) && substr_count($token, '.') === 2);

$decoded = verifyJwt($token);
assertTest('JWT verification validates signature and expiration', $decoded !== null && $decoded['id'] === 999 && $decoded['role'] === 'client');

$tamperedToken = $token . 'x';
$tamperedDecoded = verifyJwt($tamperedToken);
assertTest('JWT verification rejects tampered signature', $tamperedDecoded === null);

// 3. User Authentication & Password Hashes
echo "\n[3] Testing Existing User Passwords & Bcrypt Compatibility...\n";
$stmt = $db->prepare("SELECT * FROM users WHERE email = ?");

// Admin test
$stmt->execute(['admin@devhorizan.com']);
$adminUser = $stmt->fetch();
assertTest('Admin user exists in database', (bool)$adminUser);
if ($adminUser) {
    $adminMatch = password_verify('Admin@12345', $adminUser['password_hash']);
    assertTest("Admin password ('Admin@12345') verified with native PHP bcrypt", $adminMatch);
}

// Client test
$stmt->execute(['client@devhorizan.com']);
$clientUser = $stmt->fetch();
assertTest('Client user exists in database', (bool)$clientUser);
if ($clientUser) {
    $clientMatch = password_verify('Client@12345', $clientUser['password_hash']);
    assertTest("Client password ('Client@12345') verified with native PHP bcrypt", $clientMatch);
}

// 4. Test Handler Logic: Projects
echo "\n[4] Testing Projects Handler Queries...\n";
require_once __DIR__ . '/../api/handlers/projects.php';

// Admin query simulation
$stmt = $db->query("
    SELECT p.*, u.full_name as client_name, u.email as client_email 
    FROM projects p 
    JOIN users u ON p.user_id = u.id 
    ORDER BY p.updated_at DESC
");
$allProjects = $stmt->fetchAll();
assertTest('Projects query for admin retrieves records', count($allProjects) > 0);

if (!empty($allProjects)) {
    $p1 = $allProjects[0];
    $stmt = $db->prepare('SELECT * FROM project_milestones WHERE project_id = ?');
    $stmt->execute([$p1['id']]);
    $milestones = $stmt->fetchAll();
    assertTest("Milestones retrieved for project #{$p1['id']}", is_array($milestones));

    $stmt = $db->prepare('SELECT * FROM payments WHERE project_id = ?');
    $stmt->execute([$p1['id']]);
    $projectPayments = $stmt->fetchAll();
    assertTest("Payments retrieved for project #{$p1['id']}", is_array($projectPayments));
}

// 5. Test Handler Logic: Requests & Revisions
echo "\n[5] Testing Project Requests & Revisions Queries...\n";
$stmt = $db->query("
    SELECT r.*, u.full_name as client_name 
    FROM project_requests r 
    JOIN users u ON r.user_id = u.id 
    ORDER BY r.created_at DESC
");
$requests = $stmt->fetchAll();
assertTest('Project requests query succeeds', is_array($requests));

// 6. Test Handler Logic: Payments & Invoices
echo "\n[6] Testing Payments & Invoices Queries...\n";
$stmt = $db->query("
    SELECT pm.*, p.title as project_title, u.full_name as client_name 
    FROM payments pm 
    JOIN projects p ON pm.project_id = p.id 
    JOIN users u ON pm.user_id = u.id 
    ORDER BY pm.created_at DESC
");
$payments = $stmt->fetchAll();
assertTest('Payments query succeeds', count($payments) > 0);
if (!empty($payments)) {
    $pm = $payments[0];
    assertTest("Invoice {$pm['invoice_number']} has valid total amount (>0)", (float)$pm['total_amount'] > 0);
}

// 7. Test Handler Logic: Reviews (Public & Admin)
echo "\n[7] Testing Client Reviews Queries...\n";
$stmt = $db->query("
    SELECT id, client_name, rating, review_text 
    FROM client_reviews 
    WHERE status = 'Approved' 
    ORDER BY created_at DESC 
    LIMIT 12
");
$publicReviews = $stmt->fetchAll();
assertTest('Public approved reviews query succeeds', is_array($publicReviews));

// 8. Test Reports & KPIs
echo "\n[8] Testing Reports Dashboard Calculations...\n";
$totalUsers = (int)$db->query("SELECT COUNT(*) as count FROM users WHERE role = 'client'")->fetch()['count'];
$totalProjects = (int)$db->query("SELECT COUNT(*) as count FROM projects")->fetch()['count'];
$finStmt = $db->query("
    SELECT COALESCE(SUM(total_amount), 0) as total_revenue,
           COALESCE(SUM(amount_paid), 0) as total_received,
           COALESCE(SUM(remaining_balance), 0) as total_outstanding
    FROM payments
");
$financialRow = $finStmt->fetch();
assertTest('Admin KPIs computed successfully', $totalUsers >= 0 && $totalProjects >= 0 && isset($financialRow['total_revenue']));
echo "    -> Total Clients: {$totalUsers}\n";
echo "    -> Total Projects: {$totalProjects}\n";
echo "    -> Total Revenue: ₹" . number_format((float)$financialRow['total_revenue']) . "\n";
echo "    -> Total Received: ₹" . number_format((float)$financialRow['total_received']) . "\n";
echo "    -> Total Outstanding: ₹" . number_format((float)$financialRow['total_outstanding']) . "\n";

// 9. Notifications
echo "\n[9] Testing Activity Logging & Notifications...\n";
$logTestId = 'test_' . time();
logActivity([
    'userId' => $adminUser['id'],
    'action' => 'TEST_PHP_SUITE',
    'entityType' => 'system',
    'details' => "Automated PHP verification run {$logTestId}"
]);

$stmt = $db->prepare("SELECT * FROM activity_logs WHERE details LIKE ? ORDER BY id DESC LIMIT 1");
$stmt->execute(["%{$logTestId}%"]);
$foundLog = $stmt->fetch();
assertTest('Activity log successfully written and queried in SQLite', (bool)$foundLog);

echo "\n========================================\n";
echo "   SUMMARY: {$passCount} PASSED, {$failCount} FAILED   \n";
echo "========================================\n";

if ($failCount > 0) {
    exit(1);
}
