const express = require('express');
const router = express.Router();
const db = require('../config/db');
const { authenticateToken, requireAdmin } = require('../middleware/auth');
const { logActivity } = require('../services/activityService');
const { createNotification } = require('../services/notificationService');

// GET /api/projects (list projects)
router.get('/', authenticateToken, async (req, res) => {
  try {
    let sql;
    let params = [];

    if (req.user.role === 'admin') {
      sql = `
        SELECT p.*, u.full_name as client_name, u.email as client_email 
        FROM projects p 
        JOIN users u ON p.user_id = u.id 
        ORDER BY p.updated_at DESC
      `;
    } else {
      sql = `
        SELECT p.*, u.full_name as client_name, u.email as client_email 
        FROM projects p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.user_id = ? 
        ORDER BY p.updated_at DESC
      `;
      params = [req.user.id];
    }

    const projects = await db.all(sql, params);
    return res.json({ success: true, projects });
  } catch (error) {
    console.error('Get projects error:', error);
    return res.status(500).json({ success: false, message: 'Server error retrieving projects.' });
  }
});

// GET /api/projects/:id (detailed project view)
router.get('/:id', authenticateToken, async (req, res) => {
  try {
    const projectId = req.params.id;
    let project = await db.get(
      `SELECT p.*, u.full_name as client_name, u.email as client_email, u.phone as client_phone 
       FROM projects p 
       JOIN users u ON p.user_id = u.id 
       WHERE p.id = ?`,
      [projectId]
    );

    if (!project) {
      return res.status(404).json({ success: false, message: 'Project not found.' });
    }

    // Role check: non-admin can only access own project
    if (req.user.role !== 'admin' && project.user_id !== req.user.id) {
      return res.status(403).json({ success: false, message: 'Permission denied to view this project.' });
    }

    // Milestones
    const milestones = await db.all(
      'SELECT * FROM project_milestones WHERE project_id = ? ORDER BY due_date ASC, id ASC',
      [projectId]
    );

    // Updates
    const updates = await db.all(
      `SELECT pu.*, u.full_name as author_name, u.role as author_role 
       FROM project_updates pu 
       JOIN users u ON pu.author_id = u.id 
       WHERE pu.project_id = ? 
       ORDER BY pu.created_at DESC`,
      [projectId]
    );

    // Documents
    const documents = await db.all(
      'SELECT * FROM documents WHERE project_id = ? ORDER BY created_at DESC',
      [projectId]
    );

    // Payments
    const payments = await db.all(
      'SELECT * FROM payments WHERE project_id = ? ORDER BY due_date ASC, id ASC',
      [projectId]
    );

    return res.json({
      success: true,
      project: {
        ...project,
        milestones,
        updates,
        documents,
        payments
      }
    });
  } catch (error) {
    console.error('Get project detail error:', error);
    return res.status(500).json({ success: false, message: 'Server error retrieving project details.' });
  }
});

// POST /api/projects (Admin create project manually)
router.post('/', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const {
      user_id,
      title,
      category = 'Web Development',
      description,
      status = 'Approved',
      progress_percent = 0,
      total_cost = 0,
      start_date,
      expected_delivery_date
    } = req.body;

    if (!user_id || !title) {
      return res.status(400).json({ success: false, message: 'User ID and project title are required.' });
    }

    const clientUser = await db.get('SELECT id, email, full_name FROM users WHERE id = ?', [user_id]);
    if (!clientUser) {
      return res.status(404).json({ success: false, message: 'Assigned client user not found.' });
    }

    const costNum = parseFloat(total_cost) || 0;

    // Check if project already exists for this client with same title (enforce ONE project tile and ONE invoice)
    const existingProject = await db.get(
      'SELECT * FROM projects WHERE user_id = ? AND LOWER(TRIM(title)) = LOWER(TRIM(?)) ORDER BY id DESC LIMIT 1',
      [user_id, title.trim()]
    );

    let projectId;

    if (existingProject) {
      projectId = existingProject.id;
      const newCost = costNum > 0 ? costNum : existingProject.total_cost;
      const newRemaining = Math.max(0, newCost - existingProject.paid_amount);
      await db.run(
        `UPDATE projects 
         SET category = ?,
             description = COALESCE(?, description),
             status = ?,
             progress_percent = ?,
             total_cost = ?,
             remaining_balance = ?,
             start_date = COALESCE(?, start_date),
             expected_delivery_date = COALESCE(?, expected_delivery_date),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?`,
        [
          category,
          description || null,
          status,
          parseInt(progress_percent, 10) || 0,
          newCost,
          newRemaining,
          start_date || existingProject.start_date,
          expected_delivery_date || existingProject.expected_delivery_date,
          projectId
        ]
      );

      // Keep invoice updated without duplicate bill
      const existingPayment = await db.get('SELECT * FROM payments WHERE project_id = ? ORDER BY id ASC LIMIT 1', [projectId]);
      if (existingPayment) {
        const payRemaining = Math.max(0, newCost - existingPayment.amount_paid);
        const payStatus = existingPayment.amount_paid >= newCost ? 'Paid' : existingPayment.amount_paid > 0 ? 'Partially Paid' : existingPayment.status;
        await db.run(
          `UPDATE payments 
           SET total_amount = ?, remaining_balance = ?, status = ?, due_date = COALESCE(?, due_date), updated_at = CURRENT_TIMESTAMP 
           WHERE id = ?`,
          [newCost, payRemaining, payStatus, expected_delivery_date || existingPayment.due_date, existingPayment.id]
        );
      } else if (costNum > 0) {
        const invNum = `INV-${new Date().getFullYear()}-${String(projectId).padStart(4, '0')}`;
        await db.run(
          `INSERT INTO payments (invoice_number, project_id, user_id, total_amount, amount_paid, remaining_balance, advance_required, status, due_date, notes)
           VALUES (?, ?, ?, ?, 0, ?, ?, 'Pending', ?, ?)`,
          [
            invNum,
            projectId,
            user_id,
            costNum,
            costNum,
            costNum * 0.5,
            expected_delivery_date || null,
            `Initial invoice generated for project: ${title}`
          ]
        );
      }
    } else {
      const result = await db.run(
        `INSERT INTO projects (user_id, title, category, description, status, progress_percent, total_cost, paid_amount, remaining_balance, start_date, expected_delivery_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)`,
        [
          user_id,
          title.trim(),
          category,
          description || null,
          status,
          parseInt(progress_percent, 10) || 0,
          costNum,
          costNum,
          start_date || new Date().toISOString().split('T')[0],
          expected_delivery_date || null
        ]
      );

      projectId = result.lastID;

      // Create automatic initial payment/invoice record if total_cost > 0
      if (costNum > 0) {
        const invNum = `INV-${new Date().getFullYear()}-${String(projectId).padStart(4, '0')}`;
        await db.run(
          `INSERT INTO payments (invoice_number, project_id, user_id, total_amount, amount_paid, remaining_balance, advance_required, status, due_date, notes)
           VALUES (?, ?, ?, ?, 0, ?, ?, 'Pending', ?, ?)`,
          [
            invNum,
            projectId,
            user_id,
            costNum,
            costNum,
            costNum * 0.5, // 50% default advance
            expected_delivery_date || null,
            `Initial invoice generated for project: ${title}`
          ]
        );
      }
    }

    // Activity Log
    await logActivity({
      userId: req.user.id,
      action: 'Created Project',
      entityType: 'project',
      entityId: projectId,
      details: `Admin created project "${title}" for client ${clientUser.full_name} (${costNum} INR)`,
      ipAddress: req.ip
    });

    // Notify client
    await createNotification({
      userId: user_id,
      title: 'New Project Created',
      message: `Your project "${title}" has been set up with status: ${status}.`,
      type: 'project',
      link: '/dashboard#projects',
      sendAsEmail: true,
      emailTo: clientUser.email
    });

    return res.status(201).json({
      success: true,
      message: 'Project created successfully!',
      projectId
    });
  } catch (error) {
    console.error('Create project error:', error);
    return res.status(500).json({ success: false, message: 'Server error creating project.' });
  }
});

// PUT /api/projects/:id (Admin edit project)
router.put('/:id', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const projectId = req.params.id;
    const project = await db.get('SELECT * FROM projects WHERE id = ?', [projectId]);
    if (!project) {
      return res.status(404).json({ success: false, message: 'Project not found.' });
    }

    const {
      title,
      category,
      description,
      status,
      progress_percent,
      total_cost,
      start_date,
      expected_delivery_date
    } = req.body;

    const newCost = total_cost !== undefined ? parseFloat(total_cost) : project.total_cost;
    const newPaid = project.paid_amount;
    const newRemaining = Math.max(0, newCost - newPaid);
    const newProgress = progress_percent !== undefined ? parseInt(progress_percent, 10) : project.progress_percent;
    const newStatus = status || project.status;
    const completedAt = newStatus === 'Completed' ? new Date().toISOString() : null;

    await db.run(
      `UPDATE projects 
       SET title = COALESCE(?, title),
           category = COALESCE(?, category),
           description = COALESCE(?, description),
           status = ?,
           progress_percent = ?,
           total_cost = ?,
           remaining_balance = ?,
           start_date = COALESCE(?, start_date),
           expected_delivery_date = COALESCE(?, expected_delivery_date),
           completed_at = ?,
           updated_at = CURRENT_TIMESTAMP
       WHERE id = ?`,
      [
        title,
        category,
        description,
        newStatus,
        newProgress,
        newCost,
        newRemaining,
        start_date,
        expected_delivery_date,
        completedAt,
        projectId
      ]
    );

    // Keep associated payment invoice synchronized without creating duplicates
    const existingPayment = await db.get('SELECT * FROM payments WHERE project_id = ? ORDER BY id ASC LIMIT 1', [projectId]);
    if (existingPayment) {
      const payRemaining = Math.max(0, newCost - existingPayment.amount_paid);
      const payStatus = existingPayment.amount_paid >= newCost ? 'Paid' : existingPayment.amount_paid > 0 ? 'Partially Paid' : existingPayment.status;
      await db.run(
        `UPDATE payments 
         SET total_amount = ?, remaining_balance = ?, status = ?, updated_at = CURRENT_TIMESTAMP 
         WHERE id = ?`,
        [newCost, payRemaining, payStatus, existingPayment.id]
      );
    }

    // Notify client of status or progress change
    const client = await db.get('SELECT email, full_name FROM users WHERE id = ?', [project.user_id]);
    if (client && (newStatus !== project.status || newProgress !== project.progress_percent)) {
      await createNotification({
        userId: project.user_id,
        title: `Project Update: ${project.title}`,
        message: `Status updated to "${newStatus}" and completion is now ${newProgress}%.`,
        type: 'project',
        link: '/dashboard#projects',
        sendAsEmail: true,
        emailTo: client.email
      });
    }

    await logActivity({
      userId: req.user.id,
      action: 'Updated Project',
      entityType: 'project',
      entityId: projectId,
      details: `Updated project "${project.title}" to status ${newStatus}, progress ${newProgress}%`,
      ipAddress: req.ip
    });

    return res.json({ success: true, message: 'Project updated successfully.' });
  } catch (error) {
    console.error('Update project error:', error);
    return res.status(500).json({ success: false, message: 'Server error updating project.' });
  }
});

// DELETE /api/projects/:id (Admin delete project)
router.delete('/:id', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const projectId = req.params.id;
    const project = await db.get('SELECT title FROM projects WHERE id = ?', [projectId]);
    if (!project) {
      return res.status(404).json({ success: false, message: 'Project not found.' });
    }

    await db.run('DELETE FROM projects WHERE id = ?', [projectId]);

    await logActivity({
      userId: req.user.id,
      action: 'Deleted Project',
      entityType: 'project',
      entityId: projectId,
      details: `Admin deleted project "${project.title}"`,
      ipAddress: req.ip
    });

    return res.json({ success: true, message: 'Project deleted successfully.' });
  } catch (error) {
    console.error('Delete project error:', error);
    return res.status(500).json({ success: false, message: 'Server error deleting project.' });
  }
});

// POST /api/projects/:id/milestones (Add milestone)
router.post('/:id/milestones', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const projectId = req.params.id;
    const { title, description, due_date, status = 'Pending' } = req.body;

    if (!title) {
      return res.status(400).json({ success: false, message: 'Milestone title is required.' });
    }

    const project = await db.get('SELECT user_id, title FROM projects WHERE id = ?', [projectId]);
    if (!project) {
      return res.status(404).json({ success: false, message: 'Project not found.' });
    }

    const result = await db.run(
      `INSERT INTO project_milestones (project_id, title, description, due_date, status)
       VALUES (?, ?, ?, ?, ?)`,
      [projectId, title.trim(), description || null, due_date || null, status]
    );

    await createNotification({
      userId: project.user_id,
      title: 'New Milestone Added',
      message: `A new milestone "${title}" has been added to project "${project.title}".`,
      type: 'project',
      link: '/dashboard#projects'
    });

    return res.status(201).json({ success: true, message: 'Milestone added.', milestoneId: result.lastID });
  } catch (error) {
    console.error('Add milestone error:', error);
    return res.status(500).json({ success: false, message: 'Server error adding milestone.' });
  }
});

// PUT /api/projects/:id/milestones/:mId (Update milestone status)
router.put('/:id/milestones/:mId', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const { id: projectId, mId: milestoneId } = req.params;
    const { status, title, description, due_date } = req.body;

    const milestone = await db.get('SELECT * FROM project_milestones WHERE id = ? AND project_id = ?', [milestoneId, projectId]);
    if (!milestone) {
      return res.status(404).json({ success: false, message: 'Milestone not found.' });
    }

    const completedAt = status === 'Completed' ? new Date().toISOString() : null;

    await db.run(
      `UPDATE project_milestones 
       SET status = COALESCE(?, status),
           title = COALESCE(?, title),
           description = COALESCE(?, description),
           due_date = COALESCE(?, due_date),
           completed_at = ?
       WHERE id = ?`,
      [status, title, description, due_date, completedAt, milestoneId]
    );

    const project = await db.get('SELECT user_id, title FROM projects WHERE id = ?', [projectId]);
    if (project && status === 'Completed') {
      await createNotification({
        userId: project.user_id,
        title: 'Milestone Completed!',
        message: `Milestone "${milestone.title}" on project "${project.title}" has been marked as completed.`,
        type: 'project',
        link: '/dashboard#projects'
      });
    }

    return res.json({ success: true, message: 'Milestone updated successfully.' });
  } catch (error) {
    console.error('Update milestone error:', error);
    return res.status(500).json({ success: false, message: 'Server error updating milestone.' });
  }
});

// POST /api/projects/:id/updates (Admin post update)
router.post('/:id/updates', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const projectId = req.params.id;
    const { title, content } = req.body;

    if (!title || !content) {
      return res.status(400).json({ success: false, message: 'Update title and content are required.' });
    }

    const project = await db.get('SELECT p.user_id, p.title, u.email FROM projects p JOIN users u ON p.user_id = u.id WHERE p.id = ?', [projectId]);
    if (!project) {
      return res.status(404).json({ success: false, message: 'Project not found.' });
    }

    await db.run(
      `INSERT INTO project_updates (project_id, author_id, title, content)
       VALUES (?, ?, ?, ?)`,
      [projectId, req.user.id, title.trim(), content.trim()]
    );

    // Notify client
    await createNotification({
      userId: project.user_id,
      title: `Update on ${project.title}: ${title}`,
      message: content,
      type: 'project',
      link: '/dashboard#projects',
      sendAsEmail: true,
      emailTo: project.email
    });

    return res.status(201).json({ success: true, message: 'Project update posted.' });
  } catch (error) {
    console.error('Post update error:', error);
    return res.status(500).json({ success: false, message: 'Server error posting project update.' });
  }
});

module.exports = router;

