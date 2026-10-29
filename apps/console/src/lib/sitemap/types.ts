export type SitemapChangeFreq =
  | 'always'
  | 'hourly'
  | 'daily'
  | 'weekly'
  | 'monthly'
  | 'yearly'
  | 'never'

export type SitemapEntry = {
  /** Site path starting with `/` (no origin). */
  path: string
  lastmod?: string
  changefreq?: SitemapChangeFreq
  priority?: number
}

export type SitemapSection = {
  /** Filename segment, e.g. `pages` → `/sitemap/pages.xml`. */
  id: string
  entries: SitemapEntry[]
}

export type SitemapBuildResult = {
  sections: SitemapSection[]
  totalUrls: number
}
