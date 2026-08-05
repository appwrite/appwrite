import express from 'express'
import { client } from '../lib/appwrite'
import { Databases } from 'node-appwrite'

const app = express()
const databases = new Databases(client)

app.get('/data', async (req, res) => {
  try {
    const list = await databases.listCollections('your-database-id')
    res.json(list)
  } catch (err) {
    res.status(500).json({ error: String(err) })
  }
})

app.listen(3000, () => console.log('Listening on http://localhost:3000'))
