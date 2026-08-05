import { Elysia } from 'elysia'
import { client } from './lib/appwrite'
import { Databases } from 'node-appwrite'

const databases = new Databases(client)

const app = new Elysia()
  .get('/data', () => databases.listCollections('your-database-id'))
  .listen(3000)

console.log(`Listening on http://localhost:${app.server?.port}`)
