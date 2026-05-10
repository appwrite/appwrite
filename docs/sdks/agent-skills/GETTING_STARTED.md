## Getting Started

Agent Skills to help developers using AI coding agents with Appwrite. Agent Skills are folders of instructions, scripts, and resources that agents like Claude Code, Cursor, GitHub Copilot, and others can discover and use to work more accurately and efficiently.

These skills follow the Agent Skills Open Standard: https://agentskills.io/home

### Install the skills

Install directly with the Skills CLI:

```bash
npx skills add appwrite/agent-skills
```

This installs the packaged `appwrite-*` skills into your local skills directory.

### Available language skills

- `appwrite-typescript`
- `appwrite-dart`
- `appwrite-kotlin`
- `appwrite-swift`
- `appwrite-php`
- `appwrite-python`
- `appwrite-ruby`
- `appwrite-go`
- `appwrite-dotnet`

### Usage

Skills are automatically available once installed. The agent will use them when relevant tasks are detected.

### Prompt examples

Use these as copy-paste prompt starters.

#### TypeScript (server-side)

```text
Use the appwrite-typescript skill.
Create a Node.js script that uses Users service to create a user, then adds an initial profile row in TablesDB.
Use env vars for endpoint, project ID, and API key.
Include error handling and a small retry for transient failures.
```

#### TypeScript (web client)

```text
Use the appwrite-typescript skill.
Build a browser login flow with Account service:
- email/password signup
- email/password session login
- fetch current user
- logout current session
Return production-ready TypeScript code.
```

#### Python

```text
Use the appwrite-python skill.
Write a script that uploads a local file to Storage, then prints the file ID and a preview URL.
Use InputFile.from_path and catch Appwrite exceptions.
```

#### PHP

```text
Use the appwrite-php skill.
Create a service class that lists rows from TablesDB with Query.equal and Query.limit, and maps them to DTOs.
Prefer dependency injection for the Appwrite client.
```

#### Go

```text
Use the appwrite-go skill.
Implement a CLI command that creates a document-style row, then reads it back and prints JSON output.
Use context timeouts and structured error messages.
```

#### Kotlin

```text
Use the appwrite-kotlin skill.
Generate Android repository code to paginate rows using cursor queries and expose a suspend function API.
Keep UI concerns out of the data layer.
```

#### Migration prompt (Databases -> TablesDB)

```text
Use the appwrite-typescript skill.
Migrate this existing Databases-based code to TablesDB APIs.
Keep behavior identical and list each API mapping you changed.
```
