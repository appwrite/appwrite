import { getActiveProfileFeatures } from '@/lib/console-profiles'

export function isAgentDocsSlug(slug: string): boolean {
  return slug === 'products/agent' || slug.startsWith('products/agent/')
}

export function isAgentDocsPathname(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return (
    normalized === '/docs/products/agent' ||
    normalized.startsWith('/docs/products/agent/')
  )
}

export function isAgentDocsHref(href: string): boolean {
  const path = href.split(/[?#]/, 2)[0] ?? href
  return (
    path === '/docs/products/agent' || path.startsWith('/docs/products/agent/')
  )
}

export function isAgentDocsEnabled(): boolean {
  return getActiveProfileFeatures().agent
}
