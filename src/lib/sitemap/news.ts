import type { BlogPostMeta } from '@/lib/blog/types'
import { escapeXml } from './xml'
import { getSitemapSiteOrigin } from './config'

/** Path served by the live news sitemap route. */
export const NEWS_SITEMAP_PATH = '/sitemap/news.xml'

/** Filename segment in the sitemap index (`/sitemap/news.xml`). */
export const NEWS_SITEMAP_SECTION_ID = 'news'

/**
 * Blog categories that are news-like. Tutorials, comparisons, and other
 * evergreen posts stay in the regular blog sitemap only.
 */
export const NEWS_SITEMAP_CATEGORIES = [
  'news',
  'announcement',
  'company',
  'init',
] as const

export const NEWS_SITEMAP_CATEGORY_SET: ReadonlySet<string> = new Set(
  NEWS_SITEMAP_CATEGORIES,
)

/** Google News sitemap limit (stricter than the generic 50,000 URL cap). */
export const NEWS_SITEMAP_MAX_URLS = 1_000

/** Google: only articles created in the last two days. */
export const NEWS_SITEMAP_LOOKBACK_DAYS = 2

/** Must match the publication name used on news.google.com. */
export const NEWS_PUBLICATION_NAME = 'Appwrite'

export const NEWS_PUBLICATION_LANGUAGE = 'en'

export const SITEMAP_XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9'
export const NEWS_SITEMAP_XMLNS = 'http://www.google.com/schemas/sitemap-news/0.9'

export type NewsSitemapEntry = {
  loc: string
  title: string
  publicationDate: string
  publicationName: string
  language: string
}

export type NewsSitemapBuildOptions = {
  origin?: string
  posts: BlogPostMeta[]
  now?: Date
}

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/
const W3C_PUBLICATION_DATE =
  /^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})?)?$/

function normalizeCategorySlug(value: string): string {
  return value.replace(/\s+/g, '-').toLowerCase()
}

function utcDayStartMs(date: Date): number {
  return Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate())
}

export function parseBlogPublishDate(value: string): Date | null {
  const trimmed = value.trim()
  if (!trimmed) return null
  const parsed = DATE_ONLY.test(trimmed)
    ? new Date(`${trimmed}T00:00:00.000Z`)
    : new Date(trimmed)
  if (Number.isNaN(parsed.getTime())) return null
  return parsed
}

/**
 * Date-only frontmatter is midnight UTC. Google's "last two days" is applied
 * as UTC calendar days: today and the two preceding days, never future dates.
 */
export function isWithinNewsSitemapWindow(
  published: Date,
  now: Date = new Date(),
): boolean {
  const publishedDay = utcDayStartMs(published)
  const today = utcDayStartMs(now)
  const oldest = today - NEWS_SITEMAP_LOOKBACK_DAYS * 24 * 60 * 60 * 1000
  return publishedDay >= oldest && publishedDay <= today
}

export function getPostCategorySlugs(post: Pick<BlogPostMeta, 'category'>): string[] {
  return post.category
    .split(',')
    .map((part) => normalizeCategorySlug(part.trim()))
    .filter(Boolean)
}

export function postMatchesNewsSitemapCategory(
  post: Pick<BlogPostMeta, 'category'>,
): boolean {
  return getPostCategorySlugs(post).some((slug) =>
    NEWS_SITEMAP_CATEGORY_SET.has(slug),
  )
}

export function toNewsPublicationDate(value: string): string | null {
  const trimmed = value.trim()
  if (DATE_ONLY.test(trimmed) && parseBlogPublishDate(trimmed)) {
    return trimmed
  }

  const parsed = parseBlogPublishDate(trimmed)
  if (!parsed) return null

  const iso = parsed.toISOString()
  return iso.replace(/\.\d{3}Z$/, 'Z')
}

export function isW3cNewsPublicationDate(value: string): boolean {
  return W3C_PUBLICATION_DATE.test(value)
}

export function selectNewsSitemapEntries(
  posts: BlogPostMeta[],
  options: { origin: string; now?: Date },
): NewsSitemapEntry[] {
  const now = options.now ?? new Date()
  const origin = options.origin.replace(/\/+$/, '')
  const selected: NewsSitemapEntry[] = []

  for (const post of posts) {
    if (post.draft || post.unlisted) continue
    if (!postMatchesNewsSitemapCategory(post)) continue
    if (!post.href.startsWith('/blog/post/')) continue

    const published = parseBlogPublishDate(post.date)
    if (!published || !isWithinNewsSitemapWindow(published, now)) continue

    const publicationDate = toNewsPublicationDate(post.date)
    if (!publicationDate || !isW3cNewsPublicationDate(publicationDate)) continue

    selected.push({
      loc: `${origin}${post.href}`,
      title: post.title,
      publicationDate,
      publicationName: NEWS_PUBLICATION_NAME,
      language: NEWS_PUBLICATION_LANGUAGE,
    })
  }

  selected.sort((a, b) => {
    const byDate = b.publicationDate.localeCompare(a.publicationDate)
    if (byDate !== 0) return byDate
    return a.loc.localeCompare(b.loc)
  })

  return selected.slice(0, NEWS_SITEMAP_MAX_URLS)
}

export function renderNewsSitemapXml(entries: NewsSitemapEntry[]): string {
  const body = entries
    .map((entry) => {
      return `  <url>
    <loc>${escapeXml(entry.loc)}</loc>
    <news:news>
      <news:publication>
        <news:name>${escapeXml(entry.publicationName)}</news:name>
        <news:language>${escapeXml(entry.language)}</news:language>
      </news:publication>
      <news:publication_date>${escapeXml(entry.publicationDate)}</news:publication_date>
      <news:title>${escapeXml(entry.title)}</news:title>
    </news:news>
  </url>`
    })
    .join('\n')

  return `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="${SITEMAP_XMLNS}"
    xmlns:news="${NEWS_SITEMAP_XMLNS}">
${body}
</urlset>
`
}

export function buildNewsSitemapXml(options: NewsSitemapBuildOptions): string {
  const origin = options.origin ?? getSitemapSiteOrigin()
  const entries = selectNewsSitemapEntries(options.posts, {
    origin,
    now: options.now,
  })
  return renderNewsSitemapXml(entries)
}
