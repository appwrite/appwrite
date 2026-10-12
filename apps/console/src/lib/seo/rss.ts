export const BLOG_RSS_PATH = '/blog/rss.xml'
export const CHANGELOG_RSS_PATH = '/changelog/rss.xml'

export type RssFeedItem = {
  title: string
  link: string
  description?: string
  /** ISO date (YYYY-MM-DD or full ISO string). */
  date: string
  /** Author display names (rendered as dc:creator). */
  authors?: string[]
  category?: string
  /** Absolute URL of the item's cover image. */
  imageUrl?: string
}

export type RssFeedOptions = {
  title: string
  link: string
  description: string
  /** Absolute URL of this feed (used for the atom:link self reference). */
  feedUrl: string
  items: RssFeedItem[]
}

function escapeXml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
}

function toRssDate(date: string): string {
  const parsed = new Date(date)
  if (Number.isNaN(parsed.getTime())) return ''
  return parsed.toUTCString()
}

function renderItem(item: RssFeedItem): string {
  const parts = [
    `<title>${escapeXml(item.title)}</title>`,
    `<link>${escapeXml(item.link)}</link>`,
    `<guid isPermaLink="true">${escapeXml(item.link)}</guid>`,
  ]

  if (item.description) {
    parts.push(`<description>${escapeXml(item.description)}</description>`)
  }

  const pubDate = toRssDate(item.date)
  if (pubDate) {
    parts.push(`<pubDate>${pubDate}</pubDate>`)
  }

  for (const author of item.authors ?? []) {
    parts.push(`<dc:creator>${escapeXml(author)}</dc:creator>`)
  }

  if (item.category) {
    parts.push(`<category>${escapeXml(item.category)}</category>`)
  }

  if (item.imageUrl) {
    parts.push(`<media:content url="${escapeXml(item.imageUrl)}" medium="image" />`)
  }

  return `    <item>\n      ${parts.join('\n      ')}\n    </item>`
}

export function buildRssFeed(options: RssFeedOptions): string {
  const items = options.items.map(renderItem).join('\n')

  return `<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title>${escapeXml(options.title)}</title>
    <link>${escapeXml(options.link)}</link>
    <description>${escapeXml(options.description)}</description>
    <language>en</language>
    <atom:link href="${escapeXml(options.feedUrl)}" rel="self" type="application/rss+xml" />
${items}
  </channel>
</rss>
`
}
