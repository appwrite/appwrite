## Installation

Install the skills with the [Skills CLI](https://github.com/vercel-labs/skills):

```bash
npx skills add appwrite/skills
```

The CLI asks which skills to install, which agents should use them, and whether to install them in the current project or globally. To skip the prompts, pass the choices as flags:

```bash
npx skills add appwrite/skills --skill appwrite-typescript --skill appwrite-cli --agent claude-code --global --yes
```

Run `npx skills add appwrite/skills --list` to see every skill in the repository without installing anything.

If you use Claude Code, Cursor, or Codex, the Appwrite plugin for that tool bundles these skills together with the hosted Appwrite MCP server:

- [Claude Code plugin](https://github.com/appwrite/claude-plugin)
- [Cursor plugin](https://github.com/appwrite/cursor-plugin)
- [Codex plugin](https://github.com/appwrite/codex-plugin)

## Available skills

| Skill | Use it for |
| --- | --- |
| `appwrite-typescript` | Web, React Native, Node.js, and Deno apps |
| `appwrite-dart` | Flutter apps and Dart servers |
| `appwrite-kotlin` | Android apps and Kotlin/JVM servers |
| `appwrite-swift` | Apple platform apps and Swift servers |
| `appwrite-php` | PHP servers, including Laravel and Symfony |
| `appwrite-python` | Python servers, including Django, Flask, and FastAPI |
| `appwrite-ruby` | Ruby servers, including Rails and Sinatra |
| `appwrite-go` | Go servers |
| `appwrite-rust` | Rust servers |
| `appwrite-dotnet` | C# and .NET servers |
| `appwrite-cli` | The Appwrite CLI: project config, deployments, and code generation |

## Usage

Agents load a skill automatically when a task matches its description. To make sure a specific skill is used, name it in your prompt.

### Prompt examples

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
Implement a CLI command that creates a row in TablesDB, then reads it back and prints JSON output.
Use context timeouts and structured error messages.
```

#### Kotlin

```text
Use the appwrite-kotlin skill.
Generate Android repository code to paginate rows using cursor queries and expose a suspend function API.
Keep UI concerns out of the data layer.
```

#### Appwrite CLI

```text
Use the appwrite-cli skill.
Pull the functions in my project, add a new Node.js function that runs on a schedule, and deploy it.
```

#### Migration prompt (Databases -> TablesDB)

```text
Use the appwrite-typescript skill.
Migrate this existing Databases-based code to TablesDB APIs.
Keep behavior identical and list each API mapping you changed.
```
