import { MARKETING_PAGE_PATHS } from '@/lib/marketing/marketing-page-paths'
import type { SitemapChangeFreq, SitemapEntry } from '../types'

const NON_HTML_MARKETING_PATHS = new Set(['/llms/txt', '/llms-full/txt'])

const LEGAL_PATHS = new Set(['/privacy', '/terms', '/cookies', '/baa'])

function marketingPriority(path: string): number {
  if (path === '/home') return 1
  if (path === '/pricing' || path.startsWith('/products/')) return 0.9
  if (path === '/docs' || path === '/blog' || path === '/changelog' || path === '/threads') return 0.8
  if (LEGAL_PATHS.has(path)) return 0.3
  return 0.7
}

function marketingChangeFreq(path: string): SitemapChangeFreq {
  if (LEGAL_PATHS.has(path)) return 'yearly'
  if (path === '/assets') return 'monthly'
  return 'weekly'
}

export function getMarketingSitemapEntries(): SitemapEntry[] {
  return MARKETING_PAGE_PATHS.filter((path) => !NON_HTML_MARKETING_PATHS.has(path))
    .map((path) => ({
      path,
      priority: marketingPriority(path),
      changefreq: marketingChangeFreq(path),
    }))
    .sort((a, b) => a.path.localeCompare(b.path))
}
