<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { client } from './lib/appwrite'
import { Account } from 'appwrite'
import SignIn from './pages/SignIn.vue'
import SignUp from './pages/SignUp.vue'

const user = ref<{ name: string } | null>(null)
const loading = ref(true)

onMounted(async () => {
  try {
    const account = new Account(client)
    const u = await account.get()
    user.value = { name: u.name }
  } catch {
    user.value = null
  } finally {
    loading.value = false
  }
})

async function handleSignOut() {
  const account = new Account(client)
  await account.deleteSession({ sessionId: 'current' })
  user.value = null
}

const path = computed(() => window.location.pathname)
</script>

<template>
  <SignIn v-if="path === '/sign-in'" />
  <SignUp v-else-if="path === '/sign-up'" />
  <div v-else>
    <p v-if="loading">Loading...</p>
    <template v-else-if="!user">
      <p>Sign in to get started.</p>
      <p>
        <a href="/sign-in">Sign in</a>
        &middot;
        <a href="/sign-up">Sign up</a>
      </p>
    </template>
    <template v-else>
      <p>Hello, {{ user.name }}</p>
      <button type="button" @click="handleSignOut">Sign out</button>
    </template>
  </div>
</template>
