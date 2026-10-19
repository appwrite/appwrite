<script lang="ts">
  import { onMount } from 'svelte'
  import { Account } from 'appwrite'
  import { client } from './lib/appwrite'
  import SignIn from './pages/SignIn.svelte'
  import SignUp from './pages/SignUp.svelte'

  const path = window.location.pathname

  let user: { name: string } | null = $state(null)
  let loading = $state(true)

  onMount(async () => {
    try {
      const account = new Account(client)
      const u = await account.get()
      user = { name: u.name }
    } catch {
      user = null
    } finally {
      loading = false
    }
  })

  async function handleSignOut() {
    const account = new Account(client)
    await account.deleteSession({ sessionId: 'current' })
    user = null
  }
</script>

{#if path === '/sign-in'}
  <SignIn />
{:else if path === '/sign-up'}
  <SignUp />
{:else if loading}
  <p>Loading...</p>
{:else if user}
  <p>Hello, {user.name}</p>
  <button type="button" onclick={handleSignOut}>Sign out</button>
{:else}
  <p>Sign in to get started.</p>
  <p>
    <a href="/sign-in">Sign in</a>
    {' · '}
    <a href="/sign-up">Sign up</a>
  </p>
{/if}
