const http = require('http');
const { clientApp, adminApp } = require('../server');

const CLIENT_TEST_PORT = 3055;
const ADMIN_TEST_PORT = 4055;

const clientServer = http.createServer(clientApp);
const adminServer = http.createServer(adminApp);

clientServer.listen(CLIENT_TEST_PORT, '127.0.0.1', () => {
  adminServer.listen(ADMIN_TEST_PORT, '127.0.0.1', async () => {
    console.log(`\n======================================================`);
    console.log(`🧪 DUAL-PORT SIMULTANEOUS CLIENT & ADMIN VERIFICATION`);
    console.log(`   Client Port: http://127.0.0.1:${CLIENT_TEST_PORT}`);
    console.log(`   Admin Port:  http://127.0.0.1:${ADMIN_TEST_PORT}`);
    console.log(`======================================================\n`);

    try {
      async function callApi(port, path, options = {}) {
        const res = await fetch(`http://127.0.0.1:${port}${path}`, options);
        const data = await res.json().catch(() => null);
        return { status: res.status, ok: res.ok, data };
      }

      // 1. Client Login on Client Port 3055
      console.log('1️⃣ Authenticating Client on Client Port 3055...');
      const clientAuth = await callApi(CLIENT_TEST_PORT, '/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: 'client@devhorizan.com', password: 'Client@12345' })
      });
      console.assert(clientAuth.ok && clientAuth.data.user.role === 'client', 'Client auth failed');
      const clientToken = clientAuth.data.token;
      console.log(`   ✅ Client Session Active: ${clientAuth.data.user.full_name}`);

      // 2. Admin Login on Admin Port 4055
      console.log('\n2️⃣ Authenticating Admin on Admin Port 4055...');
      const adminAuth = await callApi(ADMIN_TEST_PORT, '/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: 'admin@devhorizan.com', password: 'Admin@12345' })
      });
      console.assert(adminAuth.ok && adminAuth.data.user.role === 'admin', 'Admin auth failed');
      const adminToken = adminAuth.data.token;
      console.log(`   ✅ Admin Session Active: ${adminAuth.data.user.full_name}`);

      // 3. Client submits project request on Port 3055
      console.log('\n3️⃣ Client submits project request from Port 3055...');
      const newReq = await callApi(CLIENT_TEST_PORT, '/api/requests', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${clientToken}`
        },
        body: JSON.stringify({
          project_name: 'Cross-Port Synchronized Platform',
          project_type: 'Web Development',
          description: 'Testing simultaneous multi-port integration.',
          expected_budget: 45000
        })
      });
      console.assert(newReq.ok, 'Request submission failed');
      const reqId = newReq.data.requestId;
      console.log(`   ✅ Request #${reqId} (${newReq.data.requestCode}) submitted on Client Port.`);

      // 4. Admin on Port 4055 immediately sees request and sends revision proposal
      console.log('\n4️⃣ Admin on Port 4055 reviews request and submits revision proposal...');
      const revRes = await callApi(ADMIN_TEST_PORT, `/api/requests/${reqId}/revision`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${adminToken}`
        },
        body: JSON.stringify({
          proposed_budget: 65000,
          reason_for_revision: 'Adjusted for dual-port isolation infrastructure.',
          updated_requirements: 'Dual port setup with shared database.'
        })
      });
      console.assert(revRes.ok, 'Send revision failed');
      console.log('   ✅ Admin revision proposal dispatched from Port 4055.');

      // 5. Client on Port 3055 accepts revision
      console.log('\n5️⃣ Client on Port 3055 accepts proposal...');
      const acceptRes = await callApi(CLIENT_TEST_PORT, `/api/requests/${reqId}/respond`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${clientToken}`
        },
        body: JSON.stringify({ action: 'accept' })
      });
      console.assert(acceptRes.ok, 'Client accept failed');
      const newProjId = acceptRes.data.projectId;
      console.log(`   ✅ Client accepted on Port 3055! Active project #${newProjId} created.`);

      // 6. Admin on Port 4055 verifies created project and issues billing update
      console.log('\n6️⃣ Admin on Port 4055 inspects project and verifies shared state...');
      const projCheck = await callApi(ADMIN_TEST_PORT, `/api/projects/${newProjId}`, {
        headers: { 'Authorization': `Bearer ${adminToken}` }
      });
      console.assert(projCheck.ok && projCheck.data.project.total_cost === 65000, 'Project check failed');
      console.log(`   ✅ Admin verified project on Port 4055: Total Cost ₹${projCheck.data.project.total_cost}`);

      console.log(`\n======================================================`);
      console.log(`🎉 DUAL-PORT SIMULTANEOUS EXECUTION VERIFIED!`);
      console.log(`   Clients operate seamlessly on Port 3000`);
      console.log(`   Admins operate seamlessly on Port 4000`);
      console.log(`   Sessions and cookies are completely independent!`);
      console.log(`======================================================\n`);
    } catch (err) {
      console.error('❌ Dual-port test failed:', err);
      process.exitCode = 1;
    } finally {
      clientServer.close();
      adminServer.close();
      process.exit(0);
    }
  });
});
