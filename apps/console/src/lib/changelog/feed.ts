import { markdocToMarkdown } from '@/lib/seo/markdoc-to-markdown'
import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'
import { getAllChangelogEntries } from './content'
import type { ChangelogEntry } from './types'

export const CHANGELOG_FEED_LIMIT = 50

/** One changelog entry for clients that render it themselves, such as the Beep mobile app. */
export type ChangelogFeedEntry = {
  slug: string
  title: string
  date: string
  description: string
  tags: string[]
  cover: string | null
  url: string
  /** CommonMark with absolute links and image URLs, and no Markdoc tags. */
  body: string
}

function absolute(path: string): string {
  return path.startsWith('/') ? `${MARKETING_SITE_ORIGIN}${path}` : path
}

/**
 * Site-relative link and image targets only resolve on the site, so a client gets them absolute. Code is
 * left alone, as a sample may show a relative link on purpose.
 */
function absoluteLinks(markdown: string): string {
  return markdown
    .split(/(```[\s\S]*?```|`[^`\n]*`)/)
    .map((part, index) =>
      index % 2 === 1
        ? part
        : part.replace(
            /(\]\()(\/[^)\s]*)/g,
            (_match, open, path) => `${open}${absolute(path)}`,
          ),
    )
    .join('')
}

/** Most entries have no description of their own, so a list row shows the opening paragraph as plain text. */
function summarize(markdown: string): string {
  const opening =
    markdown
      .split(/\n\s*\n/)
      .map((block) => block.trim())
      .find((block) => block !== '' && !/^([#>|`-]|\d+\.|!\[)/.test(block)) ??
    ''
  return opening
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/[*_`]/g, '')
    .replace(/\s+/g, ' ')
}

function toFeedEntry(entry: ChangelogEntry): ChangelogFeedEntry {
  const body = absoluteLinks(markdocToMarkdown(entry.content))
  return {
    slug: entry.slug,
    title: entry.title,
    date: entry.date,
    description: entry.description?.trim() || summarize(body),
    tags: entry.parsedTags,
    cover: entry.cover ? absolute(entry.cover) : null,
    url: `${MARKETING_SITE_ORIGIN}${entry.href}`,
    body,
  }
}

export function buildChangelogFeed(): { entries: ChangelogFeedEntry[] } {
  return {
    entries: getAllChangelogEntries()
      .slice(0, CHANGELOG_FEED_LIMIT)
      .map(toFeedEntry),
  }
}
