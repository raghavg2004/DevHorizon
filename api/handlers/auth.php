<?php
/**
 * Auth Handler
 * Endpoints:
 *   POST /api/auth/register
 *   POST /api/auth/login
 *   POST /api/auth/logout
 *   GET  /api/auth/me
 *   PUT  /api/auth/profile
 *   PUT  /api/auth/change-password
 *   POST /api/auth/forgot-password
 *   POST /api/auth/reset-password
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../utils.php';

function handleAuth(string $method, array $pathSegments): void {
    $action = $pathSegments[0] ?? '';
    $db = getDb();
    $body = getBody();

    switch ($action) {
        case 'register':
            if ($method !== 'POST') {
                errorResponse('Method Not Allowed', 405);
            }

            $email = trim(strtolower($body['email'] ?? ''));
            $password = $body['password'] ?? '';
            $fullName = trim($body['full_name'] ?? '');
            $phone = !empty($body['phone']) ? trim($body['phone']) : null;
            $companyName = !empty($body['company_name']) ? trim($body['company_name']) : null;

            if (empty($email) || empty($password) || empty($fullName)) {
                errorResponse('Please provide full name, email, and password.', 400);
            }

            $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                errorResponse('This email address is already registered.', 400);
            }

            $passwordHash = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $db->prepare("
                INSERT INTO users (role, email, password_hash, full_name, phone, status)
                VALUES ('client', ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$email, $passwordHash, $fullName, $phone]);
            $userId = (int)$db->lastInsertId();

            $stmt = $db->prepare("
                INSERT INTO user_profiles (user_id, company_name, avatar_url)
                VALUES (?, ?, '/assets/img/avatar-default.png')
            ");
            $stmt->execute([$userId, $companyName]);

            logActivity([
                'userId' => $userId,
                'action' => 'Registered Account',
                'entityType' => 'user',
                'entityId' => $userId,
                'details' => "New client registration for {$fullName} ({$email})"
            ]);

            notifyAdmins([
                'title' => 'New Client Registration',
                'message' => "{$fullName} ({$email}) registered as a new client.",
                'type' => 'system',
                'link' => '/admin#users'
            ]);

            createNotification([
                'userId' => $userId,
                'title' => 'Welcome to DevHorizan!',
                'message' => 'Your client portal account is ready. You can submit project requests, track milestones, and manage payments.',
                'type' => 'system',
                'link' => '/dashboard'
            ]);

            $token = generateJwt([
                'id' => $userId,
                'email' => $email,
                'role' => 'client'
            ]);

            setcookie('devhorizan_token', $token, [
                'expires' => time() + (7 * 86400),
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);

            jsonResponse([
                'success' => true,
                'message' => 'Registration successful! Welcome to DevHorizan.',
                'token' => $token,
                'user' => [
                    'id' => $userId,
                    'email' => $email,
                    'full_name' => $fullName,
                    'role' => 'client'
                ]
            ], 201);
            break;

        case 'login':
            if ($method !== 'POST') {
                errorResponse('Method Not Allowed', 405);
            }

            $email = trim(strtolower($body['email'] ?? ''));
            $password = $body['password'] ?? '';

            if (empty($email) || empty($password)) {
                errorResponse('Please provide both email and password.', 400);
            }

            $stmt = $db->prepare('SELECT id, role, email, password_hash, full_name, phone, status FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                errorResponse('Invalid email or password.', 401);
            }

            if ($user['status'] !== 'active') {
                errorResponse('Your account is currently inactive or suspended.', 403);
            }

            if (!password_verify($password, $user['password_hash'])) {
                errorResponse('Invalid email or password.', 401);
            }

            $token = generateJwt([
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'role' => $user['role']
            ]);

            setcookie('devhorizan_token', $token, [
                'expires' => time() + (7 * 86400),
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);

            logActivity([
                'userId' => $user['id'],
                'action' => 'Logged In',
                'entityType' => 'auth',
                'entityId' => $user['id'],
                'details' => 'User logged in from ' . getClientIp()
            ]);

            jsonResponse([
                'success' => true,
                'message' => 'Login successful!',
                'token' => $token,
                'user' => [
                    'id' => (int)$user['id'],
                    'email' => $user['email'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role'],
                    'phone' => $user['phone']
                ]
            ]);
            break;

        case 'logout':
            setcookie('devhorizan_token', '', [
                'expires' => time() - 3600,
                'path' => '/'
            ]);
            jsonResponse([
                'success' => true,
                'message' => 'Logged out successfully.'
            ]);
            break;

        case 'me':
            if ($method !== 'GET') {
                errorResponse('Method Not Allowed', 405);
            }
            $user = requireAuth();

            $stmt = $db->prepare('SELECT company_name, address, avatar_url, bio FROM user_profiles WHERE user_id = ?');
            $stmt->execute([$user['id']]);
            $profile = $stmt->fetch() ?: [];

            $stmt = $db->prepare('SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0');
            $stmt->execute([$user['id']]);
            $unreadRow = $stmt->fetch();
            $unreadCount = $unreadRow ? (int)$unreadRow['unread'] : 0;

            jsonResponse([
                'success' => true,
                'user' => array_merge($user, [
                    'profile' => $profile,
                    'unreadNotifications' => $unreadCount
                ])
            ]);
            break;

        case 'profile':
            if ($method !== 'PUT') {
                errorResponse('Method Not Allowed', 405);
            }
            $user = requireAuth();

            $fullName = !empty($body['full_name']) ? trim($body['full_name']) : null;
            $phone = isset($body['phone']) ? trim($body['phone']) : null;
            $companyName = !empty($body['company_name']) ? trim($body['company_name']) : null;
            $address = !empty($body['address']) ? trim($body['address']) : null;
            $bio = !empty($body['bio']) ? trim($body['bio']) : null;
            $avatarUrl = !empty($body['avatar_url']) ? trim($body['avatar_url']) : null;

            if ($fullName !== null) {
                $stmt = $db->prepare('UPDATE users SET full_name = ?, phone = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $stmt->execute([$fullName, $phone, $user['id']]);
            }

            $stmt = $db->prepare('SELECT id FROM user_profiles WHERE user_id = ?');
            $stmt->execute([$user['id']]);
            $hasProfile = $stmt->fetch();

            if ($hasProfile) {
                $stmt = $db->prepare('
                    UPDATE user_profiles
                    SET company_name = ?, address = ?, bio = ?, avatar_url = COALESCE(?, avatar_url), updated_at = CURRENT_TIMESTAMP
                    WHERE user_id = ?
                ');
                $stmt->execute([$companyName, $address, $bio, $avatarUrl, $user['id']]);
            } else {
                $stmt = $db->prepare('
                    INSERT INTO user_profiles (user_id, company_name, address, bio, avatar_url)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmt->execute([$user['id'], $companyName, $address, $bio, $avatarUrl ?: '/assets/img/avatar-default.png']);
            }

            logActivity([
                'userId' => $user['id'],
                'action' => 'Updated Profile',
                'entityType' => 'user',
                'entityId' => $user['id'],
                'details' => 'User updated personal profile information'
            ]);

            jsonResponse([
                'success' => true,
                'message' => 'Profile updated successfully.'
            ]);
            break;

        case 'change-password':
            if ($method !== 'PUT') {
                errorResponse('Method Not Allowed', 405);
            }
            $user = requireAuth();

            $currentPassword = $body['current_password'] ?? '';
            $newPassword = $body['new_password'] ?? '';

            if (empty($currentPassword) || empty($newPassword)) {
                errorResponse('Please provide both current and new password.', 400);
            }

            $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
                errorResponse('Current password is incorrect.', 400);
            }

            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $db->prepare('UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$newHash, $user['id']]);

            logActivity([
                'userId' => $user['id'],
                'action' => 'Changed Password',
                'entityType' => 'auth',
                'entityId' => $user['id'],
                'details' => 'User changed account password'
            ]);

            jsonResponse([
                'success' => true,
                'message' => 'Password changed successfully.'
            ]);
            break;

        case 'forgot-password':
            if ($method !== 'POST') {
                errorResponse('Method Not Allowed', 405);
            }

            $email = trim(strtolower($body['email'] ?? ''));
            if (empty($email)) {
                errorResponse('Please provide an email address.', 400);
            }

            $stmt = $db->prepare('SELECT id, full_name FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $foundUser = $stmt->fetch();

            if (!$foundUser) {
                // Return success anyway for security
                jsonResponse([
                    'success' => true,
                    'message' => 'If an account exists with this email, a reset link will be sent shortly.'
                ]);
            }

            $resetToken = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', time() + 3600);

            $stmt = $db->prepare('UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?');
            $stmt->execute([$resetToken, $expiry, $foundUser['id']]);

            $host = $_SERVER['HTTP_HOST'] ?? 'localhost:3000';
            $resetLink = "http://{$host}/reset-password.html?token={$resetToken}&email=" . urlencode($email);

            sendEmail([
                'to' => $email,
                'subject' => '[DevHorizan] Password Reset Request',
                'text' => "Hello {$foundUser['full_name']},\n\nYou requested a password reset for your DevHorizan account.\n\nClick the link below:\n{$resetLink}\n\nValid for 1 hour.",
                'html' => "
                    <div style=\"font-family: Arial, sans-serif; max-width: 550px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 10px; padding: 25px;\">
                        <h2 style=\"color: #0f3460; margin-top: 0;\">Password Reset Request</h2>
                        <p>Hello <strong>" . htmlspecialchars($foundUser['full_name']) . "</strong>,</p>
                        <p>You recently requested to reset the password for your DevHorizan account. Click the button below to proceed:</p>
                        <div style=\"margin: 25px 0;\">
                            <a href=\"{$resetLink}\" style=\"background: #2f6fed; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block;\">Reset Password</a>
                        </div>
                        <p style=\"color: #64748b; font-size: 13px;\">This link expires in 1 hour. If you did not initiate this request, you can safely ignore this email.</p>
                    </div>
                "
            ]);

            jsonResponse([
                'success' => true,
                'message' => 'If an account exists with this email, a reset link has been dispatched.'
            ]);
            break;

        case 'reset-password':
            if ($method !== 'POST') {
                errorResponse('Method Not Allowed', 405);
            }

            $token = $body['token'] ?? '';
            $newPassword = $body['new_password'] ?? '';

            if (empty($token) || empty($newPassword)) {
                errorResponse('Invalid request. Token and new password required.', 400);
            }

            $stmt = $db->prepare('SELECT id, reset_token_expiry FROM users WHERE reset_token = ?');
            $stmt->execute([$token]);
            $userWithToken = $stmt->fetch();

            if (!$userWithToken) {
                errorResponse('Invalid or expired password reset link.', 400);
            }

            if (strtotime($userWithToken['reset_token_expiry']) < time()) {
                errorResponse('Password reset link has expired. Please request a new one.', 400);
            }

            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $db->prepare('
                UPDATE users 
                SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ');
            $stmt->execute([$newHash, $userWithToken['id']]);

            logActivity([
                'userId' => $userWithToken['id'],
                'action' => 'Reset Password',
                'entityType' => 'auth',
                'entityId' => $userWithToken['id'],
                'details' => 'Password was reset using reset token'
            ]);

            jsonResponse([
                'success' => true,
                'message' => 'Your password has been reset successfully. You can now log in.'
            ]);
            break;

        default:
            errorResponse('Not Found', 404);
            break;
    }
}
