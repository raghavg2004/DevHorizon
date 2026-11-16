const db = require('../config/db');

async function logActivity({ userId, action, entityType, entityId, details, ipAddress = null }) {
  try {
    await db.run(
      `INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, ip_address)
       VALUES (?, ?, ?, ?, ?, ?)`,
      [userId || null, action, entityType, entityId || null, details || null, ipAddress]
    );
  } catch (err) {
    console.error('Failed to record activity log:', err.message);
  }
}

module.exports = {
  logActivity
};

