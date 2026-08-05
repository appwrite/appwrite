<script setup lang="ts">
import { Account, ID } from 'appwrite'
import { client } from '~/utils/appwrite'

const name = ref('')
const email = ref('')
const password = ref('')
const error = ref('')

async function handleSubmit(event: Event) {
  event.preventDefault()
  error.value = ''
  try {
    const account = new Account(client)
    await account.create({
      userId: ID.unique(),
      email: email.value,
      password: password.value,
      name: name.value.trim() || undefined,
    })
    await account.createEmailPasswordSession({
      email: email.value,
      password: password.value,
    })
    await navigateTo('/')
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Sign up failed'
  }
}
</script>

<template>
  <form @submit="handleSubmit">
    <h1>Sign up</h1>
    <p v-if="error">{{ error }}</p>
    <input
      v-model="name"
      type="text"
      placeholder="Name"
    />
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
    <button type="submit">Sign up</button>
    <p>
      Already have an account? <NuxtLink to="/sign-in">Sign in</NuxtLink>
    </p>
  </form>
</template>
