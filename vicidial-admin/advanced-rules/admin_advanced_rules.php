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

  <!-- VICIdial core stylesheet -->
  <link rel="stylesheet" type="text/css" href="vicidial_stylesheet.php">

  <!-- Inline UI styles (2-file setup: PHP + JS only) -->
  <style>
body {
  font-family: Arial, sans-serif;
  font-size: 12px;
}
.rules-container {
  margin: 12px 18px;
  background: #fff;
  border: 1px solid #ccc;
  padding: 14px;
  max-width: 1200px;
}
.rules-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}
.rules-header-left {
  font-weight: bold;
}
.rules-header-right {
  font-size: 11px;
  color: #555;
}
.rules-table {
  border-collapse: collapse;
  width: 100%;
  margin-top: 10px;
}
.rules-table th,
.rules-table td {
  border: 1px solid #d0d0d0;
  padding: 4px 6px;
  font-size: 11px;
}
.rules-table th {
  background: #eee;
}
.btn {
  padding: 3px 8px;
  font-size: 11px;
  cursor: pointer;
  border-radius: 3px;
  border: 1px solid #888;
  background: #f5f5f5;
}
.btn:hover {
  background: #e7e7e7;
}
.pill {
  display: inline-block;
  padding: 2px 6px;
  border: 1px solid #bbb;
  border-radius: 10px;
  font-size: 11px;
  background: #fafafa;
}
.msg {
  margin: 10px 0;
  padding: 8px 10px;
  border: 1px solid #7bb07b;
  background: #e9f7e9;
  white-space: pre-wrap;
}
.err {
  margin: 10px 0;
  padding: 8px 10px;
  border: 1px solid #cc6b6b;
  background: #ffe9e9;
  white-space: pre-wrap;
}
.sample-block {
  margin-top: 10px;
}
.sample-table {
  border-collapse: collapse;
  width: 100%;
  margin-top: 6px;
}
.sample-table th,
.sample-table td {
  border: 1px solid #d0d0d0;
  padding: 3px 4px;
  font-size: 11px;
}
.sample-table th {
  background: #f4f4f4;
}
.status-badge {
  padding: 1px 4px;
  border-radius: 3px;
  background-color: #eee;
  border: 1px solid #ccc;
  font-size: 10px;
}
.toprow {
  display: flex;
  gap: 12px;
  align-items: center;
  flex-wrap: wrap;
}
.small {
  font-size: 11px;
  color: #555;
}

/* --- Modal (Ytel-style) --- */
#rule-modal {
  display: none;
  position: fixed;
  left: 0;
  top: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.35);
  z-index: 9999;
  overflow: auto;
}
#rule-modal .box {
  background: #fff;
  width: 1100px;
  max-width: 96vw;
  margin: 30px auto;
  padding: 14px;
  border: 1px solid #ccc;
}

/* IMPORTANT: minmax(0,1fr) prevents grid overflow */
.ytel-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 16px;
  margin-top: 10px;
}

/* Allow grid items to shrink instead of overflowing */
.ytel-card {
  border: 1px solid #d0d0d0;
  padding: 10px;
  background: #fff;
  min-width: 0;
  overflow: hidden;
}

.ytel-title {
  font-weight: bold;
  margin-bottom: 8px;
}

.ytel-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 12px;
  margin-bottom: 10px;
}

.ytel-field {
  min-width: 0;
}

.ytel-field label {
  display: block;
  font-size: 11px;
  color: #333;
  margin-bottom: 3px;
}

/* label helper for inline count pill */
.label-flex {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.label-flex .pill {
  font-size: 10px;
  padding: 1px 6px;
}

/* Ensure controls can shrink inside grid columns */
.ytel-field input[type="text"],
.ytel-field input[type="number"],
.ytel-field input[type="datetime-local"],
.ytel-field input[type="time"],
.ytel-field select,
.ytel-field textarea {
  width: 100%;
  box-sizing: border-box;
  min-width: 0;
}

.ytel-help {
  font-size: 10px;
  color: #777;
  margin-top: 3px;
}
.ytel-divider {
  height: 1px;
  background: #eee;
  margin: 10px 0;
}
.ytel-warn {
  border: 1px solid #e0b84a;
  background: #fff7da;
  padding: 8px;
  font-size: 11px;
  margin-top: 8px;
}
.ytel-error {
  border: 1px solid #cc6b6b;
  background: #ffe9e9;
  padding: 8px;
  font-size: 11px;
  margin-top: 8px;
  display: none;
}
.ytel-actionsbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 12px;
}
.ytel-actionsbar .right {
  display: flex;
  gap: 8px;
}
.ytel-advanced {
  display: none;
  margin-top: 10px;
}
.ytel-advanced textarea {
  height: 160px;
  font-family: monospace;
  width: 100%;
  box-sizing: border-box;
  min-width: 0;
}

/* Fix compare rows: allow wrapping so fixed-width select doesn't force overflow */
.ytel-field > div[style*="display:flex"] {
  flex-wrap: wrap;
}
.ytel-field > div[style*="display:flex"] > select {
  flex: 0 0 170px;
  max-width: 170px;
}
.ytel-field > div[style*="display:flex"] > input {
  flex: 1 1 140px;
  min-width: 120px;
}

/* Optional: collapse to one column on smaller widths */
@media (max-width: 1100px) {
  .ytel-grid {
    grid-template-columns: 1fr;
  }
  .ytel-row {
    grid-template-columns: 1fr;
  }
}
  </style>

  <script>
    // Provided by PHP so the JS file can stay static
    window.RULES_API_BASE = <?php echo json_encode($RULES_API_BASE); ?>;
    window.RULES_API_KEY  = <?php echo json_encode($RULES_API_KEY); ?>;
  </script>

  <!-- Single JS file -->
  <script src="advanced_rules/rules_ui.js?v=<?php echo time(); ?>"></script>
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
          <label>Preferred time of day (optional)</label>
          <input id="sched-time-of-day" type="time" />
          <div class="ytel-help">If Next execution time is empty, we will compute the next run at this time.</div>
        </div>
        <div class="ytel-field">
          <label>Time zone</label>
          <select id="sched-timezone">
            <option value="">(Use browser default)</option>
            <option value="UTC">UTC</option>
            <option value="America/New_York">America/New_York (ET)</option>
            <option value="America/Chicago">America/Chicago (CT)</option>
            <option value="America/Denver">America/Denver (MT)</option>
            <option value="America/Los_Angeles">America/Los_Angeles (PT)</option>
            <option value="Asia/Kolkata">Asia/Kolkata (IST)</option>
          </select>
          <div class="ytel-help">UI shows and saves scheduling times in this time zone (stored as UTC).</div>
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
            <div class="ytel-help">Selecting a campaign filters Statuses (lists show all lists).</div>
          </div>

          <div class="ytel-field">
            <label class="label-flex">
              <span>From List</span>
              <span id="from-list-count" class="pill">0 selected</span>
            </label>
            <select id="from-list-id" multiple size="8" style="height:140px;"></select>
            <div class="ytel-help">Maps to <code>list_id</code> (supports multi). No selection = All lists.</div>
          </div>
        </div>

        <div class="ytel-row">
          <div class="ytel-field">
            <label class="label-flex">
              <span>From Status</span>
              <span id="from-status-count" class="pill">0 selected</span>
            </label>
            <select id="from-status" name="from_status[]" multiple size="8" style="height:140px;"></select>
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

        <div class="ytel-row">
          <div class="ytel-field">
            <label>At least # days since last local call time (shortcut)</label>
            <input id="from-days-lastcall" type="number" min="0" placeholder="Leave empty = ignore" />
            <div class="ytel-help">Uses <code>last_local_call_time</code> + <code>OLDER_THAN_DAYS</code></div>
          </div>
        </div>

        <div class="ytel-row">
          <div class="ytel-field">
            <label>Called count</label>
            <div style="display:flex; gap:8px;">
              <select id="cc-op" style="width:170px;">
                <option value="">Do not apply</option>
                <option value="=">equal(=)</option>
                <option value="!=">not equal(≠)</option>
                <option value=">=">greater than or equal(>=)</option>
                <option value=">">greater than(>)</option>
                <option value="<=">less than or equal(<=)</option>
                <option value="<">less than(&lt;)</option>
                <option value="IN">In (CSV)</option>
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
                <option value=">">greater than(>)</option>
                <option value="<=">less than or equal(<=)</option>
                <option value="<">less than(&lt;)</option>
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
                <option value=">">greater than(>)</option>
                <option value="<=">less than or equal(<=)</option>
                <option value="<">less than(&lt;)</option>
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
            <label>To List ID</label>
            <select id="to-list-id">
              <option value="">Leave empty = no change</option>
            </select>
            <div class="ytel-help">Maps to <code>actions.move_to_list</code></div>
          </div>
          <div class="ytel-field">
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

</body>
</html>
