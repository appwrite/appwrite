<script lang="ts">
  import { onMount } from 'svelte'
  import { client } from './lib/appwrite'
  import { Account } from 'appwrite'

  let user: { name: string } | null = null

  onMount(async () => {
    try {
      const account = new Account(client)
      const u = await account.get()
      user = { name: u.name }
    } catch {
      // Not signed in
    }
  })
</script>

<div>
  {#if user}
    <p>Hello, {user.name}</p>
  {:else}
    <p>Sign in to get started.</p>
  {/if}
</div>
