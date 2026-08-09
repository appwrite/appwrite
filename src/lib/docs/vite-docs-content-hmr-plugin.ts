import path from 'node:path'
import type { Plugin } from 'vite'

export const DOCS_CONTENT_HMR_EVENT = 'docs-content-update'

function isDocsContentFile(file: string): boolean {
  const normalized = file.replaceAll('\\', '/')
  return (
    normalized.includes('/src/content/docs/') ||
    normalized.includes('/src/content/docs-local/') ||
    normalized.includes('/src/content/docs-partials/')
  ) && /\.(?:markdoc|md)$/.test(normalized)
}

/**
 * Soft-refresh docs markdoc/partials in the browser without Vite's default SSR
 * program reload. Lazy `import.meta.glob` means the client often has no module
 * edge to the edited file, so default HMR only reloads SSR and skips our UI.
 */
export function docsContentHmrPlugin(): Plugin {
  return {
    name: 'docs-content-hmr',
    apply: 'serve',
    handleHotUpdate({ file, server }) {
      if (!isDocsContentFile(file)) return

      server.ws.send({
        type: 'custom',
        event: DOCS_CONTENT_HMR_EVENT,
        data: {
          file: path.relative(server.config.root, file),
        },
      })

      // Empty array: we handle refresh ourselves (View fetch + query invalidate).
      // Returning undefined would let Vite full-reload the SSR module graph.
      return []
    },
  }
}
