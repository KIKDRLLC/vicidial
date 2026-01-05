import { Body, Controller, Ip, Post } from '@nestjs/common';
import { AmdService } from './amd.service';
import { AmdEventDto } from './dto/amd.dto';

@Controller('amd')
export class AmdController {
  constructor(private readonly amdService: AmdService) {}

  @Post('event')
  async ingest(@Body() dto: AmdEventDto, @Ip() ip: string) {
    // ip is useful for logging; you said inbound is already limited to VICIdial
    return this.amdService.insertEvent(dto, dto);
  }
}
