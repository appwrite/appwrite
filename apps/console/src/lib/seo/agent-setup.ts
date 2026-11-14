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
import {
  API_KEY_PREFILL_NAME_PARAM,
  API_KEY_PREFILL_SCOPES_PARAM,
} from '../api-keys/create-link.ts'

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

/** Opens the project's create-key drawer filled in; placeholders for the agent to replace. */
export const API_KEY_CREATE_LINK = `${AGENT_SETUP_ORIGIN}/projects/<projectId>/api-keys?${API_KEY_PREFILL_NAME_PARAM}=<App>%20server&${API_KEY_PREFILL_SCOPES_PARAM}=<scope>,<scope>`

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

This is Appwrite's setup guide for coding agents. Fetch it yourself and work through it end to end. It has ten numbered steps and ends with a Resources section. If your copy is missing any of them, or reads like a summary, download it with \`curl -fsSL ${AGENT_SETUP_URL}\` and read all of it.

**The goal:** the user ends with a working app on Appwrite, not just installed tools. Connect Appwrite MCP, install the skills, the CLI, and the SDKs the app needs, get the user signed in, propose a build, then build and deploy its first slice, and close with what Appwrite can do next for this app.

The user prompt may include a project ID, a project name, and an API endpoint. Use them when present. Otherwise you link or create a project in step 6.

## At a glance

For people reading along: what the agent does, and the little it needs from you.

| Step | The agent | You |
| --- | --- | --- |
| 1. Check | Reads the repo and what is installed | Nothing |
| 2. Environment | Works out which agent app and shell it runs in | Nothing |
| 3. MCP and skills | Installs the Appwrite plugin or MCP server, and the skills | Approve the install |
| 4. CLI | Installs the CLI and starts its sign-in | Approve the sign-in in your browser |
| 5. Proposal | Proposes the stack, services, and a first slice, then checks in | Reply "go", "setup only", or change the plan |
| 6. Project | Scaffolds an empty repo, links or creates the project, registers platforms, writes the env file | Nothing |
| 7. SDKs | Installs the SDKs and adds a client module | Nothing |
| 8. Build | Pushes the schema, then builds and tests the first slice | Nothing |
| 9. Ship | Deploys to Appwrite Sites, unless you chose local only | Nothing |
| 10. Hand off | Reports what works, the live URL, and what to build next | Authorize MCP when it suits you |

The agent messages you twice: at the checkpoint after step 5 (one browser approval and a "go"), and at the hand-off. Nothing in between waits on you.

### What changes on your machine

| What | Where | Scope |
| --- | --- | --- |
| Appwrite plugin or MCP server | Your agent app's user-level config | All projects |
| Appwrite skills | Your user-level skills folder, such as \`~/.agents/skills\` | All projects |
| Appwrite CLI | Global install | All projects |
| SDK packages | The project's manifest | This project |
| \`appwrite.config.json\` | Project root: project ID, endpoint, and schema | This project, commit it |
| Env file | Project root: endpoint, project ID, and a one-hour server key for local runs when server code needs one | This project, gitignored |
| Project resources | Your Appwrite project: platforms, tables, buckets, and the site | Your Appwrite account |
| Test data | A test user and rows, to verify the build | Deleted once the checks pass |

### What the agent never does

- Print, commit, or ask you to paste an API key or secret.
- Replace a backend the project already uses, unless you ask for a migration.
- Deploy, delete anything it did not create in this session, or create anything billable (such as a dedicated database) without asking first.
- Wait silently on a sign-in, or skip a step without telling you.

## How to work

- **Do the work yourself.** Run commands and edit config directly. Hand the user only what needs them: a browser sign-in, a click in an app, a restart, or a decision.
- **Expect approval prompts.** Plugin installs, MCP config edits, and \`npm install -g\` write outside the project, and sandboxes can block network access (Codex does by default), so your app may ask the user to approve them. Ask with a one-line reason. If the user declines or your environment blocks a step, add it to the user's list. Never skip a step silently.
- **Never wait on a sign-in.** \`appwrite login\`, \`codex mcp login\`, and similar commands block until the user approves in a browser. Run them in the background or put them on the user's list.
- **Ask only for what the build needs.** Keep a running list of what only the user can do (the user's list) and finish everything else first. The checkpoint at the end of step 5 carries the CLI sign-in, your questions, and the proposal. MCP sign-ins, reloads, and restarts go on the hand-off list, because the build does not use MCP. Never ask what the repo already answers.
- **Keep this conversation alive.** A restart or a new session (such as a new Codex session to load the plugin) stops this session and any background sign-in, so it goes on the hand-off list with its resume command (\`claude --continue\`, \`codex resume --last\`).
- **Check before changing.** Skip what is already set up. Read config before editing it, merge instead of replacing, and keep unrelated entries.
- **Use OAuth, not API keys.** MCP and the CLI sign in through the browser. Server keys never come from the user up front (step 6).
- **Respect what exists.** If the project already has a backend (Firebase, Supabase, its own API), add Appwrite only where the user wants it. Each Appwrite service works on its own.

### Write for a senior developer

- Lead with the result, then the detail. Use real names: commands, paths, IDs, versions, URLs.
- Recommend instead of listing options. Every question carries your default, so "go" is always a valid answer.
- State limits and costs plainly (for example, dedicated PostgreSQL is a paid compute tier). Never overstate what works.
- No hype words ("seamless", "powerful", "supercharge"), no emoji, no exclamation marks.

## 1. Check what is already set up

Skip each step that is already done:

- **MCP**: if you can call \`appwrite_get_context\` (or any other \`appwrite_*\` tool), MCP is connected. In step 3, skip the plugin and server setup, but still install the skills if they are missing. If the tools are not callable but Appwrite is already configured (\`claude mcp list\`, \`codex mcp list\`, or your app's MCP config file), only its sign-in goes on the user's list.
- **CLI**: run \`appwrite --version\`. If it prints a version, step 4 only needs \`appwrite update\`.
- **Skills**: if Appwrite skills are available (\`appwrite-typescript\` from \`npx skills\`, \`appwrite:typescript\` from a plugin), skip the skills install.
- **Project**: the prompt's project ID and endpoint, or else \`projectId\` and \`endpoint\` in \`appwrite.config.json\`.
- **The app**: read the README and the manifests. Note the framework, the language, what the app does, any Appwrite SDK or client module, and any backend it already uses. If the user said what to build, that wins.

## 2. Identify your environment

Use the section in step 3 for the product you run in. If you are unsure:

- \`CLAUDECODE=1\` or a \`claude\` CLI session: **Claude Code**. The Claude desktop chat, Cowork, or claude.ai without the CLI: **Claude apps**.
- A \`codex\` CLI session, the Codex IDE extension, or Codex in the ChatGPT desktop app: **Codex**.
- Cursor's agent or the \`cursor-agent\` CLI: **Cursor**. GitHub Copilot or another agent inside VS Code: **VS Code**.
- Cascade or Devin Local in Devin Desktop (formerly Windsurf): **Devin Desktop and Windsurf**.
- Antigravity, Zed, OpenCode, Grok Build, and Pi have their own sections. Anything else: **Other agents**.

If you still cannot tell, ask the user which app they are using.

Your shell runs on the user's machine, even inside a sandbox that limits writes or network (Codex, Cursor, and Claude Code all have one). Treat it as remote only when you are a cloud agent (Codex cloud, Cursor background agents, Claude Code on the web, the GitHub Copilot coding agent) or the user says so, and then follow "If your shell is not on the user's machine" near the end. With no shell at all (a chat app where MCP is your only tool), the MCP setup goes on the user's list, the CLI is optional, and you build through MCP's write tools and hand over the code as files.

## 3. Connect Appwrite MCP and install the skills

The hosted server is \`${MCP_SERVER_URL}\` and signs in with OAuth, so do not create an API key for it. Pick **one** section. Where a plugin exists, it bundles the server and the skills: install the plugin instead of adding the server by hand, or the server registers twice.

### Claude Code

Install the plugin (server, skills, and deploy commands). Add the official marketplace first; adding it again is harmless:

\`\`\`sh
claude plugin marketplace add ${CLAUDE_OFFICIAL_MARKETPLACE_REPO}
claude plugin install ${CLAUDE_PLUGIN_OFFICIAL}
\`\`\`

If the plugin is still not found, install it from the Appwrite marketplace:

\`\`\`sh
claude plugin marketplace add ${CLAUDE_PLUGIN_MARKETPLACE_REPO}
claude plugin install appwrite@appwrite
\`\`\`

Only if neither installs, add the server with \`claude mcp add ${MCP_SERVER_NAME} --transport http ${MCP_SERVER_URL}\` and the skills with \`${skillsInstall('claude-code')}\`.

For the user's list: \`/reload-plugins\` (or restart with \`claude --continue\` to keep this conversation), then \`/mcp\`, select the Appwrite server, and choose **Authenticate**. On a remote host without a browser, the user runs \`claude mcp login ${MCP_SERVER_NAME} --no-browser\` and opens the printed URL on their own machine.

### Claude apps (desktop chat, Cowork, claude.ai)

You cannot install plugins here, so this goes on the user's list. The plugin is not in the default catalog:

1. In the sidebar, **Customize** > **Plugins** > **Add marketplace**, and enter \`${CLAUDE_PLUGIN_MARKETPLACE_REPO}\`.
2. Install the **appwrite** plugin.
3. In the plugin's **Connectors** tab, connect **appwrite** and sign in.

Without plugins, add a custom connector instead: **Settings** > **Connectors** > **Add custom connector**, name \`${MCP_SERVER_NAME}\`, URL \`${MCP_SERVER_URL}\`, then **Connect**.

### Codex

Install Appwrite's plugin from the OpenAI Curated catalog (connection and skills):

\`\`\`sh
codex plugin add ${CODEX_PLUGIN_ID}
\`\`\`

Codex keeps plugins in \`~/.codex\`, which its sandbox protects, so this needs an approval, and the plugin loads in the next session. For the user's list: connect Appwrite at ${CODEX_PLUGIN_CONNECT_URL}, then start a new Codex session at the hand-off (\`codex resume --last\` continues this conversation). Without a shell (the ChatGPT desktop app), the user installs it there: **Plugins**, search \`Appwrite\`, **Install plugin**.

If \`codex plugin add\` fails (plugins are off for the workspace, or Codex is signed in with an API key), add the server and the skills:

\`\`\`sh
${MCP_CODEX_INSTALL_COMMAND}
${skillsInstall('codex')}
\`\`\`

Then \`codex mcp login ${MCP_SERVER_NAME}\` and a new session go on the user's list. If \`codex plugin list\` shows \`appwrite@appwrite\`, it is an old plugin without the hosted server. Remove it:

\`\`\`sh
codex plugin remove appwrite@appwrite
codex plugin marketplace remove appwrite
\`\`\`

### Cursor

Merge this into \`~/.cursor/mcp.json\` (create it if missing, and keep other servers). Cursor asks the user to approve the edit:

\`\`\`json
${MCP_JSON}
\`\`\`

\`\`\`sh
${skillsInstall('cursor')}
\`\`\`

For the user's list: **Cursor Settings** > **MCP**, then **Needs login** next to appwrite. If you cannot write the file, give the user the one-click install link: ${CURSOR_MCP_INSTALL_URL}. The optional [Appwrite plugin for Cursor](${CURSOR_PLUGIN_PAGE_URL}) adds commands, not the server.

### VS Code

VS Code's file tools cannot write outside the workspace, so use the VS Code CLI, which merges the server into the user's MCP configuration:

\`\`\`sh
code --add-mcp '${VSCODE_ADD_MCP_JSON}'
${skillsInstall('github-copilot')}
\`\`\`

If \`code\` is not on the PATH, use \`"/Applications/Visual Studio Code.app/Contents/Resources/app/bin/code"\` on macOS, or \`code-insiders\` for Insiders. In PowerShell, escape the inner double quotes. For the user's list: trust and authenticate the server when VS Code starts it, or run **MCP: List Servers**, select **appwrite**, and choose **Start Server**.

### Devin Desktop and Windsurf

Devin Desktop is the new name for Windsurf. With the \`devin\` CLI:

\`\`\`sh
devin mcp add -s user ${MCP_SERVER_NAME} ${MCP_SERVER_URL}
\`\`\`

Otherwise merge \`{"mcpServers": {"${MCP_SERVER_NAME}": {"url": "${MCP_SERVER_URL}"}}}\` into \`~/.config/devin/mcp_config.json\` (\`%APPDATA%\\devin\\mcp_config.json\` on Windows), which both Devin Desktop agents read. An older Windsurf install uses \`~/.codeium/windsurf/mcp_config.json\` and needs the \`serverUrl\` key instead, because it ignores \`url\` without an error.

\`\`\`sh
${skillsInstall('windsurf')}
\`\`\`

For the user's list: \`devin mcp login ${MCP_SERVER_NAME}\`, or refresh the MCP list in the agent panel and sign in.

### Antigravity

Merge this into \`~/.gemini/config/mcp_config.json\` (older releases: \`~/.gemini/antigravity/mcp_config.json\`). Antigravity needs \`serverUrl\` and ignores \`url\`:

\`\`\`json
${SERVER_URL_MCP_JSON}
\`\`\`

\`\`\`sh
${skillsInstall('antigravity')}
\`\`\`

For the user's list: open the MCP manager (\`/mcp\` in Antigravity CLI), reload the servers, and sign in to appwrite.

### Zed

Merge this into \`~/.config/zed/settings.json\` (macOS and Linux) from the terminal, because Zed's file tools only edit the open project. Keep the file's comments and other settings:

\`\`\`json
${ZED_JSON}
\`\`\`

\`\`\`sh
${skillsInstall('zed')}
\`\`\`

Zed reloads the settings on its own and asks the user to sign in. Add that to the user's list.

### OpenCode

Merge this into the global \`~/.config/opencode/opencode.json\`, not the project's \`opencode.json\`:

\`\`\`json
${OPENCODE_JSON}
\`\`\`

\`\`\`sh
${skillsInstall('opencode')}
\`\`\`

For the user's list: \`opencode mcp auth ${MCP_SERVER_NAME}\`, then approve the sign-in in the browser.

### Grok Build

\`\`\`sh
grok mcp add -s user ${MCP_SERVER_NAME} ${MCP_SERVER_URL}
${skillsInstall('grok')}
\`\`\`

For the user's list: in a new Grok session, run \`/mcps\`, select **appwrite**, and press \`i\` to sign in.

### Pi

Pi reaches MCP servers through the \`pi-mcp-adapter\` extension:

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

## 4. Install the Appwrite CLI and start the sign-in

The CLI links the project, pushes the schema, and deploys. MCP does not replace it. Install the latest version, preferring npm:

\`\`\`sh
npm install -g appwrite-cli@latest
appwrite --version
\`\`\`

Without npm, or on a permissions error from a global install: \`brew install appwrite\` on macOS, \`curl -sL ${CLI_INSTALL_SH} | bash\` on Linux and macOS, or \`iwr -useb ${CLI_INSTALL_PS1} | iex\` in Windows PowerShell. If the CLI is already installed, run \`appwrite update\` instead: it upgrades through whichever method installed it, so the user never ends up with two copies. Do not pin an older version.

With a project ID and endpoint in the prompt, point the CLI at the project. In an empty repo, wait until you scaffold the app (step 6), because some generators refuse a folder that has files in it:

\`\`\`sh
appwrite client --endpoint="<endpoint>" --project-id="<projectId>"
\`\`\`

This saves the endpoint to the CLI's global settings and writes \`appwrite.config.json\` with the project ID. Leave that file as the CLI wrote it.

Start the sign-in last, right before you send the checkpoint, so its code is still fresh. \`appwrite login\` prints a URL and waits until the user approves it in a browser:

\`\`\`sh
appwrite login > "\${TMPDIR:-/tmp}/appwrite-login.log" 2>&1 &
\`\`\`

Put the URL from the log in the checkpoint. If the log has no URL, or your environment stops background processes when a command ends (Codex does), put \`appwrite login\` itself on the user's list. A new user creates an account on the same page, and the Free plan is enough to start.

## 5. Propose the build and check in

Decide what to build before you install SDKs or create resources. The tools follow the product.

### Decide what you can

From step 1 and the user's words:

- **App**: one sentence, for example "a shared grocery list with photo attachments".
- **Stack**: keep the repo's. For an empty repo, recommend TanStack Start with TypeScript for web, Flutter for iOS and Android from one codebase (React Native when the team writes TypeScript), and Appwrite Functions or the user's server framework for an API only.
- **Sessions**: keep the repo's approach. Web apps on a server-rendering framework sign users in on the server, so the session is an httpOnly cookie on the app's domain. The sign-in handlers need a key with \`sessions.write\`, which never comes from the user (step 6). Client-only apps (mobile, single-page apps) use client-side sessions and need no key.
- **Existing auth**: if the app keeps another provider (Firebase Auth, Auth0, its own), bridge it with custom tokens instead of replacing it. Server code verifies the provider's token and finds or creates the matching Appwrite user with an ID derived from the provider's user ID, so the mapping stays stable. Only then does it call \`users.createToken()\`, which needs an existing user, and the client exchanges the secret with \`account.createSession()\`. Mention migrating the users as the alternative.
- **First slice**: the smallest end-to-end flow, usually sign-in plus create, list, and update on one screen. Add uploads or realtime only when they are the point of the app.
- **Ship**: deploy web apps to Appwrite Sites (step 9), so the hand-off has a live URL and the deployed server code gets its key from Sites. The user's "go" approves it.

| The app needs | Appwrite | Set it up with |
| --- | --- | --- |
| Sign-up, sign-in | Auth | \`Account\` in the SDK. Email and password, magic URL, OTP, and anonymous sessions are on by default |
| Roles, sharing | Teams and permissions | \`Teams\` in the SDK, \`$permissions\` and \`rowSecurity\` in the table config |
| App data with access rules | TablesDB | \`tables\` in \`appwrite.config.json\`, then \`appwrite push tables\` |
| Schemaless JSON | DocumentsDB | MCP or the Console |
| Semantic search, RAG | VectorsDB, with built-in embeddings | MCP or the Console |
| Raw SQL, an existing ORM | Dedicated PostgreSQL or MySQL (a paid compute tier) | A connection string |
| Uploads, images | Storage (image transformations need a paid plan) | \`buckets\` in \`appwrite.config.json\`, then \`appwrite push buckets\` |
| Live updates, presence | Realtime | \`Realtime\` in the client SDK |
| Server logic, webhooks, cron | Functions | \`appwrite init functions\`, \`appwrite run functions\`, \`appwrite push functions\` |
| Hosting | Sites | Step 9 |
| Email, SMS, push | Messaging | A provider in the Console or MCP, \`topics\` in \`appwrite.config.json\` |
| Custom domain, rate limits | Domains and Firewall | The Console or MCP |

### Ask only what changes the build

Ask at most four questions, each with your default, and only about what you could not infer:

- What the app is, when nothing says (default: a starter with sign-in and one list, to rename later).
- The platforms and, for an empty repo, the stack.
- Sign-in methods beyond email and password. OAuth providers need a client ID and secret from the provider, which is a user step, so default to email and password.
- Without a project: create one (default: named after the app, in the region closest to its users: \`fra\`, \`nyc\`, \`sfo\`, \`sgp\`, \`syd\`, or \`tor\`) or link one. A Free organization holds two projects, so mention linking.

If the user only wants the setup, send the checkpoint without **Proposed build**. When they reply, do step 6 (no scaffold), step 7 if the repo has code, then step 10.

### Send the checkpoint

End your turn with one message in this shape, leaving out what does not apply:

\`\`\`markdown
**Setup**
| Tool | Status |
| --- | --- |
| Appwrite MCP | Installed, you sign in at the end |
| Appwrite skills | Installed |
| Appwrite CLI | <version>, sign-in pending |
| Project | <name> (<projectId>, <region>), or: created after you sign in |

**Your turn**
1. Approve the CLI sign-in: <url> (or run appwrite login in your terminal)

**Proposed build**
- App: <one sentence>
- Stack: <framework, language, packages>
- Appwrite: <services and resources, for example: Auth (email and password), TablesDB database main with tables lists and items>
- First slice: <what works when the build is done>
- Ship: <deploy to Appwrite Sites, or local only>

**Questions** (defaults in bold)
1. <question>? **<default>**

Approve the sign-in, then reply "go", or change anything above.
Reply "setup only" if you would rather build it yourself.
\`\`\`

If the repo already has code and settles the stack, you can install the SDKs (step 7) before you send it.

## 6. Link or create the project

Continue here when the user replies. Run \`appwrite whoami\` to confirm the CLI sign-in. If it is not done, start \`appwrite login\` in the background again and send the user the new URL.

### Scaffold (empty repo only)

Check the toolchain (for example \`node -v\` or \`flutter --version\`), then scaffold the stack with its official generator, before anything writes \`appwrite.config.json\` or an env file. Run the generator non-interactively, with its flags or \`--yes\`.

### Project

- **Known project** (from the prompt or \`appwrite.config.json\`): use it, and run the \`appwrite client\` command from step 4 if you have not yet.
- **No project yet**: \`appwrite init project\` links an existing project or creates one, and writes its ID and endpoint to \`appwrite.config.json\`. Pass \`--organization-id\`, \`--project-id\`, and \`--project-name\` so it does not prompt (\`appwrite list-organizations\` and \`appwrite list-projects\` list the options). To pick the region, create it with \`appwrite organization create-project --organization-id "<organizationId>" --project-id "<projectId>" --name "<App>" --region "<region>"\` (project IDs take lowercase letters and hyphens), then run the \`appwrite client\` command with \`https://<region>.cloud.appwrite.io/v1\`. If the plan's project limit blocks the create, list the user's projects and ask which one to link.

API calls use the project's regional endpoint from \`appwrite.config.json\`. \`appwrite whoami\` can show \`https://cloud.appwrite.io/v1\` for the account. That is expected.

### Platforms

Client SDKs only accept requests from registered platforms, and a missing one fails with \`general_unknown_origin\`. Check \`appwrite project list-platforms\`, then add what is missing:

\`\`\`sh
appwrite project create-web-platform --platform-id "web-local" --name "<App> local" --hostname "localhost"
\`\`\`

\`localhost\` also covers \`127.0.0.1\`, \`[::1]\`, and every port. Add the site's domain in step 9. For mobile and desktop, use \`appwrite project create-android-platform\`, \`create-apple-platform\`, \`create-windows-platform\`, or \`create-linux-platform\` with the package name or bundle ID (\`--help\` lists the flags).

### Env file and keys

- Put the endpoint and project ID in the env file under the framework's public prefix (for example \`VITE_APPWRITE_ENDPOINT\` and \`VITE_APPWRITE_PROJECT_ID\`), and check that the file is gitignored. Flutter and native apps use a constants file or \`--dart-define\`. Neither value is a secret.
- Client SDKs need only those two values. Do not invent an API key, and never put one in client code.
- Server code that acts as the signed-in user uses a session client with the session secret from the cookie, and needs no key. Code that acts as the app does: SSR sign-in handlers that create sessions (\`sessions.write\`), admin operations, workers, and scripts. Grant it only the scopes it uses, and never ask the user for it up front.
- **On Appwrite Sites**, every server-rendered request carries a short-lived key in the \`x-appwrite-key\` header, with the scopes granted to the site (step 9), and the build gets one as \`APPWRITE_SITE_API_KEY\`. Functions receive the header too.
- **Locally**, mint a one-hour key with \`appwrite project create-ephemeral-key --scopes <scope> --duration 3600 --json --show-secrets\` and pipe its \`secret\` into \`APPWRITE_API_KEY\` in the env file, so it never shows in your output. Add a script that does the same (for example \`appwrite:key\` in \`package.json\`), so the user refreshes it with one command.
- **A long-lived key** is only for code that runs outside Sites and Functions without the CLI, such as a CI job. Only then, and only on the hand-off list, give the user the Console link that opens the create form filled in: \`${API_KEY_CREATE_LINK}\`. Neither the MCP sign-in (it carries no \`keys.write\`) nor the CLI can create one, so do not try, and do not drive the Console in a browser. Never echo the key in chat or commit it.

Read the key per request, so the same code runs on Sites and locally. \`@appwrite.io/react\` takes a fixed \`apiKey\`, so build its handlers inside the route:

\`\`\`ts
// src/routes/api/appwrite/$.ts
import { createFileRoute } from "@tanstack/react-router";
import { createAppwriteHandlers } from "@appwrite.io/react/handlers/tanstack";

// Appwrite Sites sends a short-lived key with each request. Locally, the env file holds a CLI-minted one.
const handlers = (request: Request) =>
  createAppwriteHandlers({
    endpoint: import.meta.env.VITE_APPWRITE_ENDPOINT,
    projectId: import.meta.env.VITE_APPWRITE_PROJECT_ID,
    apiKey: request.headers.get("x-appwrite-key") ?? process.env.APPWRITE_API_KEY!,
    basePath: "/api/appwrite",
  });

export const Route = createFileRoute("/api/appwrite/$")({
  server: {
    handlers: {
      GET: ({ request }) => handlers(request).GET({ request }),
      POST: ({ request }) => handlers(request).POST({ request }),
    },
  },
});
\`\`\`

## 7. Install the matching official SDKs

Install a client SDK for each client app, plus a server SDK for SSR, workers, or Functions, at the latest version and with the project's package manager (switch the npm commands to pnpm, yarn, or bun to match the repo). Skip what is already at latest, do not pin an old major, and leave unrelated dependencies alone.

| Stack | Install |
| --- | --- |
| React on TanStack Start, Next.js, or Vite | \`npm install @appwrite.io/react@latest appwrite@latest @tanstack/react-query\`, plus \`node-appwrite@latest\` for SSR |
| Other browser apps: Vue, Svelte, Angular, plain JavaScript | \`npm install appwrite@latest\` |
| Node.js or Bun servers and Functions | \`npm install node-appwrite@latest\` |
| Deno | \`deno add jsr:@appwrite/sdk\` (or \`npm:node-appwrite\` if the project already uses npm specifiers) |
| React Native | \`npm install react-native-appwrite@latest\` |
| Flutter | \`flutter pub add appwrite\` |
| Dart server | \`dart pub add dart_appwrite\` |
| Python | \`pip install -U appwrite\` |
| PHP | \`composer require appwrite/appwrite\` |
| Ruby | \`bundle add appwrite\` (or \`gem install appwrite\`) |
| .NET | \`dotnet add package Appwrite\` |
| Go | \`go get github.com/appwrite/sdk-for-go@latest\` |
| Rust | \`cargo add appwrite\` |
| Native Android | The latest \`io.appwrite:sdk-for-android\` from Maven Central |
| Native Apple: iOS, macOS, watchOS, tvOS | The latest tag of \`https://github.com/appwrite/sdk-for-apple\`, through Swift Package Manager |
| Kotlin or Java server | The latest \`io.appwrite:sdk-for-kotlin\` from Maven Central |
| Swift server | The latest tag of \`https://github.com/appwrite/sdk-for-swift\`, through Swift Package Manager |

Follow the matching skill (for example \`appwrite-typescript\` or \`appwrite-python\`). If it is not loaded yet, read its \`SKILL.md\` from disk: under \`~/.agents/skills\` for \`npx skills\` installs, or in the plugin's \`skills\` folder (under \`~/.claude/plugins\` for Claude Code).

Then add a client module in the project's usual place (for example \`src/lib/appwrite.ts\`) that reads the endpoint and project ID from the env file. Keep server clients in a server-only module, and keep an existing Appwrite client that is already correct.

## 8. Build the first working slice

Build the slice in the repo's own style. One flow that works end to end beats a broad scaffold that does not.

### Schema as config

Define databases, tables, and buckets in \`appwrite.config.json\` and push them, so the schema is reviewable in a pull request and repeatable in CI. Use MCP or the Console only for what the config cannot express.

- **Tables**: \`tablesDB\` and \`tables\` entries with \`columns\`, \`indexes\`, \`$permissions\`, and \`rowSecurity\`. Use explicit column types (\`varchar\`, \`text\`, \`integer\`, \`float\`, \`boolean\`, \`datetime\`, \`email\`, \`url\`, \`enum\`, \`relationship\`). The legacy \`string\` type is deprecated. The \`appwrite-cli\` skill has the full format.
- **Permissions**: least privilege. For data each user owns, grant \`create("users")\` on the table, turn on \`rowSecurity\`, and give each row read, update, and delete for \`user:<userId>\`. Never grant write access to \`any\`.
- **Buckets**: \`buckets\` entries with the allowed file extensions and a maximum file size.
- **Sign-in methods**: email and password, magic URL, email and phone OTP, and anonymous sessions are on by default, and passkeys are off. Toggle one with \`appwrite project update-auth-method --method-id <method> --enabled <true|false>\`. An OAuth provider needs its client ID and secret: set them with \`appwrite project update-o-auth-2-<provider>\` (for example \`update-o-auth-2-google\`), or have the user do it in the Console.

\`--force\` confirms destructive changes for you. Without the user's approval of the specific change, use it only on a project you created in this session. There, push without prompts:

\`\`\`sh
appwrite push tables --all --force
appwrite push buckets --all --force
\`\`\`

Treat every other project as holding data, even one with no tables yet: it can still have buckets and files. There, run \`appwrite pull tables\` and \`appwrite pull buckets\` first, merge your changes into the pulled config, and push each with \`--all\` but without \`--force\`. If the CLI stops at a destructive change, show the user what it reported, and run it again with \`--force\` only after they approve that change.

For TypeScript, \`appwrite generate\` writes typed helpers from the schema. For other languages, \`appwrite types <output-directory>\` writes the model types. Run it again after each schema change.

### Code

Auth first (sign up, sign in, sign out, and the current user, with the sessions from step 5), then the core flow on the main table, then uploads or realtime when the slice has them. Use \`ID.unique()\` for new IDs, \`Query\` for filters, and \`Permission\` with \`Role\` for row permissions. The skill has the exact calls.

### Run and verify

1. Start the dev server (for mobile, a simulator or emulator) and run the slice yourself: sign up a test user, sign in, create a row, and read it back. Use generated values with an easy-to-find prefix, never the user's real password. If background processes die when a command ends, start the server, run the checks, and stop it within one command.
2. Confirm the data with \`appwrite tablesdb list-rows --database-id "<databaseId>" --table-id "<tableId>" --raw\` (\`--json\` leaves out \`$permissions\`, so it cannot show row security), or with the MCP \`appwrite_show\` tool and \`view: "table"\`.
3. Fix what fails:

| Error | Usual cause | Fix |
| --- | --- | --- |
| \`general_unknown_origin\` | The hostname is not a registered platform | Add a Web platform (step 6) |
| \`user_unauthorized\` | No session, or the permissions do not grant access | Sign in, then check \`$permissions\` and \`rowSecurity\` |
| \`general_unauthorized_scope\` | The key or the guest role lacks a scope | Add the scope, or sign the user in |
| \`project_not_found\` | A wrong project ID, or another region's endpoint | Use \`projectId\` and \`endpoint\` from \`appwrite.config.json\` |
| \`row_invalid_structure\` | The row data does not match the columns | Match the keys and types, or change the schema and push it |
| \`user_session_already_exists\` | Signing in during an active session | Read the current user first, or sign out |

4. Once the checks pass, delete the test user and rows you created (\`appwrite users delete --user-id "<userId>"\`, \`appwrite tablesdb delete-row --database-id "<databaseId>" --table-id "<tableId>" --row-id "<rowId>"\`). You created them in this session, so this needs no approval, and the user has nothing to clean up.

## 9. Ship it

Deploy unless the user chose local only.

- **Site**: create it once with the scopes its server code needs, so every request carries a key: \`appwrite sites create --site-id "<siteId>" --name "<App>" --framework <framework> --adapter ssr --build-runtime <runtime> --scopes sessions.write\`, plus the install, build, and output flags for the framework (\`appwrite sites list-frameworks\` lists the presets and runtimes). Do not add scopes later with \`appwrite sites update\`: it resets every setting you leave out.
- **Variables**: set the public ones with \`appwrite sites create-variable --site-id "<siteId>" --variable-id "unique()" --key VITE_APPWRITE_ENDPOINT --value "<endpoint>"\`, and the same for the project ID. Never set \`APPWRITE_API_KEY\` on the site: Sites provides the key.
- **Deploy**: add the site to \`appwrite.config.json\` (\`appwrite pull sites\`, with its \`path\` pointing at the app) and run \`appwrite push sites\`, which streams the build logs. With MCP loaded, the \`appwrite_show\` tool with \`view: "deployment"\` follows it too.
- **Check it live**: add the site's domain as a Web platform, then run the slice once more on the live URL.
- **Functions**: \`appwrite init functions\` scaffolds one, \`appwrite run functions\` runs it locally, and \`appwrite push functions\` deploys it. Set its \`scopes\` and triggers (\`events\`, \`schedule\`) in its config; its key arrives in \`x-appwrite-key\`.
- **Git deploys**: to deploy on every push, with a preview per branch and commit, the user connects the repository to the site in the Console.

## 10. Hand off

If Appwrite MCP tools are available, verify them: call \`appwrite_get_context\`, then list the project's databases. If they are not loaded yet, do not stall.

End with one message, in this order, leaving out empty sections:

1. **Built**: what works now and where: the live URL, and the command to run it locally. For example: "Sign-up, sign-in, and shared lists on TablesDB, live at <url>."
2. **Setup**: the checkpoint's tool table with the final status, plus the project ID and region.
3. **Your turn**: every remaining action, in order (the MCP sign-in for this agent from step 3, approvals the user declined, a restart or new session with its resume command). None of them blocks what was built.
4. **In the repo**: three to six bullets with paths: the client module, \`appwrite.config.json\`, the env file and the command that refreshes the local key, the new routes or screens, and the commands to run the app and push schema changes. Say in a few words that the test data is deleted.
5. **Next with Appwrite**: three to five capabilities picked for this app from the reference below. One line each: what it unlocks here, and what it takes (an SDK call, a config entry and \`appwrite push\`, a Console toggle). Never a generic feature list.
6. **Next step**: the one thing you recommend building next, as a yes or no question.

Calibrate **Next with Appwrite** like this:

\`\`\`text
Weak:   Appwrite has powerful realtime features that will take your app to the next level!
Strong: Realtime: subscribe the list view to row events on items, so every open client updates over one WebSocket. No polling, about 20 lines in ListView.tsx.
\`\`\`

If the user chose setup only, keep **Next with Appwrite** to three lines about what the repo does, and leave out **Next step**. If a sign-in or listing fails, say so plainly and point at the MCP sign-in or \`appwrite login\`, not at an API key.

## If your shell is not on the user's machine

In a cloud sandbox or remote container, still make the code changes (the scaffold in step 6, then steps 7 and 8). Give the user one command to run on their own machine instead of installing the CLI where they cannot use it:

\`\`\`sh
npm install -g appwrite-cli@latest && appwrite client --endpoint="<endpoint>" --project-id="<projectId>" && appwrite login
\`\`\`

Without a project in the prompt, replace the \`appwrite client\` part with \`appwrite init project\` after the sign-in. Schema pushes and deploys need the signed-in CLI, so list those commands for the user too, or run them through MCP when it is connected.

## Appwrite reference for the hand-off

Draw on this in step 10. Pick what fits the app, and do not paste the list.

- **Scope**: Auth, five database engines, Storage, Functions, Sites, Realtime, and Messaging in one project, behind one SDK, one CLI, and one MCP server. Use one service or all of them.
- **Protocols**: an S3-compatible API for Storage, OAuth 2.1 and OpenID Connect for Auth, and plain PostgreSQL and MySQL for the dedicated engines, so existing drivers, ORMs, and tools work unchanged.
- **Auth**: 50 OAuth providers, native ID-token sign-in, MFA, passkeys, breached-password checks against Have I Been Pwned, teams and roles, and user import with Argon2, bcrypt, scrypt, PHPass, MD5, or SHA hashes.
- **Databases**: TablesDB (relational, typed, transactions, relationships, geo queries, row-level permissions), DocumentsDB (schemaless JSON with full-text search), VectorsDB (HNSW indexes with built-in embeddings), and managed PostgreSQL 18 and MySQL 8.4 on dedicated compute, with backups, point-in-time recovery, branches, read replicas, and high availability.
- **Compute and hosting**: Functions in 14 runtimes (Node.js, Bun, Deno, Python, Go, Rust, PHP, Ruby, Dart, Java, Kotlin, Swift, .NET, C++), triggered by HTTP, cron, or platform events. Sites for static, SSR, and CSR apps on TanStack Start, Next.js, Nuxt, SvelteKit, Astro, Remix, Angular, Vue, React, Vite, Flutter web, and more, with Git deploys from GitHub, GitLab, or Bitbucket and a fresh key on every server-rendered request.
- **Realtime and messaging**: WebSocket subscriptions and presence. Email, SMS, and push through SendGrid, Mailgun, Resend, Amazon SES, SMTP, Twilio, Vonage, MSG91, Telesign, Textmagic, FCM, and APNs.
- **Network**: custom domains with automatic TLS, domain search and purchase, a global CDN, DDoS protection, and firewall rules that deny, rate limit, or redirect traffic.
- **Workflow**: schema, buckets, functions, and sites live in \`appwrite.config.json\`. \`appwrite push\` applies them and \`appwrite generate\` regenerates the typed helpers, so a change is reviewable in a pull request and repeatable in CI.
- **Operations**: six regions (Frankfurt, New York, San Francisco, Singapore, Sydney, Toronto), SOC 2 Type II, HIPAA BAAs that Pro organizations accept in the Console, and migrations from Firebase, Supabase, Nhost, another Appwrite project, or CSV and JSON files. Open source, with a self-hosted option.
- **Plans**: the Free plan covers a first project (two per organization, no image transformations). Pro lifts the project limit. Check the user's plan before you promise a capability that needs it.

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
