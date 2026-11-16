import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'
import { BLOG_RSS_PATH, CHANGELOG_RSS_PATH } from '@/lib/seo/rss'

/** NLWeb Schema Feeds map, linked from robots.txt with `schemamap:`. */
export const SCHEMAMAP_PATH = '/schemamap.xml'

const RSS_FEEDS = [BLOG_RSS_PATH, CHANGELOG_RSS_PATH]

export function buildSchemamap(): string {
  const urls = RSS_FEEDS.map(
    (path) => `  <url contentType="structuredData/rss">
    <loc>${MARKETING_SITE_ORIGIN}${path}</loc>
    <sf:contentType>structuredData/rss</sf:contentType>
  </url>`,
  ).join('\n')

  return `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:sf="http://schema.org/schemas/schemafeed/0.1">
${urls}
</urlset>
`
}
