import { createResource, Show } from 'solid-js'
import { Account } from 'appwrite'
import { client } from './lib/appwrite'
import { SignIn } from './pages/SignIn'
import { SignUp } from './pages/SignUp'

function Home() {
  const [user, { mutate }] = createResource(async () => {
    const account = new Account(client)
    return account.get().catch(() => null)
  })

  async function handleSignOut() {
    const account = new Account(client)
    await account.deleteSession({ sessionId: 'current' })
    mutate(null)
  }

  return (
    <Show when={!user.loading} fallback={<p>Loading...</p>}>
      <Show
        when={user()}
        fallback={
          <div>
            <p>Sign in to get started.</p>
            <p>
              <a href="/sign-in">Sign in</a>
              {' · '}
              <a href="/sign-up">Sign up</a>
            </p>
          </div>
        }
      >
        <div>
          <p>Hello, {user()?.name}</p>
          <button type="button" onClick={handleSignOut}>
            Sign out
          </button>
        </div>
      </Show>
    </Show>
  )
}

export default function App() {
  const path = window.location.pathname
  if (path === '/sign-in') return <SignIn />
  if (path === '/sign-up') return <SignUp />
  return <Home />
}
