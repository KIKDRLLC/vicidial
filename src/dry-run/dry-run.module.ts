import { Module } from '@nestjs/common';
import { DryRunController } from './dry-run.controller';
import { DryRunService } from './dry-run.service';
import { DatabaseModule } from '../database/database.module';
import { QueryBuilderService } from '../common/rules/query-builder/query-builder.service';
@Module({
  imports: [DatabaseModule],
  controllers: [DryRunController],
  providers: [DryRunService, QueryBuilderService],
  exports: [DryRunService],
})
export class DryRunModule {}
