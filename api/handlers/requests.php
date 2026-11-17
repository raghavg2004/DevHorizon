<?php
/**
 * Requests Handler
 * Endpoints:
 *   GET  /api/requests
 *   GET  /api/requests/:id
 *   POST /api/requests
 *   POST /api/requests/:id/revision
 *   POST /api/requests/:id/respond
 *   POST /api/requests/:id/approve
 *   POST /api/requests/:id/reject
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../utils.php';

function handleRequests(string $method, array $pathSegments): void {
    $db = getDb();
    $body = getBody();
    $firstSegment = $pathSegments[0] ?? null;

    // List all requests or create new: /api/requests
    if ($firstSegment === null || $firstSegment === '') {
        if ($method === 'GET') {
            $user = requireAuth();
            $selectFields = '
                r.*, u.full_name as client_name, u.email as client_email, u.phone as client_phone,
                rev.id as latest_revision_id,
                rev.revision_number as latest_revision_number,
                rev.proposed_budget as latest_revision_budget,
                rev.reason_for_revision as latest_revision_reason,
                rev.updated_requirements as latest_revision_requirements,
                rev.scope_changes as latest_revision_scope,
                rev.admin_comments as latest_revision_comments,
                rev.status as latest_revision_status,
                rev.user_response_notes as latest_revision_user_notes,
                rev.created_at as latest_revision_created_at
            ';

            if ($user['role'] === 'admin') {
                $sql = "
                    SELECT {$selectFields}
                    FROM project_requests r 
                    JOIN users u ON r.user_id = u.id 
                    LEFT JOIN project_revisions rev ON rev.id = (
                        SELECT id FROM project_revisions WHERE request_id = r.id ORDER BY id DESC LIMIT 1
                    )
                    ORDER BY r.created_at DESC
                ";
                $stmt = $db->query($sql);
                $requests = $stmt->fetchAll();
            } else {
                $sql = "
                    SELECT {$selectFields}
                    FROM project_requests r 
                    JOIN users u ON r.user_id = u.id 
                    LEFT JOIN project_revisions rev ON rev.id = (
                        SELECT id FROM project_revisions WHERE request_id = r.id ORDER BY id DESC LIMIT 1
                    )
                    WHERE r.user_id = ? 
                    ORDER BY r.created_at DESC
                ";
                $stmt = $db->prepare($sql);
                $stmt->execute([$user['id']]);
                $requests = $stmt->fetchAll();
            }

            foreach ($requests as &$req) {
                $req['id'] = (int)$req['id'];
                $req['user_id'] = (int)$req['user_id'];
                $req['expected_budget'] = (float)$req['expected_budget'];
                if (isset($req['latest_revision_budget']) && $req['latest_revision_budget'] !== null) {
                    $req['latest_revision_budget'] = (float)$req['latest_revision_budget'];
                }
            }

            jsonResponse(['success' => true, 'requests' => $requests]);
        }

        if ($method === 'POST') {
            $user = requireAuth();
            $projectName = trim($body['project_name'] ?? '');
            $projectType = $body['project_type'] ?? '';
            $description = trim($body['description'] ?? '');
            $requiredFeatures = !empty($body['required_features']) ? $body['required_features'] : null;
            $expectedBudget = isset($body['expected_budget']) ? (float)$body['expected_budget'] : 0.0;
            $expectedDeadline = !empty($body['expected_deadline']) ? $body['expected_deadline'] : null;
            $additionalRequirements = !empty($body['additional_requirements']) ? $body['additional_requirements'] : null;
            $referenceAttachments = !empty($body['reference_attachments']) ? $body['reference_attachments'] : null;

            if (empty($projectName) || empty($projectType) || empty($description)) {
                errorResponse('Project name, project type, and description are required.', 400);
            }

            $requestCode = 'REQ-' . date('Y') . '-' . rand(1000, 9999);

            $stmt = $db->prepare('
                INSERT INTO project_requests (request_code, user_id, project_name, project_type, description, required_features, expected_budget, expected_deadline, additional_requirements, reference_attachments, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Submitted")
            ');
            $stmt->execute([
                $requestCode,
                $user['id'],
                $projectName,
                $projectType,
                $description,
                $requiredFeatures,
                $expectedBudget,
                $expectedDeadline,
                $additionalRequirements,
                $referenceAttachments
            ]);
            $requestId = (int)$db->lastInsertId();

            logActivity([
                'userId' => $user['id'],
                'action' => 'Submitted Request',
                'entityType' => 'request',
                'entityId' => $requestId,
                'details' => "Client submitted request {$requestCode} for \"{$projectName}\" (Est: ₹{$expectedBudget})"
            ]);

            notifyAdmins([
                'title' => 'New Project Request Submitted',
                'message' => "{$user['full_name']} submitted request {$requestCode}: \"{$projectName}\" (Est. Budget: ₹" . number_format($expectedBudget) . ')',
                'type' => 'request',
                'link' => '/admin#projectRequests'
            ]);

            createNotification([
                'userId' => $user['id'],
                'title' => 'Project Request Submitted',
                'message' => "Your request {$requestCode} for \"{$projectName}\" was received and is awaiting admin review.",
                'type' => 'request',
                'link' => '/dashboard#requests'
            ]);

            jsonResponse([
                'success' => true,
                'message' => 'Project request submitted successfully!',
                'requestId' => $requestId,
                'requestCode' => $requestCode
            ], 201);
        }

        errorResponse('Method Not Allowed', 405);
    }

    $requestId = (int)$firstSegment;
    $secondSegment = $pathSegments[1] ?? null;

    // Single request detail: GET /api/requests/:id
    if ($secondSegment === null || $secondSegment === '') {
        if ($method === 'GET') {
            $user = requireAuth();
            $stmt = $db->prepare('
                SELECT r.*, u.full_name as client_name, u.email as client_email, u.phone as client_phone 
                FROM project_requests r 
                JOIN users u ON r.user_id = u.id 
                WHERE r.id = ?
            ');
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();

            if (!$request) {
                errorResponse('Project request not found.', 404);
            }

            if ($user['role'] !== 'admin' && (int)$request['user_id'] !== (int)$user['id']) {
                errorResponse('Permission denied to view this request.', 403);
            }

            $stmt = $db->prepare('
                SELECT * FROM project_revisions 
                WHERE request_id = ? 
                ORDER BY revision_number DESC, id DESC
            ');
            $stmt->execute([$requestId]);
            $revisions = $stmt->fetchAll();

            $request['id'] = (int)$request['id'];
            $request['user_id'] = (int)$request['user_id'];
            $request['expected_budget'] = (float)$request['expected_budget'];
            $request['revisions'] = $revisions;

            jsonResponse(['success' => true, 'request' => $request]);
        }

        errorResponse('Method Not Allowed', 405);
    }

    // Action: POST /api/requests/:id/revision
    if ($secondSegment === 'revision') {
        if ($method !== 'POST') {
            errorResponse('Method Not Allowed', 405);
        }
        $user = requireAdmin();

        $proposedBudget = isset($body['proposed_budget']) ? (float)$body['proposed_budget'] : null;
        $reasonForRevision = trim($body['reason_for_revision'] ?? '');
        $updatedRequirements = !empty($body['updated_requirements']) ? $body['updated_requirements'] : null;
        $scopeChanges = !empty($body['scope_changes']) ? $body['scope_changes'] : null;
        $adminComments = !empty($body['admin_comments']) ? $body['admin_comments'] : null;

        if ($proposedBudget === null || empty($reasonForRevision)) {
            errorResponse('Proposed budget and reason for revision are required.', 400);
        }

        $stmt = $db->prepare('
            SELECT r.*, u.email, u.full_name 
            FROM project_requests r 
            JOIN users u ON r.user_id = u.id 
            WHERE r.id = ?
        ');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();

        if (!$request) {
            errorResponse('Project request not found.', 404);
        }

        $stmt = $db->prepare('SELECT COUNT(*) as count FROM project_revisions WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $revCountRow = $stmt->fetch();
        $nextRevNumber = ($revCountRow ? (int)$revCountRow['count'] : 0) + 1;

        $stmt = $db->prepare('
            INSERT INTO project_revisions (request_id, revision_number, proposed_budget, updated_requirements, scope_changes, reason_for_revision, admin_comments, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, "Pending User Response")
        ');
        $stmt->execute([
            $requestId,
            $nextRevNumber,
            $proposedBudget,
            $updatedRequirements,
            $scopeChanges,
            $reasonForRevision,
            $adminComments
        ]);

        $stmt = $db->prepare('
            UPDATE project_requests 
            SET status = "Revision Requested", 
                admin_notes = ?, 
                updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ');
        $stmt->execute([$adminComments ?: $reasonForRevision, $requestId]);

        logActivity([
            'userId' => $user['id'],
            'action' => 'Sent Revision Proposal',
            'entityType' => 'revision',
            'entityId' => $requestId,
            'details' => "Admin proposed revised budget ₹{$proposedBudget} for request {$request['request_code']}"
        ]);

        createNotification([
            'userId' => $request['user_id'],
            'title' => 'Budget & Scope Revision Proposal',
            'message' => "Admin reviewed request \"{$request['project_name']}\" and proposed a revised budget of ₹" . number_format($proposedBudget) . ". Reason: {$reasonForRevision}",
            'type' => 'revision',
            'link' => "/dashboard#requests?rev={$requestId}",
            'sendAsEmail' => true,
            'emailTo' => $request['email']
        ]);

        jsonResponse([
            'success' => true,
            'message' => 'Revision proposal successfully sent to client.'
        ]);
    }

    // Action: POST /api/requests/:id/respond
    if ($secondSegment === 'respond') {
        if ($method !== 'POST') {
            errorResponse('Method Not Allowed', 405);
        }
        $user = requireAuth();

        $action = $body['action'] ?? '';
        $notes = $body['notes'] ?? '';
        $updatedDescription = !empty($body['updated_description']) ? trim($body['updated_description']) : null;
        $updatedBudget = isset($body['updated_budget']) ? (float)$body['updated_budget'] : null;

        if (!in_array($action, ['accept', 'reject', 'resubmit'])) {
            errorResponse('Invalid action. Must be "accept", "reject", or "resubmit".', 400);
        }

        $stmt = $db->prepare('SELECT * FROM project_requests WHERE id = ?');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();

        if (!$request) {
            errorResponse('Request not found.', 404);
        }

        if ((int)$request['user_id'] !== (int)$user['id']) {
            errorResponse('Permission denied.', 403);
        }

        $stmt = $db->prepare('SELECT * FROM project_revisions WHERE request_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$requestId]);
        $latestRevision = $stmt->fetch();

        if ($action === 'accept') {
            if ($latestRevision) {
                $stmt = $db->prepare('
                    UPDATE project_revisions 
                    SET status = "Accepted", user_response_notes = ?, responded_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ');
                $stmt->execute([$notes ?: 'Client accepted revised proposal terms.', $latestRevision['id']]);
            }

            $stmt = $db->prepare('
                UPDATE project_requests 
                SET status = "Approved", updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ');
            $stmt->execute([$requestId]);

            $finalCost = $latestRevision ? (float)$latestRevision['proposed_budget'] : (float)$request['expected_budget'];

            // Enforce ONE project tile and ONE invoice
            $stmt = $db->prepare('
                SELECT * FROM projects 
                WHERE request_id = ? OR (user_id = ? AND LOWER(TRIM(title)) = LOWER(TRIM(?))) 
                ORDER BY id DESC LIMIT 1
            ');
            $stmt->execute([$requestId, $user['id'], $request['project_name']]);
            $existingProject = $stmt->fetch();

            if ($existingProject) {
                $newProjectId = (int)$existingProject['id'];
                $newRemaining = max(0.0, $finalCost - (float)$existingProject['paid_amount']);
                $stmt = $db->prepare('
                    UPDATE projects 
                    SET title = COALESCE(?, title),
                        category = COALESCE(?, category),
                        description = COALESCE(?, description),
                        status = "Approved",
                        total_cost = ?,
                        remaining_balance = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ');
                $stmt->execute([
                    $request['project_name'],
                    $request['project_type'],
                    ($latestRevision && !empty($latestRevision['updated_requirements'])) ? $latestRevision['updated_requirements'] : $request['description'],
                    $finalCost,
                    $newRemaining,
                    $newProjectId
                ]);

                $stmt = $db->prepare('SELECT * FROM payments WHERE project_id = ? ORDER BY id ASC LIMIT 1');
                $stmt->execute([$newProjectId]);
                $existingPayment = $stmt->fetch();

                if ($existingPayment) {
                    $payRemaining = max(0.0, $finalCost - (float)$existingPayment['amount_paid']);
                    $payStatus = (float)$existingPayment['amount_paid'] >= $finalCost ? 'Paid' : ((float)$existingPayment['amount_paid'] > 0 ? 'Partially Paid' : $existingPayment['status']);
                    $stmt = $db->prepare('
                        UPDATE payments 
                        SET total_amount = ?, remaining_balance = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ');
                    $stmt->execute([$finalCost, $payRemaining, $payStatus, $existingPayment['id']]);
                }
            } else {
                $stmt = $db->prepare('
                    INSERT INTO projects (user_id, request_id, title, category, description, status, progress_percent, total_cost, paid_amount, remaining_balance, start_date, expected_delivery_date)
                    VALUES (?, ?, ?, ?, ?, "Approved", 10, ?, 0, ?, ?, ?)
                ');
                $stmt->execute([
                    $user['id'],
                    $requestId,
                    $request['project_name'],
                    $request['project_type'],
                    ($latestRevision && !empty($latestRevision['updated_requirements'])) ? $latestRevision['updated_requirements'] : $request['description'],
                    $finalCost,
                    $finalCost,
                    date('Y-m-d'),
                    $request['expected_deadline']
                ]);
                $newProjectId = (int)$db->lastInsertId();

                $invNum = 'INV-' . date('Y') . '-' . str_pad((string)$newProjectId, 4, '0', STR_PAD_LEFT);
                $stmt = $db->prepare('
                    INSERT INTO payments (invoice_number, project_id, user_id, total_amount, amount_paid, remaining_balance, advance_required, status, due_date, notes)
                    VALUES (?, ?, ?, ?, 0, ?, ?, "Pending", ?, ?)
                ');
                $stmt->execute([
                    $invNum,
                    $newProjectId,
                    $user['id'],
                    $finalCost,
                    $finalCost,
                    $finalCost * 0.5,
                    $request['expected_deadline'],
                    "Initial invoice for approved project {$request['project_name']} (from accepted revision)"
                ]);
            }

            notifyAdmins([
                'title' => 'Proposal Accepted by Client',
                'message' => "{$user['full_name']} accepted the revised terms for \"{$request['project_name']}\". Active project #{$newProjectId} was created!",
                'type' => 'project',
                'link' => '/admin#projects'
            ]);

            createNotification([
                'userId' => $user['id'],
                'title' => 'Proposal Accepted & Project Activated',
                'message' => "You accepted the revised proposal for \"{$request['project_name']}\". Active project #{$newProjectId} has been created and is now in progress!",
                'type' => 'project',
                'link' => '/dashboard#projects'
            ]);

            logActivity([
                'userId' => $user['id'],
                'action' => 'Accepted Proposal',
                'entityType' => 'request',
                'entityId' => $requestId,
                'details' => "Client accepted proposal for {$request['request_code']}. Project #{$newProjectId} generated."
            ]);

            jsonResponse([
                'success' => true,
                'message' => 'Proposal accepted! Your project is now approved and active.',
                'projectId' => $newProjectId
            ]);
        }

        if ($action === 'reject') {
            if ($latestRevision) {
                $stmt = $db->prepare('
                    UPDATE project_revisions 
                    SET status = "Rejected", user_response_notes = ?, responded_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ');
                $stmt->execute([$notes ?: 'Client declined the proposed revision.', $latestRevision['id']]);
            }

            $stmt = $db->prepare('
                UPDATE project_requests 
                SET status = "Rejected", updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ');
            $stmt->execute([$requestId]);

            notifyAdmins([
                'title' => 'Proposal Declined by Client',
                'message' => "{$user['full_name']} declined the revision proposal for \"{$request['project_name']}\". Reason: " . ($notes ?: 'No reason specified'),
                'type' => 'revision',
                'link' => '/admin#projectRequests'
            ]);

            createNotification([
                'userId' => $user['id'],
                'title' => 'Revision Proposal Declined',
                'message' => "You declined the revision proposal for \"{$request['project_name']}\". The request has been marked as declined.",
                'type' => 'request',
                'link' => '/dashboard#requests'
            ]);

            logActivity([
                'userId' => $user['id'],
                'action' => 'Declined Proposal',
                'entityType' => 'request',
                'entityId' => $requestId,
                'details' => "Client declined revision proposal for {$request['request_code']}"
            ]);

            jsonResponse([
                'success' => true,
                'message' => 'Proposal declined. Request status updated.'
            ]);
        }

        if ($action === 'resubmit') {
            if ($latestRevision) {
                $stmt = $db->prepare('
                    UPDATE project_revisions 
                    SET status = "Resubmitted", user_response_notes = ?, responded_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ');
                $stmt->execute([$notes ?: 'Client revised requirements and counter-submitted.', $latestRevision['id']]);
            }

            $newBudget = $updatedBudget !== null ? $updatedBudget : (float)$request['expected_budget'];
            $newDesc = $updatedDescription ?: $request['description'];

            $stmt = $db->prepare('
                UPDATE project_requests 
                SET description = ?, 
                    expected_budget = ?, 
                    status = "Under Admin Review", 
                    additional_requirements = COALESCE(?, additional_requirements),
                    updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ');
            $stmt->execute([$newDesc, $newBudget, $notes, $requestId]);

            notifyAdmins([
                'title' => 'Project Requirements Resubmitted',
                'message' => "{$user['full_name']} revised requirements and resubmitted \"{$request['project_name']}\" for review. (Counter-Budget: ₹" . number_format($newBudget) . ')',
                'type' => 'request',
                'link' => '/admin#projectRequests'
            ]);

            createNotification([
                'userId' => $user['id'],
                'title' => 'Counter-Proposal Sent to Admin',
                'message' => "Your revised requirements and counter-proposed budget of ₹" . number_format($newBudget) . " for \"{$request['project_name']}\" have been sent to admin for re-review.",
                'type' => 'request',
                'link' => '/dashboard#requests'
            ]);

            logActivity([
                'userId' => $user['id'],
                'action' => 'Resubmitted Request',
                'entityType' => 'request',
                'entityId' => $requestId,
                'details' => "Client counter-revised requirements for {$request['request_code']}"
            ]);

            jsonResponse([
                'success' => true,
                'message' => 'Your revised requirements have been submitted to the admin for re-review.'
            ]);
        }

        errorResponse('Invalid request state.', 400);
    }

    // Action: POST /api/requests/:id/approve (Admin direct approve)
    if ($secondSegment === 'approve') {
        if ($method !== 'POST') {
            errorResponse('Method Not Allowed', 405);
        }
        $user = requireAdmin();

        $finalBudget = isset($body['final_budget']) ? (float)$body['final_budget'] : null;
        $startDate = !empty($body['start_date']) ? $body['start_date'] : date('Y-m-d');
        $expectedDeliveryDate = !empty($body['expected_delivery_date']) ? $body['expected_delivery_date'] : null;

        $stmt = $db->prepare('
            SELECT r.*, u.email, u.full_name 
            FROM project_requests r 
            JOIN users u ON r.user_id = u.id 
            WHERE r.id = ?
        ');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();

        if (!$request) {
            errorResponse('Request not found.', 404);
        }

        $cost = $finalBudget !== null ? $finalBudget : (float)$request['expected_budget'];

        $stmt = $db->prepare('UPDATE project_requests SET status = "Approved", updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$requestId]);

        $stmt = $db->prepare('
            SELECT * FROM projects 
            WHERE request_id = ? OR (user_id = ? AND LOWER(TRIM(title)) = LOWER(TRIM(?))) 
            ORDER BY id DESC LIMIT 1
        ');
        $stmt->execute([$requestId, $request['user_id'], $request['project_name']]);
        $existingProject = $stmt->fetch();

        if ($existingProject) {
            $projectId = (int)$existingProject['id'];
            $newRemaining = max(0.0, $cost - (float)$existingProject['paid_amount']);
            $stmt = $db->prepare('
                UPDATE projects 
                SET title = COALESCE(?, title),
                    category = COALESCE(?, category),
                    description = COALESCE(?, description),
                    status = "Approved",
                    total_cost = ?,
                    remaining_balance = ?,
                    start_date = COALESCE(?, start_date),
                    expected_delivery_date = COALESCE(?, expected_delivery_date),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ');
            $stmt->execute([
                $request['project_name'],
                $request['project_type'],
                $request['description'],
                $cost,
                $newRemaining,
                $startDate,
                $expectedDeliveryDate ?: $request['expected_deadline'],
                $projectId
            ]);

            $stmt = $db->prepare('SELECT * FROM payments WHERE project_id = ? ORDER BY id ASC LIMIT 1');
            $stmt->execute([$projectId]);
            $existingPayment = $stmt->fetch();

            if ($existingPayment) {
                $payRemaining = max(0.0, $cost - (float)$existingPayment['amount_paid']);
                $payStatus = (float)$existingPayment['amount_paid'] >= $cost ? 'Paid' : ((float)$existingPayment['amount_paid'] > 0 ? 'Partially Paid' : $existingPayment['status']);
                $stmt = $db->prepare('
                    UPDATE payments 
                    SET total_amount = ?, remaining_balance = ?, status = ?, due_date = COALESCE(?, due_date), updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ');
                $stmt->execute([$cost, $payRemaining, $payStatus, $expectedDeliveryDate ?: $request['expected_deadline'], $existingPayment['id']]);
            }
        } else {
            $stmt = $db->prepare('
                INSERT INTO projects (user_id, request_id, title, category, description, status, progress_percent, total_cost, paid_amount, remaining_balance, start_date, expected_delivery_date)
                VALUES (?, ?, ?, ?, ?, "Approved", 10, ?, 0, ?, ?, ?)
            ');
            $stmt->execute([
                $request['user_id'],
                $requestId,
                $request['project_name'],
                $request['project_type'],
                $request['description'],
                $cost,
                $cost,
                $startDate,
                $expectedDeliveryDate ?: $request['expected_deadline']
            ]);
            $projectId = (int)$db->lastInsertId();

            $invNum = 'INV-' . date('Y') . '-' . str_pad((string)$projectId, 4, '0', STR_PAD_LEFT);
            $stmt = $db->prepare('
                INSERT INTO payments (invoice_number, project_id, user_id, total_amount, amount_paid, remaining_balance, advance_required, status, due_date, notes)
                VALUES (?, ?, ?, ?, 0, ?, ?, "Pending", ?, ?)
            ');
            $stmt->execute([
                $invNum,
                $projectId,
                $request['user_id'],
                $cost,
                $cost,
                $cost * 0.5,
                $expectedDeliveryDate ?: $request['expected_deadline'],
                "Initial invoice for approved project {$request['project_name']}"
            ]);
        }

        createNotification([
            'userId' => $request['user_id'],
            'title' => 'Project Request Approved!',
            'message' => "Your request \"{$request['project_name']}\" has been approved and created as an active project.",
            'type' => 'project',
            'link' => '/dashboard#projects',
            'sendAsEmail' => true,
            'emailTo' => $request['email']
        ]);

        logActivity([
            'userId' => $user['id'],
            'action' => 'Approved Project Request',
            'entityType' => 'request',
            'entityId' => $requestId,
            'details' => "Admin approved request {$request['request_code']} and created project #{$projectId}"
        ]);

        jsonResponse([
            'success' => true,
            'message' => 'Request approved and project created successfully!',
            'projectId' => $projectId
        ]);
    }

    // Action: POST /api/requests/:id/reject (Admin direct reject)
    if ($secondSegment === 'reject') {
        if ($method !== 'POST') {
            errorResponse('Method Not Allowed', 405);
        }
        $user = requireAdmin();

        $reason = trim($body['reason'] ?? '');
        $stmt = $db->prepare('
            SELECT r.*, u.email, u.full_name 
            FROM project_requests r 
            JOIN users u ON r.user_id = u.id 
            WHERE r.id = ?
        ');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();

        if (!$request) {
            errorResponse('Request not found.', 404);
        }

        $stmt = $db->prepare('
            UPDATE project_requests 
            SET status = "Rejected", admin_notes = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ');
        $stmt->execute([$reason ?: 'Not aligned with current capabilities or scope requirements.', $requestId]);

        createNotification([
            'userId' => $request['user_id'],
            'title' => 'Project Request Update',
            'message' => "Your request \"{$request['project_name']}\" was rejected. Reason: " . ($reason ?: 'Contact team for details.'),
            'type' => 'request',
            'link' => '/dashboard#requests',
            'sendAsEmail' => true,
            'emailTo' => $request['email']
        ]);

        logActivity([
            'userId' => $user['id'],
            'action' => 'Rejected Project Request',
            'entityType' => 'request',
            'entityId' => $requestId,
            'details' => "Admin rejected request {$request['request_code']}"
        ]);

        jsonResponse([
            'success' => true,
            'message' => 'Project request rejected.'
        ]);
    }

    errorResponse('Not Found', 404);
}
