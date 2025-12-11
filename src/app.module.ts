import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { DatabaseModule } from './database/database.module';
import { RulesModule } from './rules/rules.module';
import { DryRunModule } from './dry-run/dry-run.module';
import { QueryBuilderService } from './common/rules/query-builder/query-builder.service';

@Module({
  imports: [
    ConfigModule.forRoot({ isGlobal: true }),
    DatabaseModule,
    RulesModule,
    DryRunModule,
  ],
  providers: [QueryBuilderService],
})
export class AppModule {}
