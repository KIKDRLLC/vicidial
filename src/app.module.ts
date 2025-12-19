import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { ScheduleModule } from '@nestjs/schedule';
import { QueryBuilderService } from './common/rules/query-builder/query-builder.service';
import { DatabaseModule } from './database/database.module';
import { DryRunModule } from './dry-run/dry-run.module';
import { RulesModule } from './rules/rules.module';
import { AmdModule } from './amd/amd.module';

@Module({
  imports: [
    ConfigModule.forRoot({ isGlobal: true }),
    DatabaseModule,
    RulesModule,
    DryRunModule,
    ScheduleModule.forRoot(),
    AmdModule, // ✅ enables cron/interval/timeout decorators app-wide
  ],
  providers: [QueryBuilderService],
})
export class AppModule {}
