<?php
/**
 * Users Management Handler (Admin Only)
 * Endpoints:
 *   GET  /api/users
 *   GET  /api/users/:id
 *   PUT  /api/users/:id/status
 *   POST /api/users/:id/notify
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../utils.php';

function handleUsers(string $method, array $pathSegments): void {
    $db = getDb();
    $body = getBody();
    $firstSegment = $pathSegments[0] ?? null;

    // List all users: GET /api/users
    if ($firstSegment === null || $firstSegment === '') {
        if ($method === 'GET') {
            $user = requireAdmin();

            $search = $_GET['search'] ?? null;
            $role = $_GET['role'] ?? null;
            $status = $_GET['status'] ?? null;

            $query = '
                SELECT u.id, u.role, u.email, u.full_name, u.phone, u.status, u.created_at,
                       up.company_name, up.avatar_url,
                       (SELECT COUNT(*) FROM projects WHERE user_id = u.id) as project_count,
                       (SELECT COALESCE(SUM(total_amount), 0) FROM payments WHERE user_id = u.id) as total_spent,
                       (SELECT COALESCE(SUM(remaining_balance), 0) FROM payments WHERE user_id = u.id) as total_due
                FROM users u
                LEFT JOIN user_profiles up ON u.id = up.user_id
                WHERE 1=1
            ';
            $params = [];

            if ($search) {
                $query .= ' AND (u.full_name LIKE ? OR u.email LIKE ? OR up.company_name LIKE ?)';
                $s = '%' . $search . '%';
                $params[] = $s;
                $params[] = $s;
                $params[] = $s;
            }

            if ($role) {
                $query .= ' AND u.role = ?';
                $params[] = $role;
            }

            if ($status) {
                $query .= ' AND u.status = ?';
                $params[] = $status;
            }

            $query .= ' ORDER BY u.created_at DESC';

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $users = $stmt->fetchAll();

            foreach ($users as &$u) {
                $u['id'] = (int)$u['id'];
                $u['project_count'] = (int)$u['project_count'];
                $u['total_spent'] = (float)$u['total_spent'];
                $u['total_due'] = (float)$u['total_due'];
            }

            jsonResponse(['success' => true, 'users' => $users]);
        }

        errorResponse('Method Not Allowed', 405);
    }

    $userId = (int)$firstSegment;
    $secondSegment = $pathSegments[1] ?? null;

    // Toggle status: PUT /api/users/:id/status
    if ($secondSegment === 'status') {
        if ($method !== 'PUT') {
            errorResponse('Method Not Allowed', 405);
        }
        $admin = requireAdmin();

        $newStatus = $body['status'] ?? '';
        if (!in_array($newStatus, ['active', 'inactive', 'suspended'])) {
            errorResponse('Invalid status value.', 400);
        }

        if ($userId === (int)$admin['id']) {
            errorResponse('You cannot deactivate your own admin account.', 400);
        }

        $stmt = $db->prepare('UPDATE users SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$newStatus, $userId]);

        logActivity([
            'userId' => $admin['id'],
            'action' => 'Updated User Status',
            'entityType' => 'user',
            'entityId' => $userId,
            'details' => "Admin set status of user #{$userId} to {$newStatus}"
        ]);

        jsonResponse([
            'success' => true,
            'message' => "User status updated to {$newStatus}."
        ]);
    }

    // Direct notification: POST /api/users/:id/notify
    if ($secondSegment === 'notify') {
        if ($method !== 'POST') {
            errorResponse('Method Not Allowed', 405);
        }
        $admin = requireAdmin();

        $title = trim($body['title'] ?? '');
        $message = trim($body['message'] ?? '');
        $sendEmailFlag = !empty($body['send_email']);

        if (empty($title) || empty($message)) {
            errorResponse('Title and message are required.', 400);
        }

        $stmt = $db->prepare('SELECT email, full_name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $targetUser = $stmt->fetch();

        if (!$targetUser) {
            errorResponse('User not found.', 404);
        }

        createNotification([
            'userId' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => 'system',
            'link' => '/dashboard',
            'sendAsEmail' => $sendEmailFlag,
            'emailTo' => $targetUser['email']
        ]);

        logActivity([
            'userId' => $admin['id'],
            'action' => 'Sent User Notification',
            'entityType' => 'user',
            'entityId' => $userId,
            'details' => "Sent notification to {$targetUser['email']}: \"{$title}\""
        ]);

        jsonResponse([
            'success' => true,
            'message' => 'Notification dispatched successfully.'
        ]);
    }

    // Single user profile: GET /api/users/:id
    if ($method === 'GET') {
        $admin = requireAdmin();

        $stmt = $db->prepare('
            SELECT u.id, u.role, u.email, u.full_name, u.phone, u.status, u.created_at,
                   up.company_name, up.address, up.avatar_url, up.bio 
            FROM users u 
            LEFT JOIN user_profiles up ON u.id = up.user_id 
            WHERE u.id = ?
        ');
        $stmt->execute([$userId]);
        $userData = $stmt->fetch();

        if (!$userData) {
            errorResponse('User not found.', 404);
        }

        $stmt = $db->prepare('SELECT * FROM projects WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        $projects = $stmt->fetchAll();

        $stmt = $db->prepare('SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        $payments = $stmt->fetchAll();

        $stmt = $db->prepare('SELECT * FROM project_requests WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        $requests = $stmt->fetchAll();

        $userData['id'] = (int)$userData['id'];
        $userData['projects'] = $projects;
        $userData['payments'] = $payments;
        $userData['requests'] = $requests;

        jsonResponse(['success' => true, 'user' => $userData]);
    }

    errorResponse('Not Found', 404);
}
