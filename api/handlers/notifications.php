<?php
/**
 * Notifications Handler
 * Endpoints:
 *   GET /api/notifications
 *   PUT /api/notifications/:id/read
 *   PUT /api/notifications/read-all
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../utils.php';

function handleNotifications(string $method, array $pathSegments): void {
    $db = getDb();
    $firstSegment = $pathSegments[0] ?? null;
    $user = requireAuth();

    if ($firstSegment === null || $firstSegment === '') {
        if ($method === 'GET') {
            $stmt = $db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
            $stmt->execute([$user['id']]);
            $notifications = $stmt->fetchAll();

            $stmt = $db->prepare('SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0');
            $stmt->execute([$user['id']]);
            $unreadRow = $stmt->fetch();
            $unreadCount = $unreadRow ? (int)$unreadRow['unread'] : 0;

            foreach ($notifications as &$n) {
                $n['id'] = (int)$n['id'];
                $n['user_id'] = (int)$n['user_id'];
                $n['is_read'] = (int)$n['is_read'];
            }

            jsonResponse([
                'success' => true,
                'notifications' => $notifications,
                'unreadCount' => $unreadCount
            ]);
        }

        errorResponse('Method Not Allowed', 405);
    }

    if ($firstSegment === 'read-all') {
        if ($method !== 'PUT') {
            errorResponse('Method Not Allowed', 405);
        }

        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        $stmt->execute([$user['id']]);

        jsonResponse([
            'success' => true,
            'message' => 'All notifications marked as read.'
        ]);
    }

    $notificationId = (int)$firstSegment;
    $secondSegment = $pathSegments[1] ?? null;

    if ($secondSegment === 'read') {
        if ($method !== 'PUT') {
            errorResponse('Method Not Allowed', 405);
        }

        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([$notificationId, $user['id']]);

        jsonResponse(['success' => true]);
    }

    errorResponse('Not Found', 404);
}
