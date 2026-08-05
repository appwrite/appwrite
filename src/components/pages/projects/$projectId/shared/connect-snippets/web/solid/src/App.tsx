import { createResource, Show } from 'solid-js'
import { Account } from 'appwrite'
import { client } from './lib/appwrite'

async function fetchUser() {
  const account = new Account(client)
  return account.get().catch(() => null)
}

export default function App() {
  const [user] = createResource(fetchUser)

  return (
    <Show when={user()} fallback={<p>Sign in to get started.</p>}>
      <p>Hello, {user()?.name}</p>
    </Show>
  )
}
