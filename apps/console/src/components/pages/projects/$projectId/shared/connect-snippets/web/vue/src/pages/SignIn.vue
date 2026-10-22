<script setup lang="ts">
import { ref } from 'vue'
import { Account } from 'appwrite'
import { client } from '../lib/appwrite'

const email = ref('')
const password = ref('')
const error = ref('')

async function handleSubmit(event: Event) {
  event.preventDefault()
  error.value = ''
  try {
    const account = new Account(client)
    await account.createEmailPasswordSession({
      email: email.value,
      password: password.value,
    })
    window.location.href = '/'
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Sign in failed'
  }
}
</script>

<template>
  <form @submit="handleSubmit">
    <h1>Sign in</h1>
    <p v-if="error">{{ error }}</p>
    <input
      v-model="email"
      type="email"
      placeholder="Email"
      required
    />
    <input
      v-model="password"
      type="password"
      placeholder="Password"
      required
    />
    <button type="submit">Sign in</button>
    <p>
      No account? <a href="/sign-up">Sign up</a>
    </p>
  </form>
</template>
