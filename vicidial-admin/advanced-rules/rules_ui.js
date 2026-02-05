/* /vicidial/advanced_rules/rules_ui.js
   Drop-in corrected version (includes working Preferred time <-> Next execution sync + TZ-safe UTC storage).
   Requires:
     window.RULES_API_BASE
     window.RULES_API_KEY
*/

const API_BASE = window.RULES_API_BASE || ''
const API_KEY = window.RULES_API_KEY || ''

let editingRuleId = null

// datetime-local <-> SQL DATETIME (ROBUST)
function dtLocalToSql (v) {
  if (!v) return null
  const s = String(v).trim()
  if (!s) return null

  // YYYY-MM-DDTHH:MM
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(s))
    return s.replace('T', ' ') + ':00'
  // YYYY-MM-DDTHH:MM:SS
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(s))
    return s.replace('T', ' ')
  // Already SQL datetime
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(s)) return s

  // Fallback: normalize first 16 chars
  if (s.includes('T')) {
    const base = s.slice(0, 16)
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(base))
      return base.replace('T', ' ') + ':00'
  }
  return null
}

function sqlToDtLocal (v) {
  if (!v) return ''
  const s = String(v)
  if (s.includes('T')) return s.slice(0, 16)
  if (s.includes(' ')) return s.replace(' ', 'T').slice(0, 16)
  return ''
}

// ---------- Scheduling time zone helpers ----------
// Store/handle scheduling datetimes as UTC SQL strings ("YYYY-MM-DD HH:mm:ss").
// UI shows/accepts them in a user-selected IANA TZ (e.g. "America/New_York").
// Avoids "adds 5/8 hours" issues from naive SQL parsing.

function getBrowserTimeZone () {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
  } catch {
    return 'UTC'
  }
}

function getScheduleTimeZone () {
  const el = document.getElementById('sched-timezone')
  const v = (el?.value || '').trim()
  return v || getBrowserTimeZone()
}

// Ensure a select can accept an arbitrary value (adds option if missing)
function setSelectValueSafe (id, value) {
  const el = document.getElementById(id)
  if (!el) return
  const v = String(value || '')
  if (v && !Array.from(el.options).some(o => String(o.value) === v)) {
    const opt = document.createElement('option')
    opt.value = v
    opt.textContent = v
    el.appendChild(opt)
  }
  el.value = v
}

function pad2 (n) {
  return String(n).padStart(2, '0')
}

function toSqlUtc (date) {
  return (
    date.getUTCFullYear() +
    '-' +
    pad2(date.getUTCMonth() + 1) +
    '-' +
    pad2(date.getUTCDate()) +
    ' ' +
    pad2(date.getUTCHours()) +
    ':' +
    pad2(date.getUTCMinutes()) +
    ':00'
  )
}

// Parse "YYYY-MM-DDTHH:mm" into parts
function parseDtLocalParts (dtLocal) {
  const m = String(dtLocal || '')
    .trim()
    .match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/)
  if (!m) return null
  return {
    y: parseInt(m[1], 10),
    mo: parseInt(m[2], 10),
    d: parseInt(m[3], 10),
    h: parseInt(m[4], 10),
    mi: parseInt(m[5], 10)
  }
}

function getZonedParts (date, timeZone) {
  const dtf = new Intl.DateTimeFormat('en-US', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false
  })
  const parts = dtf.formatToParts(date)
  const get = type => parts.find(p => p.type === type)?.value
  return {
    y: parseInt(get('year'), 10),
    mo: parseInt(get('month'), 10),
    d: parseInt(get('day'), 10),
    h: parseInt(get('hour'), 10),
    mi: parseInt(get('minute'), 10),
    s: parseInt(get('second'), 10)
  }
}

// Convert a wall-clock datetime in a given IANA TZ to a real UTC Date.
// Uses a small iterative correction loop to handle DST transitions.
function zonedPartsToUtcDate (wanted, timeZone) {
  // initial guess: treat wanted as UTC
  let guess = new Date(
    Date.UTC(wanted.y, wanted.mo - 1, wanted.d, wanted.h, wanted.mi, 0)
  )

  for (let i = 0; i < 3; i++) {
    const got = getZonedParts(guess, timeZone)

    const wantedAsUtc = Date.UTC(
      wanted.y,
      wanted.mo - 1,
      wanted.d,
      wanted.h,
      wanted.mi,
      0
    )
    const gotAsUtc = Date.UTC(got.y, got.mo - 1, got.d, got.h, got.mi, 0)

    const diffMs = wantedAsUtc - gotAsUtc
    if (diffMs === 0) break
    guess = new Date(guess.getTime() + diffMs)
  }

  return guess
}

// dt-local ("YYYY-MM-DDTHH:mm") in selected TZ -> UTC SQL ("YYYY-MM-DD HH:mm:ss")
function schedDtLocalToSqlUtc (dtLocal, timeZone) {
  const wanted = parseDtLocalParts(dtLocal)
  if (!wanted) return null
  const utc = zonedPartsToUtcDate(wanted, timeZone)
  return toSqlUtc(utc)
}

// UTC SQL ("YYYY-MM-DD HH:mm:ss") -> dt-local ("YYYY-MM-DDTHH:mm") shown in selected TZ
function sqlUtcToSchedDtLocal (sqlUtc, timeZone) {
  if (!sqlUtc) return ''
  const s = String(sqlUtc).trim()
  const iso = s.includes('T') ? s : s.replace(' ', 'T')
  const d = new Date(iso.endsWith('Z') ? iso : iso + 'Z')
  if (isNaN(d.getTime())) return ''
  const z = getZonedParts(d, timeZone)
  return (
    z.y +
    '-' +
    pad2(z.mo) +
    '-' +
    pad2(z.d) +
    'T' +
    pad2(z.h) +
    ':' +
    pad2(z.mi)
  )
}

// compute nextExecAt from time-of-day (HH:mm) in selected TZ, returning UTC SQL
function computeNextExecAtFromTimeOfDay (timeHHMM, timeZone) {
  if (!timeHHMM) return null
  const parts = String(timeHHMM).split(':')
  if (parts.length < 2) return null

  const hh = parseInt(parts[0], 10)
  const mm = parseInt(parts[1], 10)
  if (!Number.isFinite(hh) || !Number.isFinite(mm)) return null

  const tz = timeZone || getScheduleTimeZone()

  // "now" in selected TZ
  const nowUtc = new Date()
  const nowZ = getZonedParts(nowUtc, tz)

  const y = nowZ.y
  const mo = nowZ.mo
  const d = nowZ.d

  // compare desired wall time to current wall time
  const nowWall = Date.UTC(nowZ.y, nowZ.mo - 1, nowZ.d, nowZ.h, nowZ.mi, 0)
  const desiredWall = Date.UTC(y, mo - 1, d, hh, mm, 0)

  let target = { y, mo, d, h: hh, mi: mm }
  if (desiredWall <= nowWall) {
    // add 1 day in wall-clock
    const tmp = new Date(Date.UTC(y, mo - 1, d, 12, 0, 0))
    tmp.setUTCDate(tmp.getUTCDate() + 1)
    target = {
      y: tmp.getUTCFullYear(),
      mo: tmp.getUTCMonth() + 1,
      d: tmp.getUTCDate(),
      h: hh,
      mi: mm
    }
  }

  const utc = zonedPartsToUtcDate(target, tz)
  return toSqlUtc(utc)
}

// ---------- Preferred time <-> Next execution sync (FIXED) ----------
// Rules:
// - If user edits Preferred time => Next exec becomes AUTO-managed (dataset.auto=1) and updates immediately.
// - If user edits Next exec => AUTO-managed turns OFF and Preferred time mirrors Next exec HH:mm.
// - If Time zone changes:
//    - AUTO-managed => recompute Next exec from Preferred time in new TZ
//    - manual Next exec => keep Preferred time mirroring Next exec

let schedSyncing = false

function isNextExecAutoManaged () {
  const el = document.getElementById('sched-next-exec')
  return !!(el && el.dataset && el.dataset.auto === '1')
}

function setNextExecAutoManaged (on) {
  const el = document.getElementById('sched-next-exec')
  if (!el || !el.dataset) return
  el.dataset.auto = on ? '1' : ''
}

function syncPreferredTimeFromNextExec () {
  if (schedSyncing) return
  const nextEl = document.getElementById('sched-next-exec')
  const todEl = document.getElementById('sched-time-of-day')
  if (!nextEl || !todEl) return

  const dtLocal = String(nextEl.value || '').trim() // "YYYY-MM-DDTHH:mm"
  if (!dtLocal) {
    todEl.value = ''
    return
  }
  const hhmm = dtLocal.slice(11, 16)
  if (!/^\d{2}:\d{2}$/.test(hhmm)) return

  schedSyncing = true
  try {
    todEl.value = hhmm
    todEl.dispatchEvent(new Event('change', { bubbles: true }))
  } finally {
    schedSyncing = false
  }
}

function syncNextExecFromPreferredTime () {
  if (schedSyncing) return
  const nextEl = document.getElementById('sched-next-exec')
  const todEl = document.getElementById('sched-time-of-day')
  if (!nextEl || !todEl) return

  const hhmm = String(todEl.value || '').trim()
  if (!hhmm) return

  const tz = getScheduleTimeZone()
  const nextUtcSql = computeNextExecAtFromTimeOfDay(hhmm, tz)
  if (!nextUtcSql) return

  const dtLocal = sqlUtcToSchedDtLocal(nextUtcSql, tz)
  if (!dtLocal) return

  schedSyncing = true
  try {
    nextEl.value = dtLocal
    setNextExecAutoManaged(true)
    nextEl.dispatchEvent(new Event('change', { bubbles: true }))
  } finally {
    schedSyncing = false
  }
}

function handleTimeZoneChanged () {
  if (isNextExecAutoManaged()) {
    syncNextExecFromPreferredTime()
  } else {
    syncPreferredTimeFromNextExec()
  }
}

// ---------- Messages ----------
function setMsg (text) {
  const ok = document.getElementById('rules-message')
  const er = document.getElementById('rules-error')
  if (ok) {
    ok.style.display = text ? 'block' : 'none'
    ok.textContent = text || ''
  }
  if (er) {
    er.style.display = 'none'
    er.textContent = ''
  }
}

function setErr (text) {
  const ok = document.getElementById('rules-message')
  const er = document.getElementById('rules-error')
  if (er) {
    er.style.display = text ? 'block' : 'none'
    er.textContent = text || ''
  }
  if (ok) {
    ok.style.display = 'none'
    ok.textContent = ''
  }
}

function clearDetails () {
  const rb = document.getElementById('runs-block')
  const sb = document.getElementById('sample-block')
  if (rb) rb.style.display = 'none'
  if (sb) sb.style.display = 'none'
  const ro = document.getElementById('runs-output')
  if (ro) ro.textContent = ''
  const sr = document.getElementById('sample-rows')
  if (sr) sr.innerHTML = ''
}

async function apiFetch (path, opts) {
  const headers = opts && opts.headers ? opts.headers : {}
  if (API_KEY) headers['x-api-key'] = API_KEY

  const url = API_BASE + '?path=' + encodeURIComponent(path)

  const res = await fetch(
    url,
    Object.assign({}, opts || {}, {
      headers,
      credentials: 'same-origin'
    })
  )

  if (!res.ok) {
    const txt = await res.text()
    throw new Error(
      (opts?.method || 'GET') +
        ' ' +
        path +
        ' failed: ' +
        res.status +
        ' ' +
        txt
    )
  }

  const text = await res.text()
  if (!text) return null
  try {
    return JSON.parse(text)
  } catch {
    return text
  }
}

function escapeHtml (str) {
  if (str === null || str === undefined) return ''
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
}

async function loadRules () {
  setMsg('Loading rules...')
  clearDetails()
  const tbody = document.getElementById('rules-rows')
  if (tbody) tbody.innerHTML = ''
  try {
    const rules = await apiFetch('/rules')
    if (!Array.isArray(rules)) {
      setErr('Unexpected response from /rules (not an array).')
      return
    }

    rules.forEach(rule => {
      const tr = document.createElement('tr')
      tr.innerHTML = `
        <td>${rule.id}</td>
        <td>${escapeHtml(rule.name || '')}</td>
        <td>${rule.is_active ? 'Yes' : 'No'}</td>
        <td>${rule.created_at || ''}</td>
        <td>${rule.updated_at || ''}</td>
        <td>
          <button type="button" class="btn" onclick="openEditRule(${
            rule.id
          });">Edit</button>
          <button type="button" class="btn" onclick="cloneRule(${
            rule.id
          });">Clone</button>
          <button type="button" class="btn" onclick="dryRunRule(${
            rule.id
          });">Dry-run</button>
          <button type="button" class="btn" onclick="viewRuns(${
            rule.id
          });">Runs</button>
          <button type="button" class="btn" onclick="applyRulePrompt(${
            rule.id
          });">Apply…</button>
          <button type="button" class="btn" onclick="deleteRule(${
            rule.id
          });">Delete</button>
        </td>
      `
      tbody.appendChild(tr)
    })

    setMsg('Loaded ' + rules.length + ' rule(s).')
  } catch (e) {
    setErr(String(e))
  }
}

// ---------- Safe DOM setters ----------
function setValue (id, value) {
  const el = document.getElementById(id)
  if (!el) {
    console.warn('Skipping missing element:', id)
    return
  }
  if ('value' in el) el.value = value
}
function setChecked (id, checked) {
  const el = document.getElementById(id)
  if (!el) {
    console.warn('Skipping missing element:', id)
    return
  }
  el.checked = checked
}
function setText (id, text) {
  const el = document.getElementById(id)
  if (!el) {
    console.warn('Skipping missing element:', id)
    return
  }
  el.textContent = text
}
function clearMultiSelect (id) {
  const el = document.getElementById(id)
  if (!el) return
  Array.from(el.options).forEach(o => (o.selected = false))
}
function setMultiSelectValues (id, values) {
  const el = document.getElementById(id)
  if (!el) return
  const set = new Set((values || []).map(String))
  Array.from(el.options).forEach(o => (o.selected = set.has(String(o.value))))
}

// ---------- UI helpers ----------
function updateFromStatusCount () {
  const sel = document.getElementById('from-status')
  const badge = document.getElementById('from-status-count')
  if (!sel || !badge) return
  const count = Array.from(sel.selectedOptions || []).length
  badge.textContent = count + ' selected'
}

function updateFromListCount () {
  const sel = document.getElementById('from-list-id')
  const badge = document.getElementById('from-list-count')
  if (!sel || !badge) return
  const count = Array.from(sel.selectedOptions || []).length
  badge.textContent = count + ' selected'
}

function toggleBetweenV2 (opSelectId, v2InputId) {
  const opEl = document.getElementById(opSelectId)
  const v2El = document.getElementById(v2InputId)
  if (!opEl || !v2El) return

  const op = String(opEl.value || '').trim()
  const show = op === 'BETWEEN' || op === 'NOT_BETWEEN'
  v2El.style.display = show ? '' : 'none'
}

function updateBetweenInputsVisibility () {
  toggleBetweenV2('cc-op', 'cc-v2')
  toggleBetweenV2('ed-op', 'ed-v2')
  toggleBetweenV2('lc-op', 'lc-v2')
}

// ---------- Builder helpers ----------
function toggleAdvanced () {
  const t = document.getElementById('toggle-advanced')
  const block = document.getElementById('advanced-block')
  if (!t || !block) return
  block.style.display = t.checked ? 'block' : 'none'
}

function showToError (show) {
  const el = document.getElementById('to-error')
  if (!el) return
  el.style.display = show ? 'block' : 'none'
}

function showFromWarning (show) {
  const el = document.getElementById('from-warning')
  if (!el) return
  el.style.display = show ? 'block' : 'none'
}

function readMultiSelectValues (selectId) {
  const el = document.getElementById(selectId)
  if (!el) return []
  return Array.from(el.selectedOptions || [])
    .map(o => o.value)
    .filter(Boolean)
}

function csvToArray (str) {
  return (str || '')
    .split(',')
    .map(s => s.trim())
    .filter(Boolean)
}

function buildCompare (field, op, v1Raw, v2Raw) {
  if (!op) return null

  if (op === 'IN') {
    const arr = csvToArray(v1Raw)
    if (!arr.length) return null
    return { field, op: 'IN', value: arr }
  }

  if (op === 'BETWEEN') {
    if (v1Raw === '' || v2Raw === '' || v1Raw == null || v2Raw == null)
      return null
    return {
      op: 'AND',
      rules: [
        { field, op: '>=', value: v1Raw },
        { field, op: '<=', value: v2Raw }
      ]
    }
  }

  if (op === 'NOT_BETWEEN') {
    if (v1Raw === '' || v2Raw === '' || v1Raw == null || v2Raw == null)
      return null
    return {
      op: 'OR',
      rules: [
        { field, op: '<', value: v1Raw },
        { field, op: '>', value: v2Raw }
      ]
    }
  }

  if (v1Raw === '' || v1Raw == null) return null
  return { field, op, value: v1Raw }
}

function buildWhereFromForm () {
  const rules = []

  // list_id (multi)
  const listIds = readMultiSelectValues('from-list-id')
    .map(v => parseInt(v, 10))
    .filter(n => Number.isFinite(n) && n > 0)

  if (listIds.length === 1)
    rules.push({ field: 'list_id', op: '=', value: listIds[0] })
  else if (listIds.length > 1)
    rules.push({ field: 'list_id', op: 'IN', value: listIds })

  // status (multi)
  const statuses = readMultiSelectValues('from-status')
  if (statuses.length === 1)
    rules.push({ field: 'status', op: '=', value: statuses[0] })
  if (statuses.length > 1)
    rules.push({ field: 'status', op: 'IN', value: statuses })

  // called_since_last_reset (ENUM: 'N' = not called since reset)
  const csrMode = (
    document.getElementById('from-called-since-mode')?.value || ''
  ).trim()
  if (csrMode === 'YES')
    rules.push({ field: 'called_since_last_reset', op: '<>', value: 'N' })
  if (csrMode === 'NO')
    rules.push({ field: 'called_since_last_reset', op: '=', value: 'N' })

  // entry_date older-than days
  const daysEntryRaw = (
    document.getElementById('from-days-entry')?.value || ''
  ).trim()
  if (daysEntryRaw !== '') {
    const n = parseInt(daysEntryRaw, 10)
    if (!isNaN(n) && n >= 0)
      rules.push({ field: 'entry_date', op: 'OLDER_THAN_DAYS', value: n })
  }

  // last_local_call_time older-than days shortcut
  const daysLastCallRaw = (
    document.getElementById('from-days-lastcall')?.value || ''
  ).trim()
  if (daysLastCallRaw !== '') {
    const n = parseInt(daysLastCallRaw, 10)
    if (!isNaN(n) && n >= 0) {
      rules.push({
        field: 'last_local_call_time',
        op: 'OLDER_THAN_DAYS',
        value: n
      })
    }
  }

  // phone contains
  const phoneContains = (
    document.getElementById('from-phone-contains')?.value || ''
  ).trim()
  if (phoneContains)
    rules.push({ field: 'phone_number', op: 'CONTAINS', value: phoneContains })

  // called_count compare
  const ccOp = (document.getElementById('cc-op')?.value || '').trim()
  const ccV1 = (document.getElementById('cc-v1')?.value || '').trim()
  const ccV2 = (document.getElementById('cc-v2')?.value || '').trim()
  if (ccOp) {
    const norm1 = ccOp === 'IN' ? ccV1 : ccV1 !== '' ? Number(ccV1) : ''
    const norm2 = ccV2 !== '' ? Number(ccV2) : ''
    const node = buildCompare('called_count', ccOp, norm1, norm2)
    if (node) rules.push(node)
  }

  // entry_date datetime compare
  {
    const op = (document.getElementById('ed-op')?.value || '').trim()
    const v1 = (document.getElementById('ed-v1')?.value || '').trim()
    const v2 = (document.getElementById('ed-v2')?.value || '').trim()
    const node = buildCompare(
      'entry_date',
      op,
      dtLocalToSql(v1),
      dtLocalToSql(v2)
    )
    if (node) rules.push(node)
  }

  // last_local_call_time datetime compare
  {
    const op = (document.getElementById('lc-op')?.value || '').trim()
    const v1 = (document.getElementById('lc-v1')?.value || '').trim()
    const v2 = (document.getElementById('lc-v2')?.value || '').trim()
    const node = buildCompare(
      'last_local_call_time',
      op,
      dtLocalToSql(v1),
      dtLocalToSql(v2)
    )
    if (node) rules.push(node)
  }

  const matchMode = document.getElementById('from-match-mode')?.value || 'AND'
  return { op: matchMode === 'OR' ? 'OR' : 'AND', rules }
}

function buildConditionsSpecFromForm () {
  const where = buildWhereFromForm()
  const sampleLimitRaw = (
    document.getElementById('sample-limit')?.value || ''
  ).trim()
  const sampleLimit =
    sampleLimitRaw !== '' ? parseInt(sampleLimitRaw, 10) : undefined

  const spec = { where }
  if (Number.isFinite(sampleLimit) && sampleLimit > 0)
    spec.sampleLimit = sampleLimit
  return spec
}

function buildActionsFromForm () {
  const moveToListRaw = (
    document.getElementById('to-list-id')?.value || ''
  ).trim()
  const toStatus = (document.getElementById('to-status')?.value || '').trim()
  const reset = !!document.getElementById('to-reset-called')?.checked

  const actions = {}
  if (moveToListRaw !== '') {
    const n = parseInt(moveToListRaw, 10)
    if (!isNaN(n) && n > 0) actions.move_to_list = n
  }
  if (toStatus) actions.update_status = toStatus
  if (reset) actions.reset_called_since_last_reset = true
  return actions
}

function refreshBuilderHints () {
  updateFromStatusCount()
  updateFromListCount()
  updateBetweenInputsVisibility()

  const cond = buildConditionsSpecFromForm()
  const actions = buildActionsFromForm()

  showFromWarning(!cond.where.rules.length)

  const hasAction = !!(
    actions.move_to_list ||
    actions.update_status ||
    actions.reset_called_since_last_reset
  )
  showToError(!hasAction)

  const prev = document.getElementById('builder-preview')
  if (prev) {
    prev.textContent =
      'FROM rules: ' +
      cond.where.rules.length +
      ' | TO actions: ' +
      [
        actions.move_to_list ? 'move_to_list' : null,
        actions.update_status ? 'update_status' : null,
        actions.reset_called_since_last_reset ? 'reset' : null
      ]
        .filter(Boolean)
        .join(', ')
  }
}

// Hook change events once (but ALSO bind schedule-sync events explicitly)
let modalEventsBound = false
function bindModalEvents () {
  if (modalEventsBound) return
  modalEventsBound = true

  const ids = [
    'sample-limit',
    'sched-interval-minutes',
    'sched-next-exec',
    'sched-time-of-day',
    'sched-timezone',
    'sched-batch-size',
    'sched-max-to-update',
    'from-match-mode',
    'from-list-id',
    'from-status',
    'from-called-since-mode',
    'from-days-entry',
    'from-days-lastcall',
    'from-phone-contains',
    'cc-op',
    'cc-v1',
    'cc-v2',
    'ed-op',
    'ed-v1',
    'ed-v2',
    'lc-op',
    'lc-v1',
    'lc-v2',
    'to-list-id',
    'to-status',
    'to-reset-called',
    'toggle-advanced'
  ]

  ids.forEach(id => {
    const el = document.getElementById(id)
    if (!el) return
    el.addEventListener('input', refreshBuilderHints)
    el.addEventListener('change', refreshBuilderHints)
  })

  // Schedule sync listeners (these were the part that often ends up "not updating")
  const nextEl = document.getElementById('sched-next-exec')
  const todEl = document.getElementById('sched-time-of-day')
  const tzEl = document.getElementById('sched-timezone')

  if (nextEl) {
    const onUserEditNext = () => {
      if (schedSyncing) return
      setNextExecAutoManaged(false)
      syncPreferredTimeFromNextExec()
      refreshBuilderHints()
    }
    nextEl.addEventListener('input', onUserEditNext)
    nextEl.addEventListener('change', onUserEditNext)
    nextEl.addEventListener('blur', onUserEditNext) // catches manual typing in some browsers
  }

  if (todEl) {
    const onUserEditTod = () => {
      if (schedSyncing) return
      setNextExecAutoManaged(true)
      syncNextExecFromPreferredTime()
      refreshBuilderHints()
    }
    todEl.addEventListener('input', onUserEditTod)
    todEl.addEventListener('change', onUserEditTod)
    todEl.addEventListener('blur', onUserEditTod)
  }

  if (tzEl) {
    tzEl.addEventListener('change', () => {
      handleTimeZoneChanged()
      refreshBuilderHints()
    })
  }
}

// ---------- Modal CRUD ----------
async function openCreateRule () {
  editingRuleId = null

  await loadCampaignsForUI()
  await loadListsForUI()
  await loadStatusesForUI()

  setText('rule-modal-title', 'Create Rule')

  setValue('rule-name', '')
  setValue('rule-description', '')
  setChecked('rule-active', true)

  setValue('sample-limit', '20')

  setValue('sched-interval-minutes', '')
  setValue('sched-next-exec', '')
  setValue('sched-time-of-day', '')
  setSelectValueSafe('sched-timezone', getBrowserTimeZone())
  setNextExecAutoManaged(false)

  // default blank so server can default
  setValue('sched-batch-size', '')
  setValue('sched-max-to-update', '')

  setValue('from-match-mode', 'AND')
  clearMultiSelect('from-list-id')
  clearMultiSelect('from-status')
  setValue('from-called-since-mode', '')
  setValue('from-days-entry', '')
  setValue('from-days-lastcall', '')
  setValue('from-phone-contains', '')

  setValue('cc-op', '')
  setValue('cc-v1', '')
  setValue('cc-v2', '')

  setValue('ed-op', '')
  setValue('ed-v1', '')
  setValue('ed-v2', '')

  setValue('lc-op', '')
  setValue('lc-v1', '')
  setValue('lc-v2', '')

  setValue('to-list-id', '')
  setValue('to-status', '')
  setChecked('to-reset-called', false)

  setChecked('toggle-advanced', false)
  toggleAdvanced()
  setValue(
    'rule-conditions',
    JSON.stringify(
      {
        where: { op: 'AND', rules: [] },
        sampleLimit: 20
      },
      null,
      2
    )
  )
  setValue('rule-actions-json', JSON.stringify({}, null, 2))

  const modal = document.getElementById('rule-modal')
  if (!modal) {
    console.error('Missing modal container #rule-modal')
    return
  }
  modal.style.display = 'block'

  bindModalEvents()
  refreshBuilderHints()
}

function closeRuleModal () {
  const modal = document.getElementById('rule-modal')
  if (modal) modal.style.display = 'none'
}

function flattenRules (node, out = []) {
  if (!node) return out
  if (node.field && node.op !== undefined) {
    out.push(node)
    return out
  }
  if (node.rules && Array.isArray(node.rules))
    node.rules.forEach(r => flattenRules(r, out))
  return out
}

function tryExtractBetween (groupNode, field) {
  if (!groupNode || !groupNode.op || !Array.isArray(groupNode.rules))
    return null

  if (groupNode.op === 'AND' && groupNode.rules.length === 2) {
    const a = groupNode.rules[0]
    const b = groupNode.rules[1]
    if (a?.field === field && b?.field === field) {
      const ops = new Set([a.op, b.op])
      if (ops.has('>=') && ops.has('<=')) {
        const v1 = a.op === '>=' ? a.value : b.value
        const v2 = a.op === '<=' ? a.value : b.value
        return { op: 'BETWEEN', v1, v2 }
      }
    }
  }

  if (groupNode.op === 'OR' && groupNode.rules.length === 2) {
    const a = groupNode.rules[0]
    const b = groupNode.rules[1]
    if (a?.field === field && b?.field === field) {
      const ops = new Set([a.op, b.op])
      if (ops.has('<') && ops.has('>')) {
        const v1 = a.op === '<' ? a.value : b.value
        const v2 = a.op === '>' ? a.value : b.value
        return { op: 'NOT_BETWEEN', v1, v2 }
      }
    }
  }

  return null
}

function resetBuilderUI () {
  setValue('sample-limit', '')

  setValue('sched-interval-minutes', '')
  setValue('sched-next-exec', '')
  setValue('sched-time-of-day', '')
  setSelectValueSafe('sched-timezone', getBrowserTimeZone())
  setNextExecAutoManaged(false)

  setValue('sched-batch-size', '')
  setValue('sched-max-to-update', '')

  setValue('from-match-mode', 'AND')
  clearMultiSelect('from-list-id')
  clearMultiSelect('from-status')
  setValue('from-called-since-mode', '')
  setValue('from-days-entry', '')
  setValue('from-days-lastcall', '')
  setValue('from-phone-contains', '')

  setValue('cc-op', '')
  setValue('cc-v1', '')
  setValue('cc-v2', '')
  setValue('ed-op', '')
  setValue('ed-v1', '')
  setValue('ed-v2', '')
  setValue('lc-op', '')
  setValue('lc-v1', '')
  setValue('lc-v2', '')

  setValue('to-list-id', '')
  setValue('to-status', '')
  setChecked('to-reset-called', false)
}

async function openEditRule (id) {
  setMsg('Loading rule ' + id + ' …')

  await loadCampaignsForUI()
  await loadListsForUI()
  await loadStatusesForUI()

  try {
    const rule = await apiFetch('/rules/' + id)
    editingRuleId = id

    setText('rule-modal-title', 'Edit Rule #' + id)
    setValue('rule-name', rule.name || '')
    setValue('rule-description', rule.description || '')
    setChecked('rule-active', !!rule.is_active)

    let conditionsObj = rule.conditions_json ?? rule.conditions ?? null
    if (typeof conditionsObj === 'string')
      conditionsObj = JSON.parse(conditionsObj)
    if (!conditionsObj)
      conditionsObj = { where: { op: 'AND', rules: [] }, sampleLimit: 20 }

    let actionsObj = rule.actions_json ?? rule.actions ?? {}
    if (typeof actionsObj === 'string') actionsObj = JSON.parse(actionsObj)

    resetBuilderUI()

    setValue(
      'sched-interval-minutes',
      rule.interval_minutes != null ? String(rule.interval_minutes) : ''
    )

    const tz = String(rule.schedule_tz || getBrowserTimeZone())
    setSelectValueSafe('sched-timezone', tz)

    const dtLocal = sqlUtcToSchedDtLocal(rule.next_exec_at, tz) // "YYYY-MM-DDTHH:mm"
    setValue('sched-next-exec', dtLocal)
    setValue('sched-time-of-day', dtLocal ? dtLocal.slice(11, 16) : '')

    setValue(
      'sched-batch-size',
      rule.apply_batch_size != null ? String(rule.apply_batch_size) : ''
    )
    setValue(
      'sched-max-to-update',
      rule.apply_max_to_update != null ? String(rule.apply_max_to_update) : ''
    )

    // default: treat it as auto-managed if we have a preferred time derived
    setNextExecAutoManaged(!!(dtLocal && dtLocal.slice(11, 16)))

    if (conditionsObj.sampleLimit != null)
      setValue('sample-limit', String(conditionsObj.sampleLimit))
    setValue('from-match-mode', conditionsObj.where?.op === 'OR' ? 'OR' : 'AND')

    const topRules = Array.isArray(conditionsObj.where?.rules)
      ? conditionsObj.where.rules
      : []

    // Populate BETWEEN / NOT_BETWEEN
    topRules.forEach(r => {
      const ccBetween = tryExtractBetween(r, 'called_count')
      if (ccBetween) {
        setValue('cc-op', ccBetween.op)
        setValue('cc-v1', ccBetween.v1 != null ? String(ccBetween.v1) : '')
        setValue('cc-v2', ccBetween.v2 != null ? String(ccBetween.v2) : '')
      }

      const edBetween = tryExtractBetween(r, 'entry_date')
      if (edBetween) {
        setValue('ed-op', edBetween.op)
        setValue('ed-v1', sqlToDtLocal(edBetween.v1))
        setValue('ed-v2', sqlToDtLocal(edBetween.v2))
      }

      const lcBetween = tryExtractBetween(r, 'last_local_call_time')
      if (lcBetween) {
        setValue('lc-op', lcBetween.op)
        setValue('lc-v1', sqlToDtLocal(lcBetween.v1))
        setValue('lc-v2', sqlToDtLocal(lcBetween.v2))
      }
    })

    const flat = []
    topRules.forEach(r => flattenRules(r, flat))

    // Populate SIMPLE compares for cc/ed/lc (only if not already set by BETWEEN)
    const allowedCcOps = new Set(['=', '!=', '>=', '>', '<=', '<', 'IN'])
    const allowedDtOps = new Set(['=', '!=', '>=', '>', '<=', '<'])
    const firstMatch = (field, allowed) =>
      flat.find(x => x.field === field && allowed.has(String(x.op)))

    // called_count
    if (!String(document.getElementById('cc-op')?.value || '').trim()) {
      const n = firstMatch('called_count', allowedCcOps)
      if (n) {
        setValue('cc-op', String(n.op || ''))
        if (String(n.op) === 'IN' && Array.isArray(n.value))
          setValue('cc-v1', n.value.join(','))
        else setValue('cc-v1', n.value != null ? String(n.value) : '')
        setValue('cc-v2', '')
      }
    }

    // entry_date
    if (!String(document.getElementById('ed-op')?.value || '').trim()) {
      const n = firstMatch('entry_date', allowedDtOps)
      if (n) {
        setValue('ed-op', String(n.op || ''))
        setValue('ed-v1', sqlToDtLocal(n.value))
        setValue('ed-v2', '')
      }
    }

    // last_local_call_time
    if (!String(document.getElementById('lc-op')?.value || '').trim()) {
      const n = firstMatch('last_local_call_time', allowedDtOps)
      if (n) {
        setValue('lc-op', String(n.op || ''))
        setValue('lc-v1', sqlToDtLocal(n.value))
        setValue('lc-v2', '')
      }
    }

    // list_id
    const listEq = flat.find(x => x.field === 'list_id' && x.op === '=')
    const listIn = flat.find(
      x => x.field === 'list_id' && x.op === 'IN' && Array.isArray(x.value)
    )
    if (listEq)
      setMultiSelectValues('from-list-id', [String(listEq.value ?? '')])
    if (listIn)
      setMultiSelectValues('from-list-id', (listIn.value || []).map(String))

    // status
    const statusEq = flat.find(x => x.field === 'status' && x.op === '=')
    const statusIn = flat.find(
      x => x.field === 'status' && x.op === 'IN' && Array.isArray(x.value)
    )
    if (statusEq) setMultiSelectValues('from-status', [statusEq.value])
    if (statusIn) setMultiSelectValues('from-status', statusIn.value)

    // called_since_last_reset
    const csrNeN = flat.find(
      x =>
        x.field === 'called_since_last_reset' &&
        (x.op === '!=' || x.op === '<>') &&
        String(x.value) === 'N'
    )
    const csrEqN = flat.find(
      x =>
        x.field === 'called_since_last_reset' &&
        x.op === '=' &&
        String(x.value) === 'N'
    )
    if (csrNeN) setValue('from-called-since-mode', 'YES')
    else if (csrEqN) setValue('from-called-since-mode', 'NO')
    else setValue('from-called-since-mode', '')

    // entry_date OLDER_THAN_DAYS shortcut
    const entryOlder = flat.find(
      x => x.field === 'entry_date' && x.op === 'OLDER_THAN_DAYS'
    )
    if (entryOlder && entryOlder.value != null)
      setValue('from-days-entry', String(entryOlder.value))

    // last_local_call_time OLDER_THAN_DAYS shortcut
    const lastCallOlder = flat.find(
      x => x.field === 'last_local_call_time' && x.op === 'OLDER_THAN_DAYS'
    )
    if (lastCallOlder && lastCallOlder.value != null)
      setValue('from-days-lastcall', String(lastCallOlder.value))

    // phone contains
    const phoneContains = flat.find(
      x => x.field === 'phone_number' && x.op === 'CONTAINS'
    )
    if (phoneContains)
      setValue('from-phone-contains', String(phoneContains.value ?? ''))

    // TO
    if (actionsObj.move_to_list != null)
      setValue('to-list-id', String(actionsObj.move_to_list))
    if (actionsObj.update_status != null)
      setValue('to-status', String(actionsObj.update_status))
    setChecked('to-reset-called', !!actionsObj.reset_called_since_last_reset)

    setChecked('toggle-advanced', false)
    toggleAdvanced()
    setValue('rule-conditions', JSON.stringify(conditionsObj, null, 2))
    setValue('rule-actions-json', JSON.stringify(actionsObj, null, 2))

    const modal = document.getElementById('rule-modal')
    if (modal) modal.style.display = 'block'

    bindModalEvents()
    refreshBuilderHints()

    setMsg('')
  } catch (e) {
    setErr('Failed to load rule: ' + String(e))
  }
}

async function saveRule () {
  const name = (document.getElementById('rule-name')?.value || '').trim()
  const description = (
    document.getElementById('rule-description')?.value || ''
  ).trim()
  const isActive = !!document.getElementById('rule-active')?.checked

  if (!name) {
    alert('Name required')
    return
  }

  const advancedOn = !!document.getElementById('toggle-advanced')?.checked

  let conditions, actions
  if (advancedOn) {
    try {
      conditions = JSON.parse(
        document.getElementById('rule-conditions')?.value || ''
      )
    } catch {
      alert('Invalid JSON in Conditions')
      return
    }
    try {
      actions = JSON.parse(
        document.getElementById('rule-actions-json')?.value || '{}'
      )
    } catch {
      alert('Invalid JSON in Actions')
      return
    }
  } else {
    conditions = buildConditionsSpecFromForm()
    actions = buildActionsFromForm()
  }

  if (!conditions || typeof conditions !== 'object' || !conditions.where) {
    alert('Conditions must include: { "where": { ... } }')
    return
  }

  const hasAction = !!(
    actions.move_to_list ||
    actions.update_status ||
    actions.reset_called_since_last_reset
  )
  if (!hasAction) {
    showToError(true)
    alert('Please apply at least one change within the TO section.')
    return
  }

  if (!conditions.where.rules || !conditions.where.rules.length) {
    if (!confirm('No FROM filters set. This may apply to ALL leads. Continue?'))
      return
  }

  // Scheduling payload (ALL optional; blank => unset/null)
  const intervalRaw = (
    document.getElementById('sched-interval-minutes')?.value || ''
  ).trim()
  const nextExecRaw = (
    document.getElementById('sched-next-exec')?.value || ''
  ).trim()
  const timeOfDay = (
    document.getElementById('sched-time-of-day')?.value || ''
  ).trim()
  const batchRaw = (
    document.getElementById('sched-batch-size')?.value || ''
  ).trim()
  const maxRaw = (
    document.getElementById('sched-max-to-update')?.value || ''
  ).trim()

  let intervalMinutes = null
  if (intervalRaw !== '') {
    const v = parseInt(intervalRaw, 10)
    if (isNaN(v) || v <= 0) {
      alert('Interval minutes must be a positive number')
      return
    }
    intervalMinutes = v
  }

  const scheduleTimeZone = getScheduleTimeZone()

  // Ensure next exec reflects preferred time if we're auto-managing
  if (timeOfDay && isNextExecAutoManaged()) syncNextExecFromPreferredTime()

  const nextExecRaw2 = (
    document.getElementById('sched-next-exec')?.value || ''
  ).trim()

  let nextExecAt = nextExecRaw2
    ? schedDtLocalToSqlUtc(nextExecRaw2, scheduleTimeZone)
    : null
  if (!nextExecAt && timeOfDay)
    nextExecAt = computeNextExecAtFromTimeOfDay(timeOfDay, scheduleTimeZone)

  let applyBatchSize = null
  if (batchRaw !== '') {
    const v = parseInt(batchRaw, 10)
    if (isNaN(v) || v < 1 || v > 5000) {
      alert('Batch size must be between 1 and 5000')
      return
    }
    applyBatchSize = v
  }

  let applyMaxToUpdate = null
  if (maxRaw !== '') {
    const v = parseInt(maxRaw, 10)
    if (isNaN(v) || v < 1 || v > 50000) {
      alert('Max leads to update must be between 1 and 50000')
      return
    }
    applyMaxToUpdate = v
  }

  const payload = {
    name,
    ...(description ? { description } : {}),
    isActive,
    conditions,
    actions,
    ...(intervalMinutes !== null ? { intervalMinutes } : {}),
    scheduleTimeZone,
    ...(nextExecAt !== null ? { nextExecAt } : {}),
    ...(applyBatchSize !== null ? { applyBatchSize } : {}),
    ...(applyMaxToUpdate !== null ? { applyMaxToUpdate } : {})
  }

  try {
    if (editingRuleId == null) {
      await apiFetch('/rules', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
      setMsg('Rule created.')
    } else {
      await apiFetch('/rules/' + editingRuleId, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
      setMsg('Rule updated.')
    }

    closeRuleModal()
    loadRules()
  } catch (e) {
    setErr('Save failed: ' + String(e))
  }
}

async function deleteRule (id) {
  if (!confirm('Delete rule #' + id + '?')) return
  try {
    await apiFetch('/rules/' + id, { method: 'DELETE' })
    setMsg('Rule deleted.')
    loadRules()
  } catch (e) {
    setErr('Delete failed: ' + String(e))
  }
}

async function dryRunRule (id) {
  setMsg('Running dry-run for rule ' + id + ' …')
  clearDetails()
  try {
    const result = await apiFetch('/rules/' + id + '/dry-run', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({})
    })

    const matched = result?.matchedCount ?? 0
    setMsg('Dry-run succeeded.\nMatched leads: ' + matched)

    const sample = Array.isArray(result?.sample) ? result.sample : []
    const sampleBody = document.getElementById('sample-rows')
    if (sampleBody) sampleBody.innerHTML = ''

    sample.forEach(r => {
      const tr = document.createElement('tr')
      tr.innerHTML = `
        <td>${r.lead_id}</td>
        <td>${r.list_id}</td>
        <td><span class="status-badge">${escapeHtml(r.status || '')}</span></td>
        <td>${escapeHtml(r.phone_number || '')}</td>
        <td>${r.entry_date || ''}</td>
        <td>${r.last_local_call_time || ''}</td>
        <td>${r.called_count != null ? r.called_count : ''}</td>
        <td>${
          r.called_since_last_reset != null ? r.called_since_last_reset : ''
        }</td>
      `
      sampleBody.appendChild(tr)
    })

    const sb = document.getElementById('sample-block')
    if (sb) sb.style.display = sample.length ? 'block' : 'none'
  } catch (e) {
    setErr('Dry-run failed: ' + String(e))
  }
}

async function viewRuns (ruleId) {
  setMsg('Loading runs for rule ' + ruleId + ' …')
  clearDetails()
  try {
    const runs = await apiFetch('/rules/' + ruleId + '/runs')
    if (!Array.isArray(runs) || runs.length === 0) {
      setMsg('No runs yet for this rule.')
      return
    }

    let text = ''
    runs.forEach(run => {
      text +=
        '#' +
        run.id +
        ' [' +
        run.run_type +
        '] ' +
        'status=' +
        run.status +
        ', matched=' +
        run.matched_count +
        ', updated=' +
        run.updated_count +
        ', started=' +
        (run.started_at || '') +
        ', ended=' +
        (run.ended_at || '') +
        '\n'
    })

    const ro = document.getElementById('runs-output')
    if (ro) ro.textContent = text
    const rb = document.getElementById('runs-block')
    if (rb) rb.style.display = 'block'

    setMsg('Loaded ' + runs.length + ' run(s).')
  } catch (e) {
    setErr('Failed to load runs: ' + String(e))
  }
}

// --------- META LOADERS ----------
async function loadCampaignsForUI () {
  const sel = document.getElementById('from-campaign-id')
  if (!sel) return

  const selected = sel.value || ''
  const rows = await apiFetch('/rules/meta/campaigns')

  sel.innerHTML = `<option value="">All campaigns</option>`
  ;(rows || []).forEach(r => {
    const opt = document.createElement('option')
    opt.value = r.campaign_id
    opt.textContent =
      r.campaign_id + (r.campaign_name ? ' - ' + r.campaign_name : '')
    if (String(opt.value) === String(selected)) opt.selected = true
    sel.appendChild(opt)
  })
}

// Lists: ALWAYS load ALL lists (ignore campaign), and show inactive too.
async function loadListsForUI () {
  const fromSel = document.getElementById('from-list-id')
  const toSel = document.getElementById('to-list-id')
  if (!fromSel && !toSel) return

  const fromSelected = new Set(
    fromSel
      ? Array.from(fromSel.selectedOptions || []).map(o => String(o.value))
      : []
  )
  const toSelected = toSel ? toSel.value || '' : ''

  const rows = await apiFetch('/rules/meta/lists')

  if (fromSel) {
    fromSel.innerHTML = ''
    ;(rows || []).forEach(r => {
      const opt = document.createElement('option')
      opt.value = r.list_id

      const inactiveTag =
        String(r.active || '').toUpperCase() === 'N' ? ' (INACTIVE)' : ''
      opt.textContent =
        r.list_id +
        (r.list_name ? ' - ' + r.list_name : '') +
        (r.campaign_id ? ' [' + r.campaign_id + ']' : '') +
        inactiveTag

      if (fromSelected.has(String(opt.value))) opt.selected = true
      fromSel.appendChild(opt)
    })
  }

  if (toSel) {
    toSel.innerHTML = `<option value="">Leave empty = no change</option>`
    ;(rows || []).forEach(r => {
      const opt = document.createElement('option')
      opt.value = r.list_id

      const inactiveTag =
        String(r.active || '').toUpperCase() === 'N' ? ' (INACTIVE)' : ''
      opt.textContent =
        r.list_id +
        (r.list_name ? ' - ' + r.list_name : '') +
        (r.campaign_id ? ' [' + r.campaign_id + ']' : '') +
        inactiveTag

      if (String(opt.value) === String(toSelected)) opt.selected = true
      toSel.appendChild(opt)
    })
  }

  updateFromListCount()
}

// Statuses: still filter by campaign
async function loadStatusesForUI () {
  const fromSel = document.getElementById('from-status')
  const toSel = document.getElementById('to-status')
  if (!fromSel && !toSel) return

  const campaignId = (
    document.getElementById('from-campaign-id')?.value || ''
  ).trim()
  const qp = campaignId ? '?campaignId=' + encodeURIComponent(campaignId) : ''

  const fromSelected = new Set(
    fromSel ? Array.from(fromSel.selectedOptions || []).map(o => o.value) : []
  )
  const toSelected = toSel ? toSel.value || '' : ''

  const rows = await apiFetch('/rules/meta/statuses' + qp)

  if (fromSel) {
    fromSel.innerHTML = ''
    ;(rows || []).forEach(r => {
      const opt = document.createElement('option')
      opt.value = r.status
      opt.textContent = r.status + (r.status_name ? ' - ' + r.status_name : '')
      if (fromSelected.has(opt.value)) opt.selected = true
      fromSel.appendChild(opt)
    })
  }

  if (toSel) {
    toSel.innerHTML = `<option value="">Leave empty = no change</option>`
    ;(rows || []).forEach(r => {
      const opt = document.createElement('option')
      opt.value = r.status
      opt.textContent = r.status + (r.status_name ? ' - ' + r.status_name : '')
      if (String(opt.value) === String(toSelected)) opt.selected = true
      toSel.appendChild(opt)
    })
  }

  updateFromStatusCount()
}

async function applyRulePrompt (ruleId) {
  const batchSize = prompt('Batch size? (e.g. 500)', '500')
  if (batchSize === null) return
  const maxToUpdate = prompt('Max leads to update? (e.g. 10000)', '10000')
  if (maxToUpdate === null) return

  const b = parseInt(batchSize, 10)
  const m = parseInt(maxToUpdate, 10)
  if (isNaN(b) || isNaN(m) || b <= 0 || m <= 0) {
    alert('Invalid numbers.')
    return
  }

  if (
    !confirm(
      'Apply rule ' +
        ruleId +
        ' with batchSize=' +
        b +
        ', maxToUpdate=' +
        m +
        ' ?'
    )
  )
    return

  setMsg('Applying rule ' + ruleId + ' …')
  clearDetails()
  try {
    const result = await apiFetch('/rules/' + ruleId + '/apply', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ batchSize: b, maxToUpdate: m })
    })

    setMsg('Apply finished:\n' + JSON.stringify(result, null, 2))
    viewRuns(ruleId)
  } catch (e) {
    setErr('Apply failed: ' + String(e))
  }
}

async function cloneRule (id) {
  if (
    !confirm('Clone rule #' + id + '? The clone will be created as inactive.')
  )
    return

  setMsg('Cloning rule ' + id + ' …')
  clearDetails()

  try {
    const res = await apiFetch('/rules/' + id + '/clone', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({})
    })

    const newId = res?.id
    if (!newId) {
      setErr('Clone succeeded but no new id returned: ' + JSON.stringify(res))
      loadRules()
      return
    }

    setMsg('Cloned rule #' + id + ' → new rule #' + newId)
    await loadRules()
    await openEditRule(newId)
  } catch (e) {
    setErr('Clone failed: ' + String(e))
  }
}

// Campaign change: refresh only statuses (lists always all)
document
  .getElementById('from-campaign-id')
  ?.addEventListener('change', async () => {
    await loadStatusesForUI()
    refreshBuilderHints()
  })

document.addEventListener('DOMContentLoaded', async function () {
  await loadRules()

  // preload lists + statuses so modal feels instant after first open
  try {
    await loadCampaignsForUI()
  } catch {}
  try {
    await loadListsForUI()
  } catch {}
  try {
    await loadStatusesForUI()
  } catch {}

  updateFromStatusCount()
  updateFromListCount()
  updateBetweenInputsVisibility()

  // Bind once; schedule sync bindings live here too
  bindModalEvents()

  // Prevent jump/auto-scroll behavior for multi selects
  const fromStatusSel = document.getElementById('from-status')
  if (fromStatusSel) {
    fromStatusSel.addEventListener('click', e => e.preventDefault())
    fromStatusSel.addEventListener('mousedown', e => {
      const opt = e.target
      if (!opt || opt.tagName !== 'OPTION') return

      const prevScrollTop = fromStatusSel.scrollTop
      e.preventDefault()
      e.stopPropagation()

      opt.selected = !opt.selected
      fromStatusSel.dispatchEvent(new Event('change', { bubbles: true }))

      requestAnimationFrame(() => {
        fromStatusSel.scrollTop = prevScrollTop
      })
    })
  }

  const fromListSel = document.getElementById('from-list-id')
  if (fromListSel) {
    fromListSel.addEventListener('click', e => e.preventDefault())
    fromListSel.addEventListener('mousedown', e => {
      const opt = e.target
      if (!opt || opt.tagName !== 'OPTION') return

      const prevScrollTop = fromListSel.scrollTop
      e.preventDefault()
      e.stopPropagation()

      opt.selected = !opt.selected
      fromListSel.dispatchEvent(new Event('change', { bubbles: true }))

      requestAnimationFrame(() => {
        fromListSel.scrollTop = prevScrollTop
      })
    })
  }
})
