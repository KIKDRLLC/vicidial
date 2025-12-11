import { BadRequestException, Injectable } from '@nestjs/common';
import {
  ConditionGroup,
  ConditionRule,
} from '../condition-types/condition.types';

@Injectable()
export class QueryBuilderService {
  // Map “DSL field” -> actual column in vicidial_list
  private fieldMap: Record<string, string> = {
    lead_id: 'lead_id',
    list_id: 'list_id',
    status: 'status',
    called_count: 'called_count',
    called_since_last_reset: 'called_since_last_reset',
    entry_date: 'entry_date',
    last_local_call_time: 'last_local_call_time',
    owner: 'owner',
    vendor_lead_code: 'vendor_lead_code',
    source_id: 'source_id',
    phone_number: 'phone_number',
    state: 'state',
    postal_code: 'postal_code',
    country_code: 'country_code',
    gmt_offset_now: 'gmt_offset_now',
  };

  buildWhere(group: ConditionGroup): { sql: string; params: any[] } {
    const params: any[] = [];
    const sql = this.buildGroup(group, params);
    return { sql: sql ? `WHERE ${sql}` : '', params };
  }

  private buildGroup(group: ConditionGroup, params: any[]): string {
    if (!group?.rules?.length) return '';

    const parts = group.rules
      .map((r) =>
        this.isGroup(r)
          ? this.wrap(this.buildGroup(r, params))
          : this.buildRule(r, params),
      )
      .filter(Boolean);

    return parts.length ? parts.join(` ${group.op} `) : '';
  }

  private buildRule(rule: ConditionRule, params: any[]): string {
    const col = this.fieldMap[rule.field];
    if (!col) throw new BadRequestException(`Unsupported field: ${rule.field}`);

    switch (rule.op) {
      case '=':
      case '!=':
      case '<':
      case '<=':
      case '>':
      case '>=':
        params.push(rule.value);
        return `${col} ${rule.op} ?`;

      case 'IN':
      case 'NOT_IN': {
        if (!Array.isArray(rule.value) || rule.value.length === 0) {
          throw new BadRequestException(`${rule.op} requires non-empty array`);
        }
        const ph = rule.value.map(() => '?').join(',');
        params.push(...rule.value);
        return `${col} ${rule.op === 'IN' ? 'IN' : 'NOT IN'} (${ph})`;
      }

      case 'LIKE':
        params.push(rule.value);
        return `${col} LIKE ?`;

      case 'STARTS_WITH':
        params.push(`${rule.value}%`);
        return `${col} LIKE ?`;

      case 'ENDS_WITH':
        params.push(`%${rule.value}`);
        return `${col} LIKE ?`;

      case 'CONTAINS':
        params.push(`%${rule.value}%`);
        return `${col} LIKE ?`;

      case 'IS_NULL':
        return `${col} IS NULL`;

      case 'IS_NOT_NULL':
        return `${col} IS NOT NULL`;

      case 'OLDER_THAN_DAYS':
        params.push(rule.value);
        return `${col} IS NOT NULL AND ${col} <= (NOW() - INTERVAL ? DAY)`;

      case 'NEWER_THAN_DAYS':
        params.push(rule.value);
        return `${col} IS NOT NULL AND ${col} >= (NOW() - INTERVAL ? DAY)`;

      default:
        throw new BadRequestException(`Unsupported operator: ${rule.op}`);
    }
  }

  private isGroup(x: any): x is ConditionGroup {
    return (
      x && typeof x === 'object' && 'op' in x && 'rules' in x && !('field' in x)
    );
  }

  private wrap(sql: string): string {
    return sql ? `(${sql})` : '';
  }
}
