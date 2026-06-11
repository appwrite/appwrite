## Add Appwrite Auth to a New Next.js App

Add Appwrite auth to a new Next.js app (**App Router**), with a working login/register/logout page.

- Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.
- Respect the user's package manager at all times. Do not use NPM if the user uses something else.

## Step 1: Create or Use Existing Next.js App

- First, check if the current working directory contains files that appear unrelated to a development workspace (e.g., personal files, downloads, random documents, media files). If so, ask the user: "This directory contains files that don't look like a development project. Would you like to proceed here anyway, or create a subdirectory with a specific folder name?"
- If the directory is empty OR contains an existing project (`package.json`, source code, config files, etc.), proceed with integration without asking.
- Create the project in the current working directory (`.`) - do NOT use `cd` to switch directories.
- If you already have a Next.js project open, stay in it and integrate Appwrite into it (**App Router** required).
- Otherwise, run: `npx create-next-app@latest . --eslint`
- When prompted: **TypeScript** = No, **ESLint** = Yes, **Tailwind** = No, **src dir** = your choice, **App Router** = Yes, **Import alias** = No.

## Step 2: Install Appwrite SDK

- Run: `npm install appwrite`

## Step 3: Create Appwrite Client Module

_Ask the user for details; never assume._

- Ask the user for:
    - **Appwrite Cloud Region** (e.g. `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
- If the user doesn't know, guide them to the **Appwrite Console** to copy these. Do not attempt to infer or access their project.
- Hardcode the endpoint and project ID in the file `app/appwrite.js` (or `app/appwrite.ts` if TS) if provided, else leave a placeholder and ask the user to provide them.
- Create file `app/appwrite.js` (or `app/appwrite.ts` if TS) with key snippet:

```js
import { Client, Account } from 'appwrite';
const endpoint = '';
const projectId = '';
if (!endpoint || !projectId) throw new Error('Missing Appwrite endpoint and project ID');
export const client = new Client().setEndpoint(endpoint).setProject(projectId);
export const account = new Account(client);
export { ID } from 'appwrite';
```

## Step 4: Build the Login Page (Client Component)

- If this is a fresh project you just created above, you may replace `app/page.js` with this component using `"use client"`.
- If you are working in an existing project, create a new route `app/auth/page.js` (or `.tsx`) instead of overriding the default route.
- It must render:
    - Email/password inputs
    - Name input for registration
    - Buttons: **Login**, **Register**, **Logout**
    - Shows "Logged in as \<name\>" when a session exists
- Implement functions:
    - `login(email, password)`: `account.createEmailPasswordSession({ email, password })` then set user via `account.get()`
    - `register()`: `account.create({ userId: ID.unique(), email, password, name })` then call `login`
    - `logout()`: `account.deleteSession({ sessionId: 'current' })` then clear user state

## Step 5: Verify Environment

_Ask the user to confirm._

- Confirm with the user that the endpoint and project ID are hardcoded in the file `app/appwrite.js` (or `app/appwrite.ts` if TS).
- Ensure the **Web** app platform exists in **Appwrite Console** with **Hostname** = `localhost`. If missing, guide the user to add it.

## Step 6: Run and Test

- Run: `npm run dev`
- Open: `http://localhost:3000`
- Test flows:
    - Register a new user and auto login works
    - Logout then login again
- Surface any Appwrite errors (invalid project, endpoint, CORS/hostname) and fix by guiding updates to `appwrite.js` and **Console** settings.

## Step 7: Optional Hardening

- If the user wants TypeScript, create `app/appwrite.ts` and `app/page.tsx` with proper types.
- Add minimal styling if requested; functionality first.

## Deliverables

- A running Next.js app with working Appwrite auth (register/login/logout)
- Files created/updated: `package.json` (deps), `app/appwrite.js`, `app/page.js`
