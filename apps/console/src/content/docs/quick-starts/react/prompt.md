## Add Appwrite Auth to a New React (Vite) App

Goal: Add Appwrite auth to a new React (Vite) app using the official Appwrite React library, with a working sign-up, sign-in, and sign-out flow.

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

Respect user's package manager at all time. Don't use NPM if the user uses something else.

## Step 1: Check Current Directory and Set Up React App

- First, check if the current working directory contains files that appear unrelated to a development workspace (e.g., personal files, downloads, random documents, media files).
- If unrelated files are detected, ask the user: 'The current directory appears to contain personal or non-project files. Would you like to: (1) proceed here anyway, or (2) create the project in a subdirectory with a specific folder name?' and proceed based on their choice.
- If the directory is empty OR contains an existing project (`package.json`, source files, config files, etc.), proceed without asking - integrate Appwrite into whatever is there.
- For a new project, run: `npm create vite@latest . -- --template react-ts`
- Create the project in the current directory (`.`). Do NOT use `cd` to switch directories.

## Step 2: Install the Appwrite React library

- Run: `npm install @appwrite.io/react appwrite @tanstack/react-query`

## Step 3: Configure environment variables (Ask User for Details; Never Assume)

- Ask the user for:
    - Appwrite Cloud Region (e.g. `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
- Create a `.env` file at the project root with the values provided. If either is missing, leave a placeholder and ask the user to fill it in:

```sh
VITE_APPWRITE_ENDPOINT=https://<REGION>.cloud.appwrite.io/v1
VITE_APPWRITE_PROJECT_ID=<PROJECT_ID>
```

## Step 4: Mount AppwriteProvider

- Replace `src/main.tsx` (or `.jsx`) so the entire app is wrapped with `AppwriteProvider`:

```tsx
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { AppwriteProvider } from '@appwrite.io/react';
import App from './App';
import './index.css';

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <AppwriteProvider
            endpoint={import.meta.env.VITE_APPWRITE_ENDPOINT}
            projectId={import.meta.env.VITE_APPWRITE_PROJECT_ID}
        >
            <App />
        </AppwriteProvider>
    </StrictMode>
);
```

## Step 5: Build the auth page

- If this is a fresh project, you may replace `src/App.tsx` (or `.jsx`) with a component that renders the auth UI.
- If you are working in an existing project, add a new route/page instead of overriding the default route. If routing is not set up, install `react-router-dom` and add an `/auth` route that renders this component.
- The component must render:
    - Email, password, and name inputs
    - Buttons: **Sign up**, **Sign in**, **Sign out**
    - Shows "Welcome, \<name\>" when a session exists
- Use the `useAuth` hook from `@appwrite.io/react`:

```tsx
import { useState } from 'react';
import { useAuth } from '@appwrite.io/react';

export default function App() {
    const { user, isLoading, signIn, signUp, signOut, error } = useAuth();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [name, setName] = useState('');

    if (isLoading) return <p>Loading...</p>;

    if (user) {
        return (
            <main>
                <p>Welcome, {user.name || user.email}</p>
                <button onClick={() => signOut.signOut()}>Sign out</button>
            </main>
        );
    }

    return (
        <main>
            <input placeholder="Name" value={name} onChange={(e) => setName(e.target.value)} />
            <input placeholder="Email" value={email} onChange={(e) => setEmail(e.target.value)} />
            <input
                placeholder="Password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
            />
            <button onClick={() => signUp.emailPassword({ email, password, name })}>Sign up</button>
            <button onClick={() => signIn.emailPassword({ email, password })}>Sign in</button>
            {error && <p>{error.message}</p>}
        </main>
    );
}
```

## Step 6: Verify environment (Ask User to Confirm)

- Confirm the `.env` file contains the correct endpoint and project ID.
- Ensure the Web app platform exists in Appwrite Console with **Hostname** = `localhost`. If missing, guide the user to add it.

## Step 7: Run and test

- Run: `npm run dev`
- Open: `http://localhost:5173`
- Test flows:
    - Sign up a new user and confirm the page shows the welcome state
    - Sign out, then sign in again
- Surface any Appwrite errors (invalid project, endpoint, CORS/hostname) and fix by guiding updates to `.env` and Console settings.

## Deliverables

- A running React app with working Appwrite auth using `@appwrite.io/react`
- Files created/updated: `package.json` (deps), `.env`, `src/main.tsx`, `src/App.tsx`
