<?php
/**
 * Projects Handler
 * Endpoints:
 *   GET    /api/projects
 *   GET    /api/projects/:id
 *   POST   /api/projects
 *   PUT    /api/projects/:id
 *   DELETE /api/projects/:id
 *   POST   /api/projects/:id/milestones
 *   PUT    /api/projects/:id/milestones/:mId
 *   POST   /api/projects/:id/updates
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../utils.php';

function handleProjects(string $method, array $pathSegments): void {
    $db = getDb();
    $body = getBody();
    $firstSegment = $pathSegments[0] ?? null;

    // List all projects: GET /api/projects
    if ($firstSegment === null || $firstSegment === '') {
        if ($method === 'GET') {
            $user = requireAuth();
            if ($user['role'] === 'admin') {
                $sql = '
                    SELECT p.*, u.full_name as client_name, u.email as client_email 
                    FROM projects p 
                    JOIN users u ON p.user_id = u.id 
                    ORDER BY p.updated_at DESC
                ';
                $stmt = $db->query($sql);
                $projects = $stmt->fetchAll();
            } else {
                $sql = '
                    SELECT p.*, u.full_name as client_name, u.email as client_email 
                    FROM projects p 
                    JOIN users u ON p.user_id = u.id 
                    WHERE p.user_id = ? 
                    ORDER BY p.updated_at DESC
                ';
                $stmt = $db->prepare($sql);
                $stmt->execute([$user['id']]);
                $projects = $stmt->fetchAll();
            }

            // Cast numeric fields for consistency
            foreach ($projects as &$p) {
                $p['id'] = (int)$p['id'];
                $p['user_id'] = (int)$p['user_id'];
                $p['progress_percent'] = (int)$p['progress_percent'];
                $p['total_cost'] = (float)$p['total_cost'];
                $p['paid_amount'] = (float)$p['paid_amount'];
                $p['remaining_balance'] = (float)$p['remaining_balance'];
            }

            jsonResponse(['success' => true, 'projects' => $projects]);
        }

        if ($method === 'POST') {
            $user = requireAdmin();
            $userId = (int)($body['user_id'] ?? 0);
            $title = trim($body['title'] ?? '');
            $category = $body['category'] ?? 'Web Development';
            $description = !empty($body['description']) ? trim($body['description']) : null;
            $status = $body['status'] ?? 'Approved';
            $progressPercent = isset($body['progress_percent']) ? (int)$body['progress_percent'] : 0;
            $totalCost = isset($body['total_cost']) ? (float)$body['total_cost'] : 0.0;
            $startDate = !empty($body['start_date']) ? $body['start_date'] : date('Y-m-d');
            $expectedDeliveryDate = !empty($body['expected_delivery_date']) ? $body['expected_delivery_date'] : null;

            if ($userId <= 0 || empty($title)) {
                errorResponse('User ID and project title are required.', 400);
            }

            $stmt = $db->prepare('SELECT id, email, full_name FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $clientUser = $stmt->fetch();
            if (!$clientUser) {
                errorResponse('Assigned client user not found.', 404);
            }

            // Check if project already exists for this client with same title
            $stmt = $db->prepare('
                SELECT * FROM projects 
                WHERE user_id = ? AND LOWER(TRIM(title)) = LOWER(TRIM(?)) 
                ORDER BY id DESC LIMIT 1
            ');
            $stmt->execute([$userId, $title]);
            $existingProject = $stmt->fetch();

            if ($existingProject) {
                $projectId = (int)$existingProject['id'];
                $newCost = $totalCost > 0 ? $totalCost : (float)$existingProject['total_cost'];
                $newRemaining = max(0.0, $newCost - (float)$existingProject['paid_amount']);

                $stmt = $db->prepare('
                    UPDATE projects 
                    SET category = ?,
                        description = COALESCE(?, description),
                        status = ?,
                        progress_percent = ?,
                        total_cost = ?,
                        remaining_balance = ?,
                        start_date = COALESCE(?, start_date),
                        expected_delivery_date = COALESCE(?, expected_delivery_date),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ');
                $stmt->execute([
                    $category,
                    $description,
                    $status,
                    $progressPercent,
                    $newCost,
                    $newRemaining,
                    $startDate,
                    $expectedDeliveryDate ?: $existingProject['expected_delivery_date'],
                    $projectId
                ]);

                // Update invoice
                $stmt = $db->prepare('SELECT * FROM payments WHERE project_id = ? ORDER BY id ASC LIMIT 1');
                $stmt->execute([$projectId]);
                $existingPayment = $stmt->fetch();

                if ($existingPayment) {
                    $payRemaining = max(0.0, $newCost - (float)$existingPayment['amount_paid']);
                    $payStatus = (float)$existingPayment['amount_paid'] >= $newCost ? 'Paid' : ((float)$existingPayment['amount_paid'] > 0 ? 'Partially Paid' : $existingPayment['status']);

                    $stmt = $db->prepare('
                        UPDATE payments 
                        SET total_amount = ?, remaining_balance = ?, status = ?, due_date = COALESCE(?, due_date), updated_at = CURRENT_TIMESTAMP 
                        WHERE id = ?
                    ');
                    $stmt->execute([$newCost, $payRemaining, $payStatus, $expectedDeliveryDate ?: $existingPayment['due_date'], $existingPayment['id']]);
                } else if ($newCost > 0) {
                    $invNum = 'INV-' . date('Y') . '-' . str_pad((string)$projectId, 4, '0', STR_PAD_LEFT);
                    $stmt = $db->prepare('
                        INSERT INTO payments (invoice_number, project_id, user_id, total_amount, amount_paid, remaining_balance, advance_required, status, due_date, notes)
                        VALUES (?, ?, ?, ?, 0, ?, ?, "Pending", ?, ?)
                    ');
                    $stmt->execute([
                        $invNum,
                        $projectId,
                        $userId,
                        $newCost,
                        $newCost,
                        $newCost * 0.5,
                        $expectedDeliveryDate,
                        "Initial invoice generated for project: {$title}"
                    ]);
                }
            } else {
                $stmt = $db->prepare('
                    INSERT INTO projects (user_id, title, category, description, status, progress_percent, total_cost, paid_amount, remaining_balance, start_date, expected_delivery_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)
                ');
                $stmt->execute([
                    $userId,
                    $title,
                    $category,
                    $description,
                    $status,
                    $progressPercent,
                    $totalCost,
                    $totalCost,
                    $startDate,
                    $expectedDeliveryDate
                ]);
                $projectId = (int)$db->lastInsertId();

                if ($totalCost > 0) {
                    $invNum = 'INV-' . date('Y') . '-' . str_pad((string)$projectId, 4, '0', STR_PAD_LEFT);
                    $stmt = $db->prepare('
                        INSERT INTO payments (invoice_number, project_id, user_id, total_amount, amount_paid, remaining_balance, advance_required, status, due_date, notes)
                        VALUES (?, ?, ?, ?, 0, ?, ?, "Pending", ?, ?)
                    ');
                    $stmt->execute([
                        $invNum,
                        $projectId,
                        $userId,
                        $totalCost,
                        $totalCost,
                        $totalCost * 0.5,
                        $expectedDeliveryDate,
                        "Initial invoice generated for project: {$title}"
                    ]);
                }
            }

            logActivity([
                'userId' => $user['id'],
                'action' => 'Created Project',
                'entityType' => 'project',
                'entityId' => $projectId,
                'details' => "Admin created project \"{$title}\" for client {$clientUser['full_name']} ({$totalCost} INR)"
            ]);

            createNotification([
                'userId' => $userId,
                'title' => 'New Project Created',
                'message' => "Your project \"{$title}\" has been set up with status: {$status}.",
                'type' => 'project',
                'link' => '/dashboard#projects',
                'sendAsEmail' => true,
                'emailTo' => $clientUser['email']
            ]);

            jsonResponse([
                'success' => true,
                'message' => 'Project created successfully!',
                'projectId' => $projectId
            ], 201);
        }

        errorResponse('Method Not Allowed', 405);
    }

    $projectId = (int)$firstSegment;
    $secondSegment = $pathSegments[1] ?? null;

    // Sub-routes on /api/projects/:id/...
    if ($secondSegment === 'milestones') {
        $milestoneId = isset($pathSegments[2]) ? (int)$pathSegments[2] : null;

        if ($method === 'POST') {
            $user = requireAdmin();
            $title = trim($body['title'] ?? '');
            $description = !empty($body['description']) ? trim($body['description']) : null;
            $dueDate = !empty($body['due_date']) ? $body['due_date'] : null;
            $status = $body['status'] ?? 'Pending';

            if (empty($title)) {
                errorResponse('Milestone title is required.', 400);
            }

            $stmt = $db->prepare('SELECT user_id, title FROM projects WHERE id = ?');
            $stmt->execute([$projectId]);
            $project = $stmt->fetch();
            if (!$project) {
                errorResponse('Project not found.', 404);
            }

            $stmt = $db->prepare('
                INSERT INTO project_milestones (project_id, title, description, due_date, status)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$projectId, $title, $description, $dueDate, $status]);
            $milestoneId = (int)$db->lastInsertId();

            createNotification([
                'userId' => $project['user_id'],
                'title' => 'New Milestone Added',
                'message' => "A new milestone \"{$title}\" has been added to project \"{$project['title']}\".",
                'type' => 'project',
                'link' => '/dashboard#projects'
            ]);

            jsonResponse(['success' => true, 'message' => 'Milestone added.', 'milestoneId' => $milestoneId], 201);
        }

        if ($method === 'PUT' && $milestoneId) {
            $user = requireAdmin();
            $status = $body['status'] ?? null;
            $title = !empty($body['title']) ? trim($body['title']) : null;
            $description = isset($body['description']) ? trim($body['description']) : null;
            $dueDate = !empty($body['due_date']) ? $body['due_date'] : null;

            $stmt = $db->prepare('SELECT * FROM project_milestones WHERE id = ? AND project_id = ?');
            $stmt->execute([$milestoneId, $projectId]);
            $milestone = $stmt->fetch();
            if (!$milestone) {
                errorResponse('Milestone not found.', 404);
            }

            $completedAt = $status === 'Completed' ? date('Y-m-d H:i:s') : null;

            $stmt = $db->prepare('
                UPDATE project_milestones 
                SET status = COALESCE(?, status),
                    title = COALESCE(?, title),
                    description = COALESCE(?, description),
                    due_date = COALESCE(?, due_date),
                    completed_at = ?
                WHERE id = ?
            ');
            $stmt->execute([$status, $title, $description, $dueDate, $completedAt, $milestoneId]);

            $stmt = $db->prepare('SELECT user_id, title FROM projects WHERE id = ?');
            $stmt->execute([$projectId]);
            $project = $stmt->fetch();

            if ($project && $status === 'Completed') {
                createNotification([
                    'userId' => $project['user_id'],
                    'title' => 'Milestone Completed!',
                    'message' => "Milestone \"{$milestone['title']}\" on project \"{$project['title']}\" has been marked as completed.",
                    'type' => 'project',
                    'link' => '/dashboard#projects'
                ]);
            }

            jsonResponse(['success' => true, 'message' => 'Milestone updated successfully.']);
        }

        errorResponse('Method Not Allowed', 405);
    }

    if ($secondSegment === 'updates') {
        if ($method === 'POST') {
            $user = requireAdmin();
            $title = trim($body['title'] ?? '');
            $content = trim($body['content'] ?? '');

            if (empty($title) || empty($content)) {
                errorResponse('Update title and content are required.', 400);
            }

            $stmt = $db->prepare('SELECT p.user_id, p.title, u.email FROM projects p JOIN users u ON p.user_id = u.id WHERE p.id = ?');
            $stmt->execute([$projectId]);
            $project = $stmt->fetch();
            if (!$project) {
                errorResponse('Project not found.', 404);
            }

            $stmt = $db->prepare('
                INSERT INTO project_updates (project_id, author_id, title, content)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([$projectId, $user['id'], $title, $content]);

            createNotification([
                'userId' => $project['user_id'],
                'title' => "Update on {$project['title']}: {$title}",
                'message' => $content,
                'type' => 'project',
                'link' => '/dashboard#projects',
                'sendAsEmail' => true,
                'emailTo' => $project['email']
            ]);

            jsonResponse(['success' => true, 'message' => 'Project update posted.'], 201);
        }

        errorResponse('Method Not Allowed', 405);
    }

    // Single Project operations: /api/projects/:id
    if ($method === 'GET') {
        $user = requireAuth();
        $stmt = $db->prepare('
            SELECT p.*, u.full_name as client_name, u.email as client_email, u.phone as client_phone 
            FROM projects p 
            JOIN users u ON p.user_id = u.id 
            WHERE p.id = ?
        ');
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();

        if (!$project) {
            errorResponse('Project not found.', 404);
        }

        if ($user['role'] !== 'admin' && (int)$project['user_id'] !== (int)$user['id']) {
            errorResponse('Permission denied to view this project.', 403);
        }

        // Milestones
        $stmt = $db->prepare('SELECT * FROM project_milestones WHERE project_id = ? ORDER BY due_date ASC, id ASC');
        $stmt->execute([$projectId]);
        $milestones = $stmt->fetchAll();

        // Updates
        $stmt = $db->prepare('
            SELECT pu.*, u.full_name as author_name, u.role as author_role 
            FROM project_updates pu 
            JOIN users u ON pu.author_id = u.id 
            WHERE pu.project_id = ? 
            ORDER BY pu.created_at DESC
        ');
        $stmt->execute([$projectId]);
        $updates = $stmt->fetchAll();

        // Documents
        $stmt = $db->prepare('SELECT * FROM documents WHERE project_id = ? ORDER BY created_at DESC');
        $stmt->execute([$projectId]);
        $documents = $stmt->fetchAll();

        // Payments
        $stmt = $db->prepare('SELECT * FROM payments WHERE project_id = ? ORDER BY due_date ASC, id ASC');
        $stmt->execute([$projectId]);
        $payments = $stmt->fetchAll();

        $project['milestones'] = $milestones;
        $project['updates'] = $updates;
        $project['documents'] = $documents;
        $project['payments'] = $payments;

        jsonResponse(['success' => true, 'project' => $project]);
    }

    if ($method === 'PUT') {
        $user = requireAdmin();
        $stmt = $db->prepare('SELECT * FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();
        if (!$project) {
            errorResponse('Project not found.', 404);
        }

        $title = !empty($body['title']) ? trim($body['title']) : $project['title'];
        $category = !empty($body['category']) ? trim($body['category']) : $project['category'];
        $description = isset($body['description']) ? trim($body['description']) : $project['description'];
        $status = !empty($body['status']) ? trim($body['status']) : $project['status'];
        $newProgress = isset($body['progress_percent']) ? (int)$body['progress_percent'] : (int)$project['progress_percent'];
        $newCost = isset($body['total_cost']) ? (float)$body['total_cost'] : (float)$project['total_cost'];
        $newPaid = (float)$project['paid_amount'];
        $newRemaining = max(0.0, $newCost - $newPaid);
        $startDate = !empty($body['start_date']) ? $body['start_date'] : $project['start_date'];
        $expectedDeliveryDate = !empty($body['expected_delivery_date']) ? $body['expected_delivery_date'] : $project['expected_delivery_date'];
        $completedAt = $status === 'Completed' ? date('Y-m-d H:i:s') : null;

        $stmt = $db->prepare('
            UPDATE projects 
            SET title = ?,
                category = ?,
                description = ?,
                status = ?,
                progress_percent = ?,
                total_cost = ?,
                remaining_balance = ?,
                start_date = ?,
                expected_delivery_date = ?,
                completed_at = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute([
            $title,
            $category,
            $description,
            $status,
            $newProgress,
            $newCost,
            $newRemaining,
            $startDate,
            $expectedDeliveryDate,
            $completedAt,
            $projectId
        ]);

        // Keep associated payment synchronized
        $stmt = $db->prepare('SELECT * FROM payments WHERE project_id = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$projectId]);
        $existingPayment = $stmt->fetch();

        if ($existingPayment) {
            $payRemaining = max(0.0, $newCost - (float)$existingPayment['amount_paid']);
            $payStatus = (float)$existingPayment['amount_paid'] >= $newCost ? 'Paid' : ((float)$existingPayment['amount_paid'] > 0 ? 'Partially Paid' : $existingPayment['status']);
            $stmt = $db->prepare('
                UPDATE payments 
                SET total_amount = ?, remaining_balance = ?, status = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ');
            $stmt->execute([$newCost, $payRemaining, $payStatus, $existingPayment['id']]);
        }

        // Notify client if status or progress changed
        $stmt = $db->prepare('SELECT email, full_name FROM users WHERE id = ?');
        $stmt->execute([$project['user_id']]);
        $client = $stmt->fetch();

        if ($client && ($status !== $project['status'] || $newProgress !== (int)$project['progress_percent'])) {
            createNotification([
                'userId' => $project['user_id'],
                'title' => "Project Update: {$project['title']}",
                'message' => "Status updated to \"{$status}\" and completion is now {$newProgress}%.",
                'type' => 'project',
                'link' => '/dashboard#projects',
                'sendAsEmail' => true,
                'emailTo' => $client['email']
            ]);
        }

        logActivity([
            'userId' => $user['id'],
            'action' => 'Updated Project',
            'entityType' => 'project',
            'entityId' => $projectId,
            'details' => "Updated project \"{$project['title']}\" to status {$status}, progress {$newProgress}%"
        ]);

        jsonResponse(['success' => true, 'message' => 'Project updated successfully.']);
    }

    if ($method === 'DELETE') {
        $user = requireAdmin();
        $stmt = $db->prepare('SELECT title FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();
        if (!$project) {
            errorResponse('Project not found.', 404);
        }

        $stmt = $db->prepare('DELETE FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);

        logActivity([
            'userId' => $user['id'],
            'action' => 'Deleted Project',
            'entityType' => 'project',
            'entityId' => $projectId,
            'details' => "Admin deleted project \"{$project['title']}\""
        ]);

        jsonResponse(['success' => true, 'message' => 'Project deleted successfully.']);
    }

    errorResponse('Method Not Allowed', 405);
}
