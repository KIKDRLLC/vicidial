// dto/reschedule-rule.dto.ts
import { IsDateString, IsOptional, IsString } from 'class-validator';

export class RescheduleRuleDto {
  @IsOptional()
  @IsDateString()
  nextExecAt?: string; // null/undefined = clear


   @IsOptional()
  @IsString()
  scheduleTimeZone?: string;
}
