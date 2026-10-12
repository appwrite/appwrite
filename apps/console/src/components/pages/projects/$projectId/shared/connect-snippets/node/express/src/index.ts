import express from 'express'
import { client } from '../lib/appwrite'
import { Project } from 'node-appwrite'

const app = express()
const project = new Project(client)

app.patch('/v1/policies', async (req, res) => {
  const policy = await project.updatePasswordStrengthPolicy({
    min: 8,
    uppercase: true,
    number: true,
    symbols: true
  })
  res.json(policy)
})

app.get('/v1/policies', async (req, res) => {
  res.json(await project.listPolicies())
})

app.listen(3000, () => console.log('Listening on http://localhost:3000'))
