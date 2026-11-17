const bcrypt = require('bcryptjs');
const db = require('../config/db');
const initDb = require('./init');

async function seedDatabase() {
  await initDb();

  const userRow = await db.get('SELECT COUNT(*) as count FROM users');
  if (userRow && userRow.count > 0) {
    console.log('ℹ️ Database already contains data. Skipping seed.');
    return;
  }

  console.log('🌱 Seeding initial database data...');

  const saltRounds = 10;
  const adminPasswordHash = bcrypt.hashSync('Admin@12345', saltRounds);
  const clientPasswordHash = bcrypt.hashSync('Client@12345', saltRounds);

  // 1. Create Admin User
  const adminRes = await db.run(
    `INSERT INTO users (role, email, password_hash, full_name, phone, status)
     VALUES (?, ?, ?, ?, ?, ?)`,
    ['admin', 'admin@devhorizan.com', adminPasswordHash, 'DevHorizan Admin', '+91 98374 52608', 'active']
  );
  const adminId = adminRes.lastID;

  // 2. Create Client User
  const clientRes = await db.run(
    `INSERT INTO users (role, email, password_hash, full_name, phone, status)
     VALUES (?, ?, ?, ?, ?, ?)`,
    ['client', 'client@devhorizan.com', clientPasswordHash, 'Raghav Sharma', '+91 98765 43210', 'active']
  );
  const clientId = clientRes.lastID;

  // 3. User Profiles
  await db.run(
    `INSERT INTO user_profiles (user_id, company_name, address, bio)
     VALUES (?, ?, ?, ?)`,
    [adminId, 'DevHorizan Technologies', 'Shivalik Greens, Dehradun', 'Executive Administrator & Lead Architect']
  );
  await db.run(
    `INSERT INTO user_profiles (user_id, company_name, address, bio)
     VALUES (?, ?, ?, ?)`,
    [clientId, 'Apex Innovations Pvt Ltd', 'Connaught Place, New Delhi', 'Product Lead and Enterprise Client']
  );

  // 4. Sample Projects for Client
  const p1 = await db.run(
    `INSERT INTO projects (user_id, title, category, description, status, progress_percent, total_cost, paid_amount, remaining_balance, start_date, expected_delivery_date)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      clientId,
      'Enterprise SaaS Web Portal',
      'Web Development',
      'Complete multi-tenant corporate management and customer analytics portal built with modern responsive frontend and Node backend.',
      'In Progress',
      65,
      150000.00,
      75000.00,
      75000.00,
      '2026-01-15',
      '2026-03-30'
    ]
  );

  const p2 = await db.run(
    `INSERT INTO projects (user_id, title, category, description, status, progress_percent, total_cost, paid_amount, remaining_balance, start_date, expected_delivery_date)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      clientId,
      'DevHorizan Mobile Client App (iOS & Android)',
      'iOS & Android Development',
      'Native iOS and Android mobile application featuring push notifications, offline syncing, real-time tracking, and dashboard integration.',
      'Approved',
      20,
      95000.00,
      47500.00,
      47500.00,
      '2026-02-01',
      '2026-04-15'
    ]
  );

  const p3 = await db.run(
    `INSERT INTO projects (user_id, title, category, description, status, progress_percent, total_cost, paid_amount, remaining_balance, start_date, expected_delivery_date)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      clientId,
      'Brand Identity & UI/UX Design Kit',
      'UI/UX Design',
      'Complete Figma design tokens, user journey flows, responsive wireframes, design system guide, and high-fidelity prototypes.',
      'Completed',
      100,
      45000.00,
      45000.00,
      0.00,
      '2025-11-10',
      '2025-12-24'
    ]
  );

  // 5. Project Milestones
  await db.run(
    `INSERT INTO project_milestones (project_id, title, description, due_date, status, completed_at)
     VALUES (?, ?, ?, ?, ?, ?)`,
    [p1.lastID, 'Architecture & Database Setup', 'Design schema and setup backend endpoints', '2026-01-30', 'Completed', '2026-01-28']
  );
  await db.run(
    `INSERT INTO project_milestones (project_id, title, description, due_date, status, completed_at)
     VALUES (?, ?, ?, ?, ?, ?)`,
    [p1.lastID, 'Frontend Dashboard & Modules', 'Build client interface, charts, and table views', '2026-02-28', 'In Progress', null]
  );
  await db.run(
    `INSERT INTO project_milestones (project_id, title, description, due_date, status, completed_at)
     VALUES (?, ?, ?, ?, ?, ?)`,
    [p1.lastID, 'Security Audit & Final Deployment', 'End to end testing, penetration checks, cloud deployment', '2026-03-30', 'Pending', null]
  );

  // 6. Project Updates
  await db.run(
    `INSERT INTO project_updates (project_id, author_id, title, content)
     VALUES (?, ?, ?, ?)`,
    [p1.lastID, adminId, 'Sprint 2 Milestone Reached', 'Frontend dashboard cards and reporting widgets have been integrated and passed initial validation.']
  );
  await db.run(
    `INSERT INTO project_updates (project_id, author_id, title, content)
     VALUES (?, ?, ?, ?)`,
    [p1.lastID, adminId, 'Database Schema Upgraded', 'Optimized query response times and integrated foreign keys with indexing.']
  );

  // 7. Project Requests & Budget Revision Workflow Sample
  const req1 = await db.run(
    `INSERT INTO project_requests (request_code, user_id, project_name, project_type, description, required_features, expected_budget, expected_deadline, additional_requirements, status, admin_notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      'REQ-2026-1001',
      clientId,
      'E-Commerce Mobile Marketplace',
      'iOS & Android Development',
      'Cross-platform marketplace app supporting vendor onboarding, in-app checkout, live order tracking, and push notifications.',
      'Payment gateway, multi-vendor dashboard, geolocation tracking, review system',
      60000.00,
      '2026-05-15',
      'Needs high scalability and smooth performance on low-end devices.',
      'Revision Requested',
      'Proposed budget (₹60,000) is insufficient for multi-vendor and real-time tracking architecture. Suggested revision to ₹95,000 with phased delivery.'
    ]
  );

  // Insert Revision History for this request
  await db.run(
    `INSERT INTO project_revisions (request_id, revision_number, proposed_budget, updated_requirements, scope_changes, reason_for_revision, admin_comments, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      req1.lastID,
      1,
      95000.00,
      'Phase 1: Customer app + Stripe/Razorpay payment gateway + Admin backend.\nPhase 2: Multi-vendor catalog & live GPS order tracking.',
      'Staged multi-vendor release to keep launch on schedule while accommodating complex vendor management.',
      'The initial proposed budget of ₹60,000 does not cover real-time GPS tracking infrastructure and multi-vendor payout accounting.',
      'We recommend this revised scope to ensure high performance and compliance with payment standards.',
      'Pending User Response'
    ]
  );

  // Another pending request
  await db.run(
    `INSERT INTO project_requests (request_code, user_id, project_name, project_type, description, required_features, expected_budget, expected_deadline, additional_requirements, status, admin_notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      'REQ-2026-1002',
      clientId,
      'Custom AI Analytics Microservice',
      'Custom Software',
      'RESTful API microservice predicting client inventory replenishment utilizing machine learning models and webhook notifications.',
      'JWT auth, Docker containerization, webhook triggers, CSV reporting',
      40000.00,
      '2026-06-01',
      'Fast response times under 200ms.',
      'Submitted',
      null
    ]
  );

  // Salesforce Admin & Development request
  await db.run(
    `INSERT INTO project_requests (request_code, user_id, project_name, project_type, description, required_features, expected_budget, expected_deadline, additional_requirements, status, admin_notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      'REQ-2026-1005',
      clientId,
      'Enterprise Salesforce Sales Cloud & LWC Architecture',
      'Salesforce Admin & Development',
      'Complete Salesforce CRM rollout including Sales Cloud configuration, Custom Objects, Automated Flows, custom Apex Triggers, and responsive Lightning Web Components (LWC).',
      'Lead and Opportunity scoring, Record-Triggered Flows, custom LWC modules, role hierarchy & sharing rules, REST API sync with accounting',
      85000.00,
      '2026-05-15',
      'Require test coverage above 85% and governor limits optimization.',
      'Under Admin Review',
      'Assigned to Raghav Gupta (Salesforce Admin & Dev Lead)'
    ]
  );

  // 8. Sample Payments & Invoices
  await db.run(
    `INSERT INTO payments (invoice_number, project_id, user_id, total_amount, amount_paid, remaining_balance, advance_required, status, due_date, paid_date, payment_method, transaction_id, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      'INV-2026-001',
      p1.lastID,
      clientId,
      150000.00,
      75000.00,
      75000.00,
      75000.00,
      'Partially Paid',
      '2026-02-15',
      '2026-01-16 11:30:00',
      'UPI / Net Banking',
      'TXN_98234710293',
      'Advance payment of 50% received. Remaining 50% due at milestone 3.'
    ]
  );

  await db.run(
    `INSERT INTO payments (invoice_number, project_id, user_id, total_amount, amount_paid, remaining_balance, advance_required, status, due_date, paid_date, payment_method, transaction_id, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      'INV-2026-002',
      p3.lastID,
      clientId,
      45000.00,
      45000.00,
      0.00,
      22500.00,
      'Paid',
      '2025-12-20',
      '2025-12-18 16:45:00',
      'Bank Transfer',
      'TXN_77361528901',
      'Full payment received upon project completion and asset handoff.'
    ]
  );

  // 9. Notifications
  await db.run(
    `INSERT INTO notifications (user_id, title, message, type, link, is_read)
     VALUES (?, ?, ?, ?, ?, ?)`,
    [
      clientId,
      'Budget Revision Proposal Received',
      'Admin has proposed a revised budget of ₹95,000 for your request "E-Commerce Mobile Marketplace". Please review and respond.',
      'revision',
      '/dashboard#requests',
      0
    ]
  );
  await db.run(
    `INSERT INTO notifications (user_id, title, message, type, link, is_read)
     VALUES (?, ?, ?, ?, ?, ?)`,
    [
      clientId,
      'Payment Reminder: Milestone 2 Due Soon',
      'Your remaining balance of ₹75,000 for "Enterprise SaaS Web Portal" is scheduled for review.',
      'payment',
      '/dashboard#billing',
      0
    ]
  );
  await db.run(
    `INSERT INTO notifications (user_id, title, message, type, link, is_read)
     VALUES (?, ?, ?, ?, ?, ?)`,
    [
      clientId,
      'New Project Update Posted',
      'Admin posted an update on "Enterprise SaaS Web Portal": Sprint 2 Milestone Reached.',
      'project',
      '/dashboard#projects',
      1
    ]
  );

  // Admin notifications
  await db.run(
    `INSERT INTO notifications (user_id, title, message, type, link, is_read)
     VALUES (?, ?, ?, ?, ?, ?)`,
    [
      adminId,
      'New Project Request Submitted',
      'Raghav Sharma submitted a new project request "Custom AI Analytics Microservice".',
      'request',
      '/admin#projectRequests',
      0
    ]
  );
  await db.run(
    `INSERT INTO notifications (user_id, title, message, type, link, is_read)
     VALUES (?, ?, ?, ?, ?, ?)`,
    [
      adminId,
      'Payment Received: ₹75,000',
      'Partial payment confirmed for Enterprise SaaS Web Portal (INV-2026-001).',
      'payment',
      '/admin#billing',
      1
    ]
  );

  // 10. Activity Logs
  await db.run(
    `INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details)
     VALUES (?, ?, ?, ?, ?)`,
    [clientId, 'Submitted Project Request', 'request', req1.lastID, 'Submitted request REQ-2026-1001 for E-Commerce Mobile Marketplace.']
  );
  await db.run(
    `INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details)
     VALUES (?, ?, ?, ?, ?)`,
    [adminId, 'Sent Budget Revision Proposal', 'revision', 1, 'Sent counter-proposal with budget ₹95,000 and phased scope.']
  );
  await db.run(
    `INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details)
     VALUES (?, ?, ?, ?, ?)`,
    [adminId, 'Recorded Payment', 'payment', 1, 'Recorded partial payment of ₹75,000 under INV-2026-001.']
  );

  console.log('✅ Seed data successfully inserted!');
  console.log('👤 Admin: admin@devhorizan.com / Admin@12345');
  console.log('👤 Client: client@devhorizan.com / Client@12345');
}

if (require.main === module) {
  seedDatabase()
    .then(() => process.exit(0))
    .catch((err) => {
      console.error('❌ Failed to seed database:', err);
      process.exit(1);
    });
}

module.exports = seedDatabase;
