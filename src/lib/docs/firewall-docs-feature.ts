import { getActiveProfileFeatures } from '@/lib/console-profiles'

export function isFirewallDocsSlug(slug: string): boolean {
  return slug === 'products/firewall' || slug.startsWith('products/firewall/')
}

export function isFirewallDocsPathname(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return (
    normalized === '/docs/products/firewall' ||
    normalized.startsWith('/docs/products/firewall/')
  )
}

export function isFirewallDocsHref(href: string): boolean {
  const path = href.split(/[?#]/, 2)[0] ?? href
  return (
    path === '/docs/products/firewall' ||
    path.startsWith('/docs/products/firewall/')
  )
}

export function isFirewallDocsEnabled(): boolean {
  return getActiveProfileFeatures().firewall
}
