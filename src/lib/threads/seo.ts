import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'
import type { DiscordAuthor, DiscordMessage, DiscordThread } from './types'
import { getAuthorDescription } from './content'
import { THREADS_DEFAULT_DESCRIPTION } from './constants'

const THREADS_OG_IMAGE = `${MARKETING_SITE_ORIGIN}/images/open-graph/website.avif`

export function getThreadsCanonicalUrl(path: string): string {
  return `${MARKETING_SITE_ORIGIN}${path}`
}

export function getThreadsPageTitle(title: string): string {
  return `${title} · Appwrite`
}

export function getThreadOgImageUrl(title: string, description: string): string {
  const params = new URLSearchParams({
    title: title.slice(0, 32),
    subtitle: description.slice(0, 64),
  })
  return `https://og.appwrite.global/image.png?${params.toString()}`
}

export function getThreadsIndexMetaTags() {
  const title = getThreadsPageTitle('Threads')
  const description =
    "Appwrite's Threads page showcases our community interactions on Discord. Join the conversation, ask questions, or assist other members with their issues."
  const canonical = getThreadsCanonicalUrl('/threads')

  return [
    { title },
    { name: 'description', content: description },
    { property: 'og:title', content: title },
    { property: 'og:description', content: description },
    { property: 'og:type', content: 'website' },
    { property: 'og:url', content: canonical },
    { property: 'og:image', content: THREADS_OG_IMAGE },
    { property: 'og:image:width', content: '1200' },
    { property: 'og:image:height', content: '630' },
    { name: 'twitter:card', content: 'summary_large_image' },
    { name: 'twitter:title', content: title },
    { name: 'twitter:description', content: description },
    { name: 'twitter:image', content: THREADS_OG_IMAGE },
    { tag: 'link', rel: 'canonical', href: canonical },
  ] as const
}

export function getThreadsThreadMetaTags(
  thread: DiscordThread,
  canonicalUrl: string,
) {
  const pageTitle = getThreadsPageTitle(`${thread.title} - Threads`)
  const description = thread.seo_description ?? THREADS_DEFAULT_DESCRIPTION
  const ogImage = getThreadOgImageUrl(
    thread.title,
    thread.seo_description ?? THREADS_DEFAULT_DESCRIPTION,
  )

  return [
    { title: pageTitle },
    { name: 'description', content: description },
    { property: 'og:title', content: pageTitle },
    { property: 'og:description', content: description },
    { property: 'og:type', content: 'article' },
    { property: 'og:url', content: canonicalUrl },
    { property: 'og:image', content: ogImage },
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
) {
  const title = getThreadsPageTitle(`${author.display_name} - Threads`)
  const description = getAuthorDescription(author)

  return [
    { title },
    { name: 'description', content: description },
    { property: 'og:title', content: title },
    { property: 'og:description', content: description },
    { property: 'og:type', content: 'profile' },
    { property: 'og:url', content: canonicalUrl },
    { property: 'og:image', content: THREADS_OG_IMAGE },
    { name: 'twitter:card', content: 'summary_large_image' },
    { name: 'twitter:title', content: title },
    { name: 'twitter:description', content: description },
    { name: 'twitter:image', content: THREADS_OG_IMAGE },
    { tag: 'link', rel: 'canonical', href: canonicalUrl },
  ] as const
}

function toIso8601DateTime(value: string): string {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? new Date().toISOString() : date.toISOString()
}

function nonEmptyText(value: string, fallback: string): string {
  const trimmed = value.trim()
  return trimmed.length > 0 ? value : fallback
}

export function getDiscussionForumPageSchema(options: {
  canonicalUrl: string
  thread: Pick<
    DiscordThread,
    'title' | 'content' | 'author' | '$createdAt' | 'vote_count'
  >
  messages: Pick<DiscordMessage, 'author' | 'message' | 'timestamp' | '$id'>[]
}) {
  const { canonicalUrl, thread, messages } = options
  const first = messages[0]
  const opText = nonEmptyText(
    first?.message ?? '',
    nonEmptyText(thread.content, thread.title),
  )
  const opAuthor = (first?.author ?? thread.author).trim() || 'Anonymous'
  const opDate = toIso8601DateTime(first?.timestamp ?? thread.$createdAt)

  const comments = messages.slice(1).map((message) => {
    const text = nonEmptyText(message.message, '(No text)')
    const comment: Record<string, unknown> = {
      '@type': 'Comment',
      text,
      author: {
        '@type': 'Person',
        name: message.author.trim() || 'Anonymous',
      },
      datePublished: toIso8601DateTime(message.timestamp),
    }
    if (message.$id) {
      comment.url = `${canonicalUrl}#message-${message.$id}`
    }
    return comment
  })

  const mainEntity: Record<string, unknown> = {
    '@type': 'DiscussionForumPosting',
    headline: thread.title,
    url: canonicalUrl,
    mainEntityOfPage: canonicalUrl,
    text: opText,
    author: {
      '@type': 'Person',
      name: opAuthor,
    },
    datePublished: opDate,
  }

  if (typeof thread.vote_count === 'number' && thread.vote_count >= 0) {
    mainEntity.interactionStatistic = {
      '@type': 'InteractionCounter',
      interactionType: 'https://schema.org/LikeAction',
      userInteractionCount: thread.vote_count,
    }
  }

  const replyCount = Math.max(0, messages.length - 1)
  if (replyCount > 0) {
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
      name: author.display_name,
      alternateName: author.username,
      ...(author.bio ? { description: author.bio } : {}),
      interactionStatistic: [
        {
          '@type': 'InteractionCounter',
          interactionType: 'https://schema.org/WriteAction',
          userInteractionCount: author.thread_count + author.reply_count,
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
      name: item.name,
      item: getThreadsCanonicalUrl(item.path),
    })),
  }
}
