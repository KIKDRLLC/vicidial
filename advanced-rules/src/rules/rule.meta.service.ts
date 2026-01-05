import { Inject, Injectable } from '@nestjs/common';
import type { Pool } from 'mysql2/promise';

@Injectable()
export class RulesMetaService {
  constructor(@Inject('DB_POOL') private readonly db: Pool) {}

  async listCampaigns() {
    // VICIdial campaigns table
    const [rows] = await this.db.query(
      `SELECT campaign_id, campaign_name
       FROM vicidial_campaigns
       WHERE active = 'Y'
       ORDER BY campaign_id`,
    );
    return rows;
  }

  async listLists(campaignId?: string) {
    // VICIdial lists table
    if (campaignId && campaignId.trim()) {
      const [rows] = await this.db.query(
        `SELECT list_id, list_name, campaign_id, active
         FROM vicidial_lists
         WHERE campaign_id = ?
         ORDER BY list_id`,
        [campaignId.trim()],
      );
      return rows;
    }

    // All lists if no campaign selected
    const [rows] = await this.db.query(
      `SELECT list_id, list_name, campaign_id, active
       FROM vicidial_lists
       WHERE active = 'Y'
       ORDER BY list_id`,
    );
    return rows;
  }

  async listStatuses(campaignId?: string) {
    // If campaign selected -> campaign statuses
    if (campaignId && campaignId.trim()) {
      const [rows] = await this.db.query(
        `SELECT status, status_name
         FROM vicidial_campaign_statuses
         WHERE campaign_id = ?
         ORDER BY status`,
        [campaignId.trim()],
      );
      return rows;
    }

    // Else -> system statuses
    const [rows] = await this.db.query(
      `SELECT status, status_name
       FROM vicidial_statuses
       ORDER BY status`,
    );
    return rows;
  }
}
