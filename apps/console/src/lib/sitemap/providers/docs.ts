import { DOCS_PAGES } from '@/lib/docs/generated/manifest'
import type { SitemapEntry } from '../types'

export function getDocsSitemapEntries(): SitemapEntry[] {
  const entries: SitemapEntry[] = [
    {
      path: '/docs',
      priority: 0.9,
      changefreq: 'weekly',
    },
  ]

  for (const page of DOCS_PAGES) {
    entries.push({
      path: page.slug ? `/docs/${page.slug}` : '/docs',
      priority: page.layout === 'tutorial' ? 0.7 : 0.8,
      changefreq: 'weekly',
    })
  }

  return entries
}
