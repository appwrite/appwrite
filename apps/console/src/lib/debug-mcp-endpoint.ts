/**
 * Debug-only override for the Appwrite assistant MCP endpoint.
 * Stores the actual MCP URL in localStorage (not a preset key).
 */

import { MCP_SERVER_URL } from '@/lib/config/mcp'

export type McpEndpointPresetId = 'production' | 'localhost' | 'custom'

export const MCP_ENDPOINT_PRESETS: Record<
  Exclude<McpEndpointPresetId, 'custom'>,
  { label: string; url: string; description: string }
> = {
  production: {
    label: 'Production',
    url: MCP_SERVER_URL,
    description: 'Hosted Appwrite MCP',
  },
  localhost: {
    label: 'Localhost',
    url: 'http://localhost:8100/',
    description: 'Local cloud compose (appwrite-mcp)',
  },
}

/** Single key: store the actual MCP URL (e.g. http://localhost:8100/). */
const DEBUG_MCP_ENDPOINT_URL_KEY = 'debug:mcpEndpointUrl'

export function normalizeMcpEndpointUrl(url: string): string {
  return url.trim().replace(/\/+$/, '') + '/'
}

function getStoredUrl(): string | null {
  if (typeof window === 'undefined' || !window.localStorage) return null
  const stored = window.localStorage.getItem(DEBUG_MCP_ENDPOINT_URL_KEY)
  if (!stored || !stored.trim()) return null
  return stored.trim()
}

/**
 * Returns the current debug MCP endpoint override preset for UI (derived from
 * stored URL), or null if using env.
 */
export function getDebugMcpEndpointOverride(): McpEndpointPresetId | null {
  const url = getStoredUrl()
  if (!url) return null
  const normalized = normalizeMcpEndpointUrl(url)
  for (const [id, preset] of Object.entries(MCP_ENDPOINT_PRESETS) as [
    keyof typeof MCP_ENDPOINT_PRESETS,
    (typeof MCP_ENDPOINT_PRESETS)[keyof typeof MCP_ENDPOINT_PRESETS],
  ][]) {
    if (normalizeMcpEndpointUrl(preset.url) === normalized) {
      return id as McpEndpointPresetId
    }
  }
  return 'custom'
}

/**
 * Returns the stored custom URL when the stored value is not a preset;
 * otherwise null for display.
 */
export function getDebugCustomMcpEndpoint(): string | null {
  const url = getStoredUrl()
  if (!url) return null
  const preset = getDebugMcpEndpointOverride()
  return preset === 'custom' ? normalizeMcpEndpointUrl(url) : null
}

/**
 * Returns the effective MCP URL when a debug override is set.
 * Returns null when no override (use env).
 */
export function getDebugMcpEndpointUrl(): string | null {
  const url = getStoredUrl()
  if (!url) return null
  try {
    new URL(url)
    return normalizeMcpEndpointUrl(url)
  } catch {
    return null
  }
}

/**
 * Returns the MCP URL from VITE_APPWRITE_MCP_URL when present, else hosted default.
 */
export function getEnvMcpEndpointUrl(): string {
  const fromEnv = (
    import.meta.env.VITE_APPWRITE_MCP_URL as string | undefined
  )?.trim()
  return normalizeMcpEndpointUrl(fromEnv || MCP_SERVER_URL)
}

/**
 * Returns the effective MCP URL currently in use, preferring the debug override
 * and falling back to the env var / hosted default.
 */
export function getEffectiveMcpEndpointUrl(): string {
  const debugUrl = getDebugMcpEndpointUrl()
  if (debugUrl) return debugUrl
  return getEnvMcpEndpointUrl()
}

export const DEBUG_MCP_ENDPOINT_CHANGE_EVENT = 'debugMcpEndpointChange'

/**
 * Set the MCP endpoint override (debug only).
 * Stores the actual URL in localStorage. For preset 'custom', pass the full URL
 * in customUrl.
 */
export function setDebugMcpEndpointOverride(
  preset: McpEndpointPresetId | null,
  customUrl?: string,
) {
  if (typeof window === 'undefined' || !window.localStorage) return
  if (preset === null) {
    window.localStorage.removeItem(DEBUG_MCP_ENDPOINT_URL_KEY)
  } else if (preset === 'custom' && customUrl?.trim()) {
    window.localStorage.setItem(
      DEBUG_MCP_ENDPOINT_URL_KEY,
      normalizeMcpEndpointUrl(customUrl.trim()),
    )
  } else if (preset !== 'custom' && MCP_ENDPOINT_PRESETS[preset]) {
    window.localStorage.setItem(
      DEBUG_MCP_ENDPOINT_URL_KEY,
      normalizeMcpEndpointUrl(MCP_ENDPOINT_PRESETS[preset].url),
    )
  }
  window.dispatchEvent(new CustomEvent(DEBUG_MCP_ENDPOINT_CHANGE_EVENT))
}

/**
 * Subscribe to MCP endpoint override changes.
 */
export function subscribeToDebugMcpEndpointChange(
  callback: () => void,
): () => void {
  if (typeof window === 'undefined') return () => undefined

  const handler = () => callback()
  window.addEventListener(DEBUG_MCP_ENDPOINT_CHANGE_EVENT, handler)
  window.addEventListener('storage', (e) => {
    if (e.key === DEBUG_MCP_ENDPOINT_URL_KEY) callback()
  })

  return () => {
    window.removeEventListener(DEBUG_MCP_ENDPOINT_CHANGE_EVENT, handler)
  }
}
