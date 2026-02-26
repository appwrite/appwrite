# API Testing Made Developer-First with Requestly

**Requestly** is a Git-friendly API testing tool built for developers who prefer version control over manual exports.

![Appwrite API Playground](https://github.com/shrawansaproo/appwrite-local/blob/f15c106d848383ebd12c82c552a680b66f1563c5/appwrite-api-play.png)

No accounts. No collection sharing links. No sync conflicts.  
Just clone → import → test → ship.

**Requestly GitHub**: https://github.com/requestly/requestly


## Quick Links — Products & Services Used

- **Requestly** → https://github.com/requestly/requestly  
- **Appwrite** → https://github.com/appwrite/appwrite  
- **Docker** → https://github.com/docker  
- **Docker Compose** → https://github.com/docker/compose  


## Quick Start: API Testing with Requestly

Get up and running with Appwrite's APIs in under 60 seconds using Requestly — no manual setup, no copy-pasting cURL commands.


### Prerequisites

- [Requestly Desktop](https://requestly.com/desktop) installed  
- [Docker](https://docker.com) installed and running  
- This repository cloned locally  


### Step 1: Start Appwrite Locally

```bash
docker compose up -d
```

Wait approximately 60 seconds for Appwrite to initialize.


### Step 2: Create Your Project

1. Go to http://localhost  
2. Create an account  
3. Create a new project  
4. Copy your **Project ID** from **Settings**  
5. Go to **API Keys** → Create Key with all scopes → Copy the key  


### Step 3: Import the Requestly Collection

1. Open **Requestly Desktop**  
2. Click **API Client** → **Import**  
3. Select **Local Workspace**  
4. Point it to this repo’s `.requestly/` folder  
5. The collection **Appwrite API Playground** appears instantly  


### Step 4: Set Your Environment Variables

1. In Requestly → Click **Environments**  
2. Select **Appwrite Local**  
3. Fill in your values:

```
APPWRITE_PROJECT_ID = your_project_id
APPWRITE_API_KEY    = your_api_key
APPWRITE_ENDPOINT   = http://localhost/v1
```


### Step 5: Run Your First Request

1. Open **01 - Authentication** → **Create Account**  
2. Click **Send**  
3. You should see a `201 Created` response with your new user object  

You are now live.


## Why Requestly vs Postman or cURL?

| Feature | Requestly | Postman | cURL |
|----------|------------|----------|-------|
| Lives inside your repo | YES | No | No |
| Git-versionable | YES | Manual export | N/A |
| Import with zero clicks | YES | Requires account | N/A |
| Team sync via GitHub PR | YES | Paid plan | N/A |
| Works offline | YES | Limited | YES |
| Env vars per branch | YES | Workspace-level | No |

With Postman, teams must manually export and import collection JSON files and manage separate environment files outside the codebase.

Requestly treats your API collection like code — versioned, reviewable, and always in sync with the branch you are working on.


## Onboarding New Contributors

When a new developer clones this repo, they get the full API collection automatically.

No sharing links.  
No Postman accounts.  
No import friction.  

Just open Requestly, point it at this repo, and start building.


## What's Inside `.requestly/`

```
.requestly/
├── collections/
│   └── appwrite-api-playground.json   (12 pre-built API requests)
└── environments/
    └── appwrite-local.json            (Environment template)
```


## Outcome

- Zero setup friction  
- Fully version-controlled API testing  
- Developer-first onboarding  
- PR-driven collaboration  

API testing should integrate seamlessly into your development workflow.

With Requestly, it does.

---