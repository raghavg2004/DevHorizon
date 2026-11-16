const express = require('express');
const router = express.Router();
const db = require('../config/db');
const { authenticateToken, requireAdmin } = require('../middleware/auth');
const { logActivity } = require('../services/activityService');
const { createNotification } = require('../services/notificationService');
const { sendEmail } = require('../services/emailService');

// GET /api/payments
router.get('/', authenticateToken, async (req, res) => {
  try {
    let sql;
    let params = [];

    if (req.user.role === 'admin') {
      sql = `
        SELECT pm.*, p.title as project_title, p.category as project_category, 
               u.full_name as client_name, u.email as client_email, u.phone as client_phone 
        FROM payments pm 
        JOIN projects p ON pm.project_id = p.id 
        JOIN users u ON pm.user_id = u.id 
        ORDER BY pm.created_at DESC
      `;
    } else {
      sql = `
        SELECT pm.*, p.title as project_title, p.category as project_category, 
               u.full_name as client_name, u.email as client_email, u.phone as client_phone 
        FROM payments pm 
        JOIN projects p ON pm.project_id = p.id 
        JOIN users u ON pm.user_id = u.id 
        WHERE pm.user_id = ? 
        ORDER BY pm.created_at DESC
      `;
      params = [req.user.id];
    }

    const payments = await db.all(sql, params);
    return res.json({ success: true, payments });
  } catch (error) {
    console.error('Get payments error:', error);
    return res.status(500).json({ success: false, message: 'Server error retrieving payments.' });
  }
});

// GET /api/payments/:id (single invoice / payment record)
router.get('/:id', authenticateToken, async (req, res) => {
  try {
    const paymentId = req.params.id;
    const payment = await db.get(
      `SELECT pm.*, p.title as project_title, p.category as project_category, p.description as project_description,
              u.full_name as client_name, u.email as client_email, u.phone as client_phone,
              up.company_name, up.address as client_address 
       FROM payments pm 
       JOIN projects p ON pm.project_id = p.id 
       JOIN users u ON pm.user_id = u.id 
       LEFT JOIN user_profiles up ON u.id = up.user_id 
       WHERE pm.id = ? OR pm.invoice_number = ?`,
      [paymentId, paymentId]
    );

    if (!payment) {
      return res.status(404).json({ success: false, message: 'Invoice or payment record not found.' });
    }

    if (req.user.role !== 'admin' && payment.user_id !== req.user.id) {
      return res.status(403).json({ success: false, message: 'Permission denied to view this invoice.' });
    }

    return res.json({ success: true, payment });
  } catch (error) {
    console.error('Get payment detail error:', error);
    return res.status(500).json({ success: false, message: 'Server error retrieving payment.' });
  }
});

// POST /api/payments (Admin create payment / invoice record)
router.post('/', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const {
      project_id,
      total_amount,
      advance_required = 0,
      due_date,
      notes
    } = req.body;

    if (!project_id || !total_amount) {
      return res.status(400).json({ success: false, message: 'Project ID and total amount are required.' });
    }

    const project = await db.get('SELECT * FROM projects WHERE id = ?', [project_id]);
    if (!project) {
      return res.status(404).json({ success: false, message: 'Project not found.' });
    }

    const totalNum = parseFloat(total_amount);
    const advanceNum = parseFloat(advance_required) || 0;

    // Check if invoice already exists for this project (enforce ONE bill per project)
    const existingPayment = await db.get('SELECT * FROM payments WHERE project_id = ? ORDER BY id ASC LIMIT 1', [project_id]);
    if (existingPayment) {
      const payRemaining = Math.max(0, totalNum - existingPayment.amount_paid);
      const payStatus = existingPayment.amount_paid >= totalNum ? 'Paid' : existingPayment.amount_paid > 0 ? 'Partially Paid' : existingPayment.status;

      await db.run(
        `UPDATE payments 
         SET total_amount = ?, remaining_balance = ?, advance_required = ?, due_date = COALESCE(?, due_date), notes = COALESCE(?, notes), status = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?`,
        [totalNum, payRemaining, advanceNum, due_date || null, notes || `Invoice for ${project.title}`, payStatus, existingPayment.id]
      );

      // Update project total_cost & remaining_balance
      await db.run(
        `UPDATE projects 
         SET total_cost = ?, remaining_balance = ? - paid_amount, updated_at = CURRENT_TIMESTAMP 
         WHERE id = ?`,
        [totalNum, totalNum, project_id]
      );

      return res.status(200).json({
        success: true,
        message: 'Existing project invoice updated successfully!',
        paymentId: existingPayment.id,
        invoiceNumber: existingPayment.invoice_number
      });
    }

    const invCountRow = await db.get('SELECT COUNT(*) as count FROM payments');
    const invNum = `INV-${new Date().getFullYear()}-${String((invCountRow ? invCountRow.count : 0) + 1).padStart(4, '0')}`;

    const result = await db.run(
      `INSERT INTO payments (invoice_number, project_id, user_id, total_amount, amount_paid, remaining_balance, advance_required, status, due_date, notes)
       VALUES (?, ?, ?, ?, 0, ?, ?, 'Pending', ?, ?)`,
      [
        invNum,
        project_id,
        project.user_id,
        totalNum,
        totalNum,
        advanceNum,
        due_date || null,
        notes || `Invoice for ${project.title}`
      ]
    );

    // Update project total_cost if payment is new total
    await db.run(
      `UPDATE projects 
       SET total_cost = ?, remaining_balance = ? - paid_amount, updated_at = CURRENT_TIMESTAMP 
       WHERE id = ?`,
      [totalNum, totalNum, project_id]
    );

    const client = await db.get('SELECT email, full_name FROM users WHERE id = ?', [project.user_id]);
    if (client) {
      await createNotification({
        userId: project.user_id,
        title: 'New Invoice Issued',
        message: `Invoice ${invNum} for ₹${totalNum.toLocaleString('en-IN')} has been generated for "${project.title}".`,
        type: 'payment',
        link: '/dashboard#billing',
        sendAsEmail: true,
        emailTo: client.email
      });
    }

    await logActivity({
      userId: req.user.id,
      action: 'Created Invoice',
      entityType: 'payment',
      entityId: result.lastID,
      details: `Generated invoice ${invNum} for ₹${totalNum}`,
      ipAddress: req.ip
    });

    return res.status(201).json({
      success: true,
      message: 'Invoice created successfully!',
      paymentId: result.lastID,
      invoiceNumber: invNum
    });
  } catch (error) {
    console.error('Create payment error:', error);
    return res.status(500).json({ success: false, message: 'Server error creating invoice.' });
  }
});

// PUT /api/payments/:id (Admin update payment record or record manual payment)
router.put('/:id', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const paymentId = req.params.id;
    const payment = await db.get('SELECT * FROM payments WHERE id = ?', [paymentId]);
    if (!payment) {
      return res.status(404).json({ success: false, message: 'Payment record not found.' });
    }

    const {
      amount_paid,
      total_amount,
      advance_required,
      due_date,
      status,
      payment_method,
      transaction_id,
      notes
    } = req.body;

    const newTotal = total_amount !== undefined ? parseFloat(total_amount) : payment.total_amount;
    const newPaid = amount_paid !== undefined ? parseFloat(amount_paid) : payment.amount_paid;
    const newRemaining = Math.max(0, newTotal - newPaid);

    let calculatedStatus = status;
    if (!status) {
      if (newPaid >= newTotal && newTotal > 0) {
        calculatedStatus = 'Paid';
      } else if (newPaid > 0) {
        calculatedStatus = 'Partially Paid';
      } else {
        calculatedStatus = 'Pending';
      }
    }

    const paidDate = calculatedStatus === 'Paid' ? (payment.paid_date || new Date().toISOString()) : payment.paid_date;

    await db.run(
      `UPDATE payments 
       SET total_amount = ?,
           amount_paid = ?,
           remaining_balance = ?,
           advance_required = COALESCE(?, advance_required),
           due_date = COALESCE(?, due_date),
           status = ?,
           paid_date = ?,
           payment_method = COALESCE(?, payment_method),
           transaction_id = COALESCE(?, transaction_id),
           notes = COALESCE(?, notes),
           updated_at = CURRENT_TIMESTAMP
       WHERE id = ?`,
      [
        newTotal,
        newPaid,
        newRemaining,
        advance_required,
        due_date,
        calculatedStatus,
        paidDate,
        payment_method,
        transaction_id,
        notes,
        paymentId
      ]
    );

    // Update project ledger
    await db.run(
      `UPDATE projects 
       SET paid_amount = ?, remaining_balance = ? - ?, updated_at = CURRENT_TIMESTAMP 
       WHERE id = ?`,
      [newPaid, newTotal, newPaid, payment.project_id]
    );

    // If marked Paid, notify client
    if (calculatedStatus === 'Paid' && payment.status !== 'Paid') {
      const client = await db.get('SELECT email, full_name FROM users WHERE id = ?', [payment.user_id]);
      if (client) {
        await createNotification({
          userId: payment.user_id,
          title: 'Payment Confirmed!',
          message: `Your payment for invoice ${payment.invoice_number} (₹${newPaid.toLocaleString('en-IN')}) has been confirmed. Thank you!`,
          type: 'payment',
          link: '/dashboard#billing',
          sendAsEmail: true,
          emailTo: client.email
        });
      }
    }

    await logActivity({
      userId: req.user.id,
      action: 'Updated Payment',
      entityType: 'payment',
      entityId: paymentId,
      details: `Updated ${payment.invoice_number}: Paid ₹${newPaid}, Remaining ₹${newRemaining}, Status: ${calculatedStatus}`,
      ipAddress: req.ip
    });

    return res.json({ success: true, message: 'Payment record updated successfully.' });
  } catch (error) {
    console.error('Update payment error:', error);
    return res.status(500).json({ success: false, message: 'Server error updating payment.' });
  }
});

// POST /api/payments/:id/pay (Client initiates or completes payment)
router.post('/:id/pay', authenticateToken, async (req, res) => {
  try {
    const paymentId = req.params.id;
    const { amount, payment_method = 'Online / UPI', transaction_id } = req.body;

    const payment = await db.get('SELECT * FROM payments WHERE id = ?', [paymentId]);
    if (!payment) {
      return res.status(404).json({ success: false, message: 'Invoice not found.' });
    }

    if (payment.user_id !== req.user.id) {
      return res.status(403).json({ success: false, message: 'Permission denied.' });
    }

    const payAmount = parseFloat(amount) || payment.remaining_balance;
    const updatedPaid = payment.amount_paid + payAmount;
    const updatedRemaining = Math.max(0, payment.total_amount - updatedPaid);
    const newStatus = updatedRemaining <= 0 ? 'Paid' : 'Partially Paid';
    const txnId = transaction_id || `TXN_${Date.now()}_${Math.floor(Math.random() * 1000)}`;

    await db.run(
      `UPDATE payments 
       SET amount_paid = ?,
           remaining_balance = ?,
           status = ?,
           paid_date = CURRENT_TIMESTAMP,
           payment_method = ?,
           transaction_id = ?,
           updated_at = CURRENT_TIMESTAMP
       WHERE id = ?`,
      [updatedPaid, updatedRemaining, newStatus, payment_method, txnId, paymentId]
    );

    // Sync project financial balance
    await db.run(
      `UPDATE projects 
       SET paid_amount = ?, remaining_balance = ?
       WHERE id = ?`,
      [updatedPaid, updatedRemaining, payment.project_id]
    );

    // Log Activity
    await logActivity({
      userId: req.user.id,
      action: 'Client Made Payment',
      entityType: 'payment',
      entityId: paymentId,
      details: `Client paid ₹${payAmount} for ${payment.invoice_number}. Txn ID: ${txnId}`,
      ipAddress: req.ip
    });

    // Notify Client
    await createNotification({
      userId: req.user.id,
      title: 'Payment Successful',
      message: `Payment of ₹${payAmount.toLocaleString('en-IN')} for ${payment.invoice_number} received successfully. Transaction ID: ${txnId}.`,
      type: 'payment',
      link: '/dashboard#billing',
      sendAsEmail: true,
      emailTo: req.user.email
    });

    return res.json({
      success: true,
      message: 'Payment processed successfully!',
      transactionId: txnId,
      newStatus,
      amountPaid: updatedPaid,
      remainingBalance: updatedRemaining
    });
  } catch (error) {
    console.error('Process payment error:', error);
    return res.status(500).json({ success: false, message: 'Server error processing payment.' });
  }
});

// POST /api/payments/:id/remind (Admin sends 1-click payment reminder)
router.post('/:id/remind', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const paymentId = req.params.id;
    const payment = await db.get(
      `SELECT pm.*, p.title as project_title, u.email as client_email, u.full_name as client_name 
       FROM payments pm 
       JOIN projects p ON pm.project_id = p.id 
       JOIN users u ON pm.user_id = u.id 
       WHERE pm.id = ?`,
      [paymentId]
    );

    if (!payment) {
      return res.status(404).json({ success: false, message: 'Payment record not found.' });
    }

    const emailSubject = `[DevHorizan] Payment Reminder: Invoice ${payment.invoice_number}`;
    const emailBody = `Dear ${payment.client_name},\n\nThis is a friendly reminder that a payment of ₹${payment.remaining_balance.toLocaleString('en-IN')} for project "${payment.project_title}" is due on ${payment.due_date || 'immediate basis'}.\n\nPlease review your invoice here: http://localhost:3000/dashboard#billing\n\nThank you,\nDevHorizan Billing Team`;

    // In-app notification
    await createNotification({
      userId: payment.user_id,
      title: `Payment Reminder: ${payment.invoice_number}`,
      message: `Reminder for outstanding balance of ₹${payment.remaining_balance.toLocaleString('en-IN')} for project "${payment.project_title}". Due date: ${payment.due_date || 'N/A'}.`,
      type: 'payment',
      link: '/dashboard#billing',
      sendAsEmail: true,
      emailTo: payment.client_email
    });

    await logActivity({
      userId: req.user.id,
      action: 'Sent Payment Reminder',
      entityType: 'payment',
      entityId: paymentId,
      details: `Admin dispatched reminder for invoice ${payment.invoice_number} to ${payment.client_email}`,
      ipAddress: req.ip
    });

    return res.json({
      success: true,
      message: `Payment reminder successfully dispatched to ${payment.client_email}.`
    });
  } catch (error) {
    console.error('Send payment reminder error:', error);
    return res.status(500).json({ success: false, message: 'Server error sending reminder.' });
  }
});

// POST /api/payments/send-invoice-email (Compatible with Project 3 email-server)
router.post('/send-invoice-email', async (req, res) => {
  try {
    const { to_email, subject, message, pdf_base64, invoice_number } = req.body;

    if (!to_email || !subject || !message) {
      return res.status(400).json({
        success: false,
        message: 'Missing required fields: to_email, subject, message'
      });
    }

    const attachments = [];
    if (pdf_base64) {
      attachments.push({
        filename: `Invoice_${invoice_number || 'Invoice'}.pdf`,
        content: Buffer.from(pdf_base64, 'base64'),
        contentType: 'application/pdf'
      });
    }

    const result = await sendEmail({
      to: to_email,
      subject,
      text: message,
      html: `<pre style="font-family: Arial, sans-serif; white-space: pre-wrap;">${message}</pre>`,
      attachments
    });

    return res.json({
      success: result.success,
      message: result.success ? 'Email sent successfully!' : result.error,
      messageId: result.messageId
    });
  } catch (error) {
    console.error('Send invoice email error:', error);
    return res.status(500).json({ success: false, message: 'Failed to send email: ' + error.message });
  }
});

module.exports = router;

