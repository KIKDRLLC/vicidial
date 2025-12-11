import { Module } from '@nestjs/common';
import { RulesService } from './rules.service';
import { RulesController } from './rules.controller';
import { DatabaseModule } from 'src/database/database.module';
import { DryRunModule } from 'src/dry-run/dry-run.module';
import { QueryBuilderService } from 'src/common/rules/query-builder/query-builder.service';

@Module({
  imports: [DatabaseModule, DryRunModule], // ✅ bring in DryRunService

  providers: [RulesService, QueryBuilderService],
  controllers: [RulesController],
})
export class RulesModule {}
