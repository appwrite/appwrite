## Add Appwrite auth to a new Vue.js app with a working login/register/logout page

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

Respect user's package manager at all times. Don't use NPM if the user uses something else.

## Step 1: Create project in Appwrite Console

- Guide user to the [Appwrite Console](https://cloud.appwrite.io/console).
- If this is their first time using Appwrite, help them create an account and create their first project.
- Under **Add a platform**, add a **Web app** with **Hostname** = `localhost`.

## Step 2: Create or integrate into Vue project

- First, check the current working directory contents.
- If the directory contains files that appear unrelated to a development workspace (e.g., personal files, downloads, random documents, media files), ask the user: "The current directory contains files that don't appear to be part of a development project. Would you like to: (1) proceed here anyway, or (2) create a subdirectory with a specific folder name?" and proceed based on their choice.
- If the directory is empty OR contains an existing project (e.g., `package.json`, `src` folder, config files), proceed directly without asking; integrate Appwrite into the existing project.
- For new projects, run: `npm init vue@latest .` (use `.` to create in current directory)
- Do NOT use `cd` to switch directories; always work in the current directory.

## Step 3: Install Appwrite SDK

- Run: `npm install appwrite`

## Step 4: Create Appwrite client module (ask user for details; never assume)

- Ask the user for:
    - **Appwrite Cloud Region** (e.g. `fra`, `nyc`)
    - **Project ID** (found in Console -> Settings page)
- Hardcode the endpoint and project ID in the file `src/lib/appwrite.js` if provided, else leave a placeholder and ask the user to provide them.
- Create file `src/lib/appwrite.js` with key snippet:

```js
import { Client, Account } from 'appwrite';

export const client = new Client();

client.setEndpoint('https://<REGION>.cloud.appwrite.io/v1').setProject('<PROJECT_ID>'); // Replace with your project ID

export const account = new Account(client);
export { ID } from 'appwrite';
```

## Step 5: Build the login page

- If this is a fresh project, replace `src/App.vue` with a component that renders the auth UI.
- If you are working in an existing project, add a new route/page instead of overriding the default route.
- The component should render:
    - Email/password inputs
    - Name input for registration
    - Buttons: **Login**, **Register**, **Logout**
    - Shows "Logged in as \<name\>" when a session exists, otherwise "Not logged in"
- Implement functions using Vue's **Composition API** with `<script setup>`:
    - `login(email, password)`: `account.createEmailPasswordSession({ email, password })` then set user via `account.get()`
    - `register()`: `account.create({ userId: ID.unique(), email: email.value, password: password.value, name: name.value })` then call `login`
    - `logout()`: `account.deleteSession({ sessionId: 'current' })` then clear user state
- Key snippet for `src/App.vue`:

```html
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

<script setup>
    import { ref } from 'vue';
    import { account, ID } from './lib/appwrite.js';

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
        login(email.value, password.value);
    };

    const logout = async () => {
        await account.deleteSession({
            sessionId: 'current'
        });
        loggedInUser.value = null;
    };
</script>
```

## Step 6: Run and test

- Run: `npm run dev -- --open --port 3000`
- Open: `http://localhost:3000`
- Test flows:
    - Register a new user and auto login works
    - Logout then login again
- Surface any Appwrite errors (invalid project, endpoint, CORS/hostname) and fix by guiding updates to `src/lib/appwrite.js` and Console settings.

## Deliverables

- A running Vue.js app with working Appwrite auth (register/login/logout)
- Files created/updated: `package.json` (deps), `src/lib/appwrite.js`, `src/App.vue`
