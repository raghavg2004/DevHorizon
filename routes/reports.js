const express = require('express');
const router = express.Router();
const db = require('../config/db');
const { authenticateToken, requireAdmin } = require('../middleware/auth');

// GET /api/reports/dashboard
router.get('/dashboard', authenticateToken, async (req, res) => {
  try {
    if (req.user.role === 'admin') {
      // 1. Admin Metrics
      const totalUsers = (await db.get("SELECT COUNT(*) as count FROM users WHERE role = 'client'")).count;
      const totalProjects = (await db.get("SELECT COUNT(*) as count FROM projects")).count;
      const activeProjects = (await db.get("SELECT COUNT(*) as count FROM projects WHERE status IN ('In Progress', 'Approved', 'Under Review')")).count;
      const completedProjects = (await db.get("SELECT COUNT(*) as count FROM projects WHERE status = 'Completed'")).count;
      const pendingRequests = (await db.get("SELECT COUNT(*) as count FROM project_requests WHERE status IN ('Submitted', 'Under Admin Review', 'Revision Requested')")).count;
      const rejectedRequests = (await db.get("SELECT COUNT(*) as count FROM project_requests WHERE status = 'Rejected'")).count;

      const financialRow = await db.get(`
        SELECT COALESCE(SUM(total_amount), 0) as total_revenue,
               COALESCE(SUM(amount_paid), 0) as total_received,
               COALESCE(SUM(remaining_balance), 0) as total_outstanding
        FROM payments
      `);

      const recentActivities = await db.all(`
        SELECT al.*, u.full_name as user_name, u.email as user_email, u.role as user_role 
        FROM activity_logs al 
        LEFT JOIN users u ON al.user_id = u.id 
        ORDER BY al.created_at DESC 
        LIMIT 10
      `);

      // Category breakdown
      const categoryBreakdown = await db.all(`
        SELECT category, COUNT(*) as count, SUM(total_cost) as total_value 
        FROM projects 
        GROUP BY category
      `);

      // Status breakdown
      const statusBreakdown = await db.all(`
        SELECT status, COUNT(*) as count 
        FROM projects 
        GROUP BY status
      `);

      return res.json({
        success: true,
        stats: {
          totalUsers,
          totalProjects,
          activeProjects,
          completedProjects,
          pendingRequests,
          rejectedRequests,
          totalRevenue: financialRow.total_revenue,
          totalReceived: financialRow.total_received,
          totalOutstanding: financialRow.total_outstanding
        },
        recentActivities,
        categoryBreakdown,
        statusBreakdown
      });
    } else {
      // 2. Client Metrics
      const userId = req.user.id;
      const totalProjects = (await db.get('SELECT COUNT(*) as count FROM projects WHERE user_id = ?', [userId])).count;
      const activeProjects = (await db.get("SELECT COUNT(*) as count FROM projects WHERE user_id = ? AND status IN ('In Progress', 'Approved', 'Under Review')", [userId])).count;
      const completedProjects = (await db.get("SELECT COUNT(*) as count FROM projects WHERE user_id = ? AND status = 'Completed'", [userId])).count;
      const pendingRequests = (await db.get("SELECT COUNT(*) as count FROM project_requests WHERE user_id = ? AND status IN ('Submitted', 'Under Admin Review', 'Revision Requested')", [userId])).count;

      const financialRow = await db.get(`
        SELECT COALESCE(SUM(total_amount), 0) as total_cost,
               COALESCE(SUM(amount_paid), 0) as total_paid,
               COALESCE(SUM(remaining_balance), 0) as remaining_balance
        FROM payments 
        WHERE user_id = ?
      `, [userId]);

      const recentNotifications = await db.all(
        'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5',
        [userId]
      );

      return res.json({
        success: true,
        stats: {
          totalProjects,
          activeProjects,
          completedProjects,
          pendingRequests,
          totalCost: financialRow.total_cost,
          totalPaid: financialRow.total_paid,
          remainingBalance: financialRow.remaining_balance
        },
        recentNotifications
      });
    }
  } catch (error) {
    console.error('Dashboard stats error:', error);
    return res.status(500).json({ success: false, message: 'Server error retrieving analytics.' });
  }
});

// GET /api/reports/activity (Admin only: activity log with filters)
router.get('/activity', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const { entity_type, limit = 50 } = req.query;
    let sql = `
      SELECT al.*, u.full_name as user_name, u.email as user_email, u.role as user_role 
      FROM activity_logs al 
      LEFT JOIN users u ON al.user_id = u.id 
    `;
    const params = [];

    if (entity_type) {
      sql += ' WHERE al.entity_type = ?';
      params.push(entity_type);
    }

    sql += ' ORDER BY al.created_at DESC LIMIT ?';
    params.push(parseInt(limit, 10));

    const activities = await db.all(sql, params);
    return res.json({ success: true, activities });
  } catch (error) {
    console.error('Get activity error:', error);
    return res.status(500).json({ success: false, message: 'Server error retrieving activity logs.' });
  }
});

module.exports = router;

