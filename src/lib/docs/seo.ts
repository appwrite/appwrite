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

export function getDocsOgImageUrl(title: string, subtitle = 'Documentation'): string {
  const params = new URLSearchParams({
    title,
    subtitle,
  })
  return `https://og.appwrite.global/image.png?${params.toString()}`
}

export function getDocsMetaTags(meta: DocsPageMeta, slug: string) {
  const isOverview = slug.split('/').pop() === slug.split('/')[0] && !slug.includes('/')
  const title = getDocsPageTitle(meta, isOverview)
  const canonical = getDocsCanonicalUrl(slug)
  const ogImage = getDocsOgImageUrl(meta.title)

  return [
    { title },
    { name: 'description', content: meta.description },
    { property: 'og:title', content: title },
    { property: 'og:description', content: meta.description },
    { property: 'og:type', content: 'article' },
    { property: 'og:url', content: canonical },
    { property: 'og:image', content: ogImage },
    { name: 'twitter:card', content: 'summary_large_image' },
    { name: 'twitter:title', content: title },
    { name: 'twitter:description', content: meta.description },
    { name: 'twitter:image', content: ogImage },
    { tag: 'link', rel: 'canonical', href: canonical },
  ] as const
}

export function getDocsBreadcrumbSchema(meta: DocsPageMeta, slug: string) {
  const parts = slug ? slug.split('/') : []
  const items = [
    { name: 'Docs', item: `${SITE_ORIGIN}/docs` },
    ...parts.map((part, index) => ({
      name: part.replace(/-/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()),
      item: `${SITE_ORIGIN}/docs/${parts.slice(0, index + 1).join('/')}`,
    })),
  ]

  if (parts.length > 0) {
    items[items.length - 1] = { name: meta.title, item: getDocsCanonicalUrl(slug) }
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

export function getDocsArticleSchema(meta: DocsPageMeta, slug: string) {
  return {
    '@context': 'https://schema.org',
    '@type': 'TechArticle',
    headline: meta.title,
    description: meta.description,
    url: getDocsCanonicalUrl(slug),
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
