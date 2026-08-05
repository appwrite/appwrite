import type { ProductNavItemId } from '@/lib/products/types'

/** How long a product stays marked as new after its launch date. */
export const PRODUCT_NEW_BADGE_MONTHS = 3

/**
 * UTC launch dates (YYYY-MM-DD) for products that should show a temporary
 * "New" badge in the footer, docs nav, and products header popover.
 */
export const PRODUCT_LAUNCH_DATES: Partial<Record<ProductNavItemId, string>> = {
  databases: '2026-07-01',
  domains: '2026-07-01',
  firewall: '2026-07-01',
  agent: '2026-08-04',
}

const DOCS_PRODUCT_NEW_HREFS: Partial<Record<string, ProductNavItemId>> = {
  '/docs/products/databases': 'databases',
  '/docs/products/domains': 'domains',
  '/docs/products/firewall': 'firewall',
  '/docs/products/agent': 'agent',
}

function parseUtcDate(isoDate: string): Date {
  const [year, month, day] = isoDate.split('-').map(Number)
  return new Date(Date.UTC(year, (month ?? 1) - 1, day ?? 1))
}

function addUtcMonths(date: Date, months: number): Date {
  const result = new Date(date.getTime())
  result.setUTCMonth(result.getUTCMonth() + months)
  return result
}

export function isProductNavItemNew(
  id: ProductNavItemId,
  now: Date = new Date(),
): boolean {
  const launchedAt = PRODUCT_LAUNCH_DATES[id]
  if (!launchedAt) return false

  const launch = parseUtcDate(launchedAt)
  if (Number.isNaN(launch.getTime())) return false

  return now.getTime() < addUtcMonths(launch, PRODUCT_NEW_BADGE_MONTHS).getTime()
}

export function isDocsProductNavNew(
  href: string,
  now: Date = new Date(),
): boolean {
  const normalized = href.replace(/\/+$/, '') || href
  const productId = DOCS_PRODUCT_NEW_HREFS[normalized]
  return productId ? isProductNavItemNew(productId, now) : false
}
