/**
 * Debug-only override for the Appwrite API endpoint.
 * Stores the actual endpoint URL in localStorage (not a preset key).
 */

export type EndpointPresetId = 'production' | 'stage' | 'localhost' | 'custom'

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
    url: 'https://stage.cloud.appwrite.io/v1',
    description: 'Appwrite Cloud staging',
  },
  localhost: {
    label: 'Localhost',
    url: 'http://localhost/v1',
    description: 'Local Appwrite instance',
  },
}

/** Single key: store the actual endpoint URL (e.g. https://cloud.appwrite.io/v1). */
const DEBUG_ENDPOINT_URL_KEY = 'debug:endpointUrl'

function normalizeUrl(url: string): string {
  try {
    const u = new URL(url)
    const origin = u.origin.replace(/\/$/, '')
    return `${origin}/v1`
  } catch {
    return url
  }
}

function getStoredUrl(): string | null {
  if (typeof window === 'undefined') return null
  const stored = localStorage.getItem(DEBUG_ENDPOINT_URL_KEY)
  if (!stored || !stored.trim()) return null
  return stored.trim()
}

/**
 * Returns the current debug endpoint override preset for UI (derived from stored URL), or null if using env.
 */
export function getDebugEndpointOverride(): EndpointPresetId | null {
  const url = getStoredUrl()
  if (!url) return null
  for (const [id, preset] of Object.entries(ENDPOINT_PRESETS) as [
    keyof typeof ENDPOINT_PRESETS,
    (typeof ENDPOINT_PRESETS)[keyof typeof ENDPOINT_PRESETS],
  ][]) {
    if (preset.url === url) return id as EndpointPresetId
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
  return preset === 'custom' ? url : null
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
    return normalizeUrl(url)
  } catch {
    return null
  }
}

export const DEBUG_ENDPOINT_CHANGE_EVENT = 'debugEndpointChange'

/**
 * Set the endpoint override (debug only).
 * Stores the actual URL in localStorage. For preset 'custom', pass the full URL in customUrl.
 */
export function setDebugEndpointOverride(
  preset: EndpointPresetId | null,
  customUrl?: string,
) {
  if (typeof window === 'undefined') return
  if (preset === null) {
    localStorage.removeItem(DEBUG_ENDPOINT_URL_KEY)
  } else if (preset === 'custom' && customUrl?.trim()) {
    localStorage.setItem(DEBUG_ENDPOINT_URL_KEY, normalizeUrl(customUrl.trim()))
  } else if (preset !== 'custom' && ENDPOINT_PRESETS[preset]) {
    localStorage.setItem(DEBUG_ENDPOINT_URL_KEY, ENDPOINT_PRESETS[preset].url)
  }
  window.dispatchEvent(new CustomEvent(DEBUG_ENDPOINT_CHANGE_EVENT))
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
    if (e.key === DEBUG_ENDPOINT_URL_KEY) callback()
  })

  return () => {
    window.removeEventListener(DEBUG_ENDPOINT_CHANGE_EVENT, handler)
  }
}
