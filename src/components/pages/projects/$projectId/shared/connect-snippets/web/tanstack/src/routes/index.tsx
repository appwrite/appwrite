import { createFileRoute } from '@tanstack/react-router'
import { Account } from 'appwrite'
import { client } from '../lib/appwrite'

export const Route = createFileRoute('/')({
  loader: async () => {
    const account = new Account(client)
    const user = await account.get().catch(() => null)
    return { user }
  },
  component: Home,
})

function Home() {
  const { user } = Route.useLoaderData()

  return (
    <div>
      {user ? <p>Hello, {user.name}</p> : <p>Sign in to get started.</p>}
    </div>
  )
}
