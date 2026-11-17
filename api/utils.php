<?php
/**
 * DevHorizan Unified API Utility Functions
 * Response helpers, request parsers, activity logger, and notification dispatcher.
 */

require_once __DIR__ . '/db.php';

function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function errorResponse(string $message, int $statusCode = 400, array $extra = []): void {
    jsonResponse(array_merge([
        'success' => false,
        'message' => $message
    ], $extra), $statusCode);
}

function getBody(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw) && php_sapi_name() === 'cli') {
        $raw = file_get_contents('php://stdin');
    }
    if (!empty($raw)) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
    }
    return $_POST ?: [];
}

function getClientIp(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function logActivity(array $params): void {
    try {
        $db = getDb();
        $stmt = $db->prepare('
            INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $params['userId'] ?? null,
            $params['action'] ?? 'Unknown Action',
            $params['entityType'] ?? 'system',
            $params['entityId'] ?? null,
            $params['details'] ?? null,
            $params['ipAddress'] ?? getClientIp()
        ]);
    } catch (Exception $e) {
        error_log('Failed to log activity: ' . $e->getMessage());
    }
}

function sendEmail(array $params): array {
    $to = $params['to'] ?? '';
    $subject = $params['subject'] ?? '';
    $text = $params['text'] ?? '';
    $html = $params['html'] ?? '';

    if (empty($to) || empty($subject)) {
        return ['success' => false, 'message' => 'Recipient and subject required.'];
    }

    $emailUser = getenv('EMAIL_USER') ?: 'devhorizan@gmail.com';
    $headers = [
        'MIME-Version: 1.0',
        'From: DevHorizan Technologies <' . $emailUser . '>',
        'Reply-To: ' . $emailUser,
        'X-Mailer: PHP/' . phpversion()
    ];

    if (!empty($html)) {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $body = $html;
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $body = $text;
    }

    // Try standard mail() if configured, otherwise simulate gracefully
    $mailSent = @mail($to, $subject, $body, implode("\r\n", $headers));

    return [
        'success' => true,
        'simulated' => !$mailSent,
        'message' => $mailSent ? 'Email dispatched' : 'Simulated email logged'
    ];
}

function createNotification(array $params): ?int {
    try {
        $db = getDb();
        $stmt = $db->prepare('
            INSERT INTO notifications (user_id, title, message, type, link, is_read)
            VALUES (?, ?, ?, ?, ?, 0)
        ');
        $stmt->execute([
            $params['userId'],
            $params['title'],
            $params['message'],
            $params['type'] ?? 'system',
            $params['link'] ?? null
        ]);

        $notificationId = (int)$db->lastInsertId();

        if (!empty($params['sendAsEmail']) && !empty($params['emailTo'])) {
            $link = $params['link'] ?? '';
            $linkHtml = $link ? '<div style="margin-top: 20px;"><a href="' . htmlspecialchars($link) . '" style="background: #2f6fed; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block;">View in Portal</a></div>' : '';

            $html = '
                <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;">
                    <div style="background: linear-gradient(135deg, #0f3460, #388da8); color: white; padding: 24px; text-align: center;">
                        <h2 style="margin: 0; font-size: 22px;">DevHorizan Notification</h2>
                    </div>
                    <div style="padding: 24px; background: #ffffff;">
                        <h3 style="color: #1e293b; margin-top: 0;">' . htmlspecialchars($params['title']) . '</h3>
                        <p style="color: #475569; font-size: 15px; line-height: 1.6;">' . nl2br(htmlspecialchars($params['message'])) . '</p>
                        ' . $linkHtml . '
                    </div>
                    <div style="background: #f8fafc; padding: 14px; text-align: center; color: #94a3b8; font-size: 12px; border-top: 1px solid #e2e8f0;">
                        DevHorizan Technologies • Mussoorie Rd, Dehradun, Uttarakhand
                    </div>
                </div>
            ';

            sendEmail([
                'to' => $params['emailTo'],
                'subject' => '[DevHorizan] ' . $params['title'],
                'text' => $params['title'] . "\n\n" . $params['message'],
                'html' => $html
            ]);
        }

        return $notificationId;
    } catch (Exception $e) {
        error_log('Failed to create notification: ' . $e->getMessage());
        return null;
    }
}

function notifyAdmins(array $params): void {
    try {
        $db = getDb();
        $stmt = $db->query("SELECT id, email FROM users WHERE role = 'admin' AND status = 'active'");
        $admins = $stmt->fetchAll();

        foreach ($admins as $admin) {
            createNotification([
                'userId' => $admin['id'],
                'title' => $params['title'],
                'message' => $params['message'],
                'type' => $params['type'] ?? 'system',
                'link' => $params['link'] ?? null,
                'sendAsEmail' => false,
                'emailTo' => $admin['email']
            ]);
        }
    } catch (Exception $e) {
        error_log('Failed to notify admins: ' . $e->getMessage());
    }
}
