<script lang="ts">
  import { Account } from 'appwrite'
  import { client } from '../lib/appwrite'

  let email = $state('')
  let password = $state('')
  let error = $state('')

  async function handleSubmit(e: SubmitEvent) {
    e.preventDefault()
    error = ''
    try {
      const account = new Account(client)
      await account.createEmailPasswordSession({ email, password })
      window.location.href = '/'
    } catch (err) {
      error = err instanceof Error ? err.message : 'Sign in failed'
    }
  }
</script>

<form onsubmit={handleSubmit}>
  <h1>Sign in</h1>
  {#if error}
    <p>{error}</p>
  {/if}
  <input
    type="email"
    placeholder="Email"
    bind:value={email}
    required
  />
  <input
    type="password"
    placeholder="Password"
    bind:value={password}
    required
  />
  <button type="submit">Sign in</button>
  <p>
    No account? <a href="/sign-up">Sign up</a>
  </p>
</form>
