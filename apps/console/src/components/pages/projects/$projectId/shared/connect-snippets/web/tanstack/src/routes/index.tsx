import { createFileRoute, Link, useRouter } from '@tanstack/react-router'
import { Account } from 'appwrite'
import { client } from '../lib/appwrite'

export const Route = createFileRoute('/')({
  ssr: false,
  loader: async () => {
    const account = new Account(client)
    const user = await account.get().catch(() => null)
    return { user }
  },
  component: Home,
})

function Home() {
  const router = useRouter()
  const { user } = Route.useLoaderData()

  if (!user) {
    return (
      <div>
        <p>Sign in to get started.</p>
        <p>
          <Link to="/sign-in">Sign in</Link>
          {' · '}
          <Link to="/sign-up">Sign up</Link>
        </p>
      </div>
    )
  }

  async function handleSignOut() {
    const account = new Account(client)
    await account.deleteSession({ sessionId: 'current' })
    await router.invalidate()
  }

  return (
    <div>
      <p>Hello, {user.name}</p>
      <button type="button" onClick={handleSignOut}>
        Sign out
      </button>
    </div>
  )
}
