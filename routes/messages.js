const express = require('express');
const router = express.Router();
const db = require('../config/db');
const { authenticateToken } = require('../middleware/auth');
const { createNotification, notifyAdmins } = require('../services/notificationService');

// GET /api/messages/project/:projectId
router.get('/project/:projectId', authenticateToken, async (req, res) => {
  try {
    const projectId = req.params.projectId;
    const project = await db.get('SELECT user_id, title FROM projects WHERE id = ?', [projectId]);

    if (!project) {
      return res.status(404).json({ success: false, message: 'Project not found.' });
    }

    if (req.user.role !== 'admin' && project.user_id !== req.user.id) {
      return res.status(403).json({ success: false, message: 'Permission denied.' });
    }

    const messages = await db.all(
      `SELECT m.*, u.full_name as sender_name, u.role as sender_role 
       FROM messages m 
       JOIN users u ON m.sender_id = u.id 
       WHERE m.project_id = ? 
       ORDER BY m.created_at ASC`,
      [projectId]
    );

    return res.json({ success: true, messages });
  } catch (error) {
    console.error('Get messages error:', error);
    return res.status(500).json({ success: false, message: 'Server error retrieving messages.' });
  }
});

// POST /api/messages/project/:projectId
router.post('/project/:projectId', authenticateToken, async (req, res) => {
  try {
    const projectId = req.params.projectId;
    const { message } = req.body;

    if (!message || !message.trim()) {
      return res.status(400).json({ success: false, message: 'Message cannot be empty.' });
    }

    const project = await db.get('SELECT user_id, title FROM projects WHERE id = ?', [projectId]);
    if (!project) {
      return res.status(404).json({ success: false, message: 'Project not found.' });
    }

    if (req.user.role !== 'admin' && project.user_id !== req.user.id) {
      return res.status(403).json({ success: false, message: 'Permission denied.' });
    }

    let receiverId;
    if (req.user.role === 'admin') {
      receiverId = project.user_id;
    } else {
      const admin = await db.get("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
      receiverId = admin ? admin.id : 1;
    }

    const result = await db.run(
      `INSERT INTO messages (project_id, sender_id, receiver_id, message)
       VALUES (?, ?, ?, ?)`,
      [projectId, req.user.id, receiverId, message.trim()]
    );

    // Notify receiver
    if (req.user.role === 'admin') {
      await createNotification({
        userId: receiverId,
        title: `New Message on "${project.title}"`,
        message: `${req.user.full_name}: ${message.trim().substring(0, 100)}...`,
        type: 'project',
        link: `/dashboard#projects`
      });
    } else {
      await notifyAdmins({
        title: `Client Query: "${project.title}"`,
        message: `${req.user.full_name}: ${message.trim().substring(0, 100)}...`,
        type: 'project',
        link: `/admin#projects`
      });
    }

    return res.status(201).json({
      success: true,
      message: 'Message sent.',
      messageId: result.lastID
    });
  } catch (error) {
    console.error('Send message error:', error);
    return res.status(500).json({ success: false, message: 'Server error sending message.' });
  }
});

module.exports = router;

