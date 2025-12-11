import { Body, Controller, Post } from '@nestjs/common';
import { DryRunService } from './dry-run.service';

@Controller('dry-run')
export class DryRunController {
  constructor(private readonly dryRunService: DryRunService) {}
  @Post('preview')
  preview(@Body() body: any) {
    return this.dryRunService.preview(body);
  }
}
