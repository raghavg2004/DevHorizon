<?php
/**
 * Reports & Analytics Handler
 * Endpoints:
 *   GET /api/reports/dashboard
 *   GET /api/reports/activity
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../utils.php';

function handleReports(string $method, array $pathSegments): void {
    $db = getDb();
    $action = $pathSegments[0] ?? '';
    $user = requireAuth();

    if ($action === 'dashboard') {
        if ($method !== 'GET') {
            errorResponse('Method Not Allowed', 405);
        }

        if ($user['role'] === 'admin') {
            $totalUsers = (int)$db->query("SELECT COUNT(*) as count FROM users WHERE role = 'client'")->fetch()['count'];
            $totalProjects = (int)$db->query("SELECT COUNT(*) as count FROM projects")->fetch()['count'];
            $activeProjects = (int)$db->query("SELECT COUNT(*) as count FROM projects WHERE status IN ('In Progress', 'Approved', 'Under Review')")->fetch()['count'];
            $completedProjects = (int)$db->query("SELECT COUNT(*) as count FROM projects WHERE status = 'Completed'")->fetch()['count'];
            $pendingRequests = (int)$db->query("SELECT COUNT(*) as count FROM project_requests WHERE status IN ('Submitted', 'Under Admin Review', 'Revision Requested')")->fetch()['count'];
            $rejectedRequests = (int)$db->query("SELECT COUNT(*) as count FROM project_requests WHERE status = 'Rejected'")->fetch()['count'];

            $finStmt = $db->query("
                SELECT COALESCE(SUM(total_amount), 0) as total_revenue,
                       COALESCE(SUM(amount_paid), 0) as total_received,
                       COALESCE(SUM(remaining_balance), 0) as total_outstanding
                FROM payments
            ");
            $financialRow = $finStmt->fetch();

            $actStmt = $db->query("
                SELECT al.*, u.full_name as user_name, u.email as user_email, u.role as user_role 
                FROM activity_logs al 
                LEFT JOIN users u ON al.user_id = u.id 
                ORDER BY al.created_at DESC 
                LIMIT 10
            ");
            $recentActivities = $actStmt->fetchAll();

            $catStmt = $db->query("
                SELECT category, COUNT(*) as count, SUM(total_cost) as total_value 
                FROM projects 
                GROUP BY category
            ");
            $categoryBreakdown = $catStmt->fetchAll();

            $statStmt = $db->query("
                SELECT status, COUNT(*) as count 
                FROM projects 
                GROUP BY status
            ");
            $statusBreakdown = $statStmt->fetchAll();

            jsonResponse([
                'success' => true,
                'stats' => [
                    'totalUsers' => $totalUsers,
                    'totalProjects' => $totalProjects,
                    'activeProjects' => $activeProjects,
                    'completedProjects' => $completedProjects,
                    'pendingRequests' => $pendingRequests,
                    'rejectedRequests' => $rejectedRequests,
                    'totalRevenue' => (float)$financialRow['total_revenue'],
                    'totalReceived' => (float)$financialRow['total_received'],
                    'totalOutstanding' => (float)$financialRow['total_outstanding']
                ],
                'recentActivities' => $recentActivities,
                'categoryBreakdown' => $categoryBreakdown,
                'statusBreakdown' => $statusBreakdown
            ]);
        } else {
            // Client dashboard
            $userId = (int)$user['id'];

            $stmt = $db->prepare('SELECT COUNT(*) as count FROM projects WHERE user_id = ?');
            $stmt->execute([$userId]);
            $totalProjects = (int)$stmt->fetch()['count'];

            $stmt = $db->prepare("SELECT COUNT(*) as count FROM projects WHERE user_id = ? AND status IN ('In Progress', 'Approved', 'Under Review')");
            $stmt->execute([$userId]);
            $activeProjects = (int)$stmt->fetch()['count'];

            $stmt = $db->prepare("SELECT COUNT(*) as count FROM projects WHERE user_id = ? AND status = 'Completed'");
            $stmt->execute([$userId]);
            $completedProjects = (int)$stmt->fetch()['count'];

            $stmt = $db->prepare("SELECT COUNT(*) as count FROM project_requests WHERE user_id = ? AND status IN ('Submitted', 'Under Admin Review', 'Revision Requested')");
            $stmt->execute([$userId]);
            $pendingRequests = (int)$stmt->fetch()['count'];

            $stmt = $db->prepare("
                SELECT COALESCE(SUM(total_amount), 0) as total_cost,
                       COALESCE(SUM(amount_paid), 0) as total_paid,
                       COALESCE(SUM(remaining_balance), 0) as remaining_balance
                FROM payments 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $financialRow = $stmt->fetch();

            $stmt = $db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
            $stmt->execute([$userId]);
            $recentNotifications = $stmt->fetchAll();

            jsonResponse([
                'success' => true,
                'stats' => [
                    'totalProjects' => $totalProjects,
                    'activeProjects' => $activeProjects,
                    'completedProjects' => $completedProjects,
                    'pendingRequests' => $pendingRequests,
                    'totalCost' => (float)$financialRow['total_cost'],
                    'totalPaid' => (float)$financialRow['total_paid'],
                    'remainingBalance' => (float)$financialRow['remaining_balance']
                ],
                'recentNotifications' => $recentNotifications
            ]);
        }
    }

    if ($action === 'activity') {
        if ($method !== 'GET') {
            errorResponse('Method Not Allowed', 405);
        }
        $user = requireAdmin();

        $entityType = $_GET['entity_type'] ?? null;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

        $sql = '
            SELECT al.*, u.full_name as user_name, u.email as user_email, u.role as user_role 
            FROM activity_logs al 
            LEFT JOIN users u ON al.user_id = u.id 
        ';
        $params = [];

        if ($entityType) {
            $sql .= ' WHERE al.entity_type = ?';
            $params[] = $entityType;
        }

        $sql .= ' ORDER BY al.created_at DESC LIMIT ?';
        $params[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $activities = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'activities' => $activities
        ]);
    }

    errorResponse('Not Found', 404);
}
