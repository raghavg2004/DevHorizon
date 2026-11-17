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
const reviewRoutes = require('./routes/reviews');

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

  // Static uploads
  app.use('/uploads', express.static(uploadsDir));

  // Mount API endpoints
  app.use('/api/auth', authRoutes);
  app.use('/api/projects', projectRoutes);
  app.use('/api/requests', requestRoutes);
  app.use('/api/payments', paymentRoutes);
  app.use('/api/users', userRoutes);
  app.use('/api/notifications', notificationRoutes);
  app.use('/api/messages', messageRoutes);
  app.use('/api/reports', reportRoutes);
  app.use('/api/reviews', reviewRoutes);

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
// Public Company Website, Client Project Portal, Client Invoicing
// -------------------------------------------------------------
const clientApp = express();
applyCommonMiddlewareAndRoutes(clientApp);

// Static assets & complete company website files
clientApp.use(express.static(publicDir));

// Client UI Page Routes
clientApp.get('/', (req, res) => res.sendFile(path.join(publicDir, 'index.html')));
clientApp.get('/login', (req, res) => res.sendFile(path.join(publicDir, 'login.html')));
clientApp.get('/register', (req, res) => res.sendFile(path.join(publicDir, 'login.html')));
clientApp.get('/forgot-password', (req, res) => res.sendFile(path.join(publicDir, 'forgot-password.html')));
clientApp.get('/reset-password', (req, res) => res.sendFile(path.join(publicDir, 'reset-password.html')));
clientApp.get('/dashboard', (req, res) => res.sendFile(path.join(publicDir, 'dashboard.html')));
clientApp.get('/invoice', (req, res) => res.sendFile(path.join(publicDir, 'invoice.html')));
clientApp.get('/invoice/:id', (req, res) => res.sendFile(path.join(publicDir, 'invoice.html')));
clientApp.get(['/ios-service.html', '/mobile-service.html', '/ios-android-service.html'], (req, res) => {
  res.sendFile(path.join(publicDir, 'android-service.html'));
});

// Admin Console Routing: Redirect to dedicated Admin Port 4000
clientApp.get(['/admin', '/admin-login', '/admin/login'], (req, res) => {
  if (process.env.NODE_ENV === 'production' || CLIENT_PORT == ADMIN_PORT || !process.env.ADMIN_PORT) {
    return res.sendFile(path.join(publicDir, 'admin.html'));
  }
  res.redirect(`http://${req.hostname}:${ADMIN_PORT}/`);
});

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
// Dedicated Admin Login & Executive Management Console ONLY
// (Strictly NO Company Website on Port 4000)
// -------------------------------------------------------------
const adminApp = express();
applyCommonMiddlewareAndRoutes(adminApp);

// Only serve necessary styling, scripts & logos for Admin interface
// (Does NOT serve publicDir as a whole so company website is NEVER exposed on Port 4000)
adminApp.use('/assets', express.static(path.join(publicDir, 'assets')));
adminApp.get(['/DH.png', '/Dh.png'], (req, res) => res.sendFile(path.join(publicDir, 'DH.png')));
adminApp.get(['/favicon.ico', '/logo.png'], (req, res) => res.sendFile(path.join(publicDir, 'assets/img/logo.png')));

// Redirect any company website pages from Port 4000 to Port 3000
const companyWebsitePages = [
  '/index.html', '/about.html', '/team.html', '/android-service.html',
  '/ios-service.html', '/mobile-service.html', '/ios-android-service.html',
  '/web-service.html', '/salesforce-service.html', '/uiux-service.html',
  '/ms-service.html', '/consultation-service.html',
  '/pp.html', '/tos.html', '/dashboard', '/register', '/forgot-password', '/reset-password'
];
adminApp.get(companyWebsitePages, (req, res) => {
  res.redirect(`http://${req.hostname}:${CLIENT_PORT}${req.originalUrl}`);
});

// Dedicated Admin Login Screen on Root & /login
adminApp.get('/', (req, res) => res.sendFile(path.join(publicDir, 'admin-login.html')));
adminApp.get('/login', (req, res) => res.sendFile(path.join(publicDir, 'admin-login.html')));
adminApp.get('/admin-login', (req, res) => res.sendFile(path.join(publicDir, 'admin-login.html')));

// Authenticated Executive Admin Console
adminApp.get('/admin', (req, res) => res.sendFile(path.join(publicDir, 'admin.html')));

// Invoices for admin viewing
adminApp.get('/invoice', (req, res) => res.sendFile(path.join(publicDir, 'invoice.html')));
adminApp.get('/invoice/:id', (req, res) => res.sendFile(path.join(publicDir, 'invoice.html')));

// API 404 handler
adminApp.use('/api', (req, res) => {
  res.status(404).json({ success: false, message: 'Admin API route not found' });
});

// Fallback on Port 4000: Always return Admin Login Screen (Never Company Website)
adminApp.use((req, res) => {
  res.sendFile(path.join(publicDir, 'admin-login.html'));
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
        console.log(`⚡ [ADMIN PORT 4000]  Running on: http://localhost:${ADMIN_PORT}`);
        console.log(`   - Admin Login Screen: http://localhost:${ADMIN_PORT}/`);
        console.log(`   - Admin Dashboard:    http://localhost:${ADMIN_PORT}/admin`);
        console.log(`   (Company website is restricted to Port 3000)`);
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
