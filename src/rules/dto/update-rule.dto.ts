import { IsBoolean, IsObject, IsOptional, IsString } from 'class-validator';

export class UpdateRuleDto {
  @IsOptional()
  @IsString()
  name?: string;

  @IsOptional()
  @IsString()
  description?: string;

  @IsOptional()
  @IsBoolean()
  isActive?: boolean;

  @IsOptional()
  @IsObject()
  conditions?: any;

  @IsOptional()
  @IsObject()
  actions?: any;
}
