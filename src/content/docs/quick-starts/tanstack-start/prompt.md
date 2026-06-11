## Add Appwrite auth to a TanStack Start app with a working login/register/logout page

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

Respect the user's package manager at all times. Don't use NPM if the user uses something else.

## Step 1: Create or use existing TanStack Start app

- First, check what files exist in the current working directory.
- If the directory contains files that appear unrelated to a development workspace (e.g., personal documents, downloads, photos, random files that don't belong in a code project), ask the user: "The current directory contains files that don't appear to be part of a development project. Would you like to proceed here anyway, or create a subdirectory with a specific folder name?"
- If the directory is empty OR contains an existing project (`package.json`, config files, `src` folder, etc.), proceed with integration without asking - just work with what's there.
- To scaffold a new TanStack Start project, run: `npm create @tanstack/start@latest .` (use `.` to create in the current directory - do NOT use `cd` to switch directories)

## Step 2: Install Appwrite SDK

- Run: `npm install appwrite`

## Step 3: Create Appwrite client module (ask user for details; never assume)

- Ask the user for:
    - **Appwrite Cloud Region** (e.g. `fra`, `nyc`)
    - **Project ID** (from Console → Settings)
- Hardcode the endpoint and project ID in the file `src/utils/appwrite.ts` (or `.js`) if provided, else leave placeholder and ask the user to provide them.
- Create file `src/utils/appwrite.ts` (or `.js`) with key snippet:

```ts
import { Client, Account, ID, type Models } from 'appwrite';
const endpoint = 'https://<REGION>.cloud.appwrite.io/v1';
const projectId = '<PROJECT_ID>';
if (!endpoint || !projectId) throw new Error('Missing Appwrite endpoint and project ID');
const client = new Client().setEndpoint(endpoint).setProject(projectId);
export const account = new Account(client);
export { ID };
export type { Models };
```

## Step 4: Build the login route

- If this is a fresh project, you may replace `src/routes/index.tsx` with an auth UI route.
- If you are working in an existing project, create a new route (e.g., `src/routes/auth.tsx`) instead of overriding the default route.
- The route should render:
    - Email/password inputs
    - Name input for registration
    - Buttons: **Login**, **Register**, **Logout**
    - Shows "Logged in as \<name\>" when a session exists
- Route scaffold example:

```tsx
import { useState } from 'react';
import { createFileRoute } from '@tanstack/react-router';
import { account, ID, type Models } from '../utils/appwrite';

export const Route = createFileRoute('/')({
    component: Index
});

function Index() {
    const [user, setUser] = useState<Models.User<Models.Preferences> | null>(null);
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [name, setName] = useState('');

    async function login(e: string, p: string) {
        await account.createEmailPasswordSession({ email: e, password: p });
        setUser(await account.get());
    }

    async function register() {
        await account.create({ userId: ID.unique(), email, password, name });
        await login(email, password);
    }

    async function logout() {
        await account.deleteSession({ sessionId: 'current' });
        setUser(null);
    }

    return (
        <div>
            <p>{user ? `Logged in as ${user.name}` : 'Not logged in'}</p>
            <form>
                <input
                    type="email"
                    placeholder="Email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                />
                <input
                    type="password"
                    placeholder="Password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                />
                <input
                    type="text"
                    placeholder="Name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                />
                <button type="button" onClick={() => login(email, password)}>
                    Login
                </button>
                <button type="button" onClick={register}>
                    Register
                </button>
                {user && (
                    <button type="button" onClick={logout}>
                        Logout
                    </button>
                )}
            </form>
        </div>
    );
}
```

## Step 5: Verify environment (ask user to confirm)

- Confirm endpoint and project ID are set in `src/utils/appwrite.ts`.
- Ensure the Web app platform exists in Appwrite Console with **Hostname** = `localhost`. If missing, guide the user to add it.

## Step 6: Run and test

- Run: `npm run dev`
- Open: `http://localhost:3000`
- Test flows:
    - Register a new user and auto login works
    - Logout then login again
- Surface any Appwrite errors (invalid project, endpoint, CORS/hostname) and fix by guiding updates to `appwrite.ts` and Console settings.

## Deliverables

- A TanStack Start app with working Appwrite auth (register/login/logout)
- Files created/updated: `package.json` (deps), `src/utils/appwrite.ts`, auth route file
