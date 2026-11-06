## Add Appwrite Auth to a New Next.js App

Add Appwrite auth to a new Next.js app (**App Router**) using the official Appwrite React library, with a working sign-up, sign-in, and sign-out flow backed by SSR session cookies.

- Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.
- Respect the user's package manager at all times. Do not use NPM if the user uses something else.

## Step 1: Create or use existing Next.js app

- First, check if the current working directory contains files that appear unrelated to a development workspace (e.g., personal files, downloads, random documents, media files). If so, ask the user: "This directory contains files that don't look like a development project. Would you like to proceed here anyway, or create a subdirectory with a specific folder name?"
- If the directory is empty OR contains an existing project (`package.json`, source code, config files, etc.), proceed with integration without asking.
- Create the project in the current working directory (`.`) - do NOT use `cd` to switch directories.
- If you already have a Next.js project open, stay in it and integrate Appwrite into it (**App Router** required).
- Otherwise, run: `npx create-next-app@latest . --ts --app`
- Accept the defaults for the remaining prompts.

## Step 2: Install the Appwrite React library

- Run: `npm install @appwrite.io/react appwrite node-appwrite @tanstack/react-query`

## Step 3: Configure environment variables

_Ask the user for details; never assume._

- Ask the user for:
    - **Appwrite Cloud Region** (e.g. `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
    - **API key** with scopes `users.read`, `users.write`, `sessions.write` (Console -> Overview -> Integrations -> API keys)
- Create a `.env.local` file at the project root:

```sh
NEXT_PUBLIC_APPWRITE_ENDPOINT=https://<REGION>.cloud.appwrite.io/v1
NEXT_PUBLIC_APPWRITE_PROJECT_ID=<PROJECT_ID>
APPWRITE_API_KEY=<API_KEY>
```

- The `APPWRITE_API_KEY` is server-only. Never expose it to the browser.

## Step 4: Mount the auth handler route

- Create `app/api/appwrite/[...appwrite]/route.ts` so the library's sign-in, sign-up, sign-out, and OAuth callback endpoints are reachable:

```ts
import { createAppwriteHandlers } from '@appwrite.io/react/handlers/next';

export const { GET, POST } = createAppwriteHandlers({
    endpoint: process.env.NEXT_PUBLIC_APPWRITE_ENDPOINT!,
    projectId: process.env.NEXT_PUBLIC_APPWRITE_PROJECT_ID!,
    apiKey: process.env.APPWRITE_API_KEY!,
    basePath: '/api/appwrite'
});
```

## Step 5: Wrap the app with AppwriteProvider

- Create `app/providers.tsx`:

```tsx
'use client';

import { AppwriteProvider } from '@appwrite.io/react';

export function Providers({
    session,
    children
}: {
    session?: string | null;
    children: React.ReactNode;
}) {
    return (
        <AppwriteProvider
            endpoint={process.env.NEXT_PUBLIC_APPWRITE_ENDPOINT!}
            projectId={process.env.NEXT_PUBLIC_APPWRITE_PROJECT_ID!}
            ssr={{ session, basePath: '/api/appwrite' }}
        >
            {children}
        </AppwriteProvider>
    );
}
```

- Replace `app/layout.tsx` to read the session cookie server-side and pass it into the provider:

```tsx
import { createNextServerHelpers } from '@appwrite.io/react/server/next';
import { Providers } from './providers';

const appwrite = {
    endpoint: process.env.NEXT_PUBLIC_APPWRITE_ENDPOINT!,
    projectId: process.env.NEXT_PUBLIC_APPWRITE_PROJECT_ID!
};

export default async function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
    const helpers = createNextServerHelpers(appwrite);
    const session = await helpers.readSessionCookie();

    return (
        <html lang="en">
            <body>
                <Providers session={session}>{children}</Providers>
            </body>
        </html>
    );
}
```

## Step 6: Build the auth page

- If this is a fresh project you just created, replace `app/page.tsx` to read the user server-side and render the auth panel. If you are working in an existing project, create a new route (e.g. `app/auth/page.tsx`) instead of overriding the default route.

```tsx
import { createNextServerHelpers } from '@appwrite.io/react/server/next';
import { AuthPanel } from './auth-panel';

const appwrite = {
    endpoint: process.env.NEXT_PUBLIC_APPWRITE_ENDPOINT!,
    projectId: process.env.NEXT_PUBLIC_APPWRITE_PROJECT_ID!
};

export default async function Page() {
    const helpers = createNextServerHelpers(appwrite);
    const user = await helpers.getLoggedInUser();

    return (
        <main>
            <p>SSR user: {user?.email ?? 'signed out'}</p>
            <AuthPanel />
        </main>
    );
}
```

- Create `app/auth-panel.tsx` for the client-side hook usage:

```tsx
'use client';

import { useState } from 'react';
import { useAuth } from '@appwrite.io/react';
import { useRouter } from 'next/navigation';

export function AuthPanel() {
    const { user, isLoading, signIn, signUp, signOut, error } = useAuth();
    const router = useRouter();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [name, setName] = useState('');

    if (isLoading) return <p>Loading...</p>;

    if (user) {
        return (
            <button onClick={() => signOut.signOut({ onSuccess: () => router.refresh() })}>
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
                        onSuccess: () => router.refresh()
                    })
                }
            >
                Sign up
            </button>
            <button
                onClick={() =>
                    signIn.emailPassword({ email, password, onSuccess: () => router.refresh() })
                }
            >
                Sign in
            </button>
            {error && <p>{error.message}</p>}
        </div>
    );
}
```

## Step 7: Verify environment

_Ask the user to confirm._

- Confirm `.env.local` has the endpoint, project ID, and API key set.
- Ensure the **Web** app platform exists in **Appwrite Console** with **Hostname** = `localhost`. If missing, guide the user to add it.

## Step 8: Run and test

- Run: `npm run dev`
- Open: `http://localhost:3000`
- Test flows:
    - Sign up a new user, confirm the SSR-rendered user reflects the change after `router.refresh()`
    - Sign out, then sign in again
- Surface any Appwrite errors (invalid project, endpoint, CORS/hostname, missing key scopes) and fix by guiding updates to `.env.local` and Console settings.

## Deliverables

- A running Next.js app with working Appwrite auth using `@appwrite.io/react`
- Files created/updated: `package.json` (deps), `.env.local`, `app/api/appwrite/[...appwrite]/route.ts`, `app/providers.tsx`, `app/layout.tsx`, `app/page.tsx`, `app/auth-panel.tsx`
