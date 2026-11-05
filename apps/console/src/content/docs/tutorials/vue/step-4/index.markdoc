---
layout: tutorial
title: Add authentication
description: Add authentication to your Vue application using Appwrite Web SDK.
step: 4
---

## User store {% #user-store %}

In Vue, you can use the [reactive](https://vuejs.org/guide/essentials/reactivity-fundamentals.html#reactive) API to share data between components.
We'll create a store to share the user's data between components.

Create a new file `src/lib/stores/user.js` and add the following code to it.

```client-web
import { ID } from "appwrite";
import { account } from "../appwrite";
import { reactive } from "vue";

export const user = reactive({
  current: null,
  async init() {
    try {
      this.current = await account.get();
    } catch (e) {
      this.current = null;
    }
  },
  async register(email, password) {
    await account.create({
        userId: ID.unique(),
        email,
        password
    });
    await this.login(email, password);
  },
  async login(email, password) {
    await account.createEmailPasswordSession({
        email,
        password
    });
    window.location.href = "/"; // Redirect to home page
  },
  async logout() {
    await account.deleteSession({
        sessionId: "current"
    });
    this.current = null;
  },
});

```

Now, we can import the `user` store in any component and use it to login, logout, or register a user. However, we'll need to call the `init` method to initialize the user's data.

## Basic routing {% #basic-routing %}

First, import the `user` store in `src/App.vue` and call the `init` method when the component is mounted.

Update `src/App.vue` to the following code.

```vue
<script setup>
import { onMounted } from "vue";
import { user } from "./stores/user.js";

onMounted(() => {
  user.init();
});
</script>

<template>
  <div>
    <main>Home page</main>
  </div>
</template>
```

You can use the [Vue Router](https://router.vuejs.org/) to handle routing in your app. However, we'll keep it simple for now.
Optionally render a `Login` component if the path is `/login`, otherwise render the `Home` component.

Update `src/App.vue` to the following code.

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
    <main>
      <Login v-if="isLoginPage" />
      <Home v-else />
    </main>
  </div>
</template>
```



## Home page {% #home-page %}

Create a new file `src/pages/Home.vue` and add the following stub code to it.

```vue
<template>
  <!-- We'll complete this component later -->
  <h1>Home page</h1>
</template>
```

## Login page {% #login-page %}

Finally, we are able to create the login page. Users will be able to login or register from this page, so we'll need to add two buttons.

Create a new file `src/pages/Login.vue` and add the following code to it.

```vue
<script setup>
import { user } from "../stores/user";
import { ref } from "vue";

const email = ref("");
const password = ref("");
</script>

<template>
  <section>
    <h1>Login or register</h1>
    <form>
      <input type="email" placeholder="Email" v-model="email" />
      <input type="password" placeholder="Password" v-model="password" />
      <div>
        <button type="button" @click="user.login(email, password)">
          Login
        </button>
        <button type="button" @click="user.register(email, password)">
          Register
        </button>
      </div>
    </form>
  </section>
</template>
```

