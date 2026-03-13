# Impersonation Demo

Small client-side React app that demonstrates the Appwrite impersonation flow.

## What it does

- signs in with `/account/sessions/email`
- relies on the browser session cookie returned by Appwrite
- calls `/account` with browser credentials included
- lists `/users` when the logged-in account is allowed to impersonate
- lets you switch impersonation targets with:
  - `x-appwrite-impersonate-user-id`
  - `x-appwrite-impersonate-user-email`
  - `x-appwrite-impersonate-user-phone`
- shows proof that it works by displaying the current account and `impersonatorUserId`

## Before you run it

1. Start Appwrite locally.
2. Make sure your project allows the demo origin, usually `http://localhost:5173`.
3. Mark at least one user in the project as `impersonator=true`.

## Environment

Copy `.env.example` to `.env` and fill in your values:

```bash
cp .env.example .env
```

Example:

```bash
VITE_APPWRITE_ENDPOINT=http://localhost/v1
VITE_APPWRITE_PROJECT=your-project-id
```

## Run

```bash
npm install
npm run dev
```

## Proof flow

1. Sign in as a user that has the `impersonator` attribute enabled.
2. The app should load the full users list.
3. Click one of the impersonation buttons on any user.
4. The active account panel should switch to the target user.
5. The active account panel should also show `impersonatorUserId` with the original user ID.
