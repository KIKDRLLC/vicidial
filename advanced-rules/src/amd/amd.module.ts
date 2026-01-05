import { Module } from '@nestjs/common';
import { AmdController } from './amd.controller';
import { AmdService } from './amd.service';
import { DatabaseModule } from 'src/database/database.module';

@Module({
  imports: [DatabaseModule],
  controllers: [AmdController],
  providers: [AmdService],
})
export class AmdModule {}
