export function normalizeDocsRoutePathname(pathname: string): string {
  return pathname.replace(/\/+$/, '') || '/'
}

export function getDocsSlugFromPath(pathname: string): string {
  const normalized = normalizeDocsRoutePathname(pathname)
  if (normalized === '/docs') return ''
  if (!normalized.startsWith('/docs/')) return ''
  return normalized.slice('/docs/'.length)
}
