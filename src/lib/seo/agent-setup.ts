import {
  MCP_API_KEY_PLACEHOLDER,
  MCP_CODEX_INSTALL_COMMAND,
  MCP_EDITOR_CONFIG_SNIPPET,
  MCP_SELF_HOSTED_DOCS_URL,
  MCP_SERVER_NAME,
  MCP_SERVER_URL,
} from '../config/mcp.ts'
import { APPWRITE_MCP_DOCS_PATH } from './agent-discovery.ts'

export const AGENT_SETUP_PATH = '/setup.md'
export const AGENT_SETUP_ORIGIN = 'https://appwrite.io'
export const AGENT_SETUP_URL = `${AGENT_SETUP_ORIGIN}${AGENT_SETUP_PATH}`

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

The user prompt includes an Appwrite project ID. Use that project after MCP is connected. If no project ID is present, call \`appwrite_get_context\` and ask which project to use.

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

## 3. Use the user's project

If the user prompt included a project ID, use it for later Appwrite calls. Confirm the connection:

1. Call \`appwrite_get_context\` if that tool exists.
2. List databases in the given project.
3. Tell the user MCP is connected and which project you used.

If listing fails because auth is incomplete, say so and point them at the Appwrite MCP login, not at creating a key.

## 4. Self-hosted Appwrite

Use this path only when the user said the instance is self-hosted and gave an endpoint. Do not use it for Appwrite Cloud.

Add a local MCP server with \`uvx mcp-server-appwrite\` and these environment variables:

- \`APPWRITE_PROJECT_ID\` from the user prompt
- \`APPWRITE_ENDPOINT\` from the user prompt
- \`APPWRITE_API_KEY\` as \`${MCP_API_KEY_PLACEHOLDER}\`, then ask the user to replace it with a real project API key. Do not commit the key.

Self-hosted docs: ${MCP_SELF_HOSTED_DOCS_URL}

## Resources

- MCP docs: ${AGENT_SETUP_ORIGIN}${APPWRITE_MCP_DOCS_PATH}
- Remote MCP: ${MCP_SERVER_URL}

These instructions are published at \`${AGENT_SETUP_URL}\` so you can re-verify them at any time.
`
}
