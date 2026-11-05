import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'
import type { Translator } from '@/lib/i18n/translate'

/**
 * Appwrite remote MCP server configuration and one-click install links.
 * Self-hosted uses a local stdio server (`uvx`) with a project API key.
 * @see https://github.com/appwrite/mcp/blob/main/docs/self-hosted.md
 */

export const MCP_SERVER_NAME = 'appwrite'
export const MCP_SERVER_URL = 'https://mcp.appwrite.io'
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

/**
 * Optional local stdio bridge for Claude Desktop when custom connectors are unavailable.
 * @see https://appwrite.io/docs/tooling/ai/vibe-coding/claude-desktop
 */
export const MCP_CLAUDE_DESKTOP_BRIDGE_CONFIG_SNIPPET = {
  mcpServers: {
    [MCP_SERVER_NAME]: {
      command: 'npx',
      args: ['-y', 'mcp-remote@0.16.0', MCP_SERVER_URL],
    },
  },
} as const

/** OpenCode remote MCP entry. */
export const MCP_OPENCODE_CONFIG_SNIPPET = {
  $schema: 'https://opencode.ai/config.json',
  mcp: {
    [MCP_SERVER_NAME]: {
      type: 'remote',
      enabled: true,
      url: MCP_SERVER_URL,
    },
  },
} as const

/** `authenticateComment` is the localized trailing comment on the `/mcp` line. */
export function getMcpClaudeCodeInstallCommand(
  authenticateComment: string,
): string {
  return `claude mcp add ${MCP_SERVER_NAME} --transport http ${MCP_SERVER_URL}
claude "/mcp" # ${authenticateComment}`
}

export const MCP_CODEX_INSTALL_COMMAND = `codex mcp add ${MCP_SERVER_NAME} --url ${MCP_SERVER_URL}`

export function getSelfHostedMcpEnv(projectId: string, endpoint: string) {
  return {
    APPWRITE_PROJECT_ID: projectId,
    APPWRITE_API_KEY: MCP_API_KEY_PLACEHOLDER,
    APPWRITE_ENDPOINT: endpoint,
  }
}

/** Shared uvx stdio server entry for self-hosted editor configs. */
function getSelfHostedUvxServer(projectId: string, endpoint: string) {
  return {
    command: 'uvx',
    args: ['mcp-server-appwrite'],
    env: getSelfHostedMcpEnv(projectId, endpoint),
  }
}

/** Cursor / VS Code / Claude Desktop mcp.json for self-hosted (stdio via uvx). */
export function getSelfHostedMcpEditorConfig(
  projectId: string,
  endpoint: string,
) {
  return {
    mcpServers: {
      [MCP_SERVER_NAME]: getSelfHostedUvxServer(projectId, endpoint),
    },
  }
}

/** OpenCode local MCP entry (`type: local` + `environment`). */
export function getSelfHostedOpencodeConfig(
  projectId: string,
  endpoint: string,
) {
  const env = getSelfHostedMcpEnv(projectId, endpoint)
  return {
    $schema: 'https://opencode.ai/config.json',
    mcp: {
      [MCP_SERVER_NAME]: {
        type: 'local',
        command: ['uvx', 'mcp-server-appwrite'],
        enabled: true,
        environment: env,
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

export type McpToolId =
  | 'claude-code'
  | 'chatgpt'
  | 'codex'
  | 'cursor'
  | 'claude-desktop'
  | 'vscode'
  | 'opencode'

type McpToolConfig = {
  id: McpToolId
  name: string
  iconPath: string
  language: CodeBlockLanguage
  code: string
  installUrl?: string
  instructions?: string
  fallbackCode?: string
}

function jsonSnippet(value: unknown): string {
  return JSON.stringify(value, null, 2)
}

export function getCloudMcpTools(t: Translator): McpToolConfig[] {
  return [
    {
      id: 'claude-code',
      name: 'Claude Code',
      iconPath: '/icons/claude.svg',
      language: 'bash',
      code: getMcpClaudeCodeInstallCommand(
        t('select "appwrite", then "Authenticate"'),
      ),
    },
    {
      id: 'chatgpt',
      name: 'ChatGPT',
      iconPath: '/icons/chatgpt.svg',
      language: 'bash',
      code: MCP_SERVER_URL,
    },
    {
      id: 'codex',
      name: 'Codex',
      iconPath: '/icons/codex.svg',
      language: 'bash',
      code: MCP_CODEX_INSTALL_COMMAND,
    },
    {
      id: 'cursor',
      name: 'Cursor',
      iconPath: '/icons/cursor-ai.svg',
      language: 'json',
      code: jsonSnippet(MCP_EDITOR_CONFIG_SNIPPET),
      installUrl: getCursorMcpInstallUrl(),
    },
    {
      id: 'claude-desktop',
      name: 'Claude Desktop',
      iconPath: '/icons/claude.svg',
      language: 'plaintext',
      code: MCP_SERVER_URL,
      instructions: t(
        'In Claude Desktop, open Customize > Connectors, add a custom connector with this URL, and sign in to Appwrite.',
      ),
      fallbackCode: jsonSnippet(MCP_CLAUDE_DESKTOP_BRIDGE_CONFIG_SNIPPET),
    },
    {
      id: 'vscode',
      name: 'VS Code',
      iconPath: '/icons/vscode.svg',
      language: 'json',
      code: jsonSnippet(MCP_EDITOR_CONFIG_SNIPPET),
      installUrl: getVscodeMcpInstallUrl(),
    },
    {
      id: 'opencode',
      name: 'OpenCode',
      iconPath: '/icons/opencode.svg',
      language: 'json',
      code: jsonSnippet(MCP_OPENCODE_CONFIG_SNIPPET),
    },
  ]
}

export function getSelfHostedMcpTools(
  projectId: string,
  endpoint: string,
): McpToolConfig[] {
  const editorConfig = jsonSnippet(
    getSelfHostedMcpEditorConfig(projectId, endpoint),
  )
  return [
    {
      id: 'claude-code',
      name: 'Claude Code',
      iconPath: '/icons/claude.svg',
      language: 'bash',
      code: getSelfHostedClaudeCodeInstallCommand(projectId, endpoint),
    },
    {
      id: 'codex',
      name: 'Codex',
      iconPath: '/icons/codex.svg',
      language: 'toml',
      code: getSelfHostedCodexConfig(projectId, endpoint),
    },
    {
      id: 'cursor',
      name: 'Cursor',
      iconPath: '/icons/cursor-ai.svg',
      language: 'json',
      code: editorConfig,
    },
    {
      id: 'claude-desktop',
      name: 'Claude Desktop',
      iconPath: '/icons/claude.svg',
      language: 'json',
      code: editorConfig,
    },
    {
      id: 'vscode',
      name: 'VS Code',
      iconPath: '/icons/vscode.svg',
      language: 'json',
      code: editorConfig,
    },
    {
      id: 'opencode',
      name: 'OpenCode',
      iconPath: '/icons/opencode.svg',
      language: 'json',
      code: jsonSnippet(getSelfHostedOpencodeConfig(projectId, endpoint)),
    },
  ]
}
