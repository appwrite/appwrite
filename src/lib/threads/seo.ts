import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'
import { getSeoSiteOrigin } from '@/lib/marketing/site-origin'
import { buildOgImageUrl, OG_IMAGE_HEIGHT, OG_IMAGE_WIDTH } from '@/lib/seo/og-image'
import { sanitizeJsonLdText } from '@/lib/seo/json-ld'
import type { DiscordAuthor, DiscordMessage, DiscordThread } from './types'
import { getAuthorDescription } from './content'
import { THREADS_DEFAULT_DESCRIPTION } from './constants'

function getThreadsDefaultOgImage(siteOrigin?: string): string {
  return buildOgImageUrl(
    {
      title: 'Appwrite Threads',
      eyebrow: 'Threads',
      subtitle: THREADS_DEFAULT_DESCRIPTION,
    },
    siteOrigin,
  )
}

export function getThreadsCanonicalUrl(path: string): string {
  return `${MARKETING_SITE_ORIGIN}${path}`
}

export function getThreadsPageTitle(title: string): string {
  return `${title} · Appwrite`
}

export function getThreadOgImageUrl(
  thread: Pick<DiscordThread, 'title' | 'seo_description' | 'content'>,
  siteOrigin?: string,
): string {
  const title = thread.title.trim()
  const seoDescription = thread.seo_description?.trim()
  const content = thread.content?.trim()
  const subtitle =
    (seoDescription && seoDescription !== title && seoDescription) ||
    (content && content !== title && content) ||
    THREADS_DEFAULT_DESCRIPTION

  return buildOgImageUrl(
    {
      title,
      eyebrow: 'Threads',
      subtitle,
    },
    siteOrigin,
  )
}

export function getThreadsIndexMetaTags(siteOrigin?: string) {
  const title = getThreadsPageTitle('Threads')
  const description =
    "Appwrite's Threads page showcases our community interactions on Discord. Join the conversation, ask questions, or assist other members with their issues."
  const canonical = getThreadsCanonicalUrl('/threads')
  const resolvedOrigin = getSeoSiteOrigin(siteOrigin)
  const ogImage = getThreadsDefaultOgImage(resolvedOrigin)

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

export function getThreadsThreadMetaTags(
  thread: DiscordThread,
  canonicalUrl: string,
  siteOrigin?: string,
) {
  const pageTitle = getThreadsPageTitle(`${thread.title} - Threads`)
  const description = thread.seo_description ?? THREADS_DEFAULT_DESCRIPTION
  const resolvedOrigin = getSeoSiteOrigin(siteOrigin)
  const ogImage = getThreadOgImageUrl(thread, resolvedOrigin)

  return [
    { title: pageTitle },
    { name: 'description', content: description },
    { property: 'og:title', content: pageTitle },
    { property: 'og:description', content: description },
    { property: 'og:type', content: 'article' },
    { property: 'og:url', content: canonicalUrl },
    { property: 'og:image', content: ogImage },
    { property: 'og:image:width', content: String(OG_IMAGE_WIDTH) },
    { property: 'og:image:height', content: String(OG_IMAGE_HEIGHT) },
    { name: 'twitter:card', content: 'summary_large_image' },
    { name: 'twitter:title', content: pageTitle },
    { name: 'twitter:description', content: description },
    { name: 'twitter:image', content: ogImage },
    { tag: 'link', rel: 'canonical', href: canonicalUrl },
  ] as const
}

export function getThreadsAuthorMetaTags(
  author: DiscordAuthor,
  canonicalUrl: string,
  siteOrigin?: string,
) {
  const title = getThreadsPageTitle(`${author.display_name} - Threads`)
  const description = getAuthorDescription(author)
  const ogImage = getThreadsDefaultOgImage(getSeoSiteOrigin(siteOrigin))

  return [
    { title },
    { name: 'description', content: description },
    { property: 'og:title', content: title },
    { property: 'og:description', content: description },
    { property: 'og:type', content: 'profile' },
    { property: 'og:url', content: canonicalUrl },
    { property: 'og:image', content: ogImage },
    { property: 'og:image:width', content: String(OG_IMAGE_WIDTH) },
    { property: 'og:image:height', content: String(OG_IMAGE_HEIGHT) },
    { name: 'twitter:card', content: 'summary_large_image' },
    { name: 'twitter:title', content: title },
    { name: 'twitter:description', content: description },
    { name: 'twitter:image', content: ogImage },
    { tag: 'link', rel: 'canonical', href: canonicalUrl },
  ] as const
}

function toIso8601DateTime(value: string): string {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? new Date().toISOString() : date.toISOString()
}

function toSchemaInteger(value: unknown): number | undefined {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return Math.max(0, Math.trunc(value))
  }
  if (typeof value === 'string' && value.trim() !== '') {
    const parsed = Number(value)
    if (Number.isFinite(parsed)) {
      return Math.max(0, Math.trunc(parsed))
    }
  }
  return undefined
}

function schemaText(value: string, fallback: string): string {
  const sanitized = sanitizeJsonLdText(value)
  const trimmed = sanitized.trim()
  return trimmed.length > 0 ? sanitized : sanitizeJsonLdText(fallback)
}

function getThreadAuthorPersonSchema(
  name: string,
  authorId?: string,
): Record<string, unknown> {
  const trimmedAuthorId = authorId?.trim()
  return {
    '@type': 'Person',
    name: schemaText(name, 'Anonymous'),
    ...(trimmedAuthorId
      ? { url: getThreadsCanonicalUrl(`/threads/authors/${trimmedAuthorId}`) }
      : {}),
  }
}

export function getDiscussionForumPageSchema(options: {
  canonicalUrl: string
  thread: Pick<
    DiscordThread,
    'title' | 'content' | 'author' | 'author_id' | '$createdAt' | 'vote_count'
  >
  messages: Pick<
    DiscordMessage,
    'author' | 'author_id' | 'message' | 'timestamp' | '$id'
  >[]
}) {
  const { canonicalUrl, thread, messages } = options
  const first = messages[0]
  const opText = schemaText(
    first?.message ?? '',
    schemaText(thread.content, thread.title),
  )
  const opAuthor = (first?.author ?? thread.author).trim() || 'Anonymous'
  const opAuthorId = first?.author_id ?? thread.author_id
  const opDate = toIso8601DateTime(first?.timestamp ?? thread.$createdAt)

  const comments = messages.slice(1).map((message) => {
    const text = schemaText(message.message, '(No text)')
    const comment: Record<string, unknown> = {
      '@type': 'Comment',
      text,
      author: getThreadAuthorPersonSchema(message.author, message.author_id),
      datePublished: toIso8601DateTime(message.timestamp),
    }
    if (message.$id) {
      comment.url = `${canonicalUrl}#message-${message.$id}`
    }
    return comment
  })

  const mainEntity: Record<string, unknown> = {
    '@type': 'DiscussionForumPosting',
    headline: schemaText(thread.title, 'Thread'),
    url: canonicalUrl,
    mainEntityOfPage: canonicalUrl,
    text: opText,
    author: getThreadAuthorPersonSchema(opAuthor, opAuthorId),
    datePublished: opDate,
  }

  const voteCount = toSchemaInteger(thread.vote_count)
  if (voteCount !== undefined) {
    mainEntity.interactionStatistic = {
      '@type': 'InteractionCounter',
      interactionType: 'https://schema.org/LikeAction',
      userInteractionCount: voteCount,
    }
  }

  const replyCount = toSchemaInteger(Math.max(0, messages.length - 1))
  if (replyCount !== undefined && replyCount > 0) {
    mainEntity.commentCount = replyCount
    mainEntity.comment = comments
  }

  return {
    '@context': 'https://schema.org',
    '@type': 'WebPage',
    url: canonicalUrl,
    mainEntity,
  }
}

export function getThreadsAuthorPageSchema(
  author: DiscordAuthor,
  canonicalUrl: string,
) {
  return {
    '@context': 'https://schema.org',
    '@type': 'ProfilePage',
    url: canonicalUrl,
    mainEntity: {
      '@type': 'Person',
      name: schemaText(author.display_name, 'Author'),
      alternateName: schemaText(author.username, 'Author'),
      ...(author.bio?.trim()
        ? { description: sanitizeJsonLdText(author.bio) }
        : {}),
      interactionStatistic: [
        {
          '@type': 'InteractionCounter',
          interactionType: 'https://schema.org/WriteAction',
          userInteractionCount:
            (toSchemaInteger(author.thread_count) ?? 0) +
            (toSchemaInteger(author.reply_count) ?? 0),
        },
      ],
    },
  }
}

export function getThreadsIndexPageSchema() {
  return {
    '@context': 'https://schema.org',
    '@type': 'WebPage',
    name: 'Threads',
    url: getThreadsCanonicalUrl('/threads'),
    description:
      'Community support threads from the Appwrite Discord. Search discussions, browse topics, and find answers from developers.',
    isPartOf: {
      '@type': 'WebSite',
      name: 'Appwrite',
      url: MARKETING_SITE_ORIGIN,
    },
  }
}

export function getThreadsBreadcrumbSchema(
  items: Array<{ name: string; path: string }>,
) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((item, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      name: schemaText(item.name, 'Threads'),
      item: getThreadsCanonicalUrl(item.path),
    })),
  }
}
