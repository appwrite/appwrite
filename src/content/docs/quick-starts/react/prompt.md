## Add Appwrite Auth to a New React (Vite) App

Goal: Add Appwrite auth to a new React (Vite) app with a working login/register/logout page.

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

Respect user's package manager at all time. Don't use NPM if the user uses something else.

## Step 1: Check Current Directory and Set Up React App

- First, check if the current working directory contains files that appear unrelated to a development workspace (e.g., personal files, downloads, random documents, media files).
- If unrelated files are detected, ask the user: 'The current directory appears to contain personal or non-project files. Would you like to: (1) proceed here anyway, or (2) create the project in a subdirectory with a specific folder name?' and proceed based on their choice.
- If the directory is empty OR contains an existing project (`package.json`, source files, config files, etc.), proceed without asking - integrate Appwrite into whatever is there.
- For a new project, run: `npm create vite@latest . -- --template react`
- Create the project in the current directory (`.`). Do NOT use `cd` to switch directories.

## Step 2: Install Appwrite SDK

- Run: `npm install appwrite`

## Step 3: Create Appwrite Client Module (Ask User for Details; Never Assume)

- Ask the user for:
    - Appwrite Cloud Region (e.g. `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
- Hardcode the endpoint and project ID in the file: `src/lib/appwrite.js` (or `.ts`) if provided, else leave placeholder and ask the user to provide them.
- Create file: `src/lib/appwrite.js` (or `.ts`) with key snippet:

```js
import { Client, Account, ID } from 'appwrite';
const endpoint = 'https://<REGION>.cloud.appwrite.io/v1';
const projectId = '<PROJECT_ID>';
if (!endpoint || !projectId) throw new Error('Missing Appwrite endpoint and project ID');
const client = new Client().setEndpoint(endpoint).setProject(projectId);
export const account = new Account(client);
export { ID };
```

## Step 4: Build the Login Page

- If this is a fresh project, you may replace `src/App.jsx` (or `.tsx`) with a component that renders the auth UI.
- If you are working in an existing project, add a new route/page instead of overriding the default route. If routing is not set up, install `react-router-dom` and add a `/auth` route that renders this component.
- The component should render:
    - Email/password inputs
    - Name input for registration
    - Buttons: **Login**, **Register**, **Logout**
    - Shows "Logged in as \<name\>" when a session exists
- Implement functions:
    - `login(email, password)`: `account.createEmailPasswordSession({ email, password })` then set user via `account.get()`
    - `register()`: `account.create({ userId: ID.unique(), email, password, name })` then call `login`
    - `logout()`: `account.deleteSession({ sessionId: 'current' })` then clear user state

## Step 5: Verify Environment (Ask User to Confirm)

- Confirm endpoint and project ID are set in `src/lib/appwrite.js`.
- Ensure the Web app platform exists in Appwrite Console with **Hostname** = `localhost`. If missing, guide the user to add it.

## Step 6: Run and Test

- Run: `npm run dev -- --open --port 3000`
- Open: `http://localhost:3000`
- Test flows:
    - Register a new user and auto login works
    - Logout then login again
- Surface any Appwrite errors (invalid project, endpoint, CORS/hostname) and fix by guiding updates to `appwrite.js` and Console settings.

## Deliverables

- A running React app with working Appwrite auth (register/login/logout)
- Files created/updated: `package.json` (deps), `src/lib/appwrite.js`, `src/App.jsx`
