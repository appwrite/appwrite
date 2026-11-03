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
  'pi',
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
const PI_JSON = JSON.stringify(
  { mcpServers: { [MCP_SERVER_NAME]: { url: MCP_SERVER_URL, auth: 'oauth' } } },
  null,
  2,
)

/** Non-interactive global install of every Appwrite skill for one agent. */
function skillsInstall(agent: string): string {
  return `npx --yes skills add appwrite/skills -g -a ${agent} -s '*' -y`
}

/**
 * Agent-facing setup and build guide served at `/setup.md`.
 * Coding agents fetch this URL when the user pastes the short connect prompt.
 */
export function generateAgentSetupMarkdown(): string {
  return `# Set up Appwrite and build with it

This is Appwrite's setup guide for coding agents. Fetch this page yourself and work through it end to end. It has ten numbered steps and ends with a Resources section. If your copy is missing any of them, or reads like a summary, download the full page with \`curl -fsSL ${AGENT_SETUP_URL}\` and read all of it.

**The goal:** the user ends with a working app on Appwrite, not just installed tools. You connect Appwrite MCP, install the Appwrite skills, the latest CLI, and the SDKs the app needs, get the user signed in, propose a build that fits what they are making, build the first working slice, and close with what Appwrite can do next for this app.

The user prompt may include a project ID, a project name, and an API endpoint. When it does, use them throughout. When it does not, you link or create a project in step 6.

## At a glance

For people reading along: everything the agent does, in order, and the little it needs from you.

| Step | The agent | You |
| --- | --- | --- |
| 1. Check | Reads the repo and what is already installed | Nothing |
| 2. Environment | Works out which agent app and shell it runs in | Nothing |
| 3. MCP and skills | Installs the Appwrite plugin or MCP server, plus the Appwrite skills | Approve the install, then authorize Appwrite MCP in your browser |
| 4. CLI | Installs the latest Appwrite CLI and starts its sign-in | Approve the CLI sign-in in your browser |
| 5. Proposal | Proposes the stack, the Appwrite services, and a first slice, then checks in | Reply "go", "setup only", or change the plan |
| 6. Project | Scaffolds an empty repo, links or creates the project, registers your app's platforms, writes the env file | Nothing, unless you pick a project |
| 7. SDKs | Installs the official SDKs the stack needs and adds a client module | Nothing |
| 8. Build | Defines the schema as config, pushes it, then builds and runs the first slice | Review it |
| 9. Ship | Deploys to Appwrite Sites or Functions, only if you said yes | Nothing |
| 10. Hand off | Reports what works, what is left, and what to build next | Pick the next step |

The agent messages you twice: at the checkpoint after step 5 (two browser approvals and a "go"), and at the hand-off.

### What changes on your machine

| What | Where | Scope |
| --- | --- | --- |
| Appwrite plugin or MCP server | Your agent app's user-level config | All projects |
| Appwrite skills | Your user-level skills folder (for example \`~/.agents/skills\`) | All projects |
| Appwrite CLI | Global install (npm, Homebrew, or the install script) | All projects |
| SDK packages | The project's dependency manifest | This project |
| \`appwrite.config.json\` | Project root: project ID, endpoint, and the schema as config | This project, safe to commit |
| Env file | Project root: endpoint, project ID, and a server key only when server code needs one | This project, gitignored |
| Project resources | Your Appwrite project: platforms, tables, buckets, functions, sites, and a server API key when server code needs one | Your Appwrite account |
| Test data | A test user and a few rows, created to verify the build | Listed in the hand-off, so you can delete them |

### What the agent never does

- Print an API key or secret, commit one, or ask you to paste one into the chat.
- Replace a backend the project already uses, unless you ask for a migration.
- Deploy, delete resources, or create anything billable (such as a dedicated database) without asking first.
- Wait silently on a sign-in, or skip a step without telling you.

## How to work

- **Do the work yourself.** Run commands and edit config files directly. Only hand the user a step that truly needs them: a browser sign-in, a click in an app's UI, a restart, or a decision.
- **Expect approval prompts.** Installing a plugin, editing your app's MCP config, and \`npm install -g\` write outside the project, so your app usually asks the user to approve them. Request the approval with a one-line reason. Sandboxes can also block network access (Codex does by default), so \`npm\`, \`npx\`, and \`appwrite\` calls may need an approval too. If the user declines or your environment blocks the step, add it to the user's list. Never skip a step silently.
- **Never wait on a sign-in.** Sign-in commands such as \`appwrite login\` and \`codex mcp login\` block until the user approves in a browser. Start them in the background or add them to the user's list. Do not run them in the foreground.
- **Batch what the user must do.** Keep a running list of what only the user can do (the user's list) and finish everything else first. The list, your questions, and the proposal go out together in one checkpoint at the end of step 5. Never ask what the repo already answers.
- **Check before changing.** Skip anything already set up. Read existing config before editing it, merge instead of replacing, and keep unrelated entries.
- **Use OAuth, not API keys.** Appwrite MCP and the CLI sign in through the browser. Only server code that acts as the app, not as the signed-in user, needs a project API key (step 6).
- **Do not make the build wait on MCP.** MCP tools often load only after a reload or a new session. The CLI and the SDKs cover everything the build needs. Use MCP for inspection, docs search, and one-off changes once it is loaded.
- **Keep this conversation alive.** A reload that keeps the session (such as \`/reload-plugins\` in Claude Code) can go in the checkpoint. A restart or a new session (such as a new Codex session to load the plugin) goes on the hand-off list instead, because it stops this session and any background sign-in. The resume command (\`claude --continue\`, \`codex resume --last\`) brings the conversation back.
- **Respect what exists.** If the project already has a backend (Firebase, Supabase, its own API), add Appwrite only where the user wants it. Each Appwrite service works on its own: auth only, storage only, or a single database is fine.

### Write for a senior developer

Every message to the user reads like an engineer briefing a peer:

- Lead with the result, then the detail. Use real names: commands, file paths, IDs, versions, URLs.
- Recommend instead of listing options. Every question carries your default, so "go" is always a valid answer.
- State limits and costs plainly (for example, dedicated PostgreSQL is a paid compute tier). Never overstate what works.
- No hype words ("seamless", "powerful", "supercharge"), no emoji, no exclamation marks.

## 1. Check what is already set up

Check each item and skip its step when it is done:

- **MCP**: if you can call \`appwrite_get_context\` (or any other \`appwrite_*\` tool), MCP is connected. In step 3, skip the plugin and server setup, but still install the skills if they are missing. If the tools are not callable but Appwrite is already configured (for example in \`claude mcp list\`, \`codex mcp list\`, or your app's MCP config file), do not install it again: only the sign-in or a reload goes on the user's list.
- **CLI**: run \`appwrite --version\`. If it prints a version, step 4 only needs the update command.
- **Skills**: if Appwrite skills are already available to you, skip the skills install. Skills installed with \`npx skills\` are named like \`appwrite-typescript\` and \`appwrite-cli\`; plugin installs name them like \`appwrite:typescript\` and \`appwrite:cli\`.
- **Project**: take the project ID and endpoint from the user prompt. Without them, look for \`appwrite.config.json\` in the repo: its \`projectId\` and \`endpoint\` are the project to use.
- **SDK**: check the manifests (\`package.json\`, \`pubspec.yaml\`, \`requirements.txt\`, \`pyproject.toml\`, \`go.mod\`, \`composer.json\`, \`Gemfile\`, \`Package.swift\`, \`*.csproj\`, \`Cargo.toml\`, Gradle files) for an Appwrite SDK, and look for an existing Appwrite client module.
- **The app**: read the README, the manifests, and the main routes or screens. Note the framework, the language, what the app does, and any backend it already uses. If the user already said what they want to build, that wins.

## 2. Identify your environment

You usually know which product you are running in. Use the matching section in step 3. If you are unsure, these signals help:

- \`CLAUDECODE=1\` in your environment, or you are a \`claude\` CLI session: **Claude Code**.
- You are inside the Claude desktop app chat, Cowork, or claude.ai, without the \`claude\` CLI: **Claude apps**.
- You are a \`codex\` CLI session, the Codex IDE extension, or Codex in the ChatGPT desktop app: **Codex**.
- You are Cursor's agent or the \`cursor-agent\` CLI: **Cursor**. You are GitHub Copilot or another agent inside VS Code: **VS Code**.
- You are Cascade or Devin Local in Devin Desktop (formerly Windsurf): **Devin Desktop and Windsurf**.
- Antigravity, Zed, OpenCode, Grok Build, and Pi have their own sections. Anything else: **Other agents**.

If you still cannot tell, ask the user one question: which app are they using?

Assume your shell runs on the user's machine. A local sandbox that limits writes or network (Codex, Cursor, and Claude Code all have one) is still the user's machine. Only treat the shell as remote when you are a cloud agent (for example Codex cloud, Cursor background agents, Claude Code on the web, or the GitHub Copilot coding agent) or the user tells you so. Then follow "If your shell is not on the user's machine" near the end of this page.

With no shell at all (a chat app where MCP is your only tool), the MCP setup in step 3 goes on the user's list and the CLI is optional. Build through MCP instead: create the project's resources with the write tools, and hand over the code as files.

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

For the user's list: open ${CODEX_PLUGIN_CONNECT_URL} and connect Appwrite (a browser window opens for the Appwrite sign-in), then start a new Codex session at the hand-off (\`codex resume --last\` continues this conversation). Without a shell (for example in the ChatGPT desktop app), the user installs the plugin there instead: **Plugins** in the sidebar, search \`Appwrite\`, **Install plugin**.

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

### Pi

Pi connects to MCP servers through the \`pi-mcp-adapter\` extension. Install it and the skills:

\`\`\`sh
pi install npm:pi-mcp-adapter
${skillsInstall('pi')}
\`\`\`

Then merge this into \`~/.pi/agent/mcp-adapter.json\`:

\`\`\`json
${PI_JSON}
\`\`\`

For the user's list: restart Pi, run \`/mcp-auth ${MCP_SERVER_NAME}\`, and approve the sign-in in the browser.

### Other agents

Add a remote HTTP server named \`${MCP_SERVER_NAME}\` with URL \`${MCP_SERVER_URL}\` to your app's user-level MCP configuration, and install the skills with \`npx --yes skills add appwrite/skills -g -s '*' -y\` (add \`-a <agent>\` when the skills CLI knows your app). Clients that only support stdio can run the server through \`npx mcp-remote ${MCP_SERVER_URL}\`.

Per-agent guides for people: ${AI_AGENTS_DOCS}

## 4. Install the Appwrite CLI and start the sign-in

The CLI links the project, pushes the schema, and deploys. MCP does not replace it. Install or upgrade to the latest published version. Prefer npm:

\`\`\`sh
npm install -g appwrite-cli@latest
appwrite --version
\`\`\`

If npm is unavailable, or a global npm install fails with a permissions error:

- macOS with Homebrew: \`brew install appwrite\` or \`brew upgrade appwrite\`
- Linux and macOS: \`curl -sL ${CLI_INSTALL_SH} | bash\`
- Windows PowerShell: \`iwr -useb ${CLI_INSTALL_PS1} | iex\`

If \`appwrite\` is already installed, run \`appwrite update\` instead. It upgrades through whichever method installed the CLI, so the user does not end up with two copies or an old major. Do not pin an older version.

If the user prompt has a project ID and an endpoint, point the CLI at that project now. In an empty repo, wait until you scaffold the app (step 6), because some generators refuse a folder that already has files in it:

\`\`\`sh
appwrite client --endpoint="<endpoint>" --project-id="<projectId>"
\`\`\`

This saves the endpoint to the CLI's global settings and creates \`appwrite.config.json\` with the project ID in the current folder. That file is expected: leave it as the CLI wrote it.

Start the sign-in last, right before you send the checkpoint (step 5), so its code is still fresh. Run it in the background. \`appwrite login\` prints a URL with a code and waits until the user approves it in a browser:

\`\`\`sh
appwrite login > "\${TMPDIR:-/tmp}/appwrite-login.log" 2>&1 &
\`\`\`

After a few seconds, read the URL from the log and put it in the checkpoint. The CLI finishes the sign-in on its own once the user approves. If the log has no URL, or your environment stops background processes when a command ends (Codex does), put \`appwrite login\` itself on the user's list instead. A user without an Appwrite account creates one on the same sign-in page, and the Free plan is enough to start.

## 5. Propose the build and check in

Decide what to build before you install SDKs or create resources. The tools follow the product.

### Decide what you can

From step 1 and the user's words, work out:

- **What it is**: one sentence, for example "a shared grocery list with photo attachments".
- **Platforms**: web, iOS, Android, desktop, or an API only.
- **Stack**: keep the repo's stack. For an empty repo, recommend one and give the reason in a few words: Next.js with TypeScript for a web app, Flutter for one codebase across iOS and Android, React Native when the team already writes TypeScript, and Appwrite Functions (or the user's server framework) for an API only.
- **Appwrite services**: map each feature with the table below.
- **Existing auth**: if the app keeps another auth provider (Firebase Auth, Auth0, its own), bridge it with custom tokens instead of replacing it. Server code verifies the provider's token and finds or creates the matching Appwrite user with an ID derived from the provider's user ID, so the mapping stays stable across sign-ins. Only then does it call \`users.createToken()\`, which needs an existing user, and the client exchanges the secret with \`account.createSession()\`. Mention migrating the users as the alternative, not the default.
- **First slice**: the smallest end-to-end flow that proves the stack, usually sign-in plus create, list, and update on one screen. Add uploads or realtime only when they are the point of the app.
- **Ship**: whether to deploy at the end (step 9). Default to local only, unless the user asked for a live URL.

| The app needs | Appwrite | Set it up with |
| --- | --- | --- |
| Sign-up, sign-in, sessions | Auth | \`Account\` in the client SDK. Email and password, magic URL, OTP, and anonymous sessions are on by default |
| Roles, organizations, sharing | Teams and permissions | \`Teams\` in the SDK, \`$permissions\` and \`rowSecurity\` in the table config |
| App data with access rules | TablesDB | \`tables\` in \`appwrite.config.json\`, then \`appwrite push tables\`. \`TablesDB\` in the SDK |
| Schemaless JSON documents | DocumentsDB | MCP or the Console |
| Semantic search, RAG, recommendations | VectorsDB, with built-in embeddings | MCP or the Console |
| Raw SQL, an existing ORM, migrations you own | Dedicated PostgreSQL or MySQL (a paid compute tier) | A connection string for the ORM or psql |
| Uploads, images, media | Storage (image transformations need a paid plan) | \`buckets\` in \`appwrite.config.json\`, then \`appwrite push buckets\`. \`Storage\` in the SDK |
| Live updates, presence | Realtime | \`Realtime\` in the client SDK |
| Server logic, webhooks, cron, background jobs | Functions | \`appwrite init functions\`, \`appwrite run functions\`, \`appwrite push functions\` |
| Hosting | Sites | \`appwrite init sites\`, \`appwrite push sites\` |
| Email, SMS, push notifications | Messaging | A provider in the Console or MCP, \`topics\` in \`appwrite.config.json\` |
| Custom domain, rate limits | Domains and Firewall | The Console or MCP |

### Ask only what changes the build

Ask at most four questions, each with your recommended default, and only about what you could not infer. Typical ones:

- What the app is, when nothing says (default: a starter with sign-in and one list, to rename later).
- The platforms and, for an empty repo, the stack.
- Sign-in methods beyond email and password. OAuth providers need a client ID and secret from the provider's developer console, which is a user step, so default to email and password for the first slice.
- Without a project: create one or link an existing one. Default to a new project named after the app, in the region closest to its users (\`fra\`, \`nyc\`, \`sfo\`, \`sgp\`, \`syd\`, or \`tor\`). Mention that a Free organization holds two projects, so the user can name one to link instead.

If the user only wants the setup, still send the checkpoint, without **Proposed build**. When they reply, do step 6 (skip the scaffold), step 7 if the repo has code, then step 10.

### Send the checkpoint

End your turn with one message in this shape. Leave out the rows and sections that do not apply:

\`\`\`markdown
**Setup**
| Tool | Status |
| --- | --- |
| Appwrite MCP | Installed, sign-in pending |
| Appwrite skills | Installed |
| Appwrite CLI | <version>, sign-in pending |
| Project | <name> (<projectId>, <region>), or: created after you sign in |

**Your turn**
1. Approve the CLI sign-in: <url> (or run appwrite login in your terminal)
2. <the MCP sign-in step for this agent, from step 3, without restarts>

**Proposed build**
- App: <one sentence>
- Stack: <framework, language, packages>
- Appwrite: <services and the resources to create, for example: Auth (email and password), TablesDB database main with tables lists and items, Storage bucket photos>
- First slice: <what works when the build is done>
- Ship: <deploy to Appwrite Sites at the end, or local only>

**Questions** (defaults in bold)
1. <question>? **<default>**

Approve the sign-ins, then reply "go", or change anything above.
Reply "setup only" if you would rather build it yourself.
\`\`\`

If the repo already has code and settles the stack, you can install the SDKs (step 7) before you send the checkpoint.

## 6. Link or create the project

Continue here when the user replies. Run \`appwrite whoami\` to confirm the CLI sign-in. If it is not signed in yet, start \`appwrite login\` in the background again and send the user the new URL.

### Scaffold (empty repo only)

Check the toolchain (for example \`node -v\` or \`flutter --version\`), then scaffold the stack from the proposal with its official generator, before anything writes \`appwrite.config.json\` or an env file. Run the generator non-interactively, with its flags or \`--yes\`, so it never waits on a prompt.

### Project

- **Known project** (from the user prompt or \`appwrite.config.json\`): use it. Run the \`appwrite client\` command from step 4 if you have not yet.
- **No project yet**: \`appwrite init project\` links an existing project or creates a new one, and writes its ID and endpoint to \`appwrite.config.json\`. It asks questions, so pass \`--organization-id\`, \`--project-id\`, and \`--project-name\` when you know the answers (\`appwrite list-organizations\` and \`appwrite list-projects\` list the options). To create a project in a chosen region without prompts, run \`appwrite organization create-project --organization-id "<organizationId>" --project-id "<projectId>" --name "<App>" --region "<region>"\` (project IDs take lowercase letters and hyphens), then the \`appwrite client\` command from step 4 with \`https://<region>.cloud.appwrite.io/v1\`. If the create fails on the plan's project limit, list the user's projects and ask which one to link. With MCP loaded, \`appwrite_get_context\` lists the same organizations and projects.

API calls go to the project's regional endpoint, \`https://<region>.cloud.appwrite.io/v1\`, from \`appwrite.config.json\`. \`appwrite whoami\` can show \`https://cloud.appwrite.io/v1\` for the account sign-in. That is expected: do not change it.

### Platforms

Client SDKs only accept requests from platforms registered on the project. A missing platform fails with \`general_unknown_origin\`. Check \`appwrite project list-platforms\`, then add what is missing:

- **Web**: \`localhost\` for development. It also covers \`127.0.0.1\`, \`[::1]\`, and every port. Add the production domain when you deploy (step 9).

  \`\`\`sh
  appwrite project create-web-platform --platform-id "web-local" --name "<App> local" --hostname "localhost"
  \`\`\`

- **Mobile and desktop**: \`appwrite project create-android-platform\`, \`create-apple-platform\`, \`create-windows-platform\`, or \`create-linux-platform\`, with the app's package name or bundle ID. Run the command with \`--help\` for its flags.

### Env file and keys

- Put the endpoint and project ID in the app's env file under the framework's public prefix (for example \`NEXT_PUBLIC_APPWRITE_ENDPOINT\` or \`VITE_APPWRITE_PROJECT_ID\`). Check that the env file is gitignored. Flutter and native apps have no env file: use a config constants file or build-time defines (\`--dart-define\`). The endpoint and project ID are not secrets.
- Client SDKs need only the endpoint and project ID. Do not invent an API key, and never put one in client code.
- Server code that acts as the signed-in user uses a session client with the session secret from the cookie, and needs no key. A project API key is only for code that acts as the app: SSR sign-in handlers that create sessions (\`sessions.write\`), admin operations, workers, and scripts. Give it only the scopes that code uses. With MCP loaded, create one with the \`project_create_key\` tool (search for "create project API key") and write it straight into the env file. Without MCP, do not stop the build for it: mint a key that lasts an hour with \`appwrite project create-ephemeral-key\` (\`--scopes\` for each scope, \`--duration 3600\`, \`--json --show-secrets\`), pipe its \`secret\` into the env file so it never shows in your output, and put a long-lived key on the hand-off list (MCP, or the Console under the project's **API keys**). Never echo the key in chat or commit it.
- Server-rendered Sites and Functions get a short-lived key on every request, in the \`x-appwrite-key\` header, carrying the scopes set on the site or function. In production, prefer it to a stored key.

## 7. Install the matching official SDKs

Install what the proposal needs: a client SDK for each client app, plus a server SDK for SSR, API routes, workers, or Functions. Use the project's package manager (switch the npm commands to pnpm, yarn, or bun when the repo uses them), install the latest published version, and leave unrelated dependencies alone. Skip anything already at latest. Do not pin an old major.

| Stack | Package | Install |
| --- | --- | --- |
| Browser apps without React: Vue, Svelte, Angular, plain JavaScript | \`appwrite\` | \`npm install appwrite@latest\` |
| React, Next.js, TanStack Start | \`@appwrite.io/react\`: a provider and auth hooks over \`appwrite\` | \`npm install @appwrite.io/react@latest appwrite@latest @tanstack/react-query\`, plus \`node-appwrite\` for SSR |
| Node.js or Bun: SSR, API routes, workers, Functions | \`node-appwrite\` | \`npm install node-appwrite@latest\` |
| Deno | \`jsr:@appwrite/sdk\` (or \`npm:node-appwrite\` if the project already uses npm specifiers) | \`deno add jsr:@appwrite/sdk\` |
| React Native | \`react-native-appwrite\` | \`npm install react-native-appwrite@latest\` |
| Flutter | \`appwrite\` on pub.dev | \`flutter pub add appwrite\` |
| Dart server | \`dart_appwrite\` on pub.dev | \`dart pub add dart_appwrite\` |
| Python | \`appwrite\` | \`pip install -U appwrite\` |
| PHP | \`appwrite/appwrite\` | \`composer require appwrite/appwrite\` |
| Ruby | \`appwrite\` | \`bundle add appwrite\` (or \`gem install appwrite\`) |
| .NET | \`Appwrite\` | \`dotnet add package Appwrite\` |
| Go | \`github.com/appwrite/sdk-for-go\` | \`go get github.com/appwrite/sdk-for-go@latest\` |
| Rust | \`appwrite\` on crates.io | \`cargo add appwrite\` |
| Native Android | \`io.appwrite:sdk-for-android\` | The latest version from Maven Central |
| Native Apple: iOS, macOS, watchOS, tvOS | \`https://github.com/appwrite/sdk-for-apple\` | Swift Package Manager, latest tag |
| Kotlin or Java server | \`io.appwrite:sdk-for-kotlin\` | The latest version from Maven Central |
| Swift server | \`https://github.com/appwrite/sdk-for-swift\` | Swift Package Manager, latest tag |

Follow the matching skill from step 3 (for example \`appwrite-typescript\`, \`appwrite-python\`, \`appwrite-dart\`). If the skills were installed in this session and are not loaded yet, read the skill file from disk: \`~/.agents/skills/appwrite-typescript/SKILL.md\` for \`npx skills\` installs, or the plugin's \`skills\` folder (for Claude Code, under \`~/.claude/plugins\`).

Then add a small client module in the project's usual lib location (for example \`src/lib/appwrite.ts\`) that reads the endpoint and project ID from the env file. Keep server clients in a server-only module. Do not overwrite an existing Appwrite client that is already correct.

## 8. Build the first working slice

Build the slice from the proposal, in the style the repo already uses. One flow that works end to end beats a broad scaffold that does not.

### Schema as config

Define databases, tables, and buckets in \`appwrite.config.json\` and push them, so the schema is reviewable in a pull request and repeatable in CI. Use MCP or the Console only for what the config does not cover.

- **Tables**: \`tablesDB\` and \`tables\` entries with \`columns\`, \`indexes\`, \`$permissions\`, and \`rowSecurity\`. Use explicit column types (\`varchar\`, \`text\`, \`integer\`, \`float\`, \`boolean\`, \`datetime\`, \`email\`, \`url\`, \`enum\`, \`relationship\`). The legacy \`string\` type is deprecated. The \`appwrite-cli\` skill has the full format.
- **Permissions**: least privilege. For data each user owns, grant \`create("users")\` on the table, turn on \`rowSecurity\`, and give each row read, update, and delete for \`user:<userId>\`. Never grant write access to \`any\`.
- **Buckets**: \`buckets\` entries with the allowed file extensions and a maximum file size.
- **Sign-in methods**: email and password, magic URL, email and phone OTP, and anonymous sessions are on by default, and passkeys are off. Turn one on or off with \`appwrite project update-auth-method --method-id <method> --enabled <true|false>\`. An OAuth provider needs its client ID and secret: set them with \`appwrite project update-o-auth-2-<provider>\` (for example \`update-o-auth-2-google\`), or have the user do it in the Console.

\`--force\` confirms destructive changes for you, so use it only on a project you created in this session. There, push without prompts:

\`\`\`sh
appwrite push tables --all --force
appwrite push buckets --all --force
\`\`\`

Treat every other project as holding data, even one with no tables yet: it can still have buckets and files. There, run \`appwrite pull tables\` and \`appwrite pull buckets\` first, merge your changes into the pulled config, and push each with \`--all\` but without \`--force\`. If the CLI stops at a destructive change, show the user what it reported and ask before you run it again with \`--force\`.

For TypeScript, generate typed helpers from the schema with \`appwrite generate\`. For other languages, \`appwrite types <output-directory>\` writes the model types. Run it again after each schema change.

### Code

- Auth first: sign up, sign in, sign out, and the current user, with the session handled the way the framework expects: \`@appwrite.io/react\` or \`node-appwrite\` with cookies for SSR, the client SDK otherwise.
- Then the core flow on the main table, then uploads or realtime when the slice includes them.
- Use \`ID.unique()\` for new IDs, \`Query\` for filters, and \`Permission\` with \`Role\` for row permissions. The skill for the language has the exact calls.

### Run and verify

1. Start the app's dev server (or, for mobile, run it on a simulator or emulator) and run the slice yourself: create a test user, sign in, create a row, and read it back. Use generated test values with an easy-to-find prefix, never the user's real password, and list what you created in the hand-off. If background processes die when a command ends, start the server, run the checks, and stop it within one command.
2. Confirm the data landed with \`appwrite tablesdb list-rows --database-id "<databaseId>" --table-id "<tableId>" --json\`, or with the MCP \`appwrite_show\` tool and \`view: "table"\`, which lets the user browse and edit the rows in the chat.
3. Fix what fails before you hand off:

| Error | Usual cause | Fix |
| --- | --- | --- |
| \`general_unknown_origin\` | The app's hostname is not a registered platform | Add a Web platform for that hostname (step 6) |
| \`user_unauthorized\` | No session, or the table and row permissions do not grant access | Sign in first, then check \`$permissions\` and \`rowSecurity\` |
| \`general_unauthorized_scope\` | The API key or the guest role lacks a scope | Add the scope to the key, or sign the user in |
| \`project_not_found\` | A wrong project ID, or an endpoint in another region | Use the \`projectId\` and \`endpoint\` from \`appwrite.config.json\` |
| \`row_invalid_structure\` | The row data does not match the columns | Match the column keys and types, or change the schema and push it |
| \`user_session_already_exists\` | Signing in while a session is active | Read the current user first, or sign out before signing in |

## 9. Ship it

Only when the user said yes at the checkpoint, or asks now.

- **Web app**: run \`appwrite init sites\` and pick the framework, or add the site to \`appwrite.config.json\` yourself (the fields are in the \`appwrite-cli\` skill). Then run \`appwrite push sites\`, and add the site's domain as a Web platform. For SSR, grant the site the scopes its server code needs: each request then carries a key in \`x-appwrite-key\`.
- **Server logic**: \`appwrite init functions\` scaffolds a function, \`appwrite run functions\` runs it locally, and \`appwrite push functions\` deploys it. Set its \`scopes\` and its triggers (\`events\`, \`schedule\`) in the function's config.
- **Variables**: put them in a \`.env\` file in the site or function folder and push with \`--with-variables\`. Do not put them in \`appwrite.config.json\`.
- **Follow the build**: \`appwrite push\` streams the build logs. With MCP loaded, the \`appwrite_show\` tool with \`view: "deployment"\` shows the status and logs live.
- **Git deploys**: to deploy on every push, with a preview per branch and commit, the user connects the repository to the site in the Console.

## 10. Hand off

If Appwrite MCP tools are available in this session, verify the connection: call \`appwrite_get_context\`, then list the databases in the project. MCP servers usually load only after a reload or a new session. If the tools are not there yet, do not stall: everything else should already be done.

End with one message, in this order. Leave out the sections that are empty:

1. **Built**: one or two sentences on what works now and where to see it. For example: "Sign-up, sign-in, and shared lists backed by TablesDB, running at \`http://localhost:3000\`."
2. **Setup**: the checkpoint's tool table with the final status, plus the project ID and region.
3. **Your turn**: a short numbered list of every remaining action, in order (approvals the user declined, sign-ins, a long-lived API key to replace a one-hour key, a restart or new session with its resume command). Put the sign-ins together so they can approve them in one go.
4. **In the repo**: three to six bullets with paths: the client module, \`appwrite.config.json\`, the env file, the new routes or screens, the commands to run the app and to push schema changes, and the test data you created.
5. **Next with Appwrite**: three to five capabilities picked for this app from the reference below. One line each: what it unlocks here, and what it takes (an SDK call, a config entry and \`appwrite push\`, a Console toggle). Never a generic feature list.
6. **Next step**: the one thing you recommend building next, as a yes or no question.

Calibrate **Next with Appwrite** like this:

\`\`\`text
Weak:   Appwrite has powerful realtime features that will take your app to the next level!
Strong: Realtime: subscribe the list view to row events on items, and every open client updates over one WebSocket. No polling, about 20 lines in ListView.tsx.
Strong: Search: a full-text index on items.name plus Query.search() in the list request. One entry in appwrite.config.json and appwrite push tables.
\`\`\`

If the user chose setup only, keep **Next with Appwrite** to three lines about what the repo does, and leave out **Next step**.

If a sign-in or listing fails, say so plainly and point the user at the Appwrite MCP sign-in or \`appwrite login\`, not at creating an API key.

## If your shell is not on the user's machine

In a cloud sandbox or remote container, still make the code changes in the repo (the scaffold in step 6, then steps 7 and 8). For the CLI, give the user one command to run on their own machine instead of installing it where they cannot use it:

\`\`\`sh
npm install -g appwrite-cli@latest && appwrite client --endpoint="<endpoint>" --project-id="<projectId>" && appwrite login
\`\`\`

Fill in the endpoint and project ID from the user prompt. If the prompt had no project, replace the \`appwrite client\` part with \`appwrite init project\` after the sign-in. Schema pushes and deploys need the signed-in CLI, so list those commands for the user too, or run them through MCP when it is connected.

## Appwrite reference for the hand-off

Draw on this in step 10. Pick what fits the app, and do not paste the list.

- **Scope**: Auth, five database engines, Storage, Functions, Sites, Realtime, and Messaging in one project, behind one SDK, one CLI, and one MCP server. Use one service or all of them.
- **Protocols**: an S3-compatible API for Storage, OAuth 2.1 and OpenID Connect for Auth, and plain PostgreSQL and MySQL for the dedicated engines, so existing drivers, ORMs, and tools work unchanged.
- **Auth**: 50 OAuth providers, native ID-token sign-in, MFA, passkeys, breached-password checks against Have I Been Pwned, teams and roles, and user import with Argon2, bcrypt, scrypt, PHPass, MD5, or SHA hashes.
- **Databases**: TablesDB (relational, typed, transactions, relationships, geo queries, row-level permissions), DocumentsDB (schemaless JSON with full-text search), VectorsDB (HNSW indexes with built-in embeddings for RAG and semantic search), and managed PostgreSQL 18 and MySQL 8.4 on dedicated compute, with backups, point-in-time recovery, branches, read replicas, and high availability.
- **Compute and hosting**: Functions in 14 runtimes (Node.js, Bun, Deno, Python, Go, Rust, PHP, Ruby, Dart, Java, Kotlin, Swift, .NET, C++), triggered by HTTP, cron, or platform events. Sites for static, SSR, and CSR apps on Next.js, Nuxt, SvelteKit, Astro, Remix, TanStack Start, Angular, Vue, React, Vite, Flutter web, and more, with Git deploys from GitHub, GitLab, or Bitbucket.
- **Realtime and messaging**: WebSocket subscriptions and presence. Email, SMS, and push through SendGrid, Mailgun, Resend, Amazon SES, SMTP, Twilio, Vonage, MSG91, Telesign, Textmagic, FCM, and APNs.
- **Network**: custom domains with automatic TLS, domain search and purchase, a global CDN, DDoS protection, and firewall rules that deny, rate limit, or redirect traffic.
- **Workflow**: schema, buckets, functions, and sites live in \`appwrite.config.json\`. \`appwrite push\` applies them and \`appwrite generate\` regenerates the typed helpers, so a change is reviewable in a pull request and repeatable in CI.
- **Operations**: six regions (Frankfurt, New York, San Francisco, Singapore, Sydney, Toronto), SOC 2 Type II, HIPAA BAAs that Pro organizations accept in the Console, and migrations from Firebase, Supabase, Nhost, another Appwrite project, or CSV and JSON files. Open source, with a self-hosted option.
- **Plans**: the Free plan covers a first project (two projects per organization, no image transformations). Pro lifts the project limit. Check the user's plan before you promise a capability that needs it.

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
