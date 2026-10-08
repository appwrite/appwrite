## Add Appwrite Auth to a New Solid (Vite) App

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

Respect user's package manager at all times. Don't use NPM if the user uses something else.

## Step 1: Create Project in Appwrite Console

- Guide the user to head to the [Appwrite Console](https://cloud.appwrite.io/console).
- If this is their first time using Appwrite, create an account and create their first project.
- Under **Add a platform**, add a **Web app** with **Hostname** = `localhost`.

## Step 2: Create Solid Project

- First, check if the current directory contains files that appear unrelated to a development workspace (e.g., personal files, downloads, random documents, media files). If such files are detected, ask the user: 'The current directory contains files that don't appear to be part of a development project. Would you like to: (1) proceed here anyway, or (2) create a subdirectory with a specific folder name?' and proceed based on their choice.
- If the directory is empty OR contains an existing project (e.g., `package.json`, `src` folder, config files), proceed directly with integration - do NOT ask the user about existing projects.
- Create the project in the current working directory (`.`) - do NOT use `cd` to switch directories.
- Run: `npm create vite@latest . -- --template solid`

## Step 3: Install Appwrite SDK

- Run: `npm install appwrite`

## Step 4: Create Appwrite Client Module

Ask the user for (never assume):

- **Appwrite Cloud Region** (e.g. `fra`, `nyc`)
- **Project ID** (from Console -> Settings)

Hardcode the endpoint and project ID in the file `src/lib/appwrite.js` if provided, else leave a placeholder and ask the user to provide them.

Create file `src/lib/appwrite.js` with key snippet:

```js
import { Client, Account } from 'appwrite';

export const client = new Client();

client.setEndpoint('https://<REGION>.cloud.appwrite.io/v1').setProject('<PROJECT_ID>'); // Replace with your project ID

export const account = new Account(client);
export { ID } from 'appwrite';
```

## Step 5: Build the Login Page

- If this is a fresh project, replace `src/App.jsx` with the auth component.
- If you are working in an existing project, add a new route/page instead of overriding the default route.
- The component should use Solid's `createSignal` for state management and render:
    - Email/password inputs
    - Name input for registration
    - Buttons: **Login**, **Register**, **Logout**
    - Shows "Logged in as \<name\>" when a session exists
- Implement functions:
    - `login(email, password)`: `account.createEmailPasswordSession({ email, password })` then set user via `account.get()`
    - `register(email, password, name)`: `account.create({ userId: ID.unique(), email, password, name })` then call `login`
    - `logout()`: `account.deleteSession({ sessionId: 'current' })` then clear user state

Key snippet for `src/App.jsx`:

```jsx
import { createSignal } from 'solid-js';
import { account, ID } from './lib/appwrite';

const App = () => {
    const [loggedInUser, setLoggedInUser] = createSignal(null);
    const [email, setEmail] = createSignal('');
    const [password, setPassword] = createSignal('');
    const [name, setName] = createSignal('');

    async function login(email, password) {
        await account.createEmailPasswordSession({
            email,
            password
        });
        setLoggedInUser(await account.get());
    }

    async function register(email, password, name) {
        await account.create({
            userId: ID.unique(),
            email,
            password,
            name
        });
        await login(email, password);
    }

    async function logout() {
        await account.deleteSession({ sessionId: 'current' });
        setLoggedInUser(null);
    }

    if (loggedInUser()) {
        return (
            <div>
                <p>Logged in as {loggedInUser().name}</p>
                <button onClick={logout}>Logout</button>
            </div>
        );
    }

    return (
        <div>
            <p>Not logged in</p>
            <form>
                <input
                    type="email"
                    placeholder="Email"
                    value={email()}
                    onChange={(e) => setEmail(e.target.value)}
                />
                <input
                    type="password"
                    placeholder="Password"
                    value={password()}
                    onChange={(e) => setPassword(e.target.value)}
                />
                <input
                    type="text"
                    placeholder="Name"
                    value={name()}
                    onChange={(e) => setName(e.target.value)}
                />
                <button type="button" onClick={() => login(email(), password())}>
                    Login
                </button>
                <button type="button" onClick={() => register(email(), password(), name())}>
                    Register
                </button>
            </form>
        </div>
    );
};

export default App;
```

## Step 6: Run and Test

- Run: `npm run dev -- --open --port 3000`
- Open: `http://localhost:3000`
- Test flows:
    - Register a new user and auto login works
    - Logout then login again
- Surface any Appwrite errors (invalid project, endpoint, CORS/hostname) and fix by guiding updates to `src/lib/appwrite.js` and Console settings.

## Deliverables

- A running Solid app with working Appwrite auth (register/login/logout)
- Files created/updated: `package.json` (deps), `src/lib/appwrite.js`, `src/App.jsx`
