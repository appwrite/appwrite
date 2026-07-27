/**
 * Centralized IDE Configuration
 *
 * This file contains the configuration for all supported IDEs in the application.
 * Use this as the single source of truth for IDE-related features like:
 * - MCP server integrations
 * - "Fix with an Agent" deeplinks
 * - IDE selection dropdowns
 */

export interface IDEConfig {
  /** Unique identifier for the IDE */
  id: string
  /** Display name */
  name: string
  /** Path to the icon in /public/icons/ */
  iconPath: string
  /** Whether the IDE supports AI chat deeplinks */
  supportsAIChat: boolean
  /**
   * Deeplink format for opening AI chat with a prompt.
   * Use `{prompt}` as the URL-encoded prompt placeholder.
   * Codex uses `generateCodexNewThreadDeeplink` instead (see `generateAIChatDeeplink`).
   */
  aiChatDeeplink?: string
  /** URL to official plugin docs (marketplace install, skills, commands) */
  pluginDocsUrl?: string
  /** URL to MCP server setup documentation for this IDE (if available) */
  mcpDocsUrl?: string
}

/**
 * List of all supported IDEs and AI assistants
 */
export const IDE_CONFIGS: IDEConfig[] = [
  // Web-based AI assistants (listed first in Fix with an Agent)
  {
    id: 'claude',
    name: 'Claude',
    iconPath: '/icons/claude.svg',
    supportsAIChat: true,
    aiChatDeeplink: 'https://claude.ai/new?q={prompt}',
  },
  {
    id: 'codex',
    name: 'Codex',
    iconPath: '/icons/chatgpt.svg',
    supportsAIChat: true,
    /** Handled by `generateCodexNewThreadDeeplink` - opens the Codex desktop app. */
    aiChatDeeplink: 'codex://threads/new',
    pluginDocsUrl: '/docs/tooling/ai/agents/codex',
  },
  // AI-powered IDEs (desktop apps with deeplinks)
  {
    id: 'cursor',
    name: 'Cursor',
    iconPath: '/icons/cursor-ai.svg',
    supportsAIChat: true,
    aiChatDeeplink: 'cursor://anysphere.cursor-deeplink/prompt?text={prompt}',
    pluginDocsUrl: '/docs/tooling/ai/ai-dev-tools/cursor',
    mcpDocsUrl: '/docs/tooling/mcp/cursor',
  },
  {
    id: 'windsurf',
    name: 'Windsurf',
    iconPath: '/icons/windsurf.svg',
    supportsAIChat: true,
    aiChatDeeplink: 'windsurf://new-chat?prompt={prompt}',
    mcpDocsUrl: '/docs/tooling/mcp/windsurf',
  },
  {
    id: 'vscode',
    name: 'VS Code',
    iconPath: '/icons/vscode.svg',
    supportsAIChat: true,
    aiChatDeeplink: 'vscode://GitHub.copilot-chat/chat?prompt={prompt}',
    mcpDocsUrl: '/docs/tooling/mcp/vscode',
  },
  // MCP-only tools (no AI chat deeplink)
  {
    id: 'claude-code',
    name: 'Claude Code',
    iconPath: '/icons/claude.svg',
    supportsAIChat: false,
    pluginDocsUrl: '/docs/tooling/ai/ai-dev-tools/claude-code',
    mcpDocsUrl: '/docs/tooling/mcp/claude',
  },
  {
    id: 'google-antigravity',
    name: 'Antigravity',
    iconPath: '/icons/google-antigravity.svg',
    supportsAIChat: false,
    mcpDocsUrl: '/docs/tooling/mcp/antigravity',
  },
  {
    id: 'opencode',
    name: 'OpenCode',
    iconPath: '/icons/opencode.svg',
    supportsAIChat: false,
    mcpDocsUrl: '/docs/tooling/mcp/opencode',
  },
]

/**
 * Get all IDEs that support AI chat deeplinks
 */
export function getAIChatIDEs(): IDEConfig[] {
  return IDE_CONFIGS.filter((ide) => ide.supportsAIChat && ide.aiChatDeeplink)
}

/**
 * Get all IDEs that have MCP documentation
 */
export function getMCPIDEs(): IDEConfig[] {
  return IDE_CONFIGS.filter((ide) => ide.mcpDocsUrl)
}

/** Official Appwrite-maintained plugins (home page and marketing). */
export const OFFICIAL_PLUGIN_IDS = ['cursor', 'claude-code', 'codex'] as const

export type HomePluginConfig = {
  id: string
  name: string
  iconPath: string
  docsUrl: string
}

/**
 * Official plugins: Cursor, Claude Code, and Codex.
 */
export function getOfficialPlugins(): HomePluginConfig[] {
  return OFFICIAL_PLUGIN_IDS.map((id) => {
    const ide = getIDEById(id)
    if (!ide?.pluginDocsUrl) {
      throw new Error(`Missing plugin docs for IDE: ${id}`)
    }

    return {
      id: ide.id,
      name: ide.name,
      iconPath: ide.iconPath,
      docsUrl: ide.pluginDocsUrl,
    }
  })
}

/**
 * MCP server setup guides for other agents and IDEs (not official plugins).
 */
export function getMcpIntegrations(): IDEConfig[] {
  const officialIds = new Set<string>(OFFICIAL_PLUGIN_IDS)
  return getMCPIDEs().filter((ide) => !officialIds.has(ide.id))
}

/**
 * Get an IDE by its ID
 */
export function getIDEById(id: string): IDEConfig | undefined {
  return IDE_CONFIGS.find((ide) => ide.id === id)
}

export type CodexNewThreadDeeplinkOptions = {
  /** Absolute path to a local directory (Codex `path=` query param). */
  workspacePath?: string
  /** Git remote URL to match a workspace root (Codex `originUrl=` query param). */
  originUrl?: string
}

/**
 * Opens a new local thread in the Codex desktop app with the composer prefilled.
 * Does not auto-run the prompt. @see https://developers.openai.com/codex/app/commands#deeplinks
 */
export function generateCodexNewThreadDeeplink(
  prompt: string,
  options?: CodexNewThreadDeeplinkOptions,
): string {
  const params = new URLSearchParams()
  const trimmedPrompt = prompt.trim()
  if (trimmedPrompt) {
    params.set('prompt', trimmedPrompt)
  }
  if (options?.workspacePath?.trim()) {
    params.set('path', options.workspacePath.trim())
  }
  if (options?.originUrl?.trim()) {
    params.set('originUrl', options.originUrl.trim())
  }
  const query = params.toString()
  return query ? `codex://threads/new?${query}` : 'codex://threads/new'
}

/**
 * Generate a deeplink URL for opening AI chat in an IDE or agent app.
 */
export function generateAIChatDeeplink(
  ide: IDEConfig,
  prompt: string,
  codexOptions?: CodexNewThreadDeeplinkOptions,
): string | null {
  if (!ide.supportsAIChat) {
    return null
  }
  if (ide.id === 'codex') {
    return generateCodexNewThreadDeeplink(prompt, codexOptions)
  }
  if (!ide.aiChatDeeplink?.includes('{prompt}')) {
    return null
  }
  return ide.aiChatDeeplink.replace('{prompt}', encodeURIComponent(prompt))
}

/**
 * Opens an agent deeplink. Native app schemes (codex://, cursor://, …) use
 * `location.assign` so the OS protocol handler runs; https links open in a tab.
 */
export function openAIChatDeeplink(deeplink: string): void {
  if (/^[a-z][a-z0-9+.-]*:/i.test(deeplink) && !/^https?:/i.test(deeplink)) {
    window.location.assign(deeplink)
    return
  }
  window.open(deeplink, '_blank', 'noopener,noreferrer')
}
