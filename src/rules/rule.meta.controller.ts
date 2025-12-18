import { Controller, Get, Query } from '@nestjs/common';
import { RulesMetaService } from './rule.meta.service';
@Controller('rules/meta')
export class RulesMetaController {
  constructor(private readonly meta: RulesMetaService) {}

  // Campaign dropdown (optional but recommended)
  @Get('campaigns')
  campaigns() {
    return this.meta.listCampaigns();
  }

  // Lists dropdown (campaign-aware)
  @Get('lists')
  lists(@Query('campaignId') campaignId?: string) {
    return this.meta.listLists(campaignId);
  }

  // Status dropdown (campaign-aware) - included here for completeness
  @Get('statuses')
  statuses(@Query('campaignId') campaignId?: string) {
    return this.meta.listStatuses(campaignId);
  }
}
