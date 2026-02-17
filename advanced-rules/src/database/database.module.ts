import { Global, Module } from '@nestjs/common';
import { ConfigModule, ConfigService } from '@nestjs/config';
import { createPool } from 'mysql2/promise';

@Global()
@Module({
  imports: [ConfigModule],
  providers: [
    {
      provide: 'DB_POOL',
      inject: [ConfigService],
      useFactory: async (config: ConfigService) => {
        const pool = createPool({
          host: config.get<string>('DB_HOST'),
          port: Number(config.get<number>('DB_PORT') ?? 3306),
          user: config.get<string>('DB_USER'),
          password: config.get<string>('DB_PASS'),
          database: config.get<string>('DB_NAME'),

          waitForConnections: true,

          // ✅ reduce crash risk in cluster (per worker)
          connectionLimit: Number(config.get<number>('DB_POOL_LIMIT') ?? 5),

          // ✅ prevent unbounded pending queries
          queueLimit: Number(config.get<number>('DB_POOL_QUEUE_LIMIT') ?? 2000),

          // ✅ avoid stuck connects
          connectTimeout: Number(
            config.get<number>('DB_CONNECT_TIMEOUT_MS') ?? 10000,
          ),

          // ✅ keep connections stable
          enableKeepAlive: true,
          keepAliveInitialDelay: 0,

          // ✅ TIMEZONE / DATETIME SAFETY
          timezone: 'Z', // driver treats dates as UTC
          dateStrings: ['DATE', 'DATETIME', 'TIMESTAMP'], // return these as strings (not JS Date)
        });

        // ✅ fail fast if DB unreachable
        await pool.query('SELECT 1');

        // ✅ enforce session timezone for all pooled connections
        // (prevents surprises from MySQL global/session tz differences)
        const conn = await pool.getConnection();
        try {
          await conn.query("SET time_zone = '+00:00'");
        } finally {
          conn.release();
        }

        return pool;
      },
    },
  ],
  exports: ['DB_POOL'],
})
export class DatabaseModule {}
