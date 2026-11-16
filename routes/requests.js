const express = require('express');
const router = express.Router();
const db = require('../config/db');
const { authenticateToken, requireAdmin } = require('../middleware/auth');
const { logActivity } = require('../services/activityService');
const { createNotification, notifyAdmins } = require('../services/notificationService');

// GET /api/requests (list requests)
router.get('/', authenticateToken, async (req, res) => {
  try {
    let sql;
    let params = [];

    const selectFields = `
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
    `;

    if (req.user.role === 'admin') {
      sql = `
        SELECT ${selectFields}
        FROM project_requests r 
        JOIN users u ON r.user_id = u.id 
        LEFT JOIN project_revisions rev ON rev.id = (
          SELECT id FROM project_revisions WHERE request_id = r.id ORDER BY id DESC LIMIT 1
        )
        ORDER BY r.created_at DESC
      `;
    } else {
      sql = `
        SELECT ${selectFields}
        FROM project_requests r 
        JOIN users u ON r.user_id = u.id 
        LEFT JOIN project_revisions rev ON rev.id = (
          SELECT id FROM project_revisions WHERE request_id = r.id ORDER BY id DESC LIMIT 1
        )
        WHERE r.user_id = ? 
        ORDER BY r.created_at DESC
      `;
      params = [req.user.id];
    }

    const requests = await db.all(sql, params);
    return res.json({ success: true, requests });
  } catch (error) {
    console.error('Get requests error:', error);
    return res.status(500).json({ success: false, message: 'Server error retrieving project requests.' });
  }
});

// GET /api/requests/:id (detailed request with revision history)
router.get('/:id', authenticateToken, async (req, res) => {
  try {
    const requestId = req.params.id;
    const request = await db.get(
      `SELECT r.*, u.full_name as client_name, u.email as client_email, u.phone as client_phone 
       FROM project_requests r 
       JOIN users u ON r.user_id = u.id 
       WHERE r.id = ?`,
      [requestId]
    );

    if (!request) {
      return res.status(404).json({ success: false, message: 'Project request not found.' });
    }

    if (req.user.role !== 'admin' && request.user_id !== req.user.id) {
      return res.status(403).json({ success: false, message: 'Permission denied to view this request.' });
    }

    // Load complete revision history
    const revisions = await db.all(
      'SELECT * FROM project_revisions WHERE request_id = ? ORDER BY revision_number DESC, id DESC',
      [requestId]
    );

    return res.json({
      success: true,
      request: {
        ...request,
        revisions
      }
    });
  } catch (error) {
    console.error('Get request detail error:', error);
    return res.status(500).json({ success: false, message: 'Server error retrieving request details.' });
  }
});

// POST /api/requests (Client submit new project request)
router.post('/', authenticateToken, async (req, res) => {
  try {
    const {
      project_name,
      project_type,
      description,
      required_features,
      expected_budget,
      expected_deadline,
      additional_requirements,
      reference_attachments
    } = req.body;

    if (!project_name || !project_type || !description) {
      return res.status(400).json({
        success: false,
        message: 'Project name, project type, and description are required.'
      });
    }

    const randomSuffix = Math.floor(1000 + Math.random() * 9000);
    const requestCode = `REQ-${new Date().getFullYear()}-${randomSuffix}`;
    const budgetNum = parseFloat(expected_budget) || 0;

    const result = await db.run(
      `INSERT INTO project_requests (request_code, user_id, project_name, project_type, description, required_features, expected_budget, expected_deadline, additional_requirements, reference_attachments, status)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted')`,
      [
        requestCode,
        req.user.id,
        project_name.trim(),
        project_type,
        description.trim(),
        required_features || null,
        budgetNum,
        expected_deadline || null,
        additional_requirements || null,
        reference_attachments || null
      ]
    );

    const requestId = result.lastID;

    // Log Activity
    await logActivity({
      userId: req.user.id,
      action: 'Submitted Request',
      entityType: 'request',
      entityId: requestId,
      details: `Client submitted request ${requestCode} for "${project_name}" (Est: ₹${budgetNum})`,
      ipAddress: req.ip
    });

    // Notify Admins
    await notifyAdmins({
      title: 'New Project Request Submitted',
      message: `${req.user.full_name} submitted request ${requestCode}: "${project_name}" (Est. Budget: ₹${budgetNum.toLocaleString('en-IN')})`,
      type: 'request',
      link: '/admin#projectRequests'
    });

    // Confirmation notification to client
    await createNotification({
      userId: req.user.id,
      title: 'Project Request Submitted',
      message: `Your request ${requestCode} for "${project_name}" was received and is awaiting admin review.`,
      type: 'request',
      link: '/dashboard#requests'
    });

    return res.status(201).json({
      success: true,
      message: 'Project request submitted successfully!',
      requestId,
      requestCode
    });
  } catch (error) {
    console.error('Submit request error:', error);
    return res.status(500).json({ success: false, message: 'Server error submitting request.' });
  }
});

// POST /api/requests/:id/revision (Admin sends counter-proposal / revision request)
router.post('/:id/revision', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const requestId = req.params.id;
    const {
      proposed_budget,
      updated_requirements,
      scope_changes,
      reason_for_revision,
      admin_comments
    } = req.body;

    if (!proposed_budget || !reason_for_revision) {
      return res.status(400).json({
        success: false,
        message: 'Proposed budget and reason for revision are required.'
      });
    }

    const request = await db.get(
      'SELECT r.*, u.email, u.full_name FROM project_requests r JOIN users u ON r.user_id = u.id WHERE r.id = ?',
      [requestId]
    );

    if (!request) {
      return res.status(404).json({ success: false, message: 'Project request not found.' });
    }

    // Count existing revisions
    const revCountRow = await db.get(
      'SELECT COUNT(*) as count FROM project_revisions WHERE request_id = ?',
      [requestId]
    );
    const nextRevNumber = (revCountRow ? revCountRow.count : 0) + 1;

    // Insert new revision proposal
    await db.run(
      `INSERT INTO project_revisions (request_id, revision_number, proposed_budget, updated_requirements, scope_changes, reason_for_revision, admin_comments, status)
       VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending User Response')`,
      [
        requestId,
        nextRevNumber,
        parseFloat(proposed_budget),
        updated_requirements || null,
        scope_changes || null,
        reason_for_revision.trim(),
        admin_comments || null
      ]
    );

    // Update request status to 'Revision Requested'
    await db.run(
      `UPDATE project_requests 
       SET status = 'Revision Requested', 
           admin_notes = ?, 
           updated_at = CURRENT_TIMESTAMP 
       WHERE id = ?`,
      [admin_comments || reason_for_revision, requestId]
    );

    // Log Activity
    await logActivity({
      userId: req.user.id,
      action: 'Sent Revision Proposal',
      entityType: 'revision',
      entityId: requestId,
      details: `Admin proposed revised budget ₹${proposed_budget} for request ${request.request_code}`,
      ipAddress: req.ip
    });

    // Notify Client via in-app notification & email
    await createNotification({
      userId: request.user_id,
      title: 'Budget & Scope Revision Proposal',
      message: `Admin reviewed request "${request.project_name}" and proposed a revised budget of ₹${parseFloat(proposed_budget).toLocaleString('en-IN')}. Reason: ${reason_for_revision}`,
      type: 'revision',
      link: `/dashboard#requests?rev=${requestId}`,
      sendAsEmail: true,
      emailTo: request.email
    });

    return res.json({
      success: true,
      message: 'Revision proposal successfully sent to client.'
    });
  } catch (error) {
    console.error('Send revision error:', error);
    return res.status(500).json({ success: false, message: 'Server error sending revision proposal.' });
  }
});

// POST /api/requests/:id/respond (Client responds to revision proposal: Accept, Reject, or Update & Resubmit)
router.post('/:id/respond', authenticateToken, async (req, res) => {
  try {
    const requestId = req.params.id;
    const { action, notes, updated_description, updated_budget } = req.body;

    if (!['accept', 'reject', 'resubmit'].includes(action)) {
      return res.status(400).json({
        success: false,
        message: 'Invalid action. Must be "accept", "reject", or "resubmit".'
      });
    }

    const request = await db.get('SELECT * FROM project_requests WHERE id = ?', [requestId]);
    if (!request) {
      return res.status(404).json({ success: false, message: 'Request not found.' });
    }

    if (request.user_id !== req.user.id) {
      return res.status(403).json({ success: false, message: 'Permission denied.' });
    }

    const latestRevision = await db.get(
      'SELECT * FROM project_revisions WHERE request_id = ? ORDER BY id DESC LIMIT 1',
      [requestId]
    );

    if (action === 'accept') {
      // 1. Mark revision as Accepted
      if (latestRevision) {
        await db.run(
          `UPDATE project_revisions 
           SET status = 'Accepted', user_response_notes = ?, responded_at = CURRENT_TIMESTAMP 
           WHERE id = ?`,
          [notes || 'Client accepted revised proposal terms.', latestRevision.id]
        );
      }

      // 2. Mark request as Approved
      await db.run(
        `UPDATE project_requests 
         SET status = 'Approved', updated_at = CURRENT_TIMESTAMP 
         WHERE id = ?`,
        [requestId]
      );

      // 3. Automatically convert into an active project (enforce ONE project tile and ONE invoice!)
      const finalCost = latestRevision ? latestRevision.proposed_budget : request.expected_budget;
      const existingProject = await db.get(
        'SELECT * FROM projects WHERE request_id = ? OR (user_id = ? AND LOWER(TRIM(title)) = LOWER(TRIM(?))) ORDER BY id DESC LIMIT 1',
        [requestId, req.user.id, request.project_name]
      );
      let newProjectId;

      if (existingProject) {
        newProjectId = existingProject.id;
        const newRemaining = Math.max(0, finalCost - existingProject.paid_amount);
        await db.run(
          `UPDATE projects 
           SET title = COALESCE(?, title),
               category = COALESCE(?, category),
               description = COALESCE(?, description),
               status = 'Approved',
               total_cost = ?,
               remaining_balance = ?,
               updated_at = CURRENT_TIMESTAMP
           WHERE id = ?`,
          [
            request.project_name,
            request.project_type,
            latestRevision && latestRevision.updated_requirements ? latestRevision.updated_requirements : request.description,
            finalCost,
            newRemaining,
            newProjectId
          ]
        );

        // Keep invoice synchronized without creating duplicate invoices
        const existingPayment = await db.get('SELECT * FROM payments WHERE project_id = ? ORDER BY id ASC LIMIT 1', [newProjectId]);
        if (existingPayment) {
          const payRemaining = Math.max(0, finalCost - existingPayment.amount_paid);
          const payStatus = existingPayment.amount_paid >= finalCost ? 'Paid' : existingPayment.amount_paid > 0 ? 'Partially Paid' : existingPayment.status;
          await db.run(
            `UPDATE payments 
             SET total_amount = ?, remaining_balance = ?, status = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?`,
            [finalCost, payRemaining, payStatus, existingPayment.id]
          );
        }
      } else {
        const projectResult = await db.run(
          `INSERT INTO projects (user_id, request_id, title, category, description, status, progress_percent, total_cost, paid_amount, remaining_balance, start_date, expected_delivery_date)
           VALUES (?, ?, ?, ?, ?, 'Approved', 10, ?, 0, ?, ?, ?)`,
          [
            req.user.id,
            requestId,
            request.project_name,
            request.project_type,
            latestRevision && latestRevision.updated_requirements ? latestRevision.updated_requirements : request.description,
            finalCost,
            finalCost,
            new Date().toISOString().split('T')[0],
            request.expected_deadline
          ]
        );

        newProjectId = projectResult.lastID;

        // Create initial invoice
        const invNum = `INV-${new Date().getFullYear()}-${String(newProjectId).padStart(4, '0')}`;
        await db.run(
          `INSERT INTO payments (invoice_number, project_id, user_id, total_amount, amount_paid, remaining_balance, advance_required, status, due_date, notes)
           VALUES (?, ?, ?, ?, 0, ?, ?, 'Pending', ?, ?)`,
          [
            invNum,
            newProjectId,
            req.user.id,
            finalCost,
            finalCost,
            finalCost * 0.5,
            request.expected_deadline,
            `Initial invoice for approved project ${request.project_name} (from accepted revision)`
          ]
        );
      }

      // Notify Admin
      await notifyAdmins({
        title: 'Proposal Accepted by Client',
        message: `${req.user.full_name} accepted the revised terms for "${request.project_name}". Active project #${newProjectId} was created!`,
        type: 'project',
        link: '/admin#projects'
      });

      // Confirmation notification to client
      await createNotification({
        userId: req.user.id,
        title: 'Proposal Accepted & Project Activated',
        message: `You accepted the revised proposal for "${request.project_name}". Active project #${newProjectId} has been created and is now in progress!`,
        type: 'project',
        link: '/dashboard#projects'
      });

      await logActivity({
        userId: req.user.id,
        action: 'Accepted Proposal',
        entityType: 'request',
        entityId: requestId,
        details: `Client accepted proposal for ${request.request_code}. Project #${newProjectId} generated.`,
        ipAddress: req.ip
      });

      return res.json({
        success: true,
        message: 'Proposal accepted! Your project is now approved and active.',
        projectId: newProjectId
      });
    }

    if (action === 'reject') {
      if (latestRevision) {
        await db.run(
          `UPDATE project_revisions 
           SET status = 'Rejected', user_response_notes = ?, responded_at = CURRENT_TIMESTAMP 
           WHERE id = ?`,
          [notes || 'Client declined the proposed revision.', latestRevision.id]
        );
      }

      await db.run(
        `UPDATE project_requests 
         SET status = 'Rejected', updated_at = CURRENT_TIMESTAMP 
         WHERE id = ?`,
        [requestId]
      );

      await notifyAdmins({
        title: 'Proposal Declined by Client',
        message: `${req.user.full_name} declined the revision proposal for "${request.project_name}". Reason: ${notes || 'No reason specified'}`,
        type: 'revision',
        link: '/admin#projectRequests'
      });

      await createNotification({
        userId: req.user.id,
        title: 'Revision Proposal Declined',
        message: `You declined the revision proposal for "${request.project_name}". The request has been marked as declined.`,
        type: 'request',
        link: '/dashboard#requests'
      });

      await logActivity({
        userId: req.user.id,
        action: 'Declined Proposal',
        entityType: 'request',
        entityId: requestId,
        details: `Client declined revision proposal for ${request.request_code}`,
        ipAddress: req.ip
      });

      return res.json({
        success: true,
        message: 'Proposal declined. Request status updated.'
      });
    }

    if (action === 'resubmit') {
      if (latestRevision) {
        await db.run(
          `UPDATE project_revisions 
           SET status = 'Resubmitted', user_response_notes = ?, responded_at = CURRENT_TIMESTAMP 
           WHERE id = ?`,
          [notes || 'Client revised requirements and counter-submitted.', latestRevision.id]
        );
      }

      const newBudget = updated_budget ? parseFloat(updated_budget) : request.expected_budget;
      const newDesc = updated_description ? updated_description.trim() : request.description;

      await db.run(
        `UPDATE project_requests 
         SET description = ?, 
             expected_budget = ?, 
             status = 'Under Admin Review', 
             additional_requirements = COALESCE(?, additional_requirements),
             updated_at = CURRENT_TIMESTAMP 
         WHERE id = ?`,
        [newDesc, newBudget, notes, requestId]
      );

      await notifyAdmins({
        title: 'Project Requirements Resubmitted',
        message: `${req.user.full_name} revised requirements and resubmitted "${request.project_name}" for review. (Counter-Budget: ₹${newBudget.toLocaleString('en-IN')})`,
        type: 'request',
        link: '/admin#projectRequests'
      });

      await createNotification({
        userId: req.user.id,
        title: 'Counter-Proposal Sent to Admin',
        message: `Your revised requirements and counter-proposed budget of ₹${newBudget.toLocaleString('en-IN')} for "${request.project_name}" have been sent to admin for re-review.`,
        type: 'request',
        link: '/dashboard#requests'
      });

      await logActivity({
        userId: req.user.id,
        action: 'Resubmitted Request',
        entityType: 'request',
        entityId: requestId,
        details: `Client counter-revised requirements for ${request.request_code}`,
        ipAddress: req.ip
      });

      return res.json({
        success: true,
        message: 'Your revised requirements have been submitted to the admin for re-review.'
      });
    }
  } catch (error) {
    console.error('Respond to revision error:', error);
    return res.status(500).json({ success: false, message: 'Server error processing revision response.' });
  }
});

// POST /api/requests/:id/approve (Admin direct approval)
router.post('/:id/approve', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const requestId = req.params.id;
    const { final_budget, start_date, expected_delivery_date } = req.body;

    const request = await db.get(
      'SELECT r.*, u.email, u.full_name FROM project_requests r JOIN users u ON r.user_id = u.id WHERE r.id = ?',
      [requestId]
    );

    if (!request) {
      return res.status(404).json({ success: false, message: 'Request not found.' });
    }

    const cost = final_budget ? parseFloat(final_budget) : request.expected_budget;

    // Update request
    await db.run(
      "UPDATE project_requests SET status = 'Approved', updated_at = CURRENT_TIMESTAMP WHERE id = ?",
      [requestId]
    );

    // Check if project ALREADY exists for this request OR client+title (enforce exactly ONE project tile and ONE invoice)
    const existingProject = await db.get(
      'SELECT * FROM projects WHERE request_id = ? OR (user_id = ? AND LOWER(TRIM(title)) = LOWER(TRIM(?))) ORDER BY id DESC LIMIT 1',
      [requestId, request.user_id, request.project_name]
    );
    let projectId;

    if (existingProject) {
      projectId = existingProject.id;
      const newRemaining = Math.max(0, cost - existingProject.paid_amount);
      await db.run(
        `UPDATE projects 
         SET title = COALESCE(?, title),
             category = COALESCE(?, category),
             description = COALESCE(?, description),
             status = 'Approved',
             total_cost = ?,
             remaining_balance = ?,
             start_date = COALESCE(?, start_date),
             expected_delivery_date = COALESCE(?, expected_delivery_date),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?`,
        [
          request.project_name,
          request.project_type,
          request.description,
          cost,
          newRemaining,
          start_date,
          expected_delivery_date || request.expected_deadline,
          projectId
        ]
      );

      // Keep existing invoice updated and synchronized
      const existingPayment = await db.get('SELECT * FROM payments WHERE project_id = ? ORDER BY id ASC LIMIT 1', [projectId]);
      if (existingPayment) {
        const payRemaining = Math.max(0, cost - existingPayment.amount_paid);
        const payStatus = existingPayment.amount_paid >= cost ? 'Paid' : existingPayment.amount_paid > 0 ? 'Partially Paid' : existingPayment.status;
        await db.run(
          `UPDATE payments 
           SET total_amount = ?, remaining_balance = ?, status = ?, due_date = COALESCE(?, due_date), updated_at = CURRENT_TIMESTAMP
           WHERE id = ?`,
          [cost, payRemaining, payStatus, expected_delivery_date || request.expected_deadline, existingPayment.id]
        );
      }
    } else {
      // Create project
      const projectResult = await db.run(
        `INSERT INTO projects (user_id, request_id, title, category, description, status, progress_percent, total_cost, paid_amount, remaining_balance, start_date, expected_delivery_date)
         VALUES (?, ?, ?, ?, ?, 'Approved', 10, ?, 0, ?, ?, ?)`,
        [
          request.user_id,
          requestId,
          request.project_name,
          request.project_type,
          request.description,
          cost,
          cost,
          start_date || new Date().toISOString().split('T')[0],
          expected_delivery_date || request.expected_deadline
        ]
      );

      projectId = projectResult.lastID;

      // Create initial invoice
      const invNum = `INV-${new Date().getFullYear()}-${String(projectId).padStart(4, '0')}`;
      await db.run(
        `INSERT INTO payments (invoice_number, project_id, user_id, total_amount, amount_paid, remaining_balance, advance_required, status, due_date, notes)
         VALUES (?, ?, ?, ?, 0, ?, ?, 'Pending', ?, ?)`,
        [
          invNum,
          projectId,
          request.user_id,
          cost,
          cost,
          cost * 0.5,
          expected_delivery_date || request.expected_deadline,
          `Initial invoice for approved project ${request.project_name}`
        ]
      );
    }

    await createNotification({
      userId: request.user_id,
      title: 'Project Request Approved!',
      message: `Your request "${request.project_name}" has been approved and created as an active project.`,
      type: 'project',
      link: '/dashboard#projects',
      sendAsEmail: true,
      emailTo: request.email
    });

    await logActivity({
      userId: req.user.id,
      action: 'Approved Project Request',
      entityType: 'request',
      entityId: requestId,
      details: `Admin approved request ${request.request_code} and created project #${projectId}`,
      ipAddress: req.ip
    });

    return res.json({
      success: true,
      message: 'Request approved and project created successfully!',
      projectId
    });
  } catch (error) {
    console.error('Approve request error:', error);
    return res.status(500).json({ success: false, message: 'Server error approving request.' });
  }
});

// POST /api/requests/:id/reject (Admin direct rejection)
router.post('/:id/reject', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const requestId = req.params.id;
    const { reason } = req.body;

    const request = await db.get(
      'SELECT r.*, u.email, u.full_name FROM project_requests r JOIN users u ON r.user_id = u.id WHERE r.id = ?',
      [requestId]
    );

    if (!request) {
      return res.status(404).json({ success: false, message: 'Request not found.' });
    }

    await db.run(
      "UPDATE project_requests SET status = 'Rejected', admin_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
      [reason || 'Not aligned with current capabilities or scope requirements.', requestId]
    );

    await createNotification({
      userId: request.user_id,
      title: 'Project Request Update',
      message: `Your request "${request.project_name}" was rejected. Reason: ${reason || 'Contact team for details.'}`,
      type: 'request',
      link: '/dashboard#requests',
      sendAsEmail: true,
      emailTo: request.email
    });

    await logActivity({
      userId: req.user.id,
      action: 'Rejected Project Request',
      entityType: 'request',
      entityId: requestId,
      details: `Admin rejected request ${request.request_code}`,
      ipAddress: req.ip
    });

    return res.json({ success: true, message: 'Project request rejected.' });
  } catch (error) {
    console.error('Reject request error:', error);
    return res.status(500).json({ success: false, message: 'Server error rejecting request.' });
  }
});

module.exports = router;

