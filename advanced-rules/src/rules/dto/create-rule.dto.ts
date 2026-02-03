import { Type } from 'class-transformer';
import {
  IsBoolean,
  IsDateString,
  IsInt,
  IsObject,
  IsOptional,
  IsString,
  Max,
  Min,
} from 'class-validator';
import type { ActionSpec } from 'src/common/rules/action-types/action.types';
import type { ConditionSpec } from 'src/common/rules/condition-types/condition.types';

export class CreateRuleDto {
  @IsString()
  name: string;

  @IsOptional()
  @IsString()
  description?: string;

  @IsOptional()
  @IsBoolean()
  isActive?: boolean;

  @IsObject()
  conditions: ConditionSpec;

  @IsObject()
  actions: ActionSpec;

  // ---- Automation settings (Ytel-like) ----
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  @Max(10080) // up to 7 days
  intervalMinutes?: number;

  @IsOptional()
  @IsDateString()
  nextExecAt?: string;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  @Max(5000)
  applyBatchSize?: number;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  @Max(50000)
  applyMaxToUpdate?: number;

   @IsOptional()
  @IsString()
  scheduleTimeZone?: string;
}
