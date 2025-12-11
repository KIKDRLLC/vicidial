import {
  ArrayMinSize,
  IsArray,
  IsInt,
  IsOptional,
  IsString,
  Max,
  Min,
} from 'class-validator';
import { Transform, Type } from 'class-transformer';

export class PreviewDryRunDto {
  @Transform(({ value }) => (Array.isArray(value) ? value : [value]))
  @IsArray()
  @ArrayMinSize(1)
  @Type(() => Number)
  @IsInt({ each: true })
  listIds: number[];

  @Transform(({ value }) => (Array.isArray(value) ? value : [value]))
  @IsArray()
  @ArrayMinSize(1)
  @IsString({ each: true })
  statuses: string[];

  @IsOptional()
  @IsInt()
  @Min(0)
  @Max(3650)
  @Type(() => Number)
  minDaysSinceEntry?: number;

  @IsOptional()
  @IsInt()
  @Min(0)
  @Max(3650)
  @Type(() => Number)
  minDaysSinceLastCall?: number;

  @IsOptional()
  @IsInt()
  @Min(0)
  @Max(9999)
  @Type(() => Number)
  calledCountEq?: number;

  @IsOptional()
  @IsInt()
  @Min(1)
  @Max(100)
  @Type(() => Number)
  sampleLimit?: number = 20;
}
