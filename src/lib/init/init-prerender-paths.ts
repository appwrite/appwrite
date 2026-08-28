/** Init page prerendered at build time to avoid per-request root SSR memory growth. */

export const INIT_PRERENDER_PATHS = ['/init'] as const

export function isInitPrerenderPath(path: string): boolean {
  return path.replace(/\/+$/, '') === '/init'
}

/** File under dist/client for `/init` (e.g. init.html). */
export function getInitPrerenderHtmlFile(urlPath: string): string | null {
  if (!isInitPrerenderPath(urlPath)) return null
  return 'init.html'
}
