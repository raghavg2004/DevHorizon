# DevHorizan Unified Enterprise Management Platform

A single, consolidated, full-stack enterprise web application unifying the **Public Company Website**, **Client Project Portal**, **Admin Command Center**, **Budget Negotiation Engine**, and **Invoicing & Notification System**.

---

## ⚡ Dual-Port Architecture: Simultaneous Client & Admin Testing

To allow testing both the **Client Portal** and **Admin Console** simultaneously in separate tabs of the same browser without cookie or session collisions, the platform runs on two dedicated ports started together with a single `npm start` command:

| Portal | Port & URL | Description & Access | Default Credentials |
| :--- | :--- | :--- | :--- |
| **🌐 Client & Public Website** | **[http://localhost:3000](http://localhost:3000)** | Public website, services, client dashboard, request submission, revision review, invoices & payments | `client@devhorizan.com` / `Client@12345` |
| **⚡ Admin Command Center** | **[http://localhost:4000](http://localhost:4000)** | Dedicated executive console, user management, project controls, budget negotiations, and billing | `admin@devhorizan.com` / `Admin@12345` |

Both ports communicate with the **same underlying SQLite database** (`data/devhorizan.db`) and real-time email/notification services.

---

## 🚀 Quick Start

### 1. Launch Platform
```bash
npm start
```
This automatically boots both servers:
- Client Portal & Website on **Port 3000**
- Admin Command Center on **Port 4000**

### 2. Run Test Suite
```bash
npm test
```
Executes the automated multi-port end-to-end integration test suite.

---

## 🧭 Platform URLs & Navigation

### Client Portal (Port 3000)
- **Home Page**: [http://localhost:3000/](http://localhost:3000/)
- **Client Login & Register**: [http://localhost:3000/login](http://localhost:3000/login)
- **Client Dashboard**: [http://localhost:3000/dashboard](http://localhost:3000/dashboard)
  - Overview KPI cards (projects, spend, paid, balance due)
  - My Projects with milestones and admin changelog updates
  - Project Request submission with unique `REQ-YYYY-XXXX` tracking
  - **Budget Negotiation Hub**: Review admin counter-proposals, accept (auto-approves & creates active project), decline, or counter-submit
  - Invoices, balance dues, online payment simulation
  - Project discussion / query messaging with admin
- **Interactive Invoices & PDF Export**: [http://localhost:3000/invoice](http://localhost:3000/invoice)

### Admin Console (Port 4000)
- **Admin Dashboard**: [http://localhost:4000/](http://localhost:4000/) or [http://localhost:4000/admin](http://localhost:4000/admin)
- **Admin Dedicated Login**: [http://localhost:4000/login](http://localhost:4000/login)
  - Executive financial & operational KPIs: Total contracted revenue, payments received, and outstanding dues
  - User management (search, filter, profile inspector, activate/deactivate, send custom notifications)
  - Project management (create, assign, edit, progress slider, milestone tracking, publish live updates to client)
  - Request review (direct approve, reject with reason, or submit budget/scope revision proposals)
  - Payment management (issue invoices, record partial/full payments, auto-calculated balances, 1-click reminders)
  - Immutable audit activity logs

---

## 🏗️ Architecture & Directory Structure

```
c:\Users\Raghav\Desktop\DevHorizan\
├── config/
│   └── db.js                 # SQLite database connection & Promise wrappers
├── db/
│   ├── schema.sql            # 13 relational entity definitions, foreign keys & indices
│   ├── init.js               # Database schema initialization
│   └── seed.js               # Automated demo data seeder
├── middleware/
│   ├── auth.js               # JWT & cookie verification, RBAC (requireAdmin, requireClient)
│   └── upload.js             # Multer file upload handler
├── routes/
│   ├── auth.js               # /api/auth (login, register, logout, profile, password reset)
│   ├── projects.js           # /api/projects (CRUD, milestones, updates, documents)
│   ├── requests.js           # /api/requests (submission, revision negotiation, approval)
│   ├── payments.js           # /api/payments (invoices, partial payments, email sender)
│   ├── users.js              # /api/users (user list, status toggle, direct notifications)
│   ├── notifications.js      # /api/notifications (in-app notifications, mark read)
│   ├── messages.js           # /api/messages (project-specific Q&A between client and admin)
│   └── reports.js            # /api/reports (KPI summaries, category breakdown, audit logs)
├── services/
│   ├── emailService.js       # Nodemailer Gmail transport (devhorizan@gmail.com)
│   ├── notificationService.js# In-app + email notification dispatcher
│   └── activityService.js    # Immutable activity audit logger
├── public/                   # Unified frontend client
│   ├── index.html            # Public website
│   ├── login.html            # Client Login & Register
│   ├── admin-login.html      # Dedicated Admin Console Login (Port 4000)
│   ├── dashboard.html        # Client SaaS Dashboard
│   ├── admin.html            # Admin SaaS Dashboard
│   ├── invoice.html          # Interactive Invoice & Receipt Portal
│   ├── assets/               # CSS, JS, Vendor libraries, Images
│   └── ... (service pages: android-service.html, web-service.html, etc.)
├── tests/
│   └── test_e2e.js           # Dual-port automated verification
├── server.js                 # Express dual-port server entry point
└── package.json
```
