import { useEffect, useState } from 'react'
import { client } from './lib/appwrite'
import { Account } from 'react-native-appwrite'

export default function App() {
  const [user, setUser] = useState<{ name: string } | null>(null)

  useEffect(() => {
    const account = new Account(client)
    account.get().then((u) => setUser({ name: u.name })).catch(() => {})
  }, [])

  return (
    <div>
      {user ? <p>Hello, {user.name}</p> : <p>Sign in to get started.</p>}
    </div>
  )
}
