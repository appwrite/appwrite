import { Handlers, PageProps } from "$fresh/server.ts"
import { Users } from "{{DENO_SDK_SPECIFIER}}"
import { client } from "../lib/appwrite.ts"

export const handler: Handlers = {
  async GET(_req, ctx) {
    const users = new Users(client)
    const list = await users.list().catch(() => null)
    return ctx.render({ total: list?.total ?? 0 })
  },
}

export default function Home({ data }: PageProps<{ total: number }>) {
  return <p>Your project has {data.total} users.</p>
}
