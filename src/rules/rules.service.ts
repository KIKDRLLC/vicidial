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
    const [res] = await this.db.query(
      `INSERT INTO lead_rules (name, description, is_active, conditions_json, actions_json)
       VALUES (?, ?, ?, ?, ?)`,
      [
        dto.name,
        dto.description ?? null,
        (dto.isActive ?? true) ? 1 : 0,
        JSON.stringify(dto.conditions),
        JSON.stringify(dto.actions),
      ],
    );

    return { id: (res as any).insertId };
  }

  async findAll() {
    const [rows] = await this.db.query(
      `SELECT id, name, description, is_active, created_at, updated_at
       FROM lead_rules
       ORDER BY id DESC`,
    );
    return rows;
  }

  async findOne(id: number) {
    const [rows] = await this.db.query(
      `SELECT *
       FROM lead_rules
       WHERE id = ?`,
      [id],
    );
    const rule = (rows as any[])[0];
    if (!rule) {
      throw new NotFoundException('Rule not found');
    }
    return rule;
  }

  async update(id: number, dto: UpdateRuleDto) {
    const existing = await this.findOne(id);

    const name = dto.name ?? existing.name;
    const description = dto.description ?? existing.description;
    const isActive = dto.isActive ?? existing.is_active === 1;

    const existingConditions =
      typeof existing.conditions_json === 'string'
        ? JSON.parse(existing.conditions_json)
        : existing.conditions_json;

    const existingActions =
      typeof existing.actions_json === 'string'
        ? JSON.parse(existing.actions_json)
        : existing.actions_json;

    const conditions = dto.conditions ?? existingConditions;
    const actions = dto.actions ?? existingActions;

    await this.db.query(
      `UPDATE lead_rules
       SET name = ?, description = ?, is_active = ?, conditions_json = ?, actions_json = ?
       WHERE id = ?`,
      [
        name,
        description,
        isActive ? 1 : 0,
        JSON.stringify(conditions),
        JSON.stringify(actions),
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
      typeof rule.conditions_json === 'string'
        ? JSON.parse(rule.conditions_json)
        : rule.conditions_json;

    const actions = (
      typeof rule.actions_json === 'string'
        ? JSON.parse(rule.actions_json)
        : rule.actions_json
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
        params,
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
}
