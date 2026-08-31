import { getActiveProfileFeatures } from '@/lib/console-profiles'

export function isDomainsDocsSlug(slug: string): boolean {
  return (
    slug === 'products/domains' ||
    slug.startsWith('products/domains/') ||
    slug === 'partners/domains' ||
    slug.startsWith('partners/domains/')
  )
}

export function isDomainsDocsPathname(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return (
    normalized === '/docs/products/domains' ||
    normalized.startsWith('/docs/products/domains/') ||
    normalized === '/docs/partners/domains' ||
    normalized.startsWith('/docs/partners/domains/')
  )
}

export function isDomainsDocsHref(href: string): boolean {
  const path = href.split(/[?#]/, 2)[0] ?? href
  return (
    path === '/docs/products/domains' ||
    path.startsWith('/docs/products/domains/') ||
    path === '/docs/partners/domains' ||
    path.startsWith('/docs/partners/domains/')
  )
}

export function isDomainsDocsEnabled(): boolean {
  return getActiveProfileFeatures().domains
}
