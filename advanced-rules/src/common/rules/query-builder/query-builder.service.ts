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

  // Optional: restrict which operators can be used on which fields
  private allowedOpsByField: Record<string, Set<string>> = {
    // numeric-ish fields
    lead_id: new Set(['=', '!=', '<', '<=', '>', '>=', 'IN', 'NOT_IN']),
    list_id: new Set(['=', '!=', 'IN', 'NOT_IN']),
    called_count: new Set(['=', '!=', '<', '<=', '>', '>=', 'IN', 'NOT_IN']),
    called_since_last_reset: new Set(['=', '!=', 'IN', 'NOT_IN']),

    // date/time fields
    entry_date: new Set([
      '=',
      '!=',
      '<',
      '<=',
      '>',
      '>=',
      'IS_NULL',
      'IS_NOT_NULL',
      'OLDER_THAN_DAYS',
      'NEWER_THAN_DAYS',
    ]),
    last_local_call_time: new Set([
      '=',
      '!=',
      '<',
      '<=',
      '>',
      '>=',
      'IS_NULL',
      'IS_NOT_NULL',
      'OLDER_THAN_DAYS',
      'NEWER_THAN_DAYS',
    ]),

    // text-ish fields
    status: new Set([
      '=',
      '!=',
      'IN',
      'NOT_IN',
      'LIKE',
      'STARTS_WITH',
      'ENDS_WITH',
      'CONTAINS',
    ]),
    owner: new Set([
      '=',
      '!=',
      'IN',
      'NOT_IN',
      'LIKE',
      'STARTS_WITH',
      'ENDS_WITH',
      'CONTAINS',
    ]),
    vendor_lead_code: new Set([
      '=',
      '!=',
      'LIKE',
      'STARTS_WITH',
      'ENDS_WITH',
      'CONTAINS',
    ]),
    source_id: new Set([
      '=',
      '!=',
      'IN',
      'NOT_IN',
      'LIKE',
      'STARTS_WITH',
      'ENDS_WITH',
      'CONTAINS',
    ]),
    phone_number: new Set([
      '=',
      '!=',
      'LIKE',
      'STARTS_WITH',
      'ENDS_WITH',
      'CONTAINS',
    ]),
    state: new Set(['=', '!=', 'IN', 'NOT_IN']),
    postal_code: new Set([
      '=',
      '!=',
      'LIKE',
      'STARTS_WITH',
      'ENDS_WITH',
      'CONTAINS',
    ]),
    country_code: new Set(['=', '!=', 'IN', 'NOT_IN']),
    gmt_offset_now: new Set(['=', '!=', '<', '<=', '>', '>=', 'IN', 'NOT_IN']),
  };

  /**
   * Build a WHERE clause from a ConditionGroup.
   * Returns:
   *   sql: e.g. "WHERE status IN (?, ?) AND list_id = ?"
   *   params: the values for "?"
   */
  buildWhere(group: ConditionGroup): { sql: string; params: any[] } {
    const params: any[] = [];
    const sqlCore = this.buildGroup(group, params);
    return { sql: sqlCore ? `WHERE ${sqlCore}` : '', params };
  }

  private buildGroup(group: ConditionGroup, params: any[]): string {
    if (!group?.rules?.length) return '';

    if (group.op !== 'AND' && group.op !== 'OR') {
      throw new BadRequestException(`Unsupported logic operator: ${group.op}`);
    }

    const parts = group.rules
      .map((r) =>
        this.isGroup(r)
          ? this.wrap(this.buildGroup(r, params))
          : this.buildRule(r as ConditionRule, params),
      )
      .filter((s) => !!s);

    return parts.length ? parts.join(` ${group.op} `) : '';
  }

  private buildRule(rule: ConditionRule, params: any[]): string {
    const col = this.fieldMap[rule.field];
    if (!col) {
      throw new BadRequestException(`Unsupported field: ${rule.field}`);
    }

    const allowedOps = this.allowedOpsByField[rule.field];
    if (allowedOps && !allowedOps.has(rule.op)) {
      throw new BadRequestException(
        `Operator ${rule.op} is not allowed for field ${rule.field}`,
      );
    }

    switch (rule.op) {
      case '=':
      case '!=':
      case '<':
      case '<=':
      case '>':
      case '>=': {
        if (rule.value === undefined) {
          throw new BadRequestException(`${rule.op} requires a value`);
        }
        params.push(rule.value);
        return `${col} ${rule.op} ?`;
      }

      case 'IN':
      case 'NOT_IN': {
        if (!Array.isArray(rule.value) || rule.value.length === 0) {
          throw new BadRequestException(
            `${rule.op} requires a non-empty array`,
          );
        }
        const ph = rule.value.map(() => '?').join(',');
        params.push(...rule.value);
        return `${col} ${rule.op === 'IN' ? 'IN' : 'NOT IN'} (${ph})`;
      }

      case 'LIKE':
      case 'STARTS_WITH':
      case 'ENDS_WITH':
      case 'CONTAINS': {
        if (rule.value === undefined || rule.value === null) {
          throw new BadRequestException(
            `${rule.op} requires a non-empty value`,
          );
        }
        const v = String(rule.value);
        switch (rule.op) {
          case 'LIKE':
            params.push(v);
            return `${col} LIKE ?`;
          case 'STARTS_WITH':
            params.push(`${v}%`);
            return `${col} LIKE ?`;
          case 'ENDS_WITH':
            params.push(`%${v}`);
            return `${col} LIKE ?`;
          case 'CONTAINS':
            params.push(`%${v}%`);
            return `${col} LIKE ?`;
        }
      }

      case 'IS_NULL':
        return `${col} IS NULL`;

      case 'IS_NOT_NULL':
        return `${col} IS NOT NULL`;

      case 'OLDER_THAN_DAYS': {
        const days = Number(rule.value);
        if (!Number.isFinite(days) || days < 0) {
          throw new BadRequestException(
            'OLDER_THAN_DAYS requires a non-negative number',
          );
        }
        params.push(days);
        // "older than N days" => date is at least N days in the past
        return `${col} IS NOT NULL AND ${col} <= (NOW() - INTERVAL ? DAY)`;
      }

      case 'NEWER_THAN_DAYS': {
        const days = Number(rule.value);
        if (!Number.isFinite(days) || days < 0) {
          throw new BadRequestException(
            'NEWER_THAN_DAYS requires a non-negative number',
          );
        }
        params.push(days);
        // "newer than N days" => date is at most N days in the past
        return `${col} IS NOT NULL AND ${col} >= (NOW() - INTERVAL ? DAY)`;
      }

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
