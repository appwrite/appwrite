import { isFeatureGatedDocsSlugHidden } from '@/lib/docs/feature-gated-docs'
import { DOCS_PAGES } from '@/lib/docs/generated/manifest'
import { getLastChanged } from '../content-dates'
import type { SitemapEntry } from '../types'

export function getDocsSitemapEntries(): SitemapEntry[] {
  const entries: SitemapEntry[] = [
    {
      path: '/docs',
      lastmod: getLastChanged(['src/content/docs/index.markdoc']),
      priority: 0.9,
      changefreq: 'weekly',
    },
  ]

  for (const page of DOCS_PAGES) {
    if (
      isFeatureGatedDocsSlugHidden(page.slug, { deferPartnersOnServer: true })
    ) {
      continue
    }
    entries.push({
      path: page.slug ? `/docs/${page.slug}` : '/docs',
      lastmod: getLastChanged([
        `src/content/docs/${page.slug}/index.markdoc`,
        `src/content/docs/${page.slug}.markdoc`,
      ]),
      priority: page.layout === 'tutorial' ? 0.7 : 0.8,
      changefreq: 'weekly',
    })
  }

  return entries
}
