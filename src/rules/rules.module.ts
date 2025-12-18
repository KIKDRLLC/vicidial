import { Module } from '@nestjs/common';
import { RulesService } from './rules.service';
import { RulesController } from './rules.controller';
import { DatabaseModule } from 'src/database/database.module';
import { DryRunModule } from 'src/dry-run/dry-run.module';
import { QueryBuilderService } from 'src/common/rules/query-builder/query-builder.service';
import { RulesSchedulerService } from './rules.scheduler';
import { RulesMetaService } from './rule.meta.service';
import { RulesMetaController } from './rule.meta.controller';

@Module({
  imports: [DatabaseModule, DryRunModule], // ✅ bring in DryRunService

  providers: [
    RulesService,
    QueryBuilderService,
    RulesSchedulerService,
    RulesMetaService,
  ],
  controllers: [RulesController, RulesMetaController],
})
export class RulesModule {}
