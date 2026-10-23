import { formatDate } from '@/lib/date-utils'
import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'
import { getSeoSiteOrigin, resolveSiteAssetUrl } from '@/lib/marketing/site-origin'
import { getCoverImageDimensions } from '@/lib/seo/cover-dimensions'
import {
  DISCORD_EMBED_ACCENT_COLOR,
  escapeDiscordMarkdown,
  getDiscordLinkButton,
  truncateDiscordText,
  type DiscordContainer,
} from '@/lib/seo/discord-embed'
import {
  buildOgCoverUrl,
  buildOgImageUrl,
  OG_IMAGE_HEIGHT,
  OG_IMAGE_WIDTH,
} from '@/lib/seo/og-image'
import { getOrganizationSchema } from '@/lib/seo/organization'
import { SEO_SITE_NAME } from '@/lib/seo/page-meta'
import { pageTitle } from '@/lib/utils/page-title'
import type { ChangelogEntry } from './types'

export const CHANGELOG_DEFAULT_DESCRIPTION =
  "Explore Appwrite's changelog to stay on top of all the product updates and track our journey."

export type ChangelogSeoOptions = {
  siteOrigin?: string
}

export function getChangelogCanonicalUrl(path: string): string {
  return `${MARKETING_SITE_ORIGIN}${path}`
}

function getChangelogEntryDescription(entry: ChangelogEntry): string {
  const description = entry.description?.trim()
  return description && description !== entry.title
    ? description
    : CHANGELOG_DEFAULT_DESCRIPTION
}

function getChangelogEntryOgImage(
  entry: ChangelogEntry,
  siteOrigin?: string,
): string {
  if (entry.cover) {
    return buildOgCoverUrl(entry.cover, siteOrigin)
  }

  return buildOgImageUrl(
    {
      title: entry.title,
      eyebrow: 'Changelog',
      subtitle: getChangelogEntryDescription(entry),
    },
    siteOrigin,
  )
}

export function getChangelogEntryMetaTags(
  entry: ChangelogEntry,
  options?: ChangelogSeoOptions,
) {
  const siteOrigin = getSeoSiteOrigin(options?.siteOrigin)
  const title = pageTitle(entry.title)
  const description = getChangelogEntryDescription(entry)
  const canonical = getChangelogCanonicalUrl(entry.href)
  const ogImage = getChangelogEntryOgImage(entry, siteOrigin)
  const ogImageDimensions = entry.cover
    ? getCoverImageDimensions(entry.cover)
    : { width: OG_IMAGE_WIDTH, height: OG_IMAGE_HEIGHT }

  return [
    { title },
    { name: 'description', content: description },
    { property: 'og:title', content: entry.title },
    { property: 'og:description', content: description },
    { property: 'og:site_name', content: SEO_SITE_NAME },
    { property: 'og:type', content: 'article' },
    { property: 'og:url', content: canonical },
    { property: 'og:image', content: ogImage },
    ...(ogImageDimensions
      ? [
          { property: 'og:image:width', content: String(ogImageDimensions.width) },
          { property: 'og:image:height', content: String(ogImageDimensions.height) },
        ]
      : []),
    { property: 'article:published_time', content: entry.date },
    { name: 'twitter:card', content: 'summary_large_image' },
    { name: 'twitter:title', content: entry.title },
    { name: 'twitter:description', content: description },
    { name: 'twitter:image', content: ogImage },
  ]
}

/** Discord link preview for a changelog entry: cover, title, summary, date, and links back. */
export function getChangelogEntryDiscordEmbed(
  entry: ChangelogEntry,
  options?: ChangelogSeoOptions,
): DiscordContainer {
  const siteOrigin = getSeoSiteOrigin(options?.siteOrigin)
  // Most entries have no description; the generic fallback adds nothing to a preview.
  const description = entry.description?.trim()

  return {
    type: 17,
    accent_color: DISCORD_EMBED_ACCENT_COLOR,
    components: [
      ...(entry.cover
        ? [
            {
              type: 12 as const,
              items: [{ media: { url: buildOgCoverUrl(entry.cover, siteOrigin) } }],
            },
          ]
        : []),
      {
        type: 10,
        content: [
          `### ${escapeDiscordMarkdown(truncateDiscordText(entry.title, 120))}`,
          description && description !== entry.title
            ? escapeDiscordMarkdown(truncateDiscordText(description, 200))
            : undefined,
          `-# Changelog · ${formatDate(entry.date)}`,
        ]
          .filter(Boolean)
          .join('\n'),
      },
      {
        type: 1,
        components: [
          getDiscordLinkButton('Read update', getChangelogCanonicalUrl(entry.href)),
          getDiscordLinkButton('All updates', getChangelogCanonicalUrl('/changelog')),
        ],
      },
    ],
  }
}

export function getChangelogEntrySchema(entry: ChangelogEntry) {
  const canonical = getChangelogCanonicalUrl(entry.href)

  return {
    '@context': 'https://schema.org',
    '@type': 'BlogPosting',
    headline: entry.title,
    description: getChangelogEntryDescription(entry),
    datePublished: entry.date,
    url: canonical,
    mainEntityOfPage: {
      '@type': 'WebPage',
      '@id': canonical,
    },
    ...(entry.cover
      ? { image: [resolveSiteAssetUrl(entry.cover, MARKETING_SITE_ORIGIN)] }
      : {}),
    author: getOrganizationSchema(),
    publisher: getOrganizationSchema(),
  }
}
