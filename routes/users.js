const express = require('express');
const router = express.Router();
const db = require('../config/db');
const { authenticateToken, requireAdmin } = require('../middleware/auth');
const { logActivity } = require('../services/activityService');
const { createNotification } = require('../services/notificationService');

// GET /api/users (Admin only: list all registered users)
router.get('/', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const { search, role, status } = req.query;

    let query = `
      SELECT u.id, u.role, u.email, u.full_name, u.phone, u.status, u.created_at,
             up.company_name, up.avatar_url,
             (SELECT COUNT(*) FROM projects WHERE user_id = u.id) as project_count,
             (SELECT COALESCE(SUM(total_amount), 0) FROM payments WHERE user_id = u.id) as total_spent,
             (SELECT COALESCE(SUM(remaining_balance), 0) FROM payments WHERE user_id = u.id) as total_due
      FROM users u
      LEFT JOIN user_profiles up ON u.id = up.user_id
      WHERE 1=1
    `;
    const params = [];

    if (search) {
      query += ` AND (u.full_name LIKE ? OR u.email LIKE ? OR up.company_name LIKE ?)`;
      const s = `%${search}%`;
      params.push(s, s, s);
    }

    if (role) {
      query += ` AND u.role = ?`;
      params.push(role);
    }

    if (status) {
      query += ` AND u.status = ?`;
      params.push(status);
    }

    query += ` ORDER BY u.created_at DESC`;

    const users = await db.all(query, params);
    return res.json({ success: true, users });
  } catch (error) {
    console.error('Get users error:', error);
    return res.status(500).json({ success: false, message: 'Server error retrieving users.' });
  }
});

// GET /api/users/:id (Admin only: user profile and project history)
router.get('/:id', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const userId = req.params.id;
    const user = await db.get(
      `SELECT u.id, u.role, u.email, u.full_name, u.phone, u.status, u.created_at,
              up.company_name, up.address, up.avatar_url, up.bio 
       FROM users u 
       LEFT JOIN user_profiles up ON u.id = up.user_id 
       WHERE u.id = ?`,
      [userId]
    );

    if (!user) {
      return res.status(404).json({ success: false, message: 'User not found.' });
    }

    const projects = await db.all('SELECT * FROM projects WHERE user_id = ? ORDER BY created_at DESC', [userId]);
    const payments = await db.all('SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC', [userId]);
    const requests = await db.all('SELECT * FROM project_requests WHERE user_id = ? ORDER BY created_at DESC', [userId]);

    return res.json({
      success: true,
      user: {
        ...user,
        projects,
        payments,
        requests
      }
    });
  } catch (error) {
    console.error('Get user profile error:', error);
    return res.status(500).json({ success: false, message: 'Server error retrieving user profile.' });
  }
});

// PUT /api/users/:id/status (Admin toggle activate / deactivate)
router.put('/:id/status', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const userId = req.params.id;
    const { status } = req.body;

    if (!['active', 'inactive', 'suspended'].includes(status)) {
      return res.status(400).json({ success: false, message: 'Invalid status value.' });
    }

    // Do not allow deactivating self
    if (parseInt(userId, 10) === req.user.id) {
      return res.status(400).json({ success: false, message: 'You cannot deactivate your own admin account.' });
    }

    await db.run('UPDATE users SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [status, userId]);

    await logActivity({
      userId: req.user.id,
      action: 'Updated User Status',
      entityType: 'user',
      entityId: userId,
      details: `Admin set status of user #${userId} to ${status}`,
      ipAddress: req.ip
    });

    return res.json({ success: true, message: `User status updated to ${status}.` });
  } catch (error) {
    console.error('Update user status error:', error);
    return res.status(500).json({ success: false, message: 'Server error updating user status.' });
  }
});

// POST /api/users/:id/notify (Admin send direct notification)
router.post('/:id/notify', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const userId = req.params.id;
    const { title, message, send_email = false } = req.body;

    if (!title || !message) {
      return res.status(400).json({ success: false, message: 'Title and message are required.' });
    }

    const targetUser = await db.get('SELECT email, full_name FROM users WHERE id = ?', [userId]);
    if (!targetUser) {
      return res.status(404).json({ success: false, message: 'User not found.' });
    }

    await createNotification({
      userId,
      title: title.trim(),
      message: message.trim(),
      type: 'system',
      link: '/dashboard',
      sendAsEmail: Boolean(send_email),
      emailTo: targetUser.email
    });

    await logActivity({
      userId: req.user.id,
      action: 'Sent User Notification',
      entityType: 'user',
      entityId: userId,
      details: `Sent notification to ${targetUser.email}: "${title}"`,
      ipAddress: req.ip
    });

    return res.json({ success: true, message: 'Notification dispatched successfully.' });
  } catch (error) {
    console.error('Send user notification error:', error);
    return res.status(500).json({ success: false, message: 'Server error sending notification.' });
  }
});

module.exports = router;

