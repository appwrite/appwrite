import { useState, type SubmitEvent } from 'react'
import Link from 'next/link'
import { useRouter } from 'next/router'
import { Account } from 'appwrite'
import { client } from '@/lib/appwrite'

export default function SignInPage() {
  const router = useRouter()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit(e: SubmitEvent<HTMLFormElement>) {
    e.preventDefault()
    setError('')
    try {
      const account = new Account(client)
      await account.createEmailPasswordSession({ email, password })
      router.push('/')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign in failed')
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <h1>Sign in</h1>
      {error ? <p>{error}</p> : null}
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
      <button type="submit">Sign in</button>
      <p>
        No account? <Link href="/sign-up">Sign up</Link>
      </p>
    </form>
  )
}
