import os from 'os';
import { Inject, Injectable, Logger } from '@nestjs/common';
import { Cron } from '@nestjs/schedule';
import type { Pool } from 'mysql2/promise';
import { RulesService } from './rules.service';

@Injectable()
export class RulesSchedulerService {
  private readonly log = new Logger(RulesSchedulerService.name);
  private readonly workerId = `${os.hostname()}:${process.pid}`;

  constructor(
    @Inject('DB_POOL') private readonly db: Pool,
    private readonly rulesService: RulesService,
  ) {}

  @Cron('*/1 * * * *') // every minute
  async tick() {
    // find a small batch of due rules
    const [rows] = await this.db.query(
      `SELECT id, apply_batch_size, apply_max_to_update, interval_minutes
       FROM lead_rules
       WHERE is_active=1
         AND next_exec_at IS NOT NULL
         AND next_exec_at <= NOW()
         AND (locked_at IS NULL OR locked_at < (NOW() - INTERVAL 5 MINUTE))
       ORDER BY next_exec_at ASC
       LIMIT 5`,
    );

    for (const r of rows as any[]) {
      // lock (MyISAM-safe-ish)
      const [lockRes] = await this.db.query(
        `UPDATE lead_rules
         SET locked_at = NOW(), locked_by = ?
         WHERE id = ?
           AND (locked_at IS NULL OR locked_at < (NOW() - INTERVAL 5 MINUTE))`,
        [this.workerId, r.id],
      );

      if (((lockRes as any).affectedRows ?? 0) !== 1) continue;

      try {
        // run apply using saved settings or defaults
        const batchSize = r.apply_batch_size ?? 500;
        const maxToUpdate = r.apply_max_to_update ?? 10000;

        await this.rulesService.applyRule(r.id, { batchSize, maxToUpdate });

        // schedule next run
        const interval = r.interval_minutes ?? null;
        await this.db.query(
          `UPDATE lead_rules
           SET last_run_at = NOW(),
               next_exec_at = CASE
                 WHEN ? IS NULL THEN NULL
                 ELSE DATE_ADD(NOW(), INTERVAL ? MINUTE)
               END,
               locked_at = NULL, locked_by = NULL
           WHERE id = ?`,
          [interval, interval, r.id],
        );
      } catch (e: any) {
        this.log.error(`Rule ${r.id} failed: ${String(e?.message ?? e)}`);

        // clear lock; optionally push next_exec_at forward to avoid hot-looping
        await this.db.query(
          `UPDATE lead_rules
           SET last_run_at = NOW(),
               next_exec_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE),
               locked_at = NULL, locked_by = NULL
           WHERE id = ?`,
          [r.id],
        );
      }
    }
  }
}
