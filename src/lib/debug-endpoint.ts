/**
 * Debug-only override for the Appwrite API endpoint.
 * Stores the actual endpoint URL in localStorage (not a preset key).
 * Also remembers a list of custom endpoints the team has added.
 */

import { getRuntimeConfig } from '@/lib/runtime-config'
import { resolveAppwriteEndpointFallback } from '@/lib/runtime-config-shared'

export type EndpointPresetId =
  | 'production'
  | 'stage'
  | 'localhostCloud'
  | 'localhostCe'
  | 'oss'
  | 'custom'

export const ENDPOINT_PRESETS: Record<
  Exclude<EndpointPresetId, 'custom'>,
  { label: string; url: string; description: string }
> = {
  production: {
    label: 'Production',
    url: 'https://cloud.appwrite.io/v1',
    description: 'Appwrite Cloud production',
  },
  stage: {
    label: 'Stage',
    url: 'https://cloud.staging.appwrite.io/v1',
    description: 'Appwrite Cloud staging',
  },
  localhostCloud: {
    label: 'Local Cloud',
    url: 'http://localhost/v1',
    description: 'Local Cloud instance',
  },
  localhostCe: {
    label: 'Local CE',
    url: 'http://localhost:9522/v1',
    description: 'Local Community Edition instance',
  },
  oss: {
    label: 'OSS',
    url: 'https://oss.appwrite.org/v1',
    description: 'Permanent self-hosted (AWS)',
  },
}

/** Single key: store the actual endpoint URL (e.g. https://cloud.appwrite.io/v1). */
const DEBUG_ENDPOINT_URL_KEY = 'debug:endpointUrl'

/** JSON array of custom endpoint URLs saved by the user (debug only). */
const DEBUG_CUSTOM_ENDPOINTS_KEY = 'debug:customEndpointUrls'

export function normalizeEndpointUrl(url: string): string {
  try {
    const u = new URL(url)
    const origin = u.origin.replace(/\/$/, '')
    return `${origin}/v1`
  } catch {
    return url.trim()
  }
}

export function isCloudEndpointUrl(url: string): boolean {
  try {
    const host = new URL(url).hostname.toLowerCase()
    return host === 'cloud.appwrite.io' || host.endsWith('.cloud.appwrite.io')
  } catch {
    return false
  }
}

function getStoredUrl(): string | null {
  if (typeof window === 'undefined' || !window.localStorage) return null
  const stored = window.localStorage.getItem(DEBUG_ENDPOINT_URL_KEY)
  if (!stored || !stored.trim()) return null
  return stored.trim()
}

function isPresetUrl(url: string): boolean {
  const normalized = normalizeEndpointUrl(url)
  return Object.values(ENDPOINT_PRESETS).some(
    (preset) => normalizeEndpointUrl(preset.url) === normalized,
  )
}

function readStoredCustomEndpoints(): string[] {
  if (typeof window === 'undefined' || !window.localStorage) return []
  const raw = window.localStorage.getItem(DEBUG_CUSTOM_ENDPOINTS_KEY)
  if (!raw) return []
  try {
    const parsed = JSON.parse(raw)
    if (!Array.isArray(parsed)) return []
    const seen = new Set<string>()
    const urls: string[] = []
    for (const entry of parsed) {
      if (typeof entry !== 'string' || !entry.trim()) continue
      const normalized = normalizeEndpointUrl(entry.trim())
      if (!normalized || isPresetUrl(normalized) || seen.has(normalized)) continue
      try {
        new URL(normalized)
      } catch {
        continue
      }
      seen.add(normalized)
      urls.push(normalized)
    }
    return urls
  } catch {
    return []
  }
}

function writeStoredCustomEndpoints(urls: string[]) {
  if (typeof window === 'undefined' || !window.localStorage) return
  if (urls.length === 0) {
    window.localStorage.removeItem(DEBUG_CUSTOM_ENDPOINTS_KEY)
    return
  }
  window.localStorage.setItem(DEBUG_CUSTOM_ENDPOINTS_KEY, JSON.stringify(urls))
}

function notifyEndpointChange() {
  window.dispatchEvent(new CustomEvent(DEBUG_ENDPOINT_CHANGE_EVENT))
}

/**
 * Returns the current debug endpoint override preset for UI (derived from stored URL), or null if using env.
 */
export function getDebugEndpointOverride(): EndpointPresetId | null {
  const url = getStoredUrl()
  if (!url) return null
  const normalized = normalizeEndpointUrl(url)
  for (const [id, preset] of Object.entries(ENDPOINT_PRESETS) as [
    keyof typeof ENDPOINT_PRESETS,
    (typeof ENDPOINT_PRESETS)[keyof typeof ENDPOINT_PRESETS],
  ][]) {
    if (normalizeEndpointUrl(preset.url) === normalized) {
      return id as EndpointPresetId
    }
  }
  return 'custom'
}

/**
 * Returns the stored custom URL when the stored value is not a preset; otherwise null for display.
 */
export function getDebugCustomEndpoint(): string | null {
  const url = getStoredUrl()
  if (!url) return null
  const preset = getDebugEndpointOverride()
  return preset === 'custom' ? normalizeEndpointUrl(url) : null
}

/**
 * Returns saved custom endpoints (excluding built-in presets).
 * Also includes the currently active custom override if it is not yet in the list
 * (migration for the previous single-custom-url behavior).
 */
export function getCustomDebugEndpoints(): string[] {
  const urls = readStoredCustomEndpoints()
  const activeCustom = getDebugCustomEndpoint()
  if (!activeCustom || urls.includes(activeCustom)) return urls
  const merged = [...urls, activeCustom]
  writeStoredCustomEndpoints(merged)
  return merged
}

/**
 * Add a custom endpoint to the saved list (no-op for invalid or preset URLs).
 * Returns the normalized URL when added or already present; otherwise null.
 */
export function addCustomDebugEndpoint(url: string): string | null {
  if (typeof window === 'undefined' || !window.localStorage) return null
  const trimmed = url.trim()
  if (!trimmed) return null
  let normalized: string
  try {
    normalized = normalizeEndpointUrl(trimmed)
    new URL(normalized)
  } catch {
    return null
  }
  if (isPresetUrl(normalized)) return null

  const urls = readStoredCustomEndpoints()
  if (!urls.includes(normalized)) {
    writeStoredCustomEndpoints([...urls, normalized])
  }
  notifyEndpointChange()
  return normalized
}

/**
 * Remove a custom endpoint from the saved list.
 * Returns true when the removed URL was the active override (caller should clear selection).
 */
export function removeCustomDebugEndpoint(url: string): boolean {
  if (typeof window === 'undefined' || !window.localStorage) return false
  const normalized = normalizeEndpointUrl(url.trim())
  const urls = readStoredCustomEndpoints().filter((entry) => entry !== normalized)
  writeStoredCustomEndpoints(urls)

  const active = getStoredUrl()
  const wasActive =
    !!active && normalizeEndpointUrl(active) === normalized && !isPresetUrl(normalized)

  if (wasActive) {
    window.localStorage.removeItem(DEBUG_ENDPOINT_URL_KEY)
  }

  notifyEndpointChange()
  return wasActive
}

/**
 * Returns the effective base API URL when a debug override is set (the actual value from localStorage).
 * Returns null when no override (use env).
 */
export function getDebugEndpointBaseUrl(): string | null {
  const url = getStoredUrl()
  if (!url) return null
  try {
    new URL(url)
    return normalizeEndpointUrl(url)
  } catch {
    return null
  }
}

/**
 * Returns the API URL from VITE_APPWRITE_ENDPOINT when present.
 */
export function getEnvEndpointBaseUrl(): string | null {
  const config = getRuntimeConfig()
  if (config.appwriteEndpoint.trim()) {
    return normalizeEndpointUrl(config.appwriteEndpoint.trim())
  }

  if (typeof window !== 'undefined') {
    return normalizeEndpointUrl(
      resolveAppwriteEndpointFallback(config.consoleProfile, window.location),
    )
  }

  return null
}

/**
 * Returns the effective API URL currently in use, preferring the debug override and
 * falling back to the env var endpoint.
 */
export function getEffectiveEndpointBaseUrl(): string | null {
  const debugBase = getDebugEndpointBaseUrl()
  if (debugBase) return debugBase

  return getEnvEndpointBaseUrl()
}

export const DEBUG_ENDPOINT_CHANGE_EVENT = 'debugEndpointChange'

/**
 * Set the endpoint override (debug only).
 * Stores the actual URL in localStorage. For preset 'custom', pass the full URL in customUrl.
 * Selecting a custom URL also adds it to the remembered custom list.
 */
export function setDebugEndpointOverride(
  preset: EndpointPresetId | null,
  customUrl?: string,
) {
  if (typeof window === 'undefined' || !window.localStorage) return
  if (preset === null) {
    window.localStorage.removeItem(DEBUG_ENDPOINT_URL_KEY)
  } else if (preset === 'custom' && customUrl?.trim()) {
    const normalized = normalizeEndpointUrl(customUrl.trim())
    window.localStorage.setItem(DEBUG_ENDPOINT_URL_KEY, normalized)
    if (!isPresetUrl(normalized)) {
      const urls = readStoredCustomEndpoints()
      if (!urls.includes(normalized)) {
        writeStoredCustomEndpoints([...urls, normalized])
      }
    }
  } else if (preset !== 'custom' && ENDPOINT_PRESETS[preset]) {
    window.localStorage.setItem(
      DEBUG_ENDPOINT_URL_KEY,
      ENDPOINT_PRESETS[preset].url,
    )
  }
  notifyEndpointChange()
}

/**
 * Subscribe to endpoint override changes.
 */
export function subscribeToDebugEndpointChange(
  callback: () => void,
): () => void {
  if (typeof window === 'undefined') return () => undefined

  const handler = () => callback()
  window.addEventListener(DEBUG_ENDPOINT_CHANGE_EVENT, handler)
  window.addEventListener('storage', (e) => {
    if (
      e.key === DEBUG_ENDPOINT_URL_KEY ||
      e.key === DEBUG_CUSTOM_ENDPOINTS_KEY
    ) {
      callback()
    }
  })

  return () => {
    window.removeEventListener(DEBUG_ENDPOINT_CHANGE_EVENT, handler)
  }
}
