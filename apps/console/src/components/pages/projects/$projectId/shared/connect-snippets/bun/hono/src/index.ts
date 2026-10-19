import { Hono } from 'hono'
import { client } from './lib/appwrite'
import { Project } from 'node-appwrite'

const app = new Hono()
const project = new Project(client)

app.patch('/v1/policies', async (c) => {
  const policy = await project.updatePasswordStrengthPolicy({
    min: 8,
    uppercase: true,
    number: true,
    symbols: true
  })
  return c.json(policy)
})

app.get('/v1/policies', async (c) => {
  return c.json(await project.listPolicies())
})

export default app
