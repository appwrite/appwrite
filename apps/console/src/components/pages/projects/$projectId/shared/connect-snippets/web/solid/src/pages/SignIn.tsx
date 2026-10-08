import { createSignal, Show } from 'solid-js'
import { Account } from 'appwrite'
import { client } from '../lib/appwrite'

export function SignIn() {
  const [email, setEmail] = createSignal('')
  const [password, setPassword] = createSignal('')
  const [error, setError] = createSignal('')

  async function handleSubmit(e: SubmitEvent) {
    e.preventDefault()
    setError('')
    try {
      const account = new Account(client)
      await account.createEmailPasswordSession({
        email: email(),
        password: password(),
      })
      window.location.href = '/'
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign in failed')
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <h1>Sign in</h1>
      <Show when={error()}>
        <p>{error()}</p>
      </Show>
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
      <button type="submit">Sign in</button>
      <p>
        No account? <a href="/sign-up">Sign up</a>
      </p>
    </form>
  )
}
