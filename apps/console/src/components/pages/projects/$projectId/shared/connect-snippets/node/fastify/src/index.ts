import Fastify from 'fastify'
import { client } from '../lib/appwrite'
import { Project } from 'node-appwrite'

const app = Fastify()
const project = new Project(client)

app.patch('/v1/policies', async () => {
  return await project.updatePasswordStrengthPolicy({
    min: 8,
    uppercase: true,
    number: true,
    symbols: true
  })
})

app.get('/v1/policies', async () => {
  return await project.listPolicies()
})

await app.listen({ port: 3000 })
console.log('Listening on http://localhost:3000')
