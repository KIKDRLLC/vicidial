import { Inject, Injectable } from '@nestjs/common';
import type { Pool } from 'mysql2/promise';
import { ConditionSpec } from 'src/common/rules/condition-types/condition.types';
import { QueryBuilderService } from 'src/common/rules/query-builder/query-builder.service';

@Injectable()
export class DryRunService {
  constructor(
    @Inject('DB_POOL') private readonly db: Pool,
    private readonly qb: QueryBuilderService,
  ) {}

  async preview(spec: ConditionSpec) {
    if (!spec?.where) {
      throw new Error('Dry-run requires a "where" condition group.');
    }

    const sampleLimit = Math.min(
      Math.max(Number(spec.sampleLimit ?? 20), 1),
      100,
    );

    const { sql: whereSql, params } = this.qb.buildWhere(spec.where);

    // Use query() (not execute()) to avoid your MariaDB "malformed packet" issues
    const [countRows] = await this.db.query(
      `SELECT COUNT(*) AS c FROM vicidial_list ${whereSql}`,
      params,
    );

    const [sampleRows] = await this.db.query(
      `SELECT lead_id, list_id, status, entry_date, last_local_call_time, called_count, called_since_last_reset
       FROM vicidial_list
       ${whereSql}
       ORDER BY lead_id
       LIMIT ${sampleLimit}`,
      params,
    );

    return {
      matchedCount: (countRows as any)[0]?.c ?? 0,
      sample: sampleRows,
    };
  }
}
