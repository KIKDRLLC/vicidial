import { Module } from '@nestjs/common';
import { ConfigModule, ConfigService } from '@nestjs/config';
import { createPool } from 'mysql2/promise';

@Module({
  imports: [ConfigModule],
  providers: [
    {
      provide: 'DB_POOL',
      inject: [ConfigService],
      useFactory: async (config: ConfigService) => {
        return createPool({
          host: config.get<string>('DB_HOST'),
          port: config.get<number>('DB_PORT'),
          user: config.get<string>('DB_USER'),
          password: config.get<string>('DB_PASS'),
          database: config.get<string>('DB_NAME'),
          waitForConnections: true,
          connectionLimit: 10,
        });
      },
    },
  ],
  exports: ['DB_POOL'],
})
export class DatabaseModule {}
