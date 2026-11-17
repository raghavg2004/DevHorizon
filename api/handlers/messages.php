<?php
/**
 * Messages Handler
 * Endpoints:
 *   GET  /api/messages/project/:projectId
 *   POST /api/messages/project/:projectId
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../utils.php';

function handleMessages(string $method, array $pathSegments): void {
    $db = getDb();
    $body = getBody();
    $user = requireAuth();

    $firstSegment = $pathSegments[0] ?? null;
    $projectId = isset($pathSegments[1]) ? (int)$pathSegments[1] : 0;

    if ($firstSegment !== 'project' || $projectId <= 0) {
        errorResponse('Not Found', 404);
    }

    $stmt = $db->prepare('SELECT user_id, title FROM projects WHERE id = ?');
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        errorResponse('Project not found.', 404);
    }

    if ($user['role'] !== 'admin' && (int)$project['user_id'] !== (int)$user['id']) {
        errorResponse('Permission denied.', 403);
    }

    if ($method === 'GET') {
        $stmt = $db->prepare('
            SELECT m.*, u.full_name as sender_name, u.role as sender_role 
            FROM messages m 
            JOIN users u ON m.sender_id = u.id 
            WHERE m.project_id = ? 
            ORDER BY m.created_at ASC
        ');
        $stmt->execute([$projectId]);
        $messages = $stmt->fetchAll();

        foreach ($messages as &$m) {
            $m['id'] = (int)$m['id'];
            $m['project_id'] = (int)$m['project_id'];
            $m['sender_id'] = (int)$m['sender_id'];
            $m['receiver_id'] = (int)$m['receiver_id'];
        }

        jsonResponse(['success' => true, 'messages' => $messages]);
    }

    if ($method === 'POST') {
        $messageText = trim($body['message'] ?? '');
        if (empty($messageText)) {
            errorResponse('Message cannot be empty.', 400);
        }

        if ($user['role'] === 'admin') {
            $receiverId = (int)$project['user_id'];
        } else {
            $stmt = $db->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
            $admin = $stmt->fetch();
            $receiverId = $admin ? (int)$admin['id'] : 1;
        }

        $stmt = $db->prepare('
            INSERT INTO messages (project_id, sender_id, receiver_id, message)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$projectId, $user['id'], $receiverId, $messageText]);
        $messageId = (int)$db->lastInsertId();

        $preview = mb_substr($messageText, 0, 100) . (mb_strlen($messageText) > 100 ? '...' : '');

        if ($user['role'] === 'admin') {
            createNotification([
                'userId' => $receiverId,
                'title' => "New Message on \"{$project['title']}\"",
                'message' => "{$user['full_name']}: {$preview}",
                'type' => 'project',
                'link' => '/dashboard#projects'
            ]);
        } else {
            notifyAdmins([
                'title' => "Client Query: \"{$project['title']}\"",
                'message' => "{$user['full_name']}: {$preview}",
                'type' => 'project',
                'link' => '/admin#projects'
            ]);
        }

        jsonResponse([
            'success' => true,
            'message' => 'Message sent.',
            'messageId' => $messageId
        ], 201);
    }

    errorResponse('Method Not Allowed', 405);
}
