<script lang="ts">
  import { invalidateAll } from '$app/navigation'
  import { Account } from 'appwrite'
  import { client } from '$lib/appwrite'

  let { data } = $props()

  let loading = $state(false)

  async function handleSignOut() {
    loading = true
    try {
      const account = new Account(client)
      await account.deleteSession({ sessionId: 'current' })
      await invalidateAll()
    } catch (err) {
      console.error(err)
    } finally {
      loading = false
    }
  }
</script>

<div>
  {#if loading}
    <p>Loading...</p>
  {:else if data.user}
    <p>Hello, {data.user.name}</p>
    <button type="button" onclick={handleSignOut}>Sign out</button>
  {:else}
    <p>Sign in to get started.</p>
    <p>
      <a href="/sign-in">Sign in</a>
      {' · '}
      <a href="/sign-up">Sign up</a>
    </p>
  {/if}
</div>
