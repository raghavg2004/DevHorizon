# DevHorizan Shared Hosting (PHP + SQLite) Deployment Guide

This guide explains how to run and deploy the **PHP + SQLite backend** of the DevHorizan platform. With this architecture, **no Node.js, npm, or dedicated VPS is required**. You can host the entire system on inexpensive shared hosting (such as **Hostinger**, **cPanel**, **Namecheap**, **GoDaddy**, **Plesk**, or any Apache/Nginx server) for as low as $1 to $3 per month with zero server maintenance.

---

## 🌟 Key Advantages of PHP + SQLite Architecture

1. **Ultra-Low Cost**: Runs on standard shared hosting plans (cPanel / Hostinger Single or Premium plans).
2. **Zero Maintenance**: SQLite is a serverless, self-contained single-file database (`data/devhorizan.db`). No MySQL server to configure, maintain, or repair.
3. **100% Frontend Compatibility**: All existing HTML, CSS, and client/admin JavaScript functions (`fetch('/api/...')`) work without any alterations.
4. **Instant Startup**: No build steps, no `npm install`, and no daemon processes to restart.
5. **Strict Security**: Native `.htaccess` rules prevent direct web downloads of your SQLite database file and sensitive configs.

---

## 🚀 1. Local Testing & Development

You can run the PHP server locally without installing Node.js or Apache:

### Step 1: Start Built-in PHP Server
From the project root, run:
```powershell
php -c php.ini -S localhost:3000 router.php
```

### Step 2: Access the Application
- **Company Website**: `http://localhost:3000/`
- **Client Portal**: `http://localhost:3000/dashboard`
- **Executive Admin Console**: `http://localhost:3000/admin`
- **Invoices**: `http://localhost:3000/invoice`
- **API Health Check**: `http://localhost:3000/api/health`

### Step 3: Default Logins
- **Admin**: `admin@devhorizan.com` / `Admin@12345`
- **Client**: `client@devhorizan.com` / `Client@12345`

---

## 🌐 2. Deploying to cPanel Shared Hosting (Namecheap, GoDaddy, Bluehost, etc.)

### Step 1: Prepare Files
Compress the following directories and files into a `.zip` archive:
- `api/` (all PHP backend code)
- `public/` (all frontend HTML, CSS, JS, images)
- `data/` (contains `devhorizan.db` and security `.htaccess`)
- `uploads/` (for user attachments and receipts)
- `.htaccess` (production Apache URL rewrites)
- `php.ini` (optional custom PHP settings)

*(Note: You do NOT need to upload `node_modules/`, `server.js`, `routes/`, or `.git/`)*.

### Step 2: Upload via File Manager
1. Log in to your hosting **cPanel**.
2. Open **File Manager** and navigate to `public_html/` (or your subdomain folder).
3. Click **Upload** and upload your `.zip` archive.
4. Right-click the `.zip` file and select **Extract**.

### Step 3: Verify PHP Version & Extensions
1. In cPanel, look for **Select PHP Version** or **MultiPHP Manager**.
2. Choose **PHP 8.1, 8.2, 8.3, or 8.4**.
3. Under **Extensions**, ensure the following standard extensions are checked:
   - `pdo_sqlite`
   - `sqlite3`
   - `mbstring`
   - `openssl`
   *(These are enabled by default on 99% of shared hosts)*.

### Step 4: Verify Folder Permissions
Make sure the `data/` directory and `uploads/` directory have write permissions so SQLite can record transactions:
- `data/` directory: `755` (or `775`)
- `data/devhorizan.db`: `644` (or `664`)
- `uploads/`: `755` (or `775`)

---

## ⚡ 3. Deploying to Hostinger (hPanel)

1. Log in to **Hostinger hPanel**.
2. Go to **Websites** → **Manage** → **File Manager**.
3. Open `public_html`.
4. Upload the project files (`api/`, `public/`, `data/`, `uploads/`, `.htaccess`).
5. Go to **Advanced** → **PHP Configuration** and ensure **PHP 8.1+** is selected.
6. Open your domain in your browser!

---

## 🔒 4. Security & Data Protection

### Database Isolation
The SQLite database is kept safe by multiple layers of security:
1. `data/.htaccess` contains:
   ```apache
   <IfModule mod_authz_core.c>
       Require all denied
   </IfModule>
   <IfModule !mod_authz_core.c>
       Order deny,allow
       Deny from all
   </IfModule>
   ```
2. The root `.htaccess` explicitly blocks any requests matching `.db`, `.sqlite`, `.env`, `.ini`, or direct access to `data/`.
3. Browsers attempting to visit `https://your-domain.com/data/devhorizan.db` will receive a `403 Forbidden` response.

### Backing Up Your Database
To back up all clients, projects, reviews, and payment records, simply copy or download the single file:
`data/devhorizan.db`

---

## 🛠️ 5. Automated Verification Test Suite

To verify that all PHP endpoints and database operations are healthy, run:
```powershell
php -c php.ini scratch/test_suite.php
php -c php.ini scratch/test_http_dispatch.php
```
Both test suites will run comprehensive tests on authentication, projects, requests, payments, public reviews, and analytics.
