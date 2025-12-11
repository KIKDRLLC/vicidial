import { IsBoolean, IsObject, IsOptional, IsString } from 'class-validator';

export class CreateRuleDto {
  @IsString()
  name: string;

  @IsOptional()
  @IsString()
  description?: string;

  @IsOptional()
  @IsBoolean()
  isActive?: boolean;

  // ✅ now expects ConditionSpec-like object
  @IsObject()
  conditions: any;

  @IsObject()
  actions: any;
}
