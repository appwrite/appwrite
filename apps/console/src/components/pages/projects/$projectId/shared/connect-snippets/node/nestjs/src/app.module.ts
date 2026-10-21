import { Module } from '@nestjs/common'
import { Project } from 'node-appwrite'
import { AppController } from './app.controller'
import { client } from './lib/appwrite'

@Module({
  controllers: [AppController],
  providers: [{ provide: Project, useFactory: () => new Project(client) }],
})
export class AppModule {}
