import { Inject, Injectable, NotFoundException } from '@nestjs/common';
import type { Pool } from 'mysql2/promise';
import { CreateRuleDto } from './dto/create-rule.dto';
import { UpdateRuleDto } from './dto/update-rule.dto';
import { QueryBuilderService } from 'src/common/rules/query-builder/query-builder.service';
import { ConditionSpec } from 'src/common/rules/condition-types/condition.types';
import { ActionSpec } from 'src/common/rules/action-types/action.types';
import {
  PROTECTED_LISTS,
  PROTECTED_STATUSES,
} from 'src/common/rules/rule-contants/rule-constants';

@Injectable()
export class RulesService {
  private toSqlUtc(date: Date): string {
    const pad2 = (n: number) => String(n).padStart(2, '0');
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
      ':' +
      pad2(date.getUTCSeconds())
    );
  }

  constructor(
    @Inject('DB_POOL') private readonly db: Pool,
    private readonly qb: QueryBuilderService,
  ) {}

  // ---------- CRUD ----------

  async create(dto: CreateRuleDto) {
    const interval = dto.intervalMinutes ?? null;

    // NOTE: DTO may not allow this yet; safe access.
    const scheduleTimeZone: string | null =
      (dto as any).scheduleTimeZone ?? null;

    let nextExecAt: string | null = dto.nextExecAt
      ? String(dto.nextExecAt).trim()
      : null;

    // If interval set and nextExecAt missing, default to now + interval (UTC string)
    if (interval != null && !nextExecAt) {
      nextExecAt = this.toSqlUtc(new Date(Date.now() + interval * 60_000));
    }

    const [res] = await this.db.query(
      `INSERT INTO lead_rules
        (name, description, is_active, conditions_json, actions_json,
         interval_minutes, next_exec_at, schedule_tz, apply_batch_size, apply_max_to_update)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        dto.name,
        dto.description ?? null,
        (dto.isActive ?? true) ? 1 : 0,
        JSON.stringify(dto.conditions),
        JSON.stringify(dto.actions),
        interval,
        nextExecAt,
        scheduleTimeZone,
        dto.applyBatchSize ?? null,
        dto.applyMaxToUpdate ?? null,
      ],
    );

    return { id: (res as any).insertId };
  }

  async findAll() {
    // Force next_exec_at to return as STRING (prevents timezone shifts)
    const [rows] = await this.db.query(
      `SELECT
  id, name, description, is_active,
  DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
  DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at,
  interval_minutes,
  DATE_FORMAT(next_exec_at, '%Y-%m-%d %H:%i:%s') AS next_exec_at,
  schedule_tz,
  apply_batch_size, apply_max_to_update,
  DATE_FORMAT(last_run_at, '%Y-%m-%d %H:%i:%s') AS last_run_at
FROM lead_rules
ORDER BY id DESC`,
    );
    return rows;
  }

  async findOne(id: number) {
    // Avoid SELECT * so next_exec_at is never returned as JS Date
    const [rows] = await this.db.query(
      `SELECT
  id,
  name,
  description,
  is_active,
  DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
  DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at,
  interval_minutes,
  DATE_FORMAT(next_exec_at, '%Y-%m-%d %H:%i:%s') AS next_exec_at,
  schedule_tz,
  apply_batch_size,
  apply_max_to_update,
  DATE_FORMAT(last_run_at, '%Y-%m-%d %H:%i:%s') AS last_run_at,
  conditions_json,
  actions_json
FROM lead_rules
WHERE id = ?`,
      [id],
    );

    const rule = (rows as any[])[0];
    if (!rule) throw new NotFoundException('Rule not found');

    console.log(
      '[findOne] typeof next_exec_at:',
      typeof rule.next_exec_at,
      rule.next_exec_at,
    );
    console.log(
      '[findOne] typeof created_at:',
      typeof rule.created_at,
      rule.created_at,
    );
    const conditions =
      typeof rule.conditions_json === 'string'
        ? JSON.parse(rule.conditions_json)
        : rule.conditions_json;

    const actions =
      typeof rule.actions_json === 'string'
        ? JSON.parse(rule.actions_json)
        : rule.actions_json;

    return {
      id: rule.id,
      name: rule.name,
      description: rule.description,
      is_active: rule.is_active,
      created_at: rule.created_at,
      updated_at: rule.updated_at,
      interval_minutes: rule.interval_minutes,
      next_exec_at: rule.next_exec_at, // ✅ string "YYYY-MM-DD HH:MM:SS" or null
      schedule_tz: rule.schedule_tz,
      apply_batch_size: rule.apply_batch_size,
      apply_max_to_update: rule.apply_max_to_update,
      last_run_at: rule.last_run_at,
      locked_at: rule.locked_at,
      locked_by: rule.locked_by,
      conditions,
      actions,
    };
  }

  async update(id: number, dto: UpdateRuleDto) {
    const existing = await this.findOne(id);

    const name = dto.name ?? existing.name;
    const description = dto.description ?? existing.description;
    const isActive = dto.isActive ?? existing.is_active === 1;

    const conditions = dto.conditions ?? existing.conditions;
    const actions = dto.actions ?? existing.actions;

    const intervalMinutes =
      dto.intervalMinutes !== undefined
        ? dto.intervalMinutes
        : existing.interval_minutes;

    // Preserve existing schedule_tz if dto doesn't provide one
    const scheduleTimeZone: string | null =
      (dto as any).scheduleTimeZone !== undefined
        ? (dto as any).scheduleTimeZone
        : ((existing as any).schedule_tz ?? null);

    // Preserve existing next_exec_at unless explicitly provided
    let nextExecAt: string | null =
      dto.nextExecAt !== undefined
        ? dto.nextExecAt != null
          ? String(dto.nextExecAt).trim()
          : null
        : ((existing as any).next_exec_at ?? null);

    const applyBatchSize =
      dto.applyBatchSize !== undefined
        ? dto.applyBatchSize
        : existing.apply_batch_size;

    const applyMaxToUpdate =
      dto.applyMaxToUpdate !== undefined
        ? dto.applyMaxToUpdate
        : existing.apply_max_to_update;

    // Scheduling rules:
    // - If interval disabled -> also disable next exec
    if (intervalMinutes == null) {
      nextExecAt = null;
    }

    // - If interval enabled but nextExecAt missing -> default now + interval (UTC string)
    if (intervalMinutes != null && !nextExecAt) {
      nextExecAt = this.toSqlUtc(
        new Date(Date.now() + Number(intervalMinutes) * 60_000),
      );
    }

    await this.db.query(
      `UPDATE lead_rules
       SET name=?, description=?, is_active=?, conditions_json=?, actions_json=?,
           interval_minutes=?, next_exec_at=?, schedule_tz=?,
           apply_batch_size=?, apply_max_to_update=?
       WHERE id=?`,
      [
        name,
        description,
        isActive ? 1 : 0,
        JSON.stringify(conditions),
        JSON.stringify(actions),
        intervalMinutes,
        nextExecAt,
        scheduleTimeZone,
        applyBatchSize,
        applyMaxToUpdate,
        id,
      ],
    );

    return { ok: true };
  }

  async remove(id: number) {
    await this.db.query(`DELETE FROM lead_rules WHERE id = ?`, [id]);
    return { ok: true };
  }

  // ---------- Run history / logging ----------

  async logDryRun(ruleId: number, matchedCount: number, sample: any[]) {
    await this.db.query(
      `INSERT INTO lead_rule_runs
         (rule_id, run_type, matched_count, updated_count, status, sample_json, started_at, ended_at)
       VALUES
         (?, 'DRY_RUN', ?, NULL, 'SUCCESS', ?, NOW(), NOW())`,
      [ruleId, matchedCount, JSON.stringify(sample ?? [])],
    );
  }

  async listRuns(ruleId: number) {
    const [rows] = await this.db.query(
      `SELECT id, rule_id, run_type, matched_count, updated_count, status,
              started_at, ended_at, created_at
       FROM lead_rule_runs
       WHERE rule_id = ?
       ORDER BY id DESC
       LIMIT 50`,
      [ruleId],
    );
    return rows;
  }

  async getRun(runId: number) {
    const [rows] = await this.db.query(
      `SELECT *
       FROM lead_rule_runs
       WHERE id = ?`,
      [runId],
    );
    return (rows as any[])[0] ?? null;
  }

  // ---------- APPLY RULE (with DSL + safety rails) ----------

  async applyRule(
    ruleId: number,
    apply: { batchSize: number; maxToUpdate: number },
  ) {
    const lockName = `lead_rule_apply_${ruleId}`;

    if (process.env.ALLOW_RULE_UPDATES !== 'true') {
      return {
        ok: false,
        message:
          'Updates are disabled in this environment. Set ALLOW_RULE_UPDATES=true to enable.',
      };
    }

    const rule = await this.findOne(ruleId);

    if (rule.is_active === 0) {
      return { ok: false, message: 'Rule is inactive.' };
    }

    const conditionsRaw =
      typeof (rule as any).conditions === 'string'
        ? JSON.parse((rule as any).conditions)
        : (rule as any).conditions;

    const actions = (
      typeof (rule as any).actions === 'string'
        ? JSON.parse((rule as any).actions)
        : (rule as any).actions
    ) as ActionSpec;

    const targetListId = actions.move_to_list;
    const targetStatus = actions.update_status;
    const resetCalled = actions.reset_called_since_last_reset === true;

    const conditionSpec = conditionsRaw as ConditionSpec;

    const batchSize = Math.min(Math.max(apply.batchSize ?? 500, 1), 5000);
    const maxToUpdate = Math.min(
      Math.max(apply.maxToUpdate ?? 10000, 1),
      50000,
    );

    if (!targetListId && !targetStatus && !resetCalled) {
      return {
        ok: false,
        message: 'No actions specified (move_to_list/update_status/reset)',
      };
    }

    if (!conditionSpec?.where) {
      return {
        ok: false,
        message: 'Rule conditions must include a "where" group (DSL).',
      };
    }

    const [dryRows] = await this.db.query(
      `SELECT id, created_at
       FROM lead_rule_runs
       WHERE rule_id = ?
         AND run_type = 'DRY_RUN'
         AND status = 'SUCCESS'
         AND created_at >= (NOW() - INTERVAL 30 MINUTE)
       ORDER BY id DESC
       LIMIT 1`,
      [ruleId],
    );
    if ((dryRows as any[]).length === 0) {
      return {
        ok: false,
        message:
          'Apply blocked: run a successful dry-run in the last 30 minutes first.',
      };
    }

    let gotLock = false;
    const [lockRows] = await this.db.query(
      `SELECT GET_LOCK(?, 0) AS got_lock`,
      [lockName],
    );
    gotLock = Number((lockRows as any)[0]?.got_lock ?? 0) === 1;
    if (!gotLock) {
      return {
        ok: false,
        message:
          'Another APPLY run is already in progress for this rule. Please try again after it finishes.',
      };
    }

    const [runRes] = await this.db.query(
      `INSERT INTO lead_rule_runs
         (rule_id, run_type, matched_count, updated_count, status, sample_json, started_at)
       VALUES
         (?, 'APPLY', 0, 0, 'STARTED', NULL, NOW())`,
      [ruleId],
    );
    const runId = (runRes as any).insertId;

    try {
      const { sql: baseWhereSql, params } = this.qb.buildWhere(
        conditionSpec.where,
      );

      const protectedListClause =
        PROTECTED_LISTS.length > 0
          ? ` AND vicidial_list.list_id NOT IN (${PROTECTED_LISTS.map(() => '?').join(',')})`
          : '';

      const protectedStatusClause =
        PROTECTED_STATUSES.length > 0
          ? ` AND vicidial_list.status NOT IN (${PROTECTED_STATUSES.map(() => '?').join(',')})`
          : '';

      if (!baseWhereSql || !baseWhereSql.trim()) {
        await this.db.query(
          `UPDATE lead_rule_runs
           SET status = 'FAILED', error_text = ?, ended_at = NOW()
           WHERE id = ?`,
          ['Refusing to apply: empty WHERE.', runId],
        );
        return { ok: false, message: 'Refusing to apply: empty WHERE.' };
      }

      const whereSql = `
        ${baseWhereSql}
        ${protectedListClause}
        ${protectedStatusClause}
      `;

      const finalParams = [
        ...params,
        ...PROTECTED_LISTS,
        ...PROTECTED_STATUSES,
      ];

      const [countRows] = await this.db.query(
        `SELECT COUNT(*) AS c
         FROM vicidial_list
         ${whereSql}`,
        finalParams,
      );
      const matchedCount = (countRows as any)[0]?.c ?? 0;

      const toUpdate = Math.min(matchedCount, maxToUpdate);
      let updatedTotal = 0;

      const [sampleRows] = await this.db.query(
        `SELECT lead_id, list_id, status, phone_number
         FROM vicidial_list
         ${whereSql}
         ORDER BY lead_id
         LIMIT 50`,
        finalParams,
      );

      let lastLeadId = 0;

      while (updatedTotal < toUpdate) {
        const remaining = toUpdate - updatedTotal;
        const currentBatch = Math.min(batchSize, remaining);

        const selectSql = `
          SELECT vicidial_list.lead_id
          FROM vicidial_list
          ${whereSql}
          AND vicidial_list.lead_id > ?
          ORDER BY vicidial_list.lead_id
          LIMIT ?
        `;

        const [idRows] = await this.db.query(selectSql, [
          ...finalParams,
          lastLeadId,
          currentBatch,
        ]);

        const ids = (idRows as any[])
          .map((r) => Number(r.lead_id))
          .filter((n) => Number.isFinite(n));

        if (ids.length === 0) break;

        lastLeadId = ids[ids.length - 1];

        const set: string[] = [];
        const updParams: any[] = [];

        if (targetListId != null) {
          set.push(`list_id = ?`);
          updParams.push(targetListId);
        }
        if (targetStatus != null) {
          set.push(`status = ?`);
          updParams.push(targetStatus);
        }
        if (resetCalled) {
          set.push(`called_since_last_reset = ?`);
          updParams.push('N');
        }

        set.push(`modify_date = NOW()`);

        const ph = ids.map(() => '?').join(',');
        const updateSql = `
          UPDATE vicidial_list
          SET ${set.join(', ')}
          WHERE lead_id IN (${ph})
        `;

        const [updRes] = await this.db.query(updateSql, [...updParams, ...ids]);
        const affected = (updRes as any).affectedRows ?? 0;

        if (affected === 0) break;

        updatedTotal += Math.min(affected, toUpdate - updatedTotal);
      }

      await this.db.query(
        `UPDATE lead_rule_runs
         SET matched_count = ?, updated_count = ?, status = 'SUCCESS',
             sample_json = ?, ended_at = NOW()
         WHERE id = ?`,
        [matchedCount, updatedTotal, JSON.stringify(sampleRows ?? []), runId],
      );

      return {
        ok: true,
        runId,
        matchedCount,
        updatedTotal,
        cappedAt: maxToUpdate,
        actionsApplied: {
          move_to_list: targetListId ?? null,
          update_status: targetStatus ?? null,
          reset_called_since_last_reset: resetCalled ? true : null,
        },
      };
    } catch (e: any) {
      await this.db.query(
        `UPDATE lead_rule_runs
         SET status = 'FAILED', error_text = ?, ended_at = NOW()
         WHERE id = ?`,
        [String(e?.message ?? e), runId],
      );
      throw e;
    } finally {
      if (gotLock) {
        try {
          await this.db.query(`SELECT RELEASE_LOCK(?) AS released`, [lockName]);
        } catch {
          // ignore
        }
      }
    }
  }

  async listStatuses(campaignId?: string) {
    if (campaignId && campaignId.trim()) {
      const [rows] = await this.db.query(
        `
        SELECT DISTINCT status, status_name
        FROM (
          SELECT status, status_name
          FROM vicidial_statuses
          UNION ALL
          SELECT status, status_name
          FROM vicidial_campaign_statuses
          WHERE campaign_id = ?
        ) s
        ORDER BY status
        `,
        [campaignId.trim()],
      );
      return rows;
    }

    const [rows] = await this.db.query(
      `
      SELECT DISTINCT status, status_name
      FROM (
        SELECT status, status_name FROM vicidial_statuses
        UNION ALL
        SELECT status, status_name FROM vicidial_campaign_statuses
      ) s
      ORDER BY status
      `,
    );
    return rows;
  }

  async cloneRule(ruleId: number, requestedName?: string) {
    const [rows] = await this.db.query(
      `SELECT * FROM lead_rules WHERE id = ?`,
      [ruleId],
    );
    const src = (rows as any[])[0];
    if (!src) throw new NotFoundException('Rule not found');

    const baseName =
      (requestedName && requestedName.trim()) ||
      `${src.name ?? 'Untitled Rule'} (Copy)`;

    const uniqueName = await this.makeUniqueRuleName(baseName);

    const description = src.description ?? null;
    const conditionsJson =
      src.conditions_json ??
      JSON.stringify({ where: { op: 'AND', rules: [] } });
    const actionsJson = src.actions_json ?? JSON.stringify({});

    const intervalMinutes = src.interval_minutes ?? null;
    const applyBatchSize = src.apply_batch_size ?? null;
    const applyMaxToUpdate = src.apply_max_to_update ?? null;

    const scheduleTz = src.schedule_tz ?? null; // ✅ copy tz
    const nextExecAt = null;

    const [res] = await this.db.query(
      `INSERT INTO lead_rules
        (name, description, is_active, conditions_json, actions_json,
         interval_minutes, next_exec_at, schedule_tz, apply_batch_size, apply_max_to_update,
         last_run_at, locked_at, locked_by)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL)`,
      [
        uniqueName,
        description,
        0,
        conditionsJson,
        actionsJson,
        intervalMinutes,
        nextExecAt,
        scheduleTz,
        applyBatchSize,
        applyMaxToUpdate,
      ],
    );

    return { id: (res as any).insertId };
  }

  private async makeUniqueRuleName(baseName: string) {
    const trimmed = baseName.trim();
    if (!trimmed) return 'Cloned Rule';

    const exists = await this.ruleNameExists(trimmed);
    if (!exists) return trimmed;

    for (let i = 2; i <= 50; i++) {
      const candidate = `${trimmed} ${i}`.replace(/\s+/g, ' ').trim();
      const used = await this.ruleNameExists(candidate);
      if (!used) return candidate;
    }

    return `${trimmed} ${Date.now()}`;
  }

  private async ruleNameExists(name: string) {
    const [rows] = await this.db.query(
      `SELECT 1 FROM lead_rules WHERE name = ? LIMIT 1`,
      [name],
    );
    return (rows as any[]).length > 0;
  }
}
