import { parseDocsPagePath, splitHrefHash } from '@/lib/marketing/urls'

export function docsHrefToPreviewSlug(href: string): string | null {
  const { pathname } = splitHrefHash(href)
  const docsPath = parseDocsPagePath(pathname)
  if (!docsPath) return null
  if (docsPath === '/docs') return ''
  return docsPath.slice('/docs/'.length)
}
