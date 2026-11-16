const express = require('express');
const router = express.Router();
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
const crypto = require('crypto');
const db = require('../config/db');
const { authenticateToken, JWT_SECRET } = require('../middleware/auth');
const { logActivity } = require('../services/activityService');
const { notifyAdmins, createNotification } = require('../services/notificationService');
const { sendEmail } = require('../services/emailService');

// POST /api/auth/register
router.post('/register', async (req, res) => {
  try {
    const { email, password, full_name, phone, company_name } = req.body;

    if (!email || !password || !full_name) {
      return res.status(400).json({ success: false, message: 'Please provide full name, email, and password.' });
    }

    const cleanEmail = email.trim().toLowerCase();
    const existing = await db.get('SELECT id FROM users WHERE email = ?', [cleanEmail]);
    if (existing) {
      return res.status(400).json({ success: false, message: 'This email address is already registered.' });
    }

    const salt = bcrypt.genSaltSync(10);
    const passwordHash = bcrypt.hashSync(password, salt);

    const userResult = await db.run(
      `INSERT INTO users (role, email, password_hash, full_name, phone, status)
       VALUES ('client', ?, ?, ?, ?, 'active')`,
      [cleanEmail, passwordHash, full_name.trim(), phone ? phone.trim() : null]
    );

    const userId = userResult.lastID;

    // Create user profile
    await db.run(
      `INSERT INTO user_profiles (user_id, company_name, avatar_url)
       VALUES (?, ?, '/assets/img/avatar-default.png')`,
      [userId, company_name ? company_name.trim() : null]
    );

    // Activity log
    await logActivity({
      userId,
      action: 'Registered Account',
      entityType: 'user',
      entityId: userId,
      details: `New client registration for ${full_name} (${cleanEmail})`,
      ipAddress: req.ip
    });

    // Notify admins
    await notifyAdmins({
      title: 'New Client Registration',
      message: `${full_name} (${cleanEmail}) registered as a new client.`,
      type: 'system',
      link: '/admin#users'
    });

    // Welcome notification for user
    await createNotification({
      userId,
      title: 'Welcome to DevHorizan!',
      message: 'Your client portal account is ready. You can submit project requests, track milestones, and manage payments.',
      type: 'system',
      link: '/dashboard'
    });

    // Generate JWT
    const token = jwt.sign(
      { id: userId, email: cleanEmail, role: 'client' },
      JWT_SECRET,
      { expiresIn: '7d' }
    );

    res.cookie('devhorizan_token', token, {
      httpOnly: true,
      maxAge: 7 * 24 * 60 * 60 * 1000,
      sameSite: 'lax'
    });

    return res.status(201).json({
      success: true,
      message: 'Registration successful! Welcome to DevHorizan.',
      token,
      user: {
        id: userId,
        email: cleanEmail,
        full_name,
        role: 'client'
      }
    });
  } catch (error) {
    console.error('Register error:', error);
    return res.status(500).json({ success: false, message: 'Server error during registration.' });
  }
});

// POST /api/auth/login
router.post('/login', async (req, res) => {
  try {
    const { email, password } = req.body;

    if (!email || !password) {
      return res.status(400).json({ success: false, message: 'Please provide both email and password.' });
    }

    const cleanEmail = email.trim().toLowerCase();
    const user = await db.get(
      'SELECT id, role, email, password_hash, full_name, phone, status FROM users WHERE email = ?',
      [cleanEmail]
    );

    if (!user) {
      return res.status(401).json({ success: false, message: 'Invalid email or password.' });
    }

    if (user.status !== 'active') {
      return res.status(403).json({ success: false, message: 'Your account is currently inactive or suspended.' });
    }

    const isMatch = bcrypt.compareSync(password, user.password_hash);
    if (!isMatch) {
      return res.status(401).json({ success: false, message: 'Invalid email or password.' });
    }

    // Generate JWT
    const token = jwt.sign(
      { id: user.id, email: user.email, role: user.role },
      JWT_SECRET,
      { expiresIn: '7d' }
    );

    res.cookie('devhorizan_token', token, {
      httpOnly: true,
      maxAge: 7 * 24 * 60 * 60 * 1000,
      sameSite: 'lax'
    });

    await logActivity({
      userId: user.id,
      action: 'Logged In',
      entityType: 'auth',
      entityId: user.id,
      details: `User logged in from ${req.ip}`,
      ipAddress: req.ip
    });

    return res.json({
      success: true,
      message: 'Login successful!',
      token,
      user: {
        id: user.id,
        email: user.email,
        full_name: user.full_name,
        role: user.role,
        phone: user.phone
      }
    });
  } catch (error) {
    console.error('Login error:', error);
    return res.status(500).json({ success: false, message: 'Server error during login.' });
  }
});

// POST /api/auth/logout
router.post('/logout', (req, res) => {
  res.clearCookie('devhorizan_token');
  return res.json({ success: true, message: 'Logged out successfully.' });
});

// GET /api/auth/me
router.get('/me', authenticateToken, async (req, res) => {
  try {
    const profile = await db.get(
      'SELECT company_name, address, avatar_url, bio FROM user_profiles WHERE user_id = ?',
      [req.user.id]
    );

    const unreadCountRow = await db.get(
      'SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0',
      [req.user.id]
    );

    return res.json({
      success: true,
      user: {
        ...req.user,
        profile: profile || {},
        unreadNotifications: unreadCountRow ? unreadCountRow.unread : 0
      }
    });
  } catch (error) {
    console.error('Auth /me error:', error);
    return res.status(500).json({ success: false, message: 'Server error fetching user.' });
  }
});

// PUT /api/auth/profile
router.put('/profile', authenticateToken, async (req, res) => {
  try {
    const { full_name, phone, company_name, address, bio, avatar_url } = req.body;

    if (full_name) {
      await db.run('UPDATE users SET full_name = ?, phone = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [
        full_name.trim(),
        phone ? phone.trim() : null,
        req.user.id
      ]);
    }

    const existingProfile = await db.get('SELECT id FROM user_profiles WHERE user_id = ?', [req.user.id]);
    if (existingProfile) {
      await db.run(
        `UPDATE user_profiles 
         SET company_name = ?, address = ?, bio = ?, avatar_url = COALESCE(?, avatar_url), updated_at = CURRENT_TIMESTAMP 
         WHERE user_id = ?`,
        [company_name || null, address || null, bio || null, avatar_url || null, req.user.id]
      );
    } else {
      await db.run(
        `INSERT INTO user_profiles (user_id, company_name, address, bio, avatar_url)
         VALUES (?, ?, ?, ?, ?)`,
        [req.user.id, company_name || null, address || null, bio || null, avatar_url || '/assets/img/avatar-default.png']
      );
    }

    await logActivity({
      userId: req.user.id,
      action: 'Updated Profile',
      entityType: 'user',
      entityId: req.user.id,
      details: 'User updated personal profile information',
      ipAddress: req.ip
    });

    return res.json({ success: true, message: 'Profile updated successfully.' });
  } catch (error) {
    console.error('Update profile error:', error);
    return res.status(500).json({ success: false, message: 'Server error updating profile.' });
  }
});

// PUT /api/auth/change-password
router.put('/change-password', authenticateToken, async (req, res) => {
  try {
    const { current_password, new_password } = req.body;

    if (!current_password || !new_password) {
      return res.status(400).json({ success: false, message: 'Please provide both current and new password.' });
    }

    const user = await db.get('SELECT password_hash FROM users WHERE id = ?', [req.user.id]);
    const isMatch = bcrypt.compareSync(current_password, user.password_hash);
    if (!isMatch) {
      return res.status(400).json({ success: false, message: 'Current password is incorrect.' });
    }

    const salt = bcrypt.genSaltSync(10);
    const newHash = bcrypt.hashSync(new_password, salt);
    await db.run('UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [newHash, req.user.id]);

    await logActivity({
      userId: req.user.id,
      action: 'Changed Password',
      entityType: 'auth',
      entityId: req.user.id,
      details: 'User changed account password',
      ipAddress: req.ip
    });

    return res.json({ success: true, message: 'Password changed successfully.' });
  } catch (error) {
    console.error('Change password error:', error);
    return res.status(500).json({ success: false, message: 'Server error changing password.' });
  }
});

// POST /api/auth/forgot-password
router.post('/forgot-password', async (req, res) => {
  try {
    const { email } = req.body;
    if (!email) {
      return res.status(400).json({ success: false, message: 'Please provide an email address.' });
    }

    const cleanEmail = email.trim().toLowerCase();
    const user = await db.get('SELECT id, full_name FROM users WHERE email = ?', [cleanEmail]);

    if (!user) {
      // Don't reveal if user exists for security
      return res.json({
        success: true,
        message: 'If an account exists with this email, a reset link will be sent shortly.'
      });
    }

    const resetToken = crypto.randomBytes(32).toString('hex');
    const expiry = new Date(Date.now() + 3600000).toISOString(); // 1 hour

    await db.run('UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?', [
      resetToken,
      expiry,
      user.id
    ]);

    const resetLink = `http://localhost:3000/reset-password.html?token=${resetToken}&email=${encodeURIComponent(cleanEmail)}`;

    await sendEmail({
      to: cleanEmail,
      subject: '[DevHorizan] Password Reset Request',
      text: `Hello ${user.full_name},\n\nYou requested a password reset for your DevHorizan account.\n\nClick the link below to reset your password:\n${resetLink}\n\nThis link is valid for 1 hour.\n\nIf you did not request this, please ignore this email.`,
      html: `
        <div style="font-family: Arial, sans-serif; max-width: 550px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 10px; padding: 25px;">
          <h2 style="color: #0f3460; margin-top: 0;">Password Reset Request</h2>
          <p>Hello <strong>${user.full_name}</strong>,</p>
          <p>You recently requested to reset the password for your DevHorizan account. Click the button below to proceed:</p>
          <div style="margin: 25px 0;">
            <a href="${resetLink}" style="background: #2f6fed; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block;">Reset Password</a>
          </div>
          <p style="color: #64748b; font-size: 13px;">This link expires in 1 hour. If you did not initiate this request, you can safely ignore this email.</p>
        </div>
      `
    });

    return res.json({
      success: true,
      message: 'If an account exists with this email, a reset link has been dispatched.'
    });
  } catch (error) {
    console.error('Forgot password error:', error);
    return res.status(500).json({ success: false, message: 'Server error processing password reset.' });
  }
});

// POST /api/auth/reset-password
router.post('/reset-password', async (req, res) => {
  try {
    const { token, new_password } = req.body;

    if (!token || !new_password) {
      return res.status(400).json({ success: false, message: 'Invalid request. Token and new password required.' });
    }

    const user = await db.get(
      'SELECT id, reset_token_expiry FROM users WHERE reset_token = ?',
      [token]
    );

    if (!user) {
      return res.status(400).json({ success: false, message: 'Invalid or expired password reset link.' });
    }

    if (new Date() > new Date(user.reset_token_expiry)) {
      return res.status(400).json({ success: false, message: 'Password reset link has expired. Please request a new one.' });
    }

    const salt = bcrypt.genSaltSync(10);
    const newHash = bcrypt.hashSync(new_password, salt);

    await db.run(
      'UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
      [newHash, user.id]
    );

    await logActivity({
      userId: user.id,
      action: 'Reset Password',
      entityType: 'auth',
      entityId: user.id,
      details: 'Password was reset using reset token',
      ipAddress: req.ip
    });

    return res.json({ success: true, message: 'Your password has been reset successfully. You can now log in.' });
  } catch (error) {
    console.error('Reset password error:', error);
    return res.status(500).json({ success: false, message: 'Server error resetting password.' });
  }
});

module.exports = router;

