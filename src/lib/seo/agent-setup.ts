import {
  MCP_API_KEY_PLACEHOLDER,
  MCP_CODEX_INSTALL_COMMAND,
  MCP_EDITOR_CONFIG_SNIPPET,
  MCP_SELF_HOSTED_DOCS_URL,
  MCP_SERVER_NAME,
  MCP_SERVER_URL,
} from '../config/mcp.ts'
import {
  APPWRITE_AGENT_SKILLS_INSTALL,
  APPWRITE_AGENT_SKILLS_REPO,
  APPWRITE_MCP_DOCS_PATH,
} from './agent-discovery.ts'

export const AGENT_SETUP_PATH = '/setup.md'
export const AGENT_SETUP_ORIGIN = 'https://appwrite.io'
export const AGENT_SETUP_URL = `${AGENT_SETUP_ORIGIN}${AGENT_SETUP_PATH}`

const CLI_INSTALL_DOCS_PATH = '/docs/tooling/command-line/installation'
const SDKS_DOCS_PATH = '/docs/sdks'
const CLI_INSTALL_SH = `${AGENT_SETUP_ORIGIN}/cli/install.sh`
const CLI_INSTALL_PS1 = `${AGENT_SETUP_ORIGIN}/cli/install.ps1`

const CURSOR_MCP_INSTALL_URL =
  'https://cursor.com/install-mcp?name=appwrite&config=eyJ1cmwiOiJodHRwczovL21jcC5hcHB3cml0ZS5pby8ifQ=='

const MCP_JSON = JSON.stringify(MCP_EDITOR_CONFIG_SNIPPET, null, 2)

/**
 * Agent-facing setup guide served at `/setup.md`.
 * Coding agents fetch this URL when the user pastes the short connect prompt.
 */
export function generateAgentSetupMarkdown(): string {
  return `# Set yourself up to work with Appwrite

These are official instructions from Appwrite. Fetch this page yourself and follow it. Do not ask the user to paste shell commands unless a browser sign-in, editor UI action, or restart is required. Inspect existing MCP config before changing it. Preserve unrelated servers.

Install Appwrite MCP, the latest Appwrite CLI, and the official Appwrite SDK that matches this project. The user prompt includes an Appwrite project ID. Use that project after MCP, the CLI, and the SDK are connected. If no project ID is present, call \`appwrite_get_context\` and ask which project to use.

## 1. Install Appwrite MCP

Identify the coding environment and use one path below. The remote server is \`${MCP_SERVER_URL}\`. It authenticates with OAuth. Do not create an API key for Appwrite Cloud.

### Claude Code

Install the Appwrite plugin if it is missing:

\`\`\`sh
claude plugin install appwrite@claude-plugins-official
\`\`\`

Then add the remote MCP server and open MCP so the user can authenticate:

\`\`\`sh
claude mcp add ${MCP_SERVER_NAME} --transport http ${MCP_SERVER_URL}
claude "/mcp"
\`\`\`

Select "appwrite", then "Authenticate". If the plugin or \`claude mcp\` is unavailable, use the Other agents path.

### Codex

\`\`\`sh
${MCP_CODEX_INSTALL_COMMAND}
\`\`\`

Complete OAuth when Codex prompts for it. If \`codex mcp\` is unavailable, use the Other agents path.

### Cursor

Prefer the one-click install:

${CURSOR_MCP_INSTALL_URL}

If that link cannot run, merge this into \`~/.cursor/mcp.json\` (or the project \`.cursor/mcp.json\`) without replacing other servers:

\`\`\`json
${MCP_JSON}
\`\`\`

Reload Cursor. In Settings > MCP, click Needs login next to Appwrite so the user can authorize in the browser.

### VS Code

Add a remote MCP server named \`${MCP_SERVER_NAME}\` with URL \`${MCP_SERVER_URL}\`. Merge into existing MCP settings. Restart or reload, then complete OAuth when VS Code asks.

### Other agents

If the environment uses a standard MCP config file, add a server named \`${MCP_SERVER_NAME}\` with URL \`${MCP_SERVER_URL}\`. Preserve existing entries. For stdio-only clients, use \`npx mcp-remote ${MCP_SERVER_URL}\`.

## 2. Authenticate

The Cloud MCP server uses OAuth. A browser window should open. Ask the user to sign in to Appwrite and approve access. Do not invent API keys for Cloud. After auth succeeds, verify MCP tools are available.

## 3. Install the latest Appwrite CLI

The CLI is for init, deploy, and project files. MCP does not replace it. Install or upgrade to the latest published CLI. Prefer npm:

\`\`\`sh
npm install -g appwrite-cli@latest
appwrite --version
\`\`\`

If npm is unavailable:

- macOS with Homebrew: \`brew install appwrite\` or \`brew upgrade appwrite\`
- Linux and macOS: \`curl -sL ${CLI_INSTALL_SH} | bash\`
- Windows PowerShell: \`iwr -useb ${CLI_INSTALL_PS1} | iex\`

If \`appwrite\` is already installed, still run the npm \`@latest\` command (or the matching upgrade) so the user is not on an old major. Do not pin an older version.

## 4. Install the matching official Appwrite SDK

Detect the project's language and runtime from the repo. Prefer an obvious stack (web/React vs Node backend). If it is ambiguous, inspect manifests and lockfiles: \`package.json\`, \`pubspec.yaml\`, \`requirements.txt\`, \`pyproject.toml\`, \`go.mod\`, \`composer.json\`, \`Gemfile\`, \`Package.swift\`, \`*.csproj\`, \`Cargo.toml\`, Gradle files.

Install or upgrade the matching official SDK to the latest published version. Use the project's package manager. Do not overwrite unrelated dependencies. If the correct SDK is already at latest, skip the install. Do not pin an old major.

Client vs server:

- Browser / React / Vue / Svelte / client-side Next.js: web SDK \`appwrite\`
- Node.js, Bun, or server-side API routes and workers: \`node-appwrite\`
- Deno: \`jsr:@appwrite/sdk\` (or \`npm:node-appwrite\` if the project already uses npm specifiers)
- React Native: \`react-native-appwrite\`
- Flutter or Dart: pub.dev \`appwrite\`
- Native Android: \`io.appwrite:sdk-for-android\`
- Native Apple (iOS, macOS, watchOS, tvOS): \`https://github.com/appwrite/sdk-for-apple\`
- Kotlin or Java backends: \`io.appwrite:sdk-for-kotlin\`
- Server Swift: \`https://github.com/appwrite/sdk-for-swift\`

Install latest (adjust the installer to pnpm, yarn, or bun when that is what the repo uses):

- Web / React (client): \`npm install appwrite@latest\`
- Node.js / Bun (server): \`npm install node-appwrite@latest\`
- React Native: \`npm install react-native-appwrite@latest\`
- Deno: \`deno add jsr:@appwrite/sdk\`
- Flutter: \`flutter pub add appwrite\`
- Dart: \`dart pub add appwrite\`
- Python: \`pip install -U appwrite\`
- PHP: \`composer require appwrite/appwrite\`
- Ruby: \`bundle add appwrite\` (or \`gem install appwrite\`)
- .NET: \`dotnet add package Appwrite\`
- Go: \`go get github.com/appwrite/sdk-for-go@latest\`
- Android: add the latest \`io.appwrite:sdk-for-android\` from Maven Central
- Apple: Swift Package Manager, latest tag of \`https://github.com/appwrite/sdk-for-apple\`
- Kotlin / Java server: latest \`io.appwrite:sdk-for-kotlin\` from Maven Central
- Swift server: latest tag of \`https://github.com/appwrite/sdk-for-swift\`
- Rust: \`cargo add appwrite\`

If skills are missing, run \`${APPWRITE_AGENT_SKILLS_INSTALL}\`. Then follow the matching SDK skill (for example \`appwrite-typescript\`, \`appwrite-python\`, \`appwrite-dart\`).

After install, add a small client module in the project's usual lib location (for example \`src/lib/appwrite.ts\`). Set the endpoint and project ID from the user prompt. Do not overwrite an existing Appwrite client that is already correct.

- Client SDKs: endpoint and project ID only. Do not invent an API key.
- Server SDKs: they need a project API key. Use a key already in the user's env, or ask for one. Do not invent a key. Do not commit a key.

## 5. Log in the CLI and select the project

Use interactive login. A browser window should open. Ask the user to approve access. Do not create an API key for Appwrite Cloud unless they asked for CI/CD.

Cloud:

\`\`\`sh
appwrite login
\`\`\`

Self-hosted only, using the endpoint from the user prompt:

\`\`\`sh
appwrite login --endpoint="<endpoint>"
\`\`\`

Then point the CLI at the project from the user prompt (endpoint from the prompt; no API key):

\`\`\`sh
appwrite client --endpoint="<endpoint>" --project-id="<projectId>"
\`\`\`

Confirm with \`appwrite client --debug\`. If login is incomplete, say so. Do not invent a key.

## 6. Use the user's project

If the user prompt included a project ID, use it for later Appwrite calls. Confirm the connection:

1. Call \`appwrite_get_context\` if that tool exists.
2. List databases in the given project.
3. Tell the user MCP, the CLI, and the SDK are ready and which project you used.

If listing fails because auth is incomplete, say so and point them at the Appwrite MCP login or \`appwrite login\`, not at creating a key.

## 7. Self-hosted Appwrite

Use this path only when the user said the instance is self-hosted and gave an endpoint. Do not use it for Appwrite Cloud.

Add a local MCP server with \`uvx mcp-server-appwrite\` and these environment variables:

- \`APPWRITE_PROJECT_ID\` from the user prompt
- \`APPWRITE_ENDPOINT\` from the user prompt
- \`APPWRITE_API_KEY\` as \`${MCP_API_KEY_PLACEHOLDER}\`, then ask the user to replace it with a real project API key. Do not commit the key.

Self-hosted docs: ${MCP_SELF_HOSTED_DOCS_URL}

## Resources

- MCP docs: ${AGENT_SETUP_ORIGIN}${APPWRITE_MCP_DOCS_PATH}
- Remote MCP: ${MCP_SERVER_URL}
- CLI install: ${AGENT_SETUP_ORIGIN}${CLI_INSTALL_DOCS_PATH}
- SDKs: ${AGENT_SETUP_ORIGIN}${SDKS_DOCS_PATH}
- Agent skills: ${APPWRITE_AGENT_SKILLS_REPO}

These instructions are published at \`${AGENT_SETUP_URL}\` so you can re-verify them at any time.
`
}
