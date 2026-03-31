# Logic to Keep a Project Active

How the Console keeps a project **active** (and handles **paused** projects) on cloud.

## Overview

- **Active**: Project is running; users can use it. The backend may auto-pause it after **inactivity** (no console access).
- **Paused**: Project was paused (e.g. due to inactivity). Data is kept; the user must **resume** from the paused curtain.
- The console signals “I’m using this project” by calling **`updateConsoleAccess`**. The backend uses this (and a cooldown) to avoid auto-pausing active projects.

---

## 1. Keeping a project active (`updateConsoleAccess`)

**File:** `src/routes/_public/projects.$projectId.tsx`  
**Helper:** `src/lib/appwrite/console-access.ts`

When the **project layout** loads in the **browser** and the app is on **cloud profile** and the project is **not paused**:

- The layout runs a fire-and-forget **`reportConsoleAccess(projectId)`** (see `src/lib/appwrite/console-access.ts`).
- That helper:
  1. Gets a fingerprint via `generateFingerprintToken()` (`src/lib/fingerprint.ts`).
  2. Sets `sdk.forConsole.client.headers['X-Appwrite-Console-Fingerprint']`.
  3. Calls `sdk.forConsole.projects.updateConsoleAccess({ projectId })` (if the SDK exposes it).
  4. In `finally`, removes the fingerprint header.

**When it is not called:** Non-cloud profile, server-side, or project **status is paused**.

---

## 2. Resuming a paused project

**Files:**

- `src/components/global/layout/PausedProjectCurtain.tsx`
- `src/lib/react-query/hooks/projects.ts` (`useResumeProject`)

- The **paused curtain** blocks project-scoped pages and offers “Upgrade plan” and “Restore project”.
- **Restore** calls `sdk.forConsole.projects.updateStatus({ projectId, status: Status.Active })` with the same fingerprint header set then removed (in `useResumeProject`).
- After success, the project query is invalidated so the curtain hides.

---

## 3. Fingerprint

**File:** `src/lib/fingerprint.ts`

Used for both **`updateConsoleAccess`** and **resume**. Sent as `X-Appwrite-Console-Fingerprint`; the header is removed after the request.

**Required for cloud:** Set **`VITE_CONSOLE_FINGERPRINT_KEY`** in `.env` (or your build env) to the **same value** the backend uses for fingerprint verification. If unset, the token is sent unsigned and the backend returns **401 Invalid console fingerprint**. In dev, a console warning is shown when the key is missing.

---

## Summary

| Concern                       | Behavior                                                                                                                                                                                                                                                         |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Keeping a project active**  | Console calls `projects.updateConsoleAccess(projectId)` when the project layout loads in the browser (cloud, non-paused). Implemented in `reportConsoleAccess()`; triggered from `projects.$projectId.tsx`. Backend uses a 6-day cooldown to avoid auto-pausing. |
| **When the signal is sent**   | On project layout load (open or navigate inside project), only when cloud + browser + non-paused.                                                                                                                                                                |
| **When it is not sent**       | Non-cloud profile, server-side, or project status is **paused**.                                                                                                                                                                                                 |
| **Resuming a paused project** | User clicks “Restore project” in `PausedProjectCurtain` → `updateStatus(projectId, Status.Active)` with fingerprint; then project query is invalidated.                                                                                                          |
| **Fingerprint**               | Same helper for `updateConsoleAccess` and resume; header set before request, removed in `finally`.                                                                                                                                                               |
