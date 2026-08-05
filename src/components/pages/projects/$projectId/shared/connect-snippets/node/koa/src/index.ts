import Koa from 'koa'
import Router from '@koa/router'
import { client } from '../lib/appwrite'
import { Databases } from 'node-appwrite'

const app = new Koa()
const router = new Router()
const databases = new Databases(client)

router.get('/data', async (ctx) => {
  try {
    ctx.body = await databases.listCollections('your-database-id')
  } catch (err) {
    ctx.status = 500
    ctx.body = { error: String(err) }
  }
})

app.use(router.routes())
app.listen(3000, () => console.log('Listening on http://localhost:3000'))
