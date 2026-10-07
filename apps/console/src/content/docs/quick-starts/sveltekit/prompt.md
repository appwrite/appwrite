## Add Appwrite Auth to a New SvelteKit App

Goal: Add Appwrite auth to a new SvelteKit app with a working login/register/logout page.

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

Respect user's package manager at all times. Don't use NPM if the user uses something else.

## Step 1: Create or Use Existing SvelteKit App

- First, check the contents of the current working directory.
- If the directory contains files that appear unrelated to a development workspace (e.g., personal documents, downloads, photos, random files that don't belong in a code project), ask the user: "The current directory contains files that don't appear to be part of a development project. Would you like to: (1) proceed here anyway, or (2) create it in a subdirectory with a specific folder name?" and proceed based on their choice.
- If the directory is empty OR contains an existing project (`package.json`, `src` folder, config files, etc.), proceed directly with integration; do NOT ask the user, just integrate into the existing project.
- Run: `npx sv create .` (this creates the project in the current directory)
- Do NOT use `cd` to switch directories; always work in the current directory (`.`).
- Follow the prompts to create your project.

## Step 2: Install Appwrite SDK

- Run: `npm install appwrite`

## Step 3: Create Appwrite Client Module (Ask User for Details; Never Assume)

- Ask the user for:
    - **Appwrite Cloud Region** (e.g. `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
- Hardcode the endpoint and project ID in the file `src/lib/appwrite.js` (or `.ts`) if provided, else leave placeholder and ask the user to provide them.
- Create file `src/lib/appwrite.js` (or `.ts`) with key snippet:

```js
import { Client, Account } from 'appwrite';

export const client = new Client();

client.setEndpoint('https://<REGION>.cloud.appwrite.io/v1').setProject('<PROJECT_ID>'); // Replace with your project ID

export const account = new Account(client);
export { ID } from 'appwrite';
```

## Step 4: Build the Login Page

- If this is a fresh project, replace the contents of `src/routes/+page.svelte` with the login page component.
- If you are working in an existing project, add a new route/page instead of overriding the default route (e.g., `src/routes/auth/+page.svelte`).
- The component should render:
    - Email/password inputs
    - Buttons: **Login**, **Register**, **Logout**
    - Shows "Logged in as \<name>" when a session exists, otherwise "Not logged in"
- Implement functions:
    - `login(email, password)`: `account.createEmailPasswordSession({ email, password })` then set `loggedInUser` via `account.get()`
    - `register(email, password)`: `account.create({ userId: ID.unique(), email, password })` then call `login`
    - `logout()`: `account.deleteSession({ sessionId: 'current' })` then set `loggedInUser` to `null`
- Key snippet for `src/routes/+page.svelte`:

```html
<script>
    import { account, ID } from '$lib/appwrite';

    let loggedInUser = null;

    async function login(email, password) {
        await account.createEmailPasswordSession({
            email,
            password
        });
        loggedInUser = await account.get();
    }

    async function register(email, password) {
        await account.create({
            userId: ID.unique(),
            email,
            password
        });
        login(email, password);
    }

    function submit(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        const type = e.submitter.dataset.type;

        if (type === 'login') {
            login(formData.get('email'), formData.get('password'));
        } else if (type === 'register') {
            register(formData.get('email'), formData.get('password'));
        }
    }

    async function logout() {
        await account.deleteSession({ sessionId: 'current' });
        loggedInUser = null;
    }
</script>

<p>{loggedInUser ? `Logged in as ${loggedInUser.name}` : 'Not logged in'}</p>

<form on:submit="{submit}">
    <input type="email" placeholder="Email" name="email" required />
    <input type="password" placeholder="Password" name="password" required />

    <button type="submit" data-type="login">Login</button>
    <button type="submit" data-type="register">Register</button>
</form>

<button on:click="{logout}">Logout</button>
```

## Step 5: Verify Environment (Ask User to Confirm)

- Confirm **endpoint** and **Project ID** are set in `src/lib/appwrite.js`.
- Ensure the Web app platform exists in **Appwrite Console** with **Hostname** = `localhost`. If missing, guide the user to add it.

## Step 6: Run and Test

- Run: `npm run dev`
- Open: `http://localhost:5173`
- Test flows:
    - Register a new user and auto login works
    - Logout then login again
- Surface any Appwrite errors (invalid project, endpoint, CORS/hostname) and fix by guiding updates to `appwrite.js` and Console settings.

## Deliverables

- A running SvelteKit app with working Appwrite auth (register/login/logout)
- Files created/updated: `package.json` (deps), `src/lib/appwrite.js`, `src/routes/+page.svelte`
