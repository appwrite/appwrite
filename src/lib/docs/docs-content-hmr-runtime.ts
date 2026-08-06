/**
 * Module-level docs HMR bridge.
 *
 * Listens for the custom Vite WS event from `docsContentHmrPlugin` (preferred)
 * and falls back to vite:afterUpdate when importers are in the client graph.
 *
 * Must not import docs content, routes, or DocsPreview — those modules can
 * Fast Refresh / invalidate and re-bind listeners mid-update.
 */

export const DOCS_CONTENT_HMR_EVENT = 'docs-content-hmr'

function isDocsHmrUpdatePath(path: string): boolean {
  return (
    path.includes('/content/docs') ||
    path.includes('/content/docs-local') ||
    path.includes('/content/docs-partials') ||
    path.includes('/lib/docs/content') ||
    path.includes('/lib/docs/partials') ||
    path.includes('/routes/docs/') ||
    path.includes('/pages/docs/') ||
    path.includes('/providers/DocsPreview')
  )
}

function touchesDocsContent(payload: {
  updates: Array<{ path: string; acceptedPath?: string }>
}): boolean {
  return payload.updates.some(
    (update) =>
      isDocsHmrUpdatePath(update.path) ||
      (update.acceptedPath != null && isDocsHmrUpdatePath(update.acceptedPath)),
  )
}

function emitDocsContentHmr() {
  if (typeof window === 'undefined') return
  window.dispatchEvent(new CustomEvent(DOCS_CONTENT_HMR_EVENT))
}

let emitTimer: ReturnType<typeof setTimeout> | null = null

function scheduleDocsContentHmr() {
  if (emitTimer != null) clearTimeout(emitTimer)
  emitTimer = setTimeout(() => {
    emitTimer = null
    emitDocsContentHmr()
  }, 30)
}

if (import.meta.hot) {
  // Primary path: Vite plugin watches markdoc/partials and sends this event.
  import.meta.hot.on('docs-content-update', () => {
    scheduleDocsContentHmr()
  })

  // Fallback when a docs importer is already in the client module graph.
  import.meta.hot.on(
    'vite:afterUpdate',
    (payload: {
      updates: Array<{ path: string; acceptedPath?: string }>
    }) => {
      if (!touchesDocsContent(payload)) return
      scheduleDocsContentHmr()
    },
  )
}
