<?php
# admin_advanced_rules.php
# VICIdial Lead Automation Rules – UI only, backed by NestJS service (via proxy)

$startMS    = microtime();
$php_script = 'admin_advanced_rules.php';

require("dbconnect_mysqli.php");
require("functions.php");
require("admin_header.php");

// Proxy endpoint (same-origin)
$RULES_API_BASE = '/vicidial/rules_proxy.php';
$RULES_API_KEY  = '';  // optional
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Lead Automation Rules</title>
  <link rel="stylesheet" type="text/css" href="vicidial_styles.css">
  <style>
    body { font-family: Arial, sans-serif; font-size: 12px; }
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

    /* --- Modal (Ytel-style) --- */
    #rule-modal { display:none; position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(0,0,0,.35); z-index:9999; overflow:auto; }
    #rule-modal .box { background:#fff; width:1100px; max-width:96vw; margin:30px auto; padding:14px; border:1px solid #ccc; }

    /* IMPORTANT: minmax(0,1fr) prevents grid overflow */
    .ytel-grid {
      display:grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
      gap:16px;
      margin-top:10px;
    }

    /* Allow grid items to shrink instead of overflowing */
    .ytel-card {
      border:1px solid #d0d0d0;
      padding:10px;
      background:#fff;
      min-width:0;
      overflow:hidden;
    }

    .ytel-title { font-weight:bold; margin-bottom:8px; }

    .ytel-row {
      display:grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
      gap:12px;
      margin-bottom:10px;
    }

    .ytel-field { min-width:0; }

    .ytel-field label { display:block; font-size:11px; color:#333; margin-bottom:3px; }

    /* NEW: label helper for inline count pill */
    .label-flex { display:flex; justify-content:space-between; align-items:center; }
    .label-flex .pill { font-size:10px; padding:1px 6px; }

    /* Ensure controls can shrink inside grid columns */
    .ytel-field input[type="text"],
    .ytel-field input[type="number"],
    .ytel-field input[type="datetime-local"],
    .ytel-field select,
    .ytel-field textarea {
      width:100%;
      box-sizing:border-box;
      min-width:0;
    }

    .ytel-help { font-size:10px; color:#777; margin-top:3px; }
    .ytel-divider { height:1px; background:#eee; margin:10px 0; }
    .ytel-warn { border:1px solid #e0b84a; background:#fff7da; padding:8px; font-size:11px; margin-top:8px; }
    .ytel-error { border:1px solid #cc6b6b; background:#ffe9e9; padding:8px; font-size:11px; margin-top:8px; display:none; }
    .ytel-actionsbar { display:flex; justify-content:space-between; align-items:center; margin-top:12px; }
    .ytel-actionsbar .right { display:flex; gap:8px; }
    .ytel-advanced { display:none; margin-top:10px; }
    .ytel-advanced textarea { height:160px; font-family:monospace; width:100%; box-sizing:border-box; min-width:0; }

    /* Fix compare rows: allow wrapping so fixed-width select doesn't force overflow */
    .ytel-field > div[style*="display:flex"] { flex-wrap: wrap; }
    .ytel-field > div[style*="display:flex"] > select { flex: 0 0 170px; max-width:170px; }
    .ytel-field > div[style*="display:flex"] > input { flex: 1 1 140px; min-width:120px; }

    /* Optional: collapse to one column on smaller widths */
    @media (max-width: 1100px) {
      .ytel-grid { grid-template-columns: 1fr; }
      .ytel-row  { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<div class="rules-container">
  <div class="rules-header">
    <div class="rules-header-left">
      Lead Automation Rules <span class="pill">Backed by rules engine</span>
    </div>
    <div class="rules-header-right">
      Proxy: <code><?php echo htmlspecialchars($RULES_API_BASE); ?></code>
    </div>
  </div>

  <div class="toprow">
    <div>
      <button type="button" class="btn" onclick="loadRules();">Reload Rules</button>
      <button type="button" class="btn" onclick="openCreateRule();">Create Rule</button>
    </div>
    <div class="small">Use Dry-run before Apply.</div>
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
        <th style="width:360px;">Actions</th>
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

<!-- Modal -->
<div id="rule-modal">
  <div class="box">
    <div style="display:flex; justify-content:space-between; align-items:center;">
      <b id="rule-modal-title">Rule</b>
      <button class="btn" onclick="closeRuleModal()">Close</button>
    </div>

    <div class="ytel-row" style="margin-top:10px;">
      <div class="ytel-field">
        <label><b>Name</b></label>
        <input id="rule-name" type="text" />
      </div>
      <div class="ytel-field">
        <label><b>Description (optional)</b></label>
        <input id="rule-description" type="text" />
      </div>
    </div>

    <div class="ytel-row">
      <div class="ytel-field">
        <label><b>Active</b></label>
        <input id="rule-active" type="checkbox" />
      </div>
      <div class="ytel-field">
        <label><b>Sample Limit (for dry-run preview)</b></label>
        <input id="sample-limit" type="number" min="1" placeholder="e.g. 20" />
        <div class="ytel-help">Maps to <code>conditions.sampleLimit</code></div>
      </div>
    </div>

    <!-- SCHEDULING -->
    <div class="ytel-card" style="margin-top:10px;">
      <div class="ytel-title">SCHEDULING <span class="ytel-help">(automation)</span></div>

      <div class="ytel-row">
        <div class="ytel-field">
          <label>Interval (minutes)</label>
          <input id="sched-interval-minutes" type="number" min="1" placeholder="e.g. 15" />
          <div class="ytel-help">If empty, automation is disabled for this rule.</div>
        </div>

        <div class="ytel-field">
          <label>Next execution time</label>
          <input id="sched-next-exec" type="datetime-local" />
          <div class="ytel-help">Sent as SQL DATETIME to backend (e.g. 2025-12-18 10:30:00).</div>
        </div>
      </div>

      <div class="ytel-row">
        <div class="ytel-field">
          <label>Batch size</label>
          <input id="sched-batch-size" type="number" min="1" max="5000" placeholder="500" />
          <div class="ytel-help">Maps to <code>apply.batchSize</code> for scheduled runs.</div>
        </div>

        <div class="ytel-field">
          <label>Max leads to update</label>
          <input id="sched-max-to-update" type="number" min="1" max="50000" placeholder="10000" />
          <div class="ytel-help">Maps to <code>apply.maxToUpdate</code> for scheduled runs.</div>
        </div>
      </div>
    </div>

    <div class="ytel-grid">
      <!-- FROM -->
      <div class="ytel-card">
        <div class="ytel-title">FROM <span class="ytel-help">(lead criteria to update)</span></div>

        <div class="ytel-row">
          <div class="ytel-field">
            <label>Match mode</label>
            <select id="from-match-mode">
              <option value="AND">ALL conditions (AND)</option>
              <option value="OR">ANY condition (OR)</option>
            </select>
          </div>

        </div>
        <div class="ytel-row">
          <div class="ytel-field">
            <label>Campaign</label>
            <select id="from-campaign-id">
              <option value="">All campaigns</option>
            </select>
            <div class="ytel-help">Selecting a campaign filters Lists + Statuses.</div>
          </div>
          <div class="ytel-field">
            <label>From List</label>
            <select id="from-list-id">
              <option value="">All lists in system</option>
            </select>
            <div class="ytel-help">Maps to <code>list_id</code></div>
          </div>
        </div>

        <div class="ytel-row">
          <div class="ytel-field">
            <!-- CHANGE #1: show selected count next to label -->
            <label class="label-flex">
              <span>From Status</span>
              <span id="from-status-count" class="pill">0 selected</span>
            </label>
            <select id="from-status" name="from_status[]" multiple size="8" style="height:140px;">
              <!-- dynamically populated -->
            </select>
          </div>

          <div class="ytel-field">
            <label>Called Since Last Reset</label>
            <select id="from-called-since-mode">
              <option value="">Do not check</option>
              <option value="YES">Yes</option>
              <option value="NO">No</option>
            </select>
            <div class="ytel-help">
              Yes =&gt; <code>called_since_last_reset &gt; 0</code>, No =&gt; <code>= 0</code>
            </div>
          </div>
        </div>

        <div class="ytel-row">

          <div class="ytel-field">
            <label>At least # days since entry date (shortcut)</label>
            <input id="from-days-entry" type="number" min="0" placeholder="Leave empty = ignore" />
            <div class="ytel-help">Uses <code>entry_date</code> + <code>OLDER_THAN_DAYS</code></div>
          </div>
        </div>

        <!-- Advanced compare blocks -->
        <div class="ytel-row">
          <div class="ytel-field">
            <label>Called count</label>
            <div style="display:flex; gap:8px;">
              <select id="cc-op" style="width:170px;">
                <option value="">Do not apply</option>
                <option value="=">equal(=)</option>
                <option value="!=">not equal(≠)</option>
                <option value=">=">greater than or equal(>=)</option>
                <option value=">">greater than(>) </option>
                <option value="<=">less than or equal(<=)</option>
                <option value="<">less than(<)</option>
                <option value="BETWEEN">Between</option>
                <option value="NOT_BETWEEN">Not Between</option>
              </select>
              <input id="cc-v1" type="text" placeholder="value (or CSV for IN)" />
              <input id="cc-v2" type="text" placeholder="and value2 (Between)" />
            </div>
          </div>

          <div class="ytel-field">
            <label>Entry date time</label>
            <div style="display:flex; gap:8px;">
              <select id="ed-op" style="width:170px;">
                <option value="">Do not apply</option>
                <option value="=">equal(=)</option>
                <option value="!=">not equal(≠)</option>
                <option value=">=">greater than or equal(>=)</option>
                <option value=">">greater than(>) </option>
                <option value="<=">less than or equal(<=)</option>
                <option value="<">less than(<)</option>
                <option value="BETWEEN">Between</option>
                <option value="NOT_BETWEEN">Not Between</option>
              </select>
              <input id="ed-v1" type="datetime-local" />
              <input id="ed-v2" type="datetime-local" />
            </div>
          </div>
        </div>

        <div class="ytel-row">
          <div class="ytel-field">
            <label>Phone contains</label>
            <input id="from-phone-contains" type="text" placeholder="optional (CONTAINS)" />
          </div>

          <div class="ytel-field">
            <label>Last Local Call Time</label>
            <div style="display:flex; gap:8px;">
              <select id="lc-op" style="width:170px;">
                <option value="">Do not apply</option>
                <option value="=">equal(=)</option>
                <option value="!=">not equal(≠)</option>
                <option value=">=">greater than or equal(>=)</option>
                <option value=">">greater than(>) </option>
                <option value="<=">less than or equal(<=)</option>
                <option value="<">less than(<)</option>
                <option value="BETWEEN">Between</option>
                <option value="NOT_BETWEEN">Not Between</option>
              </select>
              <input id="lc-v1" type="datetime-local" />
              <input id="lc-v2" type="datetime-local" />
            </div>
          </div>
        </div>

        <div id="from-warning" class="ytel-warn" style="display:none;">
          No FROM filters set. This will match <b>ALL leads</b>.
        </div>
      </div>

      <!-- TO -->
      <div class="ytel-card">
        <div class="ytel-title">TO <span class="ytel-help">(updates applied to matched leads)</span></div>

        <div class="ytel-row">
          <div class="ytel-field">
            <!-- CHANGE #2: dropdown replicating available lists -->
            <label>To List ID</label>
            <select id="to-list-id">
              <option value="">Leave empty = no change</option>
            </select>
            <div class="ytel-help">Maps to <code>actions.move_to_list</code></div>
          </div>
          <div class="ytel-field">
            <!-- CHANGE #3: single-select status dropdown -->
            <label>To Status</label>
            <select id="to-status">
              <option value="">Leave empty = no change</option>
            </select>
            <div class="ytel-help">Maps to <code>actions.update_status</code></div>
          </div>
        </div>

        <div class="ytel-row">
          <div class="ytel-field">
            <label>Reset Called Since Last Reset</label>
            <label class="small">
              <input id="to-reset-called" type="checkbox" />
              Reset counter
            </label>
            <div class="ytel-help">Maps to <code>actions.reset_called_since_last_reset</code></div>
          </div>
          <div class="ytel-field">
            <label>&nbsp;</label>
            <div id="to-error" class="ytel-error">
              Please set at least one TO action (move list/status/reset).
            </div>
          </div>
        </div>

        <div class="ytel-divider"></div>

        <label class="small">
          <input id="toggle-advanced" type="checkbox" onchange="toggleAdvanced()" />
          Advanced JSON (optional)
        </label>

        <div id="advanced-block" class="ytel-advanced">
          <div class="ytel-help">If filled, this overrides the builder.</div>
          <label class="small"><b>Conditions JSON</b> (ConditionSpec)</label>
          <textarea id="rule-conditions"></textarea>

          <label class="small" style="margin-top:8px; display:block;"><b>Actions JSON</b> (ActionSpec)</label>
          <textarea id="rule-actions-json"></textarea>
        </div>
      </div>
    </div>

    <div class="ytel-actionsbar">
      <div class="small" id="builder-preview" style="color:#666;"></div>
      <div class="right">
        <button class="btn" onclick="saveRule()">Save</button>
      </div>
    </div>
  </div>
</div>

<script>
  const API_BASE = <?php echo json_encode($RULES_API_BASE); ?>;
  const API_KEY  = <?php echo json_encode($RULES_API_KEY); ?>;

  let editingRuleId = null;

  // datetime-local <-> SQL DATETIME
  function dtLocalToSql(v) {
    // "2025-12-17T13:45" -> "2025-12-17 13:45:00"
    if (!v) return null;
    const s = String(v).trim();
    if (!s) return null;
    return s.replace('T', ' ') + ':00';
  }
  function sqlToDtLocal(v) {
    // "2025-12-17 13:45:00" -> "2025-12-17T13:45"
    if (!v) return '';
    const s = String(v);
    if (s.includes('T')) return s.slice(0, 16);
    if (s.includes(' ')) return s.replace(' ', 'T').slice(0, 16);
    return '';
  }

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
    document.getElementById('runs-block').style.display = 'none';
    document.getElementById('sample-block').style.display = 'none';
    document.getElementById('runs-output').textContent = '';
    document.getElementById('sample-rows').innerHTML = '';
  }

  async function apiFetch(path, opts) {
    const headers = (opts && opts.headers) ? opts.headers : {};
    if (API_KEY) headers['x-api-key'] = API_KEY;

    const url = API_BASE + '?path=' + encodeURIComponent(path);

    const res = await fetch(url, Object.assign({}, opts || {}, {
      headers,
      credentials: 'same-origin'
    }));

    if (!res.ok) {
      const txt = await res.text();
      throw new Error((opts?.method || 'GET') + ' ' + path + ' failed: ' + res.status + ' ' + txt);
    }

    const text = await res.text();
    if (!text) return null;
    try { return JSON.parse(text); } catch { return text; }
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
    tbody.innerHTML = '';
    try {
      const rules = await apiFetch('/rules');
      if (!Array.isArray(rules)) {
        setErr('Unexpected response from /rules (not an array).');
        return;
      }

      rules.forEach(rule => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${rule.id}</td>
          <td>${escapeHtml(rule.name || '')}</td>
          <td>${rule.is_active ? 'Yes' : 'No'}</td>
          <td>${rule.created_at || ''}</td>
          <td>${rule.updated_at || ''}</td>
          <td>
            <button type="button" class="btn" onclick="openEditRule(${rule.id});">Edit</button>
            <button type="button" class="btn" onclick="cloneRule(${rule.id});">Clone</button>
            <button type="button" class="btn" onclick="dryRunRule(${rule.id});">Dry-run</button>
            <button type="button" class="btn" onclick="viewRuns(${rule.id});">Runs</button>
            <button type="button" class="btn" onclick="applyRulePrompt(${rule.id});">Apply…</button>
            <button type="button" class="btn" onclick="deleteRule(${rule.id});">Delete</button>
          </td>
        `;
        tbody.appendChild(tr);
      });

      setMsg('Loaded ' + rules.length + ' rule(s).');
    } catch (e) {
      setErr(String(e));
    }
  }

  // ---------- Safe DOM setters ----------
  function setValue(id, value) {
    const el = document.getElementById(id);
    if (!el) { console.warn("Skipping missing element:", id); return; }
    if ("value" in el) el.value = value;
  }
  function setChecked(id, checked) {
    const el = document.getElementById(id);
    if (!el) { console.warn("Skipping missing element:", id); return; }
    el.checked = checked;
  }
  function setText(id, text) {
    const el = document.getElementById(id);
    if (!el) { console.warn("Skipping missing element:", id); return; }
    el.textContent = text;
  }
  function clearMultiSelect(id) {
    const el = document.getElementById(id);
    if (!el) return;
    Array.from(el.options).forEach(o => (o.selected = false));
  }
  function setMultiSelectValues(id, values) {
    const el = document.getElementById(id);
    if (!el) return;
    const set = new Set((values || []).map(String));
    Array.from(el.options).forEach(o => (o.selected = set.has(String(o.value))));
  }

  // ---------- UI helpers ----------
  // CHANGE #1: status selected count badge
  function updateFromStatusCount() {
    const sel = document.getElementById('from-status');
    const badge = document.getElementById('from-status-count');
    if (!sel || !badge) return;
    const count = Array.from(sel.selectedOptions || []).length;
    badge.textContent = count + ' selected';
  }

  // Show the 2nd value input only for BETWEEN / NOT_BETWEEN
function toggleBetweenV2(opSelectId, v2InputId) {
  const opEl = document.getElementById(opSelectId);
  const v2El = document.getElementById(v2InputId);
  if (!opEl || !v2El) return;

  const op = String(opEl.value || '').trim();
  const show = (op === 'BETWEEN' || op === 'NOT_BETWEEN');

  // hide/show the input itself
  v2El.style.display = show ? '' : 'none';
}

function updateBetweenInputsVisibility() {
  toggleBetweenV2('cc-op', 'cc-v2');
  toggleBetweenV2('ed-op', 'ed-v2');
  toggleBetweenV2('lc-op', 'lc-v2');
}


  // ---------- Builder helpers ----------
  function toggleAdvanced() {
    const t = document.getElementById('toggle-advanced');
    const block = document.getElementById('advanced-block');
    if (!t || !block) return;
    block.style.display = t.checked ? 'block' : 'none';
  }

  function showToError(show) {
    const el = document.getElementById('to-error');
    if (!el) return;
    el.style.display = show ? 'block' : 'none';
  }

  function showFromWarning(show) {
    const el = document.getElementById('from-warning');
    if (!el) return;
    el.style.display = show ? 'block' : 'none';
  }

  function readMultiSelectValues(selectId) {
    const el = document.getElementById(selectId);
    if (!el) return [];
    return Array.from(el.selectedOptions || []).map(o => o.value).filter(Boolean);
  }

  function csvToArray(str) {
    return (str || '')
      .split(',')
      .map(s => s.trim())
      .filter(Boolean);
  }

  // Builds a rule/group for numeric/datetime compares including BETWEEN/NOT_BETWEEN/IN
  function buildCompare(field, op, v1Raw, v2Raw) {
    if (!op) return null;

    if (op === 'IN') {
      const arr = csvToArray(v1Raw);
      if (!arr.length) return null;
      return { field, op: 'IN', value: arr };
    }

    if (op === 'BETWEEN') {
      if (v1Raw === '' || v2Raw === '' || v1Raw == null || v2Raw == null) return null;
      return { op: 'AND', rules: [
        { field, op: '>=', value: v1Raw },
        { field, op: '<=', value: v2Raw }
      ]};
    }

    if (op === 'NOT_BETWEEN') {
      if (v1Raw === '' || v2Raw === '' || v1Raw == null || v2Raw == null) return null;
      return { op: 'OR', rules: [
        { field, op: '<', value: v1Raw },
        { field, op: '>', value: v2Raw }
      ]};
    }

    if (v1Raw === '' || v1Raw == null) return null;
    return { field, op, value: v1Raw };
  }

  function buildWhereFromForm() {
    const rules = [];

    // list_id
    const fromList = (document.getElementById('from-list-id')?.value || '').trim();
    if (fromList !== '') {
      const n = parseInt(fromList, 10);
      if (!isNaN(n) && n > 0) rules.push({ field: 'list_id', op: '=', value: n });
    }

    // status multi-select
    const statuses = readMultiSelectValues('from-status');
    if (statuses.length === 1) rules.push({ field: 'status', op: '=', value: statuses[0] });
    if (statuses.length > 1) rules.push({ field: 'status', op: 'IN', value: statuses });

    // called_since_last_reset dropdown
    const csrMode = (document.getElementById('from-called-since-mode')?.value || '').trim();
    if (csrMode === 'YES') rules.push({ field: 'called_since_last_reset', op: '>', value: 0 });
    if (csrMode === 'NO')  rules.push({ field: 'called_since_last_reset', op: '=', value: 0 });

    // entry_date older-than days (shortcut)
    const daysEntryRaw = (document.getElementById('from-days-entry')?.value || '').trim();
    if (daysEntryRaw !== '') {
      const n = parseInt(daysEntryRaw, 10);
      if (!isNaN(n) && n >= 0) rules.push({ field: 'entry_date', op: 'OLDER_THAN_DAYS', value: n });
    }

    // phone contains
    const phoneContains = (document.getElementById('from-phone-contains')?.value || '').trim();
    if (phoneContains) rules.push({ field: 'phone_number', op: 'CONTAINS', value: phoneContains });

    // called_count compare block (preferred)
    const ccOp = (document.getElementById('cc-op')?.value || '').trim();
    const ccV1 = (document.getElementById('cc-v1')?.value || '').trim();
    const ccV2 = (document.getElementById('cc-v2')?.value || '').trim();

    if (ccOp) {
      const norm1 = (ccOp === 'IN') ? ccV1 : (ccV1 !== '' ? Number(ccV1) : '');
      const norm2 = (ccV2 !== '' ? Number(ccV2) : '');
      const node = buildCompare('called_count', ccOp, norm1, norm2);
      if (node) rules.push(node);
    } else {
      // called_count >= shortcut only when advanced cc-op not used
      const ccShortcut = (document.getElementById('from-called-count')?.value || '').trim();
      if (ccShortcut !== '') {
        const n = parseInt(ccShortcut, 10);
        if (!isNaN(n) && n >= 0) rules.push({ field: 'called_count', op: '>=', value: n });
      }
    }

    // entry_date datetime compare
    {
      const op = (document.getElementById('ed-op')?.value || '').trim();
      const v1 = (document.getElementById('ed-v1')?.value || '').trim();
      const v2 = (document.getElementById('ed-v2')?.value || '').trim();
      const node = buildCompare('entry_date', op, dtLocalToSql(v1), dtLocalToSql(v2));
      if (node) rules.push(node);
    }

    // last_local_call_time datetime compare
    {
      const op = (document.getElementById('lc-op')?.value || '').trim();
      const v1 = (document.getElementById('lc-v1')?.value || '').trim();
      const v2 = (document.getElementById('lc-v2')?.value || '').trim();
      const node = buildCompare('last_local_call_time', op, dtLocalToSql(v1), dtLocalToSql(v2));
      if (node) rules.push(node);
    }

    const matchMode = document.getElementById('from-match-mode')?.value || 'AND';
    return { op: (matchMode === 'OR' ? 'OR' : 'AND'), rules };
  }

  function buildConditionsSpecFromForm() {
    const where = buildWhereFromForm();
    const sampleLimitRaw = (document.getElementById('sample-limit')?.value || '').trim();
    const sampleLimit = sampleLimitRaw !== '' ? parseInt(sampleLimitRaw, 10) : undefined;

    const spec = { where };
    if (Number.isFinite(sampleLimit) && sampleLimit > 0) spec.sampleLimit = sampleLimit;
    return spec;
  }

  function buildActionsFromForm() {
    const moveToListRaw = (document.getElementById('to-list-id')?.value || '').trim();
    const toStatus = (document.getElementById('to-status')?.value || '').trim();
    const reset = !!document.getElementById('to-reset-called')?.checked;

    const actions = {};
    if (moveToListRaw !== '') {
      const n = parseInt(moveToListRaw, 10);
      if (!isNaN(n) && n > 0) actions.move_to_list = n;
    }
    if (toStatus) actions.update_status = toStatus;
    if (reset) actions.reset_called_since_last_reset = true;

    return actions;
  }

  function refreshBuilderHints() {
    // keep the count pill always in sync
    updateFromStatusCount();
    updateBetweenInputsVisibility()
    const cond = buildConditionsSpecFromForm();
    const actions = buildActionsFromForm();

    showFromWarning(!cond.where.rules.length);

    const hasAction = !!(actions.move_to_list || actions.update_status || actions.reset_called_since_last_reset);
    showToError(!hasAction);

    const prev = document.getElementById('builder-preview');
    if (prev) {
      prev.textContent =
        'FROM rules: ' + cond.where.rules.length +
        ' | TO actions: ' +
        [actions.move_to_list ? 'move_to_list' : null,
         actions.update_status ? 'update_status' : null,
         actions.reset_called_since_last_reset ? 'reset' : null].filter(Boolean).join(', ');
    }
  }

  // Hook change events once
  let modalEventsBound = false;
  function bindModalEvents() {
    if (modalEventsBound) return;
    modalEventsBound = true;

    const ids = [
      'sample-limit',

      // Scheduling
      'sched-interval-minutes','sched-next-exec','sched-batch-size','sched-max-to-update',

      // FROM
      'from-match-mode','from-list-id','from-status','from-called-since-mode',
      'from-called-count','from-days-entry','from-phone-contains',

      // Compare blocks
      'cc-op','cc-v1','cc-v2',
      'ed-op','ed-v1','ed-v2',
      'lc-op','lc-v1','lc-v2',

      // TO
      'to-list-id','to-status','to-reset-called'
    ];

    ids.forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input', refreshBuilderHints);
      el.addEventListener('change', refreshBuilderHints);
    });
  }

  // ---------- Modal CRUD ----------
  async function openCreateRule() {
    editingRuleId = null;

    // Ensure meta dropdowns are populated
    await loadCampaignsForUI();
    await loadListsForUI();
    await loadStatusesForUI();

    setText('rule-modal-title', 'Create Rule');

    setValue('rule-name', '');
    setValue('rule-description', '');
    setChecked('rule-active', true);

    setValue('sample-limit', '20');

    // Scheduling defaults
    setValue('sched-interval-minutes', '');
    setValue('sched-next-exec', '');
    setValue('sched-batch-size', '500');
    setValue('sched-max-to-update', '10000');

    // FROM
    setValue('from-match-mode', 'AND');
    setValue('from-list-id', '');
    clearMultiSelect('from-status');
    setValue('from-called-since-mode', '');
    setValue('from-called-count', '');
    setValue('from-days-entry', '');
    setValue('from-phone-contains', '');

    // Compare blocks
    setValue('cc-op', '');
    setValue('cc-v1', '');
    setValue('cc-v2', '');

    setValue('ed-op', '');
    setValue('ed-v1', '');
    setValue('ed-v2', '');

    setValue('lc-op', '');
    setValue('lc-v1', '');
    setValue('lc-v2', '');

    // TO
    setValue('to-list-id', '');
    setValue('to-status', '');
    setChecked('to-reset-called', false);

    // Advanced JSON defaults
    setChecked('toggle-advanced', false);
    toggleAdvanced();
    setValue('rule-conditions', JSON.stringify({
      where: { op: "AND", rules: [] },
      sampleLimit: 20
    }, null, 2));
    setValue('rule-actions-json', JSON.stringify({}, null, 2));

    const modal = document.getElementById('rule-modal');
    if (!modal) { console.error("Missing modal container #rule-modal"); return; }
    modal.style.display = 'block';

    bindModalEvents();
    refreshBuilderHints();
  }

  function closeRuleModal() {
    const modal = document.getElementById('rule-modal');
    if (modal) modal.style.display = 'none';
  }

  function flattenRules(node, out = []) {
    if (!node) return out;
    if (node.field && node.op !== undefined) {
      out.push(node);
      return out;
    }
    if (node.rules && Array.isArray(node.rules)) {
      node.rules.forEach(r => flattenRules(r, out));
    }
    return out;
  }

  function tryExtractBetween(groupNode, field) {
    if (!groupNode || !groupNode.op || !Array.isArray(groupNode.rules)) return null;

    // BETWEEN pattern: AND of >= and <= for same field
    if (groupNode.op === 'AND' && groupNode.rules.length === 2) {
      const a = groupNode.rules[0], b = groupNode.rules[1];
      if (a?.field === field && b?.field === field) {
        const ops = new Set([a.op, b.op]);
        if (ops.has('>=') && ops.has('<=')) {
          const v1 = (a.op === '>=' ? a.value : b.value);
          const v2 = (a.op === '<=' ? a.value : b.value);
          return { op: 'BETWEEN', v1, v2 };
        }
      }
    }

    // NOT_BETWEEN pattern: OR of < and > for same field
    if (groupNode.op === 'OR' && groupNode.rules.length === 2) {
      const a = groupNode.rules[0], b = groupNode.rules[1];
      if (a?.field === field && b?.field === field) {
        const ops = new Set([a.op, b.op]);
        if (ops.has('<') && ops.has('>')) {
          const v1 = (a.op === '<' ? a.value : b.value);
          const v2 = (a.op === '>' ? a.value : b.value);
          return { op: 'NOT_BETWEEN', v1, v2 };
        }
      }
    }

    return null;
  }

  function resetBuilderUI() {
    setValue('sample-limit', '');

    // Scheduling
    setValue('sched-interval-minutes', '');
    setValue('sched-next-exec', '');
    setValue('sched-batch-size', '500');
    setValue('sched-max-to-update', '10000');

    // FROM
    setValue('from-match-mode', 'AND');
    setValue('from-list-id', '');
    clearMultiSelect('from-status');
    setValue('from-called-since-mode', '');
    setValue('from-called-count', '');
    setValue('from-days-entry', '');
    setValue('from-phone-contains', '');

    // Compare
    setValue('cc-op', ''); setValue('cc-v1', ''); setValue('cc-v2', '');
    setValue('ed-op', ''); setValue('ed-v1', ''); setValue('ed-v2', '');
    setValue('lc-op', ''); setValue('lc-v1', ''); setValue('lc-v2', '');

    // TO
    setValue('to-list-id', '');
    setValue('to-status', '');
    setChecked('to-reset-called', false);
  }

  async function openEditRule(id) {
    setMsg('Loading rule ' + id + ' …');

    // Ensure meta dropdowns are populated before we set values
    await loadCampaignsForUI();
    await loadListsForUI();
    await loadStatusesForUI();

    try {
      const rule = await apiFetch('/rules/' + id);
      editingRuleId = id;

      setText('rule-modal-title', 'Edit Rule #' + id);
      setValue('rule-name', rule.name || '');
      setValue('rule-description', rule.description || '');
      setChecked('rule-active', !!rule.is_active);

      let conditionsObj = rule.conditions_json ?? rule.conditions ?? null;
      if (typeof conditionsObj === 'string') conditionsObj = JSON.parse(conditionsObj);
      if (!conditionsObj) conditionsObj = { where: { op:"AND", rules:[] }, sampleLimit: 20 };

      let actionsObj = rule.actions_json ?? rule.actions ?? {};
      if (typeof actionsObj === 'string') actionsObj = JSON.parse(actionsObj);

      resetBuilderUI();

      // Scheduling load (safe if backend hasn't implemented yet)
      setValue('sched-interval-minutes', rule.intervalMinutes != null ? String(rule.intervalMinutes) : '');
      setValue('sched-next-exec', sqlToDtLocal(rule.nextExecAt));
      setValue('sched-batch-size', rule.applyBatchSize != null ? String(rule.applyBatchSize) : '500');
      setValue('sched-max-to-update', rule.applyMaxToUpdate != null ? String(rule.applyMaxToUpdate) : '10000');

      // sample limit & match mode
      if (conditionsObj.sampleLimit != null) setValue('sample-limit', String(conditionsObj.sampleLimit));
      setValue('from-match-mode', (conditionsObj.where?.op === 'OR') ? 'OR' : 'AND');

      const topRules = Array.isArray(conditionsObj.where?.rules) ? conditionsObj.where.rules : [];

      // Detect BETWEEN / NOT_BETWEEN groups first
      topRules.forEach(r => {
        const ccBetween = tryExtractBetween(r, 'called_count');
        if (ccBetween) {
          setValue('cc-op', ccBetween.op);
          setValue('cc-v1', ccBetween.v1 != null ? String(ccBetween.v1) : '');
          setValue('cc-v2', ccBetween.v2 != null ? String(ccBetween.v2) : '');
        }
        const edBetween = tryExtractBetween(r, 'entry_date');
        if (edBetween) {
          setValue('ed-op', edBetween.op);
          setValue('ed-v1', sqlToDtLocal(edBetween.v1));
          setValue('ed-v2', sqlToDtLocal(edBetween.v2));
        }
        const lcBetween = tryExtractBetween(r, 'last_local_call_time');
        if (lcBetween) {
          setValue('lc-op', lcBetween.op);
          setValue('lc-v1', sqlToDtLocal(lcBetween.v1));
          setValue('lc-v2', sqlToDtLocal(lcBetween.v2));
        }
      });

      const flat = [];
      topRules.forEach(r => flattenRules(r, flat));

      // list_id
      const listRule = flat.find(x => x.field === 'list_id' && x.op === '=');
      if (listRule) setValue('from-list-id', String(listRule.value ?? ''));

      // status
      const statusEq = flat.find(x => x.field === 'status' && x.op === '=');
      const statusIn = flat.find(x => x.field === 'status' && x.op === 'IN' && Array.isArray(x.value));
      if (statusEq) setMultiSelectValues('from-status', [statusEq.value]);
      if (statusIn) setMultiSelectValues('from-status', statusIn.value);

      // called_since_last_reset (YES/NO)
      const csrGt = flat.find(x => x.field === 'called_since_last_reset' && x.op === '>' && Number(x.value) === 0);
      const csrEq0 = flat.find(x => x.field === 'called_since_last_reset' && x.op === '=' && Number(x.value) === 0);
      if (csrGt) setValue('from-called-since-mode', 'YES');
      if (csrEq0) setValue('from-called-since-mode', 'NO');

      // entry_date OLDER_THAN_DAYS shortcut
      const entryOlder = flat.find(x => x.field === 'entry_date' && x.op === 'OLDER_THAN_DAYS');
      if (entryOlder && entryOlder.value != null) setValue('from-days-entry', String(entryOlder.value));

      // phone contains
      const phoneContains = flat.find(x => x.field === 'phone_number' && x.op === 'CONTAINS');
      if (phoneContains) setValue('from-phone-contains', String(phoneContains.value ?? ''));

      // called_count shortcut if advanced is empty
      if (!(document.getElementById('cc-op')?.value || '').trim()) {
        const ccGe = flat.find(x => x.field === 'called_count' && x.op === '>=');
        if (ccGe && ccGe.value != null) setValue('from-called-count', String(ccGe.value));
      }

      // called_count simple compare (if present and advanced empty)
      if (!(document.getElementById('cc-op')?.value || '').trim()) {
        const ccSimple = flat.find(x => x.field === 'called_count' && ['=','!=','>','>=','<','<='].includes(x.op));
        const ccIn = flat.find(x => x.field === 'called_count' && x.op === 'IN' && Array.isArray(x.value));
        if (ccIn) {
          setValue('cc-op', 'IN');
          setValue('cc-v1', ccIn.value.join(','));
          setValue('cc-v2', '');
        } else if (ccSimple) {
          setValue('cc-op', ccSimple.op);
          setValue('cc-v1', ccSimple.value != null ? String(ccSimple.value) : '');
          setValue('cc-v2', '');
        }
      }

      // entry_date simple compare (if not already set by BETWEEN)
      if (!(document.getElementById('ed-op')?.value || '').trim()) {
        const edIn = flat.find(x => x.field === 'entry_date' && x.op === 'IN' && Array.isArray(x.value));
        const edSimple = flat.find(x => x.field === 'entry_date' && ['=','!=','>','>=','<','<='].includes(x.op));
        if (edIn) {
          setValue('ed-op', 'IN');
          setValue('ed-v1', edIn.value.join(',')); // (rare; if your backend uses IN for datetime)
          setValue('ed-v2', '');
        } else if (edSimple) {
          setValue('ed-op', edSimple.op);
          setValue('ed-v1', sqlToDtLocal(edSimple.value));
          setValue('ed-v2', '');
        }
      }

      // last_local_call_time simple compare (if not already set by BETWEEN)
      if (!(document.getElementById('lc-op')?.value || '').trim()) {
        const lcIn = flat.find(x => x.field === 'last_local_call_time' && x.op === 'IN' && Array.isArray(x.value));
        const lcSimple = flat.find(x => x.field === 'last_local_call_time' && ['=','!=','>','>=','<','<='].includes(x.op));
        if (lcIn) {
          setValue('lc-op', 'IN');
          setValue('lc-v1', lcIn.value.join(','));
          setValue('lc-v2', '');
        } else if (lcSimple) {
          setValue('lc-op', lcSimple.op);
          setValue('lc-v1', sqlToDtLocal(lcSimple.value));
          setValue('lc-v2', '');
        }
      }

      // TO (now dropdowns)
      if (actionsObj.move_to_list != null) setValue('to-list-id', String(actionsObj.move_to_list));
      if (actionsObj.update_status != null) setValue('to-status', String(actionsObj.update_status));
      setChecked('to-reset-called', !!actionsObj.reset_called_since_last_reset);

      // Advanced snapshots
      setChecked('toggle-advanced', false);
      toggleAdvanced();
      setValue('rule-conditions', JSON.stringify(conditionsObj, null, 2));
      setValue('rule-actions-json', JSON.stringify(actionsObj, null, 2));

      const modal = document.getElementById('rule-modal');
      if (modal) modal.style.display = 'block';

      bindModalEvents();
      refreshBuilderHints();

      setMsg('');
    } catch (e) {
      setErr('Failed to load rule: ' + String(e));
    }
  }

  async function saveRule() {
    const name = (document.getElementById('rule-name')?.value || '').trim();
    const description = (document.getElementById('rule-description')?.value || '').trim();
    const isActive = !!document.getElementById('rule-active')?.checked;

    if (!name) { alert('Name required'); return; }

    const advancedOn = !!document.getElementById('toggle-advanced')?.checked;

    let conditions, actions;

    if (advancedOn) {
      try { conditions = JSON.parse(document.getElementById('rule-conditions')?.value || ''); }
      catch { alert('Invalid JSON in Conditions'); return; }

      try { actions = JSON.parse(document.getElementById('rule-actions-json')?.value || '{}'); }
      catch { alert('Invalid JSON in Actions'); return; }
    } else {
      conditions = buildConditionsSpecFromForm();
      actions = buildActionsFromForm();
    }

    if (!conditions || typeof conditions !== 'object' || !conditions.where) {
      alert('Conditions must include: { "where": { ... } }');
      return;
    }

    const hasAction = !!(actions.move_to_list || actions.update_status || actions.reset_called_since_last_reset);
    if (!hasAction) {
      showToError(true);
      alert('Please apply at least one change within the TO section.');
      return;
    }

    if (!conditions.where.rules || !conditions.where.rules.length) {
      if (!confirm('No FROM filters set. This may apply to ALL leads. Continue?')) return;
    }

    // Scheduling payload (validated)
    const intervalRaw = (document.getElementById('sched-interval-minutes')?.value || '').trim();
    const nextExecRaw = (document.getElementById('sched-next-exec')?.value || '').trim();
    const batchRaw = (document.getElementById('sched-batch-size')?.value || '').trim();
    const maxRaw   = (document.getElementById('sched-max-to-update')?.value || '').trim();

    let intervalMinutes = intervalRaw !== '' ? parseInt(intervalRaw, 10) : null;
    if (intervalMinutes != null && (isNaN(intervalMinutes) || intervalMinutes <= 0)) {
      alert('Interval minutes must be a positive number');
      return;
    }

    let applyBatchSize = batchRaw !== '' ? parseInt(batchRaw, 10) : 500;
    if (isNaN(applyBatchSize) || applyBatchSize < 1 || applyBatchSize > 5000) {
      alert('Batch size must be between 1 and 5000');
      return;
    }

    let applyMaxToUpdate = maxRaw !== '' ? parseInt(maxRaw, 10) : 10000;
    if (isNaN(applyMaxToUpdate) || applyMaxToUpdate < 1 || applyMaxToUpdate > 50000) {
      alert('Max leads to update must be between 1 and 50000');
      return;
    }

    const nextExecAt = nextExecRaw ? dtLocalToSql(nextExecRaw) : null;

    const payload = {
      name,
      ...(description ? { description } : {}),
      isActive,
      conditions,
      actions,

      // scheduling fields (backend must persist)
      intervalMinutes,
      nextExecAt,
      applyBatchSize,
      applyMaxToUpdate
    };

    try {
      if (editingRuleId == null) {
        await apiFetch('/rules', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        setMsg('Rule created.');
      } else {
        await apiFetch('/rules/' + editingRuleId, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        setMsg('Rule updated.');
      }

      closeRuleModal();
      loadRules();
    } catch (e) {
      setErr('Save failed: ' + String(e));
    }
  }

  async function deleteRule(id) {
    if (!confirm('Delete rule #' + id + '?')) return;
    try {
      await apiFetch('/rules/' + id, { method: 'DELETE' });
      setMsg('Rule deleted.');
      loadRules();
    } catch (e) {
      setErr('Delete failed: ' + String(e));
    }
  }

  // ---------- Existing operations ----------
  async function dryRunRule(id) {
    setMsg('Running dry-run for rule ' + id + ' …');
    clearDetails();
    try {
      const result = await apiFetch('/rules/' + id + '/dry-run', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
      });

      const matched = result?.matchedCount ?? 0;
      setMsg('Dry-run succeeded.\nMatched leads: ' + matched);

      const sample = Array.isArray(result?.sample) ? result.sample : [];
      const sampleBody = document.getElementById('sample-rows');
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
    } catch (e) {
      setErr('Dry-run failed: ' + String(e));
    }
  }

  async function viewRuns(ruleId) {
    setMsg('Loading runs for rule ' + ruleId + ' …');
    clearDetails();
    try {
      const runs = await apiFetch('/rules/' + ruleId + '/runs');
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
                ', ended=' + (run.ended_at || '') + "\n";
      });

      document.getElementById('runs-output').textContent = text;
      document.getElementById('runs-block').style.display = 'block';

      setMsg('Loaded ' + runs.length + ' run(s).');
    } catch (e) {
      setErr('Failed to load runs: ' + String(e));
    }
  }

  async function loadCampaignsForUI() {
    const sel = document.getElementById('from-campaign-id');
    if (!sel) return;

    const selected = sel.value || '';
    const rows = await apiFetch('/rules/meta/campaigns');

    sel.innerHTML = `<option value="">All campaigns</option>`;
    (rows || []).forEach(r => {
      const opt = document.createElement('option');
      opt.value = r.campaign_id;
      opt.textContent = r.campaign_id + (r.campaign_name ? (' - ' + r.campaign_name) : '');
      if (String(opt.value) === String(selected)) opt.selected = true;
      sel.appendChild(opt);
    });
  }

  // CHANGE #2: populate BOTH from-list-id and to-list-id
  async function loadListsForUI() {
    const fromSel = document.getElementById('from-list-id');
    const toSel   = document.getElementById('to-list-id');
    if (!fromSel && !toSel) return;

    const campaignId = (document.getElementById('from-campaign-id')?.value || '').trim();
    const qp = campaignId ? ('?campaignId=' + encodeURIComponent(campaignId)) : '';

    const fromSelected = fromSel ? (fromSel.value || '') : '';
    const toSelected   = toSel ? (toSel.value || '') : '';

    const rows = await apiFetch('/rules/meta/lists' + qp);

    if (fromSel) {
      fromSel.innerHTML = `<option value="">All lists</option>`;
      (rows || []).forEach(r => {
        const opt = document.createElement('option');
        opt.value = r.list_id;
        opt.textContent =
          r.list_id +
          (r.list_name ? (' - ' + r.list_name) : '') +
          (r.campaign_id ? (' [' + r.campaign_id + ']') : '');

        if (String(opt.value) === String(fromSelected)) opt.selected = true;
        fromSel.appendChild(opt);
      });
    }

    if (toSel) {
      toSel.innerHTML = `<option value="">Leave empty = no change</option>`;
      (rows || []).forEach(r => {
        const opt = document.createElement('option');
        opt.value = r.list_id;
        opt.textContent =
          r.list_id +
          (r.list_name ? (' - ' + r.list_name) : '') +
          (r.campaign_id ? (' [' + r.campaign_id + ']') : '');

        if (String(opt.value) === String(toSelected)) opt.selected = true;
        toSel.appendChild(opt);
      });
    }
  }

  // CHANGE #3: populate BOTH from-status (multi) and to-status (single)
  async function loadStatusesForUI() {
    const fromSel = document.getElementById('from-status');
    const toSel   = document.getElementById('to-status');
    if (!fromSel && !toSel) return;

    const campaignId = (document.getElementById('from-campaign-id')?.value || '').trim();
    const qp = campaignId ? ('?campaignId=' + encodeURIComponent(campaignId)) : '';

    const fromSelected = new Set(fromSel ? Array.from(fromSel.selectedOptions || []).map(o => o.value) : []);
    const toSelected = toSel ? (toSel.value || '') : '';

    const rows = await apiFetch('/rules/meta/statuses' + qp);

    if (fromSel) {
      fromSel.innerHTML = '';
      (rows || []).forEach(r => {
        const opt = document.createElement('option');
        opt.value = r.status;
        opt.textContent = r.status + (r.status_name ? (' - ' + r.status_name) : '');
        if (fromSelected.has(opt.value)) opt.selected = true;
        fromSel.appendChild(opt);
      });
    }

    if (toSel) {
      toSel.innerHTML = `<option value="">Leave empty = no change</option>`;
      (rows || []).forEach(r => {
        const opt = document.createElement('option');
        opt.value = r.status;
        opt.textContent = r.status + (r.status_name ? (' - ' + r.status_name) : '');
        if (String(opt.value) === String(toSelected)) opt.selected = true;
        toSel.appendChild(opt);
      });
    }

    updateFromStatusCount();
  }

  async function applyRulePrompt(ruleId) {
    const batchSize = prompt('Batch size? (e.g. 500)', '500');
    if (batchSize === null) return;
    const maxToUpdate = prompt('Max leads to update? (e.g. 10000)', '10000');
    if (maxToUpdate === null) return;

    const b = parseInt(batchSize, 10);
    const m = parseInt(maxToUpdate, 10);
    if (isNaN(b) || isNaN(m) || b <= 0 || m <= 0) { alert('Invalid numbers.'); return; }

    if (!confirm('Apply rule ' + ruleId + ' with batchSize=' + b + ', maxToUpdate=' + m + ' ?')) return;

    setMsg('Applying rule ' + ruleId + ' …');
    clearDetails();
    try {
      const result = await apiFetch('/rules/' + ruleId + '/apply', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ batchSize: b, maxToUpdate: m })
      });

      setMsg('Apply finished:\n' + JSON.stringify(result, null, 2));
      viewRuns(ruleId);
    } catch (e) {
      setErr('Apply failed: ' + String(e));
    }
  }

  async function cloneRule(id) {
  if (!confirm('Clone rule #' + id + '? The clone will be created as inactive.')) return;

  setMsg('Cloning rule ' + id + ' …');
  clearDetails();

  try {
    // If you support custom naming later:
    // const newName = prompt('Name for cloned rule (optional):', '');
    // const body = newName ? { name: newName } : {};

    const res = await apiFetch('/rules/' + id + '/clone', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({}) // keep empty for now
    });

    const newId = res?.id;
    if (!newId) {
      setErr('Clone succeeded but no new id returned: ' + JSON.stringify(res));
      loadRules();
      return;
    }

    setMsg('Cloned rule #' + id + ' → new rule #' + newId);
    await loadRules();

    // Open the cloned rule for editing immediately
    await openEditRule(newId);
  } catch (e) {
    setErr('Clone failed: ' + String(e));
  }
}


  // Campaign change should refresh both FROM and TO dropdowns (lists + statuses)
  document.getElementById('from-campaign-id')?.addEventListener('change', async () => {
    await loadListsForUI();
    await loadStatusesForUI();
    refreshBuilderHints();
  });

  document.addEventListener('DOMContentLoaded', function() {
    loadRules();
    updateFromStatusCount();
  });

  // Keep the custom multi-select toggling behavior (and keep counts in sync)
  const fromStatusSel = document.getElementById("from-status");
  fromStatusSel?.addEventListener("mousedown", (e) => {
    if (e.target && e.target.tagName === "OPTION") {
      e.preventDefault();
      e.target.selected = !e.target.selected;
      fromStatusSel.dispatchEvent(new Event("change", { bubbles: true }));
    }
  });
</script>

</body>
</html>
