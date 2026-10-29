import { Hono } from "jsr:@hono/hono"
import { Project } from "{{DENO_SDK_SPECIFIER}}"
import { client } from "./lib/appwrite.ts"

const app = new Hono()
const project = new Project(client)

app.patch("/v1/policies", async (c) => {
  const policy = await project.updatePasswordStrengthPolicy({
    min: 8,
    uppercase: true,
    number: true,
    symbols: true
  })
  return c.json(policy)
})

app.get("/v1/policies", async (c) => {
  return c.json(await project.listPolicies())
})

Deno.serve(app.fetch)
