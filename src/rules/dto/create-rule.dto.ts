import { IsBoolean, IsObject, IsOptional, IsString } from 'class-validator';
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
}
