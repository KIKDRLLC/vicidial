import { IsInt, IsOptional, IsString, MaxLength } from 'class-validator';

export class AmdEventDto {
  @IsString()
  @MaxLength(64)
  call_uniqueid!: string;

  @IsOptional()
  @IsInt()
  lead_id?: number;

  @IsOptional()
  @IsString()
  @MaxLength(128)
  dialer_host?: string;

  @IsString()
  @MaxLength(16)
  amd_status!: string; // HUMAN | MACHINE | UNKNOWN

  @IsOptional()
  @IsString()
  @MaxLength(64)
  amd_cause?: string;

  @IsOptional()
  @IsString()
  recording_path?: string;
}
