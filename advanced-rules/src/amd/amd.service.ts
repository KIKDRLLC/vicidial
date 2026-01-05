import { Inject, Injectable, Logger } from '@nestjs/common';
import type { Pool } from 'mysql2/promise';
import { AmdEventDto } from './dto/amd.dto';

@Injectable()
export class AmdService {
  private readonly logger = new Logger(AmdService.name);

  constructor(@Inject('DB_POOL') private readonly db: Pool) {}

  async insertEvent(dto: AmdEventDto, rawPayload: any) {
    // Normalize status a bit (dialers sometimes send weird casing)
    const amdStatus = (dto.amd_status || '').toUpperCase().slice(0, 16);

    const sql = `
      INSERT INTO amd_events
        (call_uniqueid, lead_id, dialer_host, amd_status, amd_cause, recording_path, raw_payload)
      VALUES
        (?, ?, ?, ?, ?, ?, ?)
    `;

    const params = [
      dto.call_uniqueid,
      dto.lead_id ?? null,
      dto.dialer_host ?? null,
      amdStatus,
      dto.amd_cause ?? null,
      dto.recording_path ?? null,
      JSON.stringify(rawPayload ?? dto),
    ];

    await this.db.execute(sql, params);

    return { ok: true };
  }
}
