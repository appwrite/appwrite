import { Handlers } from "$fresh/server.ts"
import { Project } from "{{DENO_SDK_SPECIFIER}}"
import { client } from "../../lib/appwrite.ts"

const project = new Project(client)

export const handler: Handlers = {
  async PATCH() {
    const policy = await project.updatePasswordStrengthPolicy({
      min: 8,
      uppercase: true,
      number: true,
      symbols: true
    })
    return Response.json(policy)
  },
  async GET() {
    return Response.json(await project.listPolicies())
  },
}
