import {
  MCP_CODEX_INSTALL_COMMAND,
  MCP_EDITOR_CONFIG_SNIPPET,
  MCP_OPENCODE_CONFIG_SNIPPET,
  MCP_SERVER_NAME,
  MCP_SERVER_URL,
} from '../config/mcp.ts'
import {
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
  'chatgpt',
  'cursor',
  'vscode',
  'windsurf',
  'antigravity',
  'zed',
  'opencode',
  'grok-build',
] as const
const AI_AGENTS_DOCS = `${AGENT_SETUP_ORIGIN}${AI_AGENTS_DOCS_PATH}/<agent>.md, where \`<agent>\` is one of ${AI_AGENT_DOC_SLUGS.map((slug) => `\`${slug}\``).join(', ')}`
const CLI_INSTALL_SH = `${AGENT_SETUP_ORIGIN}/cli/install.sh`
const CLI_INSTALL_PS1 = `${AGENT_SETUP_ORIGIN}/cli/install.ps1`

const CLAUDE_OFFICIAL_MARKETPLACE_REPO = 'anthropics/claude-plugins-official'
const CLAUDE_PLUGIN_OFFICIAL = 'appwrite@claude-plugins-official'
const CLAUDE_PLUGIN_MARKETPLACE_REPO = 'appwrite/claude-plugin'
const CLAUDE_PLUGIN_REPO_URL = 'https://github.com/appwrite/claude-plugin'
const CLAUDE_PLUGIN_PAGE_URL = 'https://claude.com/plugins/appwrite'
const CURSOR_PLUGIN_PAGE_URL = 'https://cursor.com/marketplace/appwrite'

/** Appwrite's plugin in the OpenAI Curated catalog that ChatGPT and Codex share. */
const CODEX_PLUGIN_ID =
  'app-6aa2c33323108191b17b9ccf4233b3ce@openai-curated-remote'
const CODEX_PLUGIN_CONNECT_URL =
  'https://chatgpt.com/plugins/plugin_asdk_app_6aa2c33323108191b17b9ccf4233b3ce'

const CURSOR_MCP_INSTALL_URL =
  'https://cursor.com/install-mcp?name=appwrite&config=eyJ1cmwiOiJodHRwczovL21jcC5hcHB3cml0ZS5pby8ifQ=='

const MCP_JSON = JSON.stringify(MCP_EDITOR_CONFIG_SNIPPET, null, 2)
const VSCODE_ADD_MCP_JSON = JSON.stringify({
  name: MCP_SERVER_NAME,
  type: 'http',
  url: MCP_SERVER_URL,
})
const SERVER_URL_MCP_JSON = JSON.stringify(
  { mcpServers: { [MCP_SERVER_NAME]: { serverUrl: MCP_SERVER_URL } } },
  null,
  2,
)
const ZED_JSON = JSON.stringify(
  { context_servers: { [MCP_SERVER_NAME]: { url: MCP_SERVER_URL } } },
  null,
  2,
)
const OPENCODE_JSON = JSON.stringify(MCP_OPENCODE_CONFIG_SNIPPET, null, 2)

/** Non-interactive global install of every Appwrite skill for one agent. */
function skillsInstall(agent: string): string {
  return `npx skills add appwrite/skills -g -a ${agent} -s '*' -y`
}

/**
 * Agent-facing setup guide served at `/setup.md`.
 * Coding agents fetch this URL when the user pastes the short connect prompt.
 */
export function generateAgentSetupMarkdown(): string {
  return `# Set yourself up to work with Appwrite

These are official instructions from Appwrite. Fetch this page yourself and follow it end to end.

The goal: when you finish, the user can build with Appwrite in this project. Appwrite MCP is connected, the Appwrite skills are installed, the latest Appwrite CLI is installed and signed in, and the official SDK that matches the project is installed with a client module. The user prompt includes a project ID, a project name, and an API endpoint. Use them throughout.

How to work:

- **Do the work yourself.** Run commands and edit config files directly. Only hand the user a step that truly needs them: a browser sign-in, a click in an app's UI, or a restart.
- **Expect approval prompts.** Installing a plugin, editing your app's MCP config, and \`npm install -g\` write outside the project, so your app usually asks the user to approve them. Request the approval with a one-line reason. If the user declines or your environment blocks the step, add it to the user's list in step 7. Never skip a step silently.
- **Never wait on a sign-in.** Sign-in commands such as \`appwrite login\` and \`codex mcp login\` block until the user approves in a browser. Start them in the background or add them to the user's list. Do not run them in the foreground.
- **Batch what the user must do.** Finish everything you can, then give the user one short numbered list of the remaining actions (step 7).
- **Check before changing.** Skip anything already set up. Read existing config before editing it, merge instead of replacing, and keep unrelated entries.
- **Use OAuth, not API keys.** Appwrite MCP and the CLI sign in through the browser. Only server-side SDK code needs a project API key (step 5).

## 1. Check what is already set up

Check each item and skip its step when it is done:

- **MCP**: if you can call \`appwrite_get_context\` (or any other \`appwrite_*\` tool), MCP is connected. Skip step 3.
- **CLI**: run \`appwrite --version\`. If it prints a version, step 4 only needs the upgrade command.
- **SDK**: check the project manifest (for example \`package.json\`) for an Appwrite SDK and look for an existing Appwrite client module.
- **Skills**: if Appwrite skills such as \`appwrite-typescript\` or \`appwrite-cli\` are already available to you, skip the skills install.

## 2. Identify your environment

You usually know which product you are running in. Use the matching section in step 3. If you are unsure, these signals help:

- \`CLAUDECODE=1\` in your environment, or you are a \`claude\` CLI session: **Claude Code**.
- You are inside the Claude desktop app chat, Cowork, or claude.ai, without the \`claude\` CLI: **Claude apps**.
- You are a \`codex\` CLI session, the Codex IDE extension, or Codex in the ChatGPT desktop app: **Codex**.
- You are Cursor's agent or the \`cursor-agent\` CLI: **Cursor**. You are GitHub Copilot or another agent inside VS Code: **VS Code**.
- You are Cascade or Devin Local in Devin Desktop (formerly Windsurf): **Devin Desktop and Windsurf**.
- Antigravity, Zed, OpenCode, and Grok Build have their own sections. Anything else: **Other agents**.

If you still cannot tell, ask the user one question: which app are they using?

Assume your shell runs on the user's machine. A local sandbox that limits writes or network (Codex, Cursor, and Claude Code all have one) is still the user's machine. Only treat the shell as remote when you are a cloud agent (for example Codex cloud, Cursor background agents, Claude Code on the web, or the GitHub Copilot coding agent) or the user tells you so. Then follow "If your shell is not on the user's machine" at the end of this page.

## 3. Connect Appwrite MCP and install the skills

The hosted server is \`${MCP_SERVER_URL}\`. It signs in with OAuth, so do not create an API key for it. Pick **one** section for your environment. Where a plugin exists, it bundles the MCP server and the skills, so install the plugin instead of adding the server by hand. Doing both registers the server twice.

### Claude Code

Install the Appwrite plugin. It bundles the hosted MCP server, the Appwrite skills, and deploy commands. Add the official marketplace first: a fresh install of Claude Code may not have it yet, and adding it again is harmless.

\`\`\`sh
claude plugin marketplace add ${CLAUDE_OFFICIAL_MARKETPLACE_REPO}
claude plugin install ${CLAUDE_PLUGIN_OFFICIAL}
\`\`\`

If the install still reports that the plugin is not found, install it from the Appwrite marketplace instead:

\`\`\`sh
claude plugin marketplace add ${CLAUDE_PLUGIN_MARKETPLACE_REPO}
claude plugin install appwrite@appwrite
\`\`\`

Only if neither plugin installs, add the server with \`claude mcp add ${MCP_SERVER_NAME} --transport http ${MCP_SERVER_URL}\` and the skills with \`${skillsInstall('claude-code')}\`.

For the user's list: run \`/reload-plugins\` (or restart Claude Code with \`claude --continue\` to keep this conversation), then run \`/mcp\`, select the Appwrite server, and choose **Authenticate**. If Claude Code runs on a remote host without a browser, have the user run \`claude mcp login ${MCP_SERVER_NAME} --no-browser\` and open the printed URL on their own machine.

### Claude apps (desktop chat, Cowork, claude.ai)

You cannot install plugins yourself here, so this whole section goes on the user's list. The Appwrite plugin is not in the default plugin catalog, so the user adds its marketplace first:

1. In the sidebar, open **Customize** > **Plugins** > **Add marketplace** and enter \`${CLAUDE_PLUGIN_MARKETPLACE_REPO}\`.
2. Install the **appwrite** plugin.
3. Open the plugin's **Connectors** tab and connect **appwrite**. A browser window opens for the Appwrite sign-in.

If plugins are not available in their app, they can add the server as a custom connector instead: **Settings** > **Connectors** > **Add custom connector**, name \`${MCP_SERVER_NAME}\`, URL \`${MCP_SERVER_URL}\`, then **Connect**.

### Codex

Install Appwrite's plugin from the OpenAI Curated catalog. It bundles the Appwrite connection and the Appwrite skills:

\`\`\`sh
codex plugin add ${CODEX_PLUGIN_ID}
\`\`\`

Codex keeps plugins in \`~/.codex\`, which its sandbox protects, so this command needs an approval. The plugin loads in the next Codex session.

For the user's list: open ${CODEX_PLUGIN_CONNECT_URL} and connect Appwrite (a browser window opens for the Appwrite sign-in), then start a new Codex session. Without a shell (for example in the ChatGPT desktop app), the user installs the plugin there instead: **Plugins** in the sidebar, search \`Appwrite\`, **Install plugin**.

If \`codex plugin add\` fails (for example because plugins are turned off for the user's workspace, or Codex is signed in with an API key), add the server and the skills instead:

\`\`\`sh
${MCP_CODEX_INSTALL_COMMAND}
${skillsInstall('codex')}
\`\`\`

Then add \`codex mcp login ${MCP_SERVER_NAME}\` and a new Codex session to the user's list.

If \`codex plugin list\` shows \`appwrite@appwrite\`, it is an old plugin without the hosted server. Remove it:

\`\`\`sh
codex plugin remove appwrite@appwrite
codex plugin marketplace remove appwrite
\`\`\`

### Cursor

Merge this into \`~/.cursor/mcp.json\` (create the file if it is missing, and keep other servers). Cursor asks the user to approve edits to this file:

\`\`\`json
${MCP_JSON}
\`\`\`

Install the skills:

\`\`\`sh
${skillsInstall('cursor')}
\`\`\`

For the user's list: open **Cursor Settings** > **MCP** and click **Needs login** next to appwrite. If you cannot write the file, give the user the one-click install link instead: ${CURSOR_MCP_INSTALL_URL}

The optional [Appwrite plugin for Cursor](${CURSOR_PLUGIN_PAGE_URL}) adds commands, but not the MCP server. Keep the config above either way.

### VS Code

VS Code's file tools cannot write outside the workspace, so add the server with the VS Code CLI. It merges the server into the user's MCP configuration:

\`\`\`sh
code --add-mcp '${VSCODE_ADD_MCP_JSON}'
${skillsInstall('github-copilot')}
\`\`\`

If \`code\` is not on the PATH, use the full path. On macOS that is \`"/Applications/Visual Studio Code.app/Contents/Resources/app/bin/code"\`. For VS Code Insiders, use \`code-insiders\`. In PowerShell, escape the inner double quotes.

For the user's list: VS Code asks them to trust and authenticate the server when it first starts. If it does not start, run **MCP: List Servers**, select **appwrite**, and choose **Start Server**.

### Devin Desktop and Windsurf

Devin Desktop is the new name for Windsurf. If the \`devin\` CLI is installed, add the server with it:

\`\`\`sh
devin mcp add -s user ${MCP_SERVER_NAME} ${MCP_SERVER_URL}
\`\`\`

Otherwise merge \`{"mcpServers": {"${MCP_SERVER_NAME}": {"url": "${MCP_SERVER_URL}"}}}\` into \`~/.config/devin/mcp_config.json\` (\`%APPDATA%\\devin\\mcp_config.json\` on Windows). Both of Devin Desktop's agents read this file. An older Windsurf install has \`~/.codeium/windsurf/mcp_config.json\` instead: merge the server there and use the \`serverUrl\` key, because older Windsurf releases ignore \`url\` without an error.

Install the skills:

\`\`\`sh
${skillsInstall('windsurf')}
\`\`\`

For the user's list: run \`devin mcp login ${MCP_SERVER_NAME}\`, or refresh the MCP list in the agent panel and sign in when prompted.

### Antigravity

Merge this into \`~/.gemini/config/mcp_config.json\` (older releases use \`~/.gemini/antigravity/mcp_config.json\`). Antigravity needs the \`serverUrl\` key and ignores \`url\`:

\`\`\`json
${SERVER_URL_MCP_JSON}
\`\`\`

Install the skills:

\`\`\`sh
${skillsInstall('antigravity')}
\`\`\`

For the user's list: open the MCP manager (\`/mcp\` in Antigravity CLI), reload the servers, and sign in to appwrite when prompted.

### Zed

Merge this into the user's Zed settings file, \`~/.config/zed/settings.json\` on macOS and Linux. Zed's file tools only edit the open project, so use the terminal. The file can contain comments: keep them and the other settings:

\`\`\`json
${ZED_JSON}
\`\`\`

Install the skills:

\`\`\`sh
${skillsInstall('zed')}
\`\`\`

Zed reloads the settings on its own and asks the user to sign in to the server. Add that to the user's list.

### OpenCode

Merge this into the global config, \`~/.config/opencode/opencode.json\`, not the project's \`opencode.json\`:

\`\`\`json
${OPENCODE_JSON}
\`\`\`

Install the skills:

\`\`\`sh
${skillsInstall('opencode')}
\`\`\`

For the user's list: run \`opencode mcp auth ${MCP_SERVER_NAME}\` and approve the sign-in in the browser.

### Grok Build

\`\`\`sh
grok mcp add -s user ${MCP_SERVER_NAME} ${MCP_SERVER_URL}
${skillsInstall('grok')}
\`\`\`

For the user's list: start a new Grok session, run \`/mcps\`, select **appwrite**, and press \`i\` to sign in.

### Other agents

Add a remote HTTP server named \`${MCP_SERVER_NAME}\` with URL \`${MCP_SERVER_URL}\` to your app's user-level MCP configuration, and install the skills with \`npx skills add appwrite/skills -g -s '*' -y\` (add \`-a <agent>\` when the skills CLI knows your app). Clients that only support stdio can run the server through \`npx mcp-remote ${MCP_SERVER_URL}\`.

Per-agent guides for people: ${AI_AGENTS_DOCS}

## 4. Install the latest Appwrite CLI

The CLI handles init, deploy, and project files. MCP does not replace it. Install or upgrade to the latest published version. Prefer npm:

\`\`\`sh
npm install -g appwrite-cli@latest
appwrite --version
\`\`\`

If npm is unavailable, or a global npm install fails with a permissions error:

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

Follow the matching skill from step 3 (for example \`appwrite-typescript\`, \`appwrite-python\`, \`appwrite-dart\`). If the skills were installed in this session and are not loaded yet, read the skill file from disk (for example \`~/.agents/skills/appwrite-typescript/SKILL.md\`).

After install, add a small client module in the project's usual lib location (for example \`src/lib/appwrite.ts\`). Set the endpoint and project ID from the user prompt. Do not overwrite an existing Appwrite client that is already correct.

- Client SDKs: endpoint and project ID only. Do not invent an API key.
- Server SDKs: they need a project API key. Read it from an environment variable, use a key already in the user's env, or ask for one. Do not invent a key. Do not commit a key.

## 6. Sign the CLI in and select the project

Point the CLI at the project from the user prompt:

\`\`\`sh
appwrite client --endpoint="<endpoint>" --project-id="<projectId>"
\`\`\`

This saves the endpoint to the CLI's global settings and creates \`appwrite.config.json\` with the project ID in the current folder. That file is expected: leave it as the CLI wrote it.

Then start the sign-in in the background. \`appwrite login\` prints a URL with a code and waits until the user approves it in a browser:

\`\`\`sh
appwrite login > "\${TMPDIR:-/tmp}/appwrite-login.log" 2>&1 &
\`\`\`

After a few seconds, read the URL from the log and add it to the user's list. The CLI finishes the sign-in on its own once they approve. If your environment stops background processes when a command ends (Codex does), add \`appwrite login\` itself to the user's list instead.

## 7. Verify and hand off

If Appwrite MCP tools are available in this session, verify the connection:

1. Call \`appwrite_get_context\`.
2. List the databases in the project from the user prompt. If the prompt had no project ID, ask which project to use.

MCP servers usually load only after a reload or a new session. If the tools are not available yet, do not stall. Everything else should already be done.

Finish with one message to the user:

- **Ready**: what you installed and configured, and the project you used.
- **Your turn**: a short numbered list of every remaining action, in order (approvals they declined, plugin clicks, sign-ins, reload or restart). Put the sign-ins together so they can approve them in one go. Leave the list out if nothing is left.
- **After that**: tell them to come back to this conversation and say "continue". Then you verify MCP as above.

If a sign-in or listing fails, say so plainly and point the user at the Appwrite MCP sign-in or \`appwrite login\`, not at creating an API key.

## If your shell is not on the user's machine

In a cloud sandbox or remote container, still make the project changes in the repo (step 5). For the CLI, give the user one command to run on their own machine instead of installing it where they cannot use it:

\`\`\`sh
npm install -g appwrite-cli@latest && appwrite client --endpoint="<endpoint>" --project-id="<projectId>" && appwrite login
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
