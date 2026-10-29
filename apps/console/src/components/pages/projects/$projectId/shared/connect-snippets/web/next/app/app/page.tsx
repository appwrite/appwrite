'use client'

import { useEffect, useState } from 'react'
import Link from 'next/link'
import { client } from '@/lib/appwrite'
import { Account } from 'appwrite'

export default function HomePage() {
  const [user, setUser] = useState<{ name: string } | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const account = new Account(client)
    account
      .get()
      .then(setUser)
      .catch(() => setUser(null))
      .finally(() => setLoading(false))
  }, [])

  if (loading) {
    return <p>Loading...</p>
  }

  if (!user) {
    return (
      <div>
        <p>Sign in to get started.</p>
        <p>
          <Link href="/sign-in">Sign in</Link>
          {' · '}
          <Link href="/sign-up">Sign up</Link>
        </p>
      </div>
    )
  }

  async function handleSignOut() {
    const account = new Account(client)
    await account.deleteSession({ sessionId: 'current' })
    setUser(null)
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
