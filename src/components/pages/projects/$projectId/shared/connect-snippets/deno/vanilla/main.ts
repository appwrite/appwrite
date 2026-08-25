import { Client, Project } from "{{DENO_SDK_SPECIFIER}}"

const client = new Client()
  .setEndpoint(Deno.env.get("APPWRITE_ENDPOINT")!)
  .setProject(Deno.env.get("APPWRITE_PROJECT_ID")!)
  .setKey(Deno.env.get("APPWRITE_API_KEY")!)

const project = new Project(client)

const policy = await project.updatePasswordStrengthPolicy({
  min: 8,
  uppercase: true,
  number: true,
  symbols: true
})

console.log(JSON.stringify(policy, null, 2))

const policies = await project.listPolicies()

console.log(JSON.stringify(policies, null, 2))
