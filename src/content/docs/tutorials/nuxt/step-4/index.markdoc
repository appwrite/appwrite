---
layout: tutorial
title: Add authentication
description: Add authentication to your Nuxt application using Appwrite Web SDK
step: 4
---

For our ideas tracker app, we want any visitor to be able to read the ideas that are stored.
On the other hand, we don't want the page spammed with just about anything from anyone just stopping by.
To prevent that, or at least making it a bit more difficult, editing ideas will be available for logged in users only.
With a login function, we can differentiate between users and decide which users have access to which content.

We will build a page with a simple login form and store its related logic in a `useUserSession` composable so it can be reused, starting with the composable.

## User session composable {% #user-session-composable %}

There are a few standard functions involved in handling a user session that are added to the composable.
The user needs to be able to register to an account, login to the account and logout from it.

We are using Appwrite as a backend to handle the user details, so we need to connect to Appwrite by importing the configurations from step 3.
The response from these interactions will be stored as references to get more information about the user in our app.

In your `composable` directory, create the file `useUserSession.js` and add the following code.

```ts
// composable/useUserSession.ts

import { ID } from "appwrite";
import { ref } from "vue";
import { account } from "../appwrite";
import { type Models } from 'appwrite';

const current = ref<Models.Session | null>(null); // Reference to current user object

export const useUserSession = () =>  {
    const register = async (email: string, password: string): Promise<void> => {
        await account.create({
            userId: ID.unique(),
            email,
            password
        }); // Register new user in Appwrite
        await login(email, password); // Login registered user
    };

    const login = async (email: string, password: string): Promise<void> => {
        const authUser = await account.createEmailPasswordSession({
            email,
            password
        }); // Open user session in Appwrite
        current.value = authUser; // Pass user data to current ref
        navigateTo("/");
    };

    const logout = async (): Promise<void> => {
        await account.deleteSession({
            sessionId: "current"
        }); // Delete Appwrite user session
        current.value = null; // Clear current ref
        navigateTo("/");
    };

    // Check if already logged in to initialize the store.
    account.getSession({
        sessionId: 'current'
    }).then((user: Models.Session) => {
        current.value = user;
    });

    return {
        current,
        login,
        logout,
        register,
    };
};
```

Then you can call the  `useUserSession()` function in the pages and components to use the functionality.

## Login page {% #login-page %}

Create a new file in the `pages` directory called `login.vue`.
This will not only create a new page, it will also add the route `/login` to the url.
In step 5 we will add a login button that will redirect us to this page.

We will define functions to handle form submissions and show either a signup
or a login form.

```vue
<!-- pages/login.vue -->
<script setup>
import { ref } from 'vue';

// Access user composable functions
const user = useUserSession();

const isSignUp = ref(false);

// Login user event handler
const handleLogin = async (event) => {
  const form = event.target;
  const formData = new FormData(form);

  await user.login(formData.get('email'), formData.get('password'));

  form.reset(); // Clear the form
};

const handleRegistration = async (event) => {
  const form = event.target;
  const formData = new FormData(form);

  await user.register(formData.get('email'), formData.get('password'));

  form.reset(); // Clear the form
};
</script>

<template>
  <div class="u-max-width-650" style="margin: 0 auto;">
    <section class="card u-margin-32">
      <h2 class="eyebrow-heading-2">Login/Register</h2>
      <AuthForm v-if="isSignUp" :handle-submit="handleRegistration" submit-type="Sign Up"></AuthForm>
      <AuthForm v-else :handle-submit="handleLogin" submit-type="Log In"></AuthForm>
      <button v-if="isSignUp" @click="isSignUp = false" class="u-margin-block-start-16">
        Already have an account? Log in
      </button>
      <button v-else @click="isSignUp = true" class="u-margin-block-start-16">
        Don't have an account? Sign up
      </button>
    </section>
  </div>
</template>
```

This page renders a login or sign up form depending on `isSignUp`'s state.
We will also show buttons to toggle between the two different types of forms.

## Authentication forms {% #auth-forms %}

In the previous step, we defined a `AuthForm` to handle signup and login.
Let's build this form now.

Create a new file `components/authForm.vue` and add the following code.
The handle submit and submit type are props passed from `pages/login.vue`

```html
<!-- components/authForm.vue -->

<template>
<form
  class="form u-width-full-line u-max-width-500 u-margin-block-start-16"
  @submit.prevent="handleSubmit"
>
  <ul class="form-list">
    <!-- Input field e-mail -->
    <li class="form-item">
      <label class="label">Email</label>
      <div class="input-text-wrapper">
        <input
          type="email"
          name="email"
          class="input-text"
          placeholder="Email"
          required
        />
      </div>
    </li>
    <!-- Input field e-mail -->
    <li class="form-item">
      <label class="label">Password</label>
      <div class="input-text-wrapper">
        <input
          type="password"
          name="password"
          class="input-text"
          placeholder="Password"
          required
        />
      </div>
    </li>
  </ul>
  <ul class="buttons-list u-margin-block-start-16">
    <!-- Login button  -->
    <li class="buttons-list-item">
      <button
        class="button is-small u-margin-inline-start-4"
        aria-label="Login"
        @click="handleSubmit"
      >
        {{submitType}}
      </button>
    </li>
  </ul>
</form>
</template>

<script setup>
import { defineProps } from 'vue'

const props = defineProps({
  handleSubmit: {
    type: Function,
    required: true
  },
  submitType: {
    type: String,
    required: true
  }
})
</script>
```

Go to the browser and add `/login` to the url to check out the new page.
