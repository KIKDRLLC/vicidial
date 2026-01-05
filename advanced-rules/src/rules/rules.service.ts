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
  constructor(
    @Inject('DB_POOL') private readonly db: Pool,
    private readonly qb: QueryBuilderService,
  ) {}

  // ---------- CRUD ----------

  async create(dto: CreateRuleDto) {
    const interval = dto.intervalMinutes ?? null;
    const nextExecAt = dto.nextExecAt ? new Date(dto.nextExecAt) : null;

    const [res] = await this.db.query(
      `INSERT INTO lead_rules
      (name, description, is_active, conditions_json, actions_json,
       interval_minutes, next_exec_at, apply_batch_size, apply_max_to_update)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        dto.name,
        dto.description ?? null,
        (dto.isActive ?? true) ? 1 : 0,
        JSON.stringify(dto.conditions),
        JSON.stringify(dto.actions),
        interval,
        nextExecAt,
        dto.applyBatchSize ?? null,
        dto.applyMaxToUpdate ?? null,
      ],
    );

    return { id: (res as any).insertId };
  }

  async findAll() {
    const [rows] = await this.db.query(
      `SELECT id, name, description, is_active, created_at, updated_at,
            interval_minutes, next_exec_at, apply_batch_size, apply_max_to_update,
            last_run_at
     FROM lead_rules
     ORDER BY id DESC`,
    );
    return rows;
  }

  async findOne(id: number) {
    const [rows] = await this.db.query(
      `SELECT * FROM lead_rules WHERE id = ?`,
      [id],
    );
    const rule = (rows as any[])[0];
    if (!rule) throw new NotFoundException('Rule not found');

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
      next_exec_at: rule.next_exec_at,
      apply_batch_size: rule.apply_batch_size,
      apply_max_to_update: rule.apply_max_to_update,
      last_run_at: rule.last_run_at,
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

    const nextExecAt =
      dto.nextExecAt !== undefined
        ? dto.nextExecAt
          ? new Date(dto.nextExecAt)
          : null
        : existing.next_exec_at;

    const applyBatchSize =
      dto.applyBatchSize !== undefined
        ? dto.applyBatchSize
        : existing.apply_batch_size;

    const applyMaxToUpdate =
      dto.applyMaxToUpdate !== undefined
        ? dto.applyMaxToUpdate
        : existing.apply_max_to_update;

    await this.db.query(
      `UPDATE lead_rules
     SET name=?, description=?, is_active=?, conditions_json=?, actions_json=?,
         interval_minutes=?, next_exec_at=?, apply_batch_size=?, apply_max_to_update=?
     WHERE id=?`,
      [
        name,
        description,
        isActive ? 1 : 0,
        JSON.stringify(conditions),
        JSON.stringify(actions),
        intervalMinutes,
        nextExecAt,
        applyBatchSize,
        applyMaxToUpdate,
        id,
      ],
    );

    return { ok: true };
  }

  async remove(id: number) {
    await this.db.query(
      `DELETE FROM lead_rules
       WHERE id = ?`,
      [id],
    );
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
      typeof rule.conditions === 'string'
        ? JSON.parse(rule.conditions)
        : rule.conditions;

    const actions = (
      typeof rule.actions === 'string' ? JSON.parse(rule.actions) : rule.actions
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

    if (!targetListId && !targetStatus) {
      return {
        ok: false,
        message: 'No actions specified (move_to_list/update_status)',
      };
    }

    if (!conditionSpec?.where) {
      return {
        ok: false,
        message: 'Rule conditions must include a "where" group (DSL).',
      };
    }

    // 🔒 Require a successful dry-run in last 30 minutes
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

    // Create APPLY run row with STARTED status
    const [runRes] = await this.db.query(
      `INSERT INTO lead_rule_runs
         (rule_id, run_type, matched_count, updated_count, status, sample_json, started_at)
       VALUES
         (?, 'APPLY', 0, 0, 'STARTED', NULL, NOW())`,
      [ruleId],
    );
    const runId = (runRes as any).insertId;

    try {
      // Build WHERE from DSL
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

      // Count matches
      const [countRows] = await this.db.query(
        `SELECT COUNT(*) AS c
         FROM vicidial_list
         ${whereSql}`,
        finalParams,
      );
      const matchedCount = (countRows as any)[0]?.c ?? 0;

      const toUpdate = Math.min(matchedCount, maxToUpdate);
      let updatedTotal = 0;
      // Fetch a sample of leads BEFORE updating, for logging
      const [sampleRows] = await this.db.query(
        `SELECT lead_id, list_id, status, phone_number
   FROM vicidial_list
   ${whereSql}
   ORDER BY lead_id
   LIMIT 50`,
        finalParams,
      );

      while (updatedTotal < toUpdate) {
        const remaining = toUpdate - updatedTotal;
        const currentBatch = Math.min(batchSize, remaining);

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
          set.push(`called_since_last_reset = 'N'`); // see next section
        }

        set.push(`modify_date = NOW()`);

        const sql = `
    UPDATE vicidial_list
    SET ${set.join(', ')}
    ${whereSql}
    LIMIT ${currentBatch}
  `;

        const [res] = await this.db.query(sql, [...updParams, ...finalParams]);
        const affected = (res as any).affectedRows ?? 0;
        if (affected === 0) break;

        updatedTotal += affected;
      }

      // Mark run as SUCCESS
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
        },
      };
    } catch (e: any) {
      // Mark run as FAILED
      await this.db.query(
        `UPDATE lead_rule_runs
         SET status = 'FAILED', error_text = ?, ended_at = NOW()
         WHERE id = ?`,
        [String(e?.message ?? e), runId],
      );
      throw e;
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
}
