---
layout: tutorial
title: Add navigation 
description: Add navigation to your Vue.js app with Appwrite authentication and pinia stores.
step: 5
---

In our app we want to have a navigation bar that is always visible. Use the `user` store to show either:
- a logout button if the user is logged in.
- a login button if the user is not logged in.

Update the App component in `src/App.vue`:

```vue
<script setup>
import { onMounted } from "vue";
import Home from "./pages/Home.vue";
import Login from "./pages/Login.vue";
import { user } from "./stores/user.js";

const isLoginPage = window.location.pathname === "/login";

onMounted(() => {
  user.init();
});
</script>

<template>
  <div>
    <nav>
      <a href="/">Idea tracker</a>
      <div>
        <template v-if="user.current">
          <span>{{ user.current.email }}</span>
          <button type="button" @click="user.logout()">Logout</button>
        </template>
        <a v-else href="/login">Login</a>
      </div>
    </nav>
    <main>
      <Login v-if="isLoginPage" />
      <Home v-else />
    </main>
  </div>
</template>
```
