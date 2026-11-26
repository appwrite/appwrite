// A plain NestJS app with no Appwrite code, run as a function with the
// `nestjs` framework: built to dist/, it only has to listen on process.env.PORT.
import 'reflect-metadata';
import { NestFactory } from '@nestjs/core';
import { AppModule } from './app.module';

async function bootstrap() {
  const app = await NestFactory.create(AppModule);
  await app.listen(process.env.PORT ?? 3000);
}

bootstrap();
