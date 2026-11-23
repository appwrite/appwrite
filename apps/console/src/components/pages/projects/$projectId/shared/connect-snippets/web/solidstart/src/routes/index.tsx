import { A, createAsync, query, revalidate } from '@solidjs/router'
import { Show } from 'solid-js'
import { Account } from 'appwrite'
import { client } from '../lib/appwrite'

const getUser = query(async () => {
  const account = new Account(client)
  return account.get().catch(() => null)
}, 'user')

export const route = {
  preload: () => getUser(),
}

export default function Home() {
  const user = createAsync(() => getUser())

  async function handleSignOut() {
    const account = new Account(client)
    await account.deleteSession({ sessionId: 'current' })
    await revalidate(getUser.key)
  }

  return (
    <Show
      when={user()}
      fallback={
        <div>
          <p>Sign in to get started.</p>
          <p>
            <A href="/sign-in">Sign in</A>
            {' · '}
            <A href="/sign-up">Sign up</A>
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
  )
}
