/** Marketing pages without a hand-written twin; their `.md` is converted from the rendered HTML. */
export const MARKDOWN_TWIN_PAGES = [
  '/affiliates',
  '/agencies',
  '/assets',
  '/baa',
  '/community',
  '/company',
  '/cookies',
  '/domains',
  '/education',
  '/enterprise',
  '/innovation-teams',
  '/partners',
  '/privacy',
  '/startups',
  '/terms',
  '/products/analytics',
  '/products/auth',
  '/products/databases',
  '/products/firewall',
  '/products/functions',
  '/products/messaging',
  '/products/postgres',
  '/products/realtime',
  '/products/sites',
  '/products/storage',
] as const

/** The page whose twin `pathname` (`/company.md`) requests, if any. */
export function markdownTwinPage(pathname: string): string | null {
  if (!pathname.endsWith('.md')) return null
  const page = pathname.slice(0, -'.md'.length)
  return (MARKDOWN_TWIN_PAGES as readonly string[]).includes(page) ? page : null
}
