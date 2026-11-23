import { useState, type SubmitEvent } from 'react'
import Link from 'next/link'
import { useRouter } from 'next/router'
import { Account, ID } from 'appwrite'
import { client } from '@/lib/appwrite'

export default function SignUpPage() {
  const router = useRouter()
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit(e: SubmitEvent<HTMLFormElement>) {
    e.preventDefault()
    setError('')
    try {
      const account = new Account(client)
      await account.create({
        userId: ID.unique(),
        email,
        password,
        name: name.trim() || undefined,
      })
      await account.createEmailPasswordSession({ email, password })
      router.push('/')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign up failed')
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <h1>Sign up</h1>
      {error ? <p>{error}</p> : null}
      <input
        type="text"
        placeholder="Name"
        value={name}
        onChange={(e) => setName(e.target.value)}
      />
      <input
        type="email"
        placeholder="Email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        required
      />
      <input
        type="password"
        placeholder="Password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        required
      />
      <button type="submit">Sign up</button>
      <p>
        Already have an account? <Link href="/sign-in">Sign in</Link>
      </p>
    </form>
  )
}
