<?php
/**
 * Reviews Handler
 * Endpoints:
 *   GET    /api/reviews/public
 *   GET    /api/reviews/my
 *   POST   /api/reviews
 *   GET    /api/reviews/admin
 *   PATCH  /api/reviews/:id/status (or PUT)
 *   DELETE /api/reviews/:id
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../utils.php';

function handleReviews(string $method, array $pathSegments): void {
    $db = getDb();
    $body = getBody();
    $firstSegment = $pathSegments[0] ?? null;

    // 1. GET /api/reviews/public
    if ($firstSegment === 'public') {
        if ($method !== 'GET') {
            errorResponse('Method Not Allowed', 405);
        }

        $stmt = $db->query("
            SELECT 
                id, 
                client_name, 
                company_or_role, 
                project_title, 
                service_category, 
                rating, 
                review_text, 
                created_at 
            FROM client_reviews 
            WHERE status = 'Approved' 
            ORDER BY created_at DESC 
            LIMIT 12
        ");
        $reviews = $stmt->fetchAll();

        foreach ($reviews as &$r) {
            $r['id'] = (int)$r['id'];
            $r['rating'] = (int)$r['rating'];
        }

        jsonResponse([
            'success' => true,
            'count' => count($reviews),
            'reviews' => $reviews
        ]);
    }

    // 2. GET /api/reviews/my
    if ($firstSegment === 'my') {
        if ($method !== 'GET') {
            errorResponse('Method Not Allowed', 405);
        }
        $user = requireAuth();

        $stmt = $db->prepare('
            SELECT * FROM client_reviews 
            WHERE user_id = ? 
            ORDER BY created_at DESC
        ');
        $stmt->execute([$user['id']]);
        $reviews = $stmt->fetchAll();

        foreach ($reviews as &$r) {
            $r['id'] = (int)$r['id'];
            $r['rating'] = (int)$r['rating'];
        }

        jsonResponse(['success' => true, 'reviews' => $reviews]);
    }

    // 3. GET /api/reviews/admin
    if ($firstSegment === 'admin') {
        if ($method !== 'GET') {
            errorResponse('Method Not Allowed', 405);
        }
        $user = requireAdmin();

        $stmt = $db->query('
            SELECT 
                r.*,
                u.email as client_email,
                u.full_name as client_account_name,
                p.title as verified_project_name
            FROM client_reviews r
            JOIN users u ON r.user_id = u.id
            LEFT JOIN projects p ON r.project_id = p.id
            ORDER BY r.created_at DESC
        ');
        $reviews = $stmt->fetchAll();

        $total = count($reviews);
        $pending = 0;
        $approved = 0;
        $rejected = 0;

        foreach ($reviews as &$r) {
            $r['id'] = (int)$r['id'];
            $r['rating'] = (int)$r['rating'];
            if ($r['status'] === 'Pending') $pending++;
            elseif ($r['status'] === 'Approved') $approved++;
            elseif ($r['status'] === 'Rejected') $rejected++;
        }

        jsonResponse([
            'success' => true,
            'stats' => [
                'total' => $total,
                'pending' => $pending,
                'approved' => $approved,
                'rejected' => $rejected
            ],
            'reviews' => $reviews
        ]);
    }

    // 4. POST /api/reviews (Client submit review)
    if ($firstSegment === null || $firstSegment === '') {
        if ($method === 'POST') {
            $user = requireAuth();

            $projectId = !empty($body['project_id']) ? (int)$body['project_id'] : null;
            $rating = isset($body['rating']) ? (int)$body['rating'] : 0;
            $reviewText = trim($body['review_text'] ?? '');
            $companyOrRole = !empty($body['company_or_role']) ? trim($body['company_or_role']) : 'Client Partner';
            $projectTitle = !empty($body['project_title']) ? trim($body['project_title']) : null;
            $serviceCategory = !empty($body['service_category']) ? trim($body['service_category']) : null;

            if ($rating < 1 || $rating > 5) {
                errorResponse('Rating must be between 1 and 5 stars.', 400);
            }

            if (mb_strlen($reviewText) < 10) {
                errorResponse('Review feedback must be at least 10 characters long.', 400);
            }

            $stmt = $db->prepare('SELECT id, full_name, email FROM users WHERE id = ?');
            $stmt->execute([$user['id']]);
            $clientUser = $stmt->fetch();
            $clientName = $clientUser ? $clientUser['full_name'] : ($user['full_name'] ?: 'Verified Client');

            if ($projectId) {
                $stmt = $db->prepare('SELECT id, title, category FROM projects WHERE id = ? AND user_id = ?');
                $stmt->execute([$projectId, $user['id']]);
                $proj = $stmt->fetch();
                if ($proj) {
                    $projectTitle = $proj['title'];
                    $serviceCategory = $proj['category'];
                }
            }

            $stmt = $db->prepare('
                INSERT INTO client_reviews (
                    project_id,
                    user_id,
                    client_name,
                    company_or_role,
                    project_title,
                    service_category,
                    rating,
                    review_text,
                    status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "Pending")
            ');
            $stmt->execute([
                $projectId,
                $user['id'],
                $clientName,
                $companyOrRole,
                $projectTitle,
                $serviceCategory ?: 'General Project',
                $rating,
                $reviewText
            ]);
            $reviewId = (int)$db->lastInsertId();

            logActivity([
                'userId' => $user['id'],
                'action' => 'REVIEW_SUBMITTED',
                'entityType' => 'review',
                'entityId' => $reviewId,
                'details' => "{$clientName} submitted a {$rating}-star verified review."
            ]);

            notifyAdmins([
                'title' => '🌟 New Client Review Submitted',
                'message' => "{$clientName} submitted a {$rating}-star review for approval.",
                'type' => 'system',
                'link' => '/admin#reviews'
            ]);

            jsonResponse([
                'success' => true,
                'message' => 'Thank you! Your verified review has been submitted for admin approval.',
                'reviewId' => $reviewId
            ], 201);
        }

        errorResponse('Method Not Allowed', 405);
    }

    // Specific review operations: /api/reviews/:id or /api/reviews/:id/status
    $reviewId = (int)$firstSegment;
    $secondSegment = $pathSegments[1] ?? null;

    if ($secondSegment === 'status') {
        if ($method !== 'PATCH' && $method !== 'PUT') {
            errorResponse('Method Not Allowed', 405);
        }
        $user = requireAdmin();

        $status = $body['status'] ?? '';
        $adminFeedback = !empty($body['admin_feedback']) ? trim($body['admin_feedback']) : null;

        if (!in_array($status, ['Approved', 'Rejected', 'Pending'])) {
            errorResponse('Invalid review status. Must be Approved, Rejected, or Pending.', 400);
        }

        $stmt = $db->prepare('SELECT * FROM client_reviews WHERE id = ?');
        $stmt->execute([$reviewId]);
        $review = $stmt->fetch();
        if (!$review) {
            errorResponse('Review not found.', 404);
        }

        $stmt = $db->prepare('
            UPDATE client_reviews 
            SET status = ?, admin_feedback = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ');
        $stmt->execute([$status, $adminFeedback, $reviewId]);

        $statusMsg = $status === 'Approved'
            ? 'Your verified review has been approved and published to the DevHorizan website! Thank you for your feedback.'
            : "Your submitted review was marked as {$status}.";

        createNotification([
            'userId' => $review['user_id'],
            'title' => $status === 'Approved' ? '🎉 Review Published Live' : 'Notice: Review Status Updated',
            'message' => $statusMsg,
            'type' => 'system',
            'link' => '/dashboard'
        ]);

        logActivity([
            'userId' => $user['id'],
            'action' => "REVIEW_" . strtoupper($status),
            'entityType' => 'review',
            'entityId' => $reviewId,
            'details' => "Admin " . strtolower($status) . " review #{$reviewId} from {$review['client_name']}"
        ]);

        jsonResponse([
            'success' => true,
            'message' => "Review #{$reviewId} has been " . strtolower($status) . " successfully.",
            'status' => $status
        ]);
    }

    if ($method === 'DELETE') {
        $user = requireAdmin();
        $stmt = $db->prepare('SELECT * FROM client_reviews WHERE id = ?');
        $stmt->execute([$reviewId]);
        $review = $stmt->fetch();
        if (!$review) {
            errorResponse('Review not found.', 404);
        }

        $stmt = $db->prepare('DELETE FROM client_reviews WHERE id = ?');
        $stmt->execute([$reviewId]);

        logActivity([
            'userId' => $user['id'],
            'action' => 'REVIEW_DELETED',
            'entityType' => 'review',
            'entityId' => $reviewId,
            'details' => "Admin deleted review #{$reviewId} from {$review['client_name']}"
        ]);

        jsonResponse([
            'success' => true,
            'message' => "Review #{$reviewId} deleted successfully."
        ]);
    }

    errorResponse('Not Found', 404);
}
