import { Elysia } from 'elysia'
import { client } from './lib/appwrite'
import { Project } from 'node-appwrite'

const project = new Project(client)

const app = new Elysia()
  .patch('/v1/policies', () =>
    project.updatePasswordStrengthPolicy({
      min: 8,
      uppercase: true,
      number: true,
      symbols: true
    })
  )
  .get('/v1/policies', () => project.listPolicies())
  .listen(3000)

console.log(`Listening on http://localhost:${app.server?.port}`)
