<script setup lang="ts">
import { Account } from 'appwrite'
import { client } from '~/utils/appwrite'

const { data: user, status, refresh } = useAsyncData(
  'user',
  async () => {
    try {
      const account = new Account(client)
      return await account.get()
    } catch {
      return null
    }
  },
  { server: false },
)

async function handleSignOut() {
  const account = new Account(client)
  await account.deleteSession({ sessionId: 'current' })
  await refresh()
}
</script>

<template>
  <div>
    <p v-if="status === 'pending'">Loading...</p>
    <template v-else-if="!user">
      <p>Sign in to get started.</p>
      <p>
        <NuxtLink to="/sign-in">Sign in</NuxtLink>
        &middot;
        <NuxtLink to="/sign-up">Sign up</NuxtLink>
      </p>
    </template>
    <template v-else>
      <p>Hello, {{ user.name }}</p>
      <button type="button" @click="handleSignOut">Sign out</button>
    </template>
  </div>
</template>
