<script lang="ts">
  import { goto } from '$app/navigation'
  import { Account, ID } from 'appwrite'
  import { client } from '$lib/appwrite'

  let name = $state('')
  let email = $state('')
  let password = $state('')
  let error = $state('')

  async function handleSubmit(e: SubmitEvent) {
    e.preventDefault()
    error = ''
    try {
      const account = new Account(client)
      await account.create({
        userId: ID.unique(),
        email,
        password,
        name: name.trim() || undefined,
      })
      await account.createEmailPasswordSession({ email, password })
      await goto('/', { invalidateAll: true })
    } catch (err) {
      error = err instanceof Error ? err.message : 'Sign up failed'
    }
  }
</script>

<form onsubmit={handleSubmit}>
  <h1>Sign up</h1>
  {#if error}
    <p>{error}</p>
  {/if}
  <input
    type="text"
    placeholder="Name"
    bind:value={name}
  />
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
  <button type="submit">Sign up</button>
  <p>
    Already have an account? <a href="/sign-in">Sign in</a>
  </p>
</form>
