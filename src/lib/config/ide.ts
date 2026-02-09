/**
 * Centralized IDE Configuration
 *
 * This file contains the configuration for all supported IDEs in the application.
 * Use this as the single source of truth for IDE-related features like:
 * - MCP server integrations
 * - "Fix with AI" deeplinks
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
   * Use {prompt} as placeholder for the URL-encoded prompt.
   * Only applicable if supportsAIChat is true.
   */
  aiChatDeeplink?: string
  /** URL to MCP documentation for this IDE (if available) */
  mcpDocsUrl?: string
}

/**
 * List of all supported IDEs and AI assistants
 */
export const IDE_CONFIGS: IDEConfig[] = [
  // AI-powered IDEs (desktop apps with deeplinks)
  {
    id: 'cursor',
    name: 'Cursor',
    iconPath: '/icons/cursor-ai.svg',
    supportsAIChat: true,
    aiChatDeeplink: 'cursor://anysphere.cursor-deeplink/prompt?text={prompt}',
    mcpDocsUrl: 'https://appwrite.io/docs/tooling/mcp/cursor',
  },
  {
    id: 'windsurf',
    name: 'Windsurf',
    iconPath: '/icons/windsurf.svg',
    supportsAIChat: true,
    aiChatDeeplink: 'windsurf://new-chat?prompt={prompt}',
    mcpDocsUrl: 'https://appwrite.io/docs/tooling/mcp/windsurf',
  },
  {
    id: 'vscode',
    name: 'VS Code',
    iconPath: '/icons/vscode.svg',
    supportsAIChat: true,
    aiChatDeeplink: 'vscode://GitHub.copilot-chat/chat?prompt={prompt}',
    mcpDocsUrl: 'https://appwrite.io/docs/tooling/mcp/vscode',
  },
  // Web-based AI assistants
  {
    id: 'chatgpt',
    name: 'ChatGPT',
    iconPath: '/icons/chatgpt.svg',
    supportsAIChat: true,
    aiChatDeeplink: 'https://chatgpt.com/?hints=search&q={prompt}',
  },
  {
    id: 'claude',
    name: 'Claude',
    iconPath: '/icons/claude.svg',
    supportsAIChat: true,
    aiChatDeeplink: 'https://claude.ai/new?q={prompt}',
  },
  // MCP-only tools (no AI chat deeplink)
  {
    id: 'claude-code',
    name: 'Claude Code',
    iconPath: '/icons/claude.svg',
    supportsAIChat: false,
    mcpDocsUrl: 'https://appwrite.io/docs/tooling/mcp/claude',
  },
  {
    id: 'google-antigravity',
    name: 'Google Antigravity',
    iconPath: '/icons/google-antigravity.svg',
    supportsAIChat: false,
    mcpDocsUrl: 'https://appwrite.io/docs/tooling/mcp/antigravity',
  },
  {
    id: 'opencode',
    name: 'OpenCode',
    iconPath: '/icons/opencode.svg',
    supportsAIChat: false,
    mcpDocsUrl: 'https://appwrite.io/docs/tooling/mcp/opencode',
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

/**
 * Get an IDE by its ID
 */
export function getIDEById(id: string): IDEConfig | undefined {
  return IDE_CONFIGS.find((ide) => ide.id === id)
}

/**
 * Generate a deeplink URL for opening AI chat in an IDE
 * @param ide - The IDE configuration
 * @param prompt - The prompt to pass to the AI chat
 * @returns The deeplink URL or null if not supported
 */
export function generateAIChatDeeplink(
  ide: IDEConfig,
  prompt: string,
): string | null {
  if (!ide.supportsAIChat || !ide.aiChatDeeplink) {
    return null
  }
  return ide.aiChatDeeplink.replace('{prompt}', encodeURIComponent(prompt))
}
