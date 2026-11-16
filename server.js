const express = require('express');
const cors = require('cors');
const cookieParser = require('cookie-parser');
const path = require('path');
const fs = require('fs');
try {
  require('dotenv').config();
} catch (_) {}

// Database & Seed
const initDb = require('./db/init');
const seedDatabase = require('./db/seed');

// Routes
const authRoutes = require('./routes/auth');
const projectRoutes = require('./routes/projects');
const requestRoutes = require('./routes/requests');
const paymentRoutes = require('./routes/payments');
const userRoutes = require('./routes/users');
const notificationRoutes = require('./routes/notifications');
const messageRoutes = require('./routes/messages');
const reportRoutes = require('./routes/reports');

const CLIENT_PORT = process.env.PORT || process.env.CLIENT_PORT || 3000;
const ADMIN_PORT = process.env.ADMIN_PORT || (process.env.PORT ? process.env.PORT : 4000);

const uploadsDir = process.env.UPLOADS_DIR || path.join(__dirname, 'uploads');
if (!fs.existsSync(uploadsDir)) {
  fs.mkdirSync(uploadsDir, { recursive: true });
}
const publicDir = path.join(__dirname, 'public');

// Helper to configure shared API routes on an app
function applyCommonMiddlewareAndRoutes(app) {
  app.use(cors({ origin: true, credentials: true }));
  app.use(express.json({ limit: '50mb' }));
  app.use(express.urlencoded({ extended: true, limit: '50mb' }));
  app.use(cookieParser());

  // Static uploads & public assets
  app.use('/uploads', express.static(uploadsDir));
  app.use(express.static(publicDir));

  // Mount API endpoints
  app.use('/api/auth', authRoutes);
  app.use('/api/projects', projectRoutes);
  app.use('/api/requests', requestRoutes);
  app.use('/api/payments', paymentRoutes);
  app.use('/api/users', userRoutes);
  app.use('/api/notifications', notificationRoutes);
  app.use('/api/messages', messageRoutes);
  app.use('/api/reports', reportRoutes);

  // Backward compatibility with Project 3 email server
  app.post('/send-invoice-email', (req, res, next) => {
    req.url = '/send-invoice-email';
    paymentRoutes(req, res, next);
  });

  // Health check
  app.get('/health', (req, res) => {
    res.json({
      status: 'online',
      app: 'DevHorizan Unified Platform',
      timestamp: new Date().toISOString()
    });
  });
}

// -------------------------------------------------------------
// 1. CLIENT APP (Port 3000)
// Public Website, Client Project Portal, Client Invoicing
// -------------------------------------------------------------
const clientApp = express();
applyCommonMiddlewareAndRoutes(clientApp);

// Client UI Page Routes
clientApp.get('/', (req, res) => res.sendFile(path.join(publicDir, 'index.html')));
clientApp.get('/login', (req, res) => res.sendFile(path.join(publicDir, 'login.html')));
clientApp.get('/register', (req, res) => res.sendFile(path.join(publicDir, 'login.html')));
clientApp.get('/forgot-password', (req, res) => res.sendFile(path.join(publicDir, 'forgot-password.html')));
clientApp.get('/reset-password', (req, res) => res.sendFile(path.join(publicDir, 'reset-password.html')));
clientApp.get('/dashboard', (req, res) => res.sendFile(path.join(publicDir, 'dashboard.html')));
clientApp.get('/invoice', (req, res) => res.sendFile(path.join(publicDir, 'invoice.html')));
clientApp.get('/invoice/:id', (req, res) => res.sendFile(path.join(publicDir, 'invoice.html')));

// Admin Console Routing (Single-Port Cloud Mode vs Local Dual-Port Mode)
clientApp.get('/admin', (req, res) => {
  if (process.env.NODE_ENV === 'production' || CLIENT_PORT == ADMIN_PORT || !process.env.ADMIN_PORT) {
    return res.sendFile(path.join(publicDir, 'admin.html'));
  }
  res.redirect(`http://${req.hostname}:${ADMIN_PORT}/admin`);
});
clientApp.get('/admin/login', (req, res) => res.sendFile(path.join(publicDir, 'admin-login.html')));
clientApp.get('/admin-login', (req, res) => res.sendFile(path.join(publicDir, 'admin-login.html')));

// API 404 handler
clientApp.use('/api', (req, res) => {
  res.status(404).json({ success: false, message: 'API route not found' });
});

// Fallback to website home
clientApp.use((req, res) => {
  res.sendFile(path.join(publicDir, 'index.html'));
});

// -------------------------------------------------------------
// 2. ADMIN APP (Port 4000)
// Dedicated Executive Command Center
// -------------------------------------------------------------
const adminApp = express();
applyCommonMiddlewareAndRoutes(adminApp);

// Admin UI Page Routes
adminApp.get('/', (req, res) => res.sendFile(path.join(publicDir, 'admin.html')));
adminApp.get('/admin', (req, res) => res.sendFile(path.join(publicDir, 'admin.html')));
adminApp.get('/login', (req, res) => res.sendFile(path.join(publicDir, 'admin-login.html')));
adminApp.get('/admin-login', (req, res) => res.sendFile(path.join(publicDir, 'admin-login.html')));
adminApp.get('/invoice', (req, res) => res.sendFile(path.join(publicDir, 'invoice.html')));
adminApp.get('/invoice/:id', (req, res) => res.sendFile(path.join(publicDir, 'invoice.html')));

// Redirect to Client Portal on Port 3000
adminApp.get('/dashboard', (req, res) => {
  if (process.env.NODE_ENV === 'production' || CLIENT_PORT == ADMIN_PORT || !process.env.CLIENT_PORT) {
    return res.sendFile(path.join(publicDir, 'dashboard.html'));
  }
  res.redirect(`http://${req.hostname}:${CLIENT_PORT}/dashboard`);
});

// API 404 handler
adminApp.use('/api', (req, res) => {
  res.status(404).json({ success: false, message: 'Admin API route not found' });
});

// Fallback to admin console
adminApp.use((req, res) => {
  res.sendFile(path.join(publicDir, 'admin.html'));
});

// -------------------------------------------------------------
// Launch Dual Servers on Separate Ports or Single Port on Cloud
// -------------------------------------------------------------
async function startServer() {
  try {
    await initDb();
    await seedDatabase();

    const isProduction = process.env.NODE_ENV === 'production';
    const isSinglePort = (CLIENT_PORT == ADMIN_PORT) || (isProduction && !process.env.ADMIN_PORT);

    if (isSinglePort) {
      // Production Single-Port Cloud Deployment (Render / Railway / Cloud VPS)
      clientApp.listen(CLIENT_PORT, () => {
        console.log(`================================================================`);
        console.log(`🚀 [DEVHORIZAN CLOUD PRODUCTION] Running on port: ${CLIENT_PORT}`);
        console.log(`   - Public Website:    /`);
        console.log(`   - Client Dashboard:  /dashboard`);
        console.log(`   - Invoices:          /invoice`);
        console.log(`   - Admin Console:     /admin`);
        console.log(`   - Admin Login:       /admin-login`);
        console.log(`================================================================`);
      });
    } else {
      // Local Development Dual-Port Mode
      clientApp.listen(CLIENT_PORT, () => {
        console.log(`================================================================`);
        console.log(`🌐 [CLIENT & WEBSITE] Running on: http://localhost:${CLIENT_PORT}`);
        console.log(`   - Public Website:    http://localhost:${CLIENT_PORT}/`);
        console.log(`   - Client Login:      http://localhost:${CLIENT_PORT}/login`);
        console.log(`   - Client Dashboard:  http://localhost:${CLIENT_PORT}/dashboard`);
        console.log(`   - Invoices:          http://localhost:${CLIENT_PORT}/invoice`);
      });

      adminApp.listen(ADMIN_PORT, () => {
        console.log(`----------------------------------------------------------------`);
        console.log(`⚡ [ADMIN CONSOLE]    Running on: http://localhost:${ADMIN_PORT}`);
        console.log(`   - Admin Console:     http://localhost:${ADMIN_PORT}/`);
        console.log(`   - Admin Login:       http://localhost:${ADMIN_PORT}/login`);
        console.log(`================================================================`);
        console.log(`✨ Both Client and Admin can now be opened simultaneously`);
        console.log(`   in separate browser tabs with zero session or cookie collisions!`);
        console.log(`================================================================`);
      });
    }
  } catch (err) {
    console.error('❌ Failed to start unified servers:', err);
    process.exit(1);
  }
}

if (require.main === module) {
  startServer();
}

module.exports = { clientApp, adminApp, startServer, CLIENT_PORT, ADMIN_PORT };
