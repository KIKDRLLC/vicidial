import { ValidationPipe } from '@nestjs/common';
import { NestFactory } from '@nestjs/core';
import { AppModule } from './app.module';
import * as nodeCrypto from 'crypto';

if (!(globalThis as any).crypto) {
  (globalThis as any).crypto = nodeCrypto;
}

async function bootstrap() {
  const app = await NestFactory.create(AppModule);

  app.useGlobalPipes(
    new ValidationPipe({
      whitelist: true,
      transform: true,
      transformOptions: { enableImplicitConversion: true },
      forbidNonWhitelisted: true,
    }),
  );

  await app.listen(process.env.PORT || 3000, '0.0.0.0');
}
bootstrap();
