import { Hono } from 'hono'
import { client } from './lib/appwrite'
import { Databases } from 'node-appwrite'

const app = new Hono()
const databases = new Databases(client)

app.get('/data', async (c) => {
  try {
    const list = await databases.listCollections('your-database-id')
    return c.json(list)
  } catch (err) {
    return c.json({ error: String(err) }, 500)
  }
})

export default app
