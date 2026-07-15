## Add Appwrite Auth to a TanStack Start app

Add Appwrite auth to a new TanStack Start app using the official Appwrite React library, with a working sign-up, sign-in, and sign-out flow backed by SSR session cookies.

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

Respect the user's package manager at all times. Don't use NPM if the user uses something else.

## Step 1: Create or use existing TanStack Start app

- First, check what files exist in the current working directory.
- If the directory contains files that appear unrelated to a development workspace (e.g., personal documents, downloads, photos, random files that don't belong in a code project), ask the user: "The current directory contains files that don't appear to be part of a development project. Would you like to proceed here anyway, or create a subdirectory with a specific folder name?"
- If the directory is empty OR contains an existing project (`package.json`, config files, `src` folder, etc.), proceed with integration without asking - just work with what's there.
- To scaffold a new TanStack Start project, run: `npx @tanstack/cli create . --framework react` (use `.` to create in the current directory - do NOT use `cd` to switch directories)

## Step 2: Install the Appwrite React library

- Run: `npm install @appwrite.io/react appwrite node-appwrite @tanstack/react-query`

## Step 3: Configure environment variables

_Ask the user for details; never assume._

- Ask the user for:
    - **Appwrite Cloud Region** (e.g. `fra`, `nyc`)
    - **Project ID** (from Console → Settings)
    - **API key** with scopes `users.read`, `users.write`, `sessions.write` (Console → Overview → Integrations → API keys)
- Create a `.env` file at the project root:

```sh
VITE_APPWRITE_ENDPOINT=https://<REGION>.cloud.appwrite.io/v1
VITE_APPWRITE_PROJECT_ID=<PROJECT_ID>
APPWRITE_API_KEY=<API_KEY>
```

- The `APPWRITE_API_KEY` is server-only. Never expose it to the browser.

## Step 4: Mount the auth handler route

- Create `src/routes/api/appwrite/$.ts`:

```ts
import { createFileRoute } from '@tanstack/react-router';
import { createAppwriteHandlers } from '@appwrite.io/react/handlers/tanstack';

export const Route = createFileRoute('/api/appwrite/$')({
    server: {
        handlers: createAppwriteHandlers({
            endpoint: import.meta.env.VITE_APPWRITE_ENDPOINT,
            projectId: import.meta.env.VITE_APPWRITE_PROJECT_ID,
            apiKey: process.env.APPWRITE_API_KEY!,
            basePath: '/api/appwrite'
        })
    }
});
```

## Step 5: Build the auth route

- If this is a fresh project, you may replace `src/routes/index.tsx` with the auth UI route. If you are working in an existing project, create a new route (e.g., `src/routes/auth.tsx`) instead of overriding the default route.
- The route reads SSR auth state via a server function and passes the session into `AppwriteProvider`:

```tsx
import { useState } from 'react';
import { createFileRoute, useRouter } from '@tanstack/react-router';
import { createServerFn } from '@tanstack/react-start';
import { AppwriteProvider, useAuth } from '@appwrite.io/react';
import { createTanStackServerHelpers } from '@appwrite.io/react/server/tanstack';

const getAuthSnapshot = createServerFn({ method: 'GET' }).handler(async () => {
    const helpers = createTanStackServerHelpers({
        endpoint: import.meta.env.VITE_APPWRITE_ENDPOINT,
        projectId: import.meta.env.VITE_APPWRITE_PROJECT_ID
    });
    return {
        session: helpers.readSessionCookie() ?? null,
        user: await helpers.getLoggedInUser()
    };
});

export const Route = createFileRoute('/')({
    loader: () => getAuthSnapshot(),
    component: Page
});

function Page() {
    const { session, user } = Route.useLoaderData();

    return (
        <AppwriteProvider
            endpoint={import.meta.env.VITE_APPWRITE_ENDPOINT}
            projectId={import.meta.env.VITE_APPWRITE_PROJECT_ID}
            ssr={{ session, basePath: '/api/appwrite' }}
        >
            <main>
                <p>SSR user: {user?.email ?? 'signed out'}</p>
                <AuthPanel />
            </main>
        </AppwriteProvider>
    );
}

function AuthPanel() {
    const { user, isLoading, signIn, signUp, signOut, error } = useAuth();
    const router = useRouter();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [name, setName] = useState('');

    if (isLoading) return <p>Loading...</p>;

    if (user) {
        return (
            <button onClick={() => signOut.signOut({ onSuccess: () => router.invalidate() })}>
                Sign out
            </button>
        );
    }

    return (
        <div>
            <input placeholder="Name" value={name} onChange={(e) => setName(e.target.value)} />
            <input placeholder="Email" value={email} onChange={(e) => setEmail(e.target.value)} />
            <input
                placeholder="Password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
            />
            <button
                onClick={() =>
                    signUp.emailPassword({
                        email,
                        password,
                        name,
                        onSuccess: () => router.invalidate()
                    })
                }
            >
                Sign up
            </button>
            <button
                onClick={() =>
                    signIn.emailPassword({ email, password, onSuccess: () => router.invalidate() })
                }
            >
                Sign in
            </button>
            {error && <p>{error.message}</p>}
        </div>
    );
}
```

## Step 6: Verify environment (ask user to confirm)

- Confirm `.env` has the endpoint, project ID, and API key set.
- Ensure the Web app platform exists in Appwrite Console with **Hostname** = `localhost`. If missing, guide the user to add it.

## Step 7: Run and test

- Run: `npm run dev`
- Open: `http://localhost:3000`
- Test flows:
    - Sign up a new user, confirm the SSR-rendered user reflects the change after `router.invalidate()`
    - Sign out, then sign in again
- Surface any Appwrite errors (invalid project, endpoint, CORS/hostname, missing key scopes) and fix by guiding updates to `.env` and Console settings.

## Deliverables

- A TanStack Start app with working Appwrite auth using `@appwrite.io/react`
- Files created/updated: `package.json` (deps), `.env`, `src/routes/api/appwrite/$.ts`, auth route file
