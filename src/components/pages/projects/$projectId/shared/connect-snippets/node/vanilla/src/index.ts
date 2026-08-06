import { Client, Project } from 'node-appwrite'

const client = new Client()
  .setEndpoint(process.env.APPWRITE_ENDPOINT)
  .setProject(process.env.APPWRITE_PROJECT_ID)
  .setKey(process.env.APPWRITE_API_KEY)

const project = new Project(client)

const policy = await project.updatePasswordStrengthPolicy({
  min: 8,
  uppercase: true,
  number: true,
  symbols: true
})

console.log(policy)

const policies = await project.listPolicies()

console.log(policies)
