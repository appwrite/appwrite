const STALE_CHUNK_RELOAD_KEY = 'console.staleChunkReloadAttempted'

/** Clears the one-time auto-reload guard after a successful full page load. */
export function clearStaleChunkReloadGuard(): void {
  if (typeof sessionStorage === 'undefined') return
  sessionStorage.removeItem(STALE_CHUNK_RELOAD_KEY)
}

/**
 * Detects failed dynamic import / route chunk loads. The server often returns SPA
 * HTML (index.html) for missing assets after deploy, which surfaces as a MIME type error.
 */
export function isStaleChunkLoadError(error: unknown): boolean {
  const message =
    error instanceof Error
      ? error.message
      : typeof error === 'string'
        ? error
        : ''
  const lower = message.toLowerCase()
  return (
    message.includes("'text/html' is not a valid JavaScript MIME type") ||
    lower.includes('failed to fetch dynamically imported module') ||
    lower.includes('importing a module script failed') ||
    lower.includes('error loading dynamically imported module') ||
    lower.includes('unable to preload css')
  )
}

/**
 * Reload once per session when a stale JS chunk is detected (e.g. after deploy).
 * Returns true when a reload was triggered.
 */
export function tryReloadForStaleChunk(error: unknown): boolean {
  if (typeof window === 'undefined') return false
  if (!isStaleChunkLoadError(error)) return false
  if (typeof sessionStorage !== 'undefined') {
    if (sessionStorage.getItem(STALE_CHUNK_RELOAD_KEY)) return false
    sessionStorage.setItem(STALE_CHUNK_RELOAD_KEY, String(Date.now()))
  }
  window.location.reload()
  return true
}
