/**
 * Appwrite remote MCP server configuration and one-click install links.
 * Self-hosted uses a local stdio server (`uvx`) with a project API key.
 * @see https://github.com/appwrite/mcp/blob/main/docs/self-hosted.md
 */

export const MCP_SERVER_NAME = 'appwrite'
export const MCP_SERVER_URL = 'https://mcp.appwrite.io/mcp'
export const MCP_API_KEY_PLACEHOLDER = 'YOUR_API_KEY'
export const MCP_SELF_HOSTED_DOCS_URL =
  'https://github.com/appwrite/mcp/blob/main/docs/self-hosted.md'

/** Server entry shared by Cursor / VS Code config snippets and install deeplinks. */
export const MCP_SERVER_CONFIG = {
  url: MCP_SERVER_URL,
} as const

/** Full mcp.json snippet shown for Cursor and VS Code. */
export const MCP_EDITOR_CONFIG_SNIPPET = {
  mcpServers: {
    [MCP_SERVER_NAME]: MCP_SERVER_CONFIG,
  },
} as const

export const MCP_CLAUDE_CODE_INSTALL_COMMAND = `claude mcp add ${MCP_SERVER_NAME} --transport http ${MCP_SERVER_URL}`

export const MCP_CODEX_INSTALL_COMMAND = `codex mcp add ${MCP_SERVER_NAME} --url ${MCP_SERVER_URL}`

export function getSelfHostedMcpEnv(projectId: string, endpoint: string) {
  return {
    APPWRITE_PROJECT_ID: projectId,
    APPWRITE_API_KEY: MCP_API_KEY_PLACEHOLDER,
    APPWRITE_ENDPOINT: endpoint,
  }
}

/** Cursor / VS Code mcp.json for self-hosted (stdio via uvx). */
export function getSelfHostedMcpEditorConfig(
  projectId: string,
  endpoint: string,
) {
  return {
    mcpServers: {
      [MCP_SERVER_NAME]: {
        command: 'uvx',
        args: ['mcp-server-appwrite'],
        env: getSelfHostedMcpEnv(projectId, endpoint),
      },
    },
  }
}

export function getSelfHostedClaudeCodeInstallCommand(
  projectId: string,
  endpoint: string,
) {
  return `claude mcp add ${MCP_SERVER_NAME} \\
  --env APPWRITE_PROJECT_ID=${projectId} \\
  --env APPWRITE_API_KEY=${MCP_API_KEY_PLACEHOLDER} \\
  --env APPWRITE_ENDPOINT=${endpoint} \\
  -- uvx mcp-server-appwrite`
}

export function getSelfHostedCodexConfig(projectId: string, endpoint: string) {
  return `[mcp_servers.${MCP_SERVER_NAME}]
command = "uvx"
args = ["mcp-server-appwrite"]

[mcp_servers.${MCP_SERVER_NAME}.env]
APPWRITE_PROJECT_ID = "${projectId}"
APPWRITE_API_KEY = "${MCP_API_KEY_PLACEHOLDER}"
APPWRITE_ENDPOINT = "${endpoint}"`
}

function toBase64(value: string): string {
  // Config is ASCII-only JSON (url + name fields).
  return globalThis.btoa(value)
}

/**
 * Cursor one-click install deeplink.
 * @see https://cursor.com/docs/mcp/install-links
 */
export function getCursorMcpInstallUrl(
  name: string = MCP_SERVER_NAME,
  config: Record<string, unknown> = MCP_SERVER_CONFIG,
): string {
  const encodedConfig = encodeURIComponent(toBase64(JSON.stringify(config)))
  return `cursor://anysphere.cursor-deeplink/mcp/install?name=${encodeURIComponent(name)}&config=${encodedConfig}`
}

/**
 * VS Code one-click install deeplink (HTTP remote server).
 * Payload is a flat server object with `name` plus transport fields.
 */
export function getVscodeMcpInstallUrl(
  name: string = MCP_SERVER_NAME,
  config: Record<string, unknown> = MCP_SERVER_CONFIG,
): string {
  const payload = JSON.stringify({ name, type: 'http', ...config })
  return `vscode:mcp/install?${encodeURIComponent(payload)}`
}

/** Open a native-app install deeplink (cursor://, vscode:, …). */
export function openMcpInstallUrl(url: string): void {
  if (/^[a-z][a-z0-9+.-]*:/i.test(url) && !/^https?:/i.test(url)) {
    window.location.assign(url)
    return
  }
  window.open(url, '_blank', 'noopener,noreferrer')
}
