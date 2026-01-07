<?php
/*
 * admin_campaign_list_mix_live.php
 * VICIdial Campaign List Mix Editor (Ytel-like)
 *
 * Table: vicidial_campaigns_list_mix
 * Fields:
 *  - vcl_id (PK)
 *  - vcl_name
 *  - campaign_id
 *  - list_mix_container (text)
 *  - mix_method enum('EVEN_MIX','IN_ORDER','RANDOM')
 *  - status enum('ACTIVE','INACTIVE')
 */

$startMS = microtime();
$php_script='admin_campaign_list_mix_live.php';

require("dbconnect_mysqli.php");
require("functions.php");
require("admin_header.php");

$campaign_id = isset($_REQUEST['campaign_id']) ? preg_replace('/[^-_0-9a-zA-Z]/', '', $_REQUEST['campaign_id']) : '';
$action      = isset($_POST['action']) ? $_POST['action'] : '';
$message     = '';
$error_msg   = '';

$READONLY = false; // production safety: never write to DB

if ($campaign_id === '') {
  $error_msg = "Missing campaign_id in URL. Example: ?campaign_id=9000";
}

// ---- Helpers ----
function clean_statuses_for_vici($raw) {
  // VICIdial expects statuses separated by spaces in UI; internally hopper converts spaces to "','"
  // We'll store in the same "space-separated tokens" style as commonly used in list_mix_container.
  $raw = strtoupper(trim((string)$raw));
  $raw = preg_replace('/[^A-Z0-9_\-\s]/', ' ', $raw);
  $raw = preg_replace('/\s+/', ' ', $raw);
  if ($raw === '') { $raw = 'NEW'; }
  return $raw;
}

function parse_list_mix_container($container) {
  // Returns array of rows: [ ['list_id'=>..., 'enabled'=>true, 'percent'=>int, 'statuses'=>"...", 'desc'=>""], ... ]
  // list_mix_container format is usually: list_id|<priority/unused>|percent|statusTokens:list_id|...|...|...
  $rows = [];
  $container = trim((string)$container);
  if ($container === '') return $rows;

  $steps = explode(':', $container);
  foreach ($steps as $step) {
    $step = trim($step);
    if ($step === '') continue;

    $parts = explode('|', $step);
    // Commonly:
    // [0]=list_id, [1]=something/placeholder, [2]=percent, [3]=statuses (space-separated)
    $list_id  = isset($parts[0]) ? preg_replace('/[^0-9]/', '', $parts[0]) : '';
    $slot1    = isset($parts[1]) ? $parts[1] : '';
    $percent  = isset($parts[2]) ? (int)$parts[2] : 0;
    $statuses = isset($parts[3]) ? $parts[3] : 'NEW';

    if ($list_id === '') continue;

    $rows[] = [
      'list_id'  => $list_id,
      'enabled'  => true,
      'percent'  => max(0, min(100, $percent)),
      'statuses' => clean_statuses_for_vici($statuses),
      'slot1'    => $slot1, // preserve what was there
      'desc'     => '',
    ];
  }
  return $rows;
}

function build_list_mix_container($rows) {
  // Build: list_id|slot1|percent|statuses : ...
  // Keep slot1 as "1" by default unless provided.
  $out = [];
  foreach ($rows as $r) {
    if (empty($r['enabled'])) continue;
    $list_id = preg_replace('/[^0-9]/', '', (string)($r['list_id'] ?? ''));
    if ($list_id === '') continue;

    $slot1 = (string)($r['slot1'] ?? '1');
    $slot1 = trim($slot1);
    if ($slot1 === '') $slot1 = '1';

    $percent = (int)($r['percent'] ?? 0);
    $percent = max(0, min(100, $percent));

    $statuses = clean_statuses_for_vici($r['statuses'] ?? 'NEW');

    $out[] = $list_id . '|' . $slot1 . '|' . $percent . '|' . $statuses;
  }
  return implode(':', $out);
}

function fetch_list_names($link, $list_ids) {
  $out = [];
  $clean = [];
  foreach ($list_ids as $id) {
    $id = preg_replace('/[^0-9]/','', (string)$id);
    if ($id !== '') $clean[$id] = true;
  }
  $ids = array_keys($clean);
  if (count($ids) === 0) return $out;

  // Build IN(?,?,?) safely
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $types = str_repeat('s', count($ids));
  $sql = "SELECT list_id, list_name FROM vicidial_lists WHERE list_id IN ($placeholders)";
  $stmt = mysqli_prepare($link, $sql);
  mysqli_stmt_bind_param($stmt, $types, ...$ids);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_bind_result($stmt, $lid, $lname);
  while (mysqli_stmt_fetch($stmt)) {
    $out[(string)$lid] = (string)$lname;
  }
  mysqli_stmt_close($stmt);
  return $out;
}
function validate_posted_mix_rows($link, $rows) {
  // $rows: array of ['list_id','enabled','percent','statuses','slot1',...]
  // returns ['errors'=>[], 'warns'=>[], 'total'=>int, 'enabled_rows'=>[], 'list_names'=>[]]

  $errors = [];
  $warns  = [];
  $total  = 0;

  // Normalize + keep enabled
  $enabled_rows = [];
  foreach ($rows as $idx => $r) {
    $n = $idx + 1;

    $list_id = preg_replace('/[^0-9]/', '', (string)($r['list_id'] ?? ''));
    $en      = !empty($r['enabled']);
    $pct     = (int)($r['percent'] ?? 0);
    $pct     = max(0, min(100, $pct));
    $sts     = clean_statuses_for_vici($r['statuses'] ?? 'NEW');

    if (!$en) continue;

    $enabled_rows[] = [
      'rownum'   => $n,
      'list_id'  => $list_id,
      'enabled'  => true,
      'percent'  => $pct,
      'statuses' => $sts,
      'slot1'    => '1',
    ];

    // Validation rules
    if ($list_id === '') $errors[] = "Row $n: List ID is required (enabled row).";
    if ($pct <= 0)       $warns[]  = "Row $n: % MIX is 0 (enabled row).";
    if ($pct > 100)      $errors[] = "Row $n: % MIX cannot be > 100.";
    if ($sts === '')     $warns[]  = "Row $n: Allowed Statuses empty; will default to NEW.";

    $total += $pct;
  }

  if ($total === 0 && count($enabled_rows) > 0) {
    $warns[] = "Total % for enabled rows is 0.";
  }
  if ($total > 100) {
    $warns[] = "Total % for enabled rows is $total (over 100). This may be intended, but usually isn’t.";
  }

  // Duplicate enabled list_id check
  $seen = [];
  foreach ($enabled_rows as $er) {
    if ($er['list_id'] === '') continue;
    if (isset($seen[$er['list_id']])) {
      $warns[] = "Duplicate enabled List ID {$er['list_id']} found (rows {$seen[$er['list_id']]} and {$er['rownum']}).";
    } else {
      $seen[$er['list_id']] = $er['rownum'];
    }
  }

  // Server-side “exists?” lookup from DB (authoritative)
  $list_names = fetch_list_names($link, array_column($enabled_rows, 'list_id'));
  foreach ($enabled_rows as $er) {
    if ($er['list_id'] === '') continue;
    if (!isset($list_names[$er['list_id']])) {
      $warns[] = "Row {$er['rownum']}: List ID {$er['list_id']} not found in vicidial_lists.";
    }
  }

  return [
    'errors'       => $errors,
    'warns'        => $warns,
    'total'        => $total,
    'enabled_rows' => $enabled_rows,
    'list_names'   => $list_names,
  ];
}


// ---- Load existing mix record (ACTIVE preferred; else newest/any) ----
$vcl_id = '';
$vcl_name = '';
$list_mix_container = '';
$mix_method = 'IN_ORDER';
$mix_status = 'INACTIVE';

if ($campaign_id !== '') {
  $stmt = "SELECT vcl_id, vcl_name, list_mix_container, mix_method, status
           FROM vicidial_campaigns_list_mix
           WHERE campaign_id=? ORDER BY (status='ACTIVE') DESC, vcl_id DESC LIMIT 1";
  $s = mysqli_prepare($link, $stmt);
  mysqli_stmt_bind_param($s, "s", $campaign_id);
  mysqli_stmt_execute($s);
  mysqli_stmt_bind_result($s, $vcl_id, $vcl_name, $list_mix_container, $mix_method, $mix_status);
  mysqli_stmt_fetch($s);
  mysqli_stmt_close($s);

  if (!$mix_method) $mix_method = 'IN_ORDER';
  if (!$mix_status) $mix_status = 'INACTIVE';
}

// ---- Handle Save / Preview ----
if ($action === 'save' && $campaign_id !== '') {
$message = "SERVER HIT: save action reached at " . date('Y-m-d H:i:s');

  $posted_rows = [];
  $list_ids  = isset($_POST['list_id']) ? (array)$_POST['list_id'] : [];
  $enableds  = isset($_POST['enabled']) ? (array)$_POST['enabled'] : [];
  $percents  = isset($_POST['percent']) ? (array)$_POST['percent'] : [];
  $statuses  = isset($_POST['statuses']) ? (array)$_POST['statuses'] : [];

  // include enableds in count so indexes align
  $count = max(count($list_ids), count($percents), count($statuses), count($enableds));
  for ($i=0; $i<$count; $i++) {
    $posted_rows[] = [
      'list_id'  => $list_ids[$i] ?? '',
      'enabled'  => isset($enableds[$i]) && ($enableds[$i] === '1'),
      'percent'  => $percents[$i] ?? 0,
      'statuses' => $statuses[$i] ?? 'NEW',
      'slot1'    => '1',
    ];
  }

  $new_mix_method = isset($_POST['mix_method']) ? $_POST['mix_method'] : 'IN_ORDER';
  if (!in_array($new_mix_method, ['EVEN_MIX','IN_ORDER','RANDOM'], true)) {
    $new_mix_method = 'IN_ORDER';
  }

  // Build container from posted rows (enabled-only is enforced inside build_list_mix_container)
  $new_container = build_list_mix_container($posted_rows);

  // Totals + validation arrays
  $total  = 0;
  $errors = [];
  $warns  = [];

  // Collect enabled list_ids for server-side existence check
  $enabled_list_ids = [];
  $seen = []; // for duplicate detection

  foreach ($posted_rows as $idx => $r) {
    $rownum = $idx + 1;
    if (empty($r['enabled'])) continue;

    $list_id = preg_replace('/[^0-9]/', '', (string)($r['list_id'] ?? ''));
    $pct     = (int)($r['percent'] ?? 0);
    $pct     = max(0, min(100, $pct));
    $sts     = clean_statuses_for_vici($r['statuses'] ?? 'NEW');

    $total += $pct;

    if ($list_id === '') $errors[] = "Row $rownum: List ID is required (enabled row).";
    if ($pct <= 0)       $warns[]  = "Row $rownum: % MIX is 0 (enabled row).";
    if ($pct > 100)      $errors[] = "Row $rownum: % MIX cannot be > 100.";
    if ($sts === '')     $warns[]  = "Row $rownum: Allowed Statuses empty; will default to NEW.";

    if ($list_id !== '') {
      $enabled_list_ids[] = $list_id;

      if (isset($seen[$list_id])) {
        $warns[] = "Duplicate enabled List ID $list_id found (rows {$seen[$list_id]} and $rownum).";
      } else {
        $seen[$list_id] = $rownum;
      }
    }
  }

  if ($total === 0 && count($enabled_list_ids) > 0) $warns[] = "Total % for enabled rows is 0.";
  if ($total > 100) $warns[] = "Total % for enabled rows is $total (over 100). This may be intended, but usually isn’t.";

  // Server-side list existence check (authoritative)
  $server_list_names = fetch_list_names($link, $enabled_list_ids); // returns [list_id => list_name]
  foreach ($enabled_list_ids as $lid) {
    if (!isset($server_list_names[$lid])) {
      // Find first row number for this lid for nicer message
      $rownum = $seen[$lid] ?? '?';
      $warns[] = "Row $rownum: List ID $lid not found in vicidial_lists.";
    }
  }

  if (!empty($READONLY)) {
    // PRODUCTION SAFE: preview only, no DB UPDATE/INSERT
    $lines = [];
    $lines[] = "READ-ONLY MODE (Production Safe): No DB changes were made.";
    $lines[] = "Mix Method: $new_mix_method | Total % (enabled rows): $total";
    $lines[] = "";

    if (count($errors)) {
      $lines[] = "ERRORS:";
      foreach ($errors as $e) $lines[] = "- $e";
      $lines[] = "";
      $error_msg = "Preview blocked due to validation errors. Fix the errors and try again.";
    }

    if (count($warns)) {
      $lines[] = "WARNINGS:";
      foreach ($warns as $w) $lines[] = "- $w";
      $lines[] = "";
    }

    $lines[] = "Would save list_mix_container:";
    $lines[] = $new_container;

    $message = implode("\n", $lines);

    // Keep UI showing current posted values
    $rows = [];
    foreach ($posted_rows as $r) {
      $rows[] = [
        'list_id'  => preg_replace('/[^0-9]/', '', (string)$r['list_id']),
        'enabled'  => !empty($r['enabled']),
        'percent'  => (int)$r['percent'],
        'statuses' => clean_statuses_for_vici($r['statuses']),
        'slot1'    => '1',
        'desc'     => '',
      ];
    }
    $mix_method = $new_mix_method;

    // IMPORTANT: don't reload from DB in READONLY
  } else {
    // If record exists, update; else insert.
    if ($vcl_id !== '') {
      $upd = "UPDATE vicidial_campaigns_list_mix
              SET list_mix_container=?, mix_method=?, status='ACTIVE'
              WHERE vcl_id=? LIMIT 1";
      $u = mysqli_prepare($link, $upd);
      mysqli_stmt_bind_param($u, "sss", $new_container, $new_mix_method, $vcl_id);
      mysqli_stmt_execute($u);
      mysqli_stmt_close($u);
    } else {
      // create a vcl_id if absent
      $generated_vcl_id = 'VCL' . $campaign_id;
      $ins = "INSERT INTO vicidial_campaigns_list_mix
              (vcl_id, vcl_name, campaign_id, list_mix_container, mix_method, status)
              VALUES (?, ?, ?, ?, ?, 'ACTIVE')";
      $name = "Campaign $campaign_id Mix";
      $i = mysqli_prepare($link, $ins);
      mysqli_stmt_bind_param($i, "sssss", $generated_vcl_id, $name, $campaign_id, $new_container, $new_mix_method);
      mysqli_stmt_execute($i);
      mysqli_stmt_close($i);
    }

    // Reload for display
    $stmt = "SELECT vcl_id, vcl_name, list_mix_container, mix_method, status
             FROM vicidial_campaigns_list_mix
             WHERE campaign_id=? ORDER BY (status='ACTIVE') DESC, vcl_id DESC LIMIT 1";
    $s = mysqli_prepare($link, $stmt);
    mysqli_stmt_bind_param($s, "s", $campaign_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_bind_result($s, $vcl_id, $vcl_name, $list_mix_container, $mix_method, $mix_status);
    mysqli_stmt_fetch($s);
    mysqli_stmt_close($s);

    $message = "Saved. (Total %: $total)";
  }
}


// Build UI rows from container only if not already set by READONLY postback
if (!isset($rows) || !is_array($rows)) {
  $rows = parse_list_mix_container($list_mix_container);
}

// If empty, seed one row
if (count($rows) === 0) {
  $rows[] = ['list_id'=>'', 'enabled'=>true, 'percent'=>0, 'statuses'=>'NEW', 'slot1'=>'1', 'desc'=>''];
}

$list_names = fetch_list_names($link, array_column($rows, 'list_id'));


?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Data Mix<?php echo $campaign_id ? " - " . htmlspecialchars($campaign_id) : ""; ?></title>
  <link rel="stylesheet" type="text/css" href="vicidial_styles.css">
  <style>
    body { font-family: Arial, sans-serif; font-size: 12px; }
    .mix-container { margin: 12px 18px; background: #fff; border: 1px solid #ccc; padding: 14px; max-width: 1200px; }
    .mix-header { display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px; }
    .mix-header-left { font-weight:bold; }
    .mix-header-right { font-size: 11px; color:#555; }
    .mix-table { border-collapse: collapse; width: 100%; margin-top: 10px; }
    .mix-table th, .mix-table td { border: 1px solid #d0d0d0; padding: 4px 6px; font-size: 11px; }
    .mix-table th { background: #eee; }
    .mix-table input[type="text"], .mix-table input[type="number"] { width: 98%; box-sizing:border-box; font-size: 11px; padding: 2px 4px; }
    .toggle { text-align:center; }
    .toggle input { transform: scale(1.15); cursor: pointer; }
    .actions { text-align:center; white-space:nowrap; }
    .btn { padding: 3px 8px; font-size: 11px; cursor:pointer; border-radius:3px; border:1px solid #888; background:#f5f5f5; }
    .btn-add { color:#0a0; border-color:#0a0; }
    .btn-remove { color:#a00; border-color:#a00; }
    .footer { margin-top: 10px; font-size: 11px; color:#555; display:flex; justify-content:space-between; align-items:center; gap:12px; }
    .note { display:block; margin-top:4px; color:#777; }
    .pill { display:inline-block; padding:2px 6px; border:1px solid #bbb; border-radius:10px; font-size:11px; background:#fafafa; }
    .msg { margin: 10px 0; padding: 8px 10px; border: 1px solid #7bb07b; background: #e9f7e9; }
    .err { margin: 10px 0; padding: 8px 10px; border: 1px solid #cc6b6b; background: #ffe9e9; }
    .toprow { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
    pre { white-space: pre-wrap; margin: 0; }

.row-bad td { background: #fff3f3; }
.row-warn td { background: #fffbe6; }
.small { font-size: 11px; color:#555; }

  </style>
</head>
<body>

<div class="mix-container">
  <div class="mix-header">
    <div class="mix-header-left">
      Data Mix
      <?php if ($campaign_id) { echo " <span class='pill'>Campaign: " . htmlspecialchars($campaign_id) . "</span>"; } ?>
      <?php if ($vcl_id) { echo " <span class='pill'>VCL: " . htmlspecialchars($vcl_id) . "</span>"; } ?>
      <?php if ($mix_status) { echo " <span class='pill'>Status: " . htmlspecialchars($mix_status) . "</span>"; } ?>
      <?php if (!empty($READONLY)) { echo " <span class='pill'>READ-ONLY</span>"; } ?>
    </div>
    <div class="mix-header-right">
      Uses VICIdial hopper table: <code>vicidial_campaigns_list_mix</code>
    </div>
  </div>

  <?php if ($error_msg): ?>
    <div class="err"><?php echo htmlspecialchars($error_msg); ?></div>
  <?php endif; ?>

  <?php if ($message): ?>
    <div class="msg"><pre><?php echo htmlspecialchars($message); ?></pre></div>
  <?php endif; ?>

  <form method="POST" action="<?php echo htmlspecialchars($php_script); ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="campaign_id" value="<?php echo htmlspecialchars($campaign_id); ?>">

    <div class="toprow">
      <div>
        <strong>Mix Method:</strong>
        <select id="mix-method" name="mix_method">
          <option value="EVEN_MIX" <?php if ($mix_method==='EVEN_MIX') echo 'selected'; ?>>EVEN_MIX</option>
          <option value="RANDOM" <?php if ($mix_method==='RANDOM') echo 'selected'; ?>>RANDOM</option>
          <option value="IN_ORDER" <?php if ($mix_method==='IN_ORDER') echo 'selected'; ?>>IN_ORDER</option>
        </select>
      </div>
      <div>
        <strong>Total %:</strong> <span id="total-percent">0</span>%
        <span class="note">Tip: Total % doesn’t have to be 100, but that’s usually intended.</span>
      </div>
      <div style="margin-left:auto;">
        <button type="button" class="btn btn-add" onclick="addRow();">ADD ROW</button>
        <button type="submit" class="btn"><?php echo !empty($READONLY) ? 'PREVIEW' : 'SAVE'; ?></button>
      </div>
    </div>

<div id="mix-warnings" class="err" style="display:none;"></div>


    <table class="mix-table">
      <thead>
        <tr>
          <th style="width: 80px;">List ID</th>
          <th style="width: 70px;">Enabled</th>
          <th>Description</th>
          <th style="width: 70px;">% MIX</th>
          <th>Allowed Statuses</th>
          <th style="width: 110px;">Actions</th>
        </tr>
      </thead>
      <tbody id="mix-rows">
        <?php foreach ($rows as $r): ?>
          <tr class="mix-row">
            <td><input type="text" name="list_id[]" value="<?php echo htmlspecialchars($r['list_id']); ?>"></td>
            <td class="toggle">
  <input type="hidden" name="enabled[]" value="<?php echo !empty($r['enabled']) ? '1' : '0'; ?>">

  <input type="checkbox" value="1" <?php echo !empty($r['enabled']) ? 'checked' : ''; ?>
         onclick="this.previousElementSibling.value = this.checked ? '1' : '0';">
</td>

            <td class="desc-cell">
  <?php
    $lid = (string)$r['list_id'];
    echo htmlspecialchars($list_names[$lid] ?? 'Unknown List');
  ?>
</td>

            <td><input type="number" name="percent[]" value="<?php echo (int)$r['percent']; ?>" min="0" max="100"></td>
            <td><input type="text" name="statuses[]" value="<?php echo htmlspecialchars($r['statuses']); ?>"></td>
            <td class="actions">
              <button type="button" class="btn btn-add" onclick="addRowAfter(this);">ADD</button>
              <button type="button" class="btn btn-remove" onclick="removeRow(this);">REMOVE</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </form>

  <div class="footer">
    <div>
      <div class="note">
        Stored format example: <code>101|1|50|NEW N A B -:102|1|50|NEW N A B -</code>
      </div>
    </div>
  </div>
</div>

<script>
// --- Totals ---
function recalcTotal() {
  var rows = document.querySelectorAll('tr.mix-row');
  var total = 0;

  rows.forEach(function (tr) {
    var pct = tr.querySelector('input[name="percent[]"]');
    var en  = tr.querySelector('input[type="hidden"][name="enabled[]"]');
    if (!pct || !en) return;
    if (en.value !== '1') return;

    var v = parseInt(pct.value, 10);
    if (!isNaN(v)) total += v;
  });

  var el = document.getElementById('total-percent');
  if (el) el.textContent = total;
}

// --- Row template + actions ---
function addRowTemplate() {
  var tr = document.createElement('tr');
  tr.className = 'mix-row';
  tr.innerHTML = `
    <td><input type="text" name="list_id[]" value=""></td>
    <td class="toggle">
      <input type="hidden" name="enabled[]" value="1">
      <input type="checkbox" checked value="1"
             onclick="this.previousElementSibling.value = this.checked ? '1' : '0'; validateMixUI(); recalcTotal();">
    </td>
    <td class="desc-cell" data-found="0"></td>
    <td><input type="number" name="percent[]" value="0" min="0" max="100"></td>
    <td><input type="text" name="statuses[]" value="NEW"></td>
    <td class="actions">
      <button type="button" class="btn btn-add" onclick="addRowAfter(this);">ADD</button>
      <button type="button" class="btn btn-remove" onclick="removeRow(this);">REMOVE</button>
    </td>
  `;
  return tr;
}

function addRow() {
  var tbody = document.getElementById('mix-rows');
  tbody.appendChild(addRowTemplate());
  validateMixUI();
  recalcTotal();
}

function addRowAfter(btn) {
  var tr = btn.closest('tr');
  if (!tr) return;
  var newTr = addRowTemplate();
  tr.parentNode.insertBefore(newTr, tr.nextSibling);
  validateMixUI();
  recalcTotal();
}

function removeRow(btn) {
  var tr = btn.closest('tr');
  if (!tr) return;
  var tbody = tr.parentNode;
  if (tbody.querySelectorAll('tr.mix-row').length <= 1) {
    alert('At least one row is required.');
    return;
  }
  tbody.removeChild(tr);
  validateMixUI();
  recalcTotal();
}

// --- Description + lookup ---
function setRowDesc(tr, text, found) {
  var cell = tr.querySelector('td.desc-cell');
  if (!cell) return;

  cell.textContent = text || '';

  // found: true => exists, false => not found, null/undefined => don't change current state
  if (found === true) cell.dataset.found = '1';
  else if (found === false) cell.dataset.found = '0';
}

async function lookupListName(listId) {
  var url = 'admin_campaign_list_mix_lookup.php?list_id=' + encodeURIComponent(listId);
  var res = await fetch(url, { credentials: 'same-origin' });
  if (!res.ok) return null;
  var data = await res.json();
  if (data && data.ok) return data.list_name;
  return null;
}

function scheduleRowLookup(inputEl) {
  var tr = inputEl.closest('tr');
  if (!tr) return;

  // keep only digits in the input
  var listId = (inputEl.value || '').replace(/[^0-9]/g, '');
  if (inputEl.value !== listId) inputEl.value = listId;

  if (listId.length === 0) {
    setRowDesc(tr, '', false);
    validateMixUI();
    return;
  }

  // debounce per-row
  if (tr._mixLookupTimer) clearTimeout(tr._mixLookupTimer);

  tr._mixLookupTimer = setTimeout(async function () {
    setRowDesc(tr, 'Looking up...', null);

    var name = await lookupListName(listId);
    if (name) setRowDesc(tr, name, true);
    else setRowDesc(tr, 'Unknown List', false);

    validateMixUI();
  }, 250);
}

// Live update desc when typing/leaving List ID field
document.addEventListener('input', function (e) {
  if (e.target && e.target.name === 'list_id[]') scheduleRowLookup(e.target);
});
document.addEventListener('blur', function (e) {
  if (e.target && e.target.name === 'list_id[]') scheduleRowLookup(e.target);
}, true);

// --- Validation ---
function normalizeStatuses(raw) {
  raw = (raw || '').toUpperCase().trim();
  raw = raw.replace(/[^A-Z0-9_\-\s]/g, ' ');
  raw = raw.replace(/\s+/g, ' ').trim();
  if (!raw) raw = 'NEW';
  return raw;
}

function getRowData(tr) {
  const listId = (tr.querySelector('input[name="list_id[]"]')?.value || '').replace(/[^0-9]/g,'');
  const pctRaw = tr.querySelector('input[name="percent[]"]')?.value ?? '0';
  const pct = parseInt(pctRaw, 10);
  const en = tr.querySelector('input[type="hidden"][name="enabled[]"]')?.value === '1';
  const statusesRaw = tr.querySelector('input[name="statuses[]"]')?.value || '';
  const statuses = normalizeStatuses(statusesRaw);
  return { listId, pct: isNaN(pct) ? 0 : pct, en, statuses };
}

function escapeHtml(s){
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function validateMixUI() {
  const panel = document.getElementById('mix-warnings');
  const rows = Array.from(document.querySelectorAll('tr.mix-row'));

  let total = 0;
  let errors = [];
  let warns = [];

  rows.forEach(r => r.classList.remove('row-bad','row-warn'));

  rows.forEach((tr, idx) => {
    const n = idx + 1;
    const d = getRowData(tr);

    if (!d.en) return;

    total += d.pct;

    let rowErrors = [];
    let rowWarns = [];

    if (!d.listId) rowErrors.push(`Row ${n}: List ID is required (enabled row).`);
    if (d.pct <= 0) rowWarns.push(`Row ${n}: % MIX is 0 (enabled row).`);
    if (d.pct > 100) rowErrors.push(`Row ${n}: % MIX cannot be > 100.`);
    if (!d.statuses) rowWarns.push(`Row ${n}: Allowed Statuses empty; will default to NEW.`);

    // Use data-found instead of text comparison (prevents false positives)
    const descCell = tr.querySelector('td.desc-cell');
    const found = descCell ? (descCell.dataset.found === '1') : true; // if missing, assume ok
    if (d.listId && !found) rowWarns.push(`Row ${n}: List ID ${d.listId} not found in vicidial_lists.`);

    if (rowErrors.length) {
      tr.classList.add('row-bad');
      errors.push(...rowErrors);
    } else if (rowWarns.length) {
      tr.classList.add('row-warn');
      warns.push(...rowWarns);
    }
  });

  if (total === 0) warns.unshift('Total % for enabled rows is 0.');
  if (total > 100) warns.unshift(`Total % for enabled rows is ${total} (over 100). This may be intended, but usually isn’t.`);

  const totalEl = document.getElementById('total-percent');
  if (totalEl) totalEl.textContent = total;

  if (panel) {
    if (errors.length || warns.length) {
      panel.style.display = 'block';
      panel.innerHTML =
        `<div><b>Validation</b></div>` +
        (errors.length ? `<div class="small"><b>Errors</b><br>${errors.map(e=>escapeHtml(e)).join('<br>')}</div>` : '') +
        (warns.length ? `<div class="small" style="margin-top:6px;"><b>Warnings</b><br>${warns.map(w=>escapeHtml(w)).join('<br>')}</div>` : '');
    } else {
      panel.style.display = 'none';
      panel.innerHTML = '';
    }
  }

  return { total, errors, warns };
}

// Run validation/totals whenever inputs change
document.addEventListener('input', function(e){
  if (!e.target) return;
  if (['list_id[]','percent[]','statuses[]'].includes(e.target.name)) {
    validateMixUI();
    recalcTotal();
  }
});
document.addEventListener('change', function(e){
  if (!e.target) return;
  if (e.target.type === 'checkbox') {
    validateMixUI();
    recalcTotal();
  }
});
document.addEventListener('DOMContentLoaded', function(){
  // mark existing server-rendered desc cells as found/not found based on text (optional but helpful)
  document.querySelectorAll('td.desc-cell').forEach(cell => {
    if (!cell.dataset.found) {
      const t = (cell.textContent || '').trim().toLowerCase();
      cell.dataset.found = (t && t !== 'unknown list') ? '1' : '0';
    }
  });
  validateMixUI();
  recalcTotal();
});

// Prevent preview if errors exist
document.querySelector('form')?.addEventListener('submit', function(e){
  const v = validateMixUI();
  if (v.errors.length) {
    e.preventDefault();
    alert('Fix validation errors before previewing.');
  }
});
</script>


</body>
</html>