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
          <label>&nbsp;</label>
          <div class="ytel-help">Example: 09:30</div>
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
