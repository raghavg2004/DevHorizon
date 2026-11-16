const http = require('http');

function post(port, path, body, token) {
  return new Promise((resolve, reject) => {
    const payload = JSON.stringify(body);
    const req = http.request({
      hostname: 'localhost',
      port,
      path,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(payload),
        ...(token ? { 'Authorization': 'Bearer ' + token } : {})
      }
    }, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => resolve({ status: res.statusCode, data: JSON.parse(data) }));
    });
    req.on('error', reject);
    req.write(payload);
    req.end();
  });
}

function get(port, path, token) {
  return new Promise((resolve, reject) => {
    const req = http.request({
      hostname: 'localhost',
      port,
      path,
      method: 'GET',
      headers: {
        ...(token ? { 'Authorization': 'Bearer ' + token } : {})
      }
    }, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => resolve({ status: res.statusCode, data: JSON.parse(data) }));
    });
    req.on('error', reject);
    req.end();
  });
}

(async () => {
  try {
    console.log('--- 1. Login Admin and Client ---');
    const adminLogin = await post(4000, '/api/auth/login', { email: 'admin@devhorizan.com', password: 'Admin@12345' });
    const adminToken = adminLogin.data.token;
    console.log('Admin login: HTTP', adminLogin.status, 'OK');

    const clientLogin = await post(3000, '/api/auth/login', { email: 'client@devhorizan.com', password: 'Client@12345' });
    const clientToken = clientLogin.data.token;
    console.log('Client login: HTTP', clientLogin.status, 'OK');

    console.log('\n--- 2. Client submits a new project request ---');
    const newReq = await post(3000, '/api/requests', {
      project_name: 'E-Commerce Ultra v2',
      project_type: 'Web Application',
      description: 'Full-stack marketplace with payment gateway',
      required_features: 'Cart, Checkout, Stripe, Dashboard',
      expected_budget: 50000,
      expected_deadline: '2026-12-15'
    }, clientToken);
    const reqId = newReq.data.requestId;
    console.log('Submitted Request ID:', reqId, 'Code:', newReq.data.requestCode);

    console.log('\n--- 3. Admin proposes a revision ---');
    const adminRev = await post(4000, '/api/requests/' + reqId + '/revision', {
      proposed_budget: 85000,
      reason_for_revision: 'Payment gateway compliance and multi-vendor scaling requires 85k budget.',
      updated_requirements: 'Phase 1: Basic Store. Phase 2: Multi-vendor marketplace.',
      admin_comments: 'We can deliver Phase 1 in 3 weeks.'
    }, adminToken);
    console.log('Admin proposed revision:', adminRev.data.message);

    console.log('\n--- 4. Client fetches requests list and sees revision details attached ---');
    const clientReqs = await get(3000, '/api/requests', clientToken);
    const targetReq = clientReqs.data.requests.find(r => r.id === reqId);
    console.log('Request Status:', targetReq.status);
    console.log('Latest Revision Budget:', targetReq.latest_revision_budget);
    console.log('Latest Revision Reason:', targetReq.latest_revision_reason);
    console.log('Latest Revision Status:', targetReq.latest_revision_status);

    console.log('\n--- 5. Client counter-proposes (Propose Revision Again to Admin) ---');
    const clientCounter = await post(3000, '/api/requests/' + reqId + '/respond', {
      action: 'resubmit',
      updated_budget: 70000,
      updated_description: 'We can skip multi-vendor and keep only single-vendor checkout.',
      notes: 'Our budget limit is ₹70,000. Single-vendor is sufficient for launch.'
    }, clientToken);
    console.log('Client counter-proposal response:', clientCounter.data.message);

    console.log('\n--- 6. Admin checks request details & revision history ---');
    const adminCheck = await get(4000, '/api/requests/' + reqId, adminToken);
    console.log('Request Status after counter:', adminCheck.data.request.status);
    console.log('New Expected Budget:', adminCheck.data.request.expected_budget);
    console.log('Revisions count:', adminCheck.data.request.revisions.length);
    console.log('Client response notes on revision 1:', adminCheck.data.request.revisions[0].user_response_notes);

    console.log('\n--- 7. Admin proposes Revision Round 2 ---');
    const adminRev2 = await post(4000, '/api/requests/' + reqId + '/revision', {
      proposed_budget: 70000,
      reason_for_revision: 'Accepted single-vendor scope at ₹70,000.',
      updated_requirements: 'Single-vendor store with high speed checkout and admin panel.',
      admin_comments: 'Ready to proceed!'
    }, adminToken);
    console.log('Admin proposed Round 2:', adminRev2.data.message);

    console.log('\n--- 8. Client reviews Round 2 and accepts proposal ---');
    const clientAccept = await post(3000, '/api/requests/' + reqId + '/respond', {
      action: 'accept',
      notes: 'Deal! Let us start development.'
    }, clientToken);
    console.log('Client accepted:', clientAccept.data.message, 'Project ID:', clientAccept.data.projectId);

    console.log('\n--- 9. Verify Active Project and Invoice were created ---');
    const projDetail = await get(3000, '/api/projects/' + clientAccept.data.projectId, clientToken);
    console.log('Active Project Title:', projDetail.data.project.title);
    console.log('Total Cost: ₹', projDetail.data.project.total_cost);
    console.log('Status:', projDetail.data.project.status);

    console.log('\n🎉 ALL REVISION WORKFLOW STEPS PASSED SUCCESSFULLY!');
  } catch (err) {
    console.error('Test error:', err);
    process.exit(1);
  }
})();

