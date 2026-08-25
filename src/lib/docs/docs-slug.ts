export function getDocsSlugFromPath(pathname: string): string {
  const normalized = pathname.replace(/\/+$/, '')
  if (normalized === '/docs') return ''
  if (!normalized.startsWith('/docs/')) return ''
  return normalized.slice('/docs/'.length)
}
