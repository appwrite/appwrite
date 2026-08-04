import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'
import { getSeoSiteOrigin, resolveSiteAssetUrl } from '@/lib/marketing/site-origin'
import { buildOgImageUrl, OG_IMAGE_HEIGHT, OG_IMAGE_WIDTH } from '@/lib/seo/og-image'
import { getCoverImageDimensions } from '@/lib/seo/cover-dimensions'
import { SEO_SITE_NAME } from '@/lib/seo/page-meta'
import { getPostCategoryLabel } from './content'
import type { BlogAuthor, BlogCategory, BlogFaq, BlogPost, BlogPostMeta } from './types'

export type BlogSeoOptions = {
  siteOrigin?: string
}

export const BLOG_DEFAULT_DESCRIPTION =
  'Product updates, tutorials, and stories from Appwrite.'

function getBlogDefaultOgImage(siteOrigin?: string): string {
  return buildOgImageUrl(
    {
      title: 'Appwrite Blog',
      eyebrow: 'Blog',
      subtitle: BLOG_DEFAULT_DESCRIPTION,
    },
    siteOrigin,
  )
}

export function getBlogCanonicalUrl(path: string): string {
  return `${MARKETING_SITE_ORIGIN}${path}`
}

export function getBlogPostTitle(post: Pick<BlogPostMeta, 'title' | 'metaTitle'>): string {
  return post.metaTitle ?? post.title
}

export function getBlogPageTitle(title: string): string {
  return `${title} · Appwrite`
}

export function getBlogPostOgImageUrl(
  post: Pick<BlogPostMeta, 'title' | 'description'>,
  siteOrigin?: string,
): string {
  const title = post.title.trim()
  const description = post.description.trim()
  const subtitle =
    description && description !== title ? description : BLOG_DEFAULT_DESCRIPTION

  return buildOgImageUrl(
    {
      title,
      eyebrow: 'Blog',
      subtitle,
    },
    siteOrigin,
  )
}

export function getBlogPostCoverImageUrl(
  post: Pick<BlogPostMeta, 'cover'>,
  siteOrigin?: string,
): string | undefined {
  return post.cover ? resolveSiteAssetUrl(post.cover, siteOrigin) : undefined
}

export function getBlogPostOgImage(post: BlogPostMeta, siteOrigin?: string): string {
  return (
    getBlogPostCoverImageUrl(post, siteOrigin) ??
    getBlogPostOgImageUrl(post, siteOrigin)
  )
}

export function getBlogIndexMetaTags(options?: BlogSeoOptions) {
  const siteOrigin = getSeoSiteOrigin(options?.siteOrigin)
  const title = getBlogPageTitle('Blog')
  const description =
    'Explore the Appwrite blog for product updates, engineering deep dives, tutorials, and customer stories.'
  const canonical = getBlogCanonicalUrl('/blog')
  const ogImage = getBlogDefaultOgImage(siteOrigin)

  return [
    { title },
    { name: 'description', content: description },
    { property: 'og:title', content: title },
    { property: 'og:description', content: description },
    { property: 'og:type', content: 'website' },
    { property: 'og:url', content: canonical },
    { property: 'og:image', content: ogImage },
    { property: 'og:image:width', content: String(OG_IMAGE_WIDTH) },
    { property: 'og:image:height', content: String(OG_IMAGE_HEIGHT) },
    { name: 'twitter:card', content: 'summary_large_image' },
    { name: 'twitter:title', content: title },
    { name: 'twitter:description', content: description },
    { name: 'twitter:image', content: ogImage },
    { tag: 'link', rel: 'canonical', href: canonical },
  ] as const
}

export function getBlogPostMetaTags(
  post: BlogPost,
  authors: BlogAuthor[] = [],
  options?: BlogSeoOptions,
) {
  const siteOrigin = getSeoSiteOrigin(options?.siteOrigin)
  const resolvedTitle = getBlogPostTitle(post)
  const title = getBlogPageTitle(resolvedTitle)
  const canonical = getBlogCanonicalUrl(post.href)
  const coverImage = getBlogPostCoverImageUrl(post, siteOrigin)
  const ogImage = coverImage ?? getBlogPostOgImageUrl(post, siteOrigin)
  // Real cover dimensions from the generated manifest; the dynamic OG
  // endpoint always renders at OG_IMAGE_WIDTH x OG_IMAGE_HEIGHT.
  const ogImageDimensions = coverImage
    ? getCoverImageDimensions(coverImage)
    : { width: OG_IMAGE_WIDTH, height: OG_IMAGE_HEIGHT }

  return [
    { title },
    { name: 'description', content: post.description },
    { property: 'og:title', content: resolvedTitle },
    { property: 'og:description', content: post.description },
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
    { property: 'article:published_time', content: post.date },
    { property: 'article:modified_time', content: post.lastUpdated },
    { property: 'article:section', content: getPostCategoryLabel(post) },
    ...authors.map((author) => ({
      property: 'article:author',
      content: getBlogCanonicalUrl(author.href),
    })),
    { name: 'twitter:card', content: 'summary_large_image' },
    { name: 'twitter:title', content: resolvedTitle },
    { name: 'twitter:description', content: post.description },
    { name: 'twitter:image', content: ogImage },
    { tag: 'link', rel: 'canonical', href: canonical },
  ]
}

export function getBlogCategoryMetaTags(category: BlogCategory, options?: BlogSeoOptions) {
  const siteOrigin = getSeoSiteOrigin(options?.siteOrigin)
  const title = getBlogPageTitle(category.name)
  const canonical = getBlogCanonicalUrl(category.href)
  const ogImage = getBlogDefaultOgImage(siteOrigin)

  return [
    { title },
    { name: 'description', content: category.description },
    { property: 'og:title', content: title },
    { property: 'og:description', content: category.description },
    { property: 'og:type', content: 'website' },
    { property: 'og:url', content: canonical },
    { property: 'og:image', content: ogImage },
    { property: 'og:image:width', content: String(OG_IMAGE_WIDTH) },
    { property: 'og:image:height', content: String(OG_IMAGE_HEIGHT) },
    { name: 'twitter:card', content: 'summary_large_image' },
    { name: 'twitter:title', content: title },
    { name: 'twitter:description', content: category.description },
    { name: 'twitter:image', content: ogImage },
    { tag: 'link', rel: 'canonical', href: canonical },
  ] as const
}

export function getBlogAuthorMetaTags(author: BlogAuthor, options?: BlogSeoOptions) {
  const siteOrigin = getSeoSiteOrigin(options?.siteOrigin)
  const title = getBlogPageTitle(author.name)
  const canonical = getBlogCanonicalUrl(author.href)
  const ogImage = getBlogDefaultOgImage(siteOrigin)

  return [
    { title },
    { name: 'description', content: author.bio },
    { property: 'og:title', content: title },
    { property: 'og:description', content: author.bio },
    { property: 'og:type', content: 'profile' },
    { property: 'og:url', content: canonical },
    { property: 'og:image', content: ogImage },
    { property: 'og:image:width', content: String(OG_IMAGE_WIDTH) },
    { property: 'og:image:height', content: String(OG_IMAGE_HEIGHT) },
    { name: 'twitter:card', content: 'summary_large_image' },
    { name: 'twitter:title', content: title },
    { name: 'twitter:description', content: author.bio },
    { name: 'twitter:image', content: ogImage },
    { tag: 'link', rel: 'canonical', href: canonical },
  ] as const
}

export const PUBLISHER_LOGO_URL = `${MARKETING_SITE_ORIGIN}/apple-touch-icon.png`

export function getPublisherSchema() {
  return {
    '@type': 'Organization',
    name: 'Appwrite',
    url: MARKETING_SITE_ORIGIN,
    logo: {
      '@type': 'ImageObject',
      url: PUBLISHER_LOGO_URL,
    },
  }
}

function getAuthorSchema(author: BlogAuthor) {
  const sameAs = [author.twitter, author.linkedin, author.github].filter(
    (url): url is string => Boolean(url),
  )

  return {
    '@type': 'Person',
    name: author.name,
    url: getBlogCanonicalUrl(author.href),
    ...(author.avatar
      ? {
          image: author.avatar.startsWith('/')
            ? `${MARKETING_SITE_ORIGIN}${author.avatar}`
            : author.avatar,
        }
      : {}),
    ...(sameAs.length > 0 ? { sameAs } : {}),
  }
}

export function getBlogPostSchema(
  post: BlogPost,
  authors: BlogAuthor[],
  options?: BlogSeoOptions,
) {
  const coverImage = getBlogPostCoverImageUrl(post, options?.siteOrigin)
  const canonical = getBlogCanonicalUrl(post.href)

  return {
    '@context': 'https://schema.org',
    '@type': 'BlogPosting',
    headline: post.title,
    description: post.description,
    datePublished: post.date,
    dateModified: post.lastUpdated,
    url: canonical,
    mainEntityOfPage: {
      '@type': 'WebPage',
      '@id': canonical,
    },
    ...(coverImage ? { image: [coverImage] } : {}),
    author: authors.map(getAuthorSchema),
    publisher: getPublisherSchema(),
  }
}

export function getBlogBreadcrumbSchema(
  items: Array<{ name: string; path: string }>,
) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((item, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      name: item.name,
      item: getBlogCanonicalUrl(item.path),
    })),
  }
}

export function getBlogFaqSchema(faqs: BlogFaq[]) {
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: faqs.map((faq) => ({
      '@type': 'Question',
      name: faq.question,
      acceptedAnswer: {
        '@type': 'Answer',
        text: faq.answer,
      },
    })),
  }
}
