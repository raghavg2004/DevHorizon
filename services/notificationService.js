const db = require('../config/db');
const { sendEmail } = require('./emailService');

async function createNotification({ userId, title, message, type = 'system', link = null, sendAsEmail = false, emailTo = null }) {
  try {
    const res = await db.run(
      `INSERT INTO notifications (user_id, title, message, type, link, is_read)
       VALUES (?, ?, ?, ?, ?, 0)`,
      [userId, title, message, type, link]
    );

    if (sendAsEmail && emailTo) {
      await sendEmail({
        to: emailTo,
        subject: `[DevHorizan] ${title}`,
        text: `${title}\n\n${message}\n\nView details: http://localhost:3000${link || ''}`,
        html: `
          <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;">
            <div style="background: linear-gradient(135deg, #0f3460, #388da8); color: white; padding: 24px; text-align: center;">
              <h2 style="margin: 0; font-size: 24px;">DevHorizan Notification</h2>
            </div>
            <div style="padding: 24px; background: #ffffff;">
              <h3 style="color: #1e293b; margin-top: 0;">${title}</h3>
              <p style="color: #475569; font-size: 15px; line-height: 1.6;">${message}</p>
              ${link ? `<div style="margin-top: 24px;"><a href="http://localhost:3000${link}" style="background: #2f6fed; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block;">View in Dashboard</a></div>` : ''}
            </div>
            <div style="background: #f8fafc; padding: 16px; text-align: center; color: #94a3b8; font-size: 12px; border-top: 1px solid #e2e8f0;">
              DevHorizan Technologies • Mussoorie Rd, Dehradun, Uttarakhand
            </div>
          </div>
        `
      });
    }

    return res.lastID;
  } catch (err) {
    console.error('Failed to create notification:', err.message);
  }
}

async function notifyAdmins({ title, message, type = 'system', link = null }) {
  try {
    const admins = await db.all("SELECT id, email FROM users WHERE role = 'admin' AND status = 'active'");
    for (const admin of admins) {
      await createNotification({
        userId: admin.id,
        title,
        message,
        type,
        link,
        sendAsEmail: false,
        emailTo: admin.email
      });
    }
  } catch (err) {
    console.error('Failed to notify admins:', err.message);
  }
}

module.exports = {
  createNotification,
  notifyAdmins
};

