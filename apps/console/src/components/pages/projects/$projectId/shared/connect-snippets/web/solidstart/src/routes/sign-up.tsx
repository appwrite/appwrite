import { A, revalidate, useNavigate } from '@solidjs/router'
import { createSignal, Show } from 'solid-js'
import { Account, ID } from 'appwrite'
import { client } from '../lib/appwrite'

export default function SignUpPage() {
  const navigate = useNavigate()
  const [name, setName] = createSignal('')
  const [email, setEmail] = createSignal('')
  const [password, setPassword] = createSignal('')
  const [error, setError] = createSignal('')

  async function handleSubmit(e: SubmitEvent) {
    e.preventDefault()
    setError('')
    try {
      const account = new Account(client)
      await account.create({
        userId: ID.unique(),
        email: email(),
        password: password(),
        name: name().trim() || undefined,
      })
      await account.createEmailPasswordSession({
        email: email(),
        password: password(),
      })
      await revalidate('user')
      navigate('/')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign up failed')
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <h1>Sign up</h1>
      <Show when={error()}>
        <p>{error()}</p>
      </Show>
      <input
        type="text"
        placeholder="Name"
        value={name()}
        onInput={(e) => setName(e.currentTarget.value)}
      />
      <input
        type="email"
        placeholder="Email"
        value={email()}
        onInput={(e) => setEmail(e.currentTarget.value)}
        required
      />
      <input
        type="password"
        placeholder="Password"
        value={password()}
        onInput={(e) => setPassword(e.currentTarget.value)}
        required
      />
      <button type="submit">Sign up</button>
      <p>
        Already have an account? <A href="/sign-in">Sign in</A>
      </p>
    </form>
  )
}
