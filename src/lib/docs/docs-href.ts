export function docsHrefToPreviewSlug(href: string): string | null {
  if (href === '/docs' || href === '/docs/') return ''

  if (href.startsWith('/docs/')) {
    return href.slice('/docs/'.length).replace(/\/+$/, '') || ''
  }

  try {
    const url = new URL(href)
    const pathname = url.pathname.replace(/\/+$/, '') || '/'
    if (pathname === '/docs') return ''
    if (pathname.startsWith('/docs/')) {
      return pathname.slice('/docs/'.length)
    }
  } catch {
    return null
  }

  return null
}
