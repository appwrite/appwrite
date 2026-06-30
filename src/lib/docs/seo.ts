import { buildOgImageUrl, OG_IMAGE_HEIGHT, OG_IMAGE_WIDTH } from '@/lib/seo/og-image'
import { getSeoSiteOrigin } from '@/lib/marketing/site-origin'
import type { DocsPageMeta } from './types'

const SITE_ORIGIN = 'https://appwrite.io'

export function getDocsCanonicalUrl(slug: string): string {
  const path = slug ? `/docs/${slug}` : '/docs'
  return `${SITE_ORIGIN}${path}`
}

export function getDocsPageTitle(meta: DocsPageMeta, isOverview = false): string {
  if (isOverview) return `${meta.title} - Overview - Appwrite`
  return `${meta.title} - Docs - Appwrite`
}

const DOCS_OG_EYEBROW = 'Documentation'
const DOCS_OG_FALLBACK_SUBTITLE =
  'Guides and references for building with Appwrite.'
const DOCS_OG_HOME_TITLE = 'Build with Appwrite'

export function getDocsOgImageUrl(
  meta: Pick<DocsPageMeta, 'title' | 'description'>,
  siteOrigin?: string,
): string {
  const title = meta.title.trim()
  const description = meta.description.trim()
  const ogTitle =
    title.localeCompare(DOCS_OG_EYEBROW, undefined, { sensitivity: 'accent' }) ===
    0
      ? DOCS_OG_HOME_TITLE
      : title
  const subtitle =
    description && description !== ogTitle && description !== title
      ? description
      : DOCS_OG_FALLBACK_SUBTITLE

  return buildOgImageUrl(
    {
      title: ogTitle,
      eyebrow: DOCS_OG_EYEBROW,
      subtitle,
    },
    siteOrigin,
  )
}

export function getDocsMetaTags(
  meta: DocsPageMeta,
  slug: string,
  options?: { canonicalSlug?: string; siteOrigin?: string },
) {
  const isOverview = slug.split('/').pop() === slug.split('/')[0] && !slug.includes('/')
  const title = getDocsPageTitle(meta, isOverview)
  const canonicalSlug = options?.canonicalSlug ?? slug
  const canonical = getDocsCanonicalUrl(canonicalSlug)
  const siteOrigin = getSeoSiteOrigin(options?.siteOrigin)
  const ogImage = getDocsOgImageUrl(meta, siteOrigin)

  return [
    { title },
    { name: 'description', content: meta.description },
    { property: 'og:title', content: title },
    { property: 'og:description', content: meta.description },
    { property: 'og:type', content: 'article' },
    { property: 'og:url', content: canonical },
    { property: 'og:image', content: ogImage },
    { property: 'og:image:width', content: String(OG_IMAGE_WIDTH) },
    { property: 'og:image:height', content: String(OG_IMAGE_HEIGHT) },
    { name: 'twitter:card', content: 'summary_large_image' },
    { name: 'twitter:title', content: title },
    { name: 'twitter:description', content: meta.description },
    { name: 'twitter:image', content: ogImage },
    { tag: 'link', rel: 'canonical', href: canonical },
  ] as const
}

export function getDocsBreadcrumbSchema(
  meta: DocsPageMeta,
  slug: string,
  options?: { canonicalSlug?: string },
) {
  const effectiveSlug = options?.canonicalSlug ?? slug
  const parts = effectiveSlug ? effectiveSlug.split('/') : []
  const items = [
    { name: 'Docs', item: `${SITE_ORIGIN}/docs` },
    ...parts.map((part, index) => ({
      name: part.replace(/-/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()),
      item: `${SITE_ORIGIN}/docs/${parts.slice(0, index + 1).join('/')}`,
    })),
  ]

  if (parts.length > 0) {
    items[items.length - 1] = {
      name: meta.title,
      item: getDocsCanonicalUrl(effectiveSlug),
    }
  }

  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((item, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      name: item.name,
      item: item.item,
    })),
  }
}

export function getDocsArticleSchema(
  meta: DocsPageMeta,
  slug: string,
  options?: { canonicalSlug?: string },
) {
  const effectiveSlug = options?.canonicalSlug ?? slug
  return {
    '@context': 'https://schema.org',
    '@type': 'TechArticle',
    headline: meta.title,
    description: meta.description,
    url: getDocsCanonicalUrl(effectiveSlug),
    ...(meta.readingTimeMinutes
      ? { timeRequired: `PT${meta.readingTimeMinutes}M` }
      : {}),
    publisher: {
      '@type': 'Organization',
      name: 'Appwrite',
      url: SITE_ORIGIN,
    },
  }
}
