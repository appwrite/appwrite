<script setup lang="ts">
import { Account } from 'appwrite'
import { client } from '~/utils/appwrite'

const { data: user } = await useAsyncData('user', async () => {
  try {
    const account = new Account(client)
    return await account.get()
  } catch {
    return null
  }
})
</script>

<template>
  <div>
    <p v-if="user">Hello, {{ user.name }}</p>
    <p v-else>Sign in to get started.</p>
  </div>
</template>
