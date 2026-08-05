import { createAsync, query } from '@solidjs/router'
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

  return (
    <div>
      {user() ? <p>Hello, {user()?.name}</p> : <p>Sign in to get started.</p>}
    </div>
  )
}
