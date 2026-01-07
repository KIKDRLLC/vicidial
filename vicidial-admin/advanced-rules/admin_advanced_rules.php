<?php
# admin_advanced_rules.php
# VICIdial Lead Automation Rules (Ytel-like) – UI only, backed by NestJS service
# IMPORTANT: This file is intended to be INCLUDED from admin.php (like ADD=xxx pages).
# Do NOT require dbconnect/functions/admin_header here.

if (!isset($link)) {
  echo "<div style='padding:10px;color:#900;'>DB link not initialized (this file must be included from admin.php).</div>";
  return;
}

/*
  This page does NOT talk directly to the Vicidial DB.
  It uses JavaScript to call your NestJS rules engine:

    - GET  /rules
    - POST /rules/:id/dry-run
    - GET  /rules/:id/runs

  Configure the base URL below.
*/

$RULES_API_BASE = 'http://10.0.2.17:3000';
$RULES_API_KEY  = '';  // optional
?>

<style>
  /* Do not style body/html here (admin.php owns the page shell) */
  .rules-container { margin: 12px 18px; background: #fff; border: 1px solid #ccc; padding: 14px; max-width: 1200px; }
  .rules-header { display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px; }
  .rules-header-left { font-weight:bold; }
  .rules-header-right { font-size: 11px; color:#555; }
  .rules-table { border-collapse: collapse; width: 100%; margin-top: 10px; }
  .rules-table th, .rules-table td { border: 1px solid #d0d0d0; padding: 4px 6px; font-size: 11px; }
  .rules-table th { background: #eee; }
  .btn { padding: 3px 8px; font-size: 11px; cursor:pointer; border-radius:3px; border:1px solid #888; background:#f5f5f5; }
  .btn:hover { background:#e7e7e7; }
  .pill { display:inline-block; padding:2px 6px; border:1px solid #bbb; border-radius:10px; font-size:11px; background:#fafafa; }
  .msg { margin: 10px 0; padding: 8px 10px; border: 1px solid #7bb07b; background: #e9f7e9; white-space: pre-wrap; }
  .err { margin: 10px 0; padding: 8px 10px; border: 1px solid #cc6b6b; background: #ffe9e9; white-space: pre-wrap; }
  .sample-block { margin-top: 10px; }
  .sample-table { border-collapse: collapse; width: 100%; margin-top: 6px; }
  .sample-table th, .sample-table td { border: 1px solid #d0d0d0; padding: 3px 4px; font-size: 11px; }
  .sample-table th { background:#f4f4f4; }
  .status-badge { padding: 1px 4px; border-radius: 3px; background-color: #eee; border: 1px solid #ccc; font-size: 10px; }
  .toprow { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
  .small { font-size: 11px; color:#555; }
</style>

<div class="rules-container">
  <div class="rules-header">
    <div class="rules-header-left">
      Lead Automation Rules
      <span class="pill">Backed by rules engine</span>
    </div>
    <div class="rules-header-right">
      API base: <code><?php echo htmlspecialchars($RULES_API_BASE); ?></code>
    </div>
  </div>

  <div class="toprow">
    <div>
      <button type="button" class="btn" onclick="loadRules();">Reload Rules</button>
    </div>
    <div class="small">
      Use Dry-run before Apply to see which leads will be affected.
    </div>
  </div>

  <div id="rules-message" class="msg" style="display:none;"></div>
  <div id="rules-error" class="err" style="display:none;"></div>

  <table class="rules-table">
    <thead>
      <tr>
        <th style="width:60px;">ID</th>
        <th>Name</th>
        <th style="width:60px;">Active</th>
        <th style="width:130px;">Created</th>
        <th style="width:130px;">Updated</th>
        <th style="width:220px;">Actions</th>
      </tr>
    </thead>
    <tbody id="rules-rows"></tbody>
  </table>

  <div id="runs-block" class="sample-block" style="display:none;">
    <h3 style="margin:10px 0 4px;">Recent Runs</h3>
    <pre id="runs-output" style="border:1px solid #ccc; background:#fafafa; padding:6px; max-height:180px; overflow-y:auto; font-size:11px;"></pre>
  </div>

  <div id="sample-block" class="sample-block" style="display:none;">
    <h3 style="margin:10px 0 4px;">Dry-run Sample Leads</h3>
    <table class="sample-table">
      <thead>
        <tr>
          <th>Lead ID</th>
          <th>List ID</th>
          <th>Status</th>
          <th>Phone</th>
          <th>Entry Date</th>
          <th>Last Call Time</th>
          <th>Called</th>
          <th>Since Reset</th>
        </tr>
      </thead>
      <tbody id="sample-rows"></tbody>
    </table>
  </div>
</div>

<script>
  const API_BASE = <?php echo json_encode($RULES_API_BASE); ?>;
  const API_KEY  = <?php echo json_encode($RULES_API_KEY); ?>;

  function setMsg(text) {
    const ok = document.getElementById('rules-message');
    const er = document.getElementById('rules-error');
    if (ok) { ok.style.display = text ? 'block' : 'none'; ok.textContent = text || ''; }
    if (er) { er.style.display = 'none'; er.textContent = ''; }
  }

  function setErr(text) {
    const ok = document.getElementById('rules-message');
    const er = document.getElementById('rules-error');
    if (er) { er.style.display = text ? 'block' : 'none'; er.textContent = text || ''; }
    if (ok) { ok.style.display = 'none'; ok.textContent = ''; }
  }

  function clearDetails() {
    const runsBlock = document.getElementById('runs-block');
    const sampleBlock = document.getElementById('sample-block');
    if (runsBlock) runsBlock.style.display = 'none';
    if (sampleBlock) sampleBlock.style.display = 'none';
    const runsOut = document.getElementById('runs-output');
    const sampleBody = document.getElementById('sample-rows');
    if (runsOut) runsOut.textContent = '';
    if (sampleBody) sampleBody.innerHTML = '';
  }

  async function apiGet(path) {
    const headers = {};
    if (API_KEY) headers['x-api-key'] = API_KEY;
    const res = await fetch(API_BASE + path, { headers, credentials: 'same-origin' });
    if (!res.ok) {
      const txt = await res.text();
      throw new Error('GET ' + path + ' failed: ' + res.status + ' ' + txt);
    }
    return res.json();
  }

  async function apiPost(path, body) {
    const headers = { 'Content-Type': 'application/json' };
    if (API_KEY) headers['x-api-key'] = API_KEY;
    const res = await fetch(API_BASE + path, {
      method: 'POST',
      headers,
      body: JSON.stringify(body || {}),
      credentials: 'same-origin'
    });
    if (!res.ok) {
      const txt = await res.text();
      throw new Error('POST ' + path + ' failed: ' + res.status + ' ' + txt);
    }
    return res.json();
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  async function loadRules() {
    setMsg('Loading rules...');
    clearDetails();
    const tbody = document.getElementById('rules-rows');
    if (tbody) tbody.innerHTML = '';
    try {
      const rules = await apiGet('/rules');
      if (!Array.isArray(rules)) {
        setErr('Unexpected response from /rules (not an array).');
        return;
      }
      if (tbody) {
        rules.forEach(rule => {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td>${rule.id}</td>
            <td>${escapeHtml(rule.name || '')}</td>
            <td>${rule.is_active ? 'Yes' : 'No'}</td>
            <td>${rule.created_at || ''}</td>
            <td>${rule.updated_at || ''}</td>
            <td>
              <button type="button" class="btn" onclick="dryRunRule(${rule.id});">Dry-run</button>
              <button type="button" class="btn" onclick="viewRuns(${rule.id});">Runs</button>
              <button type="button" class="btn" onclick="applyRulePrompt(${rule.id});">Apply…</button>
            </td>
          `;
          tbody.appendChild(tr);
        });
      }
      setMsg('Loaded ' + rules.length + ' rule(s).');
    } catch (e) {
      setErr(String(e));
    }
  }

  async function dryRunRule(id) {
    setMsg('Running dry-run for rule ' + id + ' …');
    clearDetails();
    try {
      const result = await apiPost('/rules/' + id + '/dry-run', {});
      const matched = result.matchedCount ?? 0;
      setMsg(
        'Dry-run succeeded.\n' +
        'Matched leads: ' + matched + '\n' +
        'Sample rows shown below (up to ' + (result.sample?.length || 0) + ').'
      );
      const sample = Array.isArray(result.sample) ? result.sample : [];
      const sampleBody = document.getElementById('sample-rows');
      if (sampleBody) {
        sampleBody.innerHTML = '';
        sample.forEach(r => {
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td>${r.lead_id}</td>
            <td>${r.list_id}</td>
            <td><span class="status-badge">${escapeHtml(r.status || '')}</span></td>
            <td>${escapeHtml(r.phone_number || '')}</td>
            <td>${r.entry_date || ''}</td>
            <td>${r.last_local_call_time || ''}</td>
            <td>${r.called_count != null ? r.called_count : ''}</td>
            <td>${r.called_since_last_reset != null ? r.called_since_last_reset : ''}</td>
          `;
          sampleBody.appendChild(tr);
        });
        document.getElementById('sample-block').style.display = sample.length ? 'block' : 'none';
      }
    } catch (e) {
      setErr('Dry-run failed: ' + String(e));
    }
  }

  async function viewRuns(ruleId) {
    setMsg('Loading runs for rule ' + ruleId + ' …');
    clearDetails();
    try {
      const runs = await apiGet('/rules/' + ruleId + '/runs');
      if (!Array.isArray(runs) || runs.length === 0) {
        setMsg('No runs yet for this rule.');
        return;
      }
      let text = '';
      runs.forEach(run => {
        text += '#' + run.id + ' [' + run.run_type + '] ' +
                'status=' + run.status +
                ', matched=' + run.matched_count +
                ', updated=' + run.updated_count +
                ', started=' + (run.started_at || '') +
                ', ended=' + (run.ended_at || '') + '\n';
      });
      const runsOut = document.getElementById('runs-output');
      if (runsOut) {
        runsOut.textContent = text;
        document.getElementById('runs-block').style.display = 'block';
      }
      setMsg('Loaded ' + runs.length + ' run(s).');
    } catch (e) {
      setErr('Failed to load runs: ' + String(e));
    }
  }

  async function applyRulePrompt(ruleId) {
    const batchSize = prompt('Batch size? (e.g. 500)', '500');
    if (batchSize === null) return;
    const maxToUpdate = prompt('Max leads to update? (e.g. 10000)', '10000');
    if (maxToUpdate === null) return;

    const b = parseInt(batchSize, 10);
    const m = parseInt(maxToUpdate, 10);
    if (isNaN(b) || isNaN(m) || b <= 0 || m <= 0) {
      alert('Invalid numbers.');
      return;
    }

    if (!confirm('Apply rule ' + ruleId + ' with batchSize=' + b + ', maxToUpdate=' + m + ' ?')) {
      return;
    }

    setMsg('Applying rule ' + ruleId + ' …');
    clearDetails();
    try {
      const result = await apiPost('/rules/' + ruleId + '/apply', {
        batchSize: b,
        maxToUpdate: m
      });
      setMsg('Apply finished:\n' + JSON.stringify(result, null, 2));
      viewRuns(ruleId);
    } catch (e) {
      setErr('Apply failed: ' + String(e));
    }
  }

  document.addEventListener('DOMContentLoaded', function() {
    loadRules();
  });
</script>