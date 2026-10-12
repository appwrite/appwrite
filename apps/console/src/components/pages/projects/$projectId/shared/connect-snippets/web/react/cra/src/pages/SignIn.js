import { useState } from 'react'
import { Account } from 'appwrite'
import { client } from '../lib/appwrite'

export function SignIn() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    try {
      const account = new Account(client)
      await account.createEmailPasswordSession({ email, password })
      window.location.href = '/'
    } catch (err) {
      setError(err?.message || 'Sign in failed')
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
        No account? <a href="/sign-up">Sign up</a>
      </p>
    </form>
  )
}
