const fs = require('fs');
const path = require('path');
const db = require('../config/db');

async function initDb() {
  const schemaPath = path.join(__dirname, 'schema.sql');
  const schemaSql = fs.readFileSync(schemaPath, 'utf8');
  await db.exec(schemaSql);

  // Ensure pending_review_amount column exists on payments
  try {
    const cols = await db.all("PRAGMA table_info(payments)");
    const hasReviewCol = cols.some(c => c.name === 'pending_review_amount');
    if (!hasReviewCol) {
      await db.run("ALTER TABLE payments ADD COLUMN pending_review_amount REAL DEFAULT 0");
    }
  } catch (e) {
    console.warn("Table migration check notice:", e.message);
  }

  // Ensure client_reviews table exists and seed initial verified reviews if empty
  try {
    await db.exec(`
      CREATE TABLE IF NOT EXISTS client_reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER REFERENCES projects(id) ON DELETE SET NULL,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        client_name TEXT NOT NULL,
        company_or_role TEXT,
        project_title TEXT,
        service_category TEXT,
        rating INTEGER NOT NULL DEFAULT 5 CHECK(rating BETWEEN 1 AND 5),
        review_text TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'Pending' CHECK(status IN ('Pending', 'Approved', 'Rejected')),
        admin_feedback TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
      );
    `);

    const reviewCount = await db.get("SELECT COUNT(*) as cnt FROM client_reviews");
    if (!reviewCount || reviewCount.cnt === 0) {
      const clientUser = await db.get("SELECT id, full_name FROM users WHERE role = 'client' LIMIT 1");
      const clientUserId = clientUser ? clientUser.id : 2;

      await db.run(`
        INSERT INTO client_reviews (user_id, client_name, company_or_role, project_title, service_category, rating, review_text, status)
        VALUES 
        (?, 'Raghav Sharma', 'Product Lead, Apex Innovations', 'Salesforce Sales Cloud & LWC Architecture', 'Salesforce Admin & Development', 5, 'DevHorizan transformed our lead qualification pipeline. Raghav and his team designed custom Apex triggers and modern Lightning Web Components that cut our processing time by 60%. Flawless execution!', 'Approved'),
        (?, 'Priya Nair', 'Founder & CEO, FinSync India', 'Native iOS & Android Mobile FinTech Application', 'iOS & Android Development', 5, 'The mobile app engineered by DevHorizan is blazing fast, handles secure UPI payments seamlessly, and has received phenomenal feedback on both the Apple App Store and Google Play Store. Exceptional communication and on-time delivery.', 'Approved'),
        (?, 'Vikramaditya Mehra', 'Operations Director, Omnichannel Retail', 'Corporate Web Portal & E-Commerce Suite', 'Web Development', 5, 'DevHorizan built a responsive, high-converting web platform for our retail network. Their post-launch maintenance, quick response time, and technical architecture gave us 99.9% uptime. Truly outstanding partner!', 'Approved')
      `, [clientUserId, clientUserId, clientUserId]);
      console.log('🌟 Seeded initial verified client reviews.');
    }
  } catch (e) {
    console.warn("Client reviews initialization notice:", e.message);
  }

  console.log('✅ Database schema initialized successfully.');
}

if (require.main === module) {
  initDb()
    .then(() => process.exit(0))
    .catch((err) => {
      console.error('❌ Failed to initialize database:', err);
      process.exit(1);
    });
}

module.exports = initDb;
