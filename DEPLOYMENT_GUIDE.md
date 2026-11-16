# DevHorizan Unified Platform - Cloud Production Deployment Guide

This guide walks you through deploying the unified DevHorizan platform to **Render.com** (or **Railway.app** / **VPS**) with **persistent disk storage** so your SQLite database (`devhorizan.db`) and uploaded files are 100% permanent.

---

## Architecture in Production

- **Single Production Port**: Cloud hosts provide a single public URL (e.g., `https://devhorizan.onrender.com` or your custom domain `https://devhorizan.com`).
- **All-in-One Routing**:
  - `https://your-domain.com/` → Public Company Website
  - `https://your-domain.com/login` → Client Portal Login
  - `https://your-domain.com/dashboard` → Client Project & Negotiation Dashboard
  - `https://your-domain.com/invoice` → Invoicing & Payment System
  - `https://your-domain.com/admin` → Admin Command Center & Portfolio
  - `https://your-domain.com/admin-login` → Admin Authentication
- **Local Dev Remains Dual-Port**: When run locally (`npm start` without `PORT`), the server automatically runs in dual-port mode (Client on `3000`, Admin on `4000`).

---

## Method 1: Deploy on Render.com (Recommended)

### Step 1: Push Project to GitHub
1. Create a repository on GitHub (e.g. `devhorizan-platform`).
2. In your local terminal:
   ```bash
   git init
   git add .
   git commit -m "Initial commit of unified DevHorizan platform"
   git branch -M main
   git remote add origin https://github.com/<your-username>/devhorizan-platform.git
   git push -u origin main
   ```

### Step 2: Create Web Service on Render
1. Go to [Render Dashboard](https://dashboard.render.com/) and click **New +** → **Web Service**.
2. Connect your GitHub repository.
3. Configure the settings:
   - **Name**: `devhorizan-platform`
   - **Runtime**: `Node`
   - **Build Command**: `npm install`
   - **Start Command**: `npm start`
   - **Instance Type**: 
     - *Free tier*: Great for testing. (Note: Render puts free services to sleep after 15 mins of inactivity and resets disks on free redeployments).
     - *Starter tier ($7/mo)*: Allows attaching a **Persistent Disk** so your SQLite database and attachments never reset.

### Step 3: Attach Persistent Disk (For permanent SQLite data)
1. In your Render Web Service settings, scroll down to **Disks** and click **Add Disk**.
2. Set:
   - **Name**: `devhorizan-storage`
   - **Mount Path**: `/var/data`
   - **Size**: `1 GB` (or more as needed)

### Step 4: Set Environment Variables
In the **Environment** tab on Render, add:
- `NODE_ENV` = `production`
- `JWT_SECRET` = (Click Generate or provide a secure 64-char string)
- `DATA_DIR` = `/var/data`
- `UPLOADS_DIR` = `/var/data/uploads`
- `EMAIL_USER` = `devhorizan@gmail.com`
- `EMAIL_APP_PASSWORD` = `vffjemqekyefcpdn`

Click **Deploy**! Render will build and deploy your app.

---

## Method 2: Deploy on Railway.app

1. Go to [Railway.app](https://railway.app/) and click **New Project** → **Deploy from GitHub repo**.
2. Select your `devhorizan-platform` repository.
3. Add a **Volume (Persistent Disk)**:
   - Click your service → **Settings** → **Volumes** → **Add Volume**.
   - Set **Mount Path**: `/app/data`.
4. Set Environment Variables:
   - `NODE_ENV` = `production`
   - `JWT_SECRET` = (secure random string)
   - `EMAIL_USER` = `devhorizan@gmail.com`
   - `EMAIL_APP_PASSWORD` = `vffjemqekyefcpdn`
5. Click **Generate Domain** under Settings to get your public `https://....up.railway.app` URL.

---

## Method 3: Deploy on a VPS (DigitalOcean / AWS / Hetzner) using Docker

If you have a Linux VPS:

1. Clone your repo:
   ```bash
   git clone https://github.com/<your-username>/devhorizan-platform.git
   cd devhorizan-platform
   ```
2. Build and run using the included `Dockerfile`:
   ```bash
   docker build -t devhorizan .
   docker run -d --name devhorizan-app \
     -p 80:3000 \
     -v $(pwd)/data:/app/data \
     -v $(pwd)/uploads:/app/uploads \
     --env-file .env \
     --restart unless-stopped \
     devhorizan
   ```
Your app will be live on `http://<your-server-ip>/` with persistent local disk storage.

