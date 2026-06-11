## Add Appwrite Auth to a New Nuxt App

Goal: Add Appwrite auth to a new Nuxt app with a working login/register/logout page.

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

Respect the user's package manager at all times. Do not use NPM if the user uses something else.

## Step 1: Create or Use Existing Nuxt App

- First, check if the current working directory contains files that appear unrelated to a development workspace (e.g., personal files, downloads, random documents, media files). If such files are detected, ask the user: "This directory contains files that don't appear to be part of a development project. Would you like to: (1) proceed here anyway, or (2) create a subdirectory with a specific folder name?"
- If the directory is empty OR contains an existing project (e.g., `package.json`, `node_modules`, `src` folder, config files), proceed with integration without asking - just use the existing project.
- For new projects, run: `npx nuxi@latest init .`
- Always create the project in the current directory (`.`) - do NOT use `cd` to switch directories.

## Step 2: Install Appwrite SDK

- Run: `npm install appwrite`

## Step 3: Create Appwrite Client Module (Ask User for Details; Never Assume)

- Ask the user for:
    - **Appwrite Cloud Region** (e.g. `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
- Hardcode the endpoint and project ID in the file `utils/appwrite.js` if provided, else leave a placeholder and ask the user to provide them.
- Create file `utils/appwrite.js` with key snippet:

```js
import { Client, Account } from 'appwrite';

export const client = new Client();

client.setEndpoint('https://<REGION>.cloud.appwrite.io/v1').setProject('<PROJECT_ID>'); // Replace with your project ID

export const account = new Account(client);
export { ID } from 'appwrite';
```

## Step 4: Build the Login Page

- If this is a fresh project, you may replace `app.vue` with a component that renders the auth UI.
- If you are working in an existing project, add a new page instead of overriding `app.vue`.
- The component should render:
    - Email/password inputs
    - Name input for registration
    - Buttons: **Login**, **Register**, **Logout**
    - Shows "Logged in as \<name\>" when a session exists, otherwise "Not logged in"
- Implement functions:
    - `login(email, password)`: `account.createEmailPasswordSession({ email, password })` then set user via `account.get()`
    - `register()`: `account.create({ userId: ID.unique(), email, password, name })` then call `login`
    - `logout()`: `account.deleteSession({ sessionId: 'current' })` then clear user state
- Use Vue 3 Composition API with `<script setup>` and `ref` for reactive state
- Key snippet for `app.vue`:

```html
<script setup>
    import { ref } from 'vue';
    import { account, ID } from './utils/appwrite.js';

    const loggedInUser = ref(null);
    const email = ref('');
    const password = ref('');
    const name = ref('');

    const login = async (email, password) => {
        await account.createEmailPasswordSession({
            email,
            password
        });
        loggedInUser.value = await account.get();
    };

    const register = async () => {
        await account.create({
            userId: ID.unique(),
            email: email.value,
            password: password.value,
            name: name.value
        });
        await login(email.value, password.value);
    };

    const logout = async () => {
        await account.deleteSession({ sessionId: 'current' });
        loggedInUser.value = null;
    };
</script>

<template>
    <div>
        <p>{{ loggedInUser ? `Logged in as ${loggedInUser.name}` : 'Not logged in' }}</p>

        <form>
            <input type="email" placeholder="Email" v-model="email" />
            <input type="password" placeholder="Password" v-model="password" />
            <input type="text" placeholder="Name" v-model="name" />
            <button type="button" @click="login(email, password)">Login</button>
            <button type="button" @click="register">Register</button>
            <button type="button" @click="logout">Logout</button>
        </form>
    </div>
</template>
```

## Step 5: Verify Environment (Ask User to Confirm)

- Confirm endpoint and project ID are set in `utils/appwrite.js`.
- Ensure the Web app platform exists in Appwrite Console with **Hostname** = `localhost`. If missing, guide the user to add it.

## Step 6: Run and Test

- Run: `npm run dev -- --open --port 3000`
- Open: `http://localhost:3000`
- Test flows:
    - Register a new user and auto login works
    - Logout then login again
- Surface any Appwrite errors (invalid project, endpoint, CORS/hostname) and fix by guiding updates to `utils/appwrite.js` and Console settings.

## Deliverables

- A running Nuxt app with working Appwrite auth (register/login/logout)
- Files created/updated: `package.json` (deps), `utils/appwrite.js`, `app.vue`
