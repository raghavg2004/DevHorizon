<?php
/**
 * Payments & Invoicing Handler
 * Endpoints:
 *   GET  /api/payments
 *   GET  /api/payments/:id
 *   POST /api/payments
 *   PUT  /api/payments/:id
 *   POST /api/payments/:id/pay
 *   POST /api/payments/:id/accept
 *   POST /api/payments/:id/reject
 *   POST /api/payments/:id/remind
 *   POST /api/payments/send-invoice-email
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../utils.php';

function handlePayments(string $method, array $pathSegments): void {
    $db = getDb();
    $body = getBody();
    $firstSegment = $pathSegments[0] ?? null;

    // Direct compatibility endpoint: POST /api/payments/send-invoice-email
    if ($firstSegment === 'send-invoice-email') {
        if ($method !== 'POST') {
            errorResponse('Method Not Allowed', 405);
        }

        $toEmail = trim($body['to_email'] ?? '');
        $subject = trim($body['subject'] ?? '');
        $message = trim($body['message'] ?? '');
        $pdfBase64 = $body['pdf_base64'] ?? null;
        $invoiceNumber = $body['invoice_number'] ?? 'Invoice';

        if (empty($toEmail) || empty($subject) || empty($message)) {
            errorResponse('Missing required fields: to_email, subject, message', 400);
        }

        $result = sendEmail([
            'to' => $toEmail,
            'subject' => $subject,
            'text' => $message,
            'html' => '<pre style="font-family: Arial, sans-serif; white-space: pre-wrap;">' . htmlspecialchars($message) . '</pre>'
        ]);

        jsonResponse([
            'success' => $result['success'],
            'message' => $result['success'] ? 'Email sent successfully!' : $result['message'],
            'messageId' => 'msg_' . time()
        ]);
    }

    // List all payments or create new invoice: /api/payments
    if ($firstSegment === null || $firstSegment === '') {
        if ($method === 'GET') {
            $user = requireAuth();

            if ($user['role'] === 'admin') {
                $sql = '
                    SELECT pm.*, p.title as project_title, p.category as project_category, 
                           u.full_name as client_name, u.email as client_email, u.phone as client_phone 
                    FROM payments pm 
                    JOIN projects p ON pm.project_id = p.id 
                    JOIN users u ON pm.user_id = u.id 
                    ORDER BY pm.created_at DESC
                ';
                $stmt = $db->query($sql);
                $payments = $stmt->fetchAll();
            } else {
                $sql = '
                    SELECT pm.*, p.title as project_title, p.category as project_category, 
                           u.full_name as client_name, u.email as client_email, u.phone as client_phone 
                    FROM payments pm 
                    JOIN projects p ON pm.project_id = p.id 
                    JOIN users u ON pm.user_id = u.id 
                    WHERE pm.user_id = ? 
                    ORDER BY pm.created_at DESC
                ';
                $stmt = $db->prepare($sql);
                $stmt->execute([$user['id']]);
                $payments = $stmt->fetchAll();
            }

            foreach ($payments as &$pm) {
                $pm['id'] = (int)$pm['id'];
                $pm['project_id'] = (int)$pm['project_id'];
                $pm['user_id'] = (int)$pm['user_id'];
                $pm['total_amount'] = (float)$pm['total_amount'];
                $pm['amount_paid'] = (float)$pm['amount_paid'];
                $pm['remaining_balance'] = (float)$pm['remaining_balance'];
                $pm['advance_required'] = (float)$pm['advance_required'];
                $pm['pending_review_amount'] = isset($pm['pending_review_amount']) ? (float)$pm['pending_review_amount'] : 0.0;
            }

            jsonResponse(['success' => true, 'payments' => $payments]);
        }

        if ($method === 'POST') {
            $user = requireAdmin();
            $projectId = (int)($body['project_id'] ?? 0);
            $totalAmount = isset($body['total_amount']) ? (float)$body['total_amount'] : 0.0;
            $advanceRequired = isset($body['advance_required']) ? (float)$body['advance_required'] : 0.0;
            $dueDate = !empty($body['due_date']) ? $body['due_date'] : null;
            $notes = !empty($body['notes']) ? trim($body['notes']) : null;

            if ($projectId <= 0 || $totalAmount <= 0) {
                errorResponse('Project ID and valid total amount are required.', 400);
            }

            $stmt = $db->prepare('SELECT * FROM projects WHERE id = ?');
            $stmt->execute([$projectId]);
            $project = $stmt->fetch();
            if (!$project) {
                errorResponse('Project not found.', 404);
            }

            // Check if payment already exists for this project (enforce ONE invoice per project)
            $stmt = $db->prepare('SELECT * FROM payments WHERE project_id = ? ORDER BY id ASC LIMIT 1');
            $stmt->execute([$projectId]);
            $existingPayment = $stmt->fetch();

            if ($existingPayment) {
                $payRemaining = max(0.0, $totalAmount - (float)$existingPayment['amount_paid']);
                $payStatus = (float)$existingPayment['amount_paid'] >= $totalAmount ? 'Paid' : ((float)$existingPayment['amount_paid'] > 0 ? 'Partially Paid' : $existingPayment['status']);

                $stmt = $db->prepare('
                    UPDATE payments 
                    SET total_amount = ?, remaining_balance = ?, advance_required = ?, due_date = COALESCE(?, due_date), notes = COALESCE(?, notes), status = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ');
                $stmt->execute([
                    $totalAmount,
                    $payRemaining,
                    $advanceRequired,
                    $dueDate,
                    $notes ?: "Invoice for {$project['title']}",
                    $payStatus,
                    $existingPayment['id']
                ]);

                // Update project total_cost and remaining_balance
                $stmt = $db->prepare('
                    UPDATE projects 
                    SET total_cost = ?, remaining_balance = ? - paid_amount, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ');
                $stmt->execute([$totalAmount, $totalAmount, $projectId]);

                jsonResponse([
                    'success' => true,
                    'message' => 'Existing project invoice updated successfully!',
                    'paymentId' => (int)$existingPayment['id'],
                    'invoiceNumber' => $existingPayment['invoice_number']
                ]);
            }

            $stmt = $db->query('SELECT COUNT(*) as count FROM payments');
            $invCountRow = $stmt->fetch();
            $invNum = 'INV-' . date('Y') . '-' . str_pad((string)(($invCountRow ? (int)$invCountRow['count'] : 0) + 1), 4, '0', STR_PAD_LEFT);

            $stmt = $db->prepare('
                INSERT INTO payments (invoice_number, project_id, user_id, total_amount, amount_paid, remaining_balance, advance_required, status, due_date, notes)
                VALUES (?, ?, ?, ?, 0, ?, ?, "Pending", ?, ?)
            ');
            $stmt->execute([
                $invNum,
                $projectId,
                $project['user_id'],
                $totalAmount,
                $totalAmount,
                $advanceRequired,
                $dueDate,
                $notes ?: "Invoice for {$project['title']}"
            ]);
            $paymentId = (int)$db->lastInsertId();

            $stmt = $db->prepare('
                UPDATE projects 
                SET total_cost = ?, remaining_balance = ? - paid_amount, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ');
            $stmt->execute([$totalAmount, $totalAmount, $projectId]);

            $stmt = $db->prepare('SELECT email, full_name FROM users WHERE id = ?');
            $stmt->execute([$project['user_id']]);
            $client = $stmt->fetch();

            if ($client) {
                createNotification([
                    'userId' => $project['user_id'],
                    'title' => 'New Invoice Issued',
                    'message' => "Invoice {$invNum} for ₹" . number_format($totalAmount) . " has been generated for \"{$project['title']}\".",
                    'type' => 'payment',
                    'link' => '/dashboard#billing',
                    'sendAsEmail' => true,
                    'emailTo' => $client['email']
                ]);
            }

            logActivity([
                'userId' => $user['id'],
                'action' => 'Created Invoice',
                'entityType' => 'payment',
                'entityId' => $paymentId,
                'details' => "Generated invoice {$invNum} for ₹{$totalAmount}"
            ]);

            jsonResponse([
                'success' => true,
                'message' => 'Invoice created successfully!',
                'paymentId' => $paymentId,
                'invoiceNumber' => $invNum
            ], 201);
        }

        errorResponse('Method Not Allowed', 405);
    }

    $paymentIdentifier = $firstSegment;
    $secondSegment = $pathSegments[1] ?? null;

    // Single invoice query: GET /api/payments/:id
    if ($secondSegment === null || $secondSegment === '') {
        if ($method === 'GET') {
            $user = requireAuth();
            $stmt = $db->prepare('
                SELECT pm.*, p.title as project_title, p.category as project_category, p.description as project_description,
                       u.full_name as client_name, u.email as client_email, u.phone as client_phone,
                       up.company_name, up.address as client_address 
                FROM payments pm 
                JOIN projects p ON pm.project_id = p.id 
                JOIN users u ON pm.user_id = u.id 
                LEFT JOIN user_profiles up ON u.id = up.user_id 
                WHERE pm.id = ? OR pm.invoice_number = ?
            ');
            $stmt->execute([$paymentIdentifier, $paymentIdentifier]);
            $payment = $stmt->fetch();

            if (!$payment) {
                errorResponse('Invoice or payment record not found.', 404);
            }

            if ($user['role'] !== 'admin' && (int)$payment['user_id'] !== (int)$user['id']) {
                errorResponse('Permission denied to view this invoice.', 403);
            }

            $payment['id'] = (int)$payment['id'];
            $payment['project_id'] = (int)$payment['project_id'];
            $payment['user_id'] = (int)$payment['user_id'];
            $payment['total_amount'] = (float)$payment['total_amount'];
            $payment['amount_paid'] = (float)$payment['amount_paid'];
            $payment['remaining_balance'] = (float)$payment['remaining_balance'];
            $payment['advance_required'] = (float)$payment['advance_required'];
            $payment['pending_review_amount'] = isset($payment['pending_review_amount']) ? (float)$payment['pending_review_amount'] : 0.0;

            jsonResponse(['success' => true, 'payment' => $payment]);
        }

        if ($method === 'PUT') {
            $user = requireAdmin();
            $stmt = $db->prepare('SELECT * FROM payments WHERE id = ? OR invoice_number = ?');
            $stmt->execute([$paymentIdentifier, $paymentIdentifier]);
            $payment = $stmt->fetch();
            if (!$payment) {
                errorResponse('Payment record not found.', 404);
            }

            $newTotal = isset($body['total_amount']) ? (float)$body['total_amount'] : (float)$payment['total_amount'];
            $newPaid = isset($body['amount_paid']) ? (float)$body['amount_paid'] : (float)$payment['amount_paid'];
            $newRemaining = max(0.0, $newTotal - $newPaid);
            $advanceRequired = isset($body['advance_required']) ? (float)$body['advance_required'] : (float)$payment['advance_required'];
            $dueDate = !empty($body['due_date']) ? $body['due_date'] : $payment['due_date'];
            $paymentMethod = !empty($body['payment_method']) ? $body['payment_method'] : $payment['payment_method'];
            $transactionId = !empty($body['transaction_id']) ? $body['transaction_id'] : $payment['transaction_id'];
            $notes = isset($body['notes']) ? $body['notes'] : $payment['notes'];

            $status = !empty($body['status']) ? $body['status'] : null;
            if (!$status) {
                if ($newPaid >= $newTotal && $newTotal > 0) {
                    $status = 'Paid';
                } else if ($newPaid > 0) {
                    $status = 'Partially Paid';
                } else {
                    $status = 'Pending';
                }
            }

            $paidDate = $status === 'Paid' ? ($payment['paid_date'] ?: date('Y-m-d H:i:s')) : $payment['paid_date'];

            $stmt = $db->prepare('
                UPDATE payments 
                SET total_amount = ?,
                    amount_paid = ?,
                    remaining_balance = ?,
                    advance_required = ?,
                    due_date = ?,
                    status = ?,
                    paid_date = ?,
                    payment_method = ?,
                    transaction_id = ?,
                    notes = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ');
            $stmt->execute([
                $newTotal,
                $newPaid,
                $newRemaining,
                $advanceRequired,
                $dueDate,
                $status,
                $paidDate,
                $paymentMethod,
                $transactionId,
                $notes,
                $payment['id']
            ]);

            // Sync project financials
            $stmt = $db->prepare('
                UPDATE projects 
                SET paid_amount = ?, remaining_balance = ? - ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ');
            $stmt->execute([$newPaid, $newTotal, $newPaid, $payment['project_id']]);

            if ($status === 'Paid' && $payment['status'] !== 'Paid') {
                $stmt = $db->prepare('SELECT email, full_name FROM users WHERE id = ?');
                $stmt->execute([$payment['user_id']]);
                $client = $stmt->fetch();
                if ($client) {
                    createNotification([
                        'userId' => $payment['user_id'],
                        'title' => 'Payment Confirmed!',
                        'message' => "Your payment for invoice {$payment['invoice_number']} (₹" . number_format($newPaid) . ") has been confirmed. Thank you!",
                        'type' => 'payment',
                        'link' => '/dashboard#billing',
                        'sendAsEmail' => true,
                        'emailTo' => $client['email']
                    ]);
                }
            }

            logActivity([
                'userId' => $user['id'],
                'action' => 'Updated Payment',
                'entityType' => 'payment',
                'entityId' => $payment['id'],
                'details' => "Updated {$payment['invoice_number']}: Paid ₹{$newPaid}, Remaining ₹{$newRemaining}, Status: {$status}"
            ]);

            jsonResponse(['success' => true, 'message' => 'Payment record updated successfully.']);
        }

        errorResponse('Method Not Allowed', 405);
    }

    $paymentId = (int)$paymentIdentifier;

    // Action: POST /api/payments/:id/pay (Client submits UPI payment for review)
    if ($secondSegment === 'pay') {
        if ($method !== 'POST') {
            errorResponse('Method Not Allowed', 405);
        }
        $user = requireAuth();

        $stmt = $db->prepare('SELECT * FROM payments WHERE id = ?');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();

        if (!$payment) {
            errorResponse('Invoice not found.', 404);
        }

        if ((int)$payment['user_id'] !== (int)$user['id']) {
            errorResponse('Permission denied.', 403);
        }

        $payAmount = isset($body['amount']) ? (float)$body['amount'] : (float)$payment['remaining_balance'];
        $paymentMethod = $body['payment_method'] ?? 'UPI (raghavgupta98880@okaxis)';
        $txnId = !empty($body['transaction_id']) ? trim($body['transaction_id']) : ('UPI_' . time() . '_' . rand(100, 999));

        $stmt = $db->prepare('
            UPDATE payments 
            SET status = "Under Review",
                pending_review_amount = ?,
                payment_method = ?,
                transaction_id = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute([$payAmount, $paymentMethod, $txnId, $paymentId]);

        notifyAdmins([
            'title' => 'Payment Submitted for Review',
            'message' => "{$user['full_name']} submitted UPI payment of ₹" . number_format($payAmount) . " (UTR: {$txnId}) for {$payment['invoice_number']}. Please verify and accept.",
            'type' => 'payment',
            'link' => '/admin#payments'
        ]);

        createNotification([
            'userId' => $user['id'],
            'title' => 'Payment in Review',
            'message' => "Your UPI payment submission of ₹" . number_format($payAmount) . " (UTR: {$txnId}) for {$payment['invoice_number']} is currently in review. Admin will verify and accept it shortly.",
            'type' => 'payment',
            'link' => '/dashboard#billing',
            'sendAsEmail' => true,
            'emailTo' => $user['email']
        ]);

        logActivity([
            'userId' => $user['id'],
            'action' => 'Client Submitted Payment for Review',
            'entityType' => 'payment',
            'entityId' => $paymentId,
            'details' => "Client submitted UPI payment of ₹{$payAmount} for {$payment['invoice_number']}. UTR: {$txnId}"
        ]);

        jsonResponse([
            'success' => true,
            'status' => 'Under Review',
            'message' => 'Payment is in review! The admin will verify and accept your payment shortly, after which your status will show as Paid.',
            'transactionId' => $txnId,
            'pendingAmount' => $payAmount
        ]);
    }

    // Action: POST /api/payments/:id/accept (Admin verifies & accepts payment)
    if ($secondSegment === 'accept') {
        if ($method !== 'POST') {
            errorResponse('Method Not Allowed', 405);
        }
        $user = requireAdmin();

        $stmt = $db->prepare('SELECT * FROM payments WHERE id = ?');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) {
            errorResponse('Payment record not found.', 404);
        }

        $pendingAmount = isset($payment['pending_review_amount']) ? (float)$payment['pending_review_amount'] : 0.0;
        $payAmount = $pendingAmount > 0 ? $pendingAmount : (float)$payment['remaining_balance'];
        $newPaid = (float)$payment['amount_paid'] + $payAmount;
        $newRemaining = max(0.0, (float)$payment['total_amount'] - $newPaid);
        $newStatus = $newRemaining <= 0 ? 'Paid' : 'Partially Paid';

        $stmt = $db->prepare('
            UPDATE payments 
            SET amount_paid = ?,
                remaining_balance = ?,
                pending_review_amount = 0,
                status = ?,
                paid_date = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute([$newPaid, $newRemaining, $newStatus, $paymentId]);

        // Sync project
        $stmt = $db->prepare('
            UPDATE projects 
            SET paid_amount = ?, remaining_balance = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute([$newPaid, $newRemaining, $payment['project_id']]);

        $stmt = $db->prepare('SELECT full_name, email FROM users WHERE id = ?');
        $stmt->execute([$payment['user_id']]);
        $client = $stmt->fetch();

        if ($client) {
            createNotification([
                'userId' => $payment['user_id'],
                'title' => 'Payment Accepted & Confirmed!',
                'message' => "Your payment of ₹" . number_format($payAmount) . " for invoice {$payment['invoice_number']} has been verified and accepted by admin. Your payment status is now {$newStatus}.",
                'type' => 'payment',
                'link' => '/dashboard#billing',
                'sendAsEmail' => true,
                'emailTo' => $client['email']
            ]);
        }

        logActivity([
            'userId' => $user['id'],
            'action' => 'Admin Accepted Payment',
            'entityType' => 'payment',
            'entityId' => $paymentId,
            'details' => "Admin verified & accepted payment of ₹{$payAmount} for {$payment['invoice_number']}. Status: {$newStatus}"
        ]);

        jsonResponse([
            'success' => true,
            'message' => "Payment of ₹" . number_format($payAmount) . " accepted and marked as {$newStatus}!",
            'newStatus' => $newStatus,
            'amountPaid' => $newPaid,
            'remainingBalance' => $newRemaining
        ]);
    }

    // Action: POST /api/payments/:id/reject (Admin rejects unverified payment)
    if ($secondSegment === 'reject') {
        if ($method !== 'POST') {
            errorResponse('Method Not Allowed', 405);
        }
        $user = requireAdmin();
        $reason = $body['reason'] ?? 'Could not verify transaction with bank';

        $stmt = $db->prepare('SELECT * FROM payments WHERE id = ?');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) {
            errorResponse('Payment record not found.', 404);
        }

        $revertedStatus = (float)$payment['amount_paid'] > 0 ? 'Partially Paid' : 'Pending';

        $stmt = $db->prepare('
            UPDATE payments 
            SET status = ?,
                pending_review_amount = 0,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute([$revertedStatus, $paymentId]);

        $stmt = $db->prepare('SELECT full_name, email FROM users WHERE id = ?');
        $stmt->execute([$payment['user_id']]);
        $client = $stmt->fetch();

        if ($client) {
            createNotification([
                'userId' => $payment['user_id'],
                'title' => 'Payment Verification Declined',
                'message' => "Your payment submission for invoice {$payment['invoice_number']} could not be verified by admin ({$reason}). Please check your transaction details or contact support.",
                'type' => 'payment',
                'link' => '/dashboard#billing',
                'sendAsEmail' => true,
                'emailTo' => $client['email']
            ]);
        }

        logActivity([
            'userId' => $user['id'],
            'action' => 'Admin Rejected Payment Submission',
            'entityType' => 'payment',
            'entityId' => $paymentId,
            'details' => "Admin rejected payment submission for {$payment['invoice_number']}: {$reason}"
        ]);

        jsonResponse([
            'success' => true,
            'message' => 'Payment submission declined.',
            'newStatus' => $revertedStatus
        ]);
    }

    // Action: POST /api/payments/:id/remind (Admin sends reminder)
    if ($secondSegment === 'remind') {
        if ($method !== 'POST') {
            errorResponse('Method Not Allowed', 405);
        }
        $user = requireAdmin();

        $stmt = $db->prepare('
            SELECT pm.*, p.title as project_title, u.email as client_email, u.full_name as client_name 
            FROM payments pm 
            JOIN projects p ON pm.project_id = p.id 
            JOIN users u ON pm.user_id = u.id 
            WHERE pm.id = ?
        ');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();

        if (!$payment) {
            errorResponse('Payment record not found.', 404);
        }

        createNotification([
            'userId' => $payment['user_id'],
            'title' => "Payment Reminder: {$payment['invoice_number']}",
            'message' => "Reminder for outstanding balance of ₹" . number_format((float)$payment['remaining_balance']) . " for project \"{$payment['project_title']}\". Due date: " . ($payment['due_date'] ?: 'Immediate') . '.',
            'type' => 'payment',
            'link' => '/dashboard#billing',
            'sendAsEmail' => true,
            'emailTo' => $payment['client_email']
        ]);

        logActivity([
            'userId' => $user['id'],
            'action' => 'Sent Payment Reminder',
            'entityType' => 'payment',
            'entityId' => $paymentId,
            'details' => "Admin dispatched reminder for invoice {$payment['invoice_number']} to {$payment['client_email']}"
        ]);

        jsonResponse([
            'success' => true,
            'message' => "Payment reminder successfully dispatched to {$payment['client_email']}."
        ]);
    }

    errorResponse('Not Found', 404);
}
