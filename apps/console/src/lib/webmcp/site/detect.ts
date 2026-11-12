const WEBMCP_FLAG = '__WEBMCP__'

/**
 * Inline head script for public-site pages: records whether the browser has
 * WebMCP before the app loads, so the tools chunk is downloaded only where an
 * agent can use it. Scanners read inline scripts, not modulepreloaded chunks.
 */
export const WEBMCP_DETECT_SCRIPT = {
  children: `window.${WEBMCP_FLAG}=!!(document.modelContext||navigator.modelContext)`,
}

export function hasWebMcp(): boolean {
  return (window as unknown as Record<string, unknown>)[WEBMCP_FLAG] === true
}
