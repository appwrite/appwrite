---
layout: tutorial
title: Add navigation
description: Add navigation to your app.
step: 5
---

To help our users navigate the app we want it to have a navigation bar that's visible on all pages.
We will once again use the `useUserSession()` composable for information about the current user.

With this piece of information we will show a login button when no user is logged in and a logout button when one is.
We will also put the user's e-mail address next to the logout button.

From the `components` directory, create the file `navbar.vue` and add the code below.

```vue
<!-- components/navbar.vue -->
<script setup>
const user = useUserSession();
</script>

<template>
  <div>
 <!--- Navbar -->
    <nav class="main-header u-padding-inline-end-0">
      <h3 class="u-stretch eyebrow-heading-1">Idea Tracker</h3>
      <!-- Email and logout button if logged in user -->
      <div
        class="main-header-end u-margin-inline-end-16"
        v-if="user.current.value"
        >
        <p>{{ user.current.providerUid }}</p>
        <button class="button" type="button" @click="user.logout()">
          Logout
        </button>
      </div>
      <!-- Login button if no user logged in -->
      <NuxtLink v-else href="/login" class="button u-margin-inline-end-16">
        Login
      </NuxtLink>
    </nav>
  </div>
</template>
```

Open `app.vue` from the root directory and add the navigation bar.

```vue
<!-- app.vue -->

<script setup>
import "@appwrite.io/pink";
import "@appwrite.io/pink-icons";
</script>

<template>
  <div>
    <NuxtLayout>
      <!-- Add navbar -->
      <Navbar />
      <NuxtPage />
    </NuxtLayout>
  </div>
</template>
```

Have a look in the browser at both the main page and the login page to test the new functionality.
