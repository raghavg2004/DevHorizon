const express = require('express');
const router = express.Router();
const db = require('../config/db');
const { authenticateToken, requireAdmin } = require('../middleware/auth');
const { logActivity } = require('../services/activityService');
const { createNotification } = require('../services/notificationService');

// 1. GET /api/reviews/public
// Returns all approved reviews for the public landing page
router.get('/public', async (req, res) => {
  try {
    const reviews = await db.all(`
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
    `);

    res.json({
      success: true,
      count: reviews.length,
      reviews
    });
  } catch (err) {
    console.error('Error fetching public reviews:', err);
    res.status(500).json({ success: false, message: 'Failed to load verified client reviews.' });
  }
});

// 2. GET /api/reviews/my
// Returns all reviews submitted by the logged-in client
router.get('/my', authenticateToken, async (req, res) => {
  try {
    const reviews = await db.all(`
      SELECT * FROM client_reviews 
      WHERE user_id = ? 
      ORDER BY created_at DESC
    `, [req.user.id]);

    res.json({ success: true, reviews });
  } catch (err) {
    console.error('Error fetching client reviews:', err);
    res.status(500).json({ success: false, message: 'Failed to load your reviews.' });
  }
});

// 3. POST /api/reviews
// Client submits a new verified review
router.post('/', authenticateToken, async (req, res) => {
  try {
    const {
      project_id,
      rating,
      review_text,
      company_or_role,
      project_title,
      service_category
    } = req.body;

    if (!rating || rating < 1 || rating > 5) {
      return res.status(400).json({ success: false, message: 'Rating must be between 1 and 5 stars.' });
    }

    if (!review_text || review_text.trim().length < 10) {
      return res.status(400).json({ success: false, message: 'Review feedback must be at least 10 characters long.' });
    }

    const clientUser = await db.get('SELECT id, full_name, email FROM users WHERE id = ?', [req.user.id]);
    const clientName = clientUser ? clientUser.full_name : req.user.full_name || 'Verified Client';

    // Verify project if project_id provided
    let finalProjectTitle = project_title || null;
    let finalCategory = service_category || null;

    if (project_id) {
      const proj = await db.get('SELECT id, title, category, status FROM projects WHERE id = ? AND user_id = ?', [project_id, req.user.id]);
      if (proj) {
        finalProjectTitle = proj.title;
        finalCategory = proj.category;
      }
    }

    const result = await db.run(`
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
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
    `, [
      project_id || null,
      req.user.id,
      clientName,
      company_or_role ? company_or_role.trim() : 'Client Partner',
      finalProjectTitle,
      finalCategory || 'General Project',
      parseInt(rating, 10),
      review_text.trim()
    ]);

    const reviewId = result.lastID;

    // Log Activity & Notify Admins
    await logActivity({
      userId: req.user.id,
      action: 'REVIEW_SUBMITTED',
      entityType: 'review',
      entityId: reviewId,
      details: `${clientName} submitted a ${rating}-star verified review.`,
      ip: req.ip
    });

    const admins = await db.all("SELECT id FROM users WHERE role = 'admin'");
    for (const admin of admins) {
      await createNotification({
        userId: admin.id,
        title: '🌟 New Client Review Submitted',
        message: `${clientName} submitted a ${rating}-star review for approval.`,
        type: 'system',
        link: '/admin#reviews'
      });
    }

    res.status(201).json({
      success: true,
      message: 'Thank you! Your verified review has been submitted for admin approval.',
      reviewId
    });
  } catch (err) {
    console.error('Error submitting client review:', err);
    res.status(500).json({ success: false, message: 'Failed to submit review.' });
  }
});

// 4. GET /api/reviews/admin
// Admin views all reviews (Pending, Approved, Rejected)
router.get('/admin', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const reviews = await db.all(`
      SELECT 
        r.*,
        u.email as client_email,
        u.full_name as client_account_name,
        p.title as verified_project_name
      FROM client_reviews r
      JOIN users u ON r.user_id = u.id
      LEFT JOIN projects p ON r.project_id = p.id
      ORDER BY r.created_at DESC
    `);

    const stats = {
      total: reviews.length,
      pending: reviews.filter(r => r.status === 'Pending').length,
      approved: reviews.filter(r => r.status === 'Approved').length,
      rejected: reviews.filter(r => r.status === 'Rejected').length
    };

    res.json({
      success: true,
      stats,
      reviews
    });
  } catch (err) {
    console.error('Error fetching admin reviews:', err);
    res.status(500).json({ success: false, message: 'Failed to load reviews list.' });
  }
});

// 5. PATCH /api/reviews/:id/status
// Admin approves or rejects a review
router.patch('/:id/status', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const reviewId = req.params.id;
    const { status, admin_feedback } = req.body;

    if (!['Approved', 'Rejected', 'Pending'].includes(status)) {
      return res.status(400).json({ success: false, message: 'Invalid review status. Must be Approved, Rejected, or Pending.' });
    }

    const review = await db.get('SELECT * FROM client_reviews WHERE id = ?', [reviewId]);
    if (!review) {
      return res.status(404).json({ success: false, message: 'Review not found.' });
    }

    await db.run(`
      UPDATE client_reviews 
      SET status = ?, admin_feedback = ?, updated_at = CURRENT_TIMESTAMP 
      WHERE id = ?
    `, [status, admin_feedback || null, reviewId]);

    // Notify the client
    const statusMsg = status === 'Approved'
      ? 'Your verified review has been approved and published to the DevHorizan website! Thank you for your feedback.'
      : `Your submitted review was marked as ${status}.`;

    await createNotification({
      userId: review.user_id,
      title: status === 'Approved' ? '🎉 Review Published Live' : 'Notice: Review Status Updated',
      message: statusMsg,
      type: 'system',
      link: '/dashboard'
    });

    await logActivity({
      userId: req.user.id,
      action: `REVIEW_${status.toUpperCase()}`,
      entityType: 'review',
      entityId: reviewId,
      details: `Admin ${status.toLowerCase()} review #${reviewId} from ${review.client_name}`,
      ip: req.ip
    });

    res.json({
      success: true,
      message: `Review #${reviewId} has been ${status.toLowerCase()} successfully.`,
      status
    });
  } catch (err) {
    console.error('Error updating review status:', err);
    res.status(500).json({ success: false, message: 'Failed to update review status.' });
  }
});

// 6. DELETE /api/reviews/:id
// Admin deletes a review
router.delete('/:id', authenticateToken, requireAdmin, async (req, res) => {
  try {
    const reviewId = req.params.id;
    const review = await db.get('SELECT * FROM client_reviews WHERE id = ?', [reviewId]);
    if (!review) {
      return res.status(404).json({ success: false, message: 'Review not found.' });
    }

    await db.run('DELETE FROM client_reviews WHERE id = ?', [reviewId]);

    await logActivity({
      userId: req.user.id,
      action: 'REVIEW_DELETED',
      entityType: 'review',
      entityId: reviewId,
      details: `Admin deleted review #${reviewId} from ${review.client_name}`,
      ip: req.ip
    });

    res.json({ success: true, message: `Review #${reviewId} deleted successfully.` });
  } catch (err) {
    console.error('Error deleting review:', err);
    res.status(500).json({ success: false, message: 'Failed to delete review.' });
  }
});

module.exports = router;
