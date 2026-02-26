## Quick Start: API Testing with Requestly

Get up and running with Appwrite's APIs in under 60 seconds using
Requestly — no manual setup, no copy-pasting cURL commands.

### Prerequisites
- [Requestly Desktop](https://requestly.com/desktop) installed
- [Docker](https://docker.com) installed and running
- This repository cloned locally

### Step 1: Start Appwrite Locally
```bash
docker compose up -d
```
Wait ~60 seconds for Appwrite to initialize.

### Step 2: Create Your Project
1. Go to http://localhost → Create account → Create project
2. Copy your **Project ID** from Settings
3. Go to API Keys → Create Key with all scopes → copy the key

### Step 3: Import the Requestly Collection
1. Open **Requestly Desktop**
2. Click **API Client** → **Import**
3. Select **Local Workspace** → point to this repo's `.requestly/` folder
4. The collection **Appwrite API Playground** appears instantly

### Step 4: Set Your Environment Variables
1. In Requestly → click **Environments** → **Appwrite Local**
2. Fill in your values:
   - `APPWRITE_PROJECT_ID` → your Project ID from Step 2
   - `APPWRITE_API_KEY` → your API Key from Step 2
   - Keep `APPWRITE_ENDPOINT` as `http://localhost/v1`

### Step 5: Run Your First Request
1. Open **01 - Authentication** → **Create Account**
2. Click **Send**
3. You should see a `201 Created` response with your new user object

That's it. You're live in under 60 seconds.

---

### Why Requestly vs. Postman or cURL?

| Feature | Requestly | Postman | cURL |
|---|---|---|---|
| Lives inside your repo | YES | No | No |
| Git-versionable | YES | Manual export | N/A |
| Import with zero clicks | YES | Requires account | N/A |
| Team sync via GitHub PR | YES | Paid plan | N/A |
| Works offline | YES | Limited | YES |
| Env vars per branch | YES | Workspace-level | No |

With Postman, teams must manually export/import collection JSON and
manage separate environment files outside the codebase. Requestly
treats your API collection like code — versioned, reviewable, and
always in sync with the branch you're working on.

---

### Onboarding New Contributors

When a new developer clones this repo, they get the full API collection
automatically. No sharing links, no Postman accounts, no importing steps.
Just open Requestly, point it at this repo, and start building.

The `.requestly/` folder includes:
- `collections/appwrite-api-playground.json` — 12 pre-built API requests
- `environments/appwrite-local.json` — template with all required variables
