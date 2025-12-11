import {
  Body,
  Controller,
  Delete,
  Get,
  Param,
  Patch,
  Post,
} from '@nestjs/common';
import { RulesService } from './rules.service';
import { CreateRuleDto } from './dto/create-rule.dto';
import { UpdateRuleDto } from './dto/update-rule.dto';
import { DryRunService } from '../dry-run/dry-run.service';
import { ApplyRuleDto } from './dto/apply-rule.dto';

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

  @Get(':id')
  findOne(@Param('id') id: string) {
    return this.rulesService.findOne(Number(id));
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
      typeof rule.conditions_json === 'string'
        ? JSON.parse(rule.conditions_json)
        : rule.conditions_json;

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
}
