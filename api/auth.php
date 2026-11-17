<?php
/**
 * DevHorizan Authentication & JWT Security Helper
 * Native PHP HMAC-SHA256 JWT implementation matching Node.js jwt.sign/verify
 */

require_once __DIR__ . '/db.php';

define('JWT_SECRET', getenv('JWT_SECRET') ?: 'devhorizan_jwt_super_secret_key_2026');

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string {
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}

function generateJwt(array $payload, int $days = 7): string {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload['iat'] = time();
    $payload['exp'] = time() + ($days * 86400);
    $payloadJson = json_encode($payload);

    $base64UrlHeader = base64UrlEncode($header);
    $base64UrlPayload = base64UrlEncode($payloadJson);

    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET, true);
    $base64UrlSignature = base64UrlEncode($signature);

    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

function verifyJwt(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $parts;

    $signature = base64UrlDecode($base64UrlSignature);
    $expectedSignature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET, true);

    if (!hash_equals($expectedSignature, $signature)) {
        return null;
    }

    $payload = json_decode(base64UrlDecode($base64UrlPayload), true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
        return null; // Expired or malformed
    }

    return $payload;
}

function getBearerToken(): ?string {
    $headers = null;
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
    }

    // Check Authorization header
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    if ($authHeader && preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
        return $matches[1];
    }

    // Fallback to cookie
    if (isset($_COOKIE['devhorizan_token'])) {
        return $_COOKIE['devhorizan_token'];
    }

    return null;
}

function getAuthenticatedUser(): ?array {
    $token = getBearerToken();
    if (!$token) {
        return null;
    }

    $payload = verifyJwt($token);
    if (!$payload || !isset($payload['id'])) {
        return null;
    }

    $db = getDb();
    $stmt = $db->prepare('SELECT id, role, email, full_name, phone, status, created_at FROM users WHERE id = ?');
    $stmt->execute([$payload['id']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        return null;
    }

    return $user;
}

function requireAuth(): array {
    $user = getAuthenticatedUser();
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Authentication required. Please sign in.'
        ]);
        exit;
    }
    return $user;
}

function requireAdmin(): array {
    $user = requireAuth();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. Administrator privileges required.'
        ]);
        exit;
    }
    return $user;
}
