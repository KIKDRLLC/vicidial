import {
  Body,
  Controller,
  Delete,
  Get,
  Param,
  Patch,
  Post,
  Query,
} from '@nestjs/common';
import { RulesService } from './rules.service';
import { CreateRuleDto } from './dto/create-rule.dto';
import { UpdateRuleDto } from './dto/update-rule.dto';
import { DryRunService } from '../dry-run/dry-run.service';
import { ApplyRuleDto } from './dto/apply-rule.dto';
import { RescheduleRuleDto } from './dto/reschedule.dto';

@Controller('rules')
export class RulesController {
  constructor(
    private readonly rulesService: RulesService,
    private readonly dryRunService: DryRunService,
  ) {}

  @Post()
  create(@Body() dto: CreateRuleDto) {
    return this.rulesService.create(dto);
  }

  @Get()
  findAll() {
    return this.rulesService.findAll();
  }



  @Patch(':id')
  update(@Param('id') id: string, @Body() dto: UpdateRuleDto) {
    return this.rulesService.update(Number(id), dto);
  }

  @Delete(':id')
  remove(@Param('id') id: string) {
    return this.rulesService.remove(Number(id));
  }

  // ✅ Ytel-like: dry-run by rule id

  @Post(':id/dry-run')
  async dryRun(@Param('id') id: string) {
    const rule = await this.rulesService.findOne(Number(id));
    const conditions =
      typeof rule.conditions === 'string'
        ? JSON.parse(rule.conditions)
        : rule.conditions;

    const result = await this.dryRunService.preview(conditions);

    await this.rulesService.logDryRun(
      Number(id),
      result.matchedCount,
      result.sample as any[],
    );

    return result;
  }

  @Post(':id/apply')
  apply(@Param('id') id: string, @Body() dto: ApplyRuleDto) {
    return this.rulesService.applyRule(Number(id), {
      batchSize: dto.batchSize ?? 500,
      maxToUpdate: dto.maxToUpdate ?? 10000,
    });
  }
  @Get(':id/runs')
  runs(@Param('id') id: string) {
    return this.rulesService.listRuns(Number(id));
  }

  @Get('runs/:runId')
  run(@Param('runId') runId: string) {
    return this.rulesService.getRun(Number(runId));
  }

  @Patch(':id/schedule')
  schedule(@Param('id') id: string, @Body() dto: RescheduleRuleDto) {
    return this.rulesService.update(Number(id), {
      nextExecAt: dto.nextExecAt ?? null,
    } as any);
  }

  @Post(':id/run-now')
  async runNow(@Param('id') id: string) {
    const ruleId = Number(id);
    const rule = await this.rulesService.findOne(ruleId);

    const batchSize = rule.apply_batch_size ?? 500;
    const maxToUpdate = rule.apply_max_to_update ?? 10000;

    return this.rulesService.applyRule(ruleId, { batchSize, maxToUpdate });
  }

  @Get('meta/statuses')
  statuses(@Query('campaignId') campaignId?: string) {
    return this.rulesService.listStatuses(campaignId);
  }


  @Post(':id/clone')
  clone(@Param('id') id: string, @Body() body?: { name?: string }) {
  return this.rulesService.cloneRule(Number(id), body?.name);
  }
  

    @Get(':id')
  findOne(@Param('id') id: string) {
    return this.rulesService.findOne(Number(id));
  }

}
