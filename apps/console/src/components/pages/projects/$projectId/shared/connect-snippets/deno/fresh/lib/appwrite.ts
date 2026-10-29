import { Client } from "{{DENO_SDK_SPECIFIER}}"

const client = new Client()
  .setEndpoint(Deno.env.get("APPWRITE_ENDPOINT")!)
  .setProject(Deno.env.get("APPWRITE_PROJECT_ID")!)
  .setKey(Deno.env.get("APPWRITE_API_KEY")!)

export { client }
