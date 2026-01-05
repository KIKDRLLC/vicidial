// dto/reschedule-rule.dto.ts
import { IsDateString, IsOptional } from 'class-validator';

export class RescheduleRuleDto {
  @IsOptional()
  @IsDateString()
  nextExecAt?: string; // null/undefined = clear
}
