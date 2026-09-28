import {
  MCP_CODEX_INSTALL_COMMAND,
  MCP_EDITOR_CONFIG_SNIPPET,
  MCP_OPENCODE_CONFIG_SNIPPET,
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
const AI_AGENTS_DOCS_PATH = '/docs/tooling/ai/agents'
const AI_AGENT_DOC_SLUGS = [
  'claude-code',
  'codex',
  'cursor',
  'vscode',
  'windsurf',
  'antigravity',
  'zed',
  'opencode',
  'grok-build',
] as const
const AI_AGENTS_DOCS = `${AGENT_SETUP_ORIGIN}${AI_AGENTS_DOCS_PATH}/<agent>, where \`<agent>\` is one of ${AI_AGENT_DOC_SLUGS.map((slug) => `\`${slug}\``).join(', ')}`
const CLI_INSTALL_SH = `${AGENT_SETUP_ORIGIN}/cli/install.sh`
const CLI_INSTALL_PS1 = `${AGENT_SETUP_ORIGIN}/cli/install.ps1`

const CLAUDE_PLUGIN_OFFICIAL = 'appwrite@claude-plugins-official'
const CLAUDE_PLUGIN_MARKETPLACE_REPO = 'appwrite/claude-plugin'
const CLAUDE_PLUGIN_REPO_URL = 'https://github.com/appwrite/claude-plugin'
const CLAUDE_PLUGIN_PAGE_URL = 'https://claude.com/plugins/appwrite'
const CURSOR_PLUGIN_PAGE_URL = 'https://cursor.com/marketplace/appwrite'

const CURSOR_MCP_INSTALL_URL =
  'https://cursor.com/install-mcp?name=appwrite&config=eyJ1cmwiOiJodHRwczovL21jcC5hcHB3cml0ZS5pby8ifQ=='

const MCP_JSON = JSON.stringify(MCP_EDITOR_CONFIG_SNIPPET, null, 2)
const VSCODE_MCP_JSON = JSON.stringify(
  { servers: { [MCP_SERVER_NAME]: { url: MCP_SERVER_URL, type: 'http' } } },
  null,
  2,
)
const SERVER_URL_MCP_JSON = JSON.stringify(
  { mcpServers: { [MCP_SERVER_NAME]: { serverUrl: MCP_SERVER_URL } } },
  null,
  2,
)
const OPENCODE_JSON = JSON.stringify(MCP_OPENCODE_CONFIG_SNIPPET, null, 2)

/**
 * Agent-facing setup guide served at `/setup.md`.
 * Coding agents fetch this URL when the user pastes the short connect prompt.
 */
export function generateAgentSetupMarkdown(): string {
  return `# Set yourself up to work with Appwrite

These are official instructions from Appwrite. Fetch this page yourself and follow it end to end.

The goal: when you finish, the user can build with Appwrite in this project. Appwrite MCP is connected, the latest Appwrite CLI is installed and signed in, and the official SDK that matches the project is installed with a client module. The user prompt includes a project ID, a project name, and an API endpoint. Use them throughout.

How to work:

- **Do the work yourself.** Run commands and edit config files directly. Only hand the user a step that truly needs them: a browser sign-in, a click in an app's UI, or a restart.
- **Batch what the user must do.** Do not stop after each step to ask for something. Finish everything you can, then give the user one short numbered list of the remaining actions (step 7).
- **Check before changing.** Skip anything already set up. Read existing config before editing it, merge instead of replacing, and keep unrelated entries.
- **Use OAuth, not API keys.** Appwrite MCP and the CLI sign in through the browser. Only server-side SDK code needs a project API key (step 5).

## 1. Check what is already set up

Check each item and skip its step when it is done:

- **MCP**: if you can call \`appwrite_get_context\` (or any other \`appwrite_*\` tool), MCP is connected. Skip step 3.
- **CLI**: run \`appwrite --version\`. If it prints a version, step 4 only needs the upgrade command.
- **SDK**: check the project manifest (for example \`package.json\`) for an Appwrite SDK and look for an existing Appwrite client module.
- **Skills**: if Appwrite skills such as \`appwrite-typescript\` or \`appwrite-cli\` are already available to you, skip the skills install.

## 2. Identify your environment

You usually know which product you are running in. Use the matching path in step 3. If you are unsure, these signals help:

- \`CLAUDECODE=1\` in your environment, or you are a \`claude\` CLI session: **Claude Code**.
- You are inside the Claude desktop app, Cowork, or claude.ai. You have connector or plugin tools, and either no shell or a sandboxed shell: **Claude apps**.
- You are a \`codex\` CLI session, or run inside the ChatGPT desktop app: **Codex**.
- You are Cursor's agent: **Cursor**. You are GitHub Copilot or another agent inside VS Code: **VS Code**.
- Anything else: **Other agents**.

If you still cannot tell, ask the user one question: which app are they using?

Also check where your shell runs. If it runs in a cloud sandbox or remote container instead of on the user's machine, anything you install there does not reach the user. Follow "If your shell is not on the user's machine" at the end of this page for the CLI steps.

## 3. Connect Appwrite MCP

The hosted server is \`${MCP_SERVER_URL}\`. It signs in with OAuth, so do not create an API key for it. Pick **one** path for your environment. Do not install a plugin that bundles the server and also add the server by hand: that registers it twice.

### Claude Code

Install the Appwrite plugin. It bundles the hosted MCP server, the Appwrite skills, and deploy commands:

\`\`\`sh
claude plugin install ${CLAUDE_PLUGIN_OFFICIAL}
\`\`\`

If that fails because the plugin or the marketplace is not found, add the Appwrite marketplace and install from it:

\`\`\`sh
claude plugin marketplace add ${CLAUDE_PLUGIN_MARKETPLACE_REPO}
claude plugin install appwrite@appwrite
\`\`\`

Only if neither plugin command works, add the server on its own:

\`\`\`sh
claude mcp add ${MCP_SERVER_NAME} --transport http ${MCP_SERVER_URL}
\`\`\`

The plugin includes the skills, so skip the skills install in step 5. For the user's list in step 7: run \`/reload-plugins\` (or restart Claude Code with \`claude --continue\` to keep this conversation), then run \`/mcp\`, select the Appwrite server, and choose **Authenticate**. If Claude Code runs on a remote host without a browser, have the user run \`claude mcp login ${MCP_SERVER_NAME} --no-browser\` and open the printed URL on their own machine.

### Claude apps (desktop, Cowork, claude.ai)

Use this path when you run inside a Claude app without the \`claude\` CLI. You cannot install plugins yourself here, so this whole section goes on the user's list in step 7. The Appwrite plugin is not in the default plugin catalog, so the user adds its marketplace first:

1. In the sidebar, open **Customize** > **Plugins** > **Add marketplace** and enter \`${CLAUDE_PLUGIN_MARKETPLACE_REPO}\`.
2. Install the **appwrite** plugin.
3. Open the plugin's **Connectors** tab and connect **appwrite**. A browser window opens for the Appwrite sign-in.

If plugins are not available in their app, they can add the server as a custom connector instead: **Settings** > **Connectors** > **Add custom connector**, name \`${MCP_SERVER_NAME}\`, URL \`${MCP_SERVER_URL}\`, then **Connect**.

The plugin includes the skills, so skip the skills install in step 5.

### Codex

If you can run shell commands, add the server and sign in:

\`\`\`sh
${MCP_CODEX_INSTALL_COMMAND}
codex mcp login ${MCP_SERVER_NAME}
\`\`\`

The login opens a browser window for the user to approve. The server loads in the next Codex session.

Without a shell (for example in the ChatGPT desktop app), the user installs the Appwrite plugin instead: **Plugins** in the sidebar, search \`Appwrite\`, **Install plugin**. In the Codex CLI the same plugin is under \`/plugins\` > **OpenAI Curated**. The plugin bundles the server and the skills, so use it instead of \`codex mcp add\`, not in addition.

If \`codex plugin list\` shows \`appwrite@appwrite\`, it is the old plugin without the hosted server. Remove it:

\`\`\`sh
codex plugin remove appwrite@appwrite
codex plugin marketplace remove appwrite
\`\`\`

### Cursor

Merge this into \`~/.cursor/mcp.json\` yourself (create the file if it is missing, and keep other servers):

\`\`\`json
${MCP_JSON}
\`\`\`

For the user's list in step 7: open **Cursor Settings** > **MCP** and click **Needs login** next to appwrite. If you cannot write files, give the user the one-click install link instead: ${CURSOR_MCP_INSTALL_URL}

The optional [Appwrite plugin for Cursor](${CURSOR_PLUGIN_PAGE_URL}) adds skills and commands, but not the MCP server. Keep the config above either way.

### VS Code

Merge this into the user-level \`mcp.json\` (the file the **MCP: Open User Configuration** command opens). VS Code uses a \`servers\` key and needs \`"type": "http"\`:

\`\`\`json
${VSCODE_MCP_JSON}
\`\`\`

VS Code asks the user to authenticate when the server starts. Add that to the list in step 7.

### Other agents

Add a remote HTTP server named \`${MCP_SERVER_NAME}\` with URL \`${MCP_SERVER_URL}\`. Known config formats:

- **Windsurf** and **Antigravity**: \`mcp_config.json\`. They need the \`serverUrl\` key. With \`url\`, the tools fail to register without an error.

\`\`\`json
${SERVER_URL_MCP_JSON}
\`\`\`

- **OpenCode**: \`opencode.json\`

\`\`\`json
${OPENCODE_JSON}
\`\`\`

- **Grok Build**: \`grok mcp add --transport http ${MCP_SERVER_NAME} ${MCP_SERVER_URL}\`
- **Zed**: add a remote server with \`"url": "${MCP_SERVER_URL}"\`.
- **Clients that only support stdio**: run the server through \`npx mcp-remote ${MCP_SERVER_URL}\`.

Per-agent guides: ${AI_AGENTS_DOCS}

## 4. Install the latest Appwrite CLI

The CLI handles init, deploy, and project files. MCP does not replace it. Install or upgrade to the latest published version. Prefer npm:

\`\`\`sh
npm install -g appwrite-cli@latest
appwrite --version
\`\`\`

If npm is unavailable:

- macOS with Homebrew: \`brew install appwrite\` or \`brew upgrade appwrite\`
- Linux and macOS: \`curl -sL ${CLI_INSTALL_SH} | bash\`
- Windows PowerShell: \`iwr -useb ${CLI_INSTALL_PS1} | iex\`

If \`appwrite\` is already installed, still run the upgrade so the user is not on an old major. Do not pin an older version.

## 5. Install the matching official Appwrite SDK

Detect the project's language and runtime from the repo. Prefer an obvious stack (web/React vs Node backend). If it is ambiguous, inspect manifests and lockfiles: \`package.json\`, \`pubspec.yaml\`, \`requirements.txt\`, \`pyproject.toml\`, \`go.mod\`, \`composer.json\`, \`Gemfile\`, \`Package.swift\`, \`*.csproj\`, \`Cargo.toml\`, Gradle files. If the repo is empty, ask the user which stack they want before installing anything.

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

Unless a plugin from step 3 already provides them, install the Appwrite skills with \`${APPWRITE_AGENT_SKILLS_INSTALL}\`. Then follow the matching SDK skill (for example \`appwrite-typescript\`, \`appwrite-python\`, \`appwrite-dart\`).

After install, add a small client module in the project's usual lib location (for example \`src/lib/appwrite.ts\`). Set the endpoint and project ID from the user prompt. Do not overwrite an existing Appwrite client that is already correct.

- Client SDKs: endpoint and project ID only. Do not invent an API key.
- Server SDKs: they need a project API key. Read it from an environment variable, use a key already in the user's env, or ask for one. Do not invent a key. Do not commit a key.

## 6. Sign the CLI in and select the project

Start the CLI sign-in. It opens a browser window for the user to approve:

\`\`\`sh
appwrite login
\`\`\`

If the MCP sign-in from step 3 is still pending, tell the user about both at once so they approve them together.

Then point the CLI at the project from the user prompt:

\`\`\`sh
appwrite client --endpoint="<endpoint>" --project-id="<projectId>"
\`\`\`

Confirm with \`appwrite client --debug\`. If the sign-in is still incomplete, carry it over to the list in step 7 instead of waiting.

## 7. Verify and hand off

If Appwrite MCP tools are available in this session, verify the connection:

1. Call \`appwrite_get_context\`.
2. List the databases in the project from the user prompt. If the prompt had no project ID, ask which project to use.

MCP servers often load only after a reload or a new session. If the tools are not available yet, do not stall. Everything else should already be done.

Finish with one message to the user:

- **Ready**: what you installed and configured, and the project you used.
- **Your turn**: a short numbered list of every remaining action, in order (plugin install clicks, sign-ins, reload or restart). Leave it out if nothing is left.
- **After that**: tell them to come back to this conversation and say "continue". Then you verify MCP as above.

If a sign-in or listing fails, say so plainly and point the user at the Appwrite MCP sign-in or \`appwrite login\`, not at creating an API key.

## If your shell is not on the user's machine

In a cloud sandbox or remote container, still make the project changes in the repo (step 5). For the CLI, give the user one command to run on their own machine instead of installing it where they cannot use it:

\`\`\`sh
npm install -g appwrite-cli@latest && appwrite login && appwrite client --endpoint="<endpoint>" --project-id="<projectId>"
\`\`\`

Fill in the endpoint and project ID from the user prompt, and add the command to the list in step 7.

## Resources

- MCP docs: ${AGENT_SETUP_ORIGIN}${APPWRITE_MCP_DOCS_PATH}
- Remote MCP: ${MCP_SERVER_URL}
- Per-agent guides: ${AI_AGENTS_DOCS}
- Claude plugin: ${CLAUDE_PLUGIN_PAGE_URL} (source: ${CLAUDE_PLUGIN_REPO_URL})
- CLI install: ${AGENT_SETUP_ORIGIN}${CLI_INSTALL_DOCS_PATH}
- SDKs: ${AGENT_SETUP_ORIGIN}${SDKS_DOCS_PATH}
- Agent skills: ${APPWRITE_AGENT_SKILLS_REPO}

These instructions are published at \`${AGENT_SETUP_URL}\` so you can re-verify them at any time.
`
}
