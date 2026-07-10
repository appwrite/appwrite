const STALE_CHUNK_RELOAD_KEY = 'console.staleChunkReloadAttempted'

/** Default delay before clearing the one-reload guard after a successful boot. */
export const STALE_CHUNK_GUARD_CLEAR_DELAY_MS = 5_000

/** Clears the one-time auto-reload guard (e.g. after a successful settle). */
export function clearStaleChunkReloadGuard(): void {
  if (typeof sessionStorage === 'undefined') return
  sessionStorage.removeItem(STALE_CHUNK_RELOAD_KEY)
}

/**
 * Clears the reload guard only after the app has stayed up long enough that
 * chunk loads likely succeeded. Must not run on router init immediately —
 * that defeats the one-reload limit and causes infinite reload loops when a
 * chunk is still missing after the first recovery attempt.
 */
export function scheduleClearStaleChunkReloadGuard(
  delayMs: number = STALE_CHUNK_GUARD_CLEAR_DELAY_MS,
): void {
  if (typeof window === 'undefined') return
  window.setTimeout(() => {
    clearStaleChunkReloadGuard()
  }, delayMs)
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
    lower.includes('mime type of "text/html"') ||
    lower.includes('failed to load module script') ||
    lower.includes('failed to fetch dynamically imported module') ||
    lower.includes('importing a module script failed') ||
    lower.includes('error loading dynamically imported module') ||
    lower.includes('unable to preload css')
  )
}

/**
 * Reload once per recovery window when a stale JS chunk is detected (e.g. after deploy).
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
